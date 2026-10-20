<?php

namespace Agoodley\LibrenmsTopology\Data;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Database access of the routing & IP diagnostics: OSPF, BGP, interface addresses, VRFs and
 * LLDP/CDP neighbors of the devices the user may see. Columns that older LibreNMS versions lack
 * are left out of the query instead of failing the whole table.
 */
class RoutingRepository extends ScopedRepository
{
    private const DEVICE_COLUMNS = ['device_id', 'hostname', 'sysName', 'display', 'status', 'uptime', 'bgpLocalAs'];

    private const OSPF_COLUMNS = [
        'ospf_instances' => ['device_id', 'ospfRouterId', 'ospfAdminStat', 'ospfAreaBdrRtrStatus', 'ospfASBdrRtrStatus', 'context_name'],
        'ospf_areas' => ['device_id', 'ospfAreaId', 'ospfAreaLsaCount', 'ospfAreaLsaCksumSum', 'ospfImportAsExtern', 'context_name'],
        'ospf_ports' => [
            'device_id', 'port_id', 'ospfIfIpAddress', 'ospfAddressLessIf', 'ospfIfAreaId', 'ospfIfType', 'ospfIfAdminStat',
            'ospfIfHelloInterval', 'ospfIfRtrDeadInterval', 'ospfIfState', 'ospfIfDesignatedRouter', 'ospfIfAuthType', 'ospfIfMetricValue',
            'context_name',
        ],
        'ospf_nbrs' => ['device_id', 'port_id', 'ospfNbrIpAddr', 'ospfNbrRtrId', 'ospfNbrState', 'context_name'],
    ];

    private const BGP_PEER_COLUMNS = [
        'device_id', 'vrf_id', 'astext', 'bgpPeerIdentifier', 'bgpPeerRemoteAs', 'bgpPeerState', 'bgpPeerAdminStatus',
        'bgpPeerLastErrorCode', 'bgpPeerLastErrorSubCode', 'bgpPeerLastErrorText', 'bgpLocalAddr', 'bgpPeerRemoteAddr', 'bgpPeerDescr',
        'bgpPeerFsmEstablishedTime', 'context_name',
    ];

    private const BGP_PREFIX_COLUMNS = [
        'device_id', 'bgpPeerIdentifier', 'afi', 'safi', 'AcceptedPrefixes', 'DeniedPrefixes', 'PrefixAdminLimit', 'PrefixThreshold', 'context_name',
    ];

    /** @var array<string, array<string, int>> table => its columns */
    private array $columns = [];

    /** @var array<int, string>|null vrf_id => name */
    private ?array $vrfNames = null;

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
     * @param  int[]  $ids
     * @return array{instances: array, areas: array, ports: array, neighbors: array}
     */
    public function ospf(array $ids): array
    {
        $rows = [];
        foreach (self::OSPF_COLUMNS as $table => $columns) {
            $rows[$table] = $this->rows($table, $ids, $this->existing($table, $columns));
        }

        return [
            'instances' => $rows['ospf_instances'],
            'areas' => $rows['ospf_areas'],
            'ports' => $rows['ospf_ports'],
            'neighbors' => $rows['ospf_nbrs'],
        ];
    }

    /**
     * BGP sessions (with 'vrf', the name of their VRF) and the prefix counters of bgpPeers_cbgp.
     *
     * @param  int[]  $ids
     * @return array{peers: array, prefixes: array}
     */
    public function bgp(array $ids): array
    {
        $vrfs = $this->vrfNames($ids);
        $peers = [];
        foreach ($this->rows('bgpPeers', $ids, $this->existing('bgpPeers', self::BGP_PEER_COLUMNS)) as $row) {
            $row['vrf'] = (string) ((! empty($row['vrf_id']) ? ($vrfs[(int) $row['vrf_id']] ?? '') : '') ?: ($row['context_name'] ?? ''));
            $peers[] = $row;
        }

        return [
            'peers' => $peers,
            'prefixes' => $this->rows('bgpPeers_cbgp', $ids, $this->existing('bgpPeers_cbgp', self::BGP_PREFIX_COLUMNS)),
        ];
    }

    /**
     * IPv4 and IPv6 interface addresses with their port and VRF name (the port's VRF, else the
     * SNMP context the address was read in).
     *
     * @param  int[]  $ids
     */
    public function addresses(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $vrfs = $this->vrfNames($ids);
        $columns = array_map(fn ($c) => "p.$c", $this->existing('ports', ['device_id', 'port_id', 'ifName', 'ifDescr', 'ifType', 'ifMtu', 'ifOperStatus', 'ifVrf']));
        $rows = [];
        foreach ([['ipv4_addresses', 'ipv4_address', 'ipv4_prefixlen'], ['ipv6_addresses', 'ipv6_compressed', 'ipv6_prefixlen']] as [$table, $address, $prefix]) {
            try {
                $query = DB::table("$table as a")->join('ports as p', 'p.port_id', '=', 'a.port_id')
                    ->whereIntegerInRaw('p.device_id', $ids)->where('p.deleted', 0)
                    ->get(array_merge($columns, ["a.$address as address", "a.$prefix as prefixlen", 'a.context_name']));
            } catch (\Throwable $e) {
                Log::debug("topology plugin: cannot read $table: " . $e->getMessage());
                continue;
            }
            foreach ($query as $row) {
                $row = (array) $row;
                $row['vrf'] = (string) ((! empty($row['ifVrf']) ? ($vrfs[(int) $row['ifVrf']] ?? '') : '') ?: ($row['context_name'] ?? ''));
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Local AS of the VRFs that have their own.
     *
     * @param  int[]  $ids
     * @return array<int, array<string, int>> device => VRF name => AS
     */
    public function vrfAs(array $ids): array
    {
        $result = [];
        foreach ($this->rows('vrfs', $ids, $this->existing('vrfs', ['device_id', 'vrf_name', 'bgpLocalAs'])) as $row) {
            if ((int) ($row['bgpLocalAs'] ?? 0) > 0 && (string) ($row['vrf_name'] ?? '') !== '') {
                $result[(int) $row['device_id']][(string) $row['vrf_name']] = (int) $row['bgpLocalAs'];
            }
        }

        return $result;
    }

    /**
     * Devices with an active LLDP/CDP link between them, both directions.
     *
     * @param  int[]  $ids
     * @return array<int, array<int, true>>
     */
    public function lldpNeighbors(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $result = [];
        try {
            foreach (DB::table('links')->where('active', 1)->whereIntegerInRaw('local_device_id', $ids)->whereIntegerInRaw('remote_device_id', $ids)
                ->distinct()->get(['local_device_id', 'remote_device_id']) as $link) {
                $a = (int) $link->local_device_id;
                $b = (int) $link->remote_device_id;
                if ($a !== $b) {
                    $result[$a][$b] = true;
                    $result[$b][$a] = true;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read links: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * @param  int[]  $ids
     * @return array<int, string> vrf_id => name
     */
    private function vrfNames(array $ids): array
    {
        if ($this->vrfNames === null) {
            $this->vrfNames = [];
            foreach ($this->rows('vrfs', $ids, $this->existing('vrfs', ['vrf_id', 'device_id', 'vrf_name'])) as $row) {
                $this->vrfNames[(int) $row['vrf_id']] = (string) ($row['vrf_name'] ?? '');
            }
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
