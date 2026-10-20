<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Support\Cidr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Overlay layers of the L3 map beyond OSPF and BGP: OSPFv3 and IS-IS adjacencies, IPsec tunnels,
 * MPLS LSPs and pseudowires, plus the lookups that recognize their far ends among the devices the
 * user may see. The tables belong to LibreNMS modules that are off by default (or newer than some
 * installs): a missing table or column is an empty layer.
 */
class L3LayerRepository extends ScopedRepository
{
    /** Layer => LibreNMS table. */
    public const TABLES = [
        'ospfv3' => 'ospfv3_nbrs',
        'isis' => 'isis_adjacencies',
        'ipsec' => 'ipsec_tunnels',
        'mpls' => 'mpls_lsps',
        'pw' => 'pseudowires',
    ];

    /** Columns read per layer; the ports join adds the local interface name where a layer has one. */
    private const COLUMNS = [
        'ospfv3' => ['device_id', 'port_id', 'router_id', 'ospfv3NbrRtrId', 'ospfv3NbrAddress', 'ospfv3NbrState', 'context_name'],
        'isis' => ['device_id', 'port_id', 'isisISAdjState', 'isisISAdjNeighSysType', 'isisISAdjNeighSysID', 'isisISAdjAreaAddress',
            'isisISAdjIPAddrAddress', 'isisCircAdminState'],
        'ipsec' => ['device_id', 'peer_addr', 'local_addr', 'tunnel_name', 'tunnel_status'],
        'mpls' => ['device_id', 'mplsLspName', 'mplsLspFromAddr', 'mplsLspToAddr', 'mplsLspAdminState', 'mplsLspOperState', 'mplsLspType',
            'mplsLspFastReroute', 'mplsLspTransitions', 'mplsLspConfiguredPaths', 'mplsLspOperationalPaths'],
        'pw' => ['device_id', 'port_id', 'peer_device_id', 'peer_ldp_id', 'cpwVcID', 'pw_type', 'pw_psntype', 'pw_local_mtu', 'pw_peer_mtu', 'pw_descr'],
    ];

    /** Layers whose port_id is the local interface (OSPFv3's is the neighbour's port, found by its address). */
    private const LOCAL_PORT = ['isis', 'pw'];

    /** Largest IN list sent in one query. */
    private const CHUNK = 1000;

    /**
     * Rows per layer among the devices: which layer toggles the map offers.
     *
     * @param  int[]  $ids
     * @return array<string, int> layer => rows (0 when the table is missing)
     */
    public function counts(array $ids): array
    {
        $counts = array_fill_keys(array_keys(self::TABLES), 0);
        foreach (self::TABLES as $layer => $table) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                try {
                    $counts[$layer] += DB::table($table)->whereIntegerInRaw('device_id', $chunk)->count();
                } catch (\Throwable $e) {
                    Log::debug("topology plugin: cannot read $table: " . $e->getMessage());
                    break;
                }
            }
        }

        return $counts;
    }

    /**
     * Rows of the given layers for the devices, with the local interface name (ifName, ifDescr) where
     * the layer has one.
     *
     * @param  int[]  $ids
     * @param  string[]  $layers  keys of TABLES
     * @return array<string, array<int, array>> layer => rows
     */
    public function load(array $ids, array $layers): array
    {
        $result = [];
        foreach (array_intersect(array_keys(self::TABLES), $layers) as $layer) {
            $table = self::TABLES[$layer];
            $local = in_array($layer, self::LOCAL_PORT, true);
            $columns = array_merge(array_map(fn ($column) => "l.$column", self::COLUMNS[$layer]), $local ? ['p.ifName', 'p.ifDescr'] : []);
            $result[$layer] = [];
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                try {
                    $query = DB::table("$table as l")->whereIntegerInRaw('l.device_id', $chunk);
                    if ($local) {
                        $query->leftJoin('ports as p', 'p.port_id', '=', 'l.port_id');
                    }
                    foreach ($query->get($columns) as $row) {
                        $result[$layer][] = (array) $row;
                    }
                } catch (\Throwable $e) {
                    Log::debug("topology plugin: cannot read $table: " . $e->getMessage());
                    $result[$layer] = [];
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Recognize far ends among the devices the user may see (see L3Builder::layerLookups()). Addresses
     * and router IDs found on several devices stay unresolved rather than picking one at random.
     *
     * @param  array{addresses?: string[], routerIds?: string[], ports?: int[], devices?: int[]}  $lookups
     * @return array{addresses: array<string, int>, routerIds: array<string, int>, ports: array<int, int>, devices: array<int, true>}
     */
    public function resolve(array $lookups): array
    {
        $visible = $this->accessible();

        $devices = [];
        foreach ($lookups['devices'] ?? [] as $deviceId) {
            if (isset($visible[(int) $deviceId])) {
                $devices[(int) $deviceId] = true;
            }
        }

        $ports = [];
        $this->each('ports', 'port_id', array_map('intval', $lookups['ports'] ?? []), ['port_id', 'device_id'], function ($row) use (&$ports, $visible) {
            if (isset($visible[(int) $row->device_id])) {
                $ports[(int) $row->port_id] = (int) $row->device_id;
            }
        }, true);

        $owners = [];
        $v4 = array_values(array_filter($lookups['addresses'] ?? [], fn ($a) => ! str_contains($a, ':')));
        $v6 = array_values(array_filter($lookups['addresses'] ?? [], fn ($a) => str_contains($a, ':')));
        foreach ([['ipv4_addresses', 'ipv4_address', $v4], ['ipv6_addresses', 'ipv6_compressed', $v6]] as [$table, $column, $addresses]) {
            $this->each("$table as a", "a.$column", $addresses, ["a.$column as address", 'p.device_id'], function ($row) use (&$owners, $visible) {
                if (isset($visible[(int) $row->device_id])) {
                    $owners[Cidr::canonical((string) $row->address) ?? (string) $row->address][(int) $row->device_id] = true;
                }
            }, false, fn ($query) => $query->join('ports as p', 'p.port_id', '=', 'a.port_id')->where('p.deleted', 0));
        }

        $routers = [];
        $routerIds = array_values(array_unique($lookups['routerIds'] ?? []));
        foreach ([['ospfv3_instances', 'router_id'], ['ospf_instances', 'ospfRouterId']] as [$table, $column]) {
            $this->each($table, $column, $routerIds, [$column . ' as rid', 'device_id'], function ($row) use (&$routers, $visible) {
                if (isset($visible[(int) $row->device_id])) {
                    $routers[(string) $row->rid][(int) $row->device_id] = true;
                }
            });
        }

        $unique = fn (array $sets) => array_map(fn ($set) => (int) array_key_first($set), array_filter($sets, fn ($set) => count($set) === 1));

        return ['addresses' => $unique($owners), 'routerIds' => $unique($routers), 'ports' => $ports, 'devices' => $devices];
    }

    /**
     * Run $handle for every row of $table whose $column is in $values (chunked); a missing table is no rows.
     *
     * @param  callable(object): void  $handle
     * @param  (callable(\Illuminate\Database\Query\Builder): mixed)|null  $join
     */
    private function each(string $table, string $column, array $values, array $columns, callable $handle, bool $integers = false, ?callable $join = null): void
    {
        foreach (array_chunk(array_values(array_unique($values)), self::CHUNK) as $chunk) {
            try {
                $query = DB::table($table);
                if ($join) {
                    $join($query);
                }
                $integers ? $query->whereIntegerInRaw($column, $chunk) : $query->whereIn($column, $chunk);
                foreach ($query->get($columns) as $row) {
                    $handle($row);
                }
            } catch (\Throwable $e) {
                Log::debug("topology plugin: cannot read $table: " . $e->getMessage());

                return;
            }
        }
    }
}
