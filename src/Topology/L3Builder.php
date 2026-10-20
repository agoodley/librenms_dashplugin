<?php

namespace Agoodley\LibrenmsTopology\Topology;

use Agoodley\LibrenmsTopology\Support\Cidr;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\Graph;
use Agoodley\LibrenmsTopology\Support\InterfaceName;

/**
 * Builds the Layer 3 map: L3 devices joined through shared IP subnets. Point-to-point subnets
 * become direct links, multi-access subnets become "media" nodes (NetBrain style), and routing
 * adjacencies (OSPF / BGP) can be overlaid.
 */
class L3Builder
{
    private const ROLE_RANK = [
        DeviceRole::FIREWALL => 5,
        DeviceRole::ROUTER => 4,
        DeviceRole::L3SWITCH => 3,
    ];

    /** @var array<string, array{network: string, vrf: string, family: int, prefix: int, members: array}> */
    private array $subnets = [];

    /** @var array<int, string[]> device_id => loopback addresses */
    private array $loopbacks = [];

    /** @var array<string, int> "vrf|ip" and "ip" => device_id */
    private array $ipIndex = [];

    /**
     * @param  array<int, array>  $devices  device_id => device row (with 'role', 'url', 'bgpLocalAs')
     * @param  array<int, array>  $addresses  rows: device_id, port_id, ifName, ifDescr, ifAlias, ifOperStatus, ifType, address, prefixlen, vrf
     * @param  array<int, array>  $ospf  rows of ospf_nbrs
     * @param  array<int, array>  $bgp  rows of bgpPeers
     */
    public function __construct(
        private array $devices,
        private array $addresses,
        private array $ospf = [],
        private array $bgp = [],
    ) {
    }

    /**
     * Build the map data returned by api/l3: nodes (devices, subnets, external routers / AS
     * clouds), edges (p2p, member, ospf, bgp), VRFs and statistics.
     *
     * @param  array{hideStubs?: bool, p2pPrefix4?: int, p2pPrefix6?: int, exclude?: string[], vrf?: string|null,
     *     ospf?: bool, bgp?: bool, root?: int|null, depth?: int|null}  $options
     *     hideStubs: leave out subnets with a single device, p2pPrefix4/6: prefix length from which a
     *     two-device subnet is drawn as a direct link, root/depth: only devices within depth hops
     */
    public function build(array $options = []): array
    {
        $hideStubs = $options['hideStubs'] ?? true;
        $p2p4 = (int) ($options['p2pPrefix4'] ?? 29);
        $p2p6 = (int) ($options['p2pPrefix6'] ?? 126);
        $root = $options['root'] ?? null;

        $this->index($options['exclude'] ?? [], $options['vrf'] ?? null);

        // devices joined through subnet nodes (device - subnet - device = one device hop), so every
        // member of a shared LAN is one hop from every other member
        $graph = new Graph;
        foreach ($this->subnets as $key => $subnet) {
            foreach (array_unique(array_column($subnet['members'], 'device_id')) as $deviceId) {
                $graph->addEdge((int) $deviceId, 's:' . $key);
            }
        }

        $isDevice = fn ($node) => is_int($node);
        $kept = array_flip(array_filter($graph->nodes(), $isDevice));
        if ($root && isset($this->devices[$root])) {
            $graph->addNode($root);
            $depth = $options['depth'] ?? null;
            $kept = array_filter($graph->distances($root, $depth === null ? null : $depth * 2), $isDevice, ARRAY_FILTER_USE_KEY);
        }

        $nodes = [];
        $edges = [];
        $stubs = [];

        foreach ($this->subnets as $key => $subnet) {
            $allDevices = array_unique(array_column($subnet['members'], 'device_id'));
            $members = array_values(array_filter($subnet['members'], fn ($m) => isset($kept[$m['device_id']])));
            if (! $members) {
                continue;
            }

            $memberDevices = array_values(array_unique(array_column($members, 'device_id')));
            $p2pLimit = $subnet['family'] === 6 ? $p2p6 : $p2p4;

            if (count($allDevices) === 1) {
                $stubs[$memberDevices[0]][] = $subnet['network'] . ($subnet['vrf'] !== '' ? ' (' . $subnet['vrf'] . ')' : '');
                if ($hideStubs) {
                    continue;
                }
            }

            if (count($allDevices) === 2 && count($memberDevices) === 2 && count($members) === 2 && $subnet['prefix'] >= $p2pLimit) {
                [$a, $b] = $members;
                if ($a['device_id'] > $b['device_id']) {
                    [$a, $b] = [$b, $a];
                }
                $edges[] = [
                    'id' => 'e' . count($edges),
                    'kind' => 'p2p',
                    'from' => 'd:' . $a['device_id'],
                    'to' => 'd:' . $b['device_id'],
                    'network' => $subnet['network'],
                    'vrf' => $subnet['vrf'],
                    'a' => $this->endpoint($a),
                    'b' => $this->endpoint($b),
                    'status' => ($a['ifOperStatus'] ?? 'up') === 'up' && ($b['ifOperStatus'] ?? 'up') === 'up' ? 'up' : 'down',
                ];
                continue;
            }

            $subnetId = 's:' . substr(md5($key), 0, 12);
            $nodes[$subnetId] = [
                'id' => $subnetId,
                'type' => 'subnet',
                'managed' => false,
                'label' => $subnet['network'] . ($subnet['vrf'] !== '' ? "\n" . $subnet['vrf'] : ''),
                'name' => $subnet['network'],
                'network' => $subnet['network'],
                'vrf' => $subnet['vrf'],
                'family' => $subnet['family'],
                'role' => 'subnet',
                'status' => 'up',
                'devices' => count($allDevices),
                'stub' => count($allDevices) === 1,
                'hosts' => array_values(array_unique(array_column($subnet['members'], 'address'))),
            ];

            // one edge per device interface in the subnet (secondary IPs merged)
            $perPort = [];
            foreach ($members as $member) {
                $portKey = $member['device_id'] . ':' . $member['port_id'];
                if (isset($perPort[$portKey])) {
                    $edges[$perPort[$portKey]]['a']['ips'][] = $member['address'];
                    continue;
                }
                $perPort[$portKey] = count($edges);
                $edges[] = [
                    'id' => 'e' . count($edges),
                    'kind' => 'member',
                    'from' => 'd:' . $member['device_id'],
                    'to' => $subnetId,
                    'network' => $subnet['network'],
                    'vrf' => $subnet['vrf'],
                    'a' => $this->endpoint($member),
                    'b' => null,
                    'status' => ($member['ifOperStatus'] ?? 'up') === 'up' ? 'up' : 'down',
                ];
            }
        }

        foreach (array_keys($kept) as $deviceId) {
            if (! isset($this->devices[$deviceId])) {
                continue;
            }
            $nodes['d:' . $deviceId] = $this->deviceNode((int) $deviceId, $stubs[$deviceId] ?? []);
        }

        if ($options['ospf'] ?? true) {
            $this->addOspf($nodes, $edges, $kept);
        }
        if ($options['bgp'] ?? true) {
            $this->addBgp($nodes, $edges, $kept);
        }

        // drop edges whose endpoints were not kept (e.g. scoped out)
        $edges = array_values(array_filter($edges, fn ($e) => isset($nodes[$e['from']], $nodes[$e['to']])));
        $this->assignParallelIndexes($edges);
        $this->assignLevels($nodes, $edges, $root);

        $vrfs = array_values(array_unique(array_filter(array_column($this->addresses, 'vrf'))));
        sort($vrfs);

        return [
            'nodes' => array_values($nodes),
            'edges' => $edges,
            'vrfs' => $vrfs,
            'stats' => [
                'devices' => count(array_filter($nodes, fn ($n) => $n['type'] === 'device')),
                'subnets' => count(array_filter($nodes, fn ($n) => $n['type'] === 'subnet')),
                'p2p' => count(array_filter($edges, fn ($e) => $e['kind'] === 'p2p')),
                'ospf' => count(array_filter($edges, fn ($e) => $e['kind'] === 'ospf')),
                'bgp' => count(array_filter($edges, fn ($e) => $e['kind'] === 'bgp')),
                'down' => count(array_filter($edges, fn ($e) => $e['status'] === 'down')),
            ],
        ];
    }

    /**
     * Sort the address rows into subnets (per VRF), loopbacks / host routes and an IP => device
     * index used to resolve routing neighbours. Link-local, loopback, multicast and excluded
     * networks are skipped.
     *
     * @param  string[]  $exclude  CIDRs never drawn
     * @param  string|null  $vrfFilter  only keep subnets of this VRF (null or '' keeps every VRF)
     */
    private function index(array $exclude, ?string $vrfFilter): void
    {
        $this->subnets = [];
        $this->loopbacks = [];
        $this->ipIndex = [];

        foreach ($this->addresses as $row) {
            $address = trim((string) ($row['address'] ?? ''));
            $prefix = (int) ($row['prefixlen'] ?? 0);
            $deviceId = (int) $row['device_id'];
            if ($address === '' || ! isset($this->devices[$deviceId]) || Cidr::isIgnorable($address)) {
                continue;
            }

            $family = Cidr::family($address);
            $vrf = (string) ($row['vrf'] ?? '');
            $this->ipIndex[$vrf . '|' . $address] = $deviceId;
            $this->ipIndex[$address] ??= $deviceId;

            if ($vrfFilter !== null && $vrfFilter !== '' && $vrf !== $vrfFilter) {
                continue;
            }

            $hostRoute = ($family === 4 && $prefix >= 32) || ($family === 6 && $prefix >= 128);
            if ($hostRoute || strtolower((string) ($row['ifType'] ?? '')) === 'softwareloopback') {
                $this->loopbacks[$deviceId][] = $address;
                continue;
            }

            $network = Cidr::network($address, $prefix);
            if ($network === null || ($exclude && Cidr::containedInAny($exclude, $network))) {
                continue;
            }

            $key = $vrf . '|' . $network;
            $this->subnets[$key] ??= [
                'network' => $network,
                'vrf' => $vrf,
                'family' => $family,
                'prefix' => $prefix,
                'members' => [],
            ];
            $row['address'] = $address;
            $row['device_id'] = $deviceId;
            $this->subnets[$key]['members'][] = $row;
        }

        ksort($this->subnets);
    }

    /**
     * Device end of a subnet link: interface name, address(es) and status.
     */
    private function endpoint(array $member): array
    {
        $name = InterfaceName::label($member);

        return [
            'device_id' => (int) $member['device_id'],
            'port_id' => isset($member['port_id']) ? (int) $member['port_id'] : null,
            'name' => $name,
            'short' => InterfaceName::short($name),
            'alias' => (string) ($member['ifAlias'] ?? ''),
            'ips' => [$member['address']],
            'prefix' => (int) $member['prefixlen'],
            'oper' => $member['ifOperStatus'] ?? null,
            'url' => $member['url'] ?? null,
        ];
    }

    /**
     * Map node for a monitored L3 device with its loopbacks, stub networks and BGP AS.
     *
     * @param  string[]  $stubs  networks only this device is attached to
     */
    private function deviceNode(int $deviceId, array $stubs): array
    {
        $device = $this->devices[$deviceId];
        $display = (string) ($device['display'] ?? '') ?: (string) ($device['hostname'] ?? $deviceId);

        return [
            'id' => 'd:' . $deviceId,
            'type' => 'device',
            'managed' => true,
            'device_id' => $deviceId,
            'label' => L2Builder::shortHost($display),
            'name' => $display,
            'hostname' => $device['hostname'] ?? '',
            'role' => $device['role'] ?? DeviceRole::ROUTER,
            'status' => L2Builder::deviceStatus($device),
            'hardware' => $device['hardware'] ?? '',
            'os' => $device['os'] ?? '',
            'version' => $device['version'] ?? '',
            'location' => $device['location'] ?? '',
            'url' => $device['url'] ?? null,
            'loopbacks' => array_values(array_unique($this->loopbacks[$deviceId] ?? [])),
            'stubs' => $stubs,
            'bgpLocalAs' => (int) ($device['bgpLocalAs'] ?? 0) ?: null,
        ];
    }

    /**
     * Device owning an IP address, looked up in the same VRF first.
     */
    private function lookupIp(string $ip, string $vrf = ''): ?int
    {
        return $this->ipIndex[$vrf . '|' . $ip] ?? $this->ipIndex[$ip] ?? null;
    }

    /**
     * OSPF adjacencies as overlay edges (both directions merged; the edge is down unless every side
     * reports full/2-way). Neighbours that are not monitored become external router nodes.
     */
    private function addOspf(array &$nodes, array &$edges, array $kept): void
    {
        $pairs = [];
        foreach ($this->ospf as $row) {
            $local = (int) $row['device_id'];
            if (! isset($kept[$local], $nodes['d:' . $local])) {
                continue;
            }

            $ip = (string) ($row['ospfNbrIpAddr'] ?? '');
            $remote = $this->lookupIp($ip, (string) ($row['context_name'] ?? ''))
                ?? $this->lookupIp((string) ($row['ospfNbrRtrId'] ?? ''));
            if ($remote === $local) {
                continue;
            }

            if ($remote && ! isset($nodes['d:' . $remote])) {
                continue; // known device outside the map scope
            }

            if ($remote) {
                $to = 'd:' . $remote;
            } else {
                $rid = (string) ($row['ospfNbrRtrId'] ?? $ip);
                $to = 'x:' . $rid;
                $nodes[$to] ??= [
                    'id' => $to,
                    'type' => 'external',
                    'managed' => false,
                    'label' => 'OSPF ' . $rid,
                    'name' => 'OSPF neighbor ' . $rid,
                    'role' => DeviceRole::ROUTER,
                    'status' => 'unknown',
                    'address' => $ip,
                ];
            }

            $from = 'd:' . $local;
            $key = strcmp($from, $to) < 0 ? "$from|$to" : "$to|$from";
            $state = strtolower((string) ($row['ospfNbrState'] ?? 'unknown'));
            if (isset($pairs[$key])) {
                $edge = &$edges[$pairs[$key]];
                if ($state !== 'full' && $edge['state'] === 'full') {
                    $edge['state'] = $state;
                }
                $edge['addresses'][] = $ip;
                unset($edge);
                continue;
            }

            $pairs[$key] = count($edges);
            $edges[] = [
                'id' => 'e' . count($edges),
                'kind' => 'ospf',
                'from' => $from,
                'to' => $to,
                'state' => $state,
                'status' => in_array($state, ['full', 'twoway'], true) ? 'up' : 'down',
                'addresses' => [$ip],
                'a' => null,
                'b' => null,
            ];
        }
    }

    /**
     * BGP sessions as overlay edges, iBGP when both sides share the AS. Peers that are not
     * monitored are grouped into one cloud per remote AS. A pair is down if any session is not established.
     */
    private function addBgp(array &$nodes, array &$edges, array $kept): void
    {
        $pairs = [];
        foreach ($this->bgp as $row) {
            $local = (int) $row['device_id'];
            if (! isset($kept[$local], $nodes['d:' . $local])) {
                continue;
            }

            $peerIp = (string) ($row['bgpPeerIdentifier'] ?? '');
            $remoteAs = (int) ($row['bgpPeerRemoteAs'] ?? 0);
            $localAs = (int) ($this->devices[$local]['bgpLocalAs'] ?? 0);
            $type = $remoteAs && $remoteAs === $localAs ? 'iBGP' : 'eBGP';
            $remote = $this->lookupIp($peerIp, (string) ($row['context_name'] ?? ''));
            if ($remote === $local) {
                continue;
            }

            if ($remote && ! isset($nodes['d:' . $remote])) {
                continue; // known device outside the map scope
            }

            if ($remote) {
                $to = 'd:' . $remote;
            } else {
                $to = 'as:' . ($remoteAs ?: $peerIp);
                $nodes[$to] ??= [
                    'id' => $to,
                    'type' => 'as',
                    'managed' => false,
                    'label' => $remoteAs ? 'AS' . $remoteAs : $peerIp,
                    'name' => $remoteAs ? 'Autonomous System ' . $remoteAs : 'BGP peer ' . $peerIp,
                    'role' => 'cloud',
                    'status' => 'unknown',
                    'peers' => [],
                ];
                $nodes[$to]['peers'][] = $peerIp;
            }

            $from = 'd:' . $local;
            $key = (strcmp($from, $to) < 0 ? "$from|$to" : "$to|$from") . '|' . $type;
            $state = strtolower((string) ($row['bgpPeerState'] ?? 'unknown'));
            if (isset($pairs[$key])) {
                $edge = &$edges[$pairs[$key]];
                if ($state !== 'established') {
                    $edge['state'] = $state;
                    $edge['status'] = 'down';
                }
                $edge['peers'][] = $peerIp;
                unset($edge);
                continue;
            }

            $pairs[$key] = count($edges);
            $edges[] = [
                'id' => 'e' . count($edges),
                'kind' => 'bgp',
                'from' => $from,
                'to' => $to,
                'type' => $type,
                'remote_as' => $remoteAs ?: null,
                'state' => $state,
                'status' => $state === 'established' ? 'up' : 'down',
                'peers' => [$peerIp],
                'description' => (string) ($row['bgpPeerDescr'] ?? ''),
                'a' => null,
                'b' => null,
            ];
        }
    }

    /**
     * Parallel edges between two nodes (e.g. a link plus an OSPF adjacency) are drawn as separate curves.
     */
    private function assignParallelIndexes(array &$edges): void
    {
        $pairs = [];
        foreach ($edges as $index => $edge) {
            $key = strcmp($edge['from'], $edge['to']) < 0 ? $edge['from'] . '|' . $edge['to'] : $edge['to'] . '|' . $edge['from'];
            $pairs[$key][] = $index;
        }

        foreach ($pairs as $indexes) {
            foreach ($indexes as $position => $index) {
                $edges[$index]['parallel'] = ['index' => $position, 'count' => count($indexes)];
            }
        }
    }

    /**
     * Hierarchy levels for the top-down layout: distance through subnets from the centered device,
     * otherwise from the best placed node of each island (external AS clouds on top, then firewalls,
     * routers and L3 switches).
     */
    private function assignLevels(array &$nodes, array $edges, ?int $root): void
    {
        $graph = new Graph;
        foreach (array_keys($nodes) as $id) {
            $graph->addNode($id);
        }
        foreach ($edges as $edge) {
            if ($edge['kind'] === 'p2p' || $edge['kind'] === 'member') {
                $graph->addEdge($edge['from'], $edge['to'], $edge['id']);
            }
        }
        foreach ($edges as $edge) {
            // routing overlays only matter for nodes not reachable through subnets (AS clouds)
            if (! in_array($edge['kind'], ['p2p', 'member'], true) && ($graph->degree($edge['to']) === 0 || $graph->degree($edge['from']) === 0)) {
                $graph->addEdge($edge['from'], $edge['to'], $edge['id']);
            }
        }

        $levels = [];
        foreach ($graph->components() as $component) {
            $start = null;
            if ($root && in_array('d:' . $root, $component, true)) {
                $start = 'd:' . $root;
            } else {
                $best = -1;
                foreach ($component as $id) {
                    $node = $nodes[$id];
                    $score = ($node['type'] === 'as' ? 9 : ($node['type'] === 'device' ? (self::ROLE_RANK[$node['role']] ?? 1) : 0)) * 10000 + $graph->degree($id);
                    if ($score > $best) {
                        $best = $score;
                        $start = $id;
                    }
                }
            }

            foreach ($graph->distances($start) as $id => $distance) {
                $levels[$id] = $distance;
            }
        }

        foreach ($nodes as $id => &$node) {
            $node['level'] = $levels[$id] ?? 0;
            $node['degree'] = $graph->degree($id);
        }
    }
}
