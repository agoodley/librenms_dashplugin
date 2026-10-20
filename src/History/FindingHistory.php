<?php

namespace Agoodley\LibrenmsTopology\History;

use Agoodley\LibrenmsTopology\Stp\Finding;

/**
 * Findings history of the background checks. A finding is the same finding while its code,
 * devices, ports and VLAN stay the same; its wording and severity may change. A finding is
 * confirmed once two runs in a row reported it and resolved once two runs in a row did not, so a
 * check that flickers from run to run (error counters of the last poll) does not fill the
 * eventlog; a finding that was never confirmed ends at its first absence. If it comes back later
 * it starts a new period.
 *
 * The eventlog of a device gets an entry when the device gets its first confirmed finding of a
 * check or loses its last one. The entry names the check only: findings are computed over the
 * whole network and name other devices, and a device's eventlog is shown to everyone who may see
 * that device.
 */
class FindingHistory
{
    /** Eventlog severity ranks: a finding is written when it is at least as severe as the setting. */
    private const RANKS = [Finding::INFO => 1, Finding::WARNING => 2, Finding::CRITICAL => 3];

    /** Devices whose eventlog gets the entry of one check and run (the first ones). */
    public const EVENTLOG_DEVICES = 10;

    /** Analyses as named in eventlog entries. */
    private const ANALYSIS_NAMES = ['stp' => 'spanning tree', 'links' => 'link & VLAN', 'redundancy' => 'redundancy'];

    /** Names of checks whose code does not read well; the others are their code in words. */
    private const LABELS = [
        'spof_device' => 'Single point of failure (device)',
        'spof_link' => 'Single point of failure (link)',
        'unit_spof' => 'Single point of failure (stack member or line card)',
        'uplinks_same_device' => 'Uplinks to one device',
        'forwarding_loop' => 'Possible bridging loop',
        'loop_without_stp' => 'Redundant path without spanning tree data',
        'stp_disabled_port' => 'Inter-switch port without spanning tree',
        'no_stp_data' => 'Switch without spanning tree data',
        'no_root_port' => 'Non-root bridge without a root port',
        'nondesignated_forwarding' => 'Forwarding port that is neither root nor designated',
        'port_broken' => 'Spanning tree port in broken state',
        'port_flapping' => 'Port with many transitions to forwarding',
        'port_transitioning' => 'Port converging at the last poll',
        'protocol_mix' => 'Mixed spanning tree modes',
        'diameter' => 'Spanning tree diameter',
        'root_changed' => 'Root bridge changes',
        'root_port_changed' => 'Root port changes',
        'root_mismatch' => 'Bridges disagree on the root bridge',
        'root_unmanaged' => 'Root bridge not monitored',
        'root_not_polled' => 'Root bridge without spanning tree data',
        'root_not_preferred' => 'Root bridge is not a designated root bridge',
        'root_default_priority' => 'Root bridge with the default priority',
        'root_at_edge' => 'Root bridge at the edge of the topology',
        'timer_mismatch' => 'Spanning tree timers differ from the root',
        'timer_relation' => 'Inconsistent spanning tree timers',
        'timers_non_default' => 'Non-default spanning tree timers',
        'tc_rate' => 'High rate of topology changes',
        'tc_recent' => 'Recent topology change',
        'optical_rx' => 'Optical receive power',
        'neighbor_not_reported' => 'Neighbor not reported by the other side',
    ];

    /** Words of check codes. */
    private const WORDS = ['lag' => 'port-channel', 'stp' => 'spanning tree', 'mtu' => 'MTU', 'vlan' => 'VLAN', 'tc' => 'topology change', 'spof' => 'single point of failure'];

    /**
     * Identity of a finding of an analysis.
     */
    public static function hash(string $analysis, array $finding): string
    {
        $devices = array_map('intval', $finding['devices'] ?? []);
        $ports = array_map('intval', $finding['ports'] ?? []);
        sort($devices);
        sort($ports);

        return sha1(implode('|', [
            $analysis,
            $finding['code'],
            $finding['vlan'] ?? '',
            implode(',', array_unique($devices)),
            implode(',', array_unique($ports)),
        ]));
    }

    /**
     * One run of an analysis against its open findings.
     *
     * @param  array<int, array{id: int, hash: string, code: string, severity: string, title: string, devices: int[],
     *     confirmed: bool, missed_at: ?string, first_seen: string}>  $open  open findings (not resolved), oldest first
     * @param  array<int, array>  $findings  findings of this run
     * @param  string  $time  time of this run (Y-m-d H:i:s)
     * @param  bool  $initial  first successful run of the analysis: its findings existed before and are confirmed
     * @return array{insert: array<string, array>, resolve: array<int, string>, miss: int[], unmiss: int[], confirm: int[],
     *     update: array<int, array>, before: array<int, array>, after: array<int, array>} findings to insert (hash =>
     *     finding), id => resolved_at, ids missed by this run, ids back after one miss, ids confirmed now, id => finding
     *     with another severity or wording, and the confirmed findings (code, severity, devices) before and after the run
     */
    public static function run(string $analysis, array $open, array $findings, string $time, bool $initial): array
    {
        $current = [];
        foreach ($findings as $finding) {
            $current[self::hash($analysis, $finding)] ??= $finding;
        }

        $result = ['insert' => $current, 'resolve' => [], 'miss' => [], 'unmiss' => [], 'confirm' => [], 'update' => [], 'before' => [], 'after' => []];
        $seen = [];
        foreach ($open as $row) {
            $id = (int) $row['id'];
            $hash = $row['hash'];
            if ($row['confirmed']) {
                $result['before'][] = self::state($row['code'], $row['severity'], $row['devices']);
            }
            if (isset($seen[$hash])) {
                $result['resolve'][$id] = $time; // a duplicate open period
                continue;
            }
            $seen[$hash] = true;

            if (isset($current[$hash])) {
                $finding = $current[$hash];
                unset($result['insert'][$hash]);
                if ($row['missed_at'] !== null) {
                    $result['unmiss'][] = $id; // back after one run without it
                } elseif (! $row['confirmed'] && $row['first_seen'] < $time) {
                    $result['confirm'][] = $id; // reported by the previous run and this one
                }
                if ($row['severity'] !== $finding['severity'] || $row['title'] !== self::title($finding)) {
                    $result['update'][$id] = $finding;
                }
                if ($row['confirmed'] || in_array($id, $result['confirm'], true)) {
                    $result['after'][] = self::state($finding['code'], $finding['severity'], $row['devices']);
                }
            } elseif (! $row['confirmed']) {
                $result['resolve'][$id] = $time; // never confirmed: gone at its first absence
            } elseif ($row['missed_at'] !== null) {
                $result['resolve'][$id] = $row['missed_at']; // two runs in a row without it: resolved since the first
            } else {
                $result['miss'][] = $id;
                $result['after'][] = self::state($row['code'], $row['severity'], $row['devices']);
            }
        }
        if ($initial) {
            foreach ($result['insert'] as $finding) {
                $result['after'][] = self::state($finding['code'], $finding['severity'], $finding['devices'] ?? []);
            }
        }

        return $result;
    }

    /**
     * Eventlog entries of a run: a device gets one when it gets its first confirmed finding of a check
     * (at least $minimum severe) or loses its last one; 'none' writes nothing. At most EVENTLOG_DEVICES
     * devices per check and run get the entry; it says on how many more devices it also happened.
     *
     * @param  array<int, array{code: string, severity: string, devices: int[]}>  $before  confirmed findings before the run
     * @param  array<int, array{code: string, severity: string, devices: int[]}>  $after  confirmed findings after the run
     * @param  string[]  $skip  check codes kept out of the eventlog
     * @return array<int, array{device_id: int, message: string, severity: string, reference: string}> severity: critical,
     *                                                                                                  warning, info or ok
     */
    public static function eventlog(string $analysis, array $before, array $after, string $minimum, array $skip = []): array
    {
        $rank = self::RANKS[$minimum] ?? null;
        if ($rank === null) {
            return [];
        }

        $was = self::byDevice($before, $skip);
        $now = self::byDevice($after, $skip);
        $name = self::ANALYSIS_NAMES[$analysis] ?? $analysis;
        $entries = [];
        foreach (['new' => [$now, $was], 'resolved' => [$was, $now]] as $change => [$present, $absent]) {
            foreach ($present as $code => $devices) {
                $devices = array_filter($devices, fn ($severity, $device) => ! isset($absent[$code][$device]) && (self::RANKS[$severity] ?? 0) >= $rank,
                    ARRAY_FILTER_USE_BOTH);
                ksort($devices);
                $more = count($devices) - self::EVENTLOG_DEVICES;
                foreach (array_slice($devices, 0, self::EVENTLOG_DEVICES, true) as $device => $severity) {
                    $entries[] = [
                        'device_id' => $device,
                        'message' => ($change === 'new'
                            ? 'Topology ' . $severity . ': ' . self::label($code) . " ($name check), details: plugin/topology/$analysis?device=$device"
                            : 'Topology resolved: ' . self::label($code) . " ($name check)")
                            . ($more > 0 ? " (also on $more more devices)" : ''),
                        'severity' => $change === 'new' ? $severity : 'ok',
                        'reference' => mb_substr("topology:$analysis:$code", 0, 64),
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * Name of a check from its code alone ("Single point of failure (device)", "MTU mismatch").
     */
    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? ucfirst(implode(' ', array_map(fn ($word) => self::WORDS[$word] ?? $word, explode('_', $code))));
    }

    /**
     * Findings that came or went in a window, each with its status: new, resolved, or changed for a
     * finding resolved and reported again with another extent by the same run (same code and title).
     *
     * @param  array<int, array>  $rows  rows of the findings history (analysis, code, title, first_seen, resolved_at)
     * @return array<int, array> the rows with `status`
     */
    public static function window(array $rows, int $from, int $to): array
    {
        $started = []; // analysis|code|title|time => rows of findings reported from that run on
        foreach ($rows as $i => $row) {
            $resolved = $row['resolved_at'] !== null ? strtotime($row['resolved_at']) : null;
            $rows[$i]['status'] = $resolved !== null && $resolved >= $from && $resolved <= $to ? 'resolved' : 'new';
            if ($rows[$i]['status'] === 'new') {
                $started[$row['analysis'] . '|' . $row['code'] . '|' . $row['title'] . '|' . $row['first_seen']][] = $i;
            }
        }
        foreach ($rows as $i => $row) {
            $key = $row['analysis'] . '|' . $row['code'] . '|' . $row['title'] . '|' . $row['resolved_at'];
            if ($row['status'] === 'resolved' && ! empty($started[$key])) {
                $rows[array_shift($started[$key])]['status'] = 'changed';
                unset($rows[$i]);
            }
        }

        return array_values($rows);
    }

    /**
     * Title as stored and logged ("Single point of failure: dist1").
     */
    public static function title(array $finding): string
    {
        return mb_substr(ucfirst((string) $finding['title']), 0, 512);
    }

    private static function state(string $code, string $severity, array $devices): array
    {
        return ['code' => $code, 'severity' => $severity, 'devices' => array_map('intval', $devices)];
    }

    /**
     * Check code => device => worst severity of its confirmed findings.
     *
     * @param  array<int, array{code: string, severity: string, devices: int[]}>  $findings
     * @param  string[]  $skip
     * @return array<string, array<int, string>>
     */
    private static function byDevice(array $findings, array $skip): array
    {
        $states = [];
        foreach ($findings as $finding) {
            if (in_array($finding['code'], $skip, true)) {
                continue;
            }
            foreach ($finding['devices'] as $device) {
                $worst = $states[$finding['code']][$device] ?? null;
                if ($worst === null || (self::RANKS[$finding['severity']] ?? 0) > (self::RANKS[$worst] ?? 0)) {
                    $states[$finding['code']][$device] = $finding['severity'];
                }
            }
        }

        return $states;
    }
}
