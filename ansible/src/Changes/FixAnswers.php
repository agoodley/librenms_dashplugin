<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;

/**
 * The answers of a change made from fixes (the Fixes page), from the topology plugin's fixes as data, and the contract
 * of the fix templates: one survey variable of type "text" per kind, one line per host and item, every field checked
 * here and again by the playbook before it is used. Pure.
 *
 * - Port descriptions, variable port_descriptions: "<host> <interface> <description>". The host is a target of the
 *   change (its inventory name); the interface a port of that device as LibreNMS knows it (its ifName: letters, digits
 *   and / . _ : -, at most 64); the description the one the topology plugin's link check builds from the LLDP/CDP
 *   neighbor: letters, digits, spaces and . _ / \ : + ( ) # @ , -, at most 80, no space at either end or two in a row.
 * - Spanning tree root priority, variable stp_priorities: "<host> <mode> <instances> <priority>". The mode pvst (a
 *   priority per VLAN: the instances are VLANs 1-4094), mst (the instances are MST instances 0-4094, the fix sets the
 *   CIST, 0) or stp (one instance: "-"); the instances a canonical list (ascending, ranges joined: 1,10-12); the
 *   priority a multiple of 4096 from 0 to 61440.
 *
 * A change records its fix (record()): its kind, the fixes and the findings they resolve, the lines, and what each
 * batch is meant to do (FixExpectation).
 */
final class FixAnswers
{
    public const PORT_DESCRIPTIONS = FixExpectation::PORT_DESCRIPTIONS;

    public const STP_ROOT_PRIORITY = FixExpectation::STP_ROOT_PRIORITY;

    public const KINDS = [self::PORT_DESCRIPTIONS, self::STP_ROOT_PRIORITY];

    /** The survey variable of each kind's template (the contract). */
    public const VARIABLES = [self::PORT_DESCRIPTIONS => 'port_descriptions', self::STP_ROOT_PRIORITY => 'stp_priorities'];

    /** Each kind in words. */
    public const LABELS = [self::PORT_DESCRIPTIONS => 'Port descriptions', self::STP_ROOT_PRIORITY => 'Spanning tree root priority'];

    /** What a description may hold (a regular expression character class body), and how long it may be. */
    public const DESCRIPTION_CHARS = 'A-Za-z0-9 ._\\/:+()#@,-';

    public const DESCRIPTION_MAX = 80;

    /** An interface name the plugin passes to a playbook. */
    public const INTERFACE = '/^[A-Za-z0-9][A-Za-z0-9\/._:-]{0,63}$/D';

    /** An inventory name (as the inventory builds them). */
    private const HOST = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/D';

    public const MODES = ['pvst', 'mst', 'stp'];

    /** Bridge priorities: multiples of 4096 up to this. */
    public const PRIORITY_MAX = 61440;

    /** Most lines of one answer. */
    public const LINES_MAX = 2000;

    /** Why a fix's lines are refused at the creation when they are not those the page showed. */
    public const CHANGED = 'The fix changed since you loaded the page: look again.';

    /**
     * The lines of the chosen fixes of one kind (the topology plugin's fixes() entries), for the hosts of the change.
     *
     * @param  list<array>  $fixes
     * @param  array<int, string>  $hosts  device_id => inventory name of the targets
     * @return array{lines: string[], devices: int[], errors: string[]} devices: those the lines change, in their order
     */
    public static function lines(string $kind, array $fixes, array $hosts): array
    {
        $lines = [];
        $devices = [];
        $errors = [];
        if (! in_array($kind, self::KINDS, true)) {
            return ['lines' => [], 'devices' => [], 'errors' => ['There is no fix of that kind.']];
        }
        foreach ($fixes as $fix) {
            if (($fix['kind'] ?? null) !== $kind) {
                $errors[] = 'A change is made from fixes of one kind.';

                continue;
            }
            if ($kind === self::PORT_DESCRIPTIONS) {
                foreach ((array) ($fix['ports'] ?? []) as $port) {
                    $deviceId = (int) ($port['device_id'] ?? 0);
                    $problem = self::hostProblem($deviceId, $hosts) ?? self::interfaceProblem((string) ($port['ifName'] ?? ''))
                        ?? self::descriptionProblem((string) ($port['description'] ?? ''));
                    if ($problem !== null) {
                        $errors[] = self::portName($port, $hosts) . ': ' . $problem;

                        continue;
                    }
                    $lines[] = $hosts[$deviceId] . ' ' . $port['ifName'] . ' ' . $port['description'];
                    $devices[$deviceId] = true;
                }

                continue;
            }
            foreach (['primary', 'secondary'] as $role) {
                $bridge = $fix[$role] ?? null;
                if (! is_array($bridge)) {
                    continue;
                }
                $deviceId = (int) ($bridge['device_id'] ?? 0);
                $mode = (string) ($bridge['mode'] ?? '');
                $instances = self::instances($mode, (array) ($bridge['instances'] ?? []));
                $priority = $bridge['priority'] ?? null;
                $problem = self::hostProblem($deviceId, $hosts) ?? (! in_array($mode, self::MODES, true) ? 'its spanning tree mode is not pvst, mst or stp' : null)
                    ?? ($instances === null ? 'its spanning tree instances are not ' . ($mode === 'mst' ? 'MST instances 0-4094' : 'VLANs 1-4094') : null)
                    ?? self::priorityProblem($priority);
                if ($problem !== null) {
                    $errors[] = UntrustedText::line((string) ($hosts[$deviceId] ?? "device #$deviceId"), 80) . ': ' . $problem;

                    continue;
                }
                $lines[] = $hosts[$deviceId] . " $mode $instances " . (int) $priority;
                $devices[$deviceId] = true;
            }
        }
        if (count($lines) > self::LINES_MAX) {
            $errors[] = 'More than ' . self::LINES_MAX . ' lines: choose fewer fixes.';
        }
        if (! $lines && ! $errors) {
            $errors[] = 'The fixes change nothing.';
        }

        return ['lines' => array_values(array_unique($lines)), 'devices' => array_keys($devices), 'errors' => array_values(array_unique($errors))];
    }

    /**
     * What is wrong with an answer of a fix template's variable (as the playbook would get it), checked line by line
     * against the contract; [] when nothing is.
     *
     * @param  array<int, string>  $hosts  device_id => inventory name of the targets
     * @return string[]
     */
    public static function check(string $kind, string $answer, array $hosts): array
    {
        $errors = [];
        $names = array_flip(array_map('strval', $hosts));
        $lines = preg_split('/\n/', str_replace("\r\n", "\n", $answer)) ?: [];
        if (count($lines) > self::LINES_MAX) {
            return ['More than ' . self::LINES_MAX . ' lines.'];
        }
        foreach ($lines as $index => $line) {
            if ($line === '') {
                continue;
            }
            $number = $index + 1;
            if ($kind === self::PORT_DESCRIPTIONS) {
                $parts = explode(' ', $line, 3);
                if (count($parts) < 3) {
                    $errors[] = "line $number is not \"<host> <interface> <description>\"";

                    continue;
                }
                [$host, $interface, $description] = $parts;
                $problem = (isset($names[$host]) && preg_match(self::HOST, $host) ? null : 'its host is not a target of the change')
                    ?? self::interfaceProblem($interface) ?? self::descriptionProblem($description);
            } elseif ($kind === self::STP_ROOT_PRIORITY) {
                $parts = explode(' ', $line);
                if (count($parts) !== 4) {
                    $errors[] = "line $number is not \"<host> <mode> <instances> <priority>\"";

                    continue;
                }
                [$host, $mode, $instances, $priority] = $parts;
                $problem = (isset($names[$host]) && preg_match(self::HOST, $host) ? null : 'its host is not a target of the change')
                    ?? (in_array($mode, self::MODES, true) ? null : 'its mode is not pvst, mst or stp')
                    ?? (self::canonical($mode, $instances) ? null : 'its instances are not a canonical list of ' . ($mode === 'mst' ? 'MST instances 0-4094' : 'VLANs 1-4094'))
                    ?? (preg_match('/^\d{1,5}$/D', $priority) ? self::priorityProblem((int) $priority) : 'its priority is not a number');
            } else {
                return ['There is no fix of that kind.'];
            }
            if ($problem !== null) {
                $errors[] = "line $number: $problem";
            }
        }

        return array_slice($errors, 0, 20);
    }

    /**
     * What the change records of its fix (its "fix" column): the kind, the fixes and the findings they resolve, the
     * lines, and what FixExpectation needs: for descriptions the findings of each device, for root priorities each
     * domain's VLANs, intended root and secondary, and the roots it moves away from.
     *
     * @param  list<array>  $fixes  the chosen fixes (the topology plugin's fixes() entries)
     * @param  string[]  $lines
     */
    public static function record(string $kind, array $fixes, array $lines): array
    {
        $findings = [];
        $devices = [];
        $roots = [];
        foreach ($fixes as $fix) {
            foreach ((array) ($fix['findings'] ?? []) as $finding) {
                $identity = (string) ($finding['identity'] ?? '');
                if ($identity === '') {
                    continue;
                }
                $findings[$identity] = ['code' => (string) ($finding['code'] ?? ''), 'severity' => (string) ($finding['severity'] ?? ''),
                    'title' => UntrustedText::line((string) ($finding['title'] ?? ''), 300), 'devices' => array_values(array_map('intval', (array) ($finding['devices'] ?? [])))];
            }
            if ($kind === self::PORT_DESCRIPTIONS) {
                foreach ((array) ($fix['devices'] ?? []) as $deviceId) {
                    foreach ((array) ($fix['resolves'] ?? []) as $identity) {
                        $devices[(string) (int) $deviceId][] = (string) $identity;
                    }
                }
            } else {
                $bridge = fn ($b) => is_array($b) ? ['device_id' => (int) ($b['device_id'] ?? 0), 'priority' => (int) ($b['priority'] ?? 0), 'mode' => (string) ($b['mode'] ?? ''),
                    'instances' => array_values(array_map('intval', (array) ($b['instances'] ?? []))), 'mac' => strtolower((string) ($b['mac'] ?? ''))] : null;
                $roots[] = ['vlans' => array_values(array_map('intval', (array) ($fix['vlans'] ?? []))), 'primary' => $bridge($fix['primary'] ?? null),
                    'secondary' => $bridge($fix['secondary'] ?? null), 'chosen_from' => UntrustedText::line((string) ($fix['chosen_from'] ?? ''), 80),
                    'old_macs' => array_values(array_unique(array_map(fn ($root) => strtolower((string) ($root['mac'] ?? '')), (array) ($fix['roots_now'] ?? []))))];
            }
        }
        $record = ['kind' => $kind, 'ids' => array_values(array_map(fn (array $fix) => (string) $fix['id'], $fixes)), 'variable' => self::VARIABLES[$kind],
            'title' => self::title($kind, $fixes), 'lines' => array_values($lines), 'findings' => $findings];

        return $record + ($kind === self::PORT_DESCRIPTIONS ? ['devices' => $devices] : ['roots' => $roots]);
    }

    /**
     * What the fixes are for, in words (the reference of the change, the timeline): "Fix: Port descriptions of lab-sw2;
     * Port descriptions of lab-sw6".
     *
     * @param  list<array>  $fixes
     */
    public static function title(string $kind, array $fixes, int $max = 3): string
    {
        $titles = array_map(fn (array $fix) => UntrustedText::line((string) ($fix['title'] ?? ''), 200), $fixes);

        return implode('; ', array_slice($titles, 0, $max)) . (count($titles) > $max ? ' and ' . (count($titles) - $max) . ' more' : '');
    }

    /**
     * What the change is meant to do, in words, for the new change page.
     *
     * @param  list<array>  $fixes
     * @param  array<int, string>  $names
     */
    public static function effect(string $kind, array $fixes, array $names): string
    {
        $count = count(array_unique(array_merge([], ...array_map(fn (array $fix) => array_map('strval', (array) ($fix['resolves'] ?? [])), $fixes))));
        $resolves = 'resolves ' . ($count === 1 ? '1 finding' : "$count findings") . ' of the topology plugin';
        if ($kind === self::PORT_DESCRIPTIONS) {
            $ports = array_sum(array_map(fn (array $fix) => count((array) ($fix['ports'] ?? [])), $fixes));

            return 'Sets the description of ' . ($ports === 1 ? '1 port' : "$ports ports") . ' to the LLDP/CDP neighbor each one faces; ' . $resolves
                . '. After each batch the plugin checks that the findings of its devices are resolved, and nothing else changed.';
        }
        $parts = [];
        foreach ($fixes as $fix) {
            $primary = (array) ($fix['primary'] ?? []);
            $secondary = $fix['secondary'] ?? null;
            $name = fn (array $bridge) => UntrustedText::line((string) ($names[(int) ($bridge['device_id'] ?? 0)] ?? '#' . (int) ($bridge['device_id'] ?? 0)), 80);
            $parts[] = 'the root bridge of ' . self::vlanText((array) ($fix['vlans'] ?? [])) . ' moves to ' . $name($primary) . ' (priority ' . (int) ($primary['priority'] ?? 0) . ')'
                . (is_array($secondary) ? ', with ' . $name($secondary) . ' as secondary (' . (int) ($secondary['priority'] ?? 0) . ')' : '');
        }

        return ucfirst(implode('; ', $parts)) . '; ' . $resolves . '. The intended root is set alone in the first batch, its secondary in the second: the root moves once. '
            . 'After each batch the plugin checks that the root of each VLAN is the intended one, and nothing else changed.';
    }

    /**
     * VLAN keys in words: "VLAN 1 / CST, VLAN 10".
     *
     * @param  int[]  $keys
     */
    public static function vlanText(array $keys): string
    {
        $keys = array_values(array_unique(array_map('intval', $keys)));
        sort($keys);

        return implode(', ', array_map(fn (int $key) => VerificationRules::vlanLabel($key), array_slice($keys, 0, 10))) . (count($keys) > 10 ? ' and ' . (count($keys) - 10) . ' more' : '');
    }

    /**
     * Why a template of the runner may not apply a kind of fix, null when it may: the contract asks for a survey variable
     * of type text (several lines) named as VARIABLES says.
     */
    public static function templateProblem(?\Agoodley\LibrenmsAnsible\Runner\RunnerTemplate $template, string $kind): ?string
    {
        $name = self::VARIABLES[$kind] ?? null;
        if ($name === null) {
            return 'there is no fix of that kind';
        }
        if ($template === null) {
            return 'the runner has no such template';
        }
        $var = $template->surveyVar($name);
        $what = 'template #' . $template->id . ' (' . UntrustedText::line($template->name, 80) . ')';
        if ($var === null) {
            return "$what has no survey variable $name, which the fix templates' contract asks for (see the plugin's README, Fixes)";
        }
        if ($var->type !== 'text') {
            return "the survey variable $name of $what is of type " . UntrustedText::line($var->rawType !== '' ? $var->rawType : $var->type, 20)
                . ', not text (several lines), as the fix templates\' contract asks';
        }

        return null;
    }

    /**
     * The instances of a bridge in a line: VLANs (pvst), MST instances (mst) as a canonical list, "-" for one instance
     * (stp); null when they are not that.
     *
     * @param  array<int, mixed>  $instances
     */
    public static function instances(string $mode, array $instances): ?string
    {
        if ($mode === 'stp') {
            return $instances === [] ? '-' : null;
        }
        [$min, $max] = $mode === 'mst' ? [0, 4094] : [1, 4094];
        $numbers = [];
        foreach ($instances as $instance) {
            if (! is_int($instance) || $instance < $min || $instance > $max) {
                return null;
            }
            $numbers[$instance] = true;
        }
        if (! $numbers) {
            return null;
        }
        ksort($numbers);
        $ranges = [];
        foreach (array_keys($numbers) as $number) {
            $last = count($ranges) - 1;
            if ($last >= 0 && $ranges[$last][1] === $number - 1) {
                $ranges[$last][1] = $number;
            } else {
                $ranges[] = [$number, $number];
            }
        }

        // two in a row are a list, three or more a range (as IOS writes them)
        $parts = [];
        foreach ($ranges as [$from, $to]) {
            $parts[] = match (true) {
                $from === $to => (string) $from,
                $to === $from + 1 => "$from,$to",
                default => "$from-$to",
            };
        }

        return implode(',', $parts);
    }

    /**
     * Is a list of instances canonical for the mode (instances() of what it lists)?
     */
    private static function canonical(string $mode, string $list): bool
    {
        if ($mode === 'stp') {
            return $list === '-';
        }
        if (! preg_match('/^\d{1,4}(-\d{1,4})?(,\d{1,4}(-\d{1,4})?)*$/D', $list)) {
            return false;
        }
        $numbers = [];
        foreach (explode(',', $list) as $part) {
            $bounds = explode('-', $part);
            $from = (int) $bounds[0];
            $to = isset($bounds[1]) ? (int) $bounds[1] : $from;
            if ($to < $from || $to - $from > 4094) {
                return false;
            }
            for ($n = $from; $n <= $to; $n++) {
                $numbers[] = $n;
            }
        }

        return self::instances($mode, $numbers) === $list;
    }

    /**
     * Why a device may not be a host of a fix's line: it is no target of the change.
     *
     * @param  array<int, string>  $hosts
     */
    private static function hostProblem(int $deviceId, array $hosts): ?string
    {
        return isset($hosts[$deviceId]) && preg_match(self::HOST, (string) $hosts[$deviceId]) ? null : 'it is not a target of the change';
    }

    private static function interfaceProblem(string $interface): ?string
    {
        return preg_match(self::INTERFACE, $interface) ? null : 'its interface name is not one the plugin passes to a playbook (letters, digits and / . _ : -, at most 64)';
    }

    private static function descriptionProblem(string $description): ?string
    {
        return preg_match('/^[' . self::DESCRIPTION_CHARS . ']{1,' . self::DESCRIPTION_MAX . '}$/D', $description) && trim($description) === $description
            && ! str_contains($description, '  ') ? null
            : 'its description is not one the link check builds (letters, digits, spaces and . _ / \\ : + ( ) # @ , -, at most ' . self::DESCRIPTION_MAX . ')';
    }

    private static function priorityProblem(mixed $priority): ?string
    {
        return is_int($priority) && $priority >= 0 && $priority <= self::PRIORITY_MAX && $priority % 4096 === 0 ? null
            : 'its priority is not a multiple of 4096 from 0 to ' . self::PRIORITY_MAX;
    }

    /**
     * A port of a fix in words, for a refusal.
     *
     * @param  array<int, string>  $hosts
     */
    private static function portName(array $port, array $hosts): string
    {
        $deviceId = (int) ($port['device_id'] ?? 0);

        return UntrustedText::line((string) ($hosts[$deviceId] ?? "device #$deviceId"), 80) . ' port #' . (int) ($port['port_id'] ?? 0);
    }
}
