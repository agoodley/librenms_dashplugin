<?php

namespace Agoodley\LibrenmsCompliance\Support;

use Agoodley\LibrenmsCompliance\Rules\BuiltinRules;
use Agoodley\LibrenmsCompliance\Rules\CustomRules;
use Agoodley\LibrenmsCompliance\Rules\Rule;

/**
 * The plugin's settings: how often the devices are checked and which (all, or a device group's), what goes to the
 * eventlog, which of the plugin's rules are on, the servers NTP, syslog and AAA must use, and the admin's own rules
 * (YAML, Rules\CustomRules). The plugin stores them itself (Data\SettingsRepository) and only LibreNMS admins change
 * them, through its own route: LibreNMS' plugin settings form can be saved by any holder of its plugin.admin permission.
 */
final class PluginSettings
{
    public const DEFAULTS = [
        'interval_hours' => '1',
        'device_group' => '',
        'eventlog' => '1',
        'eventlog_minimum' => 'warning',
        'disabled_rules' => [],
        'ntp_servers' => '',
        'syslog_servers' => '',
        'aaa_servers' => '',
        'custom_rules' => '',
    ];

    public const CHOICES = [
        'eventlog' => ['1' => 'on', '0' => 'off'],
        'eventlog_minimum' => ['critical' => 'critical', 'warning' => 'warning and worse', 'info' => 'every finding'],
    ];

    public const LIMITS = ['interval_hours' => [1, 24]];

    /** The settings that list servers, and the rule each one is for. */
    public const SERVERS = ['ntp_servers' => 'ntp', 'syslog_servers' => 'syslog', 'aaa_servers' => 'aaa'];

    /** Most servers a list holds. */
    private const MAX_SERVERS = 20;

    /** @var array<string, mixed> */
    private array $values;

    public function __construct(array $values = [], public readonly ?string $error = null)
    {
        $this->values = array_intersect_key($values, self::DEFAULTS) + self::DEFAULTS;
    }

    /**
     * Settings from a form, or what is wrong with it (field => why); the admin's rules must parse without an error.
     *
     * @return array{settings: ?self, errors: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $values = self::DEFAULTS;
        foreach (self::LIMITS as $key => [$min, $max]) {
            $value = trim((string) ($input[$key] ?? ''));
            if (! ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
                $errors[$key] = "must be a whole number from $min to $max";
            } else {
                $values[$key] = (string) (int) $value;
            }
        }
        foreach (self::CHOICES as $key => $choices) {
            $value = (string) ($input[$key] ?? '');
            if (! isset($choices[$value])) {
                $errors[$key] = 'must be one of ' . implode(', ', array_keys($choices));
            } else {
                $values[$key] = $value;
            }
        }
        $group = trim((string) ($input['device_group'] ?? ''));
        if (mb_strlen($group) > 255) {
            $errors['device_group'] = 'must be a device group\'s name';
        }
        $values['device_group'] = $group;
        $disabled = $input['disabled_rules'] ?? [];
        $disabled = is_array($disabled) ? array_values(array_intersect(array_map('strval', $disabled), array_keys(BuiltinRules::RULES))) : [];
        $values['disabled_rules'] = $disabled;
        foreach (array_keys(self::SERVERS) as $key) {
            $text = (string) ($input[$key] ?? '');
            $servers = self::list($text);
            $bad = array_filter($servers, fn (string $server) => preg_match('~^[A-Za-z0-9][A-Za-z0-9.:_-]{0,252}$~', $server) !== 1);
            if ($bad) {
                $errors[$key] = 'not an address or a host name: ' . implode(', ', array_slice($bad, 0, 3));
            } elseif (count($servers) > self::MAX_SERVERS) {
                $errors[$key] = 'at most ' . self::MAX_SERVERS . ' servers';
            }
            $values[$key] = implode("\n", $servers);
        }
        $yaml = str_replace(["\r\n", "\r"], "\n", (string) ($input['custom_rules'] ?? ''));
        $parsed = CustomRules::parse($yaml);
        if ($parsed['errors']) {
            $errors['custom_rules'] = implode(' ', $parsed['errors']);
        }
        $values['custom_rules'] = rtrim($yaml) === '' ? '' : rtrim($yaml) . "\n";

        return $errors ? ['settings' => null, 'errors' => $errors] : ['settings' => new self($values), 'errors' => []];
    }

    /**
     * Every setting, for storing and for the form.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function intervalHours(): int
    {
        [$min, $max] = self::LIMITS['interval_hours'];

        return max($min, min($max, (int) $this->values['interval_hours']));
    }

    /**
     * The device group whose devices are checked; '' for every device.
     */
    public function deviceGroup(): string
    {
        return trim((string) $this->values['device_group']);
    }

    public function eventlog(): bool
    {
        return (string) $this->values['eventlog'] === '1';
    }

    public function eventlogMinimum(): string
    {
        return isset(self::CHOICES['eventlog_minimum'][$this->values['eventlog_minimum']]) ? (string) $this->values['eventlog_minimum'] : 'warning';
    }

    /**
     * The plugin's rules switched off.
     *
     * @return string[]
     */
    public function disabledRules(): array
    {
        return array_values(array_intersect((array) $this->values['disabled_rules'], array_keys(BuiltinRules::RULES)));
    }

    /**
     * The servers a setting lists.
     *
     * @return string[]
     */
    public function servers(string $key): array
    {
        return self::list((string) ($this->values[$key] ?? ''));
    }

    /**
     * The admin's rules, and what is wrong with them (the stored ones parsed without an error when they were saved).
     *
     * @return array{rules: array<string, Rule>, errors: string[]}
     */
    public function customRules(): array
    {
        return CustomRules::parse((string) $this->values['custom_rules']);
    }

    /**
     * The rules a check runs: the plugin's that are on, then the admin's.
     *
     * @return array<string, Rule>
     */
    public function rules(): array
    {
        $rules = array_diff_key(BuiltinRules::rules(), array_flip($this->disabledRules()));

        return $rules + $this->customRules()['rules'];
    }

    /**
     * The plugin's rules with the servers the settings name.
     */
    public function builtin(): BuiltinRules
    {
        return new BuiltinRules(['ntp_servers' => $this->servers('ntp_servers'), 'syslog_servers' => $this->servers('syslog_servers'),
            'aaa_servers' => $this->servers('aaa_servers')]);
    }

    /**
     * The names of a list: separated by commas, spaces or new lines, each once.
     *
     * @return string[]
     */
    private static function list(string $text): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('~[\s,;]+~', $text) ?: []), fn (string $name) => $name !== '')));
    }
}
