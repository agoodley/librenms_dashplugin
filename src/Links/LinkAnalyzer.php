<?php

namespace Agoodley\LibrenmsTopology\Links;

use Agoodley\LibrenmsTopology\Stp\Finding;
use Agoodley\LibrenmsTopology\Stp\StpAnalyzer;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\Graph;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Topology\L2Builder;

/**
 * Link and VLAN consistency checks. Both ends of every LLDP/CDP link between two monitored
 * devices are compared (speed, duplex, MTU, port mode, native and allowed VLANs,
 * port-channels), interface counters and optical receive levels are checked, VLANs are followed
 * across trunks to find VLANs split into unconnected parts, and port descriptions are compared
 * with the actual neighbours.
 * Pure PHP: all data is passed in as arrays so it can be tested without LibreNMS.
 */
class LinkAnalyzer
{
    /** Vendors count the Ethernet header, FCS and 802.1Q tag differently in ifMtu (1500 / 1514 / 1518 / 1522). */
    private const MTU_TOLERANCE = 22;

    /** An uneven LAG: the busiest member carries at least this many times the traffic of the least loaded one. */
    private const LAG_IMBALANCE_RATIO = 3;

    /** Fewer discarded packets per poll than this are not reported, whatever the percentage. */
    private const MIN_DISCARDS = 100;

    /** Names too generic to identify a device in a port description. */
    private const GENERIC_NAMES = ['switch', 'router', 'core', 'access', 'uplink', 'downlink', 'trunk', 'link', 'port', 'server', 'firewall', 'stack', 'local', 'default'];

    /** Check codes and their category; the "disabled checks" setting accepts either. */
    public const CHECKS = [
        'speed_mismatch' => 'mismatch',
        'duplex_mismatch' => 'mismatch',
        'half_duplex' => 'mismatch',
        'mtu_mismatch' => 'mismatch',
        'mode_mismatch' => 'vlans',
        'native_vlan_mismatch' => 'vlans',
        'access_vlan_mismatch' => 'vlans',
        'trunk_vlan_mismatch' => 'vlans',
        'vlan_split' => 'vlans',
        'lag_unbundled' => 'lags',
        'lag_split' => 'lags',
        'lag_degraded' => 'lags',
        'lag_imbalance' => 'lags',
        'link_errors' => 'counters',
        'link_discards' => 'counters',
        'optical_rx' => 'optics',
        'neighbor_not_reported' => 'neighbors',
        'no_neighbor_data' => 'neighbors',
        'description_wrong' => 'descriptions',
        'description_missing' => 'descriptions',
    ];

    /** Order of the categories within one severity: what breaks traffic first. */
    private const CATEGORY_ORDER = ['vlans', 'mismatch', 'lags', 'optics', 'counters', 'descriptions', 'neighbors'];

    /** @var array<int, true> analyzed devices; other entries of $devices are monitored neighbours outside the scope */
    private array $scope;

    /** @var array<string, array> physical links between two monitored devices, keyed by port pair */
    private array $physical = [];

    /** @var array<string, array> links with port-channel members collapsed, keyed by "device#port|device#port" */
    private array $logical = [];

    /** @var array<int, array> LLDP/CDP neighbours that are not monitored, or whose port LibreNMS could not resolve */
    private array $partial = [];

    /** @var array<int, int[]> aggregate port_id => member port_ids (reverse of $lagParent) */
    private array $lagMembers = [];

    /** @var array<int, true> devices that report port-channel membership at all (ifStackTable / PAgP) */
    private array $lagDevices = [];

    /** @var array<int, array<int, true>> port (or aggregate) => devices at the other end of its links */
    private array $farOf = [];

    /** @var array<int, array<int, array<int, true>>> device => local port => monitored neighbour, from its LLDP/CDP table */
    private array $reports = [];

    /** @var array<int, true> devices with at least one LLDP/CDP neighbour */
    private array $reporting = [];

    /** @var array<string, true> disabled check codes and categories */
    private array $disabled;

    /** @var array<int, array> port_id => mode / VLANs / native VLAN, see vlanEnd() */
    private array $vlanEnds = [];

    /** @var Finding[] */
    private array $findings = [];

    /**
     * @param  array<int, array>  $devices  device_id => device row (display, hostname, sysName, os) of the analyzed
     *                                       devices and of monitored neighbours outside the scope
     * @param  int[]  $scope  analyzed devices (the group / location filter)
     * @param  array<int, array>  $links  rows of the links table reported by the analyzed devices
     * @param  array<int, array>  $ports  port_id => port row (link ports, all aggregates and the members of linked
     *                                     aggregates) with the 'has_ip' flag
     * @param  array<int, array<int, bool>>  $portVlans  port_id => [vlan => untagged]
     * @param  array<int, int>  $lagParent  member port_id => aggregate port_id
     * @param  array<int, array<int, true>>  $activeVlans  device_id => VLANs in use on the device (hosts or a VLAN interface)
     * @param  array<int, array>  $statistics  port_id => ports_statistics row (discards and non-unicast packets)
     * @param  array<int, array{rx: float, low: ?float, low_warn: ?float, sensor: string}>  $optics  port_id => weakest
     *                                                                                                 optical receive level
     */
    public function __construct(
        private array $devices,
        array $scope,
        private array $links,
        private array $ports,
        private array $portVlans,
        private array $lagParent,
        private PluginSettings $settings,
        private array $activeVlans = [],
        private array $statistics = [],
        private array $optics = [],
    ) {
        $this->scope = array_fill_keys(array_map('intval', $scope), true);
        foreach ($this->lagParent as $member => $parent) {
            $this->lagMembers[$parent][] = $member;
            if (isset($this->ports[$parent]['device_id'])) {
                $this->lagDevices[(int) $this->ports[$parent]['device_id']] = true;
            }
        }
        $this->disabled = array_fill_keys(array_map('strtolower', $settings->list('links_disabled_checks')), true);
        $this->prepareLinks();
    }

    /**
     * Run every check and return the data of the link diagnostics page: summary, findings and one
     * row per link (port-channels collapsed) with both ends side by side.
     */
    public function analyze(): array
    {
        $this->findings = [];

        $this->checkPhysical();
        $this->checkModes();
        $this->checkLags();
        $covered = $this->checkSplitVlans();
        $this->checkTrunkVlans($covered);
        $this->checkNeighbors();
        $this->checkDescriptions();

        $findings = array_map(fn (Finding $f) => $f->toArray() + ['category' => self::CHECKS[$f->code] ?? 'other'], $this->findings);
        $categoryOrder = array_flip(self::CATEGORY_ORDER);
        usort($findings, fn ($a, $b) => [Finding::SEVERITY_ORDER[$a['severity']], $categoryOrder[$a['category']] ?? 99, $a['title']]
            <=> [Finding::SEVERITY_ORDER[$b['severity']], $categoryOrder[$b['category']] ?? 99, $b['title']]);

        $rows = $this->linkRows($findings);

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
            'links' => $rows,
        ];
    }

    /**
     * Summary cards of a (possibly filtered) result.
     */
    public static function summarize(array $rows, array $findings): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        $categories = array_fill_keys(array_values(array_unique(self::CHECKS)), 0);
        foreach ($findings as $finding) {
            $counts[$finding['severity']]++;
            $categories[$finding['category']] = ($categories[$finding['category']] ?? 0) + 1;
        }

        $devices = [];
        foreach ($rows as $row) {
            $devices[$row['a']['device_id']] = true;
            $devices[$row['b']['device_id']] = true;
        }

        return [
            'links' => count($rows),
            'physical' => array_sum(array_map(fn ($row) => $row['lag']['members'] ?? 1, $rows)),
            'lags' => count(array_filter($rows, fn ($row) => $row['lag'] !== null)),
            'devices' => count($devices),
            'with_issues' => count(array_filter($rows, fn ($row) => $row['severity'] !== 'ok')),
            'findings' => $counts,
            'categories' => $categories,
            'status' => $counts['critical'] ? 'critical' : ($counts['warning'] ? 'warning' : 'ok'),
        ];
    }

    // ---------------------------------------------------------------- physical links

    /**
     * Per cable: speed, duplex and MTU of both ends (while both are up), error and discard
     * counters of the last poll, and the optical receive level of each end.
     */
    private function checkPhysical(): void
    {
        foreach ($this->physical as $link) {
            $a = $this->ports[$link['a_port']];
            $b = $this->ports[$link['b_port']];
            $pair = $this->endLabel($link['a_port']) . ' ↔ ' . $this->endLabel($link['b_port']);
            $devices = [$link['a_dev'], $link['b_dev']];
            $ports = [$link['a_port'], $link['b_port']];

            if ($this->isUp($link['a_port']) && $this->isUp($link['b_port'])) {
                $speedA = (int) ($a['ifSpeed'] ?? 0);
                $speedB = (int) ($b['ifSpeed'] ?? 0);
                if ($speedA > 0 && $speedB > 0 && $speedA !== $speedB) {
                    $this->add(new Finding(
                        Finding::WARNING, 'speed_mismatch',
                        "speed mismatch: $pair",
                        $this->endLabel($link['a_port']) . ' runs at ' . self::speed($speedA) . ', ' . $this->endLabel($link['b_port']) . ' at ' . self::speed($speedB) . '.',
                        'Both ends of one cable always run at the same speed, so something sits in between (media converter, or an '
                        . 'unmanaged switch passing CDP/LLDP through) or one device reports a wrong speed. Check the cabling and autonegotiation.',
                        null, $devices, $ports,
                    ));
                }

                $duplexA = self::duplex($a['ifDuplex'] ?? null);
                $duplexB = self::duplex($b['ifDuplex'] ?? null);
                if (in_array('half', [$duplexA, $duplexB], true)) {
                    $mismatch = in_array('full', [$duplexA, $duplexB], true);
                    $this->add(new Finding(
                        Finding::WARNING, $mismatch ? 'duplex_mismatch' : 'half_duplex',
                        ($mismatch ? 'duplex mismatch: ' : 'half duplex link: ') . $pair,
                        $this->endLabel($link['a_port']) . ' runs ' . ($duplexA ?? 'unknown') . ' duplex, ' . $this->endLabel($link['b_port']) . ' ' . ($duplexB ?? 'unknown') . ' duplex.',
                        $mismatch
                            ? 'A duplex mismatch causes late collisions, CRC errors and very poor throughput. Use autonegotiation on both ends, or set the same speed and duplex on both.'
                            : 'Links between switches should run full duplex: check for a hub in between or failed autonegotiation.',
                        null, $devices, $ports,
                    ));
                }

                $mtuA = (int) ($a['ifMtu'] ?? 0);
                $mtuB = (int) ($b['ifMtu'] ?? 0);
                if ($mtuA > 0 && $mtuB > 0 && abs($mtuA - $mtuB) > self::MTU_TOLERANCE) {
                    $this->add(new Finding(
                        Finding::WARNING, 'mtu_mismatch',
                        "MTU mismatch: $pair",
                        $this->endLabel($link['a_port']) . " has MTU $mtuA, " . $this->endLabel($link['b_port']) . " MTU $mtuB.",
                        'Frames larger than the smaller MTU are silently dropped (jumbo frames for storage or vMotion, VXLAN/MPLS overhead), and '
                        . 'OSPF adjacencies over such a link stall in ExStart. Set the same MTU on both ends.',
                        null, $devices, $ports,
                    ));
                }
            }

            $this->checkCounters($link);
            $this->checkOptics($link);
        }
    }

    /**
     * Interface errors (bad cable, optic or connector) and discards (congestion) in the last poll.
     */
    private function checkCounters(array $link): void
    {
        $errorLimit = $this->settings->int('links_error_threshold', 0);
        $discardPercent = $this->settings->int('links_discard_percent', 0, 100);
        $errors = [];
        $discards = [];

        foreach ([$link['a_port'], $link['b_port']] as $portId) {
            $port = $this->ports[$portId];
            if (! $this->deviceUp((int) $port['device_id'])) {
                continue; // LibreNMS keeps the last counters of an unreachable device
            }
            $stats = $this->statistics[$portId] ?? [];
            $label = $this->endLabel($portId);
            $inErrors = self::delta($port, 'ifInErrors_delta');
            $outErrors = self::delta($port, 'ifOutErrors_delta');
            $inPackets = self::delta($port, 'ifInUcastPkts_delta') + self::delta($stats, 'ifInNUcastPkts_delta');
            $outPackets = self::delta($port, 'ifOutUcastPkts_delta') + self::delta($stats, 'ifOutNUcastPkts_delta');

            if ($errorLimit > 0 && $inErrors + $outErrors >= $errorLimit) {
                $parts = [];
                foreach (['input' => [$inErrors, $inPackets], 'output' => [$outErrors, $outPackets]] as $direction => [$count, $packets]) {
                    if ($count > 0) {
                        $share = self::percent($count, $packets + $count);
                        $parts[] = number_format($count) . " $direction errors" . ($share ? " ($share of packets)" : '');
                    }
                }
                $errors[$portId] = "$label: " . implode(', ', $parts) . self::period($port);
            }

            if ($discardPercent > 0) {
                $inDiscards = self::delta($stats, 'ifInDiscards_delta');
                $outDiscards = self::delta($stats, 'ifOutDiscards_delta');
                $parts = [];
                foreach (['incoming' => [$inDiscards, $inPackets], 'outgoing' => [$outDiscards, $outPackets]] as $direction => [$count, $packets]) {
                    if ($count >= self::MIN_DISCARDS && $count / ($packets + $count) * 100 >= $discardPercent) {
                        $parts[] = self::percent($count, $packets + $count) . " of $direction packets (" . number_format($count) . ')';
                    }
                }
                if ($parts) {
                    $discards[$portId] = "$label discarded " . implode(' and ', $parts) . self::period($port);
                }
            }
        }

        $pair = $this->endLabel($link['a_port']) . ' ↔ ' . $this->endLabel($link['b_port']);
        if ($errors) {
            $this->add(new Finding(
                Finding::WARNING, 'link_errors',
                "interface errors: $pair",
                implode('; ', $errors) . '.',
                'Input errors (CRC, framing) mean damaged frames arrive at that end: check the cable and patch panel, clean or replace the '
                . 'optics on both ends and look for a duplex mismatch. Output errors usually point at the local interface or a collision domain.',
                null, [$link['a_dev'], $link['b_dev']], array_keys($errors),
            ));
        }
        if ($discards) {
            $this->add(new Finding(
                Finding::WARNING, 'link_discards',
                "packet discards: $pair",
                implode('; ', $discards) . '.',
                'Outgoing discards mean the port ran out of buffers (congestion or microbursts): check the utilization, QoS and whether the '
                . 'link needs more capacity. Incoming discards are frames the device chose to drop (unknown VLAN, policy, overload).',
                null, [$link['a_dev'], $link['b_dev']], array_keys($discards),
            ));
        }
    }

    /**
     * Optical receive level of each end against the transceiver's own (DOM) thresholds.
     * LibreNMS never guesses dBm thresholds, so they come from the device.
     */
    private function checkOptics(array $link): void
    {
        $margin = $this->settings->int('links_rx_margin', 0, 20);
        if (! $this->adminUp($link['a_port']) || ! $this->adminUp($link['b_port'])) {
            return; // a port shut down on purpose sends no light
        }

        foreach ([[$link['a_port'], $link['b_port']], [$link['b_port'], $link['a_port']]] as [$portId, $farId]) {
            $optic = $this->optics[$portId] ?? null;
            if (! $optic || ! is_numeric($optic['rx'] ?? null) || ! $this->deviceUp((int) $this->ports[$portId]['device_id'])) {
                continue; // no reading, or the stale reading of an unreachable device
            }
            $rx = (float) $optic['rx'];
            $low = is_numeric($optic['low'] ?? null) ? (float) $optic['low'] : null;
            $warn = is_numeric($optic['low_warn'] ?? null) ? (float) $optic['low_warn'] : null;

            [$severity, $what] = match (true) {
                $low !== null && $rx <= $low => [Finding::CRITICAL, 'below the alarm threshold'],
                $warn !== null && $rx <= $warn => [Finding::WARNING, 'below the warning threshold'],
                $margin > 0 && $low !== null && $rx - $low <= $margin => [Finding::INFO, "within $margin dB of the alarm threshold"],
                default => [null, null],
            };
            if ($severity === null) {
                continue;
            }

            $limits = array_filter([
                $warn !== null ? 'warning ' . self::dbm($warn) : null,
                $low !== null ? 'alarm ' . self::dbm($low) : null,
            ]);
            $sensor = trim((string) ($optic['sensor'] ?? ''));
            $this->add(new Finding(
                $severity, 'optical_rx',
                "optical receive power $what: " . $this->endLabel($portId),
                $this->endLabel($portId) . ' receives ' . self::dbm($rx) . ' from ' . $this->endLabel($farId) . ($sensor !== '' ? " ($sensor)" : '')
                . '; transceiver thresholds: ' . implode(', ', $limits) . '.',
                'Clean the connectors, check patch cords, fiber length and splices, and compare with the transmit power of ' . $this->endLabel($farId) . '.',
                null, [$link['a_dev'], $link['b_dev']], [$portId, $farId],
            ));
        }
    }

    // ---------------------------------------------------------------- port modes and VLANs

    /**
     * Port mode of both ends of a link (port-channels as a whole): trunk against access port,
     * different native VLANs on a trunk, different VLANs on an access link.
     */
    private function checkModes(): void
    {
        foreach ($this->logical as $link) {
            $a = $this->vlanEnd($link['a_port']);
            $b = $this->vlanEnd($link['b_port']);
            if (array_intersect([$a['mode'], $b['mode']], ['routed', 'unknown'])) {
                continue;
            }

            $labelA = $this->endLabel($link['a_port']);
            $labelB = $this->endLabel($link['b_port']);
            $pair = "$labelA ↔ $labelB";
            $devices = [$link['a_dev'], $link['b_dev']];
            $ports = [$link['a_port'], $link['b_port']];

            if ($a['mode'] !== $b['mode']) {
                [$trunk, $access, $trunkLabel, $accessLabel] = $a['mode'] === 'trunk' ? [$a, $b, $labelA, $labelB] : [$b, $a, $labelB, $labelA];
                $this->add(new Finding(
                    Finding::WARNING, 'mode_mismatch',
                    "trunk / access mismatch: $pair",
                    "$trunkLabel is a trunk" . ($trunk['native'] ? " (native VLAN {$trunk['native']})" : '') . ", $accessLabel an access port in VLAN {$access['native']}.",
                    'Only untagged frames cross such a link: the access VLAN is bridged into the native VLAN of the trunk and every tagged VLAN is '
                    . 'dropped. Configure both ends as trunks or both as access ports.',
                    null, $devices, $ports,
                ));
                continue;
            }

            if ($a['native'] === null || $b['native'] === null || $a['native'] === $b['native']) {
                continue;
            }

            if ($a['mode'] === 'trunk') {
                $this->add(new Finding(
                    Finding::WARNING, 'native_vlan_mismatch',
                    "native VLAN mismatch: $pair",
                    "$labelA uses native VLAN {$a['native']}, $labelB native VLAN {$b['native']}.",
                    "Untagged frames from one side arrive in a different VLAN on the other, merging VLANs {$a['native']} and {$b['native']}; "
                    . 'Rapid-PVST+ blocks such ports as PVID-inconsistent. Use the same native VLAN on both ends.',
                    null, $devices, $ports,
                ));
            } else {
                $this->add(new Finding(
                    Finding::WARNING, 'access_vlan_mismatch',
                    "access VLAN mismatch: $pair",
                    "$labelA is an access port in VLAN {$a['native']}, $labelB in VLAN {$b['native']}.",
                    "VLANs {$a['native']} and {$b['native']} are bridged together through this link. Put both ends in the same VLAN or make the link a trunk.",
                    null, $devices, $ports,
                ));
            }
        }
    }

    /**
     * VLANs in use on at least two devices of one L2 domain that are not connected to each other
     * in that VLAN: a trunk on the way does not carry it. Islands that may be joined outside the
     * analysis (through an unmanaged switch or a device outside the scope) are treated as one.
     * The finding names where the VLAN stops: trunks between two parts and the links on the
     * shortest path from every other part to the largest one.
     *
     * @return array<string, array<int, true>> logical link => VLANs already reported here
     */
    private function checkSplitVlans(): array
    {
        $covered = [];
        if (! $this->enabled('vlan_split')) {
            return $covered;
        }

        $ignore = $this->ignoredVlans();
        $domain = new Graph;
        $inside = [];   // logical key => link between two analyzed devices (switchports only)
        $exits = [];    // [device, port end, external node] VLAN may continue outside the analysis
        $joins = [];    // [device, device, port end, port] analyzed neighbours whose port LibreNMS did not resolve
        $participating = [];

        foreach ($this->logical as $key => $link) {
            $a = $this->vlanEnd($link['a_port']);
            $b = $this->vlanEnd($link['b_port']);
            $inA = isset($this->scope[$link['a_dev']]);
            $inB = isset($this->scope[$link['b_dev']]);
            if ($a['mode'] === 'routed' || $b['mode'] === 'routed' || (! $inA && ! $inB)) {
                continue;
            }
            if ($inA && $inB) {
                $inside[$key] = [$a, $b];
                $domain->addEdge($link['a_dev'], $link['b_dev'], $key);
                $participating[$link['a_dev']] = $participating[$link['b_dev']] = true;
            } else {
                [$deviceId, $end] = $inA ? [$link['a_dev'], $a] : [$link['b_dev'], $b];
                $exits[] = [$deviceId, $end, 'x:outside'];
                $participating[$deviceId] = true;
            }
        }

        foreach ($this->partial as $index => $partial) {
            $end = $this->vlanEnd($partial['port_id']);
            $deviceId = $partial['device_id'];
            if ($end['mode'] === 'routed' || ! isset($this->scope[$deviceId])) {
                continue;
            }
            $remote = $partial['remote_device_id'];
            if ($remote && isset($this->scope[$remote])) {
                $joins[] = [$deviceId, $remote, $end, $partial['port_id']];
                $domain->addEdge($deviceId, $remote, 'p' . $index);
                $participating[$deviceId] = $participating[$remote] = true;
            } elseif ($remote) {
                $exits[] = [$deviceId, $end, 'x:outside'];
                $participating[$deviceId] = true;
            } elseif (in_array(DeviceRole::fromPlatform($partial['platform'], $partial['host']), [DeviceRole::SWITCH, DeviceRole::UNKNOWN], true)) {
                // an unmanaged switch may carry the VLAN on to anything behind it
                $exits[] = [$deviceId, $end, 'x:' . self::hostKey($partial['host'], $deviceId . ':' . $partial['port_id'])];
                $participating[$deviceId] = true;
            }
        }

        // VLANs in use on at least two analyzed devices that take part in the topology
        $users = [];
        foreach ($this->activeVlans as $deviceId => $vlans) {
            if (! isset($this->scope[$deviceId], $participating[$deviceId])) {
                continue;
            }
            foreach (array_keys($vlans) as $vlan) {
                if (! isset($ignore[$vlan])) {
                    $users[$vlan][] = (int) $deviceId;
                }
            }
        }
        $users = array_filter($users, fn ($ids) => count($ids) >= 2);
        if (! $users) {
            return $covered;
        }

        $domainOf = [];
        foreach ($domain->components() as $index => $component) {
            foreach ($component as $node) {
                $domainOf[$node] = $index;
            }
        }

        // the L2 domains as adjacency lists, to find the way between two parts of a split VLAN
        $adjacency = [];
        foreach (array_keys($inside) as $key) {
            $adjacency[$this->logical[$key]['a_dev']][] = [$this->logical[$key]['b_dev'], $key];
            $adjacency[$this->logical[$key]['b_dev']][] = [$this->logical[$key]['a_dev'], $key];
        }
        foreach ($joins as $index => [$deviceId, $remote]) {
            $adjacency[$deviceId][] = [$remote, 'j' . $index];
            $adjacency[$remote][] = [$deviceId, 'j' . $index];
        }

        ksort($users);
        foreach ($users as $vlan => $deviceIds) {
            $graph = new Graph;
            foreach ($deviceIds as $deviceId) {
                $graph->addNode($deviceId);
            }

            $carried = []; // links of the L2 domains that carry the VLAN
            $half = [];    // logical key => end that carries the VLAN while the other does not
            foreach ($inside as $key => [$a, $b]) {
                $carriesA = self::carries($a, $vlan);
                $carriesB = self::carries($b, $vlan);
                if ($carriesA !== false && $carriesB !== false) {
                    $graph->addEdge($this->logical[$key]['a_dev'], $this->logical[$key]['b_dev'], $key);
                    $carried[$key] = true;
                } elseif ($carriesA === true || $carriesB === true) {
                    $half[$key] = $carriesA === true ? 'a' : 'b';
                }
            }
            foreach ($joins as $index => [$deviceId, $remote, $end]) {
                if (self::carries($end, $vlan) !== false) {
                    $graph->addEdge($deviceId, $remote, 'j' . $index);
                    $carried['j' . $index] = true;
                }
            }
            foreach ($exits as $index => [$deviceId, $end, $node]) {
                if (self::carries($end, $vlan) !== false) {
                    $graph->addEdge($deviceId, $node, 'x' . $index);
                }
            }

            $userSet = array_flip($deviceIds);
            $byDomain = [];
            foreach ($graph->components() as $component) {
                $members = array_values(array_filter($component, fn ($node) => is_int($node) && isset($userSet[$node])));
                if (! $members) {
                    continue;
                }
                sort($members);
                $byDomain[$domainOf[$members[0]] ?? 'alone' . $members[0]][] = ['members' => $members, 'nodes' => $component];
            }

            foreach ($byDomain as $islands) {
                if (count($islands) < 2) {
                    continue;
                }
                usort($islands, fn ($x, $y) => count($y['members']) <=> count($x['members']) ?: $x['members'][0] <=> $y['members'][0]);

                $islandOf = [];
                foreach ($islands as $index => $island) {
                    foreach ($island['nodes'] as $node) {
                        $islandOf[$node] = $index;
                    }
                }

                // where the VLAN stops: trunks between two parts that carry it on one end only, and the
                // links on the shortest way from every other part to the largest one, which can cross
                // switches that do not carry the VLAN at all
                $stops = [];
                foreach ($half as $key => $carrier) {
                    $link = $this->logical[$key];
                    if (isset($islandOf[$link['a_dev']], $islandOf[$link['b_dev']]) && $islandOf[$link['a_dev']] !== $islandOf[$link['b_dev']]) {
                        $stops[$key] = true;
                    }
                }
                $largest = array_flip(array_filter($islands[0]['nodes'], 'is_int'));
                foreach (array_slice($islands, 1) as $island) {
                    foreach (self::path($adjacency, array_filter($island['nodes'], 'is_int'), $largest) as $edge) {
                        if (! isset($carried[$edge])) {
                            $stops[$edge] = true;
                        }
                    }
                }

                $stopLabels = [];
                $ports = [];
                $oneEnded = false;
                foreach (array_keys($stops) as $edge) {
                    if (! isset($this->logical[$edge])) {
                        [, $remote, , $portId] = $joins[(int) substr((string) $edge, 1)];
                        $stopLabels[] = $this->endLabel($portId) . ' (toward ' . $this->deviceName($remote) . ') does not carry it';
                        $ports[] = $portId;
                        continue;
                    }
                    $link = $this->logical[$edge];
                    $labelA = $this->endLabel($link['a_port']);
                    $labelB = $this->endLabel($link['b_port']);
                    $stopLabels[] = match ($half[$edge] ?? null) {
                        'a' => "$labelA carries it, $labelB does not",
                        'b' => "$labelB carries it, $labelA does not",
                        default => "$labelA ↔ $labelB does not carry it",
                    };
                    $oneEnded = $oneEnded || isset($half[$edge]);
                    $ports[] = $link['a_port'];
                    $ports[] = $link['b_port'];
                    $covered[$edge][$vlan] = true;
                }

                $parts = [];
                $members = [];
                foreach (array_slice($islands, 0, 6) as $index => $island) {
                    $parts[] = 'part ' . ($index + 1) . ': ' . $this->names(array_slice($island['members'], 0, 8))
                        . (count($island['members']) > 8 ? ' (+' . (count($island['members']) - 8) . ' more)' : '');
                }
                foreach ($islands as $island) {
                    $members = array_merge($members, $island['members']);
                }

                $this->add(new Finding(
                    $oneEnded ? Finding::WARNING : Finding::INFO, 'vlan_split',
                    "VLAN $vlan is split into " . count($islands) . ' unconnected parts',
                    "VLAN $vlan is in use (hosts or a VLAN interface) on switches that no trunk path connects in this VLAN: " . implode('; ', $parts) . '.'
                    . ($stopLabels ? ' Where it stops: ' . implode('; ', array_slice($stopLabels, 0, 10)) . '.' : ''),
                    "Allow VLAN $vlan on both ends of the trunks between these switches, or remove it where it should not be used. If the VLAN "
                    . 'is local to each switch by design, add it to the ignored VLANs setting. Links without LLDP/CDP are invisible to this check.',
                    $vlan, $members, $ports,
                ));
            }
        }

        return $covered;
    }

    /**
     * VLANs in use on both devices of a trunk but allowed on one end only (not already reported
     * as a split VLAN): the trunk silently drops them, so it does not back up the VLAN's other paths.
     *
     * @param  array<string, array<int, true>>  $covered  logical link => VLANs reported as split
     */
    private function checkTrunkVlans(array $covered): void
    {
        $ignore = $this->ignoredVlans();
        foreach ($this->logical as $key => $link) {
            $a = $this->vlanEnd($link['a_port']);
            $b = $this->vlanEnd($link['b_port']);
            if ($a['mode'] !== 'trunk' || $b['mode'] !== 'trunk' || $a['vlans'] === null || $b['vlans'] === null) {
                continue;
            }

            $relevant = fn (int $vlan) => isset($this->activeVlans[$link['a_dev']][$vlan], $this->activeVlans[$link['b_dev']][$vlan])
                && ! isset($ignore[$vlan]) && ! isset($covered[$key][$vlan]);
            $onlyA = array_values(array_filter(array_keys(array_diff_key($a['vlans'], $b['vlans'])), $relevant));
            $onlyB = array_values(array_filter(array_keys(array_diff_key($b['vlans'], $a['vlans'])), $relevant));
            if (! $onlyA && ! $onlyB) {
                continue;
            }

            $labelA = $this->endLabel($link['a_port']);
            $labelB = $this->endLabel($link['b_port']);
            $parts = [];
            if ($onlyA) {
                $parts[] = "$labelA carries VLAN " . self::ranges($onlyA) . " that $labelB does not";
            }
            if ($onlyB) {
                $parts[] = "$labelB carries VLAN " . self::ranges($onlyB) . " that $labelA does not";
            }

            $this->add(new Finding(
                Finding::WARNING, 'trunk_vlan_mismatch',
                "allowed VLANs differ: $labelA ↔ $labelB",
                implode('; ', $parts) . ', although both switches use these VLANs.',
                'Traffic of these VLANs is dropped at the end that does not carry them, so this trunk does not back up their other paths. '
                . 'Make the allowed VLAN lists of both ends match.',
                null, [$link['a_dev'], $link['b_dev']], [$link['a_port'], $link['b_port']],
            ));
        }
    }

    // ---------------------------------------------------------------- port-channels

    /**
     * Port-channel problems: aggregate on one end only, members landing in different aggregates,
     * members down, uneven load across the members.
     */
    private function checkLags(): void
    {
        $unbundled = []; // "device#aggregate|far device" => [far port => local member]
        $landing = [];   // "device#aggregate" => far device => far aggregate => far member ports

        foreach ($this->physical as $link) {
            foreach ([['a', 'b'], ['b', 'a']] as [$x, $y]) {
                $aggregate = $this->lagParent[$link[$x . '_port']] ?? null;
                if ($aggregate === null) {
                    continue;
                }
                $farPort = $link[$y . '_port'];
                $farDevice = $link[$y . '_dev'];
                $farAggregate = $this->lagParent[$farPort] ?? null;
                if ($farAggregate === null) {
                    // only trust a missing aggregate when the far device reports port-channels at all
                    if (isset($this->lagDevices[$farDevice])) {
                        $unbundled[$link[$x . '_dev'] . '#' . $aggregate . '|' . $farDevice][$farPort] = $link[$x . '_port'];
                    }
                } else {
                    $landing[$link[$x . '_dev'] . '#' . $aggregate][$farDevice][$farAggregate][] = $farPort;
                }
            }
        }

        foreach ($unbundled as $key => $farPorts) {
            [$local, $farDevice] = explode('|', $key);
            [$deviceId, $aggregate] = array_map('intval', explode('#', $local));
            $farDevice = (int) $farDevice;
            $farLabels = array_map(fn ($port) => $this->portName($port), array_keys($farPorts));
            $this->add(new Finding(
                Finding::WARNING, 'lag_unbundled',
                'port-channel on one end only: ' . $this->endLabel($aggregate),
                $this->endLabel($aggregate) . ' (members ' . implode(', ', array_map(fn ($port) => $this->portName($port), $farPorts)) . ') connects to '
                . $this->deviceName($farDevice) . ' ' . implode(', ', $farLabels) . ', which ' . (count($farLabels) > 1 ? 'are' : 'is') . ' not part of a port-channel.',
                'Configure the same port-channel (and LACP mode) on both ends. With spanning tree the unbundled side blocks all but one link; '
                . 'without it the mismatch can loop or black-hole traffic.',
                null, [$deviceId, $farDevice], array_merge([$aggregate], array_values($farPorts), array_keys($farPorts)),
            ));
        }

        foreach ($landing as $local => $byDevice) {
            [$deviceId, $aggregate] = array_map('intval', explode('#', $local));
            foreach ($byDevice as $farDevice => $farAggregates) {
                if (count($farAggregates) < 2) {
                    continue;
                }
                $parts = [];
                foreach ($farAggregates as $farAggregate => $members) {
                    $parts[] = $this->portName($farAggregate) . ' (' . implode(', ', array_map(fn ($port) => $this->portName($port), $members)) . ')';
                }
                $this->add(new Finding(
                    Finding::WARNING, 'lag_split',
                    'port-channel members land in different port-channels: ' . $this->endLabel($aggregate),
                    'The members of ' . $this->endLabel($aggregate) . ' connect to ' . count($farAggregates) . ' different port-channels on '
                    . $this->deviceName($farDevice) . ': ' . implode(', ', $parts) . '.',
                    'Put all members that connect the same two switches into one port-channel on both ends, or check the cabling.',
                    null, [$deviceId, $farDevice], array_merge([$aggregate], array_keys($farAggregates)),
                ));
            }
        }

        // both ends of a port-channel see the same failed member and the same traffic: report once per
        // link, and an aggregate shared by several links (multi-chassis) only once
        $degradedSeen = [];
        $imbalanceSeen = [];
        foreach ($this->logical as $link) {
            $this->checkDegraded($link, $degradedSeen);

            // load is compared on the end that bundles at least two of the links' members: on a
            // multi-chassis port-channel that is the single-chassis end
            foreach (['a', 'b'] as $x) {
                $aggregate = $link[$x . '_port'];
                if (isset($imbalanceSeen[$aggregate]) || count($this->upMembers($aggregate)) < 2) {
                    continue;
                }
                $imbalanceSeen[$link['a_port']] = $imbalanceSeen[$link['b_port']] = true;
                $this->checkImbalance($aggregate, (int) $link[$x . '_dev']);
                break;
            }
        }
    }

    /**
     * Members of an aggregate that are up and report a speed.
     *
     * @return array<int, array> port_id => port row
     */
    private function upMembers(int $aggregate): array
    {
        $members = [];
        foreach ($this->lagMembers[$aggregate] ?? [] as $member) {
            $port = $this->ports[$member] ?? null;
            if ($port && ($port['ifOperStatus'] ?? null) === 'up' && (int) ($port['ifSpeed'] ?? 0) > 0) {
                $members[$member] = $port;
            }
        }

        return $members;
    }

    /**
     * Port-channel with some members down (that are not shut down on purpose) on either end.
     *
     * @param  array<int, true>  $seen  aggregates already checked
     */
    private function checkDegraded(array $link, array &$seen): void
    {
        $parts = [];
        $devices = [];
        $ports = [];
        foreach (['a', 'b'] as $x) {
            $aggregate = $link[$x . '_port'];
            if (empty($this->lagMembers[$aggregate]) || isset($seen[$aggregate])) {
                continue;
            }
            $seen[$aggregate] = true;

            $up = [];
            $down = [];
            foreach ($this->lagMembers[$aggregate] as $member) {
                $port = $this->ports[$member] ?? null;
                if (! $port || ($port['ifAdminStatus'] ?? 'up') !== 'up') {
                    continue;
                }
                if (($port['ifOperStatus'] ?? null) === 'up') {
                    $up[] = $member;
                } else {
                    $down[] = $member;
                }
            }
            // healthy, or completely down (an outage, not a degraded port-channel)
            if ($up && $down) {
                $parts[] = $this->endLabel($aggregate) . ': ' . count($up) . ' of ' . (count($up) + count($down)) . ' members up, down: '
                    . implode(', ', array_map(fn ($port) => $this->portName($port), $down));
                $devices[] = $link[$x . '_dev'];
                $ports = array_merge($ports, [$aggregate], $down);
            }
        }
        if (! $parts) {
            return;
        }

        $this->add(new Finding(
            Finding::WARNING, 'lag_degraded',
            'port-channel degraded: ' . $this->endLabel($link['a_port']) . ' ↔ ' . $this->endLabel($link['b_port']),
            implode('; ', $parts) . '.',
            'The port-channel runs with reduced capacity and without member redundancy. Check the down members, their cabling and LACP state.',
            null, array_values(array_unique(array_merge($devices, [$link['a_dev'], $link['b_dev']]))), $ports,
        ));
    }

    /**
     * Traffic of an aggregate concentrated on one member while another one is almost idle: the
     * sender's load-balancing hash does not spread the flows.
     */
    private function checkImbalance(int $aggregate, int $deviceId): void
    {
        $threshold = $this->settings->int('links_lag_imbalance', 0, 100);
        $members = $this->upMembers($aggregate);
        if ($threshold <= 0 || count($members) < 2) {
            return;
        }

        $far = array_keys($this->farOf[$aggregate] ?? []);
        foreach (['out' => 'ifOutOctets_rate', 'in' => 'ifInOctets_rate'] as $direction => $column) {
            $rates = array_map(fn ($port) => max(0, (int) ($port[$column] ?? 0)) * 8, $members);
            arsort($rates);
            $busiest = array_key_first($rates);
            $least = array_key_last($rates);
            $utilization = $rates[$busiest] / (int) $members[$busiest]['ifSpeed'] * 100;
            if ($utilization < $threshold || $rates[$busiest] < self::LAG_IMBALANCE_RATIO * max($rates[$least], 1)) {
                continue;
            }

            $parts = [];
            foreach ($rates as $member => $bps) {
                $parts[] = $this->portName($member) . ' ' . self::rate($bps) . ' (' . round($bps / (int) $members[$member]['ifSpeed'] * 100) . '%)';
            }
            $sender = $direction === 'out' ? $this->deviceName($deviceId) : ($far ? $this->names($far) : 'the far end');
            $this->add(new Finding(
                Finding::WARNING, 'lag_imbalance',
                'uneven load across port-channel members: ' . $this->endLabel($aggregate),
                'Traffic ' . ($direction === 'out' ? 'sent by ' : 'received by ') . $this->endLabel($aggregate) . ': ' . implode(', ', $parts) . '.',
                "The sender ($sender) hashes flows onto the members. A hash that includes IP addresses and L4 ports spreads the load better; "
                . 'a few very large flows cannot be spread at all.',
                null, array_merge([$deviceId], $far), array_merge([$aggregate], array_keys($members)),
            ));

            return;
        }
    }

    // ---------------------------------------------------------------- neighbours and descriptions

    /**
     * Links that only one end reports: LLDP/CDP disabled on one side, or a unidirectional link.
     */
    private function checkNeighbors(): void
    {
        $missing = []; // device => [own port, reporting port]
        foreach ($this->physical as $link) {
            if (! isset($this->scope[$link['a_dev']], $this->scope[$link['b_dev']])) {
                continue; // the neighbour table of a device outside the scope is not loaded
            }
            $byA = isset($this->reports[$link['a_dev']][$link['a_port']][$link['b_dev']]);
            $byB = isset($this->reports[$link['b_dev']][$link['b_port']][$link['a_dev']]);
            if ($byA && ! $byB) {
                $missing[$link['b_dev']][] = [$link['b_port'], $link['a_port']];
            } elseif ($byB && ! $byA) {
                $missing[$link['a_dev']][] = [$link['a_port'], $link['b_port']];
            }
        }

        ksort($missing);
        foreach ($missing as $deviceId => $items) {
            $name = $this->deviceName($deviceId);
            $ports = [];
            $labels = [];
            foreach ($items as [$own, $reporter]) {
                $labels[] = $this->portName($own) . ' (' . $this->endLabel($reporter) . ')';
                $ports[] = $own;
                $ports[] = $reporter;
            }
            $reporters = array_values(array_unique(array_map(fn ($item) => (int) $this->ports[$item[1]]['device_id'], $items)));

            if (isset($this->reporting[$deviceId])) {
                $this->add(new Finding(
                    Finding::INFO, 'neighbor_not_reported',
                    "neighbors not seen by $name",
                    "$name does not list the LLDP/CDP neighbor on " . implode(', ', array_slice($labels, 0, 20)) . (count($labels) > 20 ? ' ...' : '')
                    . ', although the neighbor lists it.',
                    'Enable LLDP/CDP (transmit and receive) on these ports. If it is enabled, the link may be unidirectional: '
                    . "$name does not receive the frames of the other end.",
                    null, array_merge([$deviceId], $reporters), $ports,
                ));
            } else {
                $this->add(new Finding(
                    Finding::INFO, 'no_neighbor_data',
                    "$name reports no LLDP/CDP neighbors",
                    count($labels) . " neighbor(s) see $name (on its ports " . implode(', ', array_slice($labels, 0, 20)) . (count($labels) > 20 ? ' ...' : '')
                    . '), but LibreNMS has no LLDP/CDP neighbors from it.',
                    'Enable LLDP or CDP on the device and the discovery-protocols module in LibreNMS so its links can be checked from both ends.',
                    null, array_merge([$deviceId], $reporters), $ports,
                ));
            }
        }
    }

    /**
     * Port descriptions of inter-switch ports: missing, or naming a monitored device that is not
     * the neighbour. Both come with configuration lines built from the LLDP/CDP neighbour.
     */
    private function checkDescriptions(): void
    {
        if (! $this->enabled('description_wrong') && ! $this->enabled('description_missing')) {
            return;
        }

        // expected neighbour of every inter-switch port and aggregate
        $expect = [];
        foreach ($this->physical as $link) {
            foreach ([['a', 'b'], ['b', 'a']] as [$x, $y]) {
                // a port can see several neighbours (through an unmanaged switch passing LLDP/CDP)
                $expect[$link[$x . '_port']]['device_id'] = $link[$x . '_dev'];
                $expect[$link[$x . '_port']]['far'][$link[$y . '_dev']] = $this->portName($link[$y . '_port']);
                $expect[$link[$x . '_port']]['host'] = null;
            }
        }
        foreach ($this->logical as $link) {
            foreach ([['a', 'b'], ['b', 'a']] as [$x, $y]) {
                if (! empty($this->lagMembers[$link[$x . '_port']])) {
                    $expect[$link[$x . '_port']]['device_id'] = $link[$x . '_dev'];
                    $expect[$link[$x . '_port']]['far'][$link[$y . '_dev']] = $this->portName($link[$y . '_port']);
                    $expect[$link[$x . '_port']]['host'] = null;
                }
            }
        }
        // ports now facing an unmanaged neighbour can still carry a stale description of a monitored device
        foreach ($this->partial as $partial) {
            if (! $partial['remote_device_id'] && $partial['host'] !== '' && ! isset($expect[$partial['port_id']])) {
                $expect[$partial['port_id']] = ['device_id' => $partial['device_id'], 'far' => [], 'host' => $partial];
            }
        }

        [$patterns, $index] = $this->nameIndex();
        $wrong = [];
        $missing = [];
        foreach ($expect as $portId => $expected) {
            $port = $this->ports[$portId] ?? null;
            $deviceId = (int) $expected['device_id'];
            if (! $port || ! isset($this->scope[$deviceId])) {
                continue;
            }

            $suggestion = $this->suggestDescription($expected);
            $alias = trim((string) ($port['ifAlias'] ?? ''));
            if ($alias === '' || strcasecmp($alias, (string) ($port['ifName'] ?? '')) === 0 || strcasecmp($alias, (string) ($port['ifDescr'] ?? '')) === 0) {
                if (! $expected['host']) {
                    $missing[$deviceId][$portId] = $suggestion;
                }
                continue;
            }

            $mentioned = self::mentions($alias, $patterns, $index);
            unset($mentioned[$deviceId]);
            if (! $mentioned || array_intersect_key($mentioned, $expected['far'])) {
                continue;
            }
            if ($expected['host'] && self::mentions($alias, [self::namePattern([L2Builder::shortHost(strtolower($expected['host']['host']))])], [])) {
                continue;
            }
            $wrong[$deviceId][$portId] = [$alias, array_values(array_unique($mentioned)), $suggestion];
        }

        ksort($wrong);
        foreach ($wrong as $deviceId => $items) {
            $parts = [];
            $ports = [];
            foreach ($items as $portId => [$alias, $names, $suggestion]) {
                $parts[] = $this->portName($portId) . ' "' . $alias . '" connects to ' . $suggestion;
                $ports[] = $portId;
            }
            $this->add(new Finding(
                Finding::WARNING, 'description_wrong',
                'port descriptions name the wrong neighbor: ' . $this->deviceName($deviceId),
                implode('; ', array_slice($parts, 0, 20)) . (count($parts) > 20 ? ' ...' : '') . '.',
                'Stale descriptions mislead troubleshooting. The configuration below describes each port with its current LLDP/CDP neighbor.',
                null, [$deviceId], $ports,
                $this->config($deviceId, array_map(fn ($item) => $item[2], $items)),
            ));
        }

        ksort($missing);
        foreach ($missing as $deviceId => $items) {
            $parts = [];
            foreach ($items as $portId => $suggestion) {
                $parts[] = $this->portName($portId) . " ($suggestion)";
            }
            $this->add(new Finding(
                Finding::INFO, 'description_missing',
                'inter-switch ports without description: ' . $this->deviceName($deviceId),
                count($items) . ' port(s): ' . implode(', ', array_slice($parts, 0, 20)) . (count($parts) > 20 ? ' ...' : '') . '.',
                'Describe inter-switch ports with the neighbor name and port, for example with the configuration below.',
                null, [$deviceId], array_keys($items),
                $this->config($deviceId, $items),
            ));
        }
    }

    /**
     * Description built from the neighbour: "core2 Te1/1/1", "leafA/leafB Po10" for a multi-chassis
     * port-channel, or the LLDP/CDP name of an unmanaged neighbour.
     */
    private function suggestDescription(array $expected): string
    {
        if ($expected['host']) {
            $host = L2Builder::shortHost($expected['host']['host']);

            return self::cleanDescription($host . ' ' . InterfaceName::short($expected['host']['remote_port']));
        }

        $names = [];
        foreach ($expected['far'] as $deviceId => $port) {
            $names[] = L2Builder::shortHost($this->deviceName($deviceId));
        }
        $ports = array_values(array_unique($expected['far']));
        if (count($ports) === 1) {
            return self::cleanDescription(implode('/', $names) . ' ' . $ports[0]);
        }

        $parts = [];
        foreach ($expected['far'] as $deviceId => $port) {
            $parts[] = L2Builder::shortHost($this->deviceName($deviceId)) . ' ' . $port;
        }

        return self::cleanDescription(implode(' / ', $parts));
    }

    /**
     * A description that is safe to paste into a CLI. Neighbour names come from LLDP/CDP, which
     * any device can advertise: keep only characters host and port names use, so no line break,
     * quote or variable can smuggle further commands into the configuration.
     */
    private static function cleanDescription(string $text): string
    {
        $text = preg_replace('/[^A-Za-z0-9 ._\/:+()#@,-]+/', '_', $text) ?? '';

        return mb_substr(trim((string) preg_replace('/ {2,}/', ' ', $text)), 0, 80);
    }

    /**
     * Configuration lines setting port descriptions, in the syntax of the device's OS
     * (Junos, ProCurve and RouterOS; Cisco-style "interface / description" otherwise).
     *
     * @param  array<int, string>  $descriptions  port_id => description
     */
    private function config(int $deviceId, array $descriptions): string
    {
        $os = strtolower((string) ($this->devices[$deviceId]['os'] ?? ''));
        $lines = [];
        foreach ($descriptions as $portId => $description) {
            // interface names come from the device itself; still strip anything that could end the line
            $name = (string) preg_replace('/[\x00-\x1F\x7F"]+/', '', InterfaceName::label($this->ports[$portId] ?? []));
            $description = self::cleanDescription($description);
            $lines[] = match ($os) {
                'junos' => "set interfaces $name description \"$description\"",
                'procurve' => "interface $name\n   name \"$description\"\n   exit",
                'routeros' => "/interface set [find name=\"$name\"] comment=\"$description\"",
                default => "interface $name\n description $description",
            };
        }

        return implode("\n", $lines);
    }

    /**
     * Patterns matching the names of all known devices as whole words, and name => device ids.
     *
     * @return array{0: string[], 1: array<string, int[]>}
     */
    private function nameIndex(): array
    {
        $names = [];
        foreach ($this->devices as $deviceId => $device) {
            foreach (['hostname', 'sysName', 'display'] as $field) {
                $name = L2Builder::shortHost(strtolower(trim((string) ($device[$field] ?? ''))));
                if (strlen($name) >= 3 && ! ctype_digit($name) && ! in_array($name, self::GENERIC_NAMES, true)) {
                    $names[$name][(int) $deviceId] = true;
                }
            }
        }

        // longest first, so "acc1-b" is matched before "acc1"
        $list = array_keys($names);
        usort($list, fn ($a, $b) => strlen($b) <=> strlen($a));
        $patterns = array_map(fn ($chunk) => self::namePattern($chunk), array_chunk($list, 300));

        return [$patterns, array_map('array_keys', $names)];
    }

    /**
     * Pattern matching any of the names as a whole word (not inside a longer word or number).
     *
     * @param  string[]  $names
     */
    private static function namePattern(array $names): string
    {
        return '/(?<![a-z0-9])(' . implode('|', array_map(fn ($name) => preg_quote($name, '/'), $names)) . ')(?![a-z0-9])/';
    }

    /**
     * Devices whose name appears in a description.
     *
     * @param  string[]  $patterns
     * @param  array<string, int[]>  $index  name => device ids; empty returns any match as [0 => name]
     * @return array<int, string> device_id => matched name
     */
    private static function mentions(string $text, array $patterns, array $index): array
    {
        $text = strtolower($text);
        $found = [];
        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $text, $matches)) {
                continue;
            }
            foreach ($matches[1] as $name) {
                if (! $index) {
                    return [0 => $name];
                }
                foreach ($index[$name] ?? [] as $deviceId) {
                    $found[$deviceId] ??= $name;
                }
            }
        }

        return $found;
    }

    // ---------------------------------------------------------------- link rows

    /**
     * One row per link (port-channels collapsed) for the links table, with the issues found on it.
     */
    private function linkRows(array $findings): array
    {
        $rows = [];
        $rowOfPort = [];
        foreach ($this->logical as $key => $link) {
            $members = array_map(fn ($member) => $this->physical[$member], $link['members']);
            $index = count($rows);
            foreach ([$link['a_port'], $link['b_port']] as $portId) {
                $rowOfPort[$portId][$index] = true;
            }
            foreach ($members as $member) {
                $rowOfPort[$member['a_port']][$index] = true;
                $rowOfPort[$member['b_port']][$index] = true;
            }

            $up = count(array_filter($members, fn ($m) => $this->isUp($m['a_port']) && $this->isUp($m['b_port'])));
            $speed = 0;
            foreach ($members as $member) {
                $speeds = array_filter([(int) ($this->ports[$member['a_port']]['ifSpeed'] ?? 0), (int) ($this->ports[$member['b_port']]['ifSpeed'] ?? 0)]);
                $speed += $speeds ? min($speeds) : 0;
            }

            $a = $this->endRow($link['a_dev'], $link['a_port'], array_column($members, 'a_port'));
            $b = $this->endRow($link['b_dev'], $link['b_port'], array_column($members, 'b_port'));
            // like the findings, only compare what a link reports while it is up
            $allUp = $up === count($members);
            $rows[] = [
                'key' => $key,
                'status' => $allUp ? 'up' : ($up === 0 ? 'down' : 'degraded'),
                'lag' => $link['lag'] ? ['members' => count($members), 'up' => $up] : null,
                'speed' => $speed,
                'protocols' => array_values(array_unique(array_merge(...array_column($members, 'protocols')))),
                'a' => $a,
                'b' => $b,
                'mismatch' => [
                    'speed' => $allUp && $a['speed'] && $b['speed'] && $a['speed'] !== $b['speed'],
                    'duplex' => $allUp && (in_array('half', [$a['duplex'], $b['duplex']], true) || in_array('mixed', [$a['duplex'], $b['duplex']], true)),
                    'mtu' => $allUp && $a['mtu'] && $b['mtu'] && abs($a['mtu'] - $b['mtu']) > self::MTU_TOLERANCE,
                ],
                'issues' => [],
                'severity' => 'ok',
            ];
        }

        foreach ($findings as $finding) {
            $indexes = [];
            foreach ($finding['ports'] as $portId) {
                $indexes += $rowOfPort[$portId] ?? [];
            }
            foreach (array_keys($indexes) as $index) {
                $current = $rows[$index]['issues'][$finding['code']] ?? $finding['severity'];
                $rows[$index]['issues'][$finding['code']] = Finding::worst([$current, $finding['severity']]);
            }
        }

        foreach ($rows as &$row) {
            $row['severity'] = $row['issues'] ? Finding::worst(array_values($row['issues'])) : 'ok';
            $row['mismatch']['mode'] = isset($row['issues']['mode_mismatch']) || isset($row['issues']['native_vlan_mismatch']) || isset($row['issues']['access_vlan_mismatch']);
            $row['mismatch']['vlans'] = isset($row['issues']['trunk_vlan_mismatch']) || isset($row['issues']['vlan_split']);
        }
        unset($row);

        $order = Finding::SEVERITY_ORDER + ['ok' => 3];
        usort($rows, fn ($x, $y) => [$order[$x['severity']], strtolower($x['a']['name']), strtolower($x['b']['name']), $x['a']['port']]
            <=> [$order[$y['severity']], strtolower($y['a']['name']), strtolower($y['b']['name']), $y['a']['port']]);

        return $rows;
    }

    /**
     * One end of a link row: port (or aggregate with its members) and the values compared.
     *
     * @param  int[]  $memberPorts  physical ports of this end
     */
    private function endRow(int $deviceId, int $portId, array $memberPorts): array
    {
        $port = $this->ports[$portId] ?? [];
        $end = $this->vlanEnd($portId);
        $members = array_values(array_unique($memberPorts));

        $speeds = array_values(array_unique(array_map(fn ($m) => (int) ($this->ports[$m]['ifSpeed'] ?? 0), $members)));
        $duplex = array_values(array_unique(array_filter(array_map(fn ($m) => self::duplex($this->ports[$m]['ifDuplex'] ?? null), $members))));
        $mtus = array_values(array_unique(array_filter(array_map(fn ($m) => (int) ($this->ports[$m]['ifMtu'] ?? 0), $members))));
        $errors = 0;
        $discards = 0;
        $rx = null;
        foreach ($members as $member) {
            $errors += self::delta($this->ports[$member] ?? [], 'ifInErrors_delta') + self::delta($this->ports[$member] ?? [], 'ifOutErrors_delta');
            $discards += self::delta($this->statistics[$member] ?? [], 'ifInDiscards_delta') + self::delta($this->statistics[$member] ?? [], 'ifOutDiscards_delta');
            if (is_numeric($this->optics[$member]['rx'] ?? null)) {
                $rx = $rx === null ? (float) $this->optics[$member]['rx'] : min($rx, (float) $this->optics[$member]['rx']);
            }
        }

        return [
            'device_id' => $deviceId,
            'name' => $this->deviceName($deviceId),
            'port_id' => $portId,
            'port' => $this->portName($portId),
            'alias' => (string) ($port['ifAlias'] ?? ''),
            'url' => $port['url'] ?? null,
            'members' => $members === [$portId] ? [] : array_map(fn ($m) => $this->portName($m), $members),
            'speed' => count($speeds) === 1 ? $speeds[0] : 0,
            'duplex' => match (count($duplex)) {
                0 => null,
                1 => $duplex[0],
                default => 'mixed',
            },
            'mtu' => count($mtus) === 1 ? $mtus[0] : ((int) ($port['ifMtu'] ?? 0) ?: null),
            'mode' => $end['mode'],
            'native' => $end['native'],
            'vlan_count' => $end['vlans'] === null ? null : count($end['vlans']),
            'vlans' => $end['vlans'] === null ? null : self::ranges(array_keys($end['vlans'])),
            'errors' => $errors,
            'discards' => $discards,
            'rx' => $rx,
        ];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Index the links table: physical links between monitored devices (both directions and
     * protocols merged), links collapsed per port-channel, every device's neighbour table, and
     * neighbours that are not monitored or whose port is unknown.
     */
    private function prepareLinks(): void
    {
        foreach ($this->links as $link) {
            $ld = (int) $link['local_device_id'];
            $lp = (int) ($link['local_port_id'] ?? 0);
            $rd = (int) ($link['remote_device_id'] ?? 0);
            $rp = (int) ($link['remote_port_id'] ?? 0);
            if (! isset($this->devices[$ld]) || $rd === $ld) {
                continue;
            }

            $this->reporting[$ld] = true;
            $managed = $rd && isset($this->devices[$rd]);
            if ($managed && $lp) {
                $this->reports[$ld][$lp][$rd] = true;
            }

            if ($managed && $lp && $rp && isset($this->ports[$lp], $this->ports[$rp])) {
                $key = min($lp, $rp) . '-' . max($lp, $rp);
                if (! isset($this->physical[$key])) {
                    [$aDev, $aPort, $bDev, $bPort] = $ld < $rd ? [$ld, $lp, $rd, $rp] : [$rd, $rp, $ld, $lp];
                    $this->physical[$key] = [
                        'key' => $key, 'a_dev' => $aDev, 'a_port' => $aPort, 'b_dev' => $bDev, 'b_port' => $bPort, 'protocols' => [],
                    ];
                }
                $protocol = strtolower((string) ($link['protocol'] ?? ''));
                if ($protocol !== '' && ! in_array($protocol, $this->physical[$key]['protocols'], true)) {
                    $this->physical[$key]['protocols'][] = $protocol;
                }
                continue;
            }

            if ($lp && isset($this->ports[$lp])) {
                $this->partial[] = [
                    'device_id' => $ld,
                    'port_id' => $lp,
                    'remote_device_id' => $managed ? $rd : null,
                    'host' => trim((string) ($link['remote_hostname'] ?? '')),
                    'remote_port' => (string) ($link['remote_port'] ?? ''),
                    'platform' => (string) ($link['remote_platform'] ?? ''),
                ];
            }
        }

        // a neighbour reported without its port is redundant when the other end reported both ports
        $linked = [];
        foreach ($this->physical as $link) {
            $linked[$link['a_port']][$link['b_dev']] = true;
            $linked[$link['b_port']][$link['a_dev']] = true;
        }
        $this->partial = array_values(array_filter($this->partial, fn ($p) => ! ($p['remote_device_id'] && isset($linked[$p['port_id']][$p['remote_device_id']]))));

        foreach ($this->physical as $key => $link) {
            $aPort = $this->lagParent[$link['a_port']] ?? $link['a_port'];
            $bPort = $this->lagParent[$link['b_port']] ?? $link['b_port'];
            $logicalKey = $link['a_dev'] . '#' . $aPort . '|' . $link['b_dev'] . '#' . $bPort;
            $this->logical[$logicalKey] ??= [
                'key' => $logicalKey, 'a_dev' => $link['a_dev'], 'a_port' => $aPort, 'b_dev' => $link['b_dev'], 'b_port' => $bPort,
                'members' => [], 'lag' => false,
            ];
            $this->logical[$logicalKey]['members'][] = $key;
            $this->logical[$logicalKey]['lag'] = $this->logical[$logicalKey]['lag'] || $aPort !== $link['a_port'] || $bPort !== $link['b_port'];
            $this->farOf[$aPort][$link['b_dev']] = true;
            $this->farOf[$bPort][$link['a_dev']] = true;
        }
    }

    /**
     * Mode, VLANs and native (untagged) VLAN of a port; a port-channel without VLAN data of its own
     * uses its members' data. Modes: trunk (Cisco trunk flag, or tagged VLANs), access (one untagged
     * VLAN), routed (IP address) or unknown.
     *
     * @return array{mode: string, vlans: array<int, bool>|null, native: int|null}
     */
    private function vlanEnd(int $portId): array
    {
        if (isset($this->vlanEnds[$portId])) {
            return $this->vlanEnds[$portId];
        }

        $port = $this->ports[$portId] ?? [];
        $vlans = $this->portVlans[$portId] ?? null;
        if (! $vlans) {
            foreach ($this->lagMembers[$portId] ?? [] as $member) {
                $vlans = ($vlans ?: []) + ($this->portVlans[$member] ?? []);
            }
        }
        $vlans = $vlans ?: null;

        if (! empty($port['has_ip'])) {
            $result = ['mode' => 'routed', 'vlans' => null, 'native' => null];
        } elseif ($vlans !== null) {
            $untagged = array_keys(array_filter($vlans));
            $result = [
                'mode' => ! empty($port['ifTrunk']) || count($vlans) > 1 || ! $untagged ? 'trunk' : 'access',
                'vlans' => $vlans,
                'native' => count($untagged) === 1 ? $untagged[0] : null,
            ];
        } elseif (! empty($port['ifTrunk'])) {
            $native = is_numeric($port['ifVlan'] ?? null) ? (int) $port['ifVlan'] : 0;
            $result = ['mode' => 'trunk', 'vlans' => null, 'native' => $native > 0 ? $native : null];
        } else {
            $result = ['mode' => 'unknown', 'vlans' => null, 'native' => null];
        }

        return $this->vlanEnds[$portId] = $result;
    }

    /**
     * Does a port carry the VLAN? null when its VLANs are unknown.
     */
    private static function carries(array $end, int $vlan): ?bool
    {
        return $end['vlans'] === null ? null : isset($end['vlans'][$vlan]);
    }

    /**
     * @return array<int, true>
     */
    private function ignoredVlans(): array
    {
        return $this->settings->intSet('links_ignore_vlans', 1, 4094);
    }

    private function enabled(string $code): bool
    {
        return ! isset($this->disabled[$code]) && ! isset($this->disabled[self::CHECKS[$code] ?? '']);
    }

    private function add(Finding $finding): void
    {
        if ($this->enabled($finding->code)) {
            $this->findings[] = $finding;
        }
    }

    private function isUp(int $portId): bool
    {
        return ($this->ports[$portId]['ifOperStatus'] ?? null) === 'up';
    }

    /**
     * Not shut down on purpose (unknown counts as up).
     */
    private function adminUp(int $portId): bool
    {
        return ($this->ports[$portId]['ifAdminStatus'] ?? 'up') !== 'down';
    }

    /**
     * Was the device reachable at its last poll? LibreNMS keeps the last counters and sensor
     * readings of a device that went down. Unknown counts as up.
     */
    private function deviceUp(int $deviceId): bool
    {
        return (bool) ($this->devices[$deviceId]['status'] ?? true);
    }

    /**
     * Display name of a device, "device #id" when it is not part of the analysis.
     */
    private function deviceName(int $deviceId): string
    {
        $device = $this->devices[$deviceId] ?? null;

        return $device ? (string) (($device['display'] ?? '') ?: ($device['hostname'] ?? $deviceId)) : "device #$deviceId";
    }

    private function names(array $deviceIds): string
    {
        return implode(', ', array_map(fn ($id) => $this->deviceName((int) $id), $deviceIds));
    }

    /**
     * Short interface name (Gi1/0/1) of a port, "port #id" when the port row was not loaded.
     */
    private function portName(int $portId): string
    {
        $port = $this->ports[$portId] ?? null;

        return $port ? InterfaceName::short(InterfaceName::label($port)) : "port #$portId";
    }

    /**
     * "device port" label of a port.
     */
    private function endLabel(int $portId): string
    {
        $deviceId = (int) ($this->ports[$portId]['device_id'] ?? 0);

        return $this->deviceName($deviceId) . ' ' . $this->portName($portId);
    }

    /**
     * Short lowercase host name identifying an unmanaged neighbour. A missing or factory default
     * name ("Switch") would merge unrelated devices, so those get $fallback (one per local port).
     */
    private static function hostKey(string $hostname, string $fallback): string
    {
        $hostname = strtolower(trim($hostname));
        $short = L2Builder::shortHost($hostname);
        if ($hostname === '' || in_array($hostname, L2Builder::GENERIC_HOSTNAMES, true) || in_array($short, L2Builder::GENERIC_HOSTNAMES, true)) {
            return 'port:' . $fallback;
        }

        return $short;
    }

    /**
     * Edges of a shortest path (breadth first) from any source node to any target node.
     *
     * @param  array<int|string, array<int, array{0: int|string, 1: string}>>  $adjacency  node => [neighbour, edge]
     * @param  array<int|string>  $sources
     * @param  array<int|string, mixed>  $targets  keyed by node
     * @return string[] edges in path order, empty when no path exists
     */
    private static function path(array $adjacency, array $sources, array $targets): array
    {
        $previous = [];
        $queue = [];
        foreach ($sources as $node) {
            $previous[$node] = null;
            $queue[] = $node;
        }

        for ($i = 0; $i < count($queue); $i++) {
            $node = $queue[$i];
            if (isset($targets[$node])) {
                $edges = [];
                while ($previous[$node] !== null) {
                    [$node, $edge] = $previous[$node];
                    $edges[] = $edge;
                }

                return array_reverse($edges);
            }
            foreach ($adjacency[$node] ?? [] as [$next, $edge]) {
                if (! array_key_exists($next, $previous)) {
                    $previous[$next] = [$node, $edge];
                    $queue[] = $next;
                }
            }
        }

        return [];
    }

    /**
     * Counter delta of the last poll (negative after a counter reset counts as 0).
     */
    private static function delta(array $row, string $column): int
    {
        return max(0, (int) ($row[$column] ?? 0));
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
     * " in the last 5m" from the port's poll period.
     */
    private static function period(array $port): string
    {
        $seconds = (int) ($port['poll_period'] ?? 0);

        return $seconds > 0 ? ' in the last ' . StpAnalyzer::duration($seconds) : ' in the last poll';
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

    /**
     * Interface speed as on a diagram: 100M, 1G, 2.5G, 10G.
     */
    public static function speed(int $bps): string
    {
        foreach ([1000000000000 => 'T', 1000000000 => 'G', 1000000 => 'M', 1000 => 'k'] as $unit => $suffix) {
            if ($bps >= $unit) {
                return round($bps / $unit, 1) . $suffix;
            }
        }

        return $bps . ' bps';
    }

    /**
     * Traffic rate: 6.2 Gbps, 310 Mbps.
     */
    private static function rate(int $bps): string
    {
        foreach ([1000000000000 => 'Tbps', 1000000000 => 'Gbps', 1000000 => 'Mbps', 1000 => 'kbps'] as $unit => $suffix) {
            if ($bps >= $unit) {
                return round($bps / $unit, 1) . ' ' . $suffix;
            }
        }

        return $bps . ' bps';
    }

    private static function dbm(float $value): string
    {
        return sprintf('%.1f dBm', $value);
    }

    /**
     * Compact VLAN list: 1,10-12,20.
     *
     * @param  int[]  $values
     */
    public static function ranges(array $values): string
    {
        $values = array_values(array_unique(array_map('intval', $values)));
        sort($values);
        $parts = [];
        for ($i = 0; $i < count($values); $i++) {
            $start = $values[$i];
            while ($i + 1 < count($values) && $values[$i + 1] === $values[$i] + 1) {
                $i++;
            }
            $parts[] = $start === $values[$i] ? (string) $start : $start . '-' . $values[$i];
        }

        return implode(',', $parts);
    }
}
