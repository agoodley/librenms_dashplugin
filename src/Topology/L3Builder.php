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
 *
 * The far end of an overlay is the device's node when it is on the map, a stub node of a device the
 * user may see outside the map, else one external node per address shared by every overlay.
 */
class L3Builder
{
    /** Overlay layers beyond OSPF and BGP: build options and edge kinds. */
    public const LAYERS = ['ospfv3', 'isis', 'ipsec', 'mpls', 'pw'];

    /** Most MPLS LSP edges (pairs of devices) drawn: an RSVP full mesh of 200 PEs has 19,900. */
    public const MAX_LSP_EDGES = 500;

    /** Most stubs of devices outside the map (the maxNodes option counts them too): a route reflector has hundreds of peers. */
    public const MAX_STUBS = 100;

    private const ROLE_RANK = [
        DeviceRole::FIREWALL => 5,
        DeviceRole::ROUTER => 4,
        DeviceRole::L3SWITCH => 3,
    ];

    /** OSPF / OSPFv3 neighbour states of a working adjacency (2-way between DROthers). */
    private const OSPF_UP = ['full', 'twoway'];

    /** OSPF / OSPFv3 neighbour states from worst to best: the merged state of an adjacency is the worse side's. */
    private const OSPF_RANK = ['down' => 1, 'attempt' => 2, 'init' => 3, 'exchangestart' => 4, 'exstart' => 4, 'exchange' => 5, 'loading' => 6, 'twoway' => 7, 'full' => 8];

    /** BGP session states from worst to best. */
    private const BGP_RANK = ['idle' => 1, 'connect' => 2, 'active' => 3, 'opensent' => 4, 'openconfirm' => 5, 'established' => 6];

    /** IPsec tunnel states that mean up (Cisco reports "active", other vendors "established"...). */
    private const IPSEC_UP = ['active', 'up', 'established', 'connected'];

    /** LibreNMS keeps peer_ldp_id in a signed INT: every LDP ID from 128.0.0.0 on is stored as this. */
    private const LDP_CAPPED = 2147483647;

    /** Ethernet header bytes that an OS counts in an interface MTU where the others do not: IOS XR 1514 is IOS XE 1500. */
    private const MTU_HEADER = ['iosxr' => 14];

    /** A VLAN tag, which IOS XR also counts in the MTU of a subinterface (a dot1q subinterface defaults to 1518). */
    private const VLAN_TAG = 4;

    /** Overlays whose two ends report the same adjacency, session or tunnel. */
    private const TWO_SIDED = ['ospf', 'bgp', 'ospfv3', 'isis', 'ipsec'];

    /** @var array<string, array{network: string, vrf: string, family: int, prefix: int, members: array}> */
    private array $subnets = [];

    /** @var array<int, string[]> device_id => loopback addresses */
    private array $loopbacks = [];

    /** @var array<string, array<int, array<string, true>>>|null canonical address => device_id => VRFs, on this map */
    private ?array $owners = null;

    /** @var array<string, array<int, bool>> loopback / host route address => device_id => in the global table, on this map */
    private array $loopbackOwners = [];

    /** @var array<int, true> indexes of the address rows of this map's devices with a usable address */
    private array $usable = [];

    /** @var array<string, ?int> deviceByAddress() results */
    private array $resolved = [];

    /** @var array<string, string> "context|address" (or system ID) => external node id */
    private array $externals = [];

    /** @var array<string, array{shown: int, total: int}> layers drawn in part */
    private array $limits = [];

    /** Stubs that may still be drawn (see MAX_STUBS). */
    private int $stubBudget = self::MAX_STUBS;

    /** @var array<int, true> devices outside the map that are a far end, drawn as stubs or not */
    private array $outside = [];

    /**
     * @param  array<int, array>  $devices  device_id => device row (with 'role', 'url', 'bgpLocalAs')
     * @param  array<int, array>  $addresses  rows: device_id, port_id, ifName, ifDescr, ifAlias, ifOperStatus, ifType, address, prefixlen, vrf
     * @param  array<int, array>  $ospf  rows of ospf_nbrs
     * @param  array<int, array>  $bgp  rows of bgpPeers
     * @param  array<string, array<int, array>>  $layers  rows per overlay layer (LAYERS): ospfv3_nbrs, isis_adjacencies, ipsec_tunnels,
     *                                                    mpls_lsps and pseudowires (the last two with the local ifName, pseudowires with its ifMtu)
     * @param  array{addresses?: array<string, int>, vrfAddresses?: array<string, int>, loopbacks?: array<string, int>, routerIds?: array<string, int>,
     *     ambiguousRouterIds?: array<string, true>, pseudowires?: array<int, array>, vcDevices?: array<int, int>, devices?: array<int, array>}  $known
     *     far ends among the other devices the user may see (L3LayerRepository::resolve() of lookups()) and the rows of
     *     those devices (TopologyRepository::devices())
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
     * The same map with its far ends among the other devices the user may see (L3LayerRepository::resolve()
     * of lookups()), keeping what was indexed.
     */
    public function withKnown(array $known): self
    {
        $builder = clone $this;
        $builder->known = $known;
        $builder->resolved = [];

        return $builder;
    }

    /**
     * Build the map data returned by api/l3: nodes (devices, stubs of devices outside the map, subnets, external
     * routers / AS clouds), edges (p2p, member, ospf, bgp and the LAYERS), VRFs, statistics and the layers drawn in part.
     *
     * @param  array{hideStubs?: bool, p2pPrefix4?: int, p2pPrefix6?: int, exclude?: string[], vrf?: string|null, ospf?: bool, bgp?: bool,
     *     ospfv3?: bool, isis?: bool, ipsec?: bool, mpls?: bool, pw?: bool, root?: int|null, depth?: int|null, maxNodes?: int|null}  $options
     *     hideStubs: leave out subnets with a single device, p2pPrefix4/6: prefix length from which a
     *     two-device subnet is drawn as a direct link, root/depth: only devices within depth hops,
     *     maxNodes: devices drawn at most, stubs of devices outside the map included
     */
    public function build(array $options = []): array
    {
        $hideStubs = $options['hideStubs'] ?? true;
        $p2p4 = (int) ($options['p2pPrefix4'] ?? 29);
        $p2p6 = (int) ($options['p2pPrefix6'] ?? 126);
        $root = $options['root'] ?? null;

        $this->index($options['exclude'] ?? [], $options['vrf'] ?? null);
        $this->externals = [];
        $this->limits = [];

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

        $drawn = count(array_filter($nodes, fn ($n) => $n['type'] === 'device'));
        $this->stubBudget = min(self::MAX_STUBS, isset($options['maxNodes']) ? max(0, (int) $options['maxNodes'] - $drawn) : PHP_INT_MAX);
        $this->outside = [];

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

        // the device of a stub in another location, group... has no rows here: its edges only have the drawn side
        foreach ($edges as &$edge) {
            $far = $nodes[$edge['to']] ?? [];
            if (in_array($edge['kind'], self::TWO_SIDED, true) && ! empty($far['outside']) && ! isset($this->devices[$far['device_id']])) {
                $edge['oneSide'] = true;
            }
        }
        unset($edge);
        $stubCount = count(array_filter($nodes, fn ($n) => ! empty($n['outside'])));
        if (count($this->outside) > $stubCount) {
            $this->limits['outside'] = ['shown' => $stubCount, 'total' => count($this->outside)];
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
                'devices' => count(array_filter($nodes, fn ($n) => $n['type'] === 'device' && empty($n['outside']))),
                'outside' => $stubCount,
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
            'limits' => $this->limits,
        ];
    }

    /**
     * What the overlays need resolved among the other devices the user may see (L3LayerRepository::resolve()):
     * the far-end addresses that are not in this map's global table (nor link-local), router IDs per context, the
     * VC IDs of the pseudowires and the peer devices LibreNMS recorded for them.
     *
     * @return array{addresses: string[], routerIds: string[], vcs: int[], devices: int[]}
     */
    public function lookups(): array
    {
        $lookups = self::layerLookups($this->layers, $this->ospf, $this->bgp);
        $owners = $this->owners();
        $global = fn (string $address) => array_filter($owners[$address] ?? [], fn ($vrfs) => isset($vrfs[''])) !== [];
        $lookups['addresses'] = array_values(array_filter($lookups['addresses'], fn ($address) => ! $global($address)));

        return $lookups;
    }

    /**
     * Far ends of the overlay rows to recognize: addresses (canonical, link-local and other ignorable ones left
     * out; including loopbacks encoded in IS-IS system IDs), "context|router ID" of OSPF and OSPFv3 neighbours,
     * the VC IDs of pseudowires (to find their other end) and the peer devices recorded for them, and the OSPF and
     * BGP neighbours the topology history recorded (a map of the past).
     *
     * @param  array<string, array<int, array>>  $layers  see the constructor
     * @param  array<int, array>  $ospf  rows of ospf_nbrs
     * @param  array<int, array>  $bgp  rows of bgpPeers
     * @return array{addresses: string[], routerIds: string[], vcs: int[], devices: int[]}
     */
    public static function layerLookups(array $layers, array $ospf = [], array $bgp = []): array
    {
        $addresses = [];
        $routerIds = [];
        $vcs = [];
        $devices = [];
        $routerId = function (string $rid, string $context) use (&$routerIds, &$addresses): void {
            if (($rid = self::routerKey($rid)) !== null) {
                $routerIds[] = $context . '|' . $rid;
                $addresses[] = $rid; // usually a loopback address
            }
        };

        foreach ($ospf as $row) {
            $addresses[] = (string) ($row['ospfNbrIpAddr'] ?? '');
            $routerId((string) ($row['ospfNbrRtrId'] ?? ''), (string) ($row['context_name'] ?? ''));
            $devices[] = (int) ($row['neighbor_device_id'] ?? 0);
        }
        foreach ($bgp as $row) {
            $addresses[] = (string) ($row['bgpPeerIdentifier'] ?? '');
            $devices[] = (int) ($row['neighbor_device_id'] ?? 0);
        }
        foreach ($layers['ospfv3'] ?? [] as $row) {
            $routerId(self::ospfv3RouterId($row), (string) ($row['context_name'] ?? ''));
            $addresses[] = (string) ($row['ospfv3NbrAddress'] ?? '');
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
            $vcs[] = (int) ($row['cpwVcID'] ?? 0);
            $devices[] = (int) ($row['peer_device_id'] ?? 0);
        }

        $canonical = [];
        foreach (array_unique($addresses) as $address) {
            $address = Cidr::canonical($address);
            if ($address !== null && ! Cidr::isIgnorable($address)) {
                $canonical[$address] = true;
            }
        }

        return [
            'addresses' => array_keys($canonical),
            'routerIds' => array_values(array_unique($routerIds)),
            'vcs' => array_values(array_unique(array_filter($vcs))),
            'devices' => array_values(array_unique(array_filter($devices, fn ($id) => $id > 0))),
        ];
    }

    /**
     * Sort the address rows into subnets (per VRF) and loopbacks / host routes. Link-local, loopback,
     * multicast and excluded networks are skipped.
     *
     * @param  string[]  $exclude  CIDRs never drawn
     * @param  string|null  $vrfFilter  only keep subnets of this VRF (null or '' keeps every VRF)
     */
    private function index(array $exclude, ?string $vrfFilter): void
    {
        $this->subnets = [];
        $this->loopbacks = [];
        $this->owners();

        foreach ($this->addresses as $index => $row) {
            if (! isset($this->usable[$index])) {
                continue;
            }
            $address = trim((string) $row['address']);
            $prefix = (int) ($row['prefixlen'] ?? 0);
            $deviceId = (int) $row['device_id'];

            $family = Cidr::family($address);
            $vrf = (string) ($row['vrf'] ?? '');
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
     * Owners of the addresses of this map's devices, in every VRF whatever the build options: canonical
     * address => device_id => VRFs (and the loopback / host route owners, and the usable rows). Link-local,
     * loopback, multicast and other ignorable addresses have none.
     *
     * @return array<string, array<int, array<string, true>>>
     */
    private function owners(): array
    {
        if ($this->owners === null) {
            $this->owners = [];
            $this->loopbackOwners = [];
            $this->usable = [];
            foreach ($this->addresses as $index => $row) {
                $address = Cidr::canonical((string) ($row['address'] ?? ''));
                $deviceId = (int) ($row['device_id'] ?? 0);
                if ($address === null || ! isset($this->devices[$deviceId]) || Cidr::isIgnorable($address)) {
                    continue;
                }
                $this->usable[$index] = true;
                $this->owners[$address][$deviceId][(string) ($row['vrf'] ?? '')] = true;
                $prefix = (int) ($row['prefixlen'] ?? 0);
                if ($prefix >= (Cidr::family($address) === 6 ? 128 : 32) || strtolower((string) ($row['ifType'] ?? '')) === 'softwareloopback') {
                    $this->loopbackOwners[$address][$deviceId] = ($this->loopbackOwners[$address][$deviceId] ?? false) || (string) ($row['vrf'] ?? '') === '';
                }
            }
        }

        return $this->owners;
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
     * @param  array|null  $device  its row when it is not one of the map's devices
     */
    private function deviceNode(int $deviceId, array $stubs, ?array $device = null): array
    {
        $device ??= $this->devices[$deviceId];
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
     * OSPF adjacencies as overlay edges, both directions merged into the worse state (down unless both
     * sides are full/2-way), also with a stub beyond the hops. The neighbour is found by its interface
     * address, else by router ID; on a map of the past it is the one the history recorded.
     */
    private function addOspf(array &$nodes, array &$edges, array $kept): void
    {
        $pairs = [];
        foreach ($this->ospf as $row) {
            $local = (int) $row['device_id'];
            $drawn = isset($kept[$local], $nodes['d:' . $local]);
            if (! $drawn && ! isset($this->devices[$local])) {
                continue;
            }

            $ip = trim((string) ($row['ospfNbrIpAddr'] ?? ''));
            $rid = self::routerKey((string) ($row['ospfNbrRtrId'] ?? ''));
            $context = (string) ($row['context_name'] ?? '');
            $remote = self::fromHistory($row) ? self::recordedNeighbor($row)
                : $this->deviceByAddress($ip, $context) ?? ($rid !== null ? $this->deviceByRouterId($rid, $context) : null);
            [$from, $to] = $drawn ? ['d:' . $local, $this->farEnd($nodes, $local, $remote, $rid ?? $ip, 'OSPF neighbor', $context, [$rid, $ip])]
                : ['d:' . $remote, $this->stubSide($nodes, $kept, $local, $remote)];
            if ($to === null) {
                continue;
            }

            $state = strtolower((string) ($row['ospfNbrState'] ?? 'unknown'));
            $index = $this->overlayEdge($edges, $pairs, 'ospf', $from, $to, ['state' => $state, 'addresses' => []]);
            $edge = &$edges[$index];
            $edge['state'] = self::worse($edge['state'], $state, self::OSPF_RANK);
            $edge['status'] = in_array($edge['state'], self::OSPF_UP, true) ? 'up' : 'down';
            $edge['addresses'][] = $ip;
            unset($edge);
        }
        $this->uniqueLists($edges, $pairs, ['addresses']);
    }

    /**
     * BGP sessions as overlay edges, iBGP when both sides share the AS. Peers that are no device the user
     * may see are grouped into one cloud per remote AS. A pair is down if any session is not established.
     */
    private function addBgp(array &$nodes, array &$edges, array $kept): void
    {
        $pairs = [];
        foreach ($this->bgp as $row) {
            $local = (int) $row['device_id'];
            $drawn = isset($kept[$local], $nodes['d:' . $local]);
            if (! $drawn && ! isset($this->devices[$local])) {
                continue;
            }

            $peerIp = (string) ($row['bgpPeerIdentifier'] ?? '');
            $remoteAs = (int) ($row['bgpPeerRemoteAs'] ?? 0);
            $localAs = (int) ($this->devices[$local]['bgpLocalAs'] ?? 0);
            $type = $remoteAs && $remoteAs === $localAs ? 'iBGP' : 'eBGP';
            $remote = self::fromHistory($row) ? self::recordedNeighbor($row) : $this->deviceByAddress($peerIp, (string) ($row['context_name'] ?? ''));
            if ($remote === $local) {
                continue;
            }

            $from = 'd:' . $local;
            $to = $remote !== null ? $this->farEnd($nodes, $local, $remote, '', 'BGP peer') : null;
            if (! $drawn) {
                // the session of a device beyond the hops with a drawn device: its side of that pair
                $from = 'd:' . $remote;
                if (($to = $this->stubSide($nodes, $kept, $local, $remote)) === null) {
                    continue;
                }
            } elseif ($to === null) {
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

            $key = (strcmp($from, $to) < 0 ? "$from|$to" : "$to|$from") . '|' . $type;
            $state = strtolower((string) ($row['bgpPeerState'] ?? 'unknown'));
            if (isset($pairs[$key])) {
                $edge = &$edges[$pairs[$key]];
                $edge['state'] = self::worse($edge['state'], $state, self::BGP_RANK);
                $edge['status'] = $edge['state'] === 'established' ? 'up' : 'down';
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
     * OSPFv3 adjacencies, merged like OSPF. A neighbour's address is usually link-local (the same on many
     * routers), so it is found by router ID in its context: of a monitored OSPF / OSPFv3 instance, else a
     * loopback with that address; a global neighbour address is tried last.
     */
    private function addOspfv3(array &$nodes, array &$edges, array $kept): void
    {
        $pairs = [];
        foreach ($this->layers['ospfv3'] ?? [] as $row) {
            $local = (int) $row['device_id'];
            $drawn = isset($kept[$local], $nodes['d:' . $local]);
            if (! $drawn && ! isset($this->devices[$local])) {
                continue;
            }

            $rid = self::routerKey(self::ospfv3RouterId($row));
            $address = trim((string) ($row['ospfv3NbrAddress'] ?? ''));
            $global = ($canonical = Cidr::canonical($address)) !== null && ! Cidr::isIgnorable($canonical) ? $canonical : '';
            $context = (string) ($row['context_name'] ?? '');
            $remote = ($rid !== null ? $this->deviceByRouterId($rid, $context) : null)
                ?? ($global !== '' ? $this->deviceByAddress($global, $context) : null);
            [$from, $to] = $drawn ? ['d:' . $local, $this->farEnd($nodes, $local, $remote, $rid ?? $global, 'OSPFv3 neighbor', $context, [$rid, $global])]
                : ['d:' . $remote, $this->stubSide($nodes, $kept, $local, $remote)];
            if ($to === null) {
                continue;
            }

            $state = strtolower((string) ($row['ospfv3NbrState'] ?? 'unknown'));
            $index = $this->overlayEdge($edges, $pairs, 'ospfv3', $from, $to, ['state' => $state, 'routerIds' => [], 'addresses' => []]);
            $edge = &$edges[$index];
            $edge['state'] = self::worse($edge['state'], $state, self::OSPF_RANK);
            $edge['status'] = in_array($edge['state'], self::OSPF_UP, true) ? 'up' : 'down';
            $edge['routerIds'][] = $rid;
            $edge['addresses'][] = $address;
            unset($edge);
        }
        $this->uniqueLists($edges, $pairs, ['routerIds', 'addresses']);
    }

    /**
     * IS-IS adjacencies, merged per pair of routers. The neighbour is found by its interface address (not a
     * link-local one), else by its system ID: as learnt from the adjacencies found by address, else a loopback
     * of a device encoded in it (0100.0000.0001 for 10.0.0.1, a common convention). Unknown neighbours become
     * external routers named by system ID.
     */
    private function addIsis(array &$nodes, array &$edges, array $kept): void
    {
        $rows = $this->layers['isis'] ?? [];

        // system ID => device_id (0 when adjacencies disagree), from the neighbours identified by address
        $learnt = [];
        foreach ($rows as $row) {
            $systemId = self::isisSystemId((string) ($row['isisISAdjNeighSysID'] ?? ''));
            $device = $systemId !== '' ? $this->deviceByAddress((string) ($row['isisISAdjIPAddrAddress'] ?? '')) : null;
            if ($device !== null && $device !== (int) $row['device_id']) {
                $learnt[$systemId] = isset($learnt[$systemId]) && $learnt[$systemId] !== $device ? 0 : $device;
            }
        }

        $pairs = [];
        foreach ($rows as $row) {
            $local = (int) $row['device_id'];
            $drawn = isset($kept[$local], $nodes['d:' . $local]);
            if (! $drawn && ! isset($this->devices[$local])) {
                continue;
            }

            $systemId = self::isisSystemId((string) ($row['isisISAdjNeighSysID'] ?? ''));
            $address = trim((string) ($row['isisISAdjIPAddrAddress'] ?? ''));
            $global = ($canonical = Cidr::canonical($address)) !== null && ! Cidr::isIgnorable($canonical) ? $canonical : '';
            $loopback = self::isisSystemIdAddress($systemId);
            $remote = $this->deviceByAddress($address)
                ?? (($learnt[$systemId] ?? 0) ?: null)
                ?? ($loopback !== null ? $this->deviceByLoopback($loopback) : null);
            [$from, $to] = $drawn ? ['d:' . $local, $this->farEnd($nodes, $local, $remote, $systemId !== '' ? $systemId : $global, 'IS-IS neighbor', '', [$global])]
                : ['d:' . $remote, $this->stubSide($nodes, $kept, $local, $remote)];
            if ($to === null) {
                continue;
            }

            $state = strtolower((string) ($row['isisISAdjState'] ?? 'unknown'));
            if (in_array(strtolower((string) ($row['isisCircAdminState'] ?? 'on')), ['off', 'down'], true)) {
                $state = 'disabled';
            }
            $index = $this->overlayEdge($edges, $pairs, 'isis', $from, $to,
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
     * reported by both ends (also a stub's beyond the hops) are merged; the edge is up when every tunnel is.
     */
    private function addIpsec(array &$nodes, array &$edges, array $kept): void
    {
        $pairs = [];
        foreach ($this->layers['ipsec'] ?? [] as $row) {
            $local = (int) $row['device_id'];
            $peer = trim((string) ($row['peer_addr'] ?? ''));
            $drawn = isset($kept[$local], $nodes['d:' . $local]);
            if ($peer === '' || (! $drawn && ! isset($this->devices[$local]))) {
                continue;
            }
            $remote = $this->deviceByAddress($peer);
            [$from, $to] = $drawn ? ['d:' . $local, $this->farEnd($nodes, $local, $remote, $peer, 'IPsec peer', '', [$peer], DeviceRole::FIREWALL)]
                : ['d:' . $remote, $this->stubSide($nodes, $kept, $local, $remote)];
            if ($to === null) {
                continue;
            }

            $status = strtolower(trim((string) ($row['tunnel_status'] ?? ''))) ?: 'unknown';
            $up = in_array($status, self::IPSEC_UP, true);
            $index = $this->overlayEdge($edges, $pairs, 'ipsec', $from, $to, ['state' => $up ? 'up' : $status, 'tunnels' => [], 'addresses' => []]);
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
     * service; disabled when every LSP is shut down. Past MAX_LSP_EDGES pairs (an RSVP full mesh) only
     * the pairs with LSPs down and then the busiest are drawn, reported in limits.
     */
    private function addLsps(array &$nodes, array &$edges, array $kept): void
    {
        // group by pair first so that the pairs over the limit create no nodes
        $groups = [];
        foreach ($this->layers['mpls'] ?? [] as $row) {
            $local = (int) $row['device_id'];
            $tail = Cidr::canonical((string) ($row['mplsLspToAddr'] ?? '')) ?? '';
            // a point-to-multipoint LSP has no single tail
            if (! isset($kept[$local], $nodes['d:' . $local]) || $tail === '' || $tail === '0.0.0.0') {
                continue;
            }
            $remote = $this->deviceByAddress($tail);
            if ($remote === $local) {
                continue;
            }

            $from = 'd:' . $local;
            $far = $remote !== null ? 'd:' . $remote : 'x:' . $tail;
            $key = strcmp($from, $far) < 0 ? "$from|$far" : "$far|$from";
            $groups[$key] ??= ['down' => 0, 'rows' => []];
            $groups[$key]['rows'][] = [$local, $remote, $tail, $row];
            if (($row['mplsLspAdminState'] ?? '') !== 'outOfService' && ($row['mplsLspOperState'] ?? '') !== 'inService') {
                $groups[$key]['down']++;
            }
        }

        if (count($groups) > self::MAX_LSP_EDGES) {
            uksort($groups, fn ($a, $b) => [$groups[$b]['down'] > 0, count($groups[$b]['rows']), $a] <=> [$groups[$a]['down'] > 0, count($groups[$a]['rows']), $b]);
            $this->limits['mpls'] = ['shown' => self::MAX_LSP_EDGES, 'total' => count($groups)];
            $groups = array_slice($groups, 0, self::MAX_LSP_EDGES, true);
        }

        $pairs = [];
        foreach ($groups as $group) {
            foreach ($group['rows'] as [$local, $remote, $tail, $row]) {
                $to = $this->farEnd($nodes, $local, $remote, $tail, 'LSP tail', '', [$tail]);
                if ($to === null) {
                    continue;
                }

                $index = $this->overlayEdge($edges, $pairs, 'mpls', 'd:' . $local, $to, ['state' => 'up', 'lsps' => [], 'addresses' => []]);
                $edges[$index]['lsps'][] = [
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
                $edges[$index]['addresses'][] = $tail;
            }
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
     * Pseudowires (VCs) between PE devices, one edge per pair of devices with both ends of each VC. LibreNMS
     * loses most peers (peer_device_id is never set, peer_ldp_id is capped at 2147483647 from 128.0.0.0 on),
     * see pseudowirePeer(); a VC whose peer stays unknown goes to an "unknown peers" node of its PE. LibreNMS
     * keeps no pseudowire state: what can be checked is the MTU of the two attachment circuits, which must
     * match for a VC to come up (RFC 4447), see payloadMtu().
     */
    private function addPseudowires(array &$nodes, array &$edges, array $kept): void
    {
        // the rows of every VC ID: this map's devices and the other devices the user may see
        $byVc = [];
        foreach ($this->layers['pw'] ?? [] as $row) {
            $byVc[(int) ($row['cpwVcID'] ?? 0)][(int) $row['device_id']][] = $row;
        }
        foreach ($this->known['pseudowires'] ?? [] as $row) {
            if (! isset($this->devices[(int) $row['device_id']])) {
                $byVc[(int) ($row['cpwVcID'] ?? 0)][(int) $row['device_id']][] = $row;
            }
        }

        $pairs = [];
        $vcs = []; // edge index => VC ID => VC with its ends by node
        foreach ($this->layers['pw'] ?? [] as $row) {
            $local = (int) $row['device_id'];
            if (! isset($kept[$local], $nodes['d:' . $local])) {
                continue;
            }

            $vcId = (int) ($row['cpwVcID'] ?? 0);
            [$remote, $ldp, $inferred] = $this->pseudowirePeer($row, $byVc);
            if ($remote === $local) {
                continue;
            }
            $to = $this->farEnd($nodes, $local, $remote, $ldp, 'Pseudowire peer', '', [$ldp]);
            if ($to === null) {
                [$to, $inferred] = [$this->unknownPeer($nodes, $local), false];
            }

            $index = $this->overlayEdge($edges, $pairs, 'pw', 'd:' . $local, $to, ['state' => 'not polled', 'vcs' => [], 'mtu_mismatch' => 0, 'addresses' => []]);
            $vcs[$index][$vcId] ??= [
                'vc' => $vcId,
                'type' => (string) ($row['pw_type'] ?? ''),
                'psn' => (string) ($row['pw_psntype'] ?? ''),
                'description' => (string) ($row['pw_descr'] ?? ''),
                'inferred' => true,
                'ends' => [],
            ];
            // paired by VC ID unless one of its ends found the other
            $vcs[$index][$vcId]['inferred'] = $vcs[$index][$vcId]['inferred'] && $inferred;
            $vcs[$index][$vcId]['ends']['d:' . $local] = $this->pseudowireEnd($row);
            // the other end's own row, also when its device is outside the map
            if ($remote !== null && ($other = $this->pseudowireRow($byVc[$vcId][$remote] ?? [], $local)) !== null) {
                $vcs[$index][$vcId]['ends']['d:' . $remote] ??= $this->pseudowireEnd($other);
            }
            $edges[$index]['addresses'][] = $ldp;
        }

        foreach ($vcs as $index => $list) {
            $edge = &$edges[$index];
            ksort($list);
            foreach ($list as $vc) {
                $a = $vc['ends'][$edge['from']] ?? null;
                $b = $vc['ends'][$edge['to']] ?? null;
                $mismatch = $a !== null && $b !== null && $a['payload'] !== null && $b['payload'] !== null && $a['payload'] !== $b['payload'];
                $edge['vcs'][] = [
                    'vc' => $vc['vc'],
                    'type' => $vc['type'],
                    'psn' => $vc['psn'],
                    'description' => $vc['description'],
                    'inferred' => $vc['inferred'],
                    'a' => $a ? ['port_id' => $a['port_id'], 'port' => $a['port']] : null,
                    'b' => $b ? ['port_id' => $b['port_id'], 'port' => $b['port']] : null,
                    'mtu_a' => $a['mtu'] ?? null,
                    'mtu_b' => $b['mtu'] ?? null,
                    'payload_a' => $a['payload'] ?? null,
                    'payload_b' => $b['payload'] ?? null,
                    'mismatch' => $mismatch,
                ];
                $edge['mtu_mismatch'] += $mismatch ? 1 : 0;
            }
            $edge['state'] = $edge['mtu_mismatch'] ? 'MTU mismatch' : 'not polled';
            $edge['status'] = $edge['mtu_mismatch'] ? 'warning' : 'unknown';
            unset($edge);
        }
        $this->uniqueLists($edges, $pairs, ['addresses']);
    }

    /**
     * Peer of a pseudowire row: the peer device LibreNMS recorded (should it ever), the owner of a usable LDP ID,
     * or the one other device whose row of this VC ID has our LDP ID. When LibreNMS lost the LDP IDs on both ends,
     * it is inferred: the one other device with this VC ID, provided no device the user may not see has it too
     * (it may still be wrong when the real peers are unmonitored PEs that reuse the VC ID).
     *
     * @param  array<int, array<int, array[]>>  $byVc  VC ID => device_id => pseudowire rows the user may see
     * @return array{0: ?int, 1: string, 2: bool} peer device (null when unknown), LDP address ('' when lost), inferred
     */
    private function pseudowirePeer(array $row, array $byVc): array
    {
        $local = (int) $row['device_id'];
        $ldp = self::ldpAddress($row['peer_ldp_id'] ?? null);
        $peer = (int) ($row['peer_device_id'] ?? 0);
        if ($peer > 0 && (isset($this->devices[$peer]) || isset($this->known['devices'][$peer]))) {
            return [$peer, $ldp, false];
        }
        if ($ldp !== '') {
            return [$this->deviceByAddress($ldp), $ldp, false];
        }

        $vcId = (int) ($row['cpwVcID'] ?? 0);
        $pointing = [];
        $lost = [];
        foreach ($byVc[$vcId] ?? [] as $deviceId => $rows) {
            if ($deviceId !== $local && ($other = $this->pseudowireRow($rows, $local)) !== null) {
                if (self::ldpAddress($other['peer_ldp_id'] ?? null) === '') {
                    $lost[] = $deviceId;
                } else {
                    $pointing[] = $deviceId;
                }
            }
        }
        if ($pointing) {
            return [count($pointing) === 1 ? $pointing[0] : null, '', false];
        }
        // devices with this VC ID the user may not see (L3LayerRepository::resolve() counts them all)
        $hidden = (int) ($this->known['vcDevices'][$vcId] ?? 0) - count($byVc[$vcId] ?? []);

        return [count($lost) === 1 && $hidden <= 0 ? $lost[0] : null, '', true];
    }

    /**
     * Among a device's pseudowire rows of one VC ID, the one that can be the other end of a VC of $local: its
     * LDP ID belongs to $local, else it is lost too.
     */
    private function pseudowireRow(array $rows, int $local): ?array
    {
        $lost = null;
        foreach ($rows as $row) {
            $ldp = self::ldpAddress($row['peer_ldp_id'] ?? null);
            if ($ldp === '') {
                $lost ??= $row;
            } elseif ($this->deviceByAddress($ldp) === $local) {
                return $row;
            }
        }

        return $lost;
    }

    /**
     * One end of a pseudowire: its attachment circuit, that port's MTU and the MTU it signals (null when unknown).
     */
    private function pseudowireEnd(array $row): array
    {
        $deviceId = (int) $row['device_id'];
        $port = self::portName($row);
        $mtu = (int) ($row['ifMtu'] ?? 0) ?: null;

        return [
            'port_id' => (int) ($row['port_id'] ?? 0) ?: null,
            'port' => $port,
            'mtu' => $mtu,
            'payload' => self::payloadMtu($mtu, (string) ($this->devices[$deviceId]['os'] ?? $this->known['devices'][$deviceId]['os'] ?? ''), $port),
        ];
    }

    /**
     * MTU an attachment circuit signals for its pseudowire, which must be the same at both ends: the interface MTU,
     * without the Ethernet header (and the VLAN tag of a subinterface) on the systems that count them in it.
     */
    public static function payloadMtu(?int $mtu, string $os, string $port): ?int
    {
        $header = self::MTU_HEADER[$os] ?? 0;
        if ($mtu === null || $header === 0) {
            return $mtu;
        }

        return $mtu - $header - (InterfaceName::isSubInterface($port) ? self::VLAN_TAG : 0);
    }

    /**
     * External node for the pseudowires of a PE whose peer is unknown: one per PE, never shared between PEs.
     */
    private function unknownPeer(array &$nodes, int $local): string
    {
        $id = 'xu:pw:' . $local;
        $nodes[$id] ??= [
            'id' => $id,
            'type' => 'external',
            'managed' => false,
            'label' => 'unknown peers',
            'name' => 'Unknown pseudowire peers of ' . ($nodes['d:' . $local]['name'] ?? $local),
            'role' => DeviceRole::ROUTER,
            'status' => 'unknown',
            'address' => '',
            'context' => '',
            'seen' => ['Pseudowire peer'],
            'addresses' => [],
        ];

        return $id;
    }

    /**
     * Node of an overlay's far end, created on first use: the device's node when it is on the map; a stub
     * (outside: true) of a device the user may see that is not on this map (beyond the hops, filtered out by
     * location, group or role) while MAX_STUBS / maxNodes allow; else an external node (see external()). Null
     * for the local device itself and for an unknown far end without an address.
     *
     * @param  string  $key  what identifies an unknown far end: its address (router ID, peer, tail...) or IS-IS system ID
     * @param  string  $seen  as what, e.g. "OSPF neighbor"
     * @param  array<int, string|null>  $addresses  the far end's addresses known from the row
     */
    private function farEnd(array &$nodes, int $local, ?int $remote, string $key, string $seen, string $context = '', array $addresses = [], string $role = DeviceRole::ROUTER): ?string
    {
        if ($remote === $local) {
            return null;
        }
        if ($remote !== null) {
            $id = 'd:' . $remote;
            $device = $this->devices[$remote] ?? $this->known['devices'][$remote] ?? null;
            if (! isset($nodes[$id]) && $device !== null) {
                $this->outside[$remote] = true;
                if ($this->stubBudget > 0) {
                    $this->stubBudget--;
                    $nodes[$id] = $this->deviceNode($remote, [], $device) + ['outside' => true];
                }
            }
            if (isset($nodes[$id])) {
                return $id;
            }
        }

        return $key === '' ? null : $this->external($nodes, $key, $seen, $context, $addresses, $role);
    }

    /**
     * Stub of a device beyond the hops (its rows were loaded) for its own row about a drawn far end, so that the
     * edge has both sides (states, tunnels, interfaces); null otherwise.
     */
    private function stubSide(array &$nodes, array $kept, int $local, ?int $remote): ?string
    {
        if ($remote === null || $remote === $local || ! isset($kept[$remote], $nodes['d:' . $remote], $this->devices[$local])) {
            return null;
        }

        return $this->farEnd($nodes, $remote, $local, '', '');
    }

    /**
     * External node of a far end that is no device the user may see, keyed by address (in its context): an
     * unmonitored router seen as OSPF neighbor, LSP tail, pseudowire and IPsec peer is one node. Its other
     * addresses (an OSPF neighbour's interface address) find it too. A firewall icon only while nothing but
     * IPsec saw it.
     *
     * @param  array<int, string|null>  $addresses
     */
    private function external(array &$nodes, string $key, string $seen, string $context, array $addresses, string $role): string
    {
        $prefix = $context !== '' ? $context . '|' : '';
        $key = Cidr::canonical($key) ?? $key;
        $known = [];
        foreach ($addresses as $address) {
            $address = Cidr::canonical((string) $address);
            if ($address !== null && ! Cidr::isIgnorable($address)) {
                $known[$address] = true;
            }
        }
        $keys = array_unique(array_merge([$prefix . $key], array_map(fn ($a) => $prefix . $a, array_keys($known))));

        $id = null;
        foreach ($keys as $lookup) {
            if (isset($this->externals[$lookup])) {
                $id = $this->externals[$lookup];
                break;
            }
        }
        if ($id === null) {
            $id = 'x:' . $prefix . $key;
            $nodes[$id] = [
                'id' => $id,
                'type' => 'external',
                'managed' => false,
                'label' => $key . ($context !== '' ? "\n" . $context : ''),
                'name' => $key,
                'role' => $role,
                'status' => 'unknown',
                'address' => (string) array_key_first($known),
                'context' => $context,
                'seen' => [],
                'addresses' => [],
            ];
        }
        foreach ($keys as $lookup) {
            $this->externals[$lookup] ??= $id;
        }

        $node = &$nodes[$id];
        if ($node['role'] !== $role) {
            $node['role'] = DeviceRole::ROUTER;
        }
        $node['seen'] = array_values(array_unique(array_merge($node['seen'], [$seen])));
        $node['addresses'] = array_values(array_unique(array_merge($node['addresses'], array_keys($known))));
        if ($node['address'] === '' && $known) {
            $node['address'] = (string) array_key_first($known);
        }
        unset($node);

        return $id;
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
     * Device owning an address, looked up in a VRF (the global table by default): a device of this map with it
     * in that VRF; else the owner among the devices the user may see (L3LayerRepository::resolve(): in the global
     * table first; for a customer VRF only one with it in that VRF, as such address space overlaps); else a device
     * of this map with it in another VRF. Nobody owns an address found on several devices at the same step, nor a
     * link-local or other ignorable one (the same on many routers).
     */
    private function deviceByAddress(string $address, string $vrf = ''): ?int
    {
        $cache = $vrf . '|' . $address;
        if (array_key_exists($cache, $this->resolved)) {
            return $this->resolved[$cache];
        }

        $canonical = Cidr::canonical($address);
        if ($canonical === null || Cidr::isIgnorable($canonical)) {
            return $this->resolved[$cache] = null;
        }
        $owners = $this->owners()[$canonical] ?? [];
        $inVrf = array_keys(array_filter($owners, fn ($vrfs) => isset($vrfs[$vrf])));
        $device = match (true) {
            $inVrf !== [] => count($inVrf) === 1 ? (int) $inVrf[0] : null,
            $vrf === '' && isset($this->known['addresses'][$canonical]) => (int) $this->known['addresses'][$canonical],
            $vrf !== '' && isset($this->known['vrfAddresses'][$vrf . '|' . $canonical]) => (int) $this->known['vrfAddresses'][$vrf . '|' . $canonical],
            default => count($owners) === 1 ? (int) array_key_first($owners) : null,
        };

        return $this->resolved[$cache] = $device;
    }

    /**
     * Device with a loopback (or host route) address: an IS-IS system ID is only trusted when the address
     * it encodes is one.
     */
    private function deviceByLoopback(string $address): ?int
    {
        $owners = $this->owners()[$address] ?? [];
        $loopbacks = $this->loopbackOwners[$address] ?? [];
        $global = array_keys(array_filter($loopbacks));

        return match (true) {
            $global !== [] => count($global) === 1 ? (int) $global[0] : null,
            isset($this->known['loopbacks'][$address]) => (int) $this->known['loopbacks'][$address],
            // only in a VRF here, or an interface address of this map
            default => count($loopbacks) === 1 && count($owners) === 1 ? (int) array_key_first($loopbacks) : null,
        };
    }

    /**
     * Device with an OSPF / OSPFv3 router ID in a context: the one whose instance has it, else the owner of
     * that address (router IDs are usually loopbacks), not when several instances have it.
     */
    private function deviceByRouterId(string $rid, string $context): ?int
    {
        $key = $context . '|' . $rid;
        if (isset($this->known['routerIds'][$key])) {
            return (int) $this->known['routerIds'][$key];
        }

        return isset($this->known['ambiguousRouterIds'][$key]) ? null : $this->deviceByAddress($rid, $context);
    }

    /**
     * Is an OSPF or BGP row one of the topology history (History\PastMap, a map of the past)? Its neighbour is the
     * device the history recorded (recordedNeighbor()), not the one with its address today.
     */
    private static function fromHistory(array $row): bool
    {
        return array_key_exists('neighbor_device_id', $row);
    }

    /**
     * The neighbour device a row of the history recorded; null: none then (an unmonitored neighbour, external).
     */
    private static function recordedNeighbor(array $row): ?int
    {
        return $row['neighbor_device_id'] !== null ? (int) $row['neighbor_device_id'] : null;
    }

    /**
     * The worse of two protocol states by rank (unknown states are the worst): the state of an adjacency or
     * session merged from both ends, whatever the order of the rows.
     *
     * @param  array<string, int>  $rank
     */
    private static function worse(string $a, string $b, array $rank): string
    {
        return [$rank[$b] ?? 0, $b] < [$rank[$a] ?? 0, $a] ? $b : $a;
    }

    /**
     * A router ID as a canonical address, null when there is none (empty, 0.0.0.0...).
     */
    private static function routerKey(string $rid): ?string
    {
        $rid = Cidr::canonical($rid);

        return $rid !== null && ! Cidr::isIgnorable($rid) ? $rid : null;
    }

    /**
     * Router ID of an OSPFv3 neighbour: LibreNMS stores it dotted (router_id) and as a number; before
     * router_id existed (2025-03) the second column held it.
     */
    private static function ospfv3RouterId(array $row): string
    {
        $rid = trim((string) ($row['router_id'] ?? ''));
        $other = trim((string) ($row['ospfv3NbrRtrId'] ?? ''));
        if ($rid === '' && $other !== '') {
            $rid = ctype_digit($other) ? (string) long2ip((int) $other) : $other;
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
     * Peer LDP router ID of a pseudowire as an address, '' when LibreNMS lost it: it stores the ID in a signed
     * INT, so every ID from 128.0.0.0 on reads 2147483647 (127.255.255.255), and an unknown one 0.
     */
    private static function ldpAddress(mixed $value): string
    {
        if (! is_numeric($value) || (int) $value <= 0 || (int) $value >= self::LDP_CAPPED) {
            return '';
        }

        return (string) long2ip((int) $value);
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
     * routers and L3 switches; never a stub of a device outside the map).
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
                    $rank = $node['type'] === 'as' ? 9 : ($node['type'] === 'device' && empty($node['outside']) ? (self::ROLE_RANK[$node['role']] ?? 1) : 0);
                    $score = $rank * 10000 + $graph->degree($id);
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
