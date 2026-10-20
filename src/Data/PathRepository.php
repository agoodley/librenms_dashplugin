<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Hosts\HostQuery;
use Agoodley\LibrenmsTopology\Path\L2Tracer;
use Agoodley\LibrenmsTopology\Support\Cidr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Database access of the path trace that the other repositories do not have: links that keep a
 * device out of the user's sight recognizable (without revealing it), the route table (LibreNMS'
 * `route`, which has no index but its primary key, so it is read at most twice per trace), ports by
 * name and the addresses a device is polled on.
 */
class PathRepository extends ScopedRepository
{
    /**
     * Active LLDP/CDP links reported by the given devices. A remote device the user may not see loses its ids, port and
     * name: its remote_hostname becomes L2Tracer::HIDDEN . <n>, the same n for every link to it in this request.
     *
     * @param  int[]  $ids
     * @return array<int, array>
     */
    public function links(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $accessible = $this->accessible();
        $hidden = [];
        $links = [];
        foreach (array_chunk(array_values(array_unique($ids)), 5000) as $chunk) {
            foreach (DB::table('links')->where('active', 1)->whereIntegerInRaw('local_device_id', $chunk)
                ->get(['id', 'local_device_id', 'local_port_id', 'remote_device_id', 'remote_port_id', 'remote_hostname',
                    'remote_port', 'remote_platform', 'remote_version', 'protocol']) as $link) {
                $link = (array) $link;
                $remote = (int) $link['remote_device_id'];
                if ($remote && ! isset($accessible[$remote])) {
                    $hidden[$remote] ??= count($hidden) + 1;
                    $link = array_merge($link, ['remote_device_id' => 0, 'remote_port_id' => null, 'remote_hostname' => L2Tracer::HIDDEN . $hidden[$remote],
                        'remote_port' => '', 'remote_platform' => '', 'remote_version' => '']);
                }
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * Rows LibreNMS estimates the route table has (information_schema, no scan); null when unknown.
     */
    public function routeTableSize(): ?int
    {
        try {
            $row = DB::selectOne('SELECT TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['route']);

            return $row === null || $row->n === null ? null : (int) $row->n;
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot estimate the route table size: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Which devices have routes in LibreNMS, per VRF (context), and when their newest route was read.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array{count: int, newest: int, contexts: array<string, int>}>
     */
    public function routeData(array $deviceIds): array
    {
        $deviceIds = $this->visible($deviceIds);
        if (! $deviceIds) {
            return [];
        }

        $data = [];
        try {
            foreach (DB::table('route')->whereIntegerInRaw('device_id', $deviceIds)->groupBy('device_id', 'context_name')
                ->selectRaw('device_id, context_name, COUNT(*) as c, MAX(updated_at) as newest')->get() as $row) {
                $deviceId = (int) $row->device_id;
                $data[$deviceId] ??= ['count' => 0, 'newest' => 0, 'contexts' => []];
                $data[$deviceId]['count'] += (int) $row->c;
                $data[$deviceId]['newest'] = max($data[$deviceId]['newest'], self::time($row->newest));
                $data[$deviceId]['contexts'][(string) $row->context_name] = (int) $row->c;
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read the route table: ' . $e->getMessage());

            return [];
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
        if (! $deviceIds || $packed === false) {
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
        if (! $names || ! $this->canSee($deviceId)) {
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
