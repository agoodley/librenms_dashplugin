<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Hosts\HostQuery;
use Agoodley\LibrenmsTopology\Path\L2Tracer;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\Cidr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Database access of the path trace that the other repositories do not have, sized to what one trace needs: the links at
 * some nodes (a device out of the user's sight stays recognizable without being revealed), the VLAN rows of one VLAN on
 * some ports, the interfaces of the routing devices, the owners of an address, and the route table (LibreNMS' `route`,
 * which has no index but its primary key: its per-device summary is cached, and it is scanned once per address routed to).
 * Every read leaves out what the user's LibreNMS role may not view (ScopedRepository::allowsTable()).
 */
class PathRepository extends ScopedRepository
{
    /** Seconds the per-device summary of the route table is kept (it changes at discovery only). */
    public const ROUTE_DATA_SECONDS = 300;

    private const ROUTE_DATA_KEY = 'topology-plugin.path.route-summary';

    /** @var array<int, int> device_id of a device out of the user's sight => its number in this request */
    private array $hiddenNumber = [];

    /** @var array<int, int> number => device_id */
    private array $hiddenDevice = [];

    /**
     * Active LLDP/CDP links at some nodes of the Layer 2 graph, reported by devices the user may see: the links of the
     * devices (reported by them or about them), the links reported about the devices out of sight numbered $hidden, and
     * the links toward the unmanaged neighbors named $names. A remote device the user may not see loses its ids, port
     * and name: its remote_hostname becomes L2Tracer::HIDDEN . <n>, the same n for every link to it in this request.
     *
     * @param  int[]  $deviceIds
     * @param  int[]  $hidden
     * @param  string[]  $names
     * @return array<int, array> link id => row
     */
    public function linksAt(array $deviceIds, array $hidden = [], array $names = []): array
    {
        if (! $this->allowsTable('links')) {
            return [];
        }
        $deviceIds = $this->visible($deviceIds);
        $hiddenIds = $this->hiddenDeviceIds($hidden);
        $names = array_values(array_unique(array_filter(array_map('strval', $names), fn ($name) => $name !== '')));

        // one query per part: the links of the devices, the links about them (also by the hidden ones' ids), the links to the named neighbors
        $parts = [];
        foreach (array_chunk($deviceIds, 2000) as $chunk) {
            $parts[] = fn ($query) => $query->whereIntegerInRaw('local_device_id', $chunk);
        }
        foreach (array_chunk(array_merge($deviceIds, $hiddenIds), 2000) as $chunk) {
            $parts[] = fn ($query) => $query->whereIntegerInRaw('remote_device_id', $chunk);
        }
        foreach (array_chunk($names, 2000) as $chunk) {
            $parts[] = fn ($query) => $query->whereIn('remote_hostname', $chunk);
        }

        $links = [];
        foreach ($parts as $part) {
            $query = DB::table('links')->where('active', 1);
            $part($query);
            foreach ($query->get(['id', 'local_device_id', 'local_port_id', 'remote_device_id', 'remote_port_id', 'remote_hostname',
                'remote_port', 'remote_platform', 'remote_version', 'protocol']) as $link) {
                $link = (array) $link;
                if (isset($links[(int) $link['id']]) || ! $this->canSee((int) $link['local_device_id'])) {
                    continue; // only what the devices the user may see report
                }
                $links[(int) $link['id']] = $this->anonymized($link);
            }
        }

        return $links;
    }

    /**
     * Ports among $portIds that have VLAN rows (ports_vlans) at all.
     *
     * @param  int[]  $portIds
     * @return array<int, true>
     */
    public function portsWithVlans(array $portIds): array
    {
        $result = [];
        if (! $this->allowsTable('ports_vlans')) {
            return $result;
        }
        foreach (array_chunk(array_values(array_unique(array_map('intval', $portIds))), 5000) as $chunk) {
            foreach (DB::table('ports_vlans')->whereIntegerInRaw('port_id', $chunk)->distinct()->pluck('port_id') as $portId) {
                $result[(int) $portId] = true;
            }
        }

        return $result;
    }

    /**
     * Ports among $portIds that carry the VLAN (ports_vlans), and whether untagged.
     *
     * @param  int[]  $portIds
     * @return array<int, bool> port_id => untagged
     */
    public function portVlans(array $portIds, int $vlan): array
    {
        $result = [];
        if (! $this->allowsTable('ports_vlans')) {
            return $result;
        }
        foreach (array_chunk(array_values(array_unique(array_map('intval', $portIds))), 5000) as $chunk) {
            foreach (DB::table('ports_vlans')->whereIntegerInRaw('port_id', $chunk)->where('vlan', $vlan)->get(['port_id', 'untagged']) as $row) {
                $result[(int) $row->port_id] = ($result[(int) $row->port_id] ?? false) || (bool) $row->untagged;
            }
        }

        return $result;
    }

    /**
     * Ports among $portIds with an IPv4 or (not link-local) IPv6 address: routed ports.
     *
     * @param  int[]  $portIds
     * @return array<int, true>
     */
    public function portsWithIp(array $portIds): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $portIds))), 5000) as $chunk) {
            foreach ($this->allowsTable('ipv4_addresses') ? DB::table('ipv4_addresses')->whereIntegerInRaw('port_id', $chunk)->distinct()->pluck('port_id') : [] as $portId) {
                $result[(int) $portId] = true;
            }
            foreach ($this->allowsTable('ipv6_addresses') ? DB::table('ipv6_addresses')->whereIntegerInRaw('port_id', $chunk)->where('ipv6_address', 'not like', 'fe80%')->distinct()->pluck('port_id') : []
                as $portId) {
                $result[(int) $portId] = true;
            }
        }

        return $result;
    }

    /**
     * The spanning tree instance of each device that carries the VLAN (StpIndex::keyFor() on every instance), read
     * without loading every VLAN's row of a PVST network: the rows of that VLAN and of the default instance, the default
     * instance only for bridges that run a single one.
     *
     * @param  int[]  $ids
     * @return array<int, array> rows of the stp table
     */
    public function stpInstances(array $ids, ?int $vlan): array
    {
        $ids = $this->visible($ids);
        if (! $ids || ! $this->allowsTable('stp')) {
            return [];
        }

        $key = StpIndex::vlanKey($vlan);
        $rows = [];
        $instances = [];
        try {
            foreach (array_chunk($ids, 5000) as $chunk) {
                foreach (DB::table('stp')->whereIntegerInRaw('device_id', $chunk)->groupBy('device_id')->selectRaw('device_id, COUNT(*) as c')->get() as $row) {
                    $instances[(int) $row->device_id] = (int) $row->c;
                }
                foreach (DB::table('stp')->whereIntegerInRaw('device_id', $chunk)
                    ->where(fn ($query) => $query->whereNull('vlan')->orWhereIn('vlan', array_values(array_unique([0, 1, max(1, $key)]))))->get() as $row) {
                    $rows[] = (array) $row;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read stp: ' . $e->getMessage());

            return [];
        }

        // a PVST bridge without an instance for the VLAN does not run spanning tree in it: not its VLAN 1 instance
        $hasKey = [];
        foreach ($rows as $row) {
            if (StpIndex::vlanKey($row['vlan']) === $key) {
                $hasKey[(int) $row['device_id']] = true;
            }
        }

        return array_values(array_filter($rows, fn ($row) => StpIndex::vlanKey($row['vlan']) === $key
            || (! isset($hasKey[(int) $row['device_id']]) && ($instances[(int) $row['device_id']] ?? 0) <= 1)));
    }

    /**
     * Rows of ports_stp for the given ports, in the instance of each device (key: StpIndex::vlanKey()).
     *
     * @param  array<int, int|null>  $keys  device_id => instance key
     * @param  int[]  $portIds
     * @return array<int, array>
     */
    public function stpPorts(array $keys, array $portIds): array
    {
        $byKey = [];
        foreach ($keys as $deviceId => $key) {
            if ($key !== null && $this->canSee((int) $deviceId)) {
                $byKey[$key][] = (int) $deviceId;
            }
        }
        $portIds = array_values(array_unique(array_filter(array_map('intval', $portIds))));
        if (! $byKey || ! $portIds || ! $this->allowsTable('ports_stp')) {
            return [];
        }

        $rows = [];
        try {
            foreach ($byKey as $key => $deviceIds) {
                foreach (array_chunk($portIds, 5000) as $chunk) {
                    $query = DB::table('ports_stp')->whereIntegerInRaw('device_id', $deviceIds)->whereIntegerInRaw('port_id', $chunk);
                    // LibreNMS stores the default instance (and Cisco PVST VLAN 1) with vlan NULL
                    $key === 0 ? $query->where(fn ($q) => $q->whereNull('vlan')->orWhereIn('vlan', [0, 1])) : $query->where('vlan', $key);
                    foreach ($query->get() as $row) {
                        $rows[] = (array) $row;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read ports_stp: ' . $e->getMessage());

            return [];
        }

        return $rows;
    }

    /**
     * IPv4 and IPv6 interface addresses (link-local ones included) of devices the user may see.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array{device_id: int, port_id: int, address: string, prefixlen: int, vrf: string, up: bool, network_id: int}>
     */
    public function interfaces(array $deviceIds): array
    {
        $deviceIds = $this->visible($deviceIds);
        if (! $deviceIds) {
            return [];
        }

        return $this->interfaceRows(fn ($query, int $family) => $query->whereIntegerInRaw('p.device_id', $deviceIds));
    }

    /**
     * Interfaces with one of the addresses, on devices the user may see (any role). LibreNMS has no index on the
     * address: one read of each address table.
     *
     * @param  string[]  $addresses
     * @return array<int, array{device_id: int, port_id: int, address: string, prefixlen: int, vrf: string, up: bool, network_id: int}>
     */
    public function ownersOf(array $addresses): array
    {
        $v4 = [];
        $v6 = [];
        foreach ($addresses as $address) {
            $canonical = Cidr::canonical((string) $address);
            if ($canonical !== null && str_contains($canonical, ':')) {
                $v6[$canonical] = HostQuery::ipv6Full($canonical);
            } elseif ($canonical !== null) {
                $v4[$canonical] = true;
            }
        }
        if (! $v4 && ! $v6) {
            return [];
        }

        return $this->interfaceRows(fn ($query, int $family) => $family === 4
            ? ($v4 ? $query->whereIn('a.ipv4_address', array_keys($v4)) : null)
            : ($v6 ? $query->where(fn ($q) => $q->whereIn('a.ipv6_compressed', array_keys($v6))->orWhereIn('a.ipv6_address', array_values($v6))) : null));
    }

    /**
     * Interfaces on the subnets that hold an address, on devices the user may see: the networks LibreNMS keeps of every
     * prefix length of the address (ipv4_networks / ipv6_networks), and the addresses on them (indexed by network for
     * IPv4).
     *
     * @return array<int, array{device_id: int, port_id: int, address: string, prefixlen: int, vrf: string, up: bool, network_id: int}>
     */
    public function subnets(string $address): array
    {
        $canonical = Cidr::canonical($address);
        if ($canonical === null) {
            return [];
        }
        $family = Cidr::family($canonical);
        $networks = [];
        for ($length = 1; $length < ($family === 4 ? 32 : 128); $length++) {
            $networks[] = (string) Cidr::network($canonical, $length);
        }
        [$table, $column, $id] = $family === 4 ? ['ipv4_networks', 'ipv4_network', 'ipv4_network_id'] : ['ipv6_networks', 'ipv6_network', 'ipv6_network_id'];
        if (! $this->allowsTable($table)) {
            return [];
        }
        try {
            $ids = array_map('intval', DB::table($table)->whereIn($column, $networks)->pluck($id)->all());
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read ' . $table . ': ' . $e->getMessage());

            return [];
        }

        return $ids ? $this->onNetworks([$family => $ids]) : [];
    }

    /**
     * Interfaces on the same subnets (networks) as these, on devices the user may see.
     *
     * @param  array<int, array>  $interfaces  with network_id
     * @return array<int, array{device_id: int, port_id: int, address: string, prefixlen: int, vrf: string, up: bool, network_id: int}>
     */
    public function peers(array $interfaces): array
    {
        $ids = [];
        foreach ($interfaces as $interface) {
            if ((int) ($interface['network_id'] ?? 0) > 0) {
                $ids[Cidr::family((string) $interface['address'])][(int) $interface['network_id']] = (int) $interface['network_id'];
            }
        }

        return $ids ? $this->onNetworks(array_map('array_values', $ids)) : [];
    }

    /**
     * Rows LibreNMS estimates the route table has (information_schema, no scan); null when unknown.
     */
    public function routeTableSize(): ?int
    {
        if (! $this->allowsTable('route')) {
            return null;
        }
        try {
            $row = DB::selectOne('SELECT TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['route']);

            return $row === null || $row->n === null ? null : (int) $row->n;
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot estimate the route table size: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Which devices have routes in LibreNMS, per VRF (context), when their newest route was read, and when their last
     * discovery started (a route table older than that was not read again: the module is off, or the device has more
     * routes than routes_max_number). The summary of the whole table is cached for ROUTE_DATA_SECONDS.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array{count: int, newest: int, contexts: array<string, int>, built: int, discovered: int, finished: int}>
     */
    public function routeData(array $deviceIds): array
    {
        $deviceIds = $this->visible($deviceIds);
        if (! $deviceIds || ! $this->allowsTable('route')) {
            return [];
        }

        $summary = null;
        try {
            // per database: installs that share a cache store keep their own
            $summary = Cache::remember(self::ROUTE_DATA_KEY . '.' . DB::connection()->getDatabaseName(), self::ROUTE_DATA_SECONDS,
                fn () => ['built' => time(), 'devices' => self::routeSummary()]);
        } catch (\Throwable $e) {
            Log::debug('topology plugin: route table summary not cached: ' . $e->getMessage());
        }
        try {
            $summary ??= ['built' => time(), 'devices' => self::routeSummary()];
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read the route table: ' . $e->getMessage());

            return [];
        }

        // when the summary was read, and when each device's last discovery started and ended: a discovery that ended after
        // the summary was read may have read the device's routes again (L3Tracer::routeState())
        $data = array_map(fn ($device) => $device + ['built' => (int) $summary['built']], array_intersect_key($summary['devices'], array_flip($deviceIds)));
        if ($data) {
            foreach (DB::table('devices')->whereIntegerInRaw('device_id', array_keys($data))->get(['device_id', 'last_discovered', 'last_discovered_timetaken']) as $row) {
                $last = self::time($row->last_discovered);
                $data[(int) $row->device_id]['finished'] = $last;
                $data[(int) $row->device_id]['discovered'] = $last > 0 ? $last - (int) round((float) $row->last_discovered_timetaken) : 0;
            }
        }

        return $data;
    }

    /**
     * Routes of the devices that cover the destination: every prefix of it, as LibreNMS stores them (IPv4 dotted,
     * IPv6 uncompressed), read in one pass.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array<int, array{context: string, dest: string, pfxlen: int, next_hop: string, port_id: int, type: int, proto: int, metric: int, updated: int}>>
     */
    public function routesToward(string $destination, array $deviceIds): array
    {
        $deviceIds = $this->visible($deviceIds);
        $packed = @inet_pton($destination);
        if (! $deviceIds || $packed === false || ! $this->allowsTable('route')) {
            return [];
        }

        $bits = strlen($packed) * 8;
        $networks = [];
        for ($length = 0; $length <= $bits; $length++) {
            $network = self::prefix($destination, $length);
            $networks[$network] = true;
            if ($bits === 128) {
                $networks[HostQuery::ipv6Full($network)] = true;
            }
        }

        $routes = [];
        try {
            foreach (DB::table('route')->whereIntegerInRaw('device_id', $deviceIds)->whereIn('inetCidrRouteDest', array_keys($networks))
                ->get(['device_id', 'context_name', 'inetCidrRouteDest', 'inetCidrRoutePfxLen', 'inetCidrRouteNextHop', 'port_id',
                    'inetCidrRouteType', 'inetCidrRouteProto', 'inetCidrRouteMetric1', 'updated_at']) as $row) {
                // 10.90.130.0 is the network of several prefix lengths: keep the routes whose length covers the destination
                $length = (int) $row->inetCidrRoutePfxLen;
                if ($length > $bits || Cidr::canonical(self::prefix($destination, $length)) !== Cidr::canonical((string) $row->inetCidrRouteDest)) {
                    continue;
                }
                $routes[(int) $row->device_id][] = [
                    'context' => (string) $row->context_name,
                    'dest' => (string) (Cidr::canonical((string) $row->inetCidrRouteDest) ?? $row->inetCidrRouteDest),
                    'pfxlen' => (int) $row->inetCidrRoutePfxLen,
                    'next_hop' => (string) $row->inetCidrRouteNextHop,
                    'port_id' => (int) $row->port_id,
                    'type' => (int) $row->inetCidrRouteType,
                    'proto' => (int) $row->inetCidrRouteProto,
                    'metric' => (int) $row->inetCidrRouteMetric1,
                    'updated' => self::time($row->updated_at),
                ];
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read the route table: ' . $e->getMessage());

            return [];
        }

        return $routes;
    }

    /**
     * Ports of a device the user may see, by interface name (ifName or ifDescr): the physical port under a sub-interface.
     *
     * @param  string[]  $names
     * @return array<string, int> lower case name => port_id
     */
    public function portsByName(int $deviceId, array $names): array
    {
        $names = array_values(array_unique(array_filter($names, fn ($name) => $name !== '')));
        if (! $names || ! $this->canSee($deviceId) || ! $this->allowsTable('ports')) {
            return [];
        }

        $found = [];
        foreach (DB::table('ports')->where('device_id', $deviceId)->where('deleted', 0)
            ->where(fn ($query) => $query->whereIn('ifName', $names)->orWhereIn('ifDescr', $names))
            ->get(['port_id', 'ifName', 'ifDescr']) as $row) {
            foreach ([$row->ifName, $row->ifDescr] as $name) {
                if ($name !== null && $name !== '') {
                    $found[strtolower((string) $name)] ??= (int) $row->port_id;
                }
            }
        }

        return $found;
    }

    /**
     * A device the user may see, with the addresses LibreNMS polls it on.
     *
     * @return array{device_id: int, name: string, hostname: string, ips: string[]}|null
     */
    public function device(int $deviceId): ?array
    {
        if (! $this->canSee($deviceId)) {
            return null;
        }
        $row = DB::table('devices')->where('device_id', $deviceId)->first(['device_id', 'hostname', 'display', 'ip', 'overwrite_ip']);
        if ($row === null) {
            return null;
        }

        $ips = [];
        foreach ([(string) $row->overwrite_ip, (string) $row->hostname] as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ips[] = $candidate;
            }
        }
        $binary = $row->ip === null ? false : @inet_ntop((string) $row->ip);
        if ($binary) {
            $ips[] = $binary;
        }

        return ['device_id' => (int) $row->device_id, 'name' => (string) ($row->display ?: $row->hostname), 'hostname' => (string) $row->hostname,
            'ips' => array_values(array_unique($ips))];
    }

    /**
     * The devices out of the user's sight behind the numbers links gave them in this request.
     *
     * @param  int[]  $numbers
     * @return int[]
     */
    protected function hiddenDeviceIds(array $numbers): array
    {
        return array_values(array_filter(array_map(fn ($n) => $this->hiddenDevice[(int) $n] ?? null, $numbers)));
    }

    /**
     * A link row with a remote device out of the user's sight made anonymous.
     */
    protected function anonymized(array $link): array
    {
        $remote = (int) $link['remote_device_id'];
        if ($remote && ! $this->canSee($remote)) {
            if (! isset($this->hiddenNumber[$remote])) {
                $this->hiddenNumber[$remote] = count($this->hiddenNumber) + 1;
                $this->hiddenDevice[$this->hiddenNumber[$remote]] = $remote;
            }
            $link = array_merge($link, ['remote_device_id' => 0, 'remote_port_id' => null, 'remote_hostname' => L2Tracer::HIDDEN . $this->hiddenNumber[$remote],
                'remote_port' => '', 'remote_platform' => '', 'remote_version' => '']);
        }

        return $link;
    }

    /**
     * Interfaces on networks (family => network ids).
     *
     * @param  array<int, int[]>  $ids
     */
    private function onNetworks(array $ids): array
    {
        return $this->interfaceRows(fn ($query, int $family) => isset($ids[$family])
            ? $query->whereIntegerInRaw($family === 4 ? 'a.ipv4_network_id' : 'a.ipv6_network_id', $ids[$family]) : null);
    }

    /**
     * Interface rows of both address tables of devices the user may see, the query of each narrowed by $where (null:
     * that table is not read); none of a table the user's role may not view (nor of ports), and the VRF names only
     * with the permission to view VRFs.
     *
     * @param  \Closure(\Illuminate\Database\Query\Builder, int): mixed  $where  query and address family
     */
    private function interfaceRows(\Closure $where): array
    {
        $rows = [];
        try {
            foreach ([4 => ['ipv4_addresses', 'a.ipv4_address', 'a.ipv4_prefixlen', 'a.ipv4_network_id'], 6 => ['ipv6_addresses', 'a.ipv6_compressed', 'a.ipv6_prefixlen', 'a.ipv6_network_id']]
                as $family => [$table, $address, $prefixlen, $network]) {
                if (! $this->allowsTable($table) || ! $this->allowsTable('ports')) {
                    continue;
                }
                $query = DB::table($table . ' as a')->join('ports as p', 'p.port_id', '=', 'a.port_id')->where('p.deleted', 0);
                if ($where($query, $family) === null) {
                    continue;
                }
                foreach ($query->get(['p.device_id', 'p.port_id', 'p.ifOperStatus', 'p.ifAdminStatus', 'p.ifVrf', $address . ' as address', $prefixlen . ' as prefixlen',
                    $network . ' as network_id', 'a.context_name']) as $row) {
                    if ($this->canSee((int) $row->device_id)) {
                        $rows[] = (array) $row;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read interface addresses: ' . $e->getMessage());

            return [];
        }
        $vrfs = [];
        foreach ($this->allows(\App\Models\Vrf::class) ? array_chunk(array_values(array_unique(array_map(fn ($row) => (int) $row['device_id'], $rows))), 5000) : [] as $chunk) {
            $vrfs += DB::table('vrfs')->whereIntegerInRaw('device_id', $chunk)->pluck('vrf_name', 'vrf_id')->all();
        }

        return array_map(fn ($row) => [
            'device_id' => (int) $row['device_id'],
            'port_id' => (int) $row['port_id'],
            'address' => (string) $row['address'],
            'prefixlen' => (int) $row['prefixlen'],
            'vrf' => (string) (($row['ifVrf'] ? ($vrfs[$row['ifVrf']] ?? '') : '') ?: ($row['context_name'] ?? '')),
            'up' => self::isUp($row['ifOperStatus'], $row['ifAdminStatus']),
            'network_id' => (int) ($row['network_id'] ?? 0),
        ], $rows);
    }

    /**
     * Is an interface up: not down, not shut down (no status counts as up)?
     */
    public static function isUp(?string $oper, ?string $admin): bool
    {
        return ! in_array(strtolower((string) $oper), ['down', 'lowerlayerdown', 'notpresent'], true) && strtolower((string) $admin) !== 'down';
    }

    /**
     * Routes of every device: how many per VRF, the newest one's time.
     *
     * @return array<int, array{count: int, newest: int, contexts: array<string, int>}>
     */
    private static function routeSummary(): array
    {
        $data = [];
        foreach (DB::table('route')->groupBy('device_id', 'context_name')->selectRaw('device_id, context_name, COUNT(*) as c, MAX(updated_at) as newest')->get() as $row) {
            $deviceId = (int) $row->device_id;
            $data[$deviceId] ??= ['count' => 0, 'newest' => 0, 'contexts' => []];
            $data[$deviceId]['count'] += (int) $row->c;
            $data[$deviceId]['newest'] = max($data[$deviceId]['newest'], self::time($row->newest));
            $data[$deviceId]['contexts'][(string) $row->context_name] = (int) $row->c;
        }

        return $data;
    }

    /**
     * @param  int[]  $deviceIds
     * @return int[] the ones the user may see
     */
    private function visible(array $deviceIds): array
    {
        return array_values(array_filter(array_unique(array_map('intval', $deviceIds)), fn ($id) => $this->canSee($id)));
    }

    /**
     * Network address of the destination's prefix of that length (10.90.130.0 for /24).
     */
    private static function prefix(string $address, int $length): string
    {
        return explode('/', (string) Cidr::network($address, $length))[0];
    }

    private static function time(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return $value instanceof \DateTimeInterface ? $value->getTimestamp() : (int) strtotime((string) $value);
    }
}
