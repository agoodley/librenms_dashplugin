<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Support\Cidr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Overlay layers of the L3 map beyond OSPF and BGP: OSPFv3 and IS-IS adjacencies, IPsec tunnels,
 * MPLS LSPs and pseudowires, plus the lookups that recognize the far ends of every overlay among the
 * devices the user may see. The tables belong to LibreNMS modules that are off by default (or newer
 * than some installs): a missing table or column is an empty layer.
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

    /** Columns read per layer, all required. */
    private const COLUMNS = [
        'ospfv3' => ['device_id', 'port_id', 'ospfv3NbrRtrId', 'ospfv3NbrAddress', 'ospfv3NbrState', 'context_name'],
        'isis' => ['device_id', 'port_id', 'isisISAdjState', 'isisISAdjNeighSysType', 'isisISAdjNeighSysID', 'isisISAdjAreaAddress',
            'isisISAdjIPAddrAddress', 'isisCircAdminState'],
        'ipsec' => ['device_id', 'peer_addr', 'local_addr', 'tunnel_name', 'tunnel_status'],
        'mpls' => ['device_id', 'mplsLspName', 'mplsLspFromAddr', 'mplsLspToAddr', 'mplsLspAdminState', 'mplsLspOperState', 'mplsLspType',
            'mplsLspFastReroute', 'mplsLspTransitions', 'mplsLspConfiguredPaths', 'mplsLspOperationalPaths'],
        'pw' => ['device_id', 'port_id', 'peer_device_id', 'peer_ldp_id', 'cpwVcID', 'pw_type', 'pw_psntype', 'pw_descr'],
    ];

    /** Columns read when this LibreNMS version has them: ospfv3_nbrs.router_id came in 2025-03. */
    private const OPTIONAL = ['ospfv3' => ['router_id']];

    /** Columns of the local interface joined per layer (OSPFv3's port_id is the neighbour's, found by its address). */
    private const LOCAL_PORT = ['isis' => ['ifName', 'ifDescr'], 'pw' => ['ifName', 'ifDescr', 'ifMtu']];

    /** Largest IN list sent in one query. */
    private const CHUNK = 1000;

    /** Largest IN list of addresses: their columns have no index, so every query scans the table. */
    private const ADDRESS_CHUNK = 5000;

    /** @var array<string, string[]> table => its columns (none when it does not exist), read once */
    private array $present = [];

    /**
     * Rows per layer and device among the devices: the map loads the layers with rows in its scope and
     * offers the toggles of those with rows on the devices it draws.
     *
     * @param  int[]  $ids
     * @return array<string, array<int, int>> layer => device_id => rows (none when the table or a column is missing)
     */
    public function countsByDevice(array $ids): array
    {
        $counts = [];
        foreach (self::TABLES as $layer => $table) {
            $counts[$layer] = [];
            if (! $this->allowsTable($table) || $this->columns($layer) === null) {
                continue;
            }
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                try {
                    foreach (DB::table($table)->whereIntegerInRaw('device_id', $chunk)->groupBy('device_id')
                        ->selectRaw('device_id, COUNT(*) as c')->pluck('c', 'device_id') as $id => $count) {
                        $counts[$layer][(int) $id] = (int) $count;
                    }
                } catch (\Throwable $e) {
                    Log::debug("topology plugin: cannot read $table: " . $e->getMessage());
                    $counts[$layer] = [];
                    break;
                }
            }
        }

        return $counts;
    }

    /**
     * Rows of the given layers for the devices, with the local interface (ifName, ifDescr; ifMtu of a
     * pseudowire's attachment circuit) where the layer has one.
     *
     * @param  int[]  $ids
     * @param  string[]  $layers  keys of TABLES
     * @return array<string, array<int, array>> layer => rows
     */
    public function load(array $ids, array $layers): array
    {
        $result = [];
        foreach (array_intersect(array_keys(self::TABLES), $layers) as $layer) {
            $result[$layer] = [];
            $table = self::TABLES[$layer];
            $columns = $this->allowsTable($table) ? $this->columns($layer) : null;
            if ($columns === null) {
                continue;
            }
            $local = self::LOCAL_PORT[$layer] ?? [];
            $select = array_merge(array_map(fn ($column) => "l.$column", $columns), array_map(fn ($column) => "p.$column", $local));
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                try {
                    $query = DB::table("$table as l")->whereIntegerInRaw('l.device_id', $chunk);
                    if ($local) {
                        $query->leftJoin('ports as p', 'p.port_id', '=', 'l.port_id');
                    }
                    foreach ($query->get($select) as $row) {
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
     * Recognize far ends among the devices the user may see (see L3Builder::lookups()): the owner of each
     * address (and of each loopback / host route; in the global table first, then in any VRF) and its owner
     * within each VRF that has it (for sessions in that VRF), the device of
     * each router ID in its context, the pseudowire rows with the VC IDs (the other ends), how many devices
     * have each VC ID (all devices: a count only) and the devices involved. Addresses and router IDs found on
     * several devices stay unresolved rather than picking one at random; such router IDs are listed so that
     * their address is not tried instead.
     *
     * @param  array{addresses?: string[], routerIds?: string[], vcs?: int[], devices?: int[]}  $lookups
     * @return array{addresses: array<string, int>, vrfAddresses: array<string, int>, loopbacks: array<string, int>, routerIds: array<string, int>,
     *     ambiguousRouterIds: array<string, true>, pseudowires: array<int, array>, vcDevices: array<int, int>, deviceIds: int[]}
     */
    public function resolve(array $lookups): array
    {
        $visible = $this->accessible();

        $owners = [];
        $inVrfs = [];
        $loopbacks = [];
        $addresses = $lookups['addresses'] ?? [];
        foreach ([
            ['ipv4_addresses', 'ipv4_address', 'ipv4_prefixlen', 32, array_filter($addresses, fn ($a) => ! str_contains($a, ':'))],
            ['ipv6_addresses', 'ipv6_compressed', 'ipv6_prefixlen', 128, array_filter($addresses, fn ($a) => str_contains($a, ':'))],
        ] as [$table, $column, $prefix, $host, $values]) {
            $this->each("$table as a", "a.$column", $values, ["a.$column as address", "a.$prefix as prefixlen", 'a.context_name', 'p.device_id', 'p.ifType', 'p.ifVrf', 'v.vrf_name'],
                function ($row) use (&$owners, &$inVrfs, &$loopbacks, $visible, $host) {
                    $deviceId = (int) $row->device_id;
                    if (! isset($visible[$deviceId])) {
                        return;
                    }
                    $address = Cidr::canonical((string) $row->address) ?? (string) $row->address;
                    // the port's VRF, else the SNMP context (as TopologyRepository::addresses() names them)
                    $vrf = (string) (((int) $row->ifVrf ? $row->vrf_name : '') ?: $row->context_name);
                    $owners[$address][$deviceId] = ($owners[$address][$deviceId] ?? false) || $vrf === '';
                    if ($vrf !== '') {
                        $inVrfs[$vrf . '|' . $address][$deviceId] = true;
                    }
                    if ((int) $row->prefixlen >= $host || strtolower((string) $row->ifType) === 'softwareloopback') {
                        $loopbacks[$address][$deviceId] = ($loopbacks[$address][$deviceId] ?? false) || $vrf === '';
                    }
                }, false, fn ($query) => $query->join('ports as p', 'p.port_id', '=', 'a.port_id')->leftJoin('vrfs as v', 'v.vrf_id', '=', 'p.ifVrf')
                    ->where('p.deleted', 0), self::ADDRESS_CHUNK);
        }

        // router IDs per context ("context|10.0.0.1"); OSPFv3 instances older than 2025-03 keep the number
        $wanted = array_flip($lookups['routerIds'] ?? []);
        $rids = [];
        foreach (array_keys($wanted) as $key) {
            $pos = strrpos($key, '|');
            $rid = $pos === false ? $key : substr($key, $pos + 1);
            $rids[$rid] = true;
            if (($long = ip2long($rid)) !== false) {
                $rids[(string) $long] = true;
            }
        }
        $routers = [];
        foreach (['ospfv3_instances' => ['router_id', 'ospfv3RouterId'], 'ospf_instances' => ['ospfRouterId']] as $table => $candidates) {
            $column = $wanted ? $this->firstColumn($table, $candidates) : null;
            if ($column === null) {
                continue;
            }
            $this->each($table, $column, array_map('strval', array_keys($rids)), ["$column as rid", 'context_name', 'device_id'], function ($row) use (&$routers, $visible, $wanted) {
                $rid = (string) $row->rid;
                $key = (string) $row->context_name . '|' . (ctype_digit($rid) ? long2ip((int) $rid) : $rid);
                if (isset($wanted[$key], $visible[(int) $row->device_id])) {
                    $routers[$key][(int) $row->device_id] = true;
                }
            });
        }

        $pseudowires = [];
        $vcDevices = [];
        $vcs = array_values(array_unique(array_map('intval', $lookups['vcs'] ?? [])));
        if ($vcs && $this->columns('pw') !== null) {
            $this->each('pseudowires as l', 'l.cpwVcID', $vcs, ['l.device_id', 'l.port_id', 'l.peer_device_id', 'l.peer_ldp_id', 'l.cpwVcID', 'p.ifName', 'p.ifDescr', 'p.ifMtu'],
                function ($row) use (&$pseudowires, &$vcDevices, $visible) {
                    $vcDevices[(int) $row->cpwVcID][(int) $row->device_id] = true;
                    if (isset($visible[(int) $row->device_id])) {
                        $pseudowires[] = (array) $row;
                    }
                }, true, fn ($query) => $query->leftJoin('ports as p', 'p.port_id', '=', 'l.port_id'));
        }

        // the only owner, else the only one in the global table
        $owner = function (array $set): ?int {
            if (count($set) === 1) {
                return (int) array_key_first($set);
            }
            $global = array_keys(array_filter($set));

            return count($global) === 1 ? (int) $global[0] : null;
        };
        $result = [
            'addresses' => array_filter(array_map($owner, $owners), fn ($id) => $id !== null),
            'vrfAddresses' => array_map(fn ($set) => (int) array_key_first($set), array_filter($inVrfs, fn ($set) => count($set) === 1)),
            'loopbacks' => array_filter(array_map($owner, $loopbacks), fn ($id) => $id !== null),
            'routerIds' => array_map(fn ($set) => (int) array_key_first($set), array_filter($routers, fn ($set) => count($set) === 1)),
            'ambiguousRouterIds' => array_fill_keys(array_keys(array_filter($routers, fn ($set) => count($set) > 1)), true),
            'pseudowires' => $pseudowires,
            'vcDevices' => array_map('count', $vcDevices),
        ];
        $peers = array_filter(array_map('intval', $lookups['devices'] ?? []), fn ($id) => isset($visible[$id]));
        $result['deviceIds'] = array_values(array_unique(array_merge(array_values($result['addresses']), array_values($result['vrfAddresses']), array_values($result['loopbacks']),
            array_values($result['routerIds']), array_map(fn ($row) => (int) $row['device_id'], $pseudowires), $peers)));

        return $result;
    }

    /**
     * Columns of a layer to read on this LibreNMS version, null when its table or a required column is missing.
     *
     * @return string[]|null
     */
    private function columns(string $layer): ?array
    {
        $present = $this->present(self::TABLES[$layer]);
        if (array_diff(self::COLUMNS[$layer], $present)) {
            return null;
        }

        return array_merge(self::COLUMNS[$layer], array_values(array_intersect(self::OPTIONAL[$layer] ?? [], $present)));
    }

    /**
     * The first of the candidate columns a table has, null when it has none (or does not exist).
     *
     * @param  string[]  $candidates
     */
    private function firstColumn(string $table, array $candidates): ?string
    {
        return array_values(array_intersect($candidates, $this->present($table)))[0] ?? null;
    }

    /**
     * Columns of a table (none when it does not exist), read once.
     *
     * @return string[]
     */
    private function present(string $table): array
    {
        if (! isset($this->present[$table])) {
            try {
                $this->present[$table] = Schema::getColumnListing($table);
            } catch (\Throwable $e) {
                Log::debug("topology plugin: cannot list the columns of $table: " . $e->getMessage());
                $this->present[$table] = [];
            }
        }

        return $this->present[$table];
    }

    /**
     * Run $handle for every row of $table whose $column is in $values (chunked); a missing table is no rows.
     *
     * @param  callable(object): void  $handle
     * @param  (callable(\Illuminate\Database\Query\Builder): mixed)|null  $join
     */
    private function each(string $table, string $column, array $values, array $columns, callable $handle, bool $integers = false, ?callable $join = null, int $chunk = self::CHUNK): void
    {
        if (! $this->allowsTable($table)) {
            return;
        }
        foreach (array_chunk(array_values(array_unique($values)), $chunk) as $part) {
            try {
                $query = DB::table($table);
                if ($join) {
                    $join($query);
                }
                $integers ? $query->whereIntegerInRaw($column, $part) : $query->whereIn($column, $part);
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
