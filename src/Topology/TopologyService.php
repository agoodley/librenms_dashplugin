<?php

namespace Agoodley\LibrenmsTopology\Topology;

use Agoodley\LibrenmsTopology\Data\LoopEvidenceRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Links\LinkAnalyzer;
use Agoodley\LibrenmsTopology\Redundancy\PortUnits;
use Agoodley\LibrenmsTopology\Redundancy\RedundancyAnalyzer;
use Agoodley\LibrenmsTopology\Stp\LoopEvidence;
use Agoodley\LibrenmsTopology\Stp\StpAnalyzer;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * Glue between the repository (LibreNMS database) and the pure builders/analyzers.
 */
class TopologyService
{
    /** Above this many ports_stp rows the STP page analyzes one VLAN at a time. */
    private const STP_ALL_VLAN_LIMIT = 150000;

    /** Above this many ports_vlans rows on inter-switch ports the link page skips the VLAN checks. */
    private const LINK_VLAN_LIMIT = 1000000;

    private const L3_ROLES = [DeviceRole::ROUTER, DeviceRole::L3SWITCH, DeviceRole::FIREWALL];

    /** Port columns the redundancy analysis needs (it loads every link port of the network). */
    private const REDUNDANCY_PORT_COLUMNS = ['port_id', 'device_id', 'ifIndex', 'ifName', 'ifDescr', 'ifOperStatus', 'ifAdminStatus'];

    public function __construct(
        private readonly TopologyRepository $repo,
        private readonly PluginSettings $settings,
    ) {
    }

    /**
     * @param  array{device: ?int, depth: int, group: ?int, location: ?int, vlan: ?int, unmanaged: bool, lag: bool, isolated: bool}  $p
     */
    public function l2(array $p): array
    {
        [$scope, $root] = $this->repo->scope($p);
        $links = $this->repo->links($scope);
        $ids = L2Builder::scopeDevices($links, $scope, $root, $root ? $p['depth'] : null);

        $linked = [];
        foreach ($links as $link) {
            $linked[(int) $link['local_device_id']] = ($linked[(int) $link['local_device_id']] ?? 0) + 1;
            if ($link['remote_device_id']) {
                $linked[(int) $link['remote_device_id']] = ($linked[(int) $link['remote_device_id']] ?? 0) + 1;
            }
        }
        if (! $root && ! $p['isolated']) {
            $ids = array_values(array_filter($ids, fn ($id) => isset($linked[$id])));
        }

        [$ids, $total, $truncated] = $this->limit($ids, $root, $linked);
        $idSet = array_flip($ids);
        $links = array_values(array_filter($links, fn ($l) => isset($idSet[(int) $l['local_device_id']])));

        $linkPorts = [];
        foreach ($links as $link) {
            $linkPorts[] = (int) $link['local_port_id'];
            $linkPorts[] = (int) $link['remote_port_id'];
        }
        $lag = $this->repo->lagParents($ids);
        $linkPorts = array_values(array_filter(array_unique(array_merge($linkPorts, array_map(fn ($p) => $lag[$p] ?? 0, $linkPorts)))));

        // the overlay shows one instance per device: the one carrying the selected VLAN
        $stp = $this->singleInstanceIndex($this->repo->stpInstances($ids), $p['vlan'], $lag);
        $rootPorts = array_filter(array_map(fn ($id) => $stp->rootPortId($id, $p['vlan']), $ids));
        $ports = $this->repo->ports(array_merge($linkPorts, $rootPorts));

        $builder = new L2Builder(
            $this->repo->devices($ids),
            $links,
            $ports,
            $this->repo->portVlans($linkPorts),
            $lag,
            $stp,
            $this->repo->deviceVlans($ids),
        );

        $result = $builder->build([
            'vlan' => $p['vlan'],
            'unmanaged' => $p['unmanaged'],
            'collapseLag' => $p['lag'],
            'root' => $root,
        ]);
        $result['scope'] = $this->scopeInfo($p, $root, $total, count($ids), $truncated);

        return $result;
    }

    /**
     * @param  array{device: ?int, depth: int, group: ?int, location: ?int, vrf: ?string, stubs: bool, ospf: bool, bgp: bool, ipv6: bool, all_devices: bool}  $p
     */
    public function l3(array $p): array
    {
        [$scope, $root] = $this->repo->scope($p);
        $addresses = $this->repo->addresses($scope, $p['ipv6']);

        $withAddresses = array_values(array_unique(array_map(fn ($a) => (int) $a['device_id'], $addresses)));
        if ($root && ! in_array($root, $withAddresses, true)) {
            $withAddresses[] = $root;
        }
        $devices = $this->repo->devices($withAddresses);
        if (! $p['all_devices']) {
            $devices = array_filter($devices, fn ($d, $id) => $id === $root || in_array($d['role'], self::L3_ROLES, true), ARRAY_FILTER_USE_BOTH);
        }

        // limit very large maps to the devices with the most interfaces
        $counts = [];
        foreach ($addresses as $address) {
            $counts[(int) $address['device_id']] = ($counts[(int) $address['device_id']] ?? 0) + 1;
        }
        $total = count($devices);
        $truncated = false;
        if (! $root && $total > $this->maxNodes()) {
            $ids = array_keys($devices);
            usort($ids, fn ($a, $b) => ($counts[$b] ?? 0) <=> ($counts[$a] ?? 0));
            $devices = array_intersect_key($devices, array_flip(array_slice($ids, 0, $this->maxNodes())));
            $truncated = true;
        }

        $ids = array_keys($devices);
        $builder = new L3Builder(
            $devices,
            array_values(array_filter($addresses, fn ($a) => isset($devices[(int) $a['device_id']]))),
            $p['ospf'] ? $this->repo->ospfNeighbors($ids) : [],
            $p['bgp'] ? $this->repo->bgpPeers($ids) : [],
        );

        $result = $builder->build([
            'hideStubs' => ! $p['stubs'],
            'p2pPrefix4' => $this->settings->int('l3_p2p_prefix', 8, 32),
            'p2pPrefix6' => 126,
            'exclude' => $this->settings->list('l3_exclude'),
            'vrf' => $p['vrf'],
            'ospf' => $p['ospf'],
            'bgp' => $p['bgp'],
            'root' => $root,
            'depth' => $root ? $p['depth'] : null,
        ]);

        $shown = count(array_filter($result['nodes'], fn ($n) => $n['type'] === 'device'));
        if ($root && $shown > $this->maxNodes()) {
            $truncated = true;
        }
        $result['scope'] = $this->scopeInfo($p, $root, $total, $shown, $truncated);

        return $result;
    }

    /**
     * @param  array{device: ?int, group: ?int, location: ?int, vlan: ?int}  $p
     */
    public function stp(array $p): array
    {
        $scope = $this->repo->deviceIds($p['group'], $p['location']);
        $vlan = $p['vlan'];
        $notice = null;
        if ($vlan === null && $this->repo->stpPortCount($scope) > self::STP_ALL_VLAN_LIMIT) {
            $vlan = 1;
            $notice = 'Large spanning tree data set: showing VLAN 1 / CST only, select a VLAN to analyze another instance.';
        }

        $instances = $this->repo->stpInstances($scope);
        $stpDevices = array_values(array_unique(array_map(fn ($i) => (int) $i['device_id'], $instances)));
        $lag = $this->repo->lagParents($stpDevices);
        $index = $vlan === null
            ? new StpIndex($instances, $this->repo->stpPorts($stpDevices), $lag)
            : $this->singleInstanceIndex($instances, $vlan, $lag);

        $links = $this->repo->links($scope);
        $portIds = $index->portIds();
        foreach ($links as $link) {
            $portIds[] = (int) $link['local_port_id'];
            $portIds[] = (int) $link['remote_port_id'];
        }
        $portIds = array_merge($portIds, array_keys($lag), array_values($lag));

        $devices = $this->repo->devices($scope);
        $analyzer = new StpAnalyzer(
            $devices,
            $index,
            $links,
            $this->repo->analysisPorts($portIds),
            $lag,
            $this->settings,
            $this->repo->stpEvents($scope, $this->settings->int('stp_event_days', 1, 365)),
        );

        $result = $analyzer->analyze($this->repo->rootLookups($index->unknownRootMacs()), $vlan);
        $result['vlans'] = $this->repo->stpVlans($scope);
        $result['notice'] = $notice;
        $result['filter'] = $p + ['effective_vlan' => $vlan];
        $result = LoopEvidence::merge($result, $this->loopEvidence($devices, $links));

        if ($p['device']) {
            $result = self::filterStpForDevice($result, $p['device']);
        }

        return $result;
    }

    /**
     * Loop evidence of the devices in scope: broadcast / multicast counters of their inter-switch
     * ports and the loop related syslog messages of their switches and routers (when the user may
     * read syslog).
     */
    private function loopEvidence(array $devices, array $links): array
    {
        $repo = new LoopEvidenceRepository($this->repo->user());
        $ports = $repo->portRates(array_keys(LoopEvidence::interSwitchPorts($devices, $links)));

        $senders = array_keys(array_filter($devices, fn ($d) => in_array($d['role'] ?? '', [DeviceRole::SWITCH, DeviceRole::L3SWITCH, DeviceRole::ROUTER], true)));
        $since = time() - $this->settings->int('stp_syslog_hours', 1, 720) * 3600;
        $pattern = LoopEvidence::databasePattern($this->settings);
        $hidden = ! $repo->canViewSyslog();
        $syslog = ['rows' => [], 'truncated' => false];
        $sent = 0;
        if (! $hidden && $pattern !== null && $senders) {
            $syslog = $repo->syslog($senders, $since, $pattern);
            $sent = $repo->syslogDevices($senders, $since);
        }

        return (new LoopEvidence($devices, $links, $ports, $syslog['rows'],
            $repo->portNames(array_column($syslog['rows'], 'device_id')), $this->settings))->analyze([
                'syslog_hidden' => $hidden,
                'syslog_truncated' => $syslog['truncated'],
                'syslog_devices' => $sent,
                'syslog_scope' => count($senders),
            ]);
    }

    /**
     * Link and VLAN diagnostics of the devices in scope. Links to monitored neighbours outside the
     * group/location filter are compared too; the VLAN topology is analyzed inside the scope.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     */
    public function links(array $p): array
    {
        [$scope, $device] = $this->repo->scope($p);
        $links = $this->repo->links($scope);

        $remote = [];
        $linkPorts = [];
        $interSwitch = [];
        foreach ($links as $link) {
            $linkPorts[] = (int) $link['local_port_id'];
            if ($link['remote_device_id']) {
                $remote[] = (int) $link['remote_device_id'];
                $linkPorts[] = (int) $link['remote_port_id'];
                $interSwitch[] = (int) $link['local_port_id'];
                $interSwitch[] = (int) $link['remote_port_id'];
            }
        }
        $linkPorts = array_values(array_unique(array_filter($linkPorts)));
        $deviceIds = array_values(array_unique(array_merge($scope, $remote)));

        // every aggregate (tells which devices report port-channels at all) and the members of linked ones
        $lag = $this->repo->lagParents($deviceIds);
        $linkedAggregates = [];
        foreach ($linkPorts as $portId) {
            if (isset($lag[$portId])) {
                $linkedAggregates[$lag[$portId]] = true;
            }
        }
        $members = array_keys(array_filter($lag, fn ($parent) => isset($linkedAggregates[$parent])));

        // ports between monitored devices, with their aggregates and all members, carry no "VLAN in use"
        $interSwitchAggregates = [];
        foreach ($interSwitch as $portId) {
            if (isset($lag[$portId])) {
                $interSwitchAggregates[$lag[$portId]] = true;
            }
        }
        $interSwitch = array_merge($interSwitch, array_keys($interSwitchAggregates),
            array_keys(array_filter($lag, fn ($parent) => isset($interSwitchAggregates[$parent]))));

        $ports = $this->repo->ports(array_merge($linkPorts, array_values(array_unique($lag)), $members));
        $withIp = $this->repo->portsWithIp(array_keys($ports));
        foreach ($ports as $portId => &$port) {
            $port['has_ip'] = isset($withIp[$portId]);
        }
        unset($port);

        $notice = null;
        $vlanPorts = array_values(array_unique(array_merge($linkPorts, array_keys($linkedAggregates), $members)));
        if ($this->repo->portVlanCount($vlanPorts) > self::LINK_VLAN_LIMIT) {
            $portVlans = [];
            $notice = 'Very large VLAN data set: the VLAN checks were skipped.';
        } else {
            $portVlans = $this->repo->portVlans($vlanPorts);
        }

        $physicalPorts = array_values(array_unique(array_merge($linkPorts, $members)));
        $analyzer = new LinkAnalyzer(
            $this->repo->devices($deviceIds, roles: false),
            $scope,
            $links,
            $ports,
            $portVlans,
            $lag,
            $this->settings,
            $portVlans ? $this->repo->activeVlans($scope, $interSwitch) : [],
            $this->repo->portStatistics($physicalPorts),
            $this->repo->opticalRx($physicalPorts),
        );

        $result = $analyzer->analyze();
        if ($device) {
            $result = self::filterLinksForDevice($result, $device);
        }
        $result['notice'] = $notice;
        $result['filter'] = $p;

        return $result;
    }

    /**
     * Redundancy and failure impact. Paths are followed through the whole network the user may
     * see; the findings are limited to the group / location filter.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     */
    public function redundancy(array $p): array
    {
        [$scope, $device] = $this->repo->scope($p);
        $result = $this->redundancyAnalyzer($scope, true)->analyze($device);
        $result['filter'] = $p;

        return $result;
    }

    /**
     * Failure simulation of the L2 map: what the given devices and links cut off the core, on the
     * whole network the user may see and as it is now.
     *
     * @param  string[]  $refs  see RedundancyAnalyzer::simulate()
     */
    public function simulate(array $refs): array
    {
        return $this->redundancyAnalyzer($this->repo->deviceIds(), false)->simulate($refs);
    }

    /**
     * Redundancy analysis of every device the user may see, reporting on $scope.
     *
     * @param  int[]  $scope
     * @param  bool  $details  also read stack members / line cards and outage start times
     */
    private function redundancyAnalyzer(array $scope, bool $details): RedundancyAnalyzer
    {
        $all = $this->repo->deviceIds();
        $links = $this->repo->links($all);

        $linkPorts = [];
        $perDevice = [];
        foreach ($links as $link) {
            $linkPorts[] = (int) $link['local_port_id'];
            $linkPorts[] = (int) $link['remote_port_id'];
            $perDevice[(int) $link['local_device_id']][(int) $link['local_port_id']] = true;
        }
        $linkPorts = array_values(array_unique(array_filter($linkPorts)));
        $lag = $this->repo->lagParents($all);
        $aggregates = array_values(array_unique(array_intersect_key($lag, array_flip($linkPorts))));
        $ports = $this->repo->ports(array_merge($linkPorts, $aggregates), self::REDUNDANCY_PORT_COLUMNS);

        $devices = $this->repo->devices($all, roles: false);
        $entityUnits = [];
        $outageStarts = [];
        if ($details) {
            // stack member / line card of the link ports of devices with several links
            $several = array_filter($perDevice, fn ($ports) => count($ports) >= 2);
            foreach ($this->repo->portEntities(array_filter($ports, fn ($port) => isset($several[(int) $port['device_id']]))) as $deviceId => $rows) {
                $entityUnits[$deviceId] = PortUnits::fromEntities($rows);
            }
            $outageStarts = $this->repo->outageStarts(array_keys(array_filter($devices, fn ($d) => empty($d['status']))));
        }

        return new RedundancyAnalyzer($devices, $scope, $links, $ports, $lag, $this->settings, $entityUnits, $outageStarts, $this->repo->stpPriorities($all));
    }

    /**
     * Narrow a link analysis to one device (link page opened from a device page).
     */
    public static function filterLinksForDevice(array $result, int $deviceId): array
    {
        $result['findings'] = array_values(array_filter($result['findings'], fn ($f) => in_array($deviceId, $f['devices'], true)));
        $result['links'] = array_values(array_filter($result['links'], fn ($l) => $l['a']['device_id'] === $deviceId || $l['b']['device_id'] === $deviceId));
        $result['summary'] = LinkAnalyzer::summarize($result['links'], $result['findings']);

        return $result;
    }

    /**
     * Data for the device overview panel. Runs on every device page view, so it only reads the
     * device's own rows plus a cheap lookup of who owns the root bridges it follows.
     */
    public function deviceSummary(int $deviceId): array
    {
        $links = $this->repo->links([$deviceId]);
        $instances = $this->repo->stpInstances([$deviceId]);

        $neighbors = [];
        foreach ($links as $link) {
            $key = $link['remote_device_id'] ? 'd' . $link['remote_device_id'] : 'u' . strtolower((string) $link['remote_hostname']);
            $neighbors[$key] = true;
        }

        $summary = [
            'neighbors' => count($neighbors),
            'links' => count(array_unique(array_filter(array_map(fn ($l) => (int) $l['local_port_id'], $links)))),
            'stp' => null,
        ];

        if ($instances) {
            $lag = $this->repo->lagParents([$deviceId]);
            $index = new StpIndex($instances, $this->repo->stpPorts([$deviceId]), $lag);
            $remoteIds = array_values(array_unique(array_filter(array_map(fn ($l) => (int) $l['remote_device_id'], $links))));

            $rootMacs = array_values(array_unique(array_filter(array_map(fn ($i) => StpIndex::mac($i['designatedRoot'] ?? ''), $instances))));
            $analyzer = new StpAnalyzer(
                $this->repo->devices(array_merge([$deviceId], $remoteIds)),
                $index,
                $links,
                $this->repo->ports(array_merge($index->portIds(), array_map(fn ($l) => (int) $l['local_port_id'], $links))),
                $lag,
                $this->settings,
            );
            $summary['stp'] = $analyzer->deviceSummary($deviceId, $this->repo->rootLookups($rootMacs, deep: false));
        }

        return $summary;
    }

    /**
     * Narrow a full STP result to one device (STP page opened from a device page).
     */
    public static function filterStpForDevice(array $result, int $deviceId): array
    {
        $result['findings'] = array_values(array_filter($result['findings'], fn ($f) => in_array($deviceId, $f['devices'], true)));
        $result['bridges'] = array_values(array_filter($result['bridges'], fn ($b) => $b['device_id'] === $deviceId));
        $result['blocked'] = array_values(array_filter($result['blocked'], fn ($b) => $b['device_id'] === $deviceId));
        $result['domains'] = array_values(array_filter($result['domains'], fn ($d) => in_array($deviceId, $d['member_ids'], true)));
        $result['events'] = array_values(array_filter($result['events'], fn ($e) => $e['device_id'] === $deviceId));
        $result['loop_evidence'] = LoopEvidence::forDevice($result['loop_evidence'] ?? [], $deviceId);
        $result['summary'] = StpAnalyzer::summarize($result['domains'], $result['bridges'], $result['blocked'], $result['findings'], $result['summary']['recent_tc_window']);

        return $result;
    }

    /**
     * STP index holding, for every bridge, the port rows of the one instance that carries $vlan
     * (null: the default instance). Instance rows are complete so PVST bridges are recognized.
     *
     * @param  array<int, int>  $lag
     */
    private function singleInstanceIndex(array $instances, ?int $vlan, array $lag): StpIndex
    {
        $selector = new StpIndex($instances);
        $keys = [];
        foreach ($selector->deviceIds() as $deviceId) {
            $keys[$deviceId] = $selector->keyFor($deviceId, $vlan);
        }

        return new StpIndex($instances, $this->repo->stpPorts(array_keys($keys), $keys), $lag);
    }

    /**
     * @param  int[]  $ids
     * @param  array<int, int>  $degree
     * @return array{0: int[], 1: int, 2: bool}
     */
    private function limit(array $ids, ?int $root, array $degree): array
    {
        $total = count($ids);
        $max = $this->maxNodes();
        if ($total <= $max) {
            return [$ids, $total, false];
        }

        if (! $root) {
            usort($ids, fn ($a, $b) => ($degree[$b] ?? 0) <=> ($degree[$a] ?? 0));
        }

        return [array_slice($ids, 0, $max), $total, true];
    }

    /**
     * Maximum devices drawn on one map (setting, 10-5000).
     */
    private function maxNodes(): int
    {
        return $this->settings->int('max_nodes', 10, 5000);
    }

    /**
     * Description of what the map shows, for the status line (centered device, filters, truncation).
     */
    private function scopeInfo(array $p, ?int $root, int $total, int $shown, bool $truncated): array
    {
        return [
            'root' => $root,
            'root_name' => $root ? $this->repo->deviceName($root) : null,
            'depth' => $p['depth'] ?? null,
            'group' => $p['group'] ?? null,
            'location' => $p['location'] ?? null,
            'total' => $total,
            'shown' => $shown,
            'truncated' => $truncated,
            'max_nodes' => $this->maxNodes(),
        ];
    }
}
