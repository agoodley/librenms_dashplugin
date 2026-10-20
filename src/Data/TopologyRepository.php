<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use App\Models\Device;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * All database access of the plugin. Everything is scoped to the devices the user may see and
 * returned as plain arrays for the (LibreNMS independent) builders and analyzers.
 */
class TopologyRepository
{
    private const PORT_COLUMNS = [
        'port_id', 'device_id', 'ifIndex', 'ifName', 'ifDescr', 'ifAlias', 'ifSpeed', 'ifOperStatus',
        'ifAdminStatus', 'ifType', 'ifVlan', 'ifTrunk', 'ifDuplex', 'ifMtu', 'ifPhysAddress',
        'ifInOctets_rate', 'ifOutOctets_rate', 'ifInErrors_rate', 'ifOutErrors_rate',
    ];

    /** @var int[]|null */
    private ?array $accessible = null;

    public function __construct(private readonly mixed $user)
    {
    }

    /**
     * Devices the user may see, optionally limited to a device group and/or location.
     *
     * @return int[]
     */
    public function deviceIds(?int $groupId = null, ?int $locationId = null): array
    {
        $query = Device::hasAccess($this->user)->where('devices.disabled', 0);
        if ($groupId) {
            $query->inDeviceGroup($groupId);
        }
        if ($locationId) {
            $query->where('devices.location_id', $locationId);
        }

        return array_map('intval', $query->pluck('devices.device_id')->all());
    }

    /**
     * @return array<int, true>
     */
    private function accessible(): array
    {
        if ($this->accessible === null) {
            $this->accessible = array_flip($this->deviceIds());
        }

        return $this->accessible;
    }

    /**
     * @param  int[]  $ids
     * @return array<int, array> device_id => row (with role and url)
     */
    public function devices(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $rows = DB::table('devices')
            ->leftJoin('locations', 'devices.location_id', '=', 'locations.id')
            ->whereIntegerInRaw('devices.device_id', $ids)
            ->get([
                'devices.device_id', 'devices.hostname', 'devices.sysName', 'devices.display', 'devices.hardware',
                'devices.os', 'devices.type', 'devices.status', 'devices.disabled', 'devices.ignore', 'devices.version',
                'devices.uptime', 'devices.bgpLocalAs', 'locations.location as location',
            ]);

        $facts = $this->roleFacts($ids);
        $devices = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $id = (int) $row['device_id'];
            $row['role'] = DeviceRole::classify($row, $facts[$id] ?? []);
            $row['url'] = url('device/' . $id);
            $devices[$id] = $row;
        }

        return $devices;
    }

    /**
     * Facts used to pick the icon of a device (bridging, routed interfaces, routing protocols).
     *
     * @param  int[]  $ids
     * @return array<int, array{bridge: bool, vlans: int, l3_interfaces: int, routing: bool}>
     */
    private function roleFacts(array $ids): array
    {
        $facts = [];
        $default = ['bridge' => false, 'vlans' => 0, 'l3_interfaces' => 0, 'routing' => false];

        foreach (DB::table('vlans')->whereIntegerInRaw('device_id', $ids)->groupBy('device_id')
            ->selectRaw('device_id, COUNT(*) as c')->pluck('c', 'device_id') as $id => $count) {
            $facts[$id] = ($facts[$id] ?? $default);
            $facts[$id]['vlans'] = (int) $count;
        }

        foreach ($this->distinctDevices('stp', $ids) as $id) {
            $facts[$id] = ($facts[$id] ?? $default);
            $facts[$id]['bridge'] = true;
        }

        foreach (DB::table('ipv4_addresses as a')->join('ports as p', 'p.port_id', '=', 'a.port_id')
            ->whereIntegerInRaw('p.device_id', $ids)->where('a.ipv4_prefixlen', '<', 32)
            ->groupBy('p.device_id')->selectRaw('p.device_id, COUNT(DISTINCT a.port_id) as c')
            ->pluck('c', 'device_id') as $id => $count) {
            $facts[$id] = ($facts[$id] ?? $default);
            $facts[$id]['l3_interfaces'] = (int) $count;
        }

        foreach (['ospf_nbrs', 'bgpPeers'] as $table) {
            foreach ($this->distinctDevices($table, $ids) as $id) {
                $facts[$id] = ($facts[$id] ?? $default);
                $facts[$id]['routing'] = true;
            }
        }

        return $facts;
    }

    /**
     * @return int[]
     */
    private function distinctDevices(string $table, array $ids): array
    {
        try {
            return array_map('intval', DB::table($table)->whereIntegerInRaw('device_id', $ids)->distinct()->pluck('device_id')->all());
        } catch (\Throwable $e) {
            Log::debug("topology plugin: cannot read $table: " . $e->getMessage());

            return [];
        }
    }

    /**
     * Active neighbour links (LLDP, CDP, xDP...) seen by the given devices. Remote devices the
     * user may not see are anonymized into unmanaged neighbours.
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
        $links = [];
        foreach (DB::table('links')->where('active', 1)->whereIntegerInRaw('local_device_id', $ids)
            ->get(['id', 'local_device_id', 'local_port_id', 'remote_device_id', 'remote_port_id', 'remote_hostname',
                'remote_port', 'remote_platform', 'remote_version', 'protocol']) as $link) {
            $link = (array) $link;
            if ($link['remote_device_id'] && ! isset($accessible[(int) $link['remote_device_id']])) {
                $link['remote_device_id'] = 0;
                $link['remote_port_id'] = null;
            }
            $links[] = $link;
        }

        return $links;
    }

    /**
     * @param  int[]  $portIds
     * @return array<int, array> port_id => row (with url)
     */
    public function ports(array $portIds): array
    {
        $portIds = array_values(array_unique(array_filter(array_map('intval', $portIds))));
        if (! $portIds) {
            return [];
        }

        $ports = [];
        foreach (array_chunk($portIds, 5000) as $chunk) {
            foreach (DB::table('ports')->whereIntegerInRaw('port_id', $chunk)->get(self::PORT_COLUMNS) as $row) {
                $row = (array) $row;
                $row['url'] = url('device/device=' . $row['device_id'] . '/tab=port/port=' . $row['port_id'] . '/');
                $ports[(int) $row['port_id']] = $row;
            }
        }

        return $ports;
    }

    /**
     * Aggregation membership: member port_id => aggregate (port-channel/ae/bond) port_id.
     * Uses IF-MIB ifStackTable (ports_stack) and Cisco PAgP/LACP group data.
     *
     * @param  int[]  $ids
     * @return array<int, int>
     */
    public function lagParents(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $pairs = [];
        try {
            [$high, $low] = Schema::hasColumn('ports_stack', 'high_port_id')
                ? ['high_port_id', 'low_port_id']
                : ['port_id_high', 'port_id_low'];

            foreach (DB::table('ports_stack')->whereIntegerInRaw('device_id', $ids)
                ->where($high, '>', 0)->where($low, '>', 0)
                ->get([$high . ' as high', $low . ' as low']) as $row) {
                $pairs[(int) $row->low][] = (int) $row->high;
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read ports_stack: ' . $e->getMessage());
        }

        try {
            foreach (DB::table('ports as m')
                ->join('ports as p', function ($join): void {
                    $join->on('p.device_id', '=', 'm.device_id')->on('p.ifIndex', '=', 'm.pagpGroupIfIndex');
                })
                ->whereIntegerInRaw('m.device_id', $ids)
                ->where('m.pagpGroupIfIndex', '>', 0)
                ->whereColumn('m.pagpGroupIfIndex', '<>', 'm.ifIndex')
                ->get(['m.port_id as low', 'p.port_id as high']) as $row) {
                $pairs[(int) $row->low][] = (int) $row->high;
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read pagp data: ' . $e->getMessage());
        }

        if (! $pairs) {
            return [];
        }

        // keep only real aggregates (not sub-interfaces, VLAN interfaces, tunnels...)
        $highIds = array_values(array_unique(array_merge(...array_values($pairs))));
        $parents = DB::table('ports')->whereIntegerInRaw('port_id', $highIds)
            ->get(['port_id', 'ifType', 'ifName', 'ifDescr'])->keyBy('port_id');

        $result = [];
        foreach ($pairs as $low => $highs) {
            foreach ($highs as $high) {
                $parent = $parents[$high] ?? null;
                if ($parent && $high !== $low && ($parent->ifType === 'ieee8023adLag' || InterfaceName::isLagName($parent->ifName ?: $parent->ifDescr))) {
                    $result[$low] = $high;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * @param  int[]  $portIds
     * @return array<int, array<int, bool>> port_id => [vlan => untagged]
     */
    public function portVlans(array $portIds): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique($portIds)), 5000) as $chunk) {
            foreach (DB::table('ports_vlans')->whereIntegerInRaw('port_id', $chunk)->get(['port_id', 'vlan', 'untagged']) as $row) {
                $result[(int) $row->port_id][(int) $row->vlan] = (bool) $row->untagged;
            }
        }

        return $result;
    }

    /**
     * @param  int[]  $ids
     * @return array<int, array<int, string>> device_id => [vlan => name]
     */
    public function deviceVlans(array $ids): array
    {
        $result = [];
        if (! $ids) {
            return $result;
        }

        foreach (DB::table('vlans')->whereIntegerInRaw('device_id', $ids)->get(['device_id', 'vlan_vlan', 'vlan_name']) as $row) {
            if ($row->vlan_vlan !== null) {
                $result[(int) $row->device_id][(int) $row->vlan_vlan] = (string) $row->vlan_name;
            }
        }

        return $result;
    }

    /**
     * STP instance and port rows; $vlan limits the load to that VLAN plus the default instance.
     *
     * @param  int[]  $ids
     * @return array{0: array, 1: array}
     */
    public function stpRows(array $ids, ?int $vlan = null): array
    {
        if (! $ids) {
            return [[], []];
        }

        $filter = function (Builder $query) use ($vlan): void {
            if ($vlan !== null) {
                $query->where(fn (Builder $q) => $q->whereNull('vlan')->orWhereIn('vlan', array_unique([0, 1, $vlan])));
            }
        };

        $instances = DB::table('stp')->whereIntegerInRaw('device_id', $ids)->tap($filter)->get()->map(fn ($r) => (array) $r)->all();
        $ports = DB::table('ports_stp')->whereIntegerInRaw('device_id', $ids)->tap($filter)->get()->map(fn ($r) => (array) $r)->all();

        return [$instances, $ports];
    }

    public function stpPortCount(array $ids): int
    {
        return $ids ? DB::table('ports_stp')->whereIntegerInRaw('device_id', $ids)->count() : 0;
    }

    /**
     * VLAN keys having STP instances among the devices.
     *
     * @param  int[]  $ids
     * @return int[]
     */
    public function stpVlans(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $keys = DB::table('stp')->whereIntegerInRaw('device_id', $ids)->distinct()->pluck('vlan')
            ->map(fn ($vlan) => StpIndex::vlanKey($vlan))->unique()->sort()->values()->all();

        return array_map('intval', $keys);
    }

    /**
     * @param  int[]  $ids
     */
    public function stpIndex(array $ids, ?int $vlan, array $lagParent): StpIndex
    {
        [$instances, $ports] = $this->stpRows($ids, $vlan);

        return new StpIndex($instances, $ports, $lagParent);
    }

    /**
     * Port ids that carry IPv4 addresses (routed ports).
     *
     * @param  int[]  $portIds
     * @return array<int, true>
     */
    public function portsWithIp(array $portIds): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique($portIds)), 5000) as $chunk) {
            foreach (DB::table('ipv4_addresses')->whereIntegerInRaw('port_id', $chunk)->distinct()->pluck('port_id') as $portId) {
                $result[(int) $portId] = true;
            }
        }

        return $result;
    }

    /**
     * IPv4 (and optionally IPv6) interface addresses of the devices.
     *
     * @param  int[]  $ids
     */
    public function addresses(array $ids, bool $ipv6 = false): array
    {
        if (! $ids) {
            return [];
        }

        $vrfs = DB::table('vrfs')->whereIntegerInRaw('device_id', $ids)->pluck('vrf_name', 'vrf_id')->all();
        $columns = ['p.device_id', 'p.port_id', 'p.ifName', 'p.ifDescr', 'p.ifAlias', 'p.ifOperStatus', 'p.ifAdminStatus', 'p.ifType', 'p.ifVrf'];

        $rows = [];
        $query = DB::table('ipv4_addresses as a')->join('ports as p', 'p.port_id', '=', 'a.port_id')
            ->whereIntegerInRaw('p.device_id', $ids)->where('p.deleted', 0)
            ->get(array_merge($columns, ['a.ipv4_address as address', 'a.ipv4_prefixlen as prefixlen', 'a.context_name']));
        foreach ($query as $row) {
            $rows[] = $this->addressRow((array) $row, $vrfs);
        }

        if ($ipv6) {
            $query = DB::table('ipv6_addresses as a')->join('ports as p', 'p.port_id', '=', 'a.port_id')
                ->whereIntegerInRaw('p.device_id', $ids)->where('p.deleted', 0)
                ->get(array_merge($columns, ['a.ipv6_compressed as address', 'a.ipv6_prefixlen as prefixlen', 'a.context_name']));
            foreach ($query as $row) {
                $rows[] = $this->addressRow((array) $row, $vrfs);
            }
        }

        return $rows;
    }

    private function addressRow(array $row, array $vrfs): array
    {
        $row['vrf'] = (string) (($row['ifVrf'] ? ($vrfs[$row['ifVrf']] ?? '') : '') ?: ($row['context_name'] ?? ''));
        $row['url'] = url('device/device=' . $row['device_id'] . '/tab=port/port=' . $row['port_id'] . '/');

        return $row;
    }

    /**
     * @param  int[]  $ids
     */
    public function ospfNeighbors(array $ids): array
    {
        return $this->rows('ospf_nbrs', $ids, ['device_id', 'port_id', 'ospfNbrIpAddr', 'ospfNbrRtrId', 'ospfNbrState', 'context_name']);
    }

    /**
     * @param  int[]  $ids
     */
    public function bgpPeers(array $ids): array
    {
        return $this->rows('bgpPeers', $ids, ['device_id', 'bgpPeerIdentifier', 'bgpPeerRemoteAs', 'bgpPeerState', 'bgpPeerAdminStatus', 'bgpLocalAddr', 'bgpPeerDescr', 'context_name']);
    }

    private function rows(string $table, array $ids, array $columns): array
    {
        if (! $ids) {
            return [];
        }

        try {
            return DB::table($table)->whereIntegerInRaw('device_id', $ids)->get($columns)->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable $e) {
            Log::debug("topology plugin: cannot read $table: " . $e->getMessage());

            return [];
        }
    }

    /**
     * Recent spanning tree eventlog entries (designated root / root port / topology changes).
     *
     * @param  int[]  $ids
     */
    public function stpEvents(array $ids, int $days): array
    {
        if (! $ids) {
            return [];
        }

        return DB::table('eventlog')->where('type', 'stp')->whereIntegerInRaw('device_id', $ids)
            ->where('datetime', '>=', date('Y-m-d H:i:s', time() - $days * 86400))
            ->orderByDesc('datetime')->limit(500)
            ->get(['device_id', 'datetime', 'message', 'severity'])->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Find where unknown root bridge MACs live: interface MACs / bridge addresses of monitored
     * devices, FDB entries and the OUI vendor.
     *
     * @param  string[]  $macs  12 hex characters
     * @return array<string, array{owners: array, fdb: array, vendor: string}>
     */
    public function rootLookups(array $macs): array
    {
        $macs = array_values(array_unique(array_map('strval', $macs)));
        if (! $macs) {
            return [];
        }

        $accessible = $this->accessible();
        $result = [];
        foreach ($macs as $mac) {
            // hidden: the bridge is a monitored device this user may not see (do not reveal which)
            $result[$mac] = ['owners' => [], 'fdb' => [], 'vendor' => '', 'hidden' => false];
        }

        foreach (DB::table('stp')->whereIn('bridgeAddress', $macs)->get(['device_id', 'bridgeAddress']) as $row) {
            if (isset($accessible[(int) $row->device_id])) {
                $result[(string) $row->bridgeAddress]['owners'][] = ['device_id' => (int) $row->device_id, 'port_id' => null];
            } else {
                $result[(string) $row->bridgeAddress]['hidden'] = true;
            }
        }

        foreach (DB::table('ports')->whereIn('ifPhysAddress', $macs)->limit(200)->get(['device_id', 'port_id', 'ifPhysAddress']) as $row) {
            if (isset($accessible[(int) $row->device_id])) {
                $result[(string) $row->ifPhysAddress]['owners'][] = ['device_id' => (int) $row->device_id, 'port_id' => (int) $row->port_id];
            } else {
                $result[(string) $row->ifPhysAddress]['hidden'] = true;
            }
        }

        try {
            foreach (DB::table('ports_fdb as f')->leftJoin('vlans as v', 'v.vlan_id', '=', 'f.vlan_id')
                ->whereIn('f.mac_address', $macs)->limit(200)
                ->get(['f.device_id', 'f.port_id', 'f.mac_address', 'v.vlan_vlan']) as $row) {
                if (isset($accessible[(int) $row->device_id])) {
                    $result[(string) $row->mac_address]['fdb'][] = ['device_id' => (int) $row->device_id, 'port_id' => (int) $row->port_id, 'vlan' => $row->vlan_vlan];
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read ports_fdb: ' . $e->getMessage());
        }

        if (class_exists(\LibreNMS\Util\Mac::class) && method_exists(\LibreNMS\Util\Mac::class, 'vendor')) {
            foreach ($macs as $mac) {
                try {
                    $result[$mac]['vendor'] = (string) \LibreNMS\Util\Mac::parse($mac)->vendor();
                } catch (\Throwable) {
                    // vendor database not available
                }
            }
        }

        // ports on the same device as fdb sightings are needed for names
        return $result;
    }

    /**
     * Port rows for STP analysis: names plus has_ip / switchport flags.
     *
     * @param  int[]  $portIds
     */
    public function analysisPorts(array $portIds): array
    {
        $ports = $this->ports($portIds);
        $withIp = $this->portsWithIp(array_keys($ports));
        $vlans = $this->portVlans(array_keys($ports));
        foreach ($ports as $portId => &$port) {
            $port['has_ip'] = isset($withIp[$portId]);
            $port['switchport'] = isset($vlans[$portId]) || ! empty($port['ifTrunk']) || (is_numeric($port['ifVlan']) && (int) $port['ifVlan'] > 0);
        }

        return $ports;
    }

    /**
     * Device groups and locations the user may filter by.
     */
    public function filterOptions(): array
    {
        $groups = [];
        $locations = [];
        try {
            $groups = \App\Models\DeviceGroup::hasAccess($this->user)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($g) => ['id' => (int) $g->id, 'name' => (string) $g->name])->all();
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot list device groups: ' . $e->getMessage());
        }
        try {
            $locations = \App\Models\Location::hasAccess($this->user)->orderBy('location')->get(['id', 'location'])
                ->map(fn ($l) => ['id' => (int) $l->id, 'name' => (string) $l->location])->all();
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot list locations: ' . $e->getMessage());
        }

        return ['groups' => $groups, 'locations' => $locations];
    }

    public function deviceName(int $deviceId): ?string
    {
        if (! isset($this->accessible()[$deviceId])) {
            return null;
        }
        $row = DB::table('devices')->where('device_id', $deviceId)->first(['display', 'hostname']);

        return $row ? (string) ($row->display ?: $row->hostname) : null;
    }
}
