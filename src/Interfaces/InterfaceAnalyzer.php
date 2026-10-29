<?php

namespace Agoodley\LibrenmsTopology\Interfaces;

use Agoodley\LibrenmsTopology\Links\LinkAnalyzer;
use Agoodley\LibrenmsTopology\Stp\Finding;
use Agoodley\LibrenmsTopology\Stp\StpAnalyzer;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Support\Text;

/**
 * Interface health of every physical Ethernet port, access ports included: links that keep going down, speeds that
 * change back and forth (1G and 100M: a cable with a damaged pair) or stay lower than they were, half duplex (a
 * neighbor with its speed and duplex set by hand), late collisions and errors over a window of hours. The changes come
 * from LibreNMS' eventlog: its poller logs each change of a port's status, speed and duplex it sees from one poll to
 * the next. The error counts come from its RRD files over the window, else from the last poll.
 *
 * A port's history is followed poll by poll from its eventlog: a speed change counts only between two polls that found
 * the port up (a port going down often reports another speed while down), a link going down not when the port was
 * shut down by hand in the same poll, and neither when the device rebooted then. A PC that sleeps and wakes takes its
 * link down and renegotiates its speed (Wake-on-LAN runs at 100M) a few times a day: ports without an LLDP/CDP neighbor
 * have their own, higher threshold.
 * Pure PHP: all data is passed in as arrays so it can be tested without LibreNMS.
 */
class InterfaceAnalyzer
{
    /** Check codes and their category; the "disabled checks" setting accepts either. */
    public const CHECKS = [
        'link_flapping' => 'flaps',
        'speed_flapping' => 'speed',
        'speed_downgraded' => 'speed',
        'port_half_duplex' => 'duplex',
        'late_collisions' => 'duplex',
        'port_errors' => 'errors',
    ];

    /** Order of the categories within one severity. */
    private const CATEGORY_ORDER = ['flaps', 'speed', 'duplex', 'errors'];

    /** ifType of physical Ethernet ports, the ones checked. */
    public const ETHERNET_TYPES = ['ethernetCsmacd', 'fastEther', 'fastEtherFX', 'gigabitEthernet'];

    /** Port classes: a link between two monitored devices (the Link & VLAN page compares both ends), another LLDP/CDP neighbor, none. */
    public const INTER_SWITCH = 'inter_switch';

    public const NEIGHBOR = 'neighbor';

    public const ACCESS = 'access';

    /**
     * The changes the poller logs for one port in one run lie this close together (seconds): it logs them one after the
     * other, and polls a device a minute apart at least.
     */
    private const SAME_POLL = 15;

    /** Changes this long before or after a device's reboot belong to the reboot, not to the port (seconds). */
    private const REBOOT_BEFORE = 300;

    private const REBOOT_AFTER = 900;

    /** Most changes of one port listed in a finding. */
    private const LISTED = 5;

    /** @var array<string, true> */
    private array $disabled;

    /** @var Finding[] */
    private array $findings = [];

    private int $window;

    private int $lookback;

    /**
     * @param  array<int, array>  $devices  device_id => hostname, sysName, display
     * @param  array<int, array>  $ports  port_id => device_id, ifName, ifDescr, ifAlias, ifType, ifSpeed, ifDuplex,
     *                                    ifOperStatus, ifAdminStatus, url (optional): the ports to check
     * @param  array<int, array>  $events  the ports' changes, oldest first: parseEvent() rows with port_id and time
     * @param  array<int, int[]>  $reboots  device_id => times it rebooted
     * @param  array<int, array>  $neighbors  port_id => class (INTER_SWITCH or NEIGHBOR), name, port: the LLDP/CDP
     *                                        neighbor of a port; ports without one are access ports
     * @param  array<int, array>  $counters  port_id => in_errors, out_errors, in_packets, out_packets, fcs and
     *                                       late_collisions (null: not polled), seconds (the time they cover)
     * @param  string  $counterSource  'rrd': the counters cover the window (LibreNMS' RRD files); 'poll': its last poll
     */
    public function __construct(
        private readonly array $devices,
        private readonly array $ports,
        private readonly array $events,
        private readonly array $reboots,
        private readonly array $neighbors,
        private readonly array $counters,
        private readonly string $counterSource,
        private readonly PluginSettings $settings,
        private readonly int $now,
    ) {
        $this->disabled = array_fill_keys(array_map('strtolower', $settings->list('interfaces_disabled_checks')), true);
        $this->window = 3600 * self::windowHours($settings);
        $this->lookback = 86400 * $settings->int('interfaces_downgrade_days', 0, 90);
    }

    /**
     * Hours of the window of the flap, speed change and error checks.
     */
    public static function windowHours(PluginSettings $settings): int
    {
        return $settings->int('interfaces_window_hours', 1, 168);
    }

    /**
     * Seconds of eventlog the analysis needs: its window, or the speed downgrades' lookback when longer.
     */
    public static function eventSeconds(PluginSettings $settings): int
    {
        return max(3600 * self::windowHours($settings), 86400 * $settings->int('interfaces_downgrade_days', 0, 90));
    }

    /**
     * A port change of LibreNMS' eventlog (type interface): its field (oper, admin, speed, duplex) and values, the
     * speeds in bits per second ("ifSpeed: 1 Gbps -> 100 Mbps"); null for any other message. A value LibreNMS logged
     * as NULL is null.
     *
     * @return array{field: string, from: int|string|null, to: int|string|null}|null
     */
    public static function parseEvent(string $message): ?array
    {
        if (! preg_match('/^(ifOperStatus|ifAdminStatus|ifSpeed|ifDuplex): (.*?) -> (.*)$/s', trim($message), $m)) {
            return null;
        }
        $field = ['ifOperStatus' => 'oper', 'ifAdminStatus' => 'admin', 'ifSpeed' => 'speed', 'ifDuplex' => 'duplex'][$m[1]];
        $value = fn (string $text) => match (true) {
            trim($text) === '' || strcasecmp(trim($text), 'NULL') === 0 => null,
            $field === 'speed' => self::parseSpeed($text),
            default => trim($text),
        };

        return ['field' => $field, 'from' => $value($m[2]), 'to' => $value($m[3])];
    }

    /**
     * Bits per second of a speed as LibreNMS logs it ("100 Mbps", "2.5 Gbps", "0 bps", or a plain number).
     */
    public static function parseSpeed(string $text): ?int
    {
        $text = trim($text);
        if (ctype_digit($text)) {
            return (int) $text;
        }
        if (! preg_match('/^(\d+(?:\.\d+)?)\s*([kMGTP]?)bps$/', $text, $m)) {
            return null;
        }
        $factor = ['' => 1, 'k' => 1000, 'M' => 1000000, 'G' => 1000000000, 'T' => 1000000000000, 'P' => 1000000000000000][$m[2]];

        return (int) round((float) $m[1] * $factor);
    }

    /**
     * Run every check and return the data of the interface health page: summary, findings and a row per port with a
     * finding or a change in the window.
     */
    public function analyze(): array
    {
        $this->findings = [];
        $byPort = [];
        foreach ($this->events as $event) {
            $byPort[(int) $event['port_id']][] = $event;
        }

        $rows = [];
        foreach ($this->ports as $portId => $port) {
            if (! in_array((string) ($port['ifType'] ?? ''), self::ETHERNET_TYPES, true)) {
                continue;
            }
            $history = $this->history((int) $portId, $port, $byPort[(int) $portId] ?? []);
            $issues = $this->check((int) $portId, $port, $history);
            if ($issues || $history['downs'] || $history['changes_in_window']) {
                $rows[] = $this->row((int) $portId, $port, $history, $issues);
            }
        }

        $findings = self::sortFindings(array_map(fn (Finding $f) => $f->toArray() + ['category' => self::CHECKS[$f->code] ?? 'other'], $this->findings));
        $rows = self::sortRows($rows);

        $names = [];
        foreach ($findings as $finding) {
            foreach ($finding['devices'] as $deviceId) {
                $names[$deviceId] = $this->deviceName($deviceId);
            }
        }

        return [
            'device_names' => $names,
            'summary' => self::summarize($rows, $findings),
            'findings' => $findings,
            'ports' => $rows,
            'window' => ['hours' => intdiv($this->window, 3600), 'downgrade_days' => intdiv($this->lookback, 86400), 'counters' => $this->counterSource],
        ];
    }

    /**
     * Findings in page order: the worst first, then by category and title.
     *
     * @param  array[]  $findings
     * @return array[]
     */
    public static function sortFindings(array $findings): array
    {
        $categoryOrder = array_flip(self::CATEGORY_ORDER);
        usort($findings, fn ($a, $b) => [Finding::SEVERITY_ORDER[$a['severity']], $categoryOrder[$a['category']] ?? 99, $a['title']]
            <=> [Finding::SEVERITY_ORDER[$b['severity']], $categoryOrder[$b['category']] ?? 99, $b['title']]);

        return $findings;
    }

    /**
     * Port rows in page order: the worst first, then the most changes, then by device and port.
     *
     * @param  array[]  $rows
     * @return array[]
     */
    public static function sortRows(array $rows): array
    {
        usort($rows, fn ($a, $b) => [Finding::SEVERITY_ORDER[$a['severity']] ?? 9, -$a['downs'] - $a['speed_changes'], $a['device'], $a['port']]
            <=> [Finding::SEVERITY_ORDER[$b['severity']] ?? 9, -$b['downs'] - $b['speed_changes'], $b['device'], $b['port']]);

        return $rows;
    }

    /**
     * Summary cards of a result.
     */
    public static function summarize(array $rows, array $findings): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        $categories = array_fill_keys(array_values(array_unique(self::CHECKS)), 0);
        foreach ($findings as $finding) {
            $counts[$finding['severity']]++;
            $categories[$finding['category']] = ($categories[$finding['category']] ?? 0) + 1;
        }

        return [
            'rows' => count($rows),
            'with_issues' => count(array_filter($rows, fn ($row) => $row['severity'] !== 'ok')),
            'devices' => count(array_unique(array_column($rows, 'device_id'))),
            'findings' => $counts,
            'categories' => $categories,
            'status' => $counts['critical'] ? 'critical' : ($counts['warning'] ? 'warning' : 'ok'),
        ];
    }

    /**
     * A port's history from its changes, poll by poll: how often its link went down in the window, each change of its
     * speed between two polls that found it up (within the lookback), and since when it runs half duplex. The state
     * before the first change is the "from" of its first change of that field, else the port's state now.
     *
     * @param  array[]  $events  the port's changes, oldest first
     * @return array{downs: int, last_down: ?int, changes: array[], changes_in_window: int, speeds: int[], half_since: ?int, last: ?int}
     */
    private function history(int $portId, array $port, array $events): array
    {
        $deviceId = (int) ($port['device_id'] ?? 0);
        $first = [];
        foreach ($events as $event) {
            if (! array_key_exists($event['field'], $first)) {
                $first[$event['field']] = $event['from'];
            }
        }
        $oper = array_key_exists('oper', $first) ? $first['oper'] : ($port['ifOperStatus'] ?? null);
        $speed = array_key_exists('speed', $first) ? $first['speed'] : (int) ($port['ifSpeed'] ?? 0);
        $lastUp = $oper === 'up' && $speed > 0 ? $speed : null;
        $since = $this->now - $this->window;
        $history = ['downs' => 0, 'last_down' => null, 'changes' => [], 'changes_in_window' => 0, 'speeds' => [], 'half_since' => null, 'last' => null];

        foreach (self::polls($events) as $poll) {
            $time = (int) $poll[0]['time'];
            $counts = $time >= $since && ! $this->nearReboot($deviceId, $time);
            $shutDown = false;
            $wentDown = false;
            foreach ($poll as $event) {
                if ($event['field'] === 'admin') {
                    $shutDown = $shutDown || ($event['to'] !== null && $event['to'] !== 'up');
                } elseif ($event['field'] === 'oper') {
                    $wentDown = $wentDown || ($event['from'] === 'up' && $event['to'] !== null && $event['to'] !== 'up');
                    $oper = $event['to'];
                } elseif ($event['field'] === 'speed') {
                    $speed = $event['to'];
                } elseif ($event['field'] === 'duplex') {
                    $history['half_since'] = $event['to'] === 'halfDuplex' ? $time : null;
                }
            }
            if ($wentDown && ! $shutDown && $counts) {
                $history['downs']++;
                $history['last_down'] = $time;
                $history['last'] = $time;
            }
            if ($oper === 'up' && $speed > 0) {
                if ($lastUp !== null && $speed !== $lastUp) {
                    $history['changes'][] = ['time' => $time, 'from' => $lastUp, 'to' => $speed];
                    if ($counts) {
                        $history['changes_in_window']++;
                        $history['speeds'][$lastUp] = true;
                        $history['speeds'][$speed] = true;
                        $history['last'] = max($history['last'] ?? 0, $time);
                    }
                }
                $lastUp = $speed;
            }
        }
        $history['speeds'] = array_keys($history['speeds']);
        rsort($history['speeds']);

        return $history;
    }

    /**
     * Changes grouped by poll: the changes of one port the poller logged in one run (within SAME_POLL seconds).
     *
     * @param  array[]  $events  oldest first
     * @return array[][]
     */
    private static function polls(array $events): array
    {
        $polls = [];
        $start = null;
        foreach ($events as $event) {
            $time = (int) $event['time'];
            if ($start === null || $time - $start > self::SAME_POLL) {
                $polls[] = [];
                $start = $time;
            }
            $polls[array_key_last($polls)][] = $event;
        }

        return $polls;
    }

    private function nearReboot(int $deviceId, int $time): bool
    {
        foreach ($this->reboots[$deviceId] ?? [] as $reboot) {
            if ($time >= $reboot - self::REBOOT_BEFORE && $time <= $reboot + self::REBOOT_AFTER) {
                return true;
            }
        }

        return false;
    }

    /**
     * The checks of one port.
     *
     * @return array<string, string> code => severity of its findings
     */
    private function check(int $portId, array $port, array $history): array
    {
        $issues = [];
        $class = $this->neighbors[$portId]['class'] ?? self::ACCESS;
        $up = ($port['ifOperStatus'] ?? null) === 'up';
        $label = $this->endLabel($portId);
        $name = $this->portName($portId);
        $hours = intdiv($this->window, 3600);
        $within = 'in the last ' . Text::count($hours, 'hour');
        $toward = $this->toward($portId);
        $threshold = $class === self::ACCESS ? $this->settings->int('interfaces_flaps_access', 0, 100000) : $this->settings->int('interfaces_flaps', 0, 100000);
        $sleepNote = $class === self::ACCESS ? ' A PC that is switched off or sleeps takes its link down too, a few times a day.' : '';

        if ($threshold > 0 && $history['downs'] >= $threshold) {
            $issues += $this->add(new Finding(
                Finding::WARNING, 'link_flapping', "link flapping: $label",
                "$name$toward went down " . Text::count($history['downs'], 'time') . " $within, last at " . self::time($history['last_down']) . '.',
                'A link that keeps going down usually has a bad cable, patch cord or connector, a failing optic, or a device behind it that '
                . 'reboots. Check the cabling and the optics on both ends and the device on the other end.' . $sleepNote,
                null, [(int) $port['device_id']], [$portId],
            ));
        }

        if ($threshold > 0 && $history['changes_in_window'] >= $threshold) {
            $recent = array_slice(array_values(array_filter($history['changes'], fn ($c) => $c['time'] >= $this->now - $this->window)), -self::LISTED);
            $issues += $this->add(new Finding(
                Finding::WARNING, 'speed_flapping', "speed flapping: $label",
                "$name$toward changed speed " . Text::count($history['changes_in_window'], 'time') . " $within ("
                . implode(' / ', array_map(fn ($s) => LinkAnalyzer::speed($s), $history['speeds'])) . '); the last: '
                . implode(', ', array_map(fn ($c) => self::time($c['time']) . ' ' . LinkAnalyzer::speed($c['from']) . ' to ' . LinkAnalyzer::speed($c['to']), $recent)) . '.',
                'Gigabit Ethernet needs all four pairs of the cable: with a damaged pair, a bad patch cord or keystone it falls back to 100M, '
                . 'and back again. Replace the patch cords, check the cabling, and set autonegotiation on both ends.'
                . ($class === self::ACCESS ? ' A PC that sleeps (Wake-on-LAN) also drops to 100M until it wakes, a few times a day.' : ''),
                null, [(int) $port['device_id']], [$portId],
            ));
        }

        $last = $history['changes'] ? end($history['changes']) : null;
        if ($up && $this->lookback > 0 && $last !== null && $last['to'] < $last['from'] && $last['time'] >= $this->now - $this->lookback
            && (int) ($port['ifSpeed'] ?? 0) === $last['to'] && ! isset($issues['speed_flapping'])) {
            $issues += $this->add(new Finding(
                $class === self::ACCESS ? Finding::INFO : Finding::WARNING, 'speed_downgraded', "speed downgraded: $label",
                "$name$toward runs at " . LinkAnalyzer::speed($last['to']) . ' since ' . self::time($last['time']) . '; before it ran at '
                . LinkAnalyzer::speed($last['from']) . '.',
                'A gigabit port that comes back at 100M often has a cable or patch cord with a damaged pair (100M needs two pairs, 1G all four); '
                . 'else the device on it now is slower, or one end has its speed set by hand.'
                . ($class === self::ACCESS ? ' A PC that sleeps (Wake-on-LAN) also runs at 100M until it wakes.' : ''),
                null, [(int) $port['device_id']], [$portId],
            ));
        }

        if ($up && $class !== self::INTER_SWITCH && strtolower((string) ($port['ifDuplex'] ?? '')) === 'halfduplex') {
            $issues += $this->add(new Finding(
                Finding::WARNING, 'port_half_duplex', "half duplex: $label",
                "$name$toward runs " . ((int) ($port['ifSpeed'] ?? 0) > 0 ? LinkAnalyzer::speed((int) $port['ifSpeed']) . ' ' : '') . 'half duplex'
                . ($history['half_since'] !== null ? ' since ' . self::time($history['half_since']) : '') . '.',
                'A switch port falls back to half duplex when the device on it has its speed and duplex set by hand while the port '
                . 'autonegotiates: a duplex mismatch, with late collisions on this end and CRC errors on the other, and very poor '
                . 'throughput. Set both ends to auto, or both to the same speed and duplex.',
                null, [(int) $port['device_id']], [$portId],
            ));
        }

        $counters = $this->counters[$portId] ?? null;
        $over = $this->counterSource === 'rrd' ? $this->span($counters) : 'in the last poll';
        if ($counters !== null && (int) ($counters['late_collisions'] ?? 0) > 0) {
            $issues += $this->add(new Finding(
                Finding::WARNING, 'late_collisions', "late collisions: $label",
                "$name$toward counted " . number_format((int) $counters['late_collisions']) . " late collisions $over"
                . (($duplex = self::duplex($port['ifDuplex'] ?? null)) !== null ? " (it runs $duplex duplex now)" : '') . '.',
                'Late collisions happen on a half-duplex end that faces a full-duplex one: a duplex mismatch. Set both ends to auto, '
                . 'or both to the same speed and duplex.',
                null, [(int) $port['device_id']], [$portId],
            ));
        }

        $limit = $this->settings->int('interfaces_errors', 0, PHP_INT_MAX);
        $in = (int) ($counters['in_errors'] ?? 0);
        $out = (int) ($counters['out_errors'] ?? 0);
        // the last poll's errors of a link between monitored devices are the Link & VLAN page's
        if ($limit > 0 && $counters !== null && $in + $out >= $limit && ! ($this->counterSource === 'poll' && $class === self::INTER_SWITCH)) {
            $parts = [];
            foreach (['input' => [$in, (int) ($counters['in_packets'] ?? 0)], 'output' => [$out, (int) ($counters['out_packets'] ?? 0)]] as $direction => [$count, $packets]) {
                if ($count > 0) {
                    // without packet counts (not polled) a share would say 100%
                    $share = $packets > 0 ? self::percent($count, $count + $packets) : null;
                    $parts[] = number_format($count) . " $direction errors" . ($share !== null ? " ($share of packets)" : '');
                }
            }
            $fcs = $counters['fcs'] ?? null;
            $issues += $this->add(new Finding(
                Finding::WARNING, 'port_errors', "interface errors: $label",
                "$name$toward: " . implode(', ', $parts) . " $over" . ($fcs !== null && (int) $fcs > 0 ? '; ' . number_format((int) $fcs) . ' of the input errors are CRC (FCS) errors' : '') . '.',
                'Input errors (CRC, framing) mean damaged frames arrive at this port: check the cable and patch panel, clean or replace '
                . 'the optics on both ends, and look for a duplex mismatch. Output errors usually point at the port itself or at '
                . 'collisions (half duplex).',
                null, [(int) $port['device_id']], [$portId],
            ));
        }

        return $issues;
    }

    /**
     * A row of the ports table.
     */
    private function row(int $portId, array $port, array $history, array $issues): array
    {
        $counters = $this->counters[$portId] ?? null;
        $neighbor = $this->neighbors[$portId] ?? null;

        return [
            'device_id' => (int) $port['device_id'],
            'device' => $this->deviceName((int) $port['device_id']),
            'port_id' => $portId,
            'port' => $this->portName($portId),
            'alias' => (string) ($port['ifAlias'] ?? ''),
            'url' => $port['url'] ?? null,
            'class' => $neighbor['class'] ?? self::ACCESS,
            'neighbor' => $neighbor ? trim(($neighbor['name'] ?? '') . ' ' . ($neighbor['port'] ?? '')) : null,
            'status' => (string) ($port['ifOperStatus'] ?? ''),
            'speed' => (int) ($port['ifSpeed'] ?? 0),
            'duplex' => self::duplex($port['ifDuplex'] ?? null),
            'downs' => $history['downs'],
            'speed_changes' => $history['changes_in_window'],
            'speeds' => $history['speeds'],
            'last_change' => $history['last'],
            'errors' => $counters === null ? null : [
                'in' => (int) ($counters['in_errors'] ?? 0), 'out' => (int) ($counters['out_errors'] ?? 0),
                'fcs' => isset($counters['fcs']) ? (int) $counters['fcs'] : null,
                'late_collisions' => isset($counters['late_collisions']) ? (int) $counters['late_collisions'] : null,
            ],
            'issues' => $issues,
            'severity' => Finding::worst(array_values($issues)),
        ];
    }

    /**
     * " toward ap-3 Gi0" for a port with an LLDP/CDP neighbor.
     */
    private function toward(int $portId): string
    {
        $neighbor = $this->neighbors[$portId] ?? null;
        $name = $neighbor ? trim(($neighbor['name'] ?? '') . ' ' . ($neighbor['port'] ?? '')) : '';

        return $name !== '' ? " (toward $name)" : '';
    }

    /**
     * "in the last 24 hours", or the shorter time the RRD files have data for.
     */
    private function span(?array $counters): string
    {
        $seconds = (int) ($counters['seconds'] ?? 0);

        return $seconds > 0 && $seconds < 0.9 * $this->window
            ? 'in the last ' . StpAnalyzer::duration($seconds) . ' (the data LibreNMS has)'
            : 'in the last ' . Text::count(intdiv($this->window, 3600), 'hour');
    }

    private function add(Finding $finding): array
    {
        if (isset($this->disabled[$finding->code]) || isset($this->disabled[self::CHECKS[$finding->code] ?? ''])) {
            return [];
        }
        $this->findings[] = $finding;

        return [$finding->code => $finding->severity];
    }

    private function deviceName(int $deviceId): string
    {
        $device = $this->devices[$deviceId] ?? null;

        return $device ? (string) (($device['display'] ?? '') ?: ($device['hostname'] ?? $deviceId)) : "device #$deviceId";
    }

    private function portName(int $portId): string
    {
        $port = $this->ports[$portId] ?? null;

        return $port ? InterfaceName::short(InterfaceName::label($port)) : "port #$portId";
    }

    private function endLabel(int $portId): string
    {
        return $this->deviceName((int) ($this->ports[$portId]['device_id'] ?? 0)) . ' ' . $this->portName($portId);
    }

    private static function duplex(?string $value): ?string
    {
        return match (strtolower((string) $value)) {
            'fullduplex', 'full' => 'full',
            'halfduplex', 'half' => 'half',
            default => null,
        };
    }

    /**
     * A time as the pages show it (the server's time zone).
     */
    public static function time(?int $time): string
    {
        return $time === null ? '?' : date('Y-m-d H:i', $time);
    }

    /**
     * Share as a percentage with sensible precision ("0.02%", "1.4%"), null without a total.
     */
    private static function percent(int $part, int $total): ?string
    {
        if ($total <= 0) {
            return null;
        }
        $value = $part / $total * 100;

        return match (true) {
            $value < 0.01 => '<0.01%',
            $value < 1 => round($value, 2) . '%',
            default => round($value, 1) . '%',
        };
    }
}
