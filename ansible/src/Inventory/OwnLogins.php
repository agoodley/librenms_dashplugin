<?php

namespace Agoodley\LibrenmsAnsible\Inventory;

/**
 * Which devices log in with a login of their own (install/rocky/device-logins.sh) rather than the default one, read
 * from LibreNMS' Oxidized group mapping: never a username or a password, which the plugin never sees. device-logins.sh
 * gives such a device the Oxidized group login-<device id> with a rule by its hostname (oxidized.maps.group.hostname,
 * with oxidized.group_support on), in the run that writes its Ansible login (inventory/host_vars/<name>.yml of the
 * playbook repository). A device has its own login here when LibreNMS gives Oxidized that group, decided as LibreNMS'
 * list_oxidized() decides it: the mapping's fields in their order, the first rule of a field that matches the device's
 * value (a regex case-insensitively, else equal), and a later field's match over an earlier one's.
 */
final class OwnLogins
{
    /** device-logins.sh's groups: this prefix and a device id. */
    public const PREFIX = 'login-';

    /** It logs in with its own login. */
    public const OWN = 'own';

    /** It logs in with the login device-logins.sh gave another device under its hostname. */
    public const OTHER = 'other';

    /** device-logins.sh gave it a login of its own, which LibreNMS does not give Oxidized. */
    public const NOT_APPLIED = 'not_applied';

    /**
     * @param  array<array-key, array>  $group  oxidized.maps.group: field => rules
     * @param  array<int, string>  $given  device id => the hostname device-logins.sh gave it its group under
     */
    private function __construct(private readonly array $group, private readonly bool $support, private readonly array $given)
    {
    }

    /**
     * From LibreNMS' settings.
     *
     * @param  mixed  $maps  oxidized.maps
     * @param  mixed  $groupSupport  oxidized.group_support
     */
    public static function from(mixed $maps, mixed $groupSupport): self
    {
        $group = is_array($maps) && is_array($maps['group'] ?? null) ? array_filter($maps['group'], 'is_array') : [];
        $given = [];
        foreach ($group['hostname'] ?? [] as $rule) {
            $id = is_array($rule) && is_scalar($rule['match'] ?? null) ? self::deviceId(self::value($rule)) : null;
            if ($id !== null && ! isset($given[$id])) {
                $given[$id] = (string) $rule['match'];
            }
        }

        return new self($group, $groupSupport === true, $given);
    }

    /**
     * The devices device-logins.sh gave a group of their own, with the hostname it gave it under.
     *
     * @return array<int, string> device id => hostname
     */
    public function given(): array
    {
        return $this->given;
    }

    /**
     * A device's login: null when it logs in with the default one (or a group of LibreNMS' that is not
     * device-logins.sh's), else its state (OWN, OTHER, NOT_APPLIED), the group LibreNMS gives Oxidized by the rule that
     * decides over device-logins.sh's (null when no rule of its hostname matches, or group support is off) and what it
     * means.
     *
     * @param  callable(string): mixed  $value  the device's value of another field of the mapping (os, sysName, ...);
     *                                          asked only when its hostname has a rule that a later field could win over
     * @return array{state: string, group: ?string, text: string}|null
     */
    public function of(int $deviceId, string $hostname, callable $value): ?array
    {
        $own = self::PREFIX . $deviceId;
        $given = $this->given[$deviceId] ?? null;
        $rule = $this->support ? self::firstMatch($this->group['hostname'] ?? [], $hostname) : null;
        if ($given === null && self::deviceId($rule === null ? null : self::value($rule)) === null) {
            return null;
        }
        // a later field's rule that matches wins over the hostname's (the fields before it lose to it)
        $winner = $rule === null ? null : ($this->laterMatch($value) ?? ['field' => 'hostname', 'rule' => $rule]);
        $group = $winner === null ? null : self::value($winner['rule']);
        $other = self::deviceId($group);
        if ($other === $deviceId) {
            return ['state' => self::OWN, 'group' => $group, 'text' => "LibreNMS gives Oxidized its group $group"];
        }
        if ($other !== null) {
            return ['state' => self::OTHER, 'group' => $group, 'text' => "LibreNMS gives Oxidized the group $group: the login device-logins.sh gave device #$other"
                . (isset($this->given[$other]) ? ' under the hostname ' . UntrustedText::line($this->given[$other], 100) : '') . '. Run device-logins.sh again'];
        }
        if ($given === null) {
            return null;
        }
        $text = match (true) {
            ! $this->support => "LibreNMS' oxidized.group_support is off, so Oxidized gets no group and logs in with the default login. Run device-logins.sh again (it turns it on)",
            $given !== $hostname => "device-logins.sh gave it the group $own under the hostname " . UntrustedText::line($given, 100)
                . ', and its hostname is ' . UntrustedText::line($hostname, 100) . ' now. Run device-logins.sh again',
            $winner !== null && $winner['field'] !== 'hostname' => "LibreNMS' Oxidized map rule " . self::ruleText($winner['field'], $winner['rule'])
                . " comes after device-logins.sh's rules by hostname, so it wins over the group $own. Change or delete it in LibreNMS' Oxidized settings",
            $winner !== null => "LibreNMS' Oxidized map rule " . self::ruleText('hostname', $winner['rule'])
                . " comes before device-logins.sh's rule for it. Run device-logins.sh again (it puts its rules first), or delete that rule",
            default => "LibreNMS does not give Oxidized its group $own. Run device-logins.sh again",
        };

        return ['state' => self::NOT_APPLIED, 'group' => $group, 'text' => $text];
    }

    /**
     * The rule that matches of the last field after hostname with one (list_oxidized() keeps the last field's match).
     *
     * @return array{field: string, rule: array}|null
     */
    private function laterMatch(callable $value): ?array
    {
        $match = null;
        $after = false;
        foreach ($this->group as $field => $rules) {
            $field = (string) $field;
            if ($after && ($rule = self::firstMatch($rules, $value($field))) !== null) {
                $match = ['field' => $field, 'rule' => $rule];
            }
            $after = $after || $field === 'hostname';
        }

        return $match;
    }

    /**
     * The first rule that matches a value, as list_oxidized() matches it: a regex with the i flag added, else equal
     * (PHP's ==, as LibreNMS compares).
     */
    private static function firstMatch(array $rules, mixed $value): ?array
    {
        $text = is_scalar($value) ? (string) $value : '';
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            // an invalid regex matches nothing (LibreNMS' settings page lets none in)
            if (isset($rule['regex']) && is_string($rule['regex']) && @preg_match($rule['regex'] . 'i', $text) === 1) {
                return $rule;
            } elseif (isset($rule['match']) && $rule['match'] == $value) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * A rule's group (value, or group in LibreNMS' old format).
     */
    private static function value(array $rule): ?string
    {
        $value = $rule['value'] ?? $rule['group'] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * The device id of one of device-logins.sh's groups, null for any other group.
     */
    private static function deviceId(?string $group): ?int
    {
        return $group !== null && preg_match('/^' . preg_quote(self::PREFIX, '/') . '([1-9][0-9]{0,9})$/D', $group, $m) ? (int) $m[1] : null;
    }

    /**
     * A rule as LibreNMS' settings page shows it: field = value (~ for a regex), and its group.
     */
    private static function ruleText(string $field, array $rule): string
    {
        $test = isset($rule['regex']) && is_string($rule['regex']) ? '~ ' . $rule['regex'] : '= ' . (is_scalar($rule['match'] ?? null) ? $rule['match'] : '');

        return UntrustedText::line("$field $test (group " . (self::value($rule) ?? '') . ')', 160);
    }
}
