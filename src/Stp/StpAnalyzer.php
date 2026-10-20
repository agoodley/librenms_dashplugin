<?php

namespace Agoodley\LibrenmsTopology\Stp;

use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\Graph;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * Spanning tree health analysis built on the LibreNMS STP, link (LLDP/CDP) and port data.
 * Pure PHP: all data is passed in as arrays so it can be tested without LibreNMS.
 */
class StpAnalyzer
{
    private const DEFAULT_PRIORITY = 32768;

    /** @var array<int, array{a_dev: int, a_port: int, b_dev: int, b_port: int}> */
    private array $physLinks = [];

    /** @var array<int, array{device_id: ?int, port_id: ?int, name: string, host: string}> port_id => neighbour */
    private array $neighbor = [];

    /** @var array<int, int[]> aggregate port_id => member port_ids (reverse of $lagParent) */
    private array $lagMembers = [];

    private Graph $adjacency;

    /** @var Finding[] */
    private array $findings = [];

    /** @var array<string, array> root bridge MAC lookups (owners, FDB sightings, vendor) */
    private array $rootLookups = [];

    /**
     * @param  array<int, array>  $devices  device_id => device row (display, hostname, uptime, role, url)
     * @param  array<int, array>  $links  rows of the links table (only devices the user can see)
     * @param  array<int, array>  $ports  port_id => port row, may contain 'has_ip', 'has_stp' and 'switchport' flags
     * @param  array<int, int>  $lagParent  member port_id => aggregate port_id
     * @param  array<int, array>  $events  eventlog rows of type stp (device_id, datetime, message)
     */
    public function __construct(
        private array $devices,
        private StpIndex $stp,
        private array $links,
        private array $ports,
        private array $lagParent,
        private PluginSettings $settings,
        private array $events = [],
        private ?int $now = null,
    ) {
        $this->now ??= time();
        foreach ($this->lagParent as $member => $parent) {
            $this->lagMembers[$parent][] = $member;
        }
        $this->prepareLinks();
    }

    /**
     * Root bridge MACs that do not belong to any monitored bridge; callers can look them up
     * (interface MACs, FDB, OUI vendor) and pass the result to analyze().
     *
     * @return string[]
     */
    public function unknownRootMacs(): array
    {
        return $this->stp->unknownRootMacs();
    }

    /**
     * Run every check and return the data of the STP diagnostics page: summary, findings (merged
     * across VLANs), domains, bridges, blocked ports and recent STP events.
     *
     * @param  array<string, array{owners?: array, fdb?: array, vendor?: string, hidden?: bool}>  $rootLookups  keyed by root MAC
     * @param  int|null  $vlanFilter  only analyze the instance of this VLAN (1 = default instance)
     */
    public function analyze(array $rootLookups = [], ?int $vlanFilter = null): array
    {
        $this->findings = [];
        $this->rootLookups = $rootLookups;
        $domains = [];
        $bridges = [];
        $blocked = [];

        foreach ($this->stp->byVlan() as $key => $members) {
            if ($vlanFilter !== null && $key !== StpIndex::vlanKey($vlanFilter)) {
                continue;
            }

            foreach ($this->domainsFor($key, $members) as [$memberIds, $graph]) {
                $domains[] = $this->analyzeDomain($key, $memberIds, $members, $graph, $rootLookups);
            }

            foreach ($members as $deviceId => $instance) {
                $bridges[] = $this->bridgeRow($deviceId, $key, $instance);
                foreach ($this->stp->portsFor($deviceId, $key) as $port) {
                    if (StpIndex::isBlocking($port['state']) || $port['state'] === 'broken') {
                        $blocked[] = $this->blockedRow($deviceId, $key, $instance, $port);
                    }
                }
            }
        }

        $this->checkPortsWithoutStp();
        $this->checkDevicesWithoutStp();
        $this->checkLoopsWithoutStp();
        $this->checkEvents();

        $findings = self::mergeAcrossVlans(array_map(fn (Finding $f) => $f->toArray(), $this->findings));
        usort($findings, fn ($a, $b) => [Finding::SEVERITY_ORDER[$a['severity']], $a['vlan'] ?? -1, $a['title']]
            <=> [Finding::SEVERITY_ORDER[$b['severity']], $b['vlan'] ?? -1, $b['title']]);

        $names = [];
        foreach ($findings as $finding) {
            foreach ($finding['devices'] as $deviceId) {
                $names[$deviceId] = $this->deviceName($deviceId);
            }
        }

        return [
            'device_names' => $names,
            'summary' => self::summarize($domains, $bridges, $blocked, $findings, $this->tcRecentSeconds()),
            'domains' => $domains,
            'findings' => $findings,
            'bridges' => $bridges,
            'blocked' => $blocked,
            'events' => $this->eventRows(),
        ];
    }

    /**
     * Summary cards of a (possibly filtered) analysis result.
     *
     * @param  int  $recentWindow  seconds within which a topology change counts as recent
     */
    public static function summarize(array $domains, array $bridges, array $blocked, array $findings, int $recentWindow): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($findings as $finding) {
            $counts[$finding['severity']]++;
        }

        return [
            'bridges' => count(array_unique(array_column($bridges, 'device_id'))),
            'instances' => count($bridges),
            'domains' => count($domains),
            'roots' => count(array_unique(array_filter(array_map(fn ($d) => $d['root']['mac'], $domains)))),
            'blocked_ports' => count(array_filter($blocked, fn ($b) => $b['state'] !== 'broken')),
            'recent_tc' => count(array_filter($bridges, fn ($b) => $b['last_tc'] !== null && $b['last_tc'] < $recentWindow)),
            'recent_tc_window' => $recentWindow,
            'findings' => $counts,
            'status' => $counts['critical'] ? 'critical' : ($counts['warning'] ? 'warning' : 'ok'),
        ];
    }

    /**
     * PVST runs one instance per VLAN, so the same problem is usually found in many VLANs:
     * report it once with the list of affected VLANs.
     */
    public static function mergeAcrossVlans(array $findings): array
    {
        $merged = [];
        foreach ($findings as $finding) {
            $key = implode('|', [$finding['severity'], $finding['code'], $finding['title'], $finding['detail'],
                implode(',', $finding['devices']), implode(',', $finding['ports'])]);
            if (! isset($merged[$key])) {
                $merged[$key] = $finding;
                continue;
            }
            if ($finding['vlan'] !== null && ! in_array($finding['vlan'], $merged[$key]['vlans'], true)) {
                $merged[$key]['vlans'][] = $finding['vlan'];
            }
        }

        foreach ($merged as &$finding) {
            sort($finding['vlans']);
            $labels = array_map(fn ($vlan) => StpIndex::vlanLabel($vlan), $finding['vlans']);
            $finding['vlan'] = $finding['vlans'][0] ?? null;
            $finding['vlan_label'] = $labels ? implode(', ', array_slice($labels, 0, 6)) . (count($labels) > 6 ? ' (+' . (count($labels) - 6) . ' more)' : '') : null;
        }

        return array_values($merged);
    }

    /**
     * Lightweight per-device summary (device page panel): no domain-wide analysis.
     *
     * @param  array<string, array{owners?: array, fdb?: array, vendor?: string}>  $rootLookups  owners of the root bridge MACs
     */
    public function deviceSummary(int $deviceId, array $rootLookups = []): array
    {
        $this->rootLookups = $rootLookups;
        $instances = [];
        foreach ($this->stp->instancesFor($deviceId) as $key => $instance) {
            $row = $this->bridgeRow($deviceId, $key, $instance);
            $row['blocked_ports'] = [];
            foreach ($this->stp->portsFor($deviceId, $key) as $port) {
                if (StpIndex::isBlocking($port['state']) || $port['state'] === 'broken') {
                    $row['blocked_ports'][] = $this->blockedRow($deviceId, $key, $instance, $port);
                }
            }
            $instances[] = $row;
        }

        // the same issue usually shows in every PVST instance: list it once with its VLANs
        $issues = [];
        $add = function (string $severity, string $text, array $row) use (&$issues): void {
            $issues[$severity . '|' . $text]['severity'] = $severity;
            $issues[$severity . '|' . $text]['text'] = $text;
            $issues[$severity . '|' . $text]['vlans'][] = $row['label'];
        };
        foreach ($instances as $row) {
            if ($row['is_root'] && $this->isDefaultPriority($row['priority'], $row['vlan'])) {
                $add(Finding::WARNING, 'root bridge with default priority', $row);
            }
            if ($row['last_tc'] !== null && $row['last_tc'] < $this->tcRecentSeconds()) {
                $add(Finding::INFO, 'topology change ' . self::duration($row['last_tc']) . ' ago', $row);
            }
            if ($row['tc_rate'] !== null && $row['tc_rate'] > $this->settings->int('stp_tc_rate_per_day', 1)) {
                $add(Finding::WARNING, $row['tc_rate'] . ' topology changes per day', $row);
            }
            foreach ($row['blocked_ports'] as $port) {
                if ($port['state'] === 'broken') {
                    $add(Finding::CRITICAL, 'port ' . $port['port'] . ' is in broken state', $row);
                }
            }
            foreach ($this->stp->portsFor($deviceId, $row['vlan']) as $port) {
                if ($port['forwardTransitions'] >= $this->settings->int('stp_flap_transitions', 1)) {
                    $add(Finding::WARNING, $this->portName($port['port_id']) . ' changed to forwarding ' . $port['forwardTransitions'] . ' times', $row);
                }
            }
            // only bridge addresses are looked up here (cheap); the STP page also checks interface MACs
            $rootMac = $this->stp->instance($deviceId, $row['vlan'])['designatedRoot'] ?? '';
            if (! $row['is_root'] && $rootMac !== '' && $this->stp->bridgeOwner($rootMac) === null && empty($rootLookups[$rootMac]['owners']) && empty($rootLookups[$rootMac]['hidden'])) {
                $add(Finding::WARNING, 'root bridge ' . $row['root_id'] . ' is not a monitored bridge (possible rogue switch, see STP diagnostics)', $row);
            }
        }

        $issues = array_values(array_map(function ($issue) {
            $vlans = count($issue['vlans']) > 4 ? count($issue['vlans']) . ' VLANs' : implode(', ', $issue['vlans']);

            return ['severity' => $issue['severity'], 'text' => $vlans . ': ' . $issue['text']];
        }, $issues));
        usort($issues, fn ($a, $b) => Finding::SEVERITY_ORDER[$a['severity']] <=> Finding::SEVERITY_ORDER[$b['severity']]);

        return ['instances' => $instances, 'issues' => $issues];
    }

    // ---------------------------------------------------------------- domains

    /**
     * Split the bridges of one VLAN instance into connected L2 domains.
     * Single-instance (CST/MST) bridges carry every VLAN, so they join PVST islands.
     *
     * @return array<int, array{0: int[], 1: Graph}>
     */
    private function domainsFor(int $key, array $members): array
    {
        $graph = new Graph;
        $participants = [];
        foreach (array_keys($members) as $deviceId) {
            $graph->addNode($deviceId);
            $participants[$deviceId] = true;
        }

        if ($key !== 0) {
            foreach ($this->stp->deviceIds() as $deviceId) {
                if (array_keys($this->stp->instancesFor($deviceId)) === [0]) {
                    $participants[$deviceId] = true;
                }
            }
        }

        foreach ($this->physLinks as $index => $link) {
            if (isset($participants[$link['a_dev']], $participants[$link['b_dev']])) {
                $graph->addEdge($link['a_dev'], $link['b_dev'], $index);
            }
        }

        $domains = [];
        foreach ($graph->components() as $component) {
            $memberIds = array_values(array_filter($component, fn ($id) => isset($members[$id])));
            if ($memberIds) {
                sort($memberIds);
                $domains[] = [$memberIds, $graph];
            }
        }

        return $domains;
    }

    /**
     * Analyze one connected L2 domain of one VLAN instance: which root the bridges agree on, root
     * placement, timers, protocols, diameter, topology changes, port states and loops.
     *
     * @param  int[]  $memberIds  bridges of this instance inside the domain
     * @param  array<int, array>  $instances  device_id => instance of this VLAN key
     * @param  Graph  $graph  L2 adjacency including single-instance transit bridges
     * @return array one row of the domains table
     */
    private function analyzeDomain(int $key, array $memberIds, array $instances, Graph $graph, array $rootLookups): array
    {
        $before = count($this->findings);
        $label = StpIndex::vlanLabel($key);

        // which root does each bridge believe in?
        $roots = [];
        foreach ($memberIds as $deviceId) {
            $roots[$instances[$deviceId]['designatedRoot']][] = $deviceId;
        }
        uasort($roots, fn ($a, $b) => count($b) <=> count($a));
        $rootMac = (string) array_key_first($roots);
        $rootDevice = $rootMac !== '' ? $this->stp->bridgeOwner($rootMac) : null;

        if (count($roots) > 1 && count($memberIds) > 1) {
            $parts = [];
            foreach ($roots as $mac => $ids) {
                $parts[] = StpIndex::formatMac((string) $mac) . ' (' . $this->rootName((string) $mac) . ') <- ' . $this->names($ids);
            }
            $this->add(new Finding(
                Finding::CRITICAL, 'root_mismatch',
                "bridges disagree on the root bridge",
                'Connected bridges report different root bridges: ' . implode('; ', $parts) . '.',
                'A single connected L2 domain must elect one root. Look for unidirectional links, BPDU filter/guard on inter-switch ports, '
                . 'native VLAN mismatches or a PVST+/MST boundary. Data is from the last poll; re-check if a topology change was in progress.',
                $key, $memberIds,
            ));
        }

        $rootInstance = $rootDevice !== null ? ($instances[$rootDevice] ?? $this->stp->instance($rootDevice, $key)) : null;
        $lookup = $rootLookups[$rootMac] ?? [];
        $owner = self::lookupOwner($lookup);
        $rootInfo = [
            'mac' => $rootMac !== '' ? StpIndex::formatMac($rootMac) : null,
            // a bridge outside the analyzed group/location can still be linked to
            'device_id' => $rootDevice ?? $owner['device_id'] ?? null,
            'name' => $rootMac !== '' ? $this->rootName($rootMac, $rootLookups) : null,
            'priority' => $rootInstance['priority'] ?? null,
            'bridge_id' => $rootInstance ? StpIndex::bridgeId($rootInstance['priority'], $rootMac) : null,
            'monitored' => $rootDevice !== null || $owner !== null || ! empty($lookup['hidden']),
        ];

        if ($rootMac !== '' && $rootDevice === null) {
            $this->checkUnknownRoot($key, $rootMac, $memberIds, $instances, $lookup);
        }

        if ($rootInstance !== null && in_array($rootDevice, $memberIds, true)) {
            $this->checkRootPlacement($key, $rootDevice, $rootInstance, $memberIds, $graph);
            $this->checkTimers($key, $rootDevice, $rootInstance, $memberIds, $instances);
        }

        $this->checkProtocols($key, $memberIds, $instances);
        $hops = $this->checkDiameter($key, $rootDevice, $memberIds, $graph);
        $this->checkTopologyChanges($key, $memberIds, $instances);
        $this->checkPorts($key, $memberIds, $instances);
        $this->checkForwardingLoop($key, $memberIds);

        $severities = array_map(fn (Finding $f) => $f->severity, array_slice($this->findings, $before));
        $lastTc = null;
        $tcTotal = 0;
        $blockedCount = 0;
        $protocols = [];
        foreach ($memberIds as $deviceId) {
            $instance = $instances[$deviceId];
            $tcTotal += $instance['topChanges'];
            if ($instance['timeSinceTopologyChange'] > 0 || $instance['topChanges'] > 0) {
                $lastTc = $lastTc === null ? $instance['timeSinceTopologyChange'] : min($lastTc, $instance['timeSinceTopologyChange']);
            }
            $protocols[$instance['protocolSpecification']] = true;
            foreach ($this->stp->portsFor($deviceId, $key) as $port) {
                $blockedCount += StpIndex::isBlocking($port['state']) ? 1 : 0;
            }
        }

        return [
            'vlan' => $key,
            'label' => $label,
            'root' => $rootInfo,
            'members' => count($memberIds),
            'member_ids' => $memberIds,
            'protocols' => array_keys($protocols),
            'blocked' => $blockedCount,
            'max_hops' => $hops,
            'last_tc' => $lastTc,
            'tc_total' => $tcTotal,
            'timers' => $rootInstance ? sprintf('%d / %d / %d', $rootInstance['bridgeHelloTime'] ?: $rootInstance['helloTime'],
                $rootInstance['bridgeMaxAge'] ?: $rootInstance['maxAge'], $rootInstance['bridgeForwardDelay'] ?: $rootInstance['forwardDelay']) : null,
            'status' => Finding::worst(array_filter($severities, fn ($s) => $s !== Finding::INFO)),
        ];
    }

    /**
     * The root bridge MAC belongs to no analyzed bridge: decide whether it is a monitored bridge
     * outside the scope (fine), a monitored device without STP data (info) or an unknown bridge,
     * and in the latter case follow the root ports to where the path leaves the monitored network.
     */
    private function checkUnknownRoot(int $key, string $rootMac, array $memberIds, array $instances, array $lookup): void
    {
        $vendor = ! empty($lookup['vendor']) ? ' (' . $lookup['vendor'] . ')' : '';
        $owner = self::lookupOwner($lookup);

        if (! $owner && ! empty($lookup['hidden'])) {
            return; // monitored device outside this user's permissions: not a rogue bridge
        }

        if ($owner && $owner['source'] === 'stp') {
            return; // monitored bridge outside the analyzed group/location: nothing wrong
        }

        if ($owner) {
            // the MAC is an interface MAC of a monitored device that reports no STP data with it
            $name = $owner['name'] ?? $this->deviceName((int) $owner['device_id']);
            $this->add(new Finding(
                Finding::INFO, 'root_not_polled',
                "root bridge $name has no STP data",
                "Root bridge MAC " . StpIndex::formatMac($rootMac) . " belongs to $name, but LibreNMS has no spanning tree data for it in this instance.",
                'Enable the stp discovery/poller modules for this device to complete the analysis.',
                $key, [(int) $owner['device_id']],
            ));

            return;
        }

        // follow root ports toward the root until the path leaves the monitored network
        $exits = [];
        $memberSet = array_flip($memberIds);
        foreach ($memberIds as $deviceId) {
            $instance = $instances[$deviceId];
            if (! $instance['rootPort']) {
                continue;
            }
            $portRow = $this->stp->portByIndex($deviceId, $key, $instance['rootPort']);
            if (! $portRow) {
                continue;
            }
            $neighbor = $this->neighborOf($portRow['port_id']);
            if ($neighbor && $neighbor['device_id'] && isset($memberSet[$neighbor['device_id']])) {
                continue; // root port leads to another monitored bridge of this domain
            }
            $towards = $neighbor
                ? ($neighbor['device_id'] ? $this->deviceName($neighbor['device_id']) : ($neighbor['host'] ?: 'unmanaged neighbor')) . ($neighbor['name'] ? ' ' . InterfaceName::short($neighbor['name']) : '')
                : 'no LLDP/CDP neighbor';
            $exits[] = $this->deviceName($deviceId) . ' ' . $this->portName($portRow['port_id']) . " (towards $towards)";
        }

        $sightings = [];
        foreach (array_slice($lookup['fdb'] ?? [], 0, 5) as $fdb) {
            $sightings[] = ($fdb['name'] ?? $this->deviceName((int) $fdb['device_id'])) . ' '
                . (($fdb['port'] ?? '') ?: $this->portName((int) $fdb['port_id'])) . (! empty($fdb['vlan']) ? ' vlan ' . $fdb['vlan'] : '');
        }

        $detail = 'Root bridge ' . StpIndex::formatMac($rootMac) . $vendor . ' is not a device monitored by LibreNMS.';
        if ($exits) {
            $detail .= ' Root path leaves the monitored network at: ' . implode('; ', array_unique($exits)) . '.';
        }
        if ($sightings) {
            $detail .= ' MAC learned on: ' . implode('; ', $sightings) . '.';
        }

        $this->add(new Finding(
            Finding::WARNING, 'root_unmanaged',
            "root bridge is not monitored (possible rogue switch)",
            $detail,
            'Identify the device owning this bridge ID. If it is not an intended core switch, lower the priority on the intended root '
            . '(e.g. "spanning-tree vlan X root primary") and protect access ports with BPDU guard / root guard.',
            $key, $memberIds,
        ));
    }

    /**
     * Root bridge sanity: explicit priority, one of the designated roots (setting), not at the edge.
     */
    private function checkRootPlacement(int $key, int $rootDevice, array $rootInstance, array $memberIds, Graph $graph): void
    {
        $name = $this->deviceName($rootDevice);

        if ($this->isDefaultPriority($rootInstance['priority'], $key)) {
            $this->add(new Finding(
                Finding::WARNING, 'root_default_priority',
                "root bridge $name was elected with the default priority",
                "$name is root with priority {$rootInstance['priority']}; the election was decided by the lowest MAC address, "
                . 'so any new switch with a lower MAC can take over the root role.',
                'Set an explicit, low priority on the intended primary root (e.g. 4096) and a secondary root (e.g. 8192).',
                $key, [$rootDevice],
            ));
        }

        $preferred = $this->preferredRoots();
        if ($preferred && ! isset($preferred[$rootDevice])) {
            $this->add(new Finding(
                Finding::WARNING, 'root_not_preferred',
                "root bridge $name is not one of the designated root bridges",
                "Configured root bridges: " . $this->names(array_keys($preferred)) . ". Current root: $name.",
                'Lower the bridge priority on the designated root or raise it on ' . $name . '.',
                $key, [$rootDevice],
            ));
        }

        if (count($memberIds) >= 3 && $graph->degree($rootDevice) <= 1) {
            $role = $this->devices[$rootDevice]['role'] ?? '';
            $this->add(new Finding(
                Finding::WARNING, 'root_at_edge',
                "root bridge $name sits at the edge of the topology",
                "$name has a single L2 neighbor" . ($role ? " (role: $role)" : '') . ' while the domain has ' . count($memberIds)
                . ' bridges, so most traffic crosses sub-optimal paths.',
                'Move the root role to a core/distribution switch.',
                $key, [$rootDevice],
            ));
        }
    }

    /**
     * Timer checks: non-default or 802.1D-inconsistent timers on the root, and bridges whose
     * operational timers (learned from the root's BPDUs) differ from the root's.
     */
    private function checkTimers(int $key, int $rootDevice, array $root, array $memberIds, array $instances): void
    {
        $hello = $root['bridgeHelloTime'] ?: $root['helloTime'];
        $maxAge = $root['bridgeMaxAge'] ?: $root['maxAge'];
        $fwd = $root['bridgeForwardDelay'] ?: $root['forwardDelay'];

        if ($hello && $maxAge && $fwd) {
            if ($hello !== 2 || $maxAge !== 20 || $fwd !== 15) {
                $this->add(new Finding(
                    Finding::INFO, 'timers_non_default',
                    "non-default timers on the root bridge",
                    "Root " . $this->deviceName($rootDevice) . " uses hello $hello s, max age $maxAge s, forward delay $fwd s (defaults 2/20/15).",
                    'Make sure the timers were tuned on purpose for the network diameter.',
                    $key, [$rootDevice],
                ));
            }

            if ($maxAge < 2 * ($hello + 1) || 2 * ($fwd - 1) < $maxAge) {
                $this->add(new Finding(
                    Finding::WARNING, 'timer_relation',
                    "inconsistent timer relationship on the root bridge",
                    "802.1D requires 2 x (forward delay - 1) >= max age >= 2 x (hello + 1); root has hello $hello, max age $maxAge, forward delay $fwd.",
                    'Restore default timers or recalculate them for the network diameter.',
                    $key, [$rootDevice],
                ));
            }
        }

        // operational timers are learned from the root's BPDUs and should match everywhere
        $expected = [$root['helloTime'], $root['maxAge'], $root['forwardDelay']];
        if (! array_filter($expected)) {
            return;
        }
        $mismatch = [];
        foreach ($memberIds as $deviceId) {
            $instance = $instances[$deviceId];
            $actual = [$instance['helloTime'], $instance['maxAge'], $instance['forwardDelay']];
            if (array_filter($actual) && $actual !== $expected) {
                $mismatch[] = $deviceId;
            }
        }

        if ($mismatch) {
            $this->add(new Finding(
                Finding::WARNING, 'timer_mismatch',
                "bridges run timers different from the root",
                'Operational timers (hello/max age/forward delay) differ from the root (' . implode('/', $expected) . ') on: ' . $this->names($mismatch) . '.',
                'Non-root bridges inherit timers from the root BPDUs; a mismatch points to bridges that do not receive the root\'s BPDUs '
                . '(separate domain, BPDU filtering) or stale data.',
                $key, $mismatch,
            ));
        }
    }

    /**
     * Mixed STP modes in one domain (PVST+ / Rapid-PVST+ / MST / 802.1D).
     */
    private function checkProtocols(int $key, array $memberIds, array $instances): void
    {
        $protocols = [];
        foreach ($memberIds as $deviceId) {
            $protocol = $instances[$deviceId]['protocolSpecification'];
            if ($protocol !== '' && $protocol !== 'unknown') {
                $protocols[$protocol][] = $deviceId;
            }
        }

        if (count($protocols) < 2) {
            return;
        }

        $names = array_keys($protocols);
        $pvst = array_intersect($names, ['pvstPlus', 'rapidPvstPlus']);
        $conflict = count($pvst) === 2 || ($pvst && array_intersect($names, ['mst', 'mstp']));
        $parts = [];
        foreach ($protocols as $protocol => $ids) {
            $parts[] = "$protocol: " . $this->names($ids);
        }

        $this->add(new Finding(
            $conflict ? Finding::WARNING : Finding::INFO, 'protocol_mix',
            'mixed spanning tree modes',
            'Bridges in this domain run different STP variants: ' . implode('; ', $parts) . '.',
            $conflict
                ? 'Mixing PVST+, Rapid-PVST+ and MST falls back to slow 802.1D timers at the boundaries; standardize on one mode.'
                : 'Non-Cisco switches often report "ieee8021d" even when running RSTP/MSTP; verify the boundary behaves as intended.',
            $key, $memberIds,
        ));
    }

    /**
     * Bridge hops from the root against the configured maximum diameter.
     *
     * @return int|null largest hop count from the root, null when the root is not in the domain
     */
    private function checkDiameter(int $key, ?int $rootDevice, array $memberIds, Graph $graph): ?int
    {
        if ($rootDevice === null || ! $graph->hasNode($rootDevice)) {
            return null;
        }

        $distances = $graph->distances($rootDevice);
        $hops = 0;
        $farthest = [];
        foreach ($memberIds as $deviceId) {
            if (isset($distances[$deviceId])) {
                if ($distances[$deviceId] > $hops) {
                    $hops = $distances[$deviceId];
                    $farthest = [$deviceId];
                } elseif ($distances[$deviceId] === $hops && $hops > 0) {
                    $farthest[] = $deviceId;
                }
            }
        }

        $max = $this->settings->int('stp_max_diameter', 1);
        if ($hops > $max) {
            $this->add(new Finding(
                Finding::WARNING, 'diameter',
                "bridges are $hops hops from the root",
                'Farthest bridges: ' . $this->names($farthest) . ". The 802.1D default timers assume a diameter of at most $max bridges.",
                'Flatten the L2 design, move the root to the center of the topology or tune max age / forward delay.',
                $key, $farthest,
            ));
        }

        return $hops;
    }

    /**
     * Recent topology changes and change storms (rate per day of uptime), with the ports most
     * likely causing them: frequently transitioning ports that are not inter-switch links.
     */
    private function checkTopologyChanges(int $key, array $memberIds, array $instances): void
    {
        $recent = [];
        $storm = [];
        foreach ($memberIds as $deviceId) {
            $instance = $instances[$deviceId];
            if ($instance['topChanges'] > 0 && $instance['timeSinceTopologyChange'] < $this->tcRecentSeconds()) {
                $recent[$deviceId] = $instance['timeSinceTopologyChange'];
            }
            $rate = $this->tcRate($deviceId, $instance);
            if ($rate !== null && $rate > $this->settings->int('stp_tc_rate_per_day', 1)) {
                $storm[$deviceId] = $rate;
            }
        }

        if (! $recent && ! $storm) {
            return;
        }

        // likely sources: frequently transitioning ports that are not inter-switch links
        $suspects = [];
        foreach ($memberIds as $deviceId) {
            foreach ($this->stp->portsFor($deviceId, $key) as $port) {
                $neighbor = $this->neighborOf($port['port_id']);
                $interSwitch = $neighbor && $neighbor['device_id'] && $this->stp->hasStp($neighbor['device_id']);
                if (! $interSwitch && $port['forwardTransitions'] >= max(5, intdiv($this->settings->int('stp_flap_transitions', 1), 4))) {
                    $suspects[] = [$port['forwardTransitions'], $deviceId, $port['port_id']];
                }
            }
        }
        rsort($suspects);
        $suspects = array_slice($suspects, 0, 5);
        $suspectText = $suspects
            ? ' Ports with most transitions to forwarding: ' . implode(', ', array_map(fn ($s) => $this->deviceName($s[1]) . ' ' . $this->portName($s[2]) . " ({$s[0]})", $suspects)) . '.'
            : '';

        if ($storm) {
            arsort($storm);
            $parts = [];
            foreach ($storm as $deviceId => $rate) {
                $parts[] = $this->deviceName($deviceId) . " ($rate/day)";
            }
            $this->add(new Finding(
                Finding::WARNING, 'tc_rate',
                "high rate of topology changes",
                'Topology change rate above ' . $this->settings->int('stp_tc_rate_per_day', 1) . '/day on: ' . implode(', ', $parts) . '.' . $suspectText,
                'Every topology change flushes MAC tables and causes flooding. Enable portfast/edge on host ports and look for flapping links.',
                $key, array_keys($storm), array_map(fn ($s) => $s[2], $suspects),
            ));
        } else {
            $last = min($recent);
            $this->add(new Finding(
                Finding::INFO, 'tc_recent',
                "topology change " . self::duration($last) . ' ago',
                count($recent) . ' bridge(s) saw a topology change in the last ' . self::duration($this->tcRecentSeconds()) . '.' . $suspectText,
                'Correlate with link up/down events; a single change after maintenance is expected.',
                $key, array_keys($recent), array_map(fn ($s) => $s[2], $suspects),
            ));
        }
    }

    /**
     * Port level problems: broken ports, flapping ports, ports still converging, forwarding ports
     * that are neither root nor designated, and non-root bridges without a root port.
     */
    private function checkPorts(int $key, array $memberIds, array $instances): void
    {
        $flapLimit = $this->settings->int('stp_flap_transitions', 1);
        $broken = [];
        $flapping = [];
        $transitioning = [];
        $nonDesignated = [];
        $noRootPort = [];

        foreach ($memberIds as $deviceId) {
            $instance = $instances[$deviceId];
            $ports = $this->stp->portsFor($deviceId, $key);
            if (! StpIndex::isRoot($instance) && ! $instance['rootPort'] && $instance['designatedRoot'] !== '') {
                $noRootPort[] = $deviceId;
            }

            // only trust designated-bridge comparisons when they work for this device at all
            $selfMatches = false;
            foreach ($ports as $port) {
                if ($port['designatedBridge'] !== '' && $port['designatedBridge'] === $instance['bridgeAddress']) {
                    $selfMatches = true;
                    break;
                }
            }

            foreach ($ports as $port) {
                $portLabel = $this->deviceName($deviceId) . ' ' . $this->portName($port['port_id']);
                if ($port['state'] === 'broken') {
                    $broken[$port['port_id']] = $portLabel;
                } elseif (in_array($port['state'], ['listening', 'learning'], true)) {
                    $transitioning[$port['port_id']] = $portLabel . ' (' . $port['state'] . ')';
                }

                if ($port['forwardTransitions'] >= $flapLimit) {
                    $flapping[$port['port_id']] = $portLabel . " ({$port['forwardTransitions']})";
                }

                if ($selfMatches && $port['state'] === 'forwarding' && StpIndex::role($instance, $port) === 'nondesignated') {
                    $nonDesignated[$port['port_id']] = $portLabel;
                }
            }
        }

        if ($broken) {
            $this->add(new Finding(
                Finding::CRITICAL, 'port_broken',
                "ports in broken state",
                'Ports reported as broken (often root/loop/type inconsistent or err-disabled by STP guards): ' . implode(', ', $broken) . '.',
                'Check "show spanning-tree inconsistentports" and the guard configuration on these ports.',
                $key, $this->devicesOfPorts(array_keys($broken)), array_keys($broken),
            ));
        }

        if ($flapping) {
            $this->add(new Finding(
                Finding::WARNING, 'port_flapping',
                "ports with many transitions to forwarding",
                "Ports that went to forwarding at least $flapLimit times: " . implode(', ', array_slice($flapping, 0, 15)) . (count($flapping) > 15 ? ' ...' : '') . '.',
                'Edge ports should use portfast/edge so they do not generate topology changes; inter-switch ports that flap point to unstable links.',
                $key, $this->devicesOfPorts(array_keys($flapping)), array_keys($flapping),
            ));
        }

        if ($nonDesignated) {
            $this->add(new Finding(
                Finding::WARNING, 'nondesignated_forwarding',
                "forwarding ports that are neither root nor designated",
                'These ports forward although another bridge is designated for the segment: ' . implode(', ', array_slice($nonDesignated, 0, 15)) . '.',
                'Such a port should be alternate/blocking. Possible causes: unidirectional link, BPDUs filtered, or data polled mid-convergence.',
                $key, $this->devicesOfPorts(array_keys($nonDesignated)), array_keys($nonDesignated),
            ));
        }

        if ($transitioning) {
            $this->add(new Finding(
                Finding::INFO, 'port_transitioning',
                "ports were converging during the last poll",
                implode(', ', array_slice($transitioning, 0, 15)) . '.',
                'Transient during convergence; persistent listening/learning states indicate a problem.',
                $key, $this->devicesOfPorts(array_keys($transitioning)), array_keys($transitioning),
            ));
        }

        if ($noRootPort) {
            $this->add(new Finding(
                Finding::WARNING, 'no_root_port',
                "non-root bridges without a root port",
                'These bridges are not root but report no root port: ' . $this->names($noRootPort) . '.',
                'Inconsistent STP state; verify on the device and re-poll.',
                $key, $noRootPort,
            ));
        }
    }

    /**
     * A cycle made only of links forwarding on both ends is a bridging loop.
     */
    private function checkForwardingLoop(int $key, array $memberIds): void
    {
        $memberSet = array_flip($memberIds);
        $links = [];
        foreach ($this->physLinks as $link) {
            if (! isset($memberSet[$link['a_dev']], $memberSet[$link['b_dev']])) {
                continue;
            }
            if (! $this->portUp($link['a_port']) || ! $this->portUp($link['b_port'])) {
                continue;
            }
            $a = $this->stp->portRow($link['a_dev'], $this->stp->keyFor($link['a_dev'], $key) ?? $key, $link['a_port']);
            $b = $this->stp->portRow($link['b_dev'], $this->stp->keyFor($link['b_dev'], $key) ?? $key, $link['b_port']);
            if ($a && $b && $a['state'] === 'forwarding' && $b['state'] === 'forwarding') {
                $links[] = $link;
            }
        }

        $cycle = $this->logicalGraph($links)->findCycle();
        if ($cycle === null) {
            return;
        }

        $path = implode(' -> ', array_map(fn ($node) => $this->logicalName($node), $cycle));
        $devices = [];
        foreach ($cycle as $node) {
            $devices = array_merge($devices, array_map('intval', explode('+', (string) $node)));
        }

        $this->add(new Finding(
            Finding::CRITICAL, 'forwarding_loop',
            'possible bridging loop',
            "All ports along this path were forwarding at the last poll: $path.",
            'One port on every redundant path must be blocking. Verify STP is enabled on these links, look for BPDU filter or '
            . 'unidirectional links. Stacks/virtual chassis or MLAG pairs not detected as such can also trigger this warning.',
            $key, $devices,
        ));
    }

    // ---------------------------------------------------------------- network wide checks

    /**
     * Inter-switch switchports that take part in no STP instance although both switches run STP
     * (spanning tree disabled or BPDU filter on the port). Routed ports are ignored.
     */
    private function checkPortsWithoutStp(): void
    {
        $missing = [];
        foreach ($this->physLinks as $link) {
            if (! $this->stp->hasStp($link['a_dev']) || ! $this->stp->hasStp($link['b_dev'])) {
                continue;
            }
            foreach ([[$link['a_dev'], $link['a_port']], [$link['b_dev'], $link['b_port']]] as [$deviceId, $portId]) {
                $port = $this->ports[$portId] ?? null;
                if (! $port || ! $this->portUp($portId) || ! empty($port['has_ip']) || empty($port['switchport'])) {
                    continue;
                }
                if (! $this->portHasStp($deviceId, $portId)) {
                    $missing[$portId] = $deviceId;
                }
            }
        }

        if ($missing) {
            $labels = [];
            foreach ($missing as $portId => $deviceId) {
                $labels[] = $this->deviceName($deviceId) . ' ' . $this->portName($portId);
            }
            $this->add(new Finding(
                Finding::WARNING, 'stp_disabled_port',
                'Inter-switch ports without spanning tree',
                'These switchports connect two STP bridges but do not participate in STP: ' . implode(', ', array_slice($labels, 0, 20)) . '.',
                'STP disabled or BPDU filter on an inter-switch link removes loop protection. Remove "spanning-tree bpdufilter" / re-enable STP.',
                null, array_values(array_unique($missing)), array_keys($missing),
            ));
        }
    }

    /**
     * Does the port (or the aggregate it belongs to) take part in spanning tree in any VLAN?
     * Uses the 'has_stp' flag when the caller provides it, because only some instances' port
     * rows may be loaded; otherwise checks the loaded rows.
     */
    private function portHasStp(int $deviceId, int $portId): bool
    {
        $candidates = array_filter([$portId, $this->lagParent[$portId] ?? null]);
        foreach ($candidates as $candidate) {
            if (! empty($this->ports[$candidate]['has_stp'])) {
                return true;
            }
        }

        if (array_key_exists('has_stp', $this->ports[$portId] ?? [])) {
            return false;
        }

        foreach (array_keys($this->stp->instancesFor($deviceId)) as $key) {
            if ($this->stp->portRow($deviceId, $key, $portId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Switches with switch neighbours but no STP data (STP disabled or not exposed via SNMP).
     */
    private function checkDevicesWithoutStp(): void
    {
        $missing = [];
        foreach ($this->devices as $deviceId => $device) {
            $role = $device['role'] ?? '';
            if ($this->stp->hasStp($deviceId) || ! in_array($role, [DeviceRole::SWITCH, DeviceRole::L3SWITCH], true)) {
                continue;
            }
            foreach ($this->adjacency->neighbours($deviceId) as $neighbor) {
                if (in_array($this->devices[$neighbor]['role'] ?? '', [DeviceRole::SWITCH, DeviceRole::L3SWITCH], true)) {
                    $missing[] = $deviceId;
                    break;
                }
            }
        }

        if ($missing) {
            $this->add(new Finding(
                Finding::INFO, 'no_stp_data',
                'Switches without spanning tree data',
                'No STP information is available for: ' . $this->names(array_slice($missing, 0, 30)) . (count($missing) > 30 ? ' ...' : '') . '.',
                'Either STP is disabled (dangerous with redundant links) or the stp module is disabled / BRIDGE-MIB is not exposed via SNMP.',
                null, $missing,
            ));
        }
    }

    /**
     * Redundant L2 paths through switches that do not report STP state cannot be verified loop-free.
     */
    private function checkLoopsWithoutStp(): void
    {
        $links = array_filter($this->physLinks, fn ($l) => $this->portUp($l['a_port']) && $this->portUp($l['b_port'])
            && empty($this->ports[$l['a_port']]['has_ip']) && empty($this->ports[$l['b_port']]['has_ip']));
        $graph = $this->logicalGraph(array_values($links));

        $flagged = [];
        foreach ($graph->nodesOnCycles() as $node) {
            $ids = array_map('intval', explode('+', (string) $node));
            $noStp = array_filter($ids, fn ($id) => ! $this->stp->hasStp($id)
                && in_array($this->devices[$id]['role'] ?? '', [DeviceRole::SWITCH, DeviceRole::L3SWITCH], true));
            $flagged = array_merge($flagged, $noStp);
        }

        if ($flagged) {
            $this->add(new Finding(
                Finding::WARNING, 'loop_without_stp',
                'Redundant L2 paths through switches without STP data',
                'These switches sit on a redundant (looped) L2 path but report no spanning tree state: ' . $this->names(array_unique($flagged)) . '.',
                'Confirm STP (or another loop prevention such as MLAG/stacking) protects these paths and enable the stp module in LibreNMS.',
                null, array_values(array_unique($flagged)),
            ));
        }
    }

    /**
     * Root bridge and root port changes recorded by LibreNMS in the eventlog.
     */
    private function checkEvents(): void
    {
        $since = $this->now - $this->settings->int('stp_event_days', 1) * 86400;
        $changes = ['root' => [], 'port' => []];
        $last = [];

        // LibreNMS logs one entry per STP instance, so a single failover on a PVST switch with
        // 50 VLANs writes 50 entries within one poll: count bursts, not entries.
        $events = $this->events;
        usort($events, fn ($a, $b) => strcmp((string) ($a['datetime'] ?? ''), (string) ($b['datetime'] ?? '')));
        foreach ($events as $event) {
            $time = strtotime((string) ($event['datetime'] ?? '')) ?: 0;
            if ($time < $since) {
                continue;
            }
            $message = (string) ($event['message'] ?? '');
            $kind = match (true) {
                stripos($message, 'designated root changed') !== false => 'root',
                stripos($message, 'root port changed') !== false => 'port',
                default => null,
            };
            if ($kind === null) {
                continue;
            }

            $deviceId = (int) ($event['device_id'] ?? 0);
            if (! isset($last[$kind][$deviceId]) || $time - $last[$kind][$deviceId] > 120) {
                $changes[$kind][$deviceId] = ($changes[$kind][$deviceId] ?? 0) + 1;
            }
            $last[$kind][$deviceId] = $time;
        }
        [$rootChanges, $rootPortChanges] = [$changes['root'], $changes['port']];

        $days = $this->settings->int('stp_event_days', 1);
        if ($rootChanges) {
            arsort($rootChanges);
            $this->add(new Finding(
                max($rootChanges) >= 3 ? Finding::CRITICAL : Finding::WARNING, 'root_changed',
                'Root bridge changes detected',
                "Designated root changed in the last $days day(s) on: " . $this->countList($rootChanges) . '.',
                'Root changes cause network-wide reconvergence. Check for new switches with low priority, root guard violations or failing core links.',
                null, array_keys($rootChanges),
            ));
        }

        if ($rootPortChanges) {
            arsort($rootPortChanges);
            $this->add(new Finding(
                max($rootPortChanges) >= 5 ? Finding::WARNING : Finding::INFO, 'root_port_changed',
                'Root port changes detected',
                "Root port changed in the last $days day(s) on: " . $this->countList($rootPortChanges) . '.',
                'A changing root port means the uplink toward the root failed or flapped.',
                null, array_keys($rootPortChanges),
            ));
        }
    }

    // ---------------------------------------------------------------- rows for tables

    /**
     * Row of the bridges table (one per device and instance).
     */
    private function bridgeRow(int $deviceId, int $key, array $instance): array
    {
        $rootPort = null;
        if ($instance['rootPort'] && ! StpIndex::isRoot($instance)) {
            $row = $this->stp->portByIndex($deviceId, $key, $instance['rootPort']);
            $rootPort = $row ? $this->portName($row['port_id']) : 'bridge port ' . $instance['rootPort'];
        }

        $ports = $this->stp->portsFor($deviceId, $key);

        return [
            'device_id' => $deviceId,
            'name' => $this->deviceName($deviceId),
            'vlan' => $key,
            'label' => StpIndex::vlanLabel($key),
            'is_root' => StpIndex::isRoot($instance),
            'priority' => $instance['priority'],
            'bridge_id' => StpIndex::bridgeId($instance['priority'], $instance['bridgeAddress']),
            'root_id' => StpIndex::formatMac($instance['designatedRoot']),
            'root_name' => $instance['designatedRoot'] !== '' ? $this->rootName($instance['designatedRoot']) : null,
            'root_port' => $rootPort,
            'root_cost' => $instance['rootCost'],
            'protocol' => $instance['protocolSpecification'],
            'timers' => sprintf('%d / %d / %d', $instance['helloTime'], $instance['maxAge'], $instance['forwardDelay']),
            'top_changes' => $instance['topChanges'],
            'last_tc' => $instance['topChanges'] > 0 ? $instance['timeSinceTopologyChange'] : null,
            'tc_rate' => $this->tcRate($deviceId, $instance),
            'ports' => count($ports),
            'blocked' => count(array_filter($ports, fn ($p) => StpIndex::isBlocking($p['state']))),
        ];
    }

    /**
     * Row of the blocked ports table, with the neighbour across the link when LLDP/CDP knows it.
     */
    private function blockedRow(int $deviceId, int $key, array $instance, array $port): array
    {
        $neighbor = $this->neighborOf($port['port_id']);

        return [
            'device_id' => $deviceId,
            'name' => $this->deviceName($deviceId),
            'vlan' => $key,
            'label' => StpIndex::vlanLabel($key),
            'port_id' => $port['port_id'],
            'port' => $this->portName($port['port_id']),
            'state' => $port['state'],
            'role' => StpIndex::role($instance, $port),
            'cost' => $port['pathCost'],
            'designated_bridge' => StpIndex::formatMac($port['designatedBridge']),
            'designated_name' => ($owner = $this->stp->bridgeOwner($port['designatedBridge'])) !== null ? $this->deviceName($owner) : null,
            'transitions' => $port['forwardTransitions'],
            'neighbor' => $neighbor ? [
                'device_id' => $neighbor['device_id'],
                'name' => $neighbor['device_id'] ? $this->deviceName($neighbor['device_id']) : ($neighbor['host'] ?: null),
                'port' => $neighbor['port_id'] ? $this->portName($neighbor['port_id']) : InterfaceName::short($neighbor['name']),
            ] : null,
        ];
    }

    /**
     * Most recent STP eventlog entries for the events table.
     */
    private function eventRows(): array
    {
        $rows = [];
        foreach (array_slice($this->events, 0, 200) as $event) {
            $rows[] = [
                'device_id' => (int) ($event['device_id'] ?? 0),
                'name' => $this->deviceName((int) ($event['device_id'] ?? 0)),
                'datetime' => (string) ($event['datetime'] ?? ''),
                'message' => (string) ($event['message'] ?? ''),
                'severity' => (int) ($event['severity'] ?? 0),
            ];
        }

        return $rows;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Index the links table: neighbour per local port, device adjacency, and one entry per
     * physical link between two analyzed devices (both directions of LLDP/CDP merged).
     */
    private function prepareLinks(): void
    {
        $this->adjacency = new Graph;
        $seen = [];
        foreach ($this->links as $link) {
            $ld = (int) $link['local_device_id'];
            $lp = (int) ($link['local_port_id'] ?? 0);
            $rd = (int) ($link['remote_device_id'] ?? 0);
            $rp = (int) ($link['remote_port_id'] ?? 0);

            if ($lp) {
                $this->neighbor[$lp] ??= [
                    'device_id' => $rd ?: null,
                    'port_id' => $rp ?: null,
                    'name' => (string) ($link['remote_port'] ?? ''),
                    'host' => (string) ($link['remote_hostname'] ?? ''),
                ];
            }

            if (! $rd || $rd === $ld || ! isset($this->devices[$ld], $this->devices[$rd])) {
                continue;
            }
            $this->adjacency->addEdge($ld, $rd);

            if ($lp && $rp) {
                $this->neighbor[$rp] ??= ['device_id' => $ld, 'port_id' => $lp, 'name' => '', 'host' => ''];
                $key = min($lp, $rp) . '-' . max($lp, $rp);
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $this->physLinks[] = $ld < $rd
                        ? ['a_dev' => $ld, 'a_port' => $lp, 'b_dev' => $rd, 'b_port' => $rp]
                        : ['a_dev' => $rd, 'a_port' => $rp, 'b_dev' => $ld, 'b_port' => $lp];
                }
            }
        }
    }

    /**
     * Graph of links where LAG members collapse into one edge and multi-chassis LAG
     * peers (MLAG/vPC: one aggregate connected to two switches) merge into one node "a+b".
     */
    private function logicalGraph(array $links): Graph
    {
        // detect multi-chassis LAGs
        $lagRemotes = [];
        foreach ($links as $link) {
            foreach ([['a', 'b'], ['b', 'a']] as [$x, $y]) {
                $parent = $this->lagParent[$link[$x . '_port']] ?? null;
                if ($parent) {
                    $lagRemotes[$link[$x . '_dev'] . ':' . $parent][$link[$y . '_dev']] = true;
                }
            }
        }

        $merge = []; // device => representative
        $find = function (int $id) use (&$merge, &$find): int {
            if (! isset($merge[$id]) || $merge[$id] === $id) {
                return $id;
            }

            return $merge[$id] = $find($merge[$id]);
        };
        foreach ($lagRemotes as $remotes) {
            $ids = array_keys($remotes);
            for ($i = 1; $i < count($ids); $i++) {
                $ra = $find($ids[0]);
                $rb = $find($ids[$i]);
                if ($ra !== $rb) {
                    $merge[max($ra, $rb)] = min($ra, $rb);
                }
            }
        }

        $groups = [];
        foreach (array_keys($merge) as $id) {
            $groups[$find($id)][$id] = true;
        }
        $nodeName = function (int $id) use ($find, &$groups): string {
            $root = $find($id);
            if (! isset($groups[$root])) {
                return (string) $id;
            }
            $members = array_keys($groups[$root] + [$root => true]);
            sort($members);

            return implode('+', $members);
        };

        $graph = new Graph;
        $seen = [];
        foreach ($links as $link) {
            $a = $nodeName($link['a_dev']);
            $b = $nodeName($link['b_dev']);
            if ($a === $b) {
                continue; // MLAG peer link or stack internal link
            }
            // all members of one aggregate (also towards an MLAG pair) form one logical link,
            // independent links between the same nodes stay separate (and form a cycle)
            $isLag = isset($this->lagParent[$link['a_port']]) || isset($this->lagParent[$link['b_port']]);
            $ends = [[$a, $link['a_port']], [$b, $link['b_port']]];
            usort($ends, fn ($x, $y) => strcmp($x[0], $y[0]));
            $parts = [];
            foreach ($ends as [$node, $port]) {
                $parent = $this->lagParent[$port] ?? null;
                $parts[] = $node . ':' . match (true) {
                    $isLag && str_contains($node, '+') => 'mlag',
                    $parent !== null => 'lag' . $parent,
                    default => 'p' . $port,
                };
            }
            $edgeKey = implode('|', $parts);
            if (isset($seen[$edgeKey])) {
                continue;
            }
            $seen[$edgeKey] = true;
            $graph->addEdge($a, $b, $edgeKey);
        }

        return $graph;
    }

    /**
     * Display name of a logical graph node ("12" or merged MLAG pair "12+13").
     */
    private function logicalName(int|string $node): string
    {
        return implode('+', array_map(fn ($id) => $this->deviceName((int) $id), explode('+', (string) $node)));
    }

    /**
     * LLDP/CDP neighbour seen on a port, or on a member of the aggregate when the port is a LAG.
     */
    private function neighborOf(int $portId): ?array
    {
        if (isset($this->neighbor[$portId])) {
            return $this->neighbor[$portId];
        }

        // STP runs on the aggregate, LLDP on its members
        foreach ($this->lagMembers[$portId] ?? [] as $member) {
            if (isset($this->neighbor[$member])) {
                return $this->neighbor[$member];
            }
        }

        return null;
    }

    /**
     * Ports without status data count as up (the links table only keeps recently seen neighbours).
     */
    private function portUp(int $portId): bool
    {
        $status = $this->ports[$portId]['ifOperStatus'] ?? 'up';

        return $status === 'up';
    }

    /**
     * Devices listed in the 'designated root bridges' setting (ids, hostnames, sysNames or display names).
     *
     * @return array<int, true>
     */
    private function preferredRoots(): array
    {
        return $this->settings->devicesIn('stp_preferred_roots', $this->devices);
    }

    /**
     * 32768 is the default bridge priority; with the extended system id (Cisco PVST) the VLAN
     * number is added, so VLAN 10 reports 32778 and the default instance (VLAN 1) 32769.
     */
    private function isDefaultPriority(int $priority, int $key): bool
    {
        return $priority === self::DEFAULT_PRIORITY || $priority === self::DEFAULT_PRIORITY + max(1, $key);
    }

    /**
     * Topology changes younger than this many seconds count as recent.
     */
    private function tcRecentSeconds(): int
    {
        return $this->settings->int('stp_tc_recent_minutes', 1) * 60;
    }

    /**
     * Topology changes per day of device uptime, null when there is not enough uptime to tell.
     */
    private function tcRate(int $deviceId, array $instance): ?float
    {
        $uptime = (int) ($this->devices[$deviceId]['uptime'] ?? 0);
        if ($uptime < 3600 || $instance['topChanges'] <= 0) {
            return null;
        }

        return round($instance['topChanges'] / ($uptime / 86400), 1);
    }

    /**
     * Human readable name of a root bridge MAC: the monitored device owning it, or a description
     * of the unmonitored bridge (with its OUI vendor when known).
     */
    private function rootName(string $mac, ?array $lookups = null): string
    {
        $lookups ??= $this->rootLookups;
        if (($owner = $this->stp->bridgeOwner($mac)) !== null) {
            return $this->deviceName($owner);
        }
        if ($owner = self::lookupOwner($lookups[$mac] ?? [])) {
            return $owner['name'] ?? $this->deviceName((int) $owner['device_id']);
        }
        if (! empty($lookups[$mac]['hidden'])) {
            return 'monitored bridge (no access)';
        }

        return ! empty($lookups[$mac]['vendor']) ? 'unmonitored ' . $lookups[$mac]['vendor'] : 'unmonitored bridge';
    }

    /**
     * Monitored owner of a looked up root MAC, preferring the device whose bridge address it is.
     *
     * @return array{device_id: int, port_id: ?int, name: ?string, source: string}|null
     */
    private static function lookupOwner(array $lookup): ?array
    {
        $owners = $lookup['owners'] ?? [];
        foreach ($owners as $owner) {
            if (($owner['source'] ?? '') === 'stp') {
                return $owner + ['name' => null];
            }
        }

        return isset($owners[0]) ? $owners[0] + ['name' => null, 'source' => 'interface'] : null;
    }

    /**
     * Display name of a device, "device #id" when it is not part of the analysis.
     */
    private function deviceName(int $deviceId): string
    {
        $device = $this->devices[$deviceId] ?? null;
        if (! $device) {
            return "device #$deviceId";
        }

        return (string) (($device['display'] ?? '') ?: ($device['hostname'] ?? $deviceId));
    }

    private function names(array $deviceIds): string
    {
        return implode(', ', array_map(fn ($id) => $this->deviceName((int) $id), $deviceIds));
    }

    private function countList(array $counts): string
    {
        $parts = [];
        foreach ($counts as $deviceId => $count) {
            $parts[] = $this->deviceName((int) $deviceId) . " ($count)";
        }

        return implode(', ', $parts);
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
     * @param  int[]  $portIds
     * @return int[] device ids owning these ports
     */
    private function devicesOfPorts(array $portIds): array
    {
        $devices = [];
        foreach ($portIds as $portId) {
            if (isset($this->ports[$portId]['device_id'])) {
                $devices[] = (int) $this->ports[$portId]['device_id'];
            }
        }

        return array_values(array_unique($devices));
    }

    private function add(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    /**
     * Compact human readable duration (95s, 12m, 3.5h, 2.1d).
     */
    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => $seconds . 's',
            $seconds < 7200 => round($seconds / 60) . 'm',
            $seconds < 172800 => round($seconds / 3600, 1) . 'h',
            default => round($seconds / 86400, 1) . 'd',
        };
    }
}
