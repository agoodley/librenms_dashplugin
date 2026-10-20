<?php

namespace Agoodley\LibrenmsTopology\Topology;

use Agoodley\LibrenmsTopology\Support\Cidr;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\Graph;
use Agoodley\LibrenmsTopology\Support\InterfaceName;

/**
 * Builds the Layer 3 map: L3 devices joined through shared IP subnets. Point-to-point subnets
 * become direct links, multi-access subnets become "media" nodes (NetBrain style), and routing
 * adjacencies (OSPF, OSPFv3, IS-IS, BGP), IPsec tunnels, MPLS LSPs and pseudowires can be overlaid.
 */
class L3Builder
{
    /** Overlay layers beyond OSPF and BGP: build options and edge kinds. */
    public const LAYERS = ['ospfv3', 'isis', 'ipsec', 'mpls', 'pw'];

    private const ROLE_RANK = [
        DeviceRole::FIREWALL => 5,
        DeviceRole::ROUTER => 4,
        DeviceRole::L3SWITCH => 3,
    ];

    /** OSPF / OSPFv3 neighbour states of a working adjacency (2-way between DROthers). */
    private const OSPF_UP = ['full', 'twoway'];

    /** IPsec tunnel states that mean up (Cisco reports "active", other vendors "established"...). */
    private const IPSEC_UP = ['active', 'up', 'established', 'connected'];

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
     * @param  array<string, array<int, array>>  $layers  rows per overlay layer (LAYERS): ospfv3_nbrs, isis_adjacencies,
     *                                                    ipsec_tunnels, mpls_lsps and pseudowires (with the local ifName)
     * @param  array{addresses?: array<string, int>, routerIds?: array<string, int>, ports?: array<int, int>, devices?: array<int, true>}  $known
     *     far ends among the other devices the user may see (L3LayerRepository::resolve() of layerLookups())
     */
    public function __construct(
        private array $devices,
        private array $addresses,
        private array $ospf = [],
        private array $bgp = [],
        private array $layers = [],
        private array $known = [],
    ) {
    }

    /**
     * Build the map data returned by api/l3: nodes (devices, subnets, external routers / AS
     * clouds / peers), edges (p2p, member, ospf, bgp and the LAYERS), VRFs and statistics.
     *
     * @param  array{hideStubs?: bool, p2pPrefix4?: int, p2pPrefix6?: int, exclude?: string[], vrf?: string|null,
     *     ospf?: bool, bgp?: bool, ospfv3?: bool, isis?: bool, ipsec?: bool, mpls?: bool, pw?: bool, root?: int|null, depth?: int|null}  $options
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
        if ($options['ospfv3'] ?? true) {
            $this->addOspfv3($nodes, $edges, $kept);
        }
        if ($options['isis'] ?? true) {
            $this->addIsis($nodes, $edges, $kept);
        }
        if ($options['ipsec'] ?? true) {
            $this->addIpsec($nodes, $edges, $kept);
        }
        if ($options['mpls'] ?? true) {
            $this->addLsps($nodes, $edges, $kept);
        }
        if ($options['pw'] ?? true) {
            $this->addPseudowires($nodes, $edges, $kept);
        }

        // drop edges whose endpoints were not kept (e.g. scoped out)
        $edges = array_values(array_filter($edges, fn ($e) => isset($nodes[$e['from']], $nodes[$e['to']])));
        $this->assignParallelIndexes($edges);
        $this->assignLevels($nodes, $edges, $root);

        $vrfs = array_values(array_unique(array_filter(array_column($this->addresses, 'vrf'))));
        sort($vrfs);

        $byKind = fn (string $kind) => array_filter($edges, fn ($e) => $e['kind'] === $kind);

        return [
            'nodes' => array_values($nodes),
            'edges' => $edges,
            'vrfs' => $vrfs,
            'stats' => [
                'devices' => count(array_filter($nodes, fn ($n) => $n['type'] === 'device')),
                'subnets' => count(array_filter($nodes, fn ($n) => $n['type'] === 'subnet')),
                'p2p' => count($byKind('p2p')),
                'ospf' => count($byKind('ospf')),
                'bgp' => count($byKind('bgp')),
                'ospfv3' => count($byKind('ospfv3')),
                'isis' => count($byKind('isis')),
                'ipsec' => count($byKind('ipsec')),
                'mpls' => array_sum(array_map(fn ($e) => count($e['lsps']), $byKind('mpls'))),
                'pw' => array_sum(array_map(fn ($e) => count($e['vcs']), $byKind('pw'))),
                'mtu' => array_sum(array_column($byKind('pw'), 'mtu_mismatch')),
                'down' => count(array_filter($edges, fn ($e) => $e['status'] === 'down')),
            ],
        ];
    }

    /**
     * What the overlay layers need resolved beyond this map's addresses (L3LayerRepository::resolve()):
     * far-end addresses (including loopbacks encoded in IS-IS system IDs), OSPFv3 router IDs, the ports
     * LibreNMS found for global OSPFv3 neighbour addresses and the pseudowire peer devices.
     *
     * @param  array<string, array<int, array>>  $layers  see the constructor
     * @return array{addresses: string[], routerIds: string[], ports: int[], devices: int[]}
     */
    public static function layerLookups(array $layers): array
    {
        $addresses = [];
        $routerIds = [];
        $ports = [];
        $devices = [];
        foreach ($layers['ospfv3'] ?? [] as $row) {
            $rid = self::ospfv3RouterId($row);
            if ($rid !== '') {
                $routerIds[] = $rid;
                $addresses[] = $rid; // the router ID is usually a loopback address
            }
            $address = (string) ($row['ospfv3NbrAddress'] ?? '');
            if ($address !== '' && ! Cidr::isIgnorable($address)) {
                $addresses[] = $address;
                $ports[] = (int) ($row['port_id'] ?? 0);
            }
        }
        foreach ($layers['isis'] ?? [] as $row) {
            $addresses[] = (string) ($row['isisISAdjIPAddrAddress'] ?? '');
            $addresses[] = (string) self::isisSystemIdAddress(self::isisSystemId((string) ($row['isisISAdjNeighSysID'] ?? '')));
        }
        foreach ($layers['ipsec'] ?? [] as $row) {
            $addresses[] = (string) ($row['peer_addr'] ?? '');
        }
        foreach ($layers['mpls'] ?? [] as $row) {
            $addresses[] = (string) ($row['mplsLspToAddr'] ?? '');
        }
        foreach ($layers['pw'] ?? [] as $row) {
            $addresses[] = self::ldpAddress($row['peer_ldp_id'] ?? null);
            $devices[] = (int) ($row['peer_device_id'] ?? 0);
        }

        return [
            'addresses' => array_values(array_unique(array_filter(array_map(fn ($a) => Cidr::canonical($a), $addresses)))),
            'routerIds' => array_values(array_unique($routerIds)),
            'ports' => array_values(array_unique(array_filter($ports))),
            'devices' => array_values(array_unique(array_filter($devices))),
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
                    $edge['status'] = in_array($state, self::OSPF_UP, true) ? 'up' : 'down';
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
     * OSPFv3 adjacencies, merged like OSPF. A neighbour's address is usually link-local (the same on
     * many routers), so it is found by router ID: of a monitored OSPF / OSPFv3 instance, else a loopback
     * with that address; a global neighbour address is tried last. Unknown neighbours share the external
     * router node of OSPF.
     */
    private function addOspfv3(array &$nodes, array &$edges, array $kept): void
    {
        $pairs = [];
        foreach ($this->layers['ospfv3'] ?? [] as $row) {
            $local = (int) $row['device_id'];
            if (! isset($kept[$local], $nodes['d:' . $local])) {
                continue;
            }

            $rid = self::ospfv3RouterId($row);
            $address = (string) ($row['ospfv3NbrAddress'] ?? '');
            $remote = $rid !== '' ? ($this->known['routerIds'][$rid] ?? $this->deviceByAddress($rid)) : null;
            if ($remote === null && $address !== '' && ! Cidr::isIgnorable($address)) {
                $remote = $this->known['ports'][(int) ($row['port_id'] ?? 0)] ?? $this->deviceByAddress($address, (string) ($row['context_name'] ?? ''));
            }
            $id = $rid !== '' ? $rid : $address;
            if ($id === '' || ($to = $this->farEnd($nodes, $local, $remote, 'x:' . $id, 'OSPF ' . $id, 'OSPF neighbor ' . $id, $address)) === null) {
                continue;
            }

            $state = strtolower((string) ($row['ospfv3NbrState'] ?? 'unknown'));
            $index = $this->overlayEdge($edges, $pairs, 'ospfv3', 'd:' . $local, $to, ['state' => $state, 'routerIds' => [], 'addresses' => []]);
            $edge = &$edges[$index];
            if (in_array($edge['state'], self::OSPF_UP, true) && ! in_array($state, self::OSPF_UP, true)) {
                $edge['state'] = $state;
            }
            $edge['status'] = in_array($edge['state'], self::OSPF_UP, true) ? 'up' : 'down';
            $edge['routerIds'][] = $rid;
            $edge['addresses'][] = $address;
            unset($edge);
        }
        $this->uniqueLists($edges, $pairs, ['routerIds', 'addresses']);
    }

    /**
     * IS-IS adjacencies, merged per pair of routers. The neighbour is found by its interface address,
     * else by a system ID that encodes a loopback address (0100.0000.0001 for 10.0.0.1, a common
     * convention). Unknown neighbours become external routers named by system ID.
     */
    private function addIsis(array &$nodes, array &$edges, array $kept): void
    {
        $pairs = [];
        foreach ($this->layers['isis'] ?? [] as $row) {
            $local = (int) $row['device_id'];
            if (! isset($kept[$local], $nodes['d:' . $local])) {
                continue;
            }

            $systemId = self::isisSystemId((string) ($row['isisISAdjNeighSysID'] ?? ''));
            $address = trim((string) ($row['isisISAdjIPAddrAddress'] ?? ''));
            $loopback = self::isisSystemIdAddress($systemId);
            $remote = $this->deviceByAddress($address) ?? ($loopback !== null ? $this->deviceByAddress($loopback) : null);
            $id = $systemId !== '' ? $systemId : $address;
            if ($id === '' || ($to = $this->farEnd($nodes, $local, $remote, 'xi:' . $id, 'IS-IS ' . $id, 'IS-IS neighbor ' . $id, $address)) === null) {
                continue;
            }

            $state = strtolower((string) ($row['isisISAdjState'] ?? 'unknown'));
            if (in_array(strtolower((string) ($row['isisCircAdminState'] ?? 'on')), ['off', 'down'], true)) {
                $state = 'disabled';
            }
            $index = $this->overlayEdge($edges, $pairs, 'isis', 'd:' . $local, $to,
                ['state' => $state, 'levels' => [], 'areas' => [], 'systemIds' => [], 'addresses' => [], 'interfaces' => []]);
            $edge = &$edges[$index];
            if ($edge['state'] === 'up' && $state !== 'up') {
                $edge['state'] = $state;
            }
            $edge['status'] = match ($edge['state']) {
                'up' => 'up',
                'disabled' => 'disabled',
                default => 'down',
            };
            $edge['levels'][] = (string) ($row['isisISAdjNeighSysType'] ?? '');
            $edge['areas'][] = (string) ($row['isisISAdjAreaAddress'] ?? '');
            $edge['systemIds'][] = $systemId;
            $edge['addresses'][] = $address;
            $edge['interfaces'][] = ['device_id' => $local, 'port_id' => (int) ($row['port_id'] ?? 0) ?: null, 'name' => self::portName($row)];
            unset($edge);
        }
        $this->uniqueLists($edges, $pairs, ['levels', 'areas', 'systemIds', 'addresses']);
    }

    /**
     * IPsec tunnels to the device owning the peer address, else to an external VPN peer. Tunnels
     * reported by both ends are merged; the edge is up when every tunnel is.
     */
    private function addIpsec(array &$nodes, array &$edges, array $kept): void
    {
        $pairs = [];
        foreach ($this->layers['ipsec'] ?? [] as $row) {
            $local = (int) $row['device_id'];
            $peer = trim((string) ($row['peer_addr'] ?? ''));
            if (! isset($kept[$local], $nodes['d:' . $local]) || $peer === ''
                || ($to = $this->farEnd($nodes, $local, $this->deviceByAddress($peer), 'xp:' . $peer, $peer, 'IPsec peer ' . $peer, $peer, DeviceRole::FIREWALL)) === null) {
                continue;
            }

            $status = strtolower(trim((string) ($row['tunnel_status'] ?? ''))) ?: 'unknown';
            $up = in_array($status, self::IPSEC_UP, true);
            $index = $this->overlayEdge($edges, $pairs, 'ipsec', 'd:' . $local, $to, ['state' => $up ? 'up' : $status, 'tunnels' => [], 'addresses' => []]);
            $edge = &$edges[$index];
            if ($edge['state'] === 'up' && ! $up) {
                $edge['state'] = $status;
            }
            $edge['status'] = match ($edge['state']) {
                'up' => 'up',
                'disabled' => 'disabled',
                default => 'down',
            };
            $edge['tunnels'][] = [
                'device_id' => $local,
                'name' => (string) ($row['tunnel_name'] ?? ''),
                'local' => (string) ($row['local_addr'] ?? ''),
                'peer' => $peer,
                'status' => $status,
                'up' => $up,
            ];
            $edge['addresses'][] = $peer;
            unset($edge);
        }
        $this->uniqueLists($edges, $pairs, ['addresses']);
    }

    /**
     * MPLS LSPs from the head end to the device owning the tail address, one edge per pair of devices
     * with arrows toward the tails. Down when an LSP that is administratively in service is not in
     * service; disabled when every LSP is shut down.
     */
    private function addLsps(array &$nodes, array &$edges, array $kept): void
    {
        $pairs = [];
        foreach ($this->layers['mpls'] ?? [] as $row) {
            $local = (int) $row['device_id'];
            $tail = trim((string) ($row['mplsLspToAddr'] ?? ''));
            // a point-to-multipoint LSP has no single tail
            if (! isset($kept[$local], $nodes['d:' . $local]) || $tail === '' || $tail === '0.0.0.0'
                || ($to = $this->farEnd($nodes, $local, $this->deviceByAddress($tail), 'xm:' . $tail, $tail, 'LSP tail ' . $tail, $tail)) === null) {
                continue;
            }

            $index = $this->overlayEdge($edges, $pairs, 'mpls', 'd:' . $local, $to, ['state' => 'up', 'lsps' => [], 'addresses' => []]);
            $edge = &$edges[$index];
            $edge['lsps'][] = [
                'name' => (string) ($row['mplsLspName'] ?? ''),
                'head' => 'd:' . $local,
                'tail' => $to,
                'from' => (string) ($row['mplsLspFromAddr'] ?? ''),
                'to' => $tail,
                'admin' => (string) ($row['mplsLspAdminState'] ?? ''),
                'oper' => (string) ($row['mplsLspOperState'] ?? ''),
                'type' => (string) ($row['mplsLspType'] ?? ''),
                'frr' => ($row['mplsLspFastReroute'] ?? '') === 'true',
                'paths' => (int) ($row['mplsLspOperationalPaths'] ?? 0) . '/' . (int) ($row['mplsLspConfiguredPaths'] ?? 0),
                'transitions' => (int) ($row['mplsLspTransitions'] ?? 0),
            ];
            $edge['addresses'][] = $tail;
            unset($edge);
        }

        foreach ($pairs as $index) {
            $edge = &$edges[$index];
            $enabled = array_filter($edge['lsps'], fn ($lsp) => $lsp['admin'] !== 'outOfService');
            $down = count(array_filter($enabled, fn ($lsp) => $lsp['oper'] !== 'inService'));
            $edge['state'] = match (true) {
                ! $enabled => 'disabled',
                $down > 0 => $down . ' of ' . count($enabled) . ' down',
                default => 'up',
            };
            $edge['status'] = ! $enabled ? 'disabled' : ($down ? 'down' : 'up');
            usort($edge['lsps'], fn ($a, $b) => [$a['head'], $a['name']] <=> [$b['head'], $b['name']]);
            $heads = array_unique(array_column($edge['lsps'], 'head'));
            $edge['arrows'] = implode(', ', array_keys(array_filter(['to' => in_array($edge['from'], $heads, true), 'from' => in_array($edge['to'], $heads, true)])));
            unset($edge);
        }
        $this->uniqueLists($edges, $pairs, ['addresses']);
    }

    /**
     * Pseudowires (VCs) between PE devices, both ends paired by VC ID, one edge per pair of devices.
     * LibreNMS keeps no state for them but does keep both MTUs: a VC whose two ends disagree cannot come
     * up (RFC 4447), so the edge is flagged. An MTU of 0 is unknown.
     */
    private function addPseudowires(array &$nodes, array &$edges, array $kept): void
    {
        $pairs = [];
        $vcs = []; // edge index => VC ID => VC with its ends by node
        foreach ($this->layers['pw'] ?? [] as $row) {
            $local = (int) $row['device_id'];
            if (! isset($kept[$local], $nodes['d:' . $local])) {
                continue;
            }

            $peer = self::ldpAddress($row['peer_ldp_id'] ?? null);
            $peerId = (int) ($row['peer_device_id'] ?? 0);
            $remote = $peerId && (isset($this->devices[$peerId]) || isset($this->known['devices'][$peerId])) ? $peerId : $this->deviceByAddress($peer);
            if (($remote === null && $peer === '')
                || ($to = $this->farEnd($nodes, $local, $remote, 'xw:' . $peer, $peer, 'Pseudowire peer ' . $peer, $peer)) === null) {
                continue;
            }

            $index = $this->overlayEdge($edges, $pairs, 'pw', 'd:' . $local, $to, ['state' => 'up', 'vcs' => [], 'mtu_mismatch' => 0, 'addresses' => []]);
            $vcId = (int) ($row['cpwVcID'] ?? 0);
            $vcs[$index][$vcId] ??= [
                'vc' => $vcId,
                'type' => (string) ($row['pw_type'] ?? ''),
                'psn' => (string) ($row['pw_psntype'] ?? ''),
                'description' => (string) ($row['pw_descr'] ?? ''),
                'ends' => [],
            ];
            $vcs[$index][$vcId]['ends']['d:' . $local] = [
                'port_id' => (int) ($row['port_id'] ?? 0) ?: null,
                'port' => self::portName($row),
                'mtu' => (int) ($row['pw_local_mtu'] ?? 0) ?: null,
                'peer_mtu' => (int) ($row['pw_peer_mtu'] ?? 0) ?: null,
            ];
            if ($peer !== '') {
                $edges[$index]['addresses'][] = $peer;
            }
        }

        foreach ($vcs as $index => $list) {
            $edge = &$edges[$index];
            ksort($list);
            foreach ($list as $vc) {
                $a = $vc['ends'][$edge['from']] ?? null;
                $b = $vc['ends'][$edge['to']] ?? null;
                // each end's own MTU, else what the other end learned from it
                $mtuA = $a['mtu'] ?? $b['peer_mtu'] ?? null;
                $mtuB = $b['mtu'] ?? $a['peer_mtu'] ?? null;
                $mismatch = $mtuA !== null && $mtuB !== null && $mtuA !== $mtuB;
                $edge['vcs'][] = [
                    'vc' => $vc['vc'],
                    'type' => $vc['type'],
                    'psn' => $vc['psn'],
                    'description' => $vc['description'],
                    'a' => $a ? ['port_id' => $a['port_id'], 'port' => $a['port']] : null,
                    'b' => $b ? ['port_id' => $b['port_id'], 'port' => $b['port']] : null,
                    'mtu_a' => $mtuA,
                    'mtu_b' => $mtuB,
                    'mismatch' => $mismatch,
                ];
                $edge['mtu_mismatch'] += $mismatch ? 1 : 0;
            }
            $edge['state'] = $edge['mtu_mismatch'] ? 'MTU mismatch' : 'up';
            $edge['status'] = $edge['mtu_mismatch'] ? 'warning' : 'up';
            unset($edge);
        }
        $this->uniqueLists($edges, $pairs, ['addresses']);
    }

    /**
     * Far end of an overlay: the device's node when it is on the map; null (left out, like OSPF and
     * BGP) when it is the local device or a device the user may see that is not on this map; else an
     * external node created on first use, which shows only the address of a device the user may not see.
     */
    private function farEnd(array &$nodes, int $local, ?int $remote, string $externalId, string $label, string $name, string $address, string $role = DeviceRole::ROUTER): ?string
    {
        if ($remote !== null) {
            return $remote !== $local && isset($nodes['d:' . $remote]) ? 'd:' . $remote : null;
        }

        $nodes[$externalId] ??= [
            'id' => $externalId,
            'type' => 'external',
            'managed' => false,
            'label' => $label,
            'name' => $name,
            'role' => $role,
            'status' => 'unknown',
            'address' => $address,
        ];

        return $externalId;
    }

    /**
     * Index of the overlay edge of one kind between two nodes, created on first use: both ends of an
     * adjacency, tunnel, LSP or pseudowire are merged into one edge per pair of nodes.
     */
    private function overlayEdge(array &$edges, array &$pairs, string $kind, string $from, string $to, array $fields): int
    {
        $key = $kind . '|' . (strcmp($from, $to) < 0 ? "$from|$to" : "$to|$from");
        if (! isset($pairs[$key])) {
            $pairs[$key] = count($edges);
            $edges[] = ['id' => 'e' . count($edges), 'kind' => $kind, 'from' => $from, 'to' => $to] + $fields + ['status' => 'up', 'a' => null, 'b' => null];
        }

        return $pairs[$key];
    }

    /**
     * Drop empty and repeated values from list fields of merged overlay edges.
     *
     * @param  array<string, int>  $pairs  edge indexes
     * @param  string[]  $fields
     */
    private function uniqueLists(array &$edges, array $pairs, array $fields): void
    {
        foreach ($pairs as $index) {
            foreach ($fields as $field) {
                $edges[$index][$field] = array_values(array_unique(array_filter($edges[$index][$field], fn ($value) => $value !== '' && $value !== null)));
            }
        }
    }

    /**
     * Device owning an address: on this map first (same VRF), then among the other devices the user may see.
     */
    private function deviceByAddress(string $address, string $vrf = ''): ?int
    {
        $address = Cidr::canonical($address);
        if ($address === null) {
            return null;
        }

        return $this->lookupIp($address, $vrf) ?? $this->known['addresses'][$address] ?? null;
    }

    /**
     * Router ID of an OSPFv3 neighbour as an address (LibreNMS stores both the dotted and the numeric form).
     */
    private static function ospfv3RouterId(array $row): string
    {
        $rid = trim((string) ($row['router_id'] ?? ''));
        if ($rid === '' && is_numeric($row['ospfv3NbrRtrId'] ?? null)) {
            $rid = (string) long2ip((int) $row['ospfv3NbrRtrId']);
        }

        return $rid;
    }

    /**
     * IS-IS system ID as 0100.0000.0001 (LibreNMS stores the hex bytes of the MIB: 01.00.00.00.00.01).
     */
    public static function isisSystemId(string $value): string
    {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $value) ?? '');

        return strlen($hex) === 12 ? implode('.', str_split($hex, 4)) : strtolower(trim($value));
    }

    /**
     * Loopback address encoded in a system ID by the common convention (0100.0000.0001 is 10.0.0.1:
     * the address in four zero-padded decimal groups), null when it does not follow it.
     */
    public static function isisSystemIdAddress(string $systemId): ?string
    {
        $digits = str_replace('.', '', $systemId);
        if (! preg_match('/^\d{12}$/', $digits)) {
            return null;
        }
        $octets = array_map('intval', str_split($digits, 3));
        if (max($octets) > 255 || array_sum($octets) === 0) {
            return null;
        }

        return implode('.', $octets);
    }

    /**
     * Peer LDP router ID of a pseudowire as an address: LibreNMS stores it as a (signed) integer.
     */
    private static function ldpAddress(mixed $value): string
    {
        if (! is_numeric($value) || (int) $value === 0) {
            return '';
        }
        $long = (int) $value;

        return (string) long2ip($long < 0 ? $long + 4294967296 : $long);
    }

    /**
     * Short name of the local interface joined to an overlay row.
     */
    private static function portName(array $row): string
    {
        $name = (string) ($row['ifName'] ?? '') ?: (string) ($row['ifDescr'] ?? '');

        return $name === '' ? '' : InterfaceName::short($name);
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
