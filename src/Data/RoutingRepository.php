<?php

namespace Agoodley\LibrenmsTopology\Data;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Database access of the routing & IP diagnostics: OSPF, BGP, interface addresses, VRFs and
 * LLDP/CDP links of the devices the user may see. The large tables are returned as generators that
 * read one row at a time (a network with thousands of routers has tens of thousands of addresses),
 * and columns that older LibreNMS versions lack are left out of the query instead of failing the
 * whole table.
 */
class RoutingRepository extends ScopedRepository
{
    private const DEVICE_COLUMNS = ['device_id', 'hostname', 'sysName', 'display', 'os', 'status', 'uptime', 'bgpLocalAs'];

    private const OSPF_COLUMNS = [
        'instances' => ['ospf_instances', ['device_id', 'ospfRouterId', 'ospfAdminStat', 'ospfAreaBdrRtrStatus', 'ospfASBdrRtrStatus', 'context_name']],
        'areas' => ['ospf_areas', ['device_id', 'ospfAreaId', 'ospfAreaLsaCount', 'ospfImportAsExtern', 'context_name']],
        'ports' => ['ospf_ports', [
            'device_id', 'port_id', 'ospfIfIpAddress', 'ospfAddressLessIf', 'ospfIfAreaId', 'ospfIfType', 'ospfIfAdminStat',
            'ospfIfHelloInterval', 'ospfIfRtrDeadInterval', 'ospfIfState', 'ospfIfDesignatedRouter', 'ospfIfAuthType', 'ospfIfMetricValue',
            'context_name',
        ]],
        'neighbors' => ['ospf_nbrs', ['device_id', 'port_id', 'ospfNbrIpAddr', 'ospfNbrRtrId', 'ospfNbrState', 'context_name']],
    ];

    private const BGP_PEER_COLUMNS = [
        'device_id', 'vrf_id', 'astext', 'bgpPeerIdentifier', 'bgpPeerRemoteAs', 'bgpPeerState', 'bgpPeerAdminStatus',
        'bgpPeerLastErrorCode', 'bgpPeerLastErrorSubCode', 'bgpPeerLastErrorText', 'bgpLocalAddr', 'bgpPeerRemoteAddr', 'bgpPeerDescr',
        'bgpPeerFsmEstablishedTime', 'context_name',
    ];

    private const BGP_PREFIX_COLUMNS = [
        'device_id', 'bgpPeerIdentifier', 'afi', 'safi', 'AcceptedPrefixes', 'DeniedPrefixes', 'PrefixAdminLimit', 'PrefixThreshold', 'context_name',
    ];

    /** IPv4 and IPv6 address tables: table, primary key, address column, prefix column. */
    private const ADDRESS_TABLES = [
        ['ipv4_addresses', 'ipv4_address_id', 'ipv4_address', 'ipv4_prefixlen'],
        ['ipv6_addresses', 'ipv6_address_id', 'ipv6_compressed', 'ipv6_prefixlen'],
    ];

    /** Rows read per query of the address tables. */
    private const CHUNK = 5000;

    /** @var array<string, array<string, int>> table => its columns */
    private array $columns = [];

    /** @var array<int, string> vrf_id => name, of the devices in $vrfsRead */
    private array $vrfNames = [];

    /** @var array<int, true> devices whose VRF names are read */
    private array $vrfsRead = [];

    /**
     * @param  int[]  $ids
     * @return array<int, array> device_id => device row
     */
    public function devices(array $ids): array
    {
        $devices = [];
        foreach ($this->rows('devices', $ids, $this->existing('devices', self::DEVICE_COLUMNS)) as $row) {
            $devices[(int) $row['device_id']] = $row;
        }

        return $devices;
    }

    /**
     * Rows of ospf_instances, ospf_areas, ospf_ports and ospf_nbrs, each a generator.
     *
     * @param  int[]  $ids
     * @return array{instances: \Generator, areas: \Generator, ports: \Generator, neighbors: \Generator}
     */
    public function ospf(array $ids): array
    {
        $streams = [];
        foreach (self::OSPF_COLUMNS as $key => [$table, $columns]) {
            $streams[$key] = $this->stream($table, $ids, $this->existing($table, $columns));
        }

        return $streams;
    }

    /**
     * BGP sessions (with 'vrf', the name of their VRF) and the prefix counters of bgpPeers_cbgp,
     * each a generator.
     *
     * @param  int[]  $ids
     * @return array{peers: \Generator, prefixes: \Generator}
     */
    public function bgp(array $ids): array
    {
        return [
            'peers' => $this->peers($ids),
            'prefixes' => $this->stream('bgpPeers_cbgp', $ids, $this->existing('bgpPeers_cbgp', self::BGP_PREFIX_COLUMNS)),
        ];
    }

    /**
     * IPv4 and IPv6 interface addresses with their port and VRF name (the port's VRF, else the
     * SNMP context the address was read in), read in chunks.
     *
     * @param  int[]  $ids
     */
    public function addresses(array $ids): \Generator
    {
        if (! $ids) {
            return;
        }

        $vrfs = $this->vrfNames($ids);
        $columns = array_map(fn ($c) => "p.$c", $this->existing('ports', ['device_id', 'port_id', 'ifName', 'ifDescr', 'ifType', 'ifMtu', 'ifOperStatus', 'ifVrf']));
        foreach (self::ADDRESS_TABLES as [$table, $key, $address, $prefix]) {
            try {
                $rows = DB::table("$table as a")->join('ports as p', 'p.port_id', '=', 'a.port_id')
                    ->whereIntegerInRaw('p.device_id', $ids)->where('p.deleted', 0)
                    ->select(array_merge($columns, ["a.$key as row_id", "a.$address as address", "a.$prefix as prefixlen", 'a.context_name']))
                    ->lazyById(self::CHUNK, "a.$key", 'row_id');
                foreach ($rows as $row) {
                    $row = (array) $row;
                    $row['vrf'] = (string) ((! empty($row['ifVrf']) ? ($vrfs[(int) $row['ifVrf']] ?? '') : '') ?: ($row['context_name'] ?? ''));
                    yield $row;
                }
            } catch (\Throwable $e) {
                Log::debug("topology plugin: cannot read $table: " . $e->getMessage());
            }
        }
    }

    /**
     * Devices among $ids with an interface address out of a list.
     *
     * @param  string[]  $addresses  canonical text (as inet_ntop() writes it, which is how LibreNMS stores them)
     * @param  int[]  $ids
     * @return int[]
     */
    public function devicesWithAddresses(array $addresses, array $ids): array
    {
        if (! $addresses || ! $ids) {
            return [];
        }

        $devices = [];
        foreach (self::ADDRESS_TABLES as [$table, , $address]) {
            foreach (array_chunk($addresses, 1000) as $chunk) {
                try {
                    foreach (DB::table("$table as a")->join('ports as p', 'p.port_id', '=', 'a.port_id')
                        ->whereIntegerInRaw('p.device_id', $ids)->where('p.deleted', 0)->whereIn("a.$address", $chunk)
                        ->distinct()->pluck('p.device_id') as $deviceId) {
                        $devices[(int) $deviceId] = true;
                    }
                } catch (\Throwable $e) {
                    Log::debug("topology plugin: cannot read $table: " . $e->getMessage());
                }
            }
        }

        return array_keys($devices);
    }

    /**
     * VRFs of each device, with the local AS of the VRFs that have their own.
     *
     * @param  int[]  $ids
     * @return array<int, array<string, ?int>> device => VRF name => AS
     */
    public function vrfs(array $ids): array
    {
        $result = [];
        foreach ($this->rows('vrfs', $ids, $this->existing('vrfs', ['device_id', 'vrf_name', 'bgpLocalAs'])) as $row) {
            if ((string) ($row['vrf_name'] ?? '') !== '') {
                $result[(int) $row['device_id']][(string) $row['vrf_name']] = (int) ($row['bgpLocalAs'] ?? 0) ?: null;
            }
        }

        return $result;
    }

    /**
     * Active LLDP/CDP links between the devices, with their ports and the ports' names.
     *
     * @param  int[]  $ids
     */
    public function links(array $ids): \Generator
    {
        if (! $ids) {
            return;
        }

        try {
            foreach (DB::table('links as l')->leftJoin('ports as lp', 'lp.port_id', '=', 'l.local_port_id')->leftJoin('ports as rp', 'rp.port_id', '=', 'l.remote_port_id')
                ->where('l.active', 1)->whereIntegerInRaw('l.local_device_id', $ids)->whereIntegerInRaw('l.remote_device_id', $ids)
                ->select(['l.local_device_id', 'l.local_port_id', 'l.remote_device_id', 'l.remote_port_id', 'lp.ifName as local_ifName', 'lp.ifDescr as local_ifDescr',
                    'rp.ifName as remote_ifName', 'rp.ifDescr as remote_ifDescr'])->cursor() as $link) {
                yield (array) $link;
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read links: ' . $e->getMessage());
        }
    }

    /**
     * @param  int[]  $ids
     */
    private function peers(array $ids): \Generator
    {
        $vrfs = $this->vrfNames($ids);
        foreach ($this->stream('bgpPeers', $ids, $this->existing('bgpPeers', self::BGP_PEER_COLUMNS)) as $row) {
            $row['vrf'] = (string) ((! empty($row['vrf_id']) ? ($vrfs[(int) $row['vrf_id']] ?? '') : '') ?: ($row['context_name'] ?? ''));
            yield $row;
        }
    }

    /**
     * Rows of a per-device table, one at a time; none when the table is missing (no columns found).
     *
     * @param  int[]  $ids
     * @param  string[]  $columns
     */
    private function stream(string $table, array $ids, array $columns): \Generator
    {
        if (! $ids || ! $columns) {
            return;
        }

        try {
            foreach (DB::table($table)->whereIntegerInRaw('device_id', $ids)->select($columns)->cursor() as $row) {
                yield (array) $row;
            }
        } catch (\Throwable $e) {
            Log::debug("topology plugin: cannot read $table: " . $e->getMessage());
        }
    }

    /**
     * VRF names by vrf_id, read once per device.
     *
     * @param  int[]  $ids
     * @return array<int, string> vrf_id => name (of these devices and of those read before)
     */
    private function vrfNames(array $ids): array
    {
        $missing = array_values(array_filter($ids, fn ($id) => ! isset($this->vrfsRead[$id])));
        if ($missing) {
            foreach ($this->rows('vrfs', $missing, $this->existing('vrfs', ['vrf_id', 'device_id', 'vrf_name'])) as $row) {
                $this->vrfNames[(int) $row['vrf_id']] = (string) ($row['vrf_name'] ?? '');
            }
            $this->vrfsRead += array_fill_keys($missing, true);
        }

        return $this->vrfNames;
    }

    /**
     * The wanted columns that the table has (none when it does not exist, so rows() returns nothing).
     *
     * @param  string[]  $wanted
     * @return string[]
     */
    private function existing(string $table, array $wanted): array
    {
        if (! isset($this->columns[$table])) {
            try {
                $this->columns[$table] = array_flip(Schema::getColumnListing($table));
            } catch (\Throwable $e) {
                Log::debug("topology plugin: cannot list the columns of $table: " . $e->getMessage());
                $this->columns[$table] = [];
            }
        }
        $columns = array_values(array_filter($wanted, fn ($column) => isset($this->columns[$table][$column])));

        return in_array('device_id', $columns, true) ? $columns : [];
    }

    /**
     * Rows of a per-device table; nothing when the table is missing (no columns found).
     *
     * @param  int[]  $ids
     * @param  string[]  $columns
     */
    protected function rows(string $table, array $ids, array $columns): array
    {
        return $columns ? parent::rows($table, $ids, $columns) : [];
    }
}
