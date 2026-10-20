<?php

namespace Agoodley\LibrenmsTopology\History;

use Agoodley\LibrenmsTopology\Stp\Finding;

/**
 * Findings history of the background checks. A finding is the same finding while its code,
 * devices, ports and VLAN stay the same; its wording and severity may change (the history keeps the
 * highest severity of a period). A finding is confirmed once two runs in a row reported it, or when
 * it takes the place of a finding of the same check and VLAN about the same devices that is still
 * open and that this run no longer reports (a finding whose ports change from run to run is
 * confirmed on its second run like any other). The devices a finding is about are those its title
 * names ("redundancy lost: acc-sw7" is about acc-sw7, not about the core switch at the other end of
 * the lost uplink, which the findings of other access switches share), kept by id when it is
 * recorded, so a device renamed later is still the same; a finding whose title names none of its
 * devices is about all of them. Two findings are about the same devices when one is about all the
 * devices the other is about. A finding is resolved once two runs in a row did not report it, so a
 * check that flickers from run to run (error counters of the last poll) does not fill the eventlog;
 * a finding that was never confirmed ends at its first absence. If it comes back later it starts a
 * new period.
 *
 * The eventlog of a device gets an entry when the highest severity of its confirmed findings of a
 * check reaches the eventlog severity, and when it has none at that severity left. The entry names
 * the check only: findings are computed over the whole network and name other devices, and a
 * device's eventlog is shown to everyone who may see that device.
 */
class FindingHistory
{
    /** Eventlog severity ranks: a finding is written when it is at least as severe as the setting. */
    private const RANKS = [Finding::INFO => 1, Finding::WARNING => 2, Finding::CRITICAL => 3];

    /** Devices whose eventlog gets the entry of one check and run (the first ones). */
    public const EVENTLOG_DEVICES = 10;

    /** Analyses as named in eventlog entries. */
    private const ANALYSIS_NAMES = ['stp' => 'spanning tree', 'links' => 'link & VLAN', 'redundancy' => 'redundancy', 'routing' => 'routing & IP'];

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
        'udld' => 'UDLD',
        'bpdu_guard' => 'BPDU guard',
        'mac_flapping' => 'MAC flapping',
        'err_disabled' => 'Err-disabled port',
        'syslog_native_vlan' => 'Native VLAN mismatch (syslog)',
        'syslog_duplex_mismatch' => 'Duplex mismatch (syslog)',
        'ospf_duplicate_router_id' => 'Duplicate OSPF router ID',
        'ospf_mtu_mismatch' => 'OSPF MTU mismatch',
        'ospf_neighbor_stuck' => 'OSPF adjacency not full',
        'ospf_timer_mismatch' => 'OSPF timer mismatch',
        'ospf_area_mismatch' => 'OSPF area mismatch',
        'ospf_area_type_mismatch' => 'OSPF area type mismatch',
        'ospf_auth_mismatch' => 'OSPF authentication mismatch',
        'ospf_network_type_mismatch' => 'OSPF network type mismatch',
        'ospf_lsdb_mismatch' => 'OSPF databases differ',
        'ospf_cost_asymmetric' => 'Asymmetric OSPF cost',
        'ospf_dr_on_p2p' => 'OSPF DR election on a point-to-point link',
        'bgp_remote_as_mismatch' => 'BGP remote AS mismatch',
        'bgp_one_sided' => 'BGP session configured on one side',
        'bgp_session_down' => 'BGP session down',
        'bgp_prefix_limit' => 'BGP prefix limit',
        'bgp_recent_reset' => 'BGP session reset',
        'bgp_no_prefixes' => 'BGP session without prefixes',
        'ip_duplicate' => 'Duplicate IP address',
        'ip_mask_mismatch' => 'Subnet mask mismatch',
        'ip_vrf_mismatch' => 'Subnet in two VRFs',
    ];

    /** Words of check codes. */
    private const WORDS = ['lag' => 'port-channel', 'stp' => 'spanning tree', 'mtu' => 'MTU', 'vlan' => 'VLAN', 'tc' => 'topology change', 'spof' => 'single point of failure'];

    /**
     * Identity of a finding of an analysis: its code, devices, ports and VLAN, and the VRFs of a
     * routing finding (the same session down in two VRFs is two findings).
     */
    public static function hash(string $analysis, array $finding): string
    {
        $devices = array_map('intval', $finding['devices'] ?? []);
        $ports = array_map('intval', $finding['ports'] ?? []);
        sort($devices);
        sort($ports);
        $parts = [
            $analysis,
            $finding['code'],
            $finding['vlan'] ?? '',
            implode(',', array_unique($devices)),
            implode(',', array_unique($ports)),
        ];
        if (isset($finding['vrfs'])) {
            $vrfs = array_map('strval', $finding['vrfs']);
            sort($vrfs);
            $parts[] = 'vrf ' . implode(',', array_unique($vrfs));
        }

        return sha1(implode('|', $parts));
    }

    /**
     * One run of an analysis against its open findings.
     *
     * @param  array<int, array{id: int, hash: string, code: string, severity: string, title: string, devices: int[],
     *     vlan?: ?int, subjects?: ?int[], confirmed: bool, missed_at: ?string, first_seen: string}>  $open  open findings (not
     *     resolved), oldest first; subjects as recorded (null: from the title)
     * @param  array<int, array>  $findings  findings of this run
     * @param  string  $time  time of this run (Y-m-d H:i:s)
     * @param  bool  $initial  first successful run of the analysis: its findings existed before and are confirmed
     * @param  array<int, string>  $names  device id => name of the devices of the findings, as their titles name them
     * @return array{insert: array<string, array>, confirmed: string[], resolve: array<int, string>, miss: int[], unmiss: int[],
     *     confirm: int[], update: array<int, array>, before: array<int, array>, after: array<int, array>} findings to insert
     *     (hash => finding with the subjects to record) and the hashes of those inserted as confirmed, id => resolved_at, ids
     *     missed by this run, ids back after one miss, ids confirmed now, id => finding with another wording or a higher
     *     severity (and its subjects), and the confirmed findings (code, highest severity, devices) before and after the run
     */
    public static function run(string $analysis, array $open, array $findings, string $time, bool $initial, array $names = []): array
    {
        $current = [];
        foreach ($findings as $finding) {
            $current[self::hash($analysis, $finding)] ??= $finding;
        }

        // findings still open (missed by the previous run or not) that this run no longer reports, by check, VLAN and the
        // devices they are about: a new finding of the same check and VLAN about the same devices takes the place of one
        $gone = [];
        foreach ($open as $row) {
            if (! isset($current[$row['hash']])) {
                $subjects = self::subjects($row, $names)['devices'];
                foreach ($subjects as $device) {
                    $gone[self::check($row) . '|' . $device][implode(',', $subjects)] = $subjects;
                }
            }
        }
        $succeeds = function (array $finding) use ($gone, $names): bool {
            if (! $gone) {
                return false;
            }
            $subjects = self::subjects($finding, $names)['devices'];
            foreach ($subjects as $device) {
                foreach ($gone[self::check($finding) . '|' . $device] ?? [] as $other) {
                    // one is about all the devices the other is about, not just about a core switch both have
                    if (! array_diff($subjects, $other) || ! array_diff($other, $subjects)) {
                        return true;
                    }
                }
            }

            return false;
        };

        $result = ['insert' => $current, 'confirmed' => [], 'resolve' => [], 'miss' => [], 'unmiss' => [], 'confirm' => [], 'update' => [],
            'before' => [], 'after' => []];
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
                } elseif (! $row['confirmed']) {
                    $result['confirm'][] = $id; // reported by the previous run (whenever it ran) and this one
                }
                // the highest severity of the period: a finding that gets milder stays open as what it was
                $severity = self::higher($row['severity'], $finding['severity']);
                if ($severity !== $row['severity'] || $row['title'] !== self::title($finding)) {
                    $result['update'][$id] = ['severity' => $severity, 'subjects' => self::recorded(self::subjects(['subjects' => null] + $finding, $names))]
                        + $finding;
                }
                if ($row['confirmed'] || in_array($id, $result['confirm'], true)) {
                    $result['after'][] = self::state($finding['code'], $severity, $row['devices']);
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
        foreach ($result['insert'] as $hash => $finding) {
            $result['insert'][$hash]['subjects'] = self::recorded(self::subjects(['subjects' => null] + $finding, $names));
            // the first run finds what existed before; a successor goes on where its predecessor was
            if ($initial || $succeeds($finding)) {
                $result['confirmed'][] = $hash;
                $result['after'][] = self::state($finding['code'], $finding['severity'], $finding['devices'] ?? []);
            }
        }

        return $result;
    }

    /**
     * Eventlog entries of a run. A device's state of a check is the highest severity of its confirmed
     * findings of the check (a finding that got milder keeps its highest one, see run()): it gets an
     * entry when that reaches $minimum and one when it no longer does, so when its findings at that
     * severity are gone; 'none' writes nothing. A skipped check writes no entries, nor does a check at
     * a skipped severity (code:severity): its state at that severity counts as below $minimum. At
     * most EVENTLOG_DEVICES devices per check and run get the entry; it says that more devices have it,
     * not how many (a reader of one device's eventlog may not see the others).
     *
     * @param  array<int, array{code: string, severity: string, devices: int[]}>  $before  confirmed findings before the run
     * @param  array<int, array{code: string, severity: string, devices: int[]}>  $after  confirmed findings after the run
     * @param  string[]  $skip  check codes kept out of the eventlog, or code:severity for the findings of a check with that
     *                          severity (ip_duplicate:info)
     * @return array<int, array{device_id: int, message: string, severity: string, reference: string}> severity: critical,
     *                                                                                                  warning, info or ok
     */
    public static function eventlog(string $analysis, array $before, array $after, string $minimum, array $skip = []): array
    {
        $rank = self::RANKS[$minimum] ?? null;
        if ($rank === null) {
            return [];
        }

        $skip = array_flip($skip);
        $logged = fn (string $code, ?string $severity) => $severity !== null && (self::RANKS[$severity] ?? 0) >= $rank
            && ! isset($skip[$code]) && ! isset($skip["$code:$severity"]);
        $was = self::byDevice($before);
        $now = self::byDevice($after);
        $name = self::ANALYSIS_NAMES[$analysis] ?? $analysis;
        $entries = [];
        foreach (['new' => [$now, $was], 'resolved' => [$was, $now]] as $change => [$present, $absent]) {
            foreach ($present as $code => $devices) {
                $devices = array_filter($devices, fn ($severity, $device) => $logged($code, $severity) && ! $logged($code, $absent[$code][$device] ?? null),
                    ARRAY_FILTER_USE_BOTH);
                ksort($devices);
                $more = count($devices) - self::EVENTLOG_DEVICES;
                foreach (array_slice($devices, 0, self::EVENTLOG_DEVICES, true) as $device => $severity) {
                    $entries[] = [
                        'device_id' => $device,
                        'message' => ($change === 'new'
                            ? 'Topology ' . $severity . ': ' . self::label($code) . " ($name check), details: plugin/topology/$analysis?device=$device"
                            : 'Topology resolved: ' . self::label($code) . " ($name check)")
                            . ($more > 0 ? ' (and on other devices)' : ''),
                        'severity' => $change === 'new' ? $severity : 'ok',
                        'reference' => mb_substr("topology:$analysis:$code", 0, 64),
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * The Checks kept out of the eventlog setting as a list: check codes, or code:severity (spaces
     * around the colon allowed), in lowercase.
     *
     * @return string[]
     */
    public static function skipList(string $setting): array
    {
        $setting = strtolower((string) preg_replace('/\s*:\s*/', ':', $setting));

        return array_values(array_filter(preg_split('/[\s,;]+/', $setting) ?: [], fn (string $token) => $token !== ''));
    }

    /**
     * Entries of the setting that are no check code of $codes, or no code:severity of one: they keep
     * nothing out of the eventlog.
     *
     * @param  string[]  $codes
     * @return string[]
     */
    public static function unknownSkips(string $setting, array $codes): array
    {
        $codes = array_flip($codes);

        return array_values(array_filter(self::skipList($setting), function (string $token) use ($codes) {
            $parts = explode(':', $token, 2);

            return ! isset($codes[$parts[0]]) || (isset($parts[1]) && ! isset(self::RANKS[$parts[1]]));
        }));
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
     * Devices a finding (or a row of the findings history) is about: those of its devices its title
     * names, as whole names in any case, or all of them when it names none (`named` false). A row
     * that recorded them (`subjects`, an empty list when its title named none) keeps them by id.
     *
     * @param  array<int, string>  $names  device id => name
     * @return array{devices: int[], named: bool}
     */
    public static function subjects(array $finding, array $names): array
    {
        $devices = array_values(array_unique(array_map('intval', $finding['devices'] ?? [])));
        sort($devices);
        if (is_array($finding['subjects'] ?? null)) {
            $named = array_values(array_intersect($devices, array_map('intval', $finding['subjects'])));

            return $named ? ['devices' => $named, 'named' => true] : ['devices' => $devices, 'named' => false];
        }
        $title = (string) ($finding['title'] ?? '');
        $named = array_values(array_filter($devices, function (int $device) use ($names, $title) {
            $name = (string) ($names[$device] ?? '');

            return $name !== '' && stripos($title, $name) !== false
                && preg_match('/(?<![\w.-])' . preg_quote($name, '/') . '(?![\w.-])/iu', $title) === 1;
        }));

        return $named ? ['devices' => $named, 'named' => true] : ['devices' => $devices, 'named' => false];
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
     * Check and VLAN of a finding or row: a successor has the same.
     */
    private static function check(array $finding): string
    {
        $vlan = $finding['vlan'] ?? null;

        return $finding['code'] . '|' . ($vlan === null || $vlan === '' ? '' : (int) $vlan);
    }

    /**
     * The devices a title names, as a row records them: an empty list when it names none.
     *
     * @param  array{devices: int[], named: bool}  $subjects
     * @return int[]
     */
    private static function recorded(array $subjects): array
    {
        return $subjects['named'] ? $subjects['devices'] : [];
    }

    /**
     * The more severe of two severities.
     */
    private static function higher(string $a, string $b): string
    {
        return (self::RANKS[$b] ?? 0) > (self::RANKS[$a] ?? 0) ? $b : $a;
    }

    /**
     * Check code => device => highest severity of its confirmed findings.
     *
     * @param  array<int, array{code: string, severity: string, devices: int[]}>  $findings
     * @return array<string, array<int, string>>
     */
    private static function byDevice(array $findings): array
    {
        $states = [];
        foreach ($findings as $finding) {
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
