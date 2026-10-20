<?php

namespace Agoodley\LibrenmsTopology\Topology;

use Agoodley\LibrenmsTopology\Data\TopologyRepository;
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

    private const L3_ROLES = [DeviceRole::ROUTER, DeviceRole::L3SWITCH, DeviceRole::FIREWALL];

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
        [$scope, $root] = $this->scope($p);
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

        [$instances, $stpPorts] = $this->repo->stpRows($ids, $p['vlan']);
        $stp = new StpIndex($instances, $stpPorts, $lag);
        $ports = $this->repo->ports(array_merge($linkPorts, array_column($stpPorts, 'port_id')));

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
        [$scope, $root] = $this->scope($p);
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

        [$instances, $stpPorts] = $this->repo->stpRows($scope, $vlan);
        $links = $this->repo->links($scope);
        $lag = $this->repo->lagParents(array_values(array_unique(array_map(fn ($i) => (int) $i['device_id'], $instances))));

        $portIds = array_column($stpPorts, 'port_id');
        foreach ($links as $link) {
            $portIds[] = (int) $link['local_port_id'];
            $portIds[] = (int) $link['remote_port_id'];
        }
        $portIds = array_merge($portIds, array_keys($lag), array_values($lag));
        $ports = $this->repo->analysisPorts($portIds);

        $analyzer = new StpAnalyzer(
            $this->repo->devices($scope),
            new StpIndex($instances, $stpPorts, $lag),
            $links,
            $ports,
            $lag,
            $this->settings,
            $this->repo->stpEvents($scope, $this->settings->int('stp_event_days', 1, 365)),
        );

        $result = $analyzer->analyze($this->repo->rootLookups($analyzer->unknownRootMacs()), $vlan);
        $result['vlans'] = $this->repo->stpVlans($scope);
        $result['notice'] = $notice;
        $result['filter'] = $p + ['effective_vlan' => $vlan];

        if ($p['device']) {
            $result = self::filterStpForDevice($result, $p['device']);
        }

        return $result;
    }

    /**
     * Data for the device overview panel (kept cheap: only the device itself).
     */
    public function deviceSummary(int $deviceId): array
    {
        $links = $this->repo->links([$deviceId]);
        [$instances, $stpPorts] = $this->repo->stpRows([$deviceId]);

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
            $portIds = array_merge(array_column($stpPorts, 'port_id'), array_map(fn ($l) => (int) $l['local_port_id'], $links));
            $remoteIds = array_values(array_unique(array_filter(array_map(fn ($l) => (int) $l['remote_device_id'], $links))));

            // which monitored devices own the root bridges this device follows?
            $rootMacs = array_values(array_unique(array_filter(array_map(fn ($i) => StpIndex::mac($i['designatedRoot'] ?? ''), $instances))));
            $lookups = $this->repo->rootLookups($rootMacs);
            $ownerIds = [];
            foreach ($lookups as $lookup) {
                foreach ($lookup['owners'] as $owner) {
                    $ownerIds[] = (int) $owner['device_id'];
                }
            }

            $analyzer = new StpAnalyzer(
                $this->repo->devices(array_values(array_unique(array_merge([$deviceId], $remoteIds, $ownerIds)))),
                new StpIndex($instances, $stpPorts, $lag),
                $links,
                $this->repo->ports($portIds),
                $lag,
                $this->settings,
            );
            $summary['stp'] = $analyzer->deviceSummary($deviceId, $lookups);
        }

        return $summary;
    }

    public static function filterStpForDevice(array $result, int $deviceId): array
    {
        $result['findings'] = array_values(array_filter($result['findings'], fn ($f) => in_array($deviceId, $f['devices'], true)));
        $result['bridges'] = array_values(array_filter($result['bridges'], fn ($b) => $b['device_id'] === $deviceId));
        $result['blocked'] = array_values(array_filter($result['blocked'], fn ($b) => $b['device_id'] === $deviceId));
        $result['domains'] = array_values(array_filter($result['domains'], fn ($d) => in_array($deviceId, $d['member_ids'], true)));
        $result['events'] = array_values(array_filter($result['events'], fn ($e) => $e['device_id'] === $deviceId));

        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($result['findings'] as $finding) {
            $counts[$finding['severity']]++;
        }
        $result['summary']['findings'] = $counts;
        $result['summary']['status'] = $counts['critical'] ? 'critical' : ($counts['warning'] ? 'warning' : 'ok');
        $result['summary']['instances'] = count($result['bridges']);
        $result['summary']['blocked_ports'] = count($result['blocked']);

        return $result;
    }

    /**
     * Device scope of a map; a root device outside the group/location filter widens the scope.
     *
     * @return array{0: int[], 1: int|null}
     */
    private function scope(array $p): array
    {
        $scope = $this->repo->deviceIds($p['group'], $p['location']);
        $root = $p['device'] ?: null;
        if ($root && ! in_array($root, $scope, true)) {
            $all = $this->repo->deviceIds();
            if (in_array($root, $all, true)) {
                $scope = $all;
            } else {
                $root = null; // not visible to this user
            }
        }

        return [$scope, $root];
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

    private function maxNodes(): int
    {
        return $this->settings->int('max_nodes', 10, 5000);
    }

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
