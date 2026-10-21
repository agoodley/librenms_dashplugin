<?php

namespace Agoodley\LibrenmsTopology\Path;

use Agoodley\LibrenmsTopology\Data\PathRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Hosts\HostService;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * What a path trace reads of the network the user may see, and no more: for each Layer 2 segment, the LLDP/CDP links
 * outward from both of its ends until its shortest forwarding path is certain (L2Tracer::horizon()), with the few port
 * columns, the VLAN rows of the traced VLAN and the spanning tree rows of its instance on those links; for the routed path
 * what L3Tracer asks as it walks (L3Data): the interfaces of the routers it reaches, the owners and subnets of an address
 * by indexed lookups, the neighbors of the routers an estimate looks at, the routes toward each address routed to.
 * Per-hop details (counters, URLs) are read for the hops of the answer only. Kept for the traces of one request.
 *
 * What the user's LibreNMS role may not view is not read (allows()): without LLDP/CDP links no Layer 2 path is found,
 * without ports none is shown and links count as up, without routes every router is estimated.
 */
class PathNetwork implements L3Data
{
    public const ROUTING_ROLES = L3Tracer::ROUTING_ROLES;

    /** Port columns of the Layer 2 graph (L2Tracer, L2Builder::uniqueLinks()). */
    public const GRAPH_COLUMNS = ['port_id', 'device_id', 'ifName', 'ifDescr', 'ifType', 'ifSpeed', 'ifOperStatus', 'ifVlan', 'ifTrunk'];

    /** Nodes whose links a request reads at most: the backstop of a search that cannot stop earlier. */
    public const MAX_EXPANDED = 3000;

    /** ... when looking for the links that explain a missing path (they are listed, not followed). */
    private const CHAIN_EXPANDED = 800;

    /** Addresses whose routes a request reads at most (a route table scan each: the destination, recursive next hops). */
    public const ROUTE_READS = 6;

    /** @var array<int, array|null> device_id => row (role, display, url), null when the user may not see it */
    private array $devices = [];

    /** @var array<int, array> link id => row */
    private array $links = [];

    /** @var array<string, true> nodes whose links are loaded */
    private array $expanded = [];

    /** @var array<int, array> port_id => graph columns */
    private array $graphPorts = [];

    /** @var array<int, int> member port_id => aggregate port_id */
    private array $lag = [];

    /** @var array<int, true> devices whose port-channels are read */
    private array $lagDevices = [];

    /** @var array<int, true> */
    private array $routed = [];

    /** @var array<int, true> ports with VLAN rows */
    private array $vlanPorts = [];

    /** @var array<int, array<int, bool>> VLAN => port_id => untagged, for the loaded ports that carry it */
    private array $vlanRows = [];

    /** @var array<int, array<int, true>> VLAN => ports read */
    private array $vlanRead = [];

    /** Grows with every load of links: tracers and spanning tree indexes of an older version are rebuilt. */
    private int $version = 0;

    /** Grows with the ports whose spanning tree state is asked for: spanning tree indexes are rebuilt. */
    private int $wanted = 0;

    /** @var array<string, array{0: int, 1: L2Tracer}> */
    private array $tracers = [];

    /** @var array<int, array{instances: array<int, array>, devices: array<int, true>, ports: array<int, array>, read: array<int, true>, index: ?StpIndex, version: ?string}> */
    private array $stp = [];

    /** @var array<int, true> ports whose spanning tree state is needed beyond the links (ends of segments, hops) */
    private array $stpWanted = [];

    /** @var array<int, array|null> port_id => full row (counters, url), null when not visible */
    private array $ports = [];

    /** @var array<int, array> device_id => its interface addresses (link-local ones included) */
    private array $interfaces = [];

    /** @var array<int, string[]> device_id => addresses LibreNMS polls it on */
    private array $polled = [];

    private ?L3Tracer $l3 = null;

    private ?array $routeData = null;

    private ?array $routeTable = null;

    /** @var array<string, ?array> address => routes per device */
    private array $routes = [];

    public function __construct(
        private readonly PathRepository $repo,
        private readonly TopologyRepository $topology,
        private readonly PluginSettings $settings,
    ) {
    }

    // ------------------------------------------------------------------ devices and ports

    public function canSee(int $deviceId): bool
    {
        return $this->repo->canSee($deviceId);
    }

    /**
     * May the user's LibreNMS role view this table's data (ScopedRepository::allowsTable())?
     */
    public function allows(string $table): bool
    {
        return $this->repo->allowsTable($table);
    }

    /**
     * What the user's role may not view among these tables ("LLDP/CDP links", ...).
     *
     * @return string[]
     */
    public function roleHides(string ...$tables): array
    {
        return $this->repo->hiddenKinds($tables);
    }

    /**
     * Row of a device the user may see (role, display name, url), null otherwise.
     */
    public function device(?int $deviceId): ?array
    {
        if ($deviceId === null) {
            return null;
        }
        $this->loadDevices([$deviceId]);

        return $this->devices[$deviceId];
    }

    /**
     * @param  int[]  $deviceIds
     */
    public function loadDevices(array $deviceIds): void
    {
        $missing = array_values(array_filter(array_unique(array_map('intval', $deviceIds)), fn ($id) => $id > 0 && ! array_key_exists($id, $this->devices)));
        if (! $missing) {
            return;
        }
        $visible = array_values(array_filter($missing, fn ($id) => $this->repo->canSee($id)));
        $rows = $visible ? $this->topology->devices($visible) : [];
        foreach ($missing as $id) {
            $this->devices[$id] = $rows[$id] ?? null;
        }
    }

    public function name(?int $deviceId): string
    {
        $device = $this->device($deviceId);

        return $device ? (string) (($device['display'] ?? '') ?: ($device['hostname'] ?? $deviceId)) : 'device #' . $deviceId;
    }

    public function role(?int $deviceId): ?string
    {
        return $this->device($deviceId)['role'] ?? null;
    }

    /**
     * Names of the devices among $ids the user may see.
     *
     * @param  int[]  $ids
     * @return array<int, string>
     */
    public function names(array $ids): array
    {
        $this->loadDevices($ids);
        $names = [];
        foreach (array_unique($ids) as $id) {
            if ($this->devices[$id] ?? null) {
                $names[$id] = $this->name($id);
            }
        }

        return $names;
    }

    /**
     * A port row with its counters and URL, null when it is not a port of a device the user may see.
     */
    public function port(?int $portId): ?array
    {
        if (! $portId) {
            return null;
        }
        $this->loadPorts([$portId]);

        return $this->ports[$portId];
    }

    /**
     * Read the ports not read yet in one query.
     *
     * @param  int[]  $portIds
     */
    public function loadPorts(array $portIds): void
    {
        $missing = array_values(array_filter(array_unique(array_map('intval', $portIds)), fn ($id) => $id > 0 && ! array_key_exists($id, $this->ports)));
        if (! $missing) {
            return;
        }
        $rows = $this->allows('ports') ? $this->topology->ports($missing) : [];
        $this->loadDevices(array_column($rows, 'device_id'));
        foreach ($missing as $portId) {
            $port = $rows[$portId] ?? null;
            $this->ports[$portId] = $port !== null && ($this->devices[(int) $port['device_id']] ?? null) !== null ? $port : null;
        }
    }

    public function portName(?int $portId): string
    {
        $port = $this->port($portId);

        return $port ? InterfaceName::short(InterfaceName::label($port)) : 'port #' . $portId;
    }

    /**
     * Discard counters of the last poll.
     *
     * @param  int[]  $portIds
     */
    public function statistics(array $portIds): array
    {
        return $portIds && $this->allows('ports_statistics') ? $this->topology->portStatistics($portIds) : [];
    }

    /**
     * The physical port and VLAN behind a router interface: an SVI (Vlan10, irb.10) has no port of its own, a
     * sub-interface (Gi0/0.130, ae0.100) is its parent port in that VLAN, any other port is itself (no VLAN).
     *
     * @return array{0: ?int, 1: ?int}
     */
    public function l2Port(int $deviceId, ?int $portId): array
    {
        $port = $this->port($portId);
        if ($port === null) {
            return [null, null];
        }
        $name = InterfaceName::label($port);
        $vlan = HostService::interfaceVlan($name);
        if (preg_match('#^(.+)\.(\d{1,4})$#', $name, $match) && ! preg_match('/^(irb|vlan)$/i', $match[1])) {
            $parent = $this->repo->portsByName($deviceId, [$match[1]])[strtolower($match[1])] ?? null;
            $tag = is_numeric($port['ifVlan'] ?? null) && (int) $port['ifVlan'] > 0 ? (int) $port['ifVlan'] : $vlan;

            return [$parent, $tag];
        }
        if ($vlan !== null || ($port['ifType'] ?? '') === 'l3ipvlan') {
            return [null, $vlan];
        }

        return [$portId, null];
    }

    // ------------------------------------------------------------------ Layer 2

    /**
     * The forwarding path between two devices in a VLAN. The links are read outward from both ends, the end with fewer
     * switches to read first, until the path found is the shortest (every path that could be as short is loaded) or one
     * end has nothing left to read; without a path, on until the chains of links between them (whatever their state) are
     * known, to tell what stops them.
     *
     * @param  array{leave?: ?int, arrive?: ?int, enter?: ?int, exit?: ?int}  $ports  see L2Tracer::trace()
     * @return array{trace: array, blockers: array, capped: bool}
     */
    public function l2(int $from, int $to, ?int $vlan, array $ports): array
    {
        $this->wantStp(array_filter([$ports['enter'] ?? null, $ports['exit'] ?? null, $ports['leave'] ?? null, $ports['arrive'] ?? null]));
        $this->loadDevices([$from, $to]);
        $this->expand(['d:' . $from, 'd:' . $to]);
        $source = 'd:' . $from;
        $target = 'd:' . $to;
        $ends = [$ports['leave'] ?? null, $ports['arrive'] ?? null];
        $capped = false;

        while (true) {
            $tracer = $this->tracer($vlan);
            $trace = $tracer->trace($from, $to, $vlan, $ports);
            $near = $tracer->horizon($source, $vlan, $ports['leave'] ?? null, $ends, $this->expanded);
            $far = $tracer->horizon($target, $vlan, $ports['arrive'] ?? null, $ends, $this->expanded);
            if ($near['radius'] === null || $far['radius'] === null
                || ($trace['found'] && count($trace['hops']) - 1 <= $near['radius'] + $far['radius'] + 1)) {
                break;
            }
            if (count($this->expanded) >= self::MAX_EXPANDED) {
                $capped = true;
                break;
            }
            $this->expand(count($near['frontier']) <= count($far['frontier']) ? $near['frontier'] : $far['frontier']);
        }
        if ($trace['found']) {
            return ['trace' => $trace, 'blockers' => [], 'capped' => $capped];
        }

        // no forwarding path: the shortest chains of links between them tell what stops it
        while (true) {
            $tracer = $this->tracer($vlan);
            $near = $tracer->horizon($source, null, null, [], $this->expanded, false);
            $far = $tracer->horizon($target, null, null, [], $this->expanded, false);
            $chain = $near['distances'][$target] ?? null;
            if ($near['radius'] === null || $far['radius'] === null || ($chain !== null && $chain <= $near['radius'] + $far['radius'] + 1)
                || count($this->expanded) >= self::CHAIN_EXPANDED) {
                break;
            }
            $this->expand(count($near['frontier']) <= count($far['frontier']) ? $near['frontier'] : $far['frontier']);
        }

        return ['trace' => $trace, 'blockers' => $tracer->blockers($from, $to, $vlan), 'capped' => $capped];
    }

    /**
     * The link between two devices on the given ports (any link between them for null ports).
     */
    public function directEdge(int $from, int $to, ?int $portA, ?int $portB): ?array
    {
        $this->loadDevices([$from, $to]);
        $this->expand(['d:' . $from, 'd:' . $to]);

        return $this->tracer(null)->directEdge($from, $to, $portA, $portB);
    }

    /**
     * Devices the user may see joined by LLDP/CDP on a port of a device (the physical port of a sub-interface): their
     * port on that link (null when not known).
     *
     * @return array<int, ?int>
     */
    public function linkedDevices(int $deviceId, int $portId): array
    {
        [$physical] = $this->l2Port($deviceId, $portId);
        if ($physical === null) {
            return [];
        }
        $this->loadDevices([$deviceId]);
        $this->expand(['d:' . $deviceId]);
        $ports = [$physical => true] + (isset($this->lag[$physical]) ? [$this->lag[$physical] => true] : []);
        foreach ($this->lag as $member => $aggregate) {
            if ($aggregate === $physical) {
                $ports[$member] = true;
            }
        }
        $found = [];
        foreach ($this->links as $link) {
            if ((int) $link['local_device_id'] === $deviceId && isset($ports[(int) $link['local_port_id']]) && (int) $link['remote_device_id'] > 0) {
                $found[(int) $link['remote_device_id']] ??= ((int) $link['remote_port_id']) ?: null;
            } elseif ((int) $link['remote_device_id'] === $deviceId && isset($ports[(int) $link['remote_port_id']])) {
                $found[(int) $link['local_device_id']] ??= ((int) $link['local_port_id']) ?: null;
            }
        }
        unset($found[$deviceId]);

        return $found;
    }

    /**
     * The L2 tracer over the loaded part of the network, with the spanning tree instance and VLAN rows of a VLAN.
     */
    public function tracer(?int $vlan): L2Tracer
    {
        $key = (string) ($vlan ?? 'default');
        if (($this->tracers[$key][0] ?? null) !== $this->version) {
            $portVlans = [];
            if ($vlan !== null) {
                $missing = array_values(array_diff(array_keys($this->vlanPorts), array_keys($this->vlanRead[$vlan] ?? [])));
                if ($missing) {
                    $this->vlanRows[$vlan] = ($this->vlanRows[$vlan] ?? []) + $this->repo->portVlans($missing, $vlan);
                    $this->vlanRead[$vlan] = ($this->vlanRead[$vlan] ?? []) + array_fill_keys($missing, true);
                }
                foreach ($this->vlanPorts as $portId => $true) {
                    $portVlans[$portId] = isset($this->vlanRows[$vlan][$portId]) ? [$vlan => $this->vlanRows[$vlan][$portId]] : [];
                }
            }
            $devices = array_filter($this->devices);
            $this->tracers[$key] = [$this->version, new L2Tracer($devices, $this->links, $this->graphPorts, $this->lag, $this->stp($vlan), $portVlans, $this->routed)];
        }

        return $this->tracers[$key][1];
    }

    /**
     * Spanning tree state of a port in the instance of the VLAN.
     *
     * @return array{state: string, role: string, vlan: ?int}|null see StpIndex::portStatus()
     */
    public function stpStatus(int $deviceId, ?int $portId, ?int $vlan): ?array
    {
        if (! $portId) {
            return null;
        }
        $this->wantStp([$portId]);
        $this->loadDevices([$deviceId]);

        return $this->stp($vlan)->portStatus($deviceId, $portId, $vlan);
    }

    /**
     * Devices among $deviceIds whose state the trace takes from their only spanning tree instance for a VLAN that is
     * not VLAN 1 (MSTP and RSTP bridges other than Cisco's have only the common instance in LibreNMS).
     *
     * @param  int[]  $deviceIds
     * @return int[]
     */
    public function commonInstance(array $deviceIds, ?int $vlan): array
    {
        if (StpIndex::vlanKey($vlan) === 0) {
            return [];
        }
        $index = $this->stp($vlan);

        return array_values(array_filter(array_unique($deviceIds), fn ($id) => $index->keyFor($id, $vlan) === 0));
    }

    /**
     * @param  int[]  $portIds
     */
    public function wantStp(array $portIds): void
    {
        foreach ($portIds as $portId) {
            if ($portId && ! isset($this->stpWanted[(int) $portId])) {
                $this->stpWanted[(int) $portId] = true;
                $this->wanted++;
            }
        }
    }

    /**
     * Read the links at some nodes (devices, devices out of sight, unmanaged neighbors) with what the tracer needs of
     * them: the rows of the devices they reach, their ports and port-channels.
     *
     * @param  string[]  $nodes
     */
    private function expand(array $nodes): void
    {
        $deviceIds = [];
        $hidden = [];
        $names = [];
        foreach ($nodes as $node) {
            if (isset($this->expanded[$node])) {
                continue;
            }
            $this->expanded[$node] = true;
            $rest = substr($node, 2);
            match (substr($node, 0, 2)) {
                'd:' => $deviceIds[] = (int) $rest,
                'h:' => $hidden[] = (int) $rest,
                default => $names[] = $rest,
            };
        }
        $rows = $deviceIds || $hidden || $names ? array_diff_key($this->repo->linksAt($deviceIds, $hidden, $names), $this->links) : [];
        if (! $rows) {
            return;
        }
        $this->links += $rows;

        $reached = [];
        $linkPorts = [];
        foreach ($rows as $link) {
            $reached[] = (int) $link['local_device_id'];
            $reached[] = (int) $link['remote_device_id'];
            $linkPorts[] = (int) $link['local_port_id'];
            $linkPorts[] = (int) ($link['remote_port_id'] ?? 0);
        }
        $this->loadDevices($reached);
        $newDevices = array_values(array_filter(array_unique($reached), fn ($id) => $id > 0 && ($this->devices[$id] ?? null) !== null && ! isset($this->lagDevices[$id])));
        if ($newDevices) {
            $this->lag += $this->allows('ports_stack') && $this->allows('ports') ? $this->topology->lagParents($newDevices) : [];
            $this->lagDevices += array_fill_keys($newDevices, true);
        }
        $linkPorts = array_values(array_filter(array_unique($linkPorts)));
        $aggregates = array_values(array_unique(array_filter(array_map(fn ($port) => $this->lag[$port] ?? null, $linkPorts))));
        $members = array_keys(array_intersect($this->lag, $aggregates));
        $missing = array_values(array_diff(array_unique(array_merge($linkPorts, $aggregates, $members)), array_keys($this->graphPorts)));
        if ($missing) {
            $this->graphPorts += $this->allows('ports') ? $this->topology->ports($missing, self::GRAPH_COLUMNS) : [];
            $this->vlanPorts += $this->repo->portsWithVlans($missing);
            foreach ($this->repo->portsWithIp(array_values(array_intersect($missing, array_merge($linkPorts, $aggregates)))) as $portId => $true) {
                $port = $this->graphPorts[$portId] ?? [];
                if (! isset($this->vlanPorts[$portId]) && empty($port['ifTrunk']) && ! (is_numeric($port['ifVlan'] ?? null) && (int) $port['ifVlan'] > 0)) {
                    $this->routed[$portId] = true;
                }
            }
        }
        $this->version++;
    }

    /**
     * Spanning tree index of the loaded devices in the instance that carries the VLAN, with the states of the loaded
     * link ports and the other ports asked for.
     */
    private function stp(?int $vlan): StpIndex
    {
        $key = StpIndex::vlanKey($vlan);
        $this->stp[$key] ??= ['instances' => [], 'devices' => [], 'ports' => [], 'read' => [], 'index' => null, 'version' => null];
        $stp = &$this->stp[$key];
        if ($stp['version'] === $this->version . ':' . $this->wanted) {
            return $stp['index'];
        }

        $devices = array_keys(array_filter($this->devices));
        $newDevices = array_values(array_diff($devices, array_keys($stp['devices'])));
        if ($newDevices) {
            foreach ($this->repo->stpInstances($newDevices, $vlan) as $row) {
                $stp['instances'][] = $row;
            }
            $stp['devices'] += array_fill_keys($newDevices, true);
        }
        $selector = new StpIndex($stp['instances']);
        $portIds = array_merge(array_keys($this->graphPorts), array_keys($this->stpWanted));
        $missing = array_values(array_diff(array_unique($portIds), array_keys($stp['read'])));
        if ($missing && $selector->deviceIds()) {
            $keys = [];
            foreach ($selector->deviceIds() as $deviceId) {
                $keys[$deviceId] = $selector->keyFor($deviceId, $vlan);
            }
            foreach ($this->repo->stpPorts($keys, $missing) as $row) {
                $stp['ports'][] = $row;
            }
        }
        $stp['read'] += array_fill_keys($missing, true);
        $stp['index'] = new StpIndex($stp['instances'], $stp['ports'], $this->lag);
        $stp['version'] = $this->version . ':' . $this->wanted;

        return $stp['index'];
    }

    // ------------------------------------------------------------------ Layer 3 (L3Data)

    /**
     * Owners of addresses, gateways and the routed path, on what L3Tracer asks of this network when it needs it.
     */
    public function l3(): L3Tracer
    {
        return $this->l3 ??= new L3Tracer($this);
    }

    public function interfaces(array $deviceIds): array
    {
        $missing = array_values(array_diff(array_unique(array_map('intval', $deviceIds)), array_keys($this->interfaces)));
        if ($missing) {
            $rows = $this->repo->interfaces($missing);
            $this->interfaces += array_fill_keys($missing, []);
            foreach ($rows as $row) {
                $this->interfaces[$row['device_id']][] = $row;
            }
        }

        return array_merge([], ...array_values(array_intersect_key($this->interfaces, array_flip($deviceIds))));
    }

    public function owners(array $addresses): array
    {
        return $this->repo->ownersOf($addresses);
    }

    public function subnets(string $address): array
    {
        return $this->repo->subnets($address);
    }

    public function peers(array $interfaces): array
    {
        return $this->repo->peers($interfaces);
    }

    public function adjacencies(array $deviceIds): array
    {
        $deviceIds = array_values(array_filter(array_unique(array_map('intval', $deviceIds)), fn ($id) => $this->repo->canSee($id)));
        if (! $deviceIds) {
            return [];
        }
        $rows = [];
        foreach ($this->allows('ospf_nbrs') ? $this->topology->ospfNeighbors($deviceIds) : [] as $row) {
            if (strtolower((string) $row['ospfNbrState']) === 'full') {
                $rows[] = ['device_id' => (int) $row['device_id'], 'port_id' => (int) ($row['port_id'] ?? 0) ?: null, 'local_ip' => null,
                    'neighbor_ip' => (string) $row['ospfNbrIpAddr'], 'kind' => 'ospf', 'vrf' => (string) ($row['context_name'] ?? '')];
            }
        }
        foreach ($this->allows('bgpPeers') ? $this->topology->bgpPeers($deviceIds) : [] as $row) {
            if (strtolower((string) $row['bgpPeerState']) === 'established') {
                $rows[] = ['device_id' => (int) $row['device_id'], 'port_id' => null, 'local_ip' => (string) ($row['bgpLocalAddr'] ?? ''),
                    'neighbor_ip' => (string) $row['bgpPeerIdentifier'], 'kind' => 'bgp', 'vrf' => (string) ($row['context_name'] ?? '')];
            }
        }

        return $rows;
    }

    public function roles(array $deviceIds): array
    {
        $this->loadDevices($deviceIds);
        $roles = [];
        foreach ($deviceIds as $id) {
            if (($this->devices[(int) $id] ?? null) !== null) {
                $roles[(int) $id] = (string) ($this->devices[(int) $id]['role'] ?? DeviceRole::UNKNOWN);
            }
        }

        return $roles;
    }

    public function linked(int $deviceId, int $portId): array
    {
        return $this->linkedDevices($deviceId, $portId);
    }

    /**
     * Routes of the devices with route data that cover an address (null once ROUTE_READS addresses were read).
     */
    public function routes(string $address): ?array
    {
        if (! array_key_exists($address, $this->routes)) {
            $data = $this->routeData();
            $this->routes[$address] = count($this->routes) >= self::ROUTE_READS ? null : ($data ? $this->repo->routesToward($address, array_keys($data)) : []);
        }

        return $this->routes[$address];
    }

    /**
     * Devices with routes in LibreNMS (see PathRepository::routeData()), none when the table is not read.
     */
    public function routeData(): array
    {
        $table = $this->routeTable();

        return $this->routeData ??= $table['skipped'] || $table['hidden'] ? [] : $this->repo->routeData($this->topology->deviceIds());
    }

    public function skipped(): bool
    {
        return $this->routeTable()['skipped'];
    }

    public function routesHidden(): bool
    {
        return $this->routeTable()['hidden'];
    }

    /**
     * Interface addresses of a device (any role).
     *
     * @return array<int, array{device_id: int, port_id: int, address: string, prefixlen: int, vrf: string, up: bool, network_id: int}>
     */
    public function interfacesOf(int $deviceId): array
    {
        return $this->interfaces([$deviceId]);
    }

    /**
     * Addresses LibreNMS polls a device on.
     *
     * @return string[]
     */
    public function pollAddresses(int $deviceId): array
    {
        return $this->polled[$deviceId] ??= $this->repo->device($deviceId)['ips'] ?? [];
    }

    /**
     * Is the route table read: not when the user's role may not view routes (hidden), skipped when LibreNMS holds more
     * routes than the path_route_rows setting allows.
     *
     * @return array{hidden: bool, skipped: bool, size: ?int, limit: int}
     */
    public function routeTable(): array
    {
        if ($this->routeTable === null) {
            $limit = $this->settings->int('path_route_rows', 0);
            $hidden = ! $this->allows('route');
            $size = $hidden ? null : $this->repo->routeTableSize();
            $this->routeTable = ['hidden' => $hidden, 'skipped' => $size !== null && $limit > 0 && $size > $limit, 'size' => $size, 'limit' => $limit];
        }

        return $this->routeTable;
    }
}
