<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Redundancy\PortUnits;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PortStack;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Database access of the maps and the spanning tree, link and redundancy diagnostics. Everything is
 * scoped to the devices the user may see (ScopedRepository) and returned as plain arrays for the
 * (LibreNMS independent) builders and analyzers.
 */
class TopologyRepository extends ScopedRepository
{
    /** @var array<string, array<int, array{0: int, 1: int}>> stackPairs() of each set of devices read in this request */
    private array $stackPairs = [];

    private const PORT_COLUMNS = [
        'port_id', 'device_id', 'ifIndex', 'ifName', 'ifDescr', 'ifAlias', 'ifSpeed', 'ifOperStatus',
        'ifAdminStatus', 'ifType', 'ifVlan', 'ifTrunk', 'ifDuplex', 'ifMtu', 'ifPhysAddress',
        'ifInOctets_rate', 'ifOutOctets_rate', 'ifInErrors_rate', 'ifOutErrors_rate',
        'ifInErrors_delta', 'ifOutErrors_delta', 'ifInUcastPkts_delta', 'ifOutUcastPkts_delta', 'poll_period',
    ];

    /**
     * @param  int[]  $ids
     * @param  bool  $roles  classify with the facts about bridging and routing (several queries); false
     *                       classifies from the device type alone
     * @return array<int, array> device_id => row (with role and url)
     */
    public function devices(array $ids, bool $roles = true): array
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

        $facts = $roles ? $this->roleFacts($ids) : [];
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

        // routed interfaces on ports LibreNMS still has (a deleted port no longer routes). HostService::routes() counts the
        // addresses of deleted ports too, on purpose (decided in the host locator's review): the two can differ for a device
        // with a deleted routed port
        foreach ($this->allowsTable('ipv4_addresses') ? DB::table('ipv4_addresses as a')->join('ports as p', 'p.port_id', '=', 'a.port_id')
            ->whereIntegerInRaw('p.device_id', $ids)->where('p.deleted', 0)->where('a.ipv4_prefixlen', '<', 32)
            ->groupBy('p.device_id')->selectRaw('p.device_id, COUNT(DISTINCT a.port_id) as c')
            ->pluck('c', 'device_id') : [] as $id => $count) {
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
     * Active neighbour links (LLDP, CDP, xDP...) seen by the given devices. Remote devices the
     * user may not see are anonymized into unmanaged neighbours.
     *
     * @param  int[]  $ids
     * @return array<int, array>
     */
    public function links(array $ids): array
    {
        if (! $ids || ! $this->allowsTable('links')) {
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
     * The rows of links() that $keep accepts, streamed: a network's thousands of phones and servers
     * are never held at once by callers that only need some of them or a count. The query is links()'
     * own, so the rows come in its order; each kept row is keyed by its position among all the rows of
     * these devices, so that the rows two reads of the same devices keep merge back into that order
     * ($first + $second, then ksort()).
     *
     * @param  int[]  $ids
     * @param  callable(array): bool  $keep  gets every row, anonymized like links() does
     * @return array<int, array> position => kept row
     */
    public function linksWhere(array $ids, callable $keep): array
    {
        if (! $ids || ! $this->allowsTable('links')) {
            return [];
        }

        $accessible = $this->accessible();
        $links = [];
        $position = 0;
        foreach (DB::table('links')->where('active', 1)->whereIntegerInRaw('local_device_id', $ids)
            ->select(['id', 'local_device_id', 'local_port_id', 'remote_device_id', 'remote_port_id', 'remote_hostname',
                'remote_port', 'remote_platform', 'remote_version', 'protocol'])->cursor() as $link) {
            $link = (array) $link;
            if ($link['remote_device_id'] && ! isset($accessible[(int) $link['remote_device_id']])) {
                $link['remote_device_id'] = 0;
                $link['remote_port_id'] = null;
            }
            if ($keep($link)) {
                $links[$position] = $link;
            }
            $position++;
        }

        return $links;
    }

    /**
     * Port descriptions (ifAlias) of these ports that are set, streamed: port_id => description.
     *
     * @param  int[]  $portIds
     * @return iterable<int, string>
     */
    public function portDescriptions(array $portIds): iterable
    {
        if (! $this->allowsTable('ports')) {
            return;
        }
        foreach (array_chunk(array_values(array_unique(array_filter(array_map('intval', $portIds)))), 5000) as $chunk) {
            foreach (DB::table('ports')->whereIntegerInRaw('port_id', $chunk)->where('ifAlias', '<>', '')
                ->select(['port_id', 'ifAlias'])->cursor() as $row) {
                yield (int) $row->port_id => (string) $row->ifAlias;
            }
        }
    }

    /**
     * Ports of these that are not up (ifOperStatus down, lowerLayerDown...), streamed; ports without a status count as up
     * (L2Builder::isUp()).
     *
     * @param  int[]  $portIds
     * @return iterable<int, int> port ids
     */
    public function portsNotUp(array $portIds): iterable
    {
        if (! $this->allowsTable('ports')) {
            return;
        }
        foreach (array_chunk(array_values(array_unique(array_filter(array_map('intval', $portIds)))), 5000) as $chunk) {
            foreach (DB::table('ports')->whereIntegerInRaw('port_id', $chunk)->whereNotNull('ifOperStatus')
                ->whereNotIn('ifOperStatus', ['', 'up'])->select(['port_id'])->cursor() as $row) {
                yield (int) $row->port_id;
            }
        }
    }

    /**
     * How many LLDP/CDP links of these devices lead to devices the user may not see (links() hides their far end).
     *
     * @param  int[]  $ids
     */
    public function hiddenLinkCount(array $ids): int
    {
        if (! $ids || ! $this->allowsTable('links')) {
            return 0;
        }

        $accessible = $this->accessible();
        $count = 0;
        foreach (DB::table('links')->where('active', 1)->whereIntegerInRaw('local_device_id', $ids)->where('remote_device_id', '>', 0)
            ->pluck('remote_device_id') as $remote) {
            $count += isset($accessible[(int) $remote]) ? 0 : 1;
        }

        return $count;
    }

    /**
     * @param  int[]  $portIds
     * @param  string[]|null  $columns  only these columns and no url (large analyses)
     * @return array<int, array> port_id => row (with url)
     */
    public function ports(array $portIds, ?array $columns = null): array
    {
        $portIds = array_values(array_unique(array_filter(array_map('intval', $portIds))));
        if (! $portIds) {
            return [];
        }

        $ports = [];
        foreach (array_chunk($portIds, 5000) as $chunk) {
            foreach (DB::table('ports')->whereIntegerInRaw('port_id', $chunk)->get($columns ?? self::PORT_COLUMNS) as $row) {
                $row = (array) $row;
                if ($columns === null) {
                    $row['url'] = url('device/device=' . $row['device_id'] . '/tab=port/port=' . $row['port_id'] . '/');
                }
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
     * @param  bool  $strict  throw read errors (the topology history skips the port-channels then) instead of
     *                        returning what could be read (the maps)
     * @return array<int, int>
     */
    public function lagParents(array $ids, bool $strict = false): array
    {
        if (! $ids || ! $this->allowsTable('ports_stack')) {
            return [];
        }

        $pairs = $this->stackPairs($ids, $strict);

        // Cisco PAgP / LACP group membership
        try {
            foreach (DB::table('ports as m')
                ->join('ports as p', function ($join): void {
                    $join->on('p.device_id', '=', 'm.device_id')->on('p.ifIndex', '=', 'm.pagpGroupIfIndex');
                })
                ->whereIntegerInRaw('m.device_id', $ids)
                ->where('m.pagpGroupIfIndex', '>', 0)
                ->whereColumn('m.pagpGroupIfIndex', '<>', 'm.ifIndex')
                ->get(['m.port_id as member', 'p.port_id as aggregate']) as $row) {
                $pairs[] = [(int) $row->aggregate, (int) $row->member];
            }
        } catch (\Throwable $e) {
            if ($strict) {
                throw $e;
            }
            Log::debug('topology plugin: cannot read pagp data: ' . $e->getMessage());
        }

        if (! $pairs) {
            return [];
        }

        $ports = [];
        foreach (array_chunk(array_values(array_unique(array_merge(...$pairs))), 5000) as $chunk) {
            foreach (DB::table('ports')->whereIntegerInRaw('port_id', $chunk)->get(['port_id', 'ifType', 'ifName', 'ifDescr']) as $port) {
                $ports[(int) $port->port_id] = (array) $port;
            }
        }

        return PortStack::lagMembers($pairs, $ports);
    }

    /**
     * Interface stack pairs of the devices (IF-MIB ifStackTable) as port id pairs, in no particular
     * order: since LibreNMS 24.8 ports_stack keeps the higher layer (a sub-interface, a port-channel)
     * in low_port_id, before it kept it as the ifIndex in port_id_high (Support\PortStack and
     * SiteConnections::stack() tell the upper port from the lower one by the ports themselves). Rows
     * without port ids are resolved from their ifIndexes. Read once per set of devices and request.
     *
     * @param  int[]  $ids
     * @param  bool  $strict  throw read errors instead of returning what could be read (see lagParents())
     * @return array<int, array{0: int, 1: int}>
     */
    public function stackPairs(array $ids, bool $strict = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $key = md5(implode(',', $ids));
        if (isset($this->stackPairs[$key])) {
            return $this->stackPairs[$key];
        }
        if (! $ids || ! $this->allowsTable('ports_stack')) {
            return [];
        }

        $pairs = [];
        $byIndex = [];
        try {
            $rows = DB::table('ports_stack')->whereIntegerInRaw('device_id', $ids)
                ->get(['device_id', 'high_ifIndex', 'low_ifIndex', 'high_port_id', 'low_port_id']);
            foreach ($rows as $row) {
                if ($row->high_port_id && $row->low_port_id) {
                    $pairs[] = [(int) $row->high_port_id, (int) $row->low_port_id];
                } else {
                    $byIndex[] = [(int) $row->device_id, (int) $row->high_ifIndex, (int) $row->low_ifIndex];
                }
            }
        } catch (\Throwable $e) {
            try {
                foreach (DB::table('ports_stack')->whereIntegerInRaw('device_id', $ids)->get(['device_id', 'port_id_high', 'port_id_low']) as $row) {
                    $byIndex[] = [(int) $row->device_id, (int) $row->port_id_high, (int) $row->port_id_low];
                }
            } catch (\Throwable $e) {
                if ($strict) {
                    throw $e;
                }
                Log::debug('topology plugin: cannot read ports_stack: ' . $e->getMessage());

                return [];   // not remembered: a strict read of the same devices must see the error
            }
        }
        if ($byIndex) {
            $portIds = [];
            foreach (DB::table('ports')->whereIntegerInRaw('device_id', array_values(array_unique(array_column($byIndex, 0))))
                ->get(['port_id', 'device_id', 'ifIndex']) as $port) {
                $portIds[$port->device_id . ':' . $port->ifIndex] = (int) $port->port_id;
            }
            foreach ($byIndex as [$deviceId, $high, $low]) {
                if (isset($portIds["$deviceId:$high"], $portIds["$deviceId:$low"])) {
                    $pairs[] = [$portIds["$deviceId:$high"], $portIds["$deviceId:$low"]];
                }
            }
        }

        return $this->stackPairs[$key] = array_values(array_filter($pairs, fn (array $pair) => $pair[0] > 0 && $pair[1] > 0 && $pair[0] !== $pair[1]));
    }

    /**
     * @param  int[]  $portIds
     * @return array<int, array<int, bool>> port_id => [vlan => untagged]
     */
    public function portVlans(array $portIds): array
    {
        $result = [];
        foreach ($this->allowsTable('ports_vlans') ? array_chunk(array_values(array_unique($portIds)), 5000) : [] as $chunk) {
            foreach (DB::table('ports_vlans')->whereIntegerInRaw('port_id', $chunk)->get(['port_id', 'vlan', 'untagged']) as $row) {
                $result[(int) $row->port_id][(int) $row->vlan] = (bool) $row->untagged;
            }
        }

        return $result;
    }

    /**
     * Number of ports_vlans rows of the ports (decides whether the link page can load them all).
     *
     * @param  int[]  $portIds
     */
    public function portVlanCount(array $portIds): int
    {
        $count = 0;
        foreach ($this->allowsTable('ports_vlans') ? array_chunk(array_values(array_unique($portIds)), 5000) : [] as $chunk) {
            $count += DB::table('ports_vlans')->whereIntegerInRaw('port_id', $chunk)->count();
        }

        return $count;
    }

    /**
     * VLANs in use on each device: carried by an up port that is not an inter-switch link (access
     * ports, trunks to servers, APs or unmanaged switches), or by an up VLAN interface (SVI).
     * Devices without per-port VLAN data use the access VLAN of their ports.
     *
     * @param  int[]  $ids
     * @param  int[]  $interSwitchPorts  ports linking two monitored devices (and their aggregates / members)
     * @return array<int, array<int, true>> device_id => [vlan => true]
     */
    public function activeVlans(array $ids, array $interSwitchPorts): array
    {
        if (! $ids || ! $this->allowsTable('ports_vlans')) {
            return [];
        }

        $exclude = array_values(array_unique(array_filter(array_map('intval', $interSwitchPorts))));
        $result = [];

        $withPortVlans = array_map('intval', DB::table('ports_vlans')->whereIntegerInRaw('device_id', $ids)->distinct()->pluck('device_id')->all());
        if ($withPortVlans) {
            $query = DB::table('ports_vlans as v')->join('ports as p', 'p.port_id', '=', 'v.port_id')
                ->whereIntegerInRaw('v.device_id', $withPortVlans)->where('p.ifOperStatus', 'up')->where('p.deleted', 0);
            if ($exclude) {
                $query->whereIntegerNotInRaw('v.port_id', $exclude);
            }
            foreach ($query->groupBy('v.device_id', 'v.vlan')->get(['v.device_id', 'v.vlan']) as $row) {
                $result[(int) $row->device_id][(int) $row->vlan] = true;
            }
        }

        $others = array_values(array_diff($ids, $withPortVlans));
        if ($others) {
            $query = DB::table('ports')->whereIntegerInRaw('device_id', $others)->where('ifOperStatus', 'up')->where('deleted', 0)
                ->whereNotNull('ifVlan')->where('ifVlan', '<>', '')
                ->where(fn (Builder $q) => $q->whereNull('ifTrunk')->orWhere('ifTrunk', ''));
            if ($exclude) {
                $query->whereIntegerNotInRaw('port_id', $exclude);
            }
            foreach ($query->groupBy('device_id', 'ifVlan')->get(['device_id', 'ifVlan']) as $row) {
                if (is_numeric($row->ifVlan) && (int) $row->ifVlan > 0) {
                    $result[(int) $row->device_id][(int) $row->ifVlan] = true;
                }
            }
        }

        // VLAN interfaces (InterfaceName::vlanInterface()): Vlan30, Vlanif30, Vlan-interface30, ve 30, irb.30
        $prefixes = fn (Builder $q, string $column) => $q->orWhere($column, 'like', 'vl%')->orWhere($column, 'like', 've%')->orWhere($column, 'like', 'irb.%');
        foreach (DB::table('ports')->whereIntegerInRaw('device_id', $ids)->where('ifOperStatus', 'up')->where('deleted', 0)
            ->where(fn (Builder $q) => $prefixes($prefixes($q->where('ifType', 'l3ipvlan'), 'ifName'), 'ifDescr'))
            ->get(['device_id', 'ifName', 'ifDescr']) as $row) {
            foreach ([$row->ifName, $row->ifDescr] as $name) {
                if (($vlan = InterfaceName::vlanInterface($name)) !== null) {
                    $result[(int) $row->device_id][$vlan] = true;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Discard and non-unicast packet counters of the last poll (ports_statistics).
     *
     * @param  int[]  $portIds
     * @return array<int, array> port_id => row
     */
    public function portStatistics(array $portIds): array
    {
        $result = [];
        try {
            foreach ($this->allowsTable('ports_statistics') ? array_chunk(array_values(array_unique($portIds)), 5000) : [] as $chunk) {
                foreach (DB::table('ports_statistics')->whereIntegerInRaw('port_id', $chunk)
                    ->get(['port_id', 'ifInDiscards_delta', 'ifOutDiscards_delta', 'ifInNUcastPkts_delta', 'ifOutNUcastPkts_delta']) as $row) {
                    $result[(int) $row->port_id] = (array) $row;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read ports_statistics: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Weakest optical receive level of each port (the worst lane of multi-lane optics) with the
     * transceiver's low thresholds. Sensors are matched like LibreNMS does: through the
     * transceiver's entity index first (Cisco marks these sensors as port sensors too, but with an
     * entity index), otherwise as port sensors (entPhysicalIndex_measured = ports, index = ifIndex).
     *
     * @param  int[]  $portIds
     * @return array<int, array{rx: float, low: ?float, low_warn: ?float, sensor: string}>
     */
    public function opticalRx(array $portIds): array
    {
        $portIds = array_values(array_unique(array_filter(array_map('intval', $portIds))));
        if (! $portIds) {
            return [];
        }

        $byIfIndex = [];
        $deviceIds = [];
        foreach (array_chunk($portIds, 5000) as $chunk) {
            foreach (DB::table('ports')->whereIntegerInRaw('port_id', $chunk)->get(['port_id', 'device_id', 'ifIndex']) as $row) {
                $byIfIndex[$row->device_id . '|' . $row->ifIndex] = (int) $row->port_id;
                $deviceIds[(int) $row->device_id] = true;
            }
        }
        $deviceIds = array_keys($deviceIds);
        $wanted = array_flip($portIds);

        // every transceiver of the devices, so a sensor of another port is never taken for one of ours
        $byEntity = [];
        try {
            foreach (DB::table('transceivers')->whereIntegerInRaw('device_id', $deviceIds)->whereNotNull('entity_physical_index')
                ->get(['port_id', 'device_id', 'entity_physical_index']) as $row) {
                $byEntity[$row->device_id . '|' . $row->entity_physical_index] = (int) $row->port_id;
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read transceivers: ' . $e->getMessage());
        }

        $result = [];
        try {
            foreach (DB::table('sensors')->whereIntegerInRaw('device_id', $deviceIds)->where('sensor_class', 'dbm')->where('sensor_deleted', 0)
                ->whereNotNull('sensor_current')
                ->get(['device_id', 'sensor_descr', 'sensor_current', 'sensor_limit_low', 'sensor_limit_low_warn', 'entPhysicalIndex', 'entPhysicalIndex_measured']) as $row) {
                $descr = strtolower((string) $row->sensor_descr);
                if (! str_contains($descr, 'rx') && ! str_contains($descr, 'receive')) {
                    continue;
                }
                $key = $row->device_id . '|' . $row->entPhysicalIndex;
                $portId = $byEntity[$key] ?? (in_array($row->entPhysicalIndex_measured, ['ports', 'port'], true) ? ($byIfIndex[$key] ?? null) : null);
                if ($portId === null || ! isset($wanted[$portId]) || (isset($result[$portId]) && $result[$portId]['rx'] <= (float) $row->sensor_current)) {
                    continue;
                }
                $result[$portId] = [
                    'rx' => (float) $row->sensor_current,
                    'low' => $row->sensor_limit_low === null ? null : (float) $row->sensor_limit_low,
                    'low_warn' => $row->sensor_limit_low_warn === null ? null : (float) $row->sensor_limit_low_warn,
                    'sensor' => (string) $row->sensor_descr,
                ];
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read optical sensors: ' . $e->getMessage());
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
     * Spanning tree instances (rows of the stp table) of the devices, for every VLAN. The table is
     * small (one row per bridge and instance) and only the complete list tells PVST bridges
     * (one instance per VLAN) from single-instance 802.1D/RSTP/MST bridges.
     *
     * @param  int[]  $ids
     */
    public function stpInstances(array $ids): array
    {
        if (! $ids || ! $this->allowsTable('stp')) {
            return [];
        }

        return DB::table('stp')->whereIntegerInRaw('device_id', $ids)->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * VLAN keys of the devices' spanning tree instances and the bridge MACs of all of them, without
     * holding whole rows (Stp\StpIndex::partial()).
     *
     * @param  int[]  $ids
     * @return array{keys: array<int, int[]>, bridges: array<string, int>, count: int} count: instances
     */
    public function stpKeys(array $ids): array
    {
        $keys = [];
        $bridges = [];
        $count = 0;
        if ($ids && $this->allowsTable('stp')) {
            foreach (DB::table('stp')->whereIntegerInRaw('device_id', $ids)->select(['device_id', 'vlan', 'bridgeAddress'])->cursor() as $row) {
                $count++;
                $keys[(int) $row->device_id][StpIndex::vlanKey($row->vlan)] = true;
                $mac = StpIndex::mac($row->bridgeAddress);
                if ($mac !== '') {
                    $bridges[$mac] ??= (int) $row->device_id;
                }
            }
        }

        return ['keys' => array_map('array_keys', $keys), 'bridges' => $bridges, 'count' => $count];
    }

    /**
     * stp rows of some instances of the devices.
     *
     * @param  array<int, int|int[]>  $keys  device_id => VLAN key(s) (0 = default instance)
     */
    public function stpInstancesFor(array $keys): array
    {
        if (! $this->allowsTable('stp')) {
            return [];
        }

        $rows = [];
        foreach (self::devicesByKey($keys) as $key => $deviceIds) {
            foreach (self::whereVlanKey(DB::table('stp')->whereIntegerInRaw('device_id', $deviceIds), $key)->orderBy('stp_id')->get() as $row) {
                $rows[] = (array) $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, int|int[]|null>  $keys  device_id => VLAN key(s)
     * @return array<int, int[]> VLAN key => device ids
     */
    private static function devicesByKey(array $keys): array
    {
        $devices = [];
        foreach ($keys as $deviceId => $deviceKeys) {
            foreach ((array) $deviceKeys as $key) {
                if ($key !== null) {
                    $devices[(int) $key][] = (int) $deviceId;
                }
            }
        }

        return $devices;
    }

    /**
     * The rows of a VLAN key: LibreNMS stores the default instance (and Cisco PVST VLAN 1) with vlan NULL.
     */
    private static function whereVlanKey(Builder $query, int $key): Builder
    {
        return $key === 0 ? $query->where(fn (Builder $q) => $q->whereNull('vlan')->orWhereIn('vlan', [0, 1])) : $query->where('vlan', $key);
    }

    /**
     * stp rows of the devices, one at a time (a PVST network has one per switch and VLAN).
     *
     * @param  int[]  $ids
     * @return iterable<int, object>
     */
    public function stpInstanceRows(array $ids): iterable
    {
        return $ids && $this->allowsTable('stp') ? DB::table('stp')->whereIntegerInRaw('device_id', $ids)->cursor() : [];
    }

    /**
     * Port rows (ports_stp) of the devices. ports_stp grows with ports x VLANs on PVST networks,
     * so callers usually load a single instance per device.
     *
     * @param  int[]  $ids
     * @param  array<int, int|int[]|null>|null  $keys  device_id => VLAN key(s) (0 = default instance) to load,
     *                                                 null loads every instance
     */
    public function stpPorts(array $ids, ?array $keys = null): array
    {
        if (! $ids || ! $this->allowsTable('ports_stp')) {
            return [];
        }

        if ($keys === null) {
            return DB::table('ports_stp')->whereIntegerInRaw('device_id', $ids)->get()->map(fn ($r) => (array) $r)->all();
        }

        $rows = [];
        foreach (self::devicesByKey(array_intersect_key($keys, array_flip($ids))) as $key => $deviceIds) {
            foreach (self::whereVlanKey(DB::table('ports_stp')->whereIntegerInRaw('device_id', $deviceIds), $key)->get() as $row) {
                $rows[] = (array) $row;
            }
        }

        return $rows;
    }

    /**
     * Ports of the devices with ports_stp rows, in any instance.
     *
     * @param  int[]  $ids
     * @return array<int, true>
     */
    public function stpPortIds(array $ids): array
    {
        $ports = [];
        if ($ids && $this->allowsTable('ports_stp')) {
            foreach (DB::table('ports_stp')->whereIntegerInRaw('device_id', $ids)->distinct()->select(['port_id'])->cursor() as $row) {
                $ports[(int) $row->port_id] = true;
            }
        }

        return $ports;
    }

    /**
     * Number of ports_stp rows of the devices, or of these VLAN keys of them (decides whether the STP page can
     * analyze those instances at once).
     *
     * @param  int[]|null  $vlanKeys
     */
    public function stpPortCount(array $ids, ?array $vlanKeys = null): int
    {
        if (! $ids || ! $this->allowsTable('ports_stp') || $vlanKeys === []) {
            return 0;
        }

        $query = DB::table('ports_stp')->whereIntegerInRaw('device_id', $ids);
        if ($vlanKeys !== null) {
            $vlanKeys = array_map('intval', $vlanKeys);
            $query->where(function (Builder $q) use ($vlanKeys): void {
                $q->whereIn('vlan', array_values(array_filter($vlanKeys)));
                if (in_array(0, $vlanKeys, true)) {
                    $q->orWhereNull('vlan')->orWhereIn('vlan', [0, 1]);
                }
            });
        }

        return $query->count();
    }

    /**
     * VLAN keys having STP instances among the devices.
     *
     * @param  int[]  $ids
     * @return int[]
     */
    public function stpVlans(array $ids): array
    {
        if (! $ids || ! $this->allowsTable('stp')) {
            return [];
        }

        $keys = DB::table('stp')->whereIntegerInRaw('device_id', $ids)->distinct()->pluck('vlan')
            ->map(fn ($vlan) => StpIndex::vlanKey($vlan))->unique()->sort()->values()->all();

        return array_map('intval', $keys);
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
        foreach ($this->allowsTable('ipv4_addresses') ? array_chunk(array_values(array_unique($portIds)), 5000) : [] as $chunk) {
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
        $ipv4 = $this->allowsTable('ipv4_addresses');
        $ipv6 = $ipv6 && $this->allowsTable('ipv6_addresses');
        if (! $ids || (! $ipv4 && ! $ipv6)) {
            return [];
        }

        // VRF names have no role permission of their own here (see the README's Permissions)
        $vrfs = DB::table('vrfs')->whereIntegerInRaw('device_id', $ids)->pluck('vrf_name', 'vrf_id')->all();
        $columns = ['p.device_id', 'p.port_id', 'p.ifName', 'p.ifDescr', 'p.ifAlias', 'p.ifOperStatus', 'p.ifAdminStatus', 'p.ifType', 'p.ifVrf'];

        $rows = [];
        $query = $ipv4 ? DB::table('ipv4_addresses as a')->join('ports as p', 'p.port_id', '=', 'a.port_id')
            ->whereIntegerInRaw('p.device_id', $ids)->where('p.deleted', 0)
            ->get(array_merge($columns, ['a.ipv4_address as address', 'a.ipv4_prefixlen as prefixlen', 'a.context_name'])) : [];
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

    /**
     * Address row with its VRF name (port VRF, else SNMP context) and port URL.
     */
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

    /**
     * Whether a device takes part in routing, for the device overview panel: OSPF neighbors, BGP
     * sessions, or addresses on two or more of its ports. Existence checks only, as the panel shows on
     * every device page.
     *
     * @return array{ospf: bool, bgp: bool, routed: bool}
     */
    public function routingPresence(int $deviceId): array
    {
        $check = function (string $table, callable $query): bool {
            try {
                return (bool) $query();
            } catch (\Throwable $e) {
                Log::debug("topology plugin: cannot read $table: " . $e->getMessage());

                return false;
            }
        };

        return [
            'ospf' => $this->allowsTable('ospf_nbrs') && $check('ospf_nbrs', fn () => DB::table('ospf_nbrs')->where('device_id', $deviceId)->exists()),
            'bgp' => $this->allowsTable('bgpPeers') && $check('bgpPeers', fn () => DB::table('bgpPeers')->where('device_id', $deviceId)->exists()),
            'routed' => $this->allowsTable('ipv4_addresses') && $check('ipv4_addresses', fn () => DB::table('ipv4_addresses as a')->join('ports as p', 'p.port_id', '=', 'a.port_id')
                ->where('p.device_id', $deviceId)->distinct()->limit(2)->pluck('a.port_id')->count() >= 2),
        ];
    }

    /**
     * Recent spanning tree eventlog entries (designated root / root port / topology changes).
     *
     * @param  int[]  $ids
     */
    public function stpEvents(array $ids, int $days): array
    {
        if (! $ids || ! $this->allowsTable('eventlog')) {
            return [];
        }

        return DB::table('eventlog')->where('type', 'stp')->whereIntegerInRaw('device_id', $ids)
            ->where('datetime', '>=', date('Y-m-d H:i:s', time() - $days * 86400))
            ->orderByDesc('datetime')->limit(500)
            ->get(['device_id', 'datetime', 'message', 'severity'])->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Find where root bridge MACs live: bridge addresses and interface MACs of monitored devices,
     * FDB entries and the OUI vendor. The FDB, interface and vendor lookups are skipped when
     * $deep is false (ports.ifPhysAddress is not indexed, so keep them off frequently viewed pages).
     *
     * @param  string[]  $macs  12 hex characters
     * @return array<string, array{owners: array, fdb: array, vendor: string, hidden: bool}>
     *     owners: [device_id, port_id, name, source: stp|interface]; hidden: owned by a monitored
     *     device this user may not see (which one is not revealed)
     */
    public function rootLookups(array $macs, bool $deep = true): array
    {
        $macs = array_values(array_unique(array_map('strval', $macs)));
        if (! $macs || ! $this->allowsTable('stp')) {
            return [];
        }

        $accessible = $this->accessible();
        $result = [];
        foreach ($macs as $mac) {
            $result[$mac] = ['owners' => [], 'fdb' => [], 'vendor' => '', 'hidden' => false];
        }

        $owners = DB::table('stp')->whereIn('bridgeAddress', $macs)->distinct()
            ->get(['device_id', 'bridgeAddress as mac'])->map(fn ($r) => [(int) $r->device_id, null, (string) $r->mac, 'stp']);
        if ($deep) {
            $owners = $owners->merge(DB::table('ports')->whereIn('ifPhysAddress', $macs)->limit(200)
                ->get(['device_id', 'port_id', 'ifPhysAddress as mac'])->map(fn ($r) => [(int) $r->device_id, (int) $r->port_id, (string) $r->mac, 'interface']));
        }
        foreach ($owners as [$deviceId, $portId, $mac, $source]) {
            if (isset($accessible[$deviceId])) {
                $result[$mac]['owners'][] = ['device_id' => $deviceId, 'port_id' => $portId, 'name' => null, 'source' => $source];
            } else {
                $result[$mac]['hidden'] = true;
            }
        }

        if ($deep && $this->allowsTable('ports_fdb')) {
            try {
                foreach (DB::table('ports_fdb as f')
                    ->leftJoin('vlans as v', 'v.vlan_id', '=', 'f.vlan_id')
                    ->leftJoin('ports as p', 'p.port_id', '=', 'f.port_id')
                    ->whereIn('f.mac_address', $macs)->limit(200)
                    ->get(['f.device_id', 'f.port_id', 'f.mac_address', 'v.vlan_vlan', 'p.ifName', 'p.ifDescr']) as $row) {
                    if (isset($accessible[(int) $row->device_id])) {
                        $result[(string) $row->mac_address]['fdb'][] = [
                            'device_id' => (int) $row->device_id,
                            'port_id' => (int) $row->port_id,
                            'port' => InterfaceName::short($row->ifName ?: $row->ifDescr),
                            'name' => null,
                            'vlan' => $row->vlan_vlan,
                        ];
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
                        // OUI database not populated
                    }
                }
            }
        }

        // owners and FDB sightings may be outside the analyzed scope: resolve their names here
        $deviceIds = [];
        foreach ($result as $lookup) {
            foreach (array_merge($lookup['owners'], $lookup['fdb']) as $entry) {
                $deviceIds[] = $entry['device_id'];
            }
        }
        $names = $this->deviceNames($deviceIds);
        foreach ($result as &$lookup) {
            foreach (['owners', 'fdb'] as $list) {
                foreach ($lookup[$list] as &$entry) {
                    $entry['name'] = $names[$entry['device_id']] ?? null;
                }
                unset($entry);
            }
        }

        return $result;
    }

    /**
     * Port rows for STP analysis: names plus flags
     *  - has_ip: routed port (carries an IPv4 address),
     *  - switchport: carries VLANs,
     *  - has_stp: takes part in spanning tree in at least one VLAN (any instance, even when only
     *    one instance's ports_stp rows were loaded).
     *
     * @param  int[]  $portIds
     */
    public function analysisPorts(array $portIds): array
    {
        $ports = $this->ports($portIds);
        $withIp = $this->portsWithIp(array_keys($ports));
        $vlans = $this->portVlans(array_keys($ports));

        $withStp = [];
        $deviceIds = array_values(array_unique(array_map(fn ($p) => (int) $p['device_id'], $ports)));
        foreach ($this->allowsTable('ports_stp') ? array_chunk(array_keys($ports), 5000) : [] as $chunk) {
            // device_id first so the (device_id, vlan, port_index) index is used
            foreach (DB::table('ports_stp')->whereIntegerInRaw('device_id', $deviceIds)->whereIntegerInRaw('port_id', $chunk)
                ->distinct()->pluck('port_id') as $portId) {
                $withStp[(int) $portId] = true;
            }
        }

        foreach ($ports as $portId => &$port) {
            $port['has_ip'] = isset($withIp[$portId]);
            $port['has_stp'] = isset($withStp[$portId]);
            $port['switchport'] = isset($vlans[$portId]) || ! empty($port['ifTrunk']) || (is_numeric($port['ifVlan']) && (int) $port['ifVlan'] > 0);
        }

        return $ports;
    }

    /**
     * Inventory rows (entPhysical) of the given ports and of their ancestors, for
     * Redundancy\PortUnits: the port entities found by ifIndex, then whatever contains them
     * (containers, modules, chassis, stack...), one level per query.
     *
     * @param  array<int, array>  $ports  port rows (device_id, ifIndex)
     * @return array<int, array<int, array>> device_id => entPhysicalIndex => row; devices whose inventory maps none
     *                                       of the ports are missing
     */
    public function portEntities(array $ports): array
    {
        $wanted = [];
        foreach ($ports as $port) {
            if ((int) ($port['ifIndex'] ?? 0) > 0) {
                $wanted[(int) $port['device_id']][(int) $port['ifIndex']] = true;
            }
        }

        $columns = ['device_id', 'entPhysicalIndex', 'entPhysicalContainedIn', 'entPhysicalClass', 'entPhysicalIsFRU', 'entPhysicalName', 'entPhysicalDescr', 'ifIndex'];
        $rows = [];
        $pending = [];
        try {
            foreach (array_chunk($wanted, 200, true) as $chunk) {
                $ifIndexes = array_keys(array_replace(...array_values($chunk)));
                foreach (DB::table('entPhysical')->whereIntegerInRaw('device_id', array_keys($chunk))->whereIntegerInRaw('ifIndex', $ifIndexes)
                    ->whereIn('entPhysicalClass', PortUnits::PORT_CLASSES)->get($columns) as $row) {
                    $row = (array) $row;
                    $deviceId = (int) $row['device_id'];
                    if (isset($chunk[$deviceId][(int) $row['ifIndex']])) {
                        $rows[$deviceId][(int) $row['entPhysicalIndex']] = $row;
                    }
                }
            }
            foreach ($rows as $deviceId => $entities) {
                foreach ($entities as $row) {
                    if ((int) $row['entPhysicalContainedIn'] > 0 && ! isset($entities[(int) $row['entPhysicalContainedIn']])) {
                        $pending[$deviceId][(int) $row['entPhysicalContainedIn']] = true;
                    }
                }
            }

            // ancestors, one level per query (inventories are rarely more than six levels deep)
            for ($level = 0; $level < 12 && $pending; $level++) {
                $next = [];
                foreach (array_chunk($pending, 200, true) as $chunk) {
                    $indexes = array_keys(array_replace(...array_values($chunk)));
                    foreach (DB::table('entPhysical')->whereIntegerInRaw('device_id', array_keys($chunk))->whereIntegerInRaw('entPhysicalIndex', $indexes)
                        ->get($columns) as $row) {
                        $row = (array) $row;
                        $deviceId = (int) $row['device_id'];
                        $index = (int) $row['entPhysicalIndex'];
                        if (! isset($chunk[$deviceId][$index]) || isset($rows[$deviceId][$index])) {
                            continue;
                        }
                        $rows[$deviceId][$index] = $row;
                        $parent = (int) $row['entPhysicalContainedIn'];
                        if ($parent > 0 && ! isset($rows[$deviceId][$parent])) {
                            $next[$deviceId][$parent] = true;
                        }
                    }
                }
                $pending = $next;
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read entPhysical: ' . $e->getMessage());

            return [];
        }

        return $rows;
    }

    /**
     * Start of the current outage of down devices.
     *
     * @param  int[]  $deviceIds
     * @return array<int, int> device_id => unix time
     */
    public function outageStarts(array $deviceIds): array
    {
        if (! $deviceIds || ! $this->allowsTable('device_outages')) {
            return [];
        }

        try {
            return DB::table('device_outages')->whereIntegerInRaw('device_id', $deviceIds)->whereNull('up_again')
                ->groupBy('device_id')->selectRaw('device_id, MAX(going_down) as since')->pluck('since', 'device_id')
                ->map(fn ($since) => (int) $since)->all();
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read device_outages: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Bridge priority of each device's default spanning tree instance (CST / MST 0, else the
     * lowest VLAN), which tells the root bridges placed on purpose.
     *
     * @param  int[]  $ids
     * @return array<int, int> device_id => priority
     */
    public function stpPriorities(array $ids): array
    {
        $best = [];
        if (! $this->allowsTable('stp')) {
            return $best;
        }
        foreach (array_chunk($ids, 5000) as $chunk) {
            foreach (DB::table('stp')->whereIntegerInRaw('device_id', $chunk)->select(['device_id', 'vlan', 'priority'])->cursor() as $row) {
                $deviceId = (int) $row->device_id;
                $key = StpIndex::vlanKey($row->vlan);
                if (! isset($best[$deviceId]) || $key < $best[$deviceId][0]) {
                    $best[$deviceId] = [$key, (int) $row->priority];
                }
            }
        }

        return array_map(fn ($entry) => $entry[1], $best);
    }
}
