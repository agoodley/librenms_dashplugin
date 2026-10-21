<?php

namespace Agoodley\LibrenmsTopology\History;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Topology\L2Builder;

/**
 * A map as of a time: the facts one snapshot of the topology history recorded (links, port-channel members,
 * root bridges, blocked ports, OSPF adjacencies, BGP sessions) given to the L2 and L3 builders in the shape
 * they read from the LibreNMS tables, for the devices the user may see. Ports, speeds, VLANs, addresses and
 * device details stay today's; a port LibreNMS no longer has keeps the name the history stored, and so does a
 * device it no longer has for a user who may see every device.
 *
 * The conversions are pure (static); an instance streams the facts of its snapshot from the repository, only
 * of the kinds the user's role may read (HistoryService::factAllowed()), and remembers the stored names of the
 * ports it met.
 */
class PastMap
{
    /** Name of a monitored neighbour the user may not see and LLDP/CDP gave no name for (FactText's wording). */
    public const MASKED = 'another device';

    /** @var array<int, array{0: int, 1: string}> port_id => device_id, stored name of the ports the facts named */
    private array $portNames = [];

    /** @var array<string, true> map node ids of devices LibreNMS no longer has */
    private array $deletedNodes = [];

    /**
     * @param  array{time: int, started_at: string, next: ?int, recorded: string[], skipped: string[]}  $snapshot  HistoryRepository::snapshotAt()
     * @param  array<int, true>  $visible  devices the user may see
     * @param  string[]  $kinds  kinds of facts the user's role may read
     * @param  array<int, true>|null  $existing  every device LibreNMS has, for a user who may see all of them (a fact's
     *                                           device that is not one of them is named as stored); null otherwise
     */
    public function __construct(
        private readonly HistoryRepository $history,
        public readonly array $snapshot,
        private readonly array $visible,
        private readonly array $kinds,
        private readonly ?array $existing = null,
    ) {
    }

    /**
     * The links of the devices at the snapshot as TopologyRepository::linksWhere() returns those of now: the rows
     * $keep accepts, keyed by their position among all of them (a second read merges back in order).
     *
     * @param  int[]  $ids  devices of the map's scope
     * @param  callable(array): bool  $keep
     * @return array<int, array>
     */
    public function linksWhere(array $ids, callable $keep): array
    {
        $scope = array_flip($ids);
        $links = [];
        $position = 0;
        foreach ($this->facts([FactCollector::LINK], $ids) as $fact) {
            $row = self::linkRow($fact, $this->visible, $scope, $this->existing);
            if ($row === null) {
                continue;
            }
            $this->notePort($row['local_device_id'], $row['local_port_id'], $row['local_port_name']);
            if ($row['remote_device_id']) {
                $this->notePort($row['remote_device_id'], (int) $row['remote_port_id'], $row['remote_port']);
            } elseif (! empty($row['deleted'])) {
                $this->deletedNodes[L2Builder::unmanagedNodeId(['b_host' => $row['remote_hostname'], 'a_device' => $row['local_device_id'],
                    'a_port' => $row['local_port_id'] ?: null, 'b_name' => $row['remote_port']])] = true;
            }
            if ($keep($row)) {
                $links[$position] = $row;
            }
            $position++;
        }

        return $links;
    }

    /**
     * Port-channel membership of the devices at the snapshot: member port_id => aggregate port_id.
     *
     * @param  int[]  $ids
     * @return array<int, int>
     */
    public function lagParents(array $ids): array
    {
        $lag = [];
        foreach ($this->facts([FactCollector::LAG], $ids, true) as $fact) {
            $data = $fact['data'];
            if (isset($this->visible[(int) ($data['device'] ?? 0)], $data['member'], $data['aggregate'])) {
                $lag[(int) $data['member']] = (int) $data['aggregate'];
                $this->notePort((int) $data['device'], (int) $data['member'], (string) ($data['member_name'] ?? ''));
                $this->notePort((int) $data['device'], (int) $data['aggregate'], (string) ($data['aggregate_name'] ?? ''));
            }
        }

        return $lag;
    }

    /**
     * Spanning tree of the devices at the snapshot for the map's overlay (stpIndex()).
     *
     * @param  int[]  $ids
     * @param  array<int, int>  $lag
     */
    public function stp(array $ids, ?int $vlan, array $lag): StpIndex
    {
        $roots = [];
        $blocked = [];
        foreach ($this->facts([FactCollector::STP_ROOT, FactCollector::STP_BLOCKED], $ids, true) as $fact) {
            if (! isset($this->visible[(int) ($fact['data']['device'] ?? 0)])) {
                continue;
            }
            if ($fact['kind'] === FactCollector::STP_ROOT) {
                $roots[] = $fact['data'];
            } else {
                $blocked[] = $fact['data'];
                $this->notePort((int) $fact['data']['device'], (int) $fact['data']['port'], (string) ($fact['data']['port_name'] ?? ''));
            }
        }

        return self::stpIndex($roots, $blocked, $vlan, $lag, $this->visible);
    }

    /**
     * OSPF adjacencies the devices reported at the snapshot, as ospf_nbrs rows (TopologyRepository::ospfNeighbors()).
     *
     * @param  int[]  $ids
     * @return array<int, array>
     */
    public function ospfNeighbors(array $ids): array
    {
        $rows = [];
        foreach ($this->facts([FactCollector::OSPF], $ids, true) as $fact) {
            if (isset($this->visible[(int) ($fact['data']['device'] ?? 0)])) {
                $rows[] = self::ospfRow($fact['data']);
            }
        }

        return $rows;
    }

    /**
     * BGP sessions the devices reported at the snapshot, as bgpPeers rows (TopologyRepository::bgpPeers()).
     *
     * @param  int[]  $ids
     * @return array<int, array>
     */
    public function bgpPeers(array $ids): array
    {
        $rows = [];
        foreach ($this->facts([FactCollector::BGP], $ids, true) as $fact) {
            if (isset($this->visible[(int) ($fact['data']['device'] ?? 0)])) {
                $rows[] = self::bgpRow($fact['data']);
            }
        }

        return $rows;
    }

    /**
     * Today's port rows with a row for each port the facts named that LibreNMS no longer has (or the user's role
     * may not read): its stored name, nothing else.
     *
     * @param  array<int, array>  $ports  port_id => today's row
     * @param  int[]  $portIds  ports the map needs
     * @return array<int, array>
     */
    public function withStoredPorts(array $ports, array $portIds): array
    {
        foreach ($portIds as $portId) {
            $portId = (int) $portId;
            if ($portId > 0 && ! isset($ports[$portId]) && isset($this->portNames[$portId])) {
                [$deviceId, $name] = $this->portNames[$portId];
                $ports[$portId] = self::storedPort($portId, $deviceId, $name);
            }
        }

        return $ports;
    }

    /**
     * The built map as of the snapshot (neutral()), with the devices it found that LibreNMS no longer has; changed in
     * place, as a copy of a whole network's map would double its memory.
     *
     * @param  array<int, string>  $states  device_id => up, down, new or unknown at the snapshot (HistoryRepository::deviceStatesAt())
     */
    public function finish(array &$result, array $states): void
    {
        self::neutral($result, $states, $this->deletedNodes);
    }

    // ---------------------------------------------------------------- conversions

    /**
     * A links row (TopologyRepository::links()) for a link fact, seen from its end on a device of the map's scope,
     * else from its other end the user may see; null when they may see neither. A far end the user may not see is
     * an unmanaged neighbour (as links() makes it), named as its neighbour's LLDP/CDP reported it, else "another
     * device"; one LibreNMS no longer has keeps its stored name (deleted) for a user who may see every device
     * ($existing). The row also carries the stored name of the local port (local_port_name), and of an unmanaged
     * neighbour its role as recorded (remote_role, the history keeps no system description to read it from).
     *
     * @param  array  $fact  stored link fact (data a and b, FactCollector::links())
     * @param  array<int, true>  $visible
     * @param  array<int, mixed>  $scope  devices of the map's scope
     * @param  array<int, true>|null  $existing  see the constructor
     */
    public static function linkRow(array $fact, array $visible, array $scope, ?array $existing = null): ?array
    {
        $ends = [$fact['data']['a'] ?? [], $fact['data']['b'] ?? []];
        $seen = fn (array $end) => isset($end['device']) && isset($visible[(int) $end['device']]);
        $local = null;
        foreach ([fn (array $end) => $seen($end) && isset($scope[(int) $end['device']]), $seen] as $test) {
            foreach ($ends as $index => $end) {
                if ($local === null && $test($end)) {
                    $local = $index;
                }
            }
        }
        if ($local === null) {
            return null;
        }
        $here = $ends[$local];
        $far = $ends[1 - $local];

        $row = [
            'id' => (int) ($fact['id'] ?? 0),
            'local_device_id' => (int) $here['device'],
            'local_port_id' => isset($here['port']) ? (int) $here['port'] : 0,
            'local_port_name' => (string) ($here['port_name'] ?? ''),
            'remote_device_id' => 0,
            'remote_port_id' => null,
            'remote_hostname' => '',
            'remote_port' => (string) ($far['port_name'] ?? ''),
            'remote_platform' => (string) ($far['platform'] ?? ''),
            'remote_version' => '',
            'protocol' => '',
        ];
        if (! isset($far['device'])) {
            // an unmanaged neighbour, stored as "unknown neighbor" when LLDP/CDP gave no name
            $row['remote_hostname'] = ($far['name'] ?? '') === 'unknown neighbor' ? '' : (string) ($far['name'] ?? '');
            $row['remote_role'] = $far['role'] ?? null;
        } elseif ($seen($far)) {
            $row['remote_device_id'] = (int) $far['device'];
            $row['remote_port_id'] = isset($far['port']) ? (int) $far['port'] : null;
            $row['remote_hostname'] = (string) ($far['host'] ?? '');
        } elseif ($existing !== null && ! isset($existing[(int) $far['device']])) {
            $row['remote_hostname'] = (string) ($far['name'] ?? $far['host'] ?? '');
            $row['remote_label'] = self::MASKED;
            $row['deleted'] = true;
        } else {
            $row['remote_hostname'] = (string) ($far['host'] ?? '');
            $row['remote_label'] = self::MASKED;
        }

        return $row;
    }

    /**
     * Spanning tree index of the root bridges and blocked ports facts recorded: per device, the instance that
     * carries $vlan (StpIndex::selectKey(), null: the default instance) with its root bridge (designatedRoot; the
     * device is the root when the history found it owns that bridge), and its blocked ports as ports_stp rows in
     * the blocking state. The history keeps no priorities, costs, root ports or timers: those stay empty.
     *
     * @param  array<int, array>  $roots  data of stp_root facts (device, root, root_device, vlans)
     * @param  array<int, array>  $blocked  data of stp_blocked facts (device, port, vlans)
     * @param  array<int, int>  $lag  member port_id => aggregate port_id
     * @param  array<int, true>|null  $visible  bridges of these devices only are known as root bridges (null: all)
     */
    public static function stpIndex(array $roots, array $blocked, ?int $vlan, array $lag, ?array $visible = null): StpIndex
    {
        $keys = [];
        $rootOf = [];
        $bridges = [];
        foreach ($roots as $data) {
            $deviceId = (int) $data['device'];
            foreach (FactText::vlans($data) as $key) {
                $keys[$deviceId][$key] = true;
                $rootOf[$deviceId][$key] = $data;
            }
            $owner = isset($data['root_device']) ? (int) $data['root_device'] : null;
            if ($owner !== null && ($visible === null || isset($visible[$owner])) && StpIndex::mac((string) $data['root']) !== '') {
                $bridges[StpIndex::mac((string) $data['root'])] = $owner;
            }
        }
        $blocking = [];
        foreach ($blocked as $data) {
            foreach (FactText::vlans($data) as $key) {
                $keys[(int) $data['device']][$key] = true;
                $blocking[(int) $data['device']][$key][] = (int) $data['port'];
            }
        }

        $instances = [];
        $ports = [];
        $deviceKeys = [];
        foreach ($keys as $deviceId => $byKey) {
            $deviceKeys[$deviceId] = array_keys($byKey);
            $key = StpIndex::selectKey($deviceKeys[$deviceId], $vlan);
            if ($key === null) {
                continue;
            }
            $root = $rootOf[$deviceId][$key] ?? null;
            $isRoot = $root !== null && isset($root['root_device']) && (int) $root['root_device'] === $deviceId;
            $mac = $root !== null ? StpIndex::mac((string) $root['root']) : '';
            $instances[] = ['device_id' => $deviceId, 'vlan' => $key, 'rootBridge' => $isRoot, 'designatedRoot' => $mac,
                'bridgeAddress' => $isRoot ? $mac : '', 'protocolSpecification' => ''];
            foreach (array_unique($blocking[$deviceId][$key] ?? []) as $portId) {
                $ports[] = ['device_id' => $deviceId, 'vlan' => $key, 'port_id' => $portId, 'state' => 'blocking'];
            }
        }

        return StpIndex::partial($deviceKeys, $instances, $ports, $lag, $bridges);
    }

    /**
     * An ospf_nbrs row for an OSPF adjacency fact: the history records working adjacencies (full or 2-way) only.
     */
    public static function ospfRow(array $data): array
    {
        return ['device_id' => (int) $data['device'], 'port_id' => 0, 'ospfNbrIpAddr' => (string) ($data['ip'] ?? ''),
            'ospfNbrRtrId' => (string) ($data['router_id'] ?? ''), 'ospfNbrState' => 'full', 'context_name' => (string) ($data['context'] ?? '')];
    }

    /**
     * A bgpPeers row for a BGP session fact: the history records established sessions only.
     */
    public static function bgpRow(array $data): array
    {
        return ['device_id' => (int) $data['device'], 'bgpPeerIdentifier' => (string) ($data['peer'] ?? ''), 'bgpPeerRemoteAs' => (int) ($data['remote_as'] ?? 0),
            'bgpPeerState' => 'established', 'bgpPeerAdminStatus' => 'start', 'bgpLocalAddr' => '', 'bgpPeerDescr' => (string) ($data['description'] ?? ''),
            'context_name' => (string) ($data['context'] ?? '')];
    }

    /**
     * Port row of a port LibreNMS no longer has: the name the history stored ("port #123" when it had none).
     */
    public static function storedPort(int $portId, int $deviceId, string $name): array
    {
        return ['port_id' => $portId, 'device_id' => $deviceId, 'ifName' => $name, 'ifDescr' => $name, 'ifAlias' => '', 'stored' => true];
    }

    /**
     * Is a stored fact present at $time: from its first snapshot (included) until the snapshot that no longer
     * saw it (excluded)? HistoryRepository::factsAt() reads exactly these.
     *
     * @param  array{first_seen: int, removed_at: ?int}  $fact
     */
    public static function presentAt(array $fact, int $time): bool
    {
        return $fact['first_seen'] <= $time && ($fact['removed_at'] === null || $fact['removed_at'] > $time);
    }

    /**
     * A built L2 or L3 map drawn as of a past time: links, sessions and segments without a status (today's port
     * states, traffic and utilization would describe another time: status unknown, no traffic), devices as they
     * were (up, down during an outage, new: not in LibreNMS yet), the spanning tree of devices as recorded (root
     * bridge and its MAC: the history keeps no priorities, costs or timers) and the nodes of devices LibreNMS no
     * longer has noted.
     *
     * @param  array<int, string>  $states  device_id => up, down, new or unknown
     * @param  array<string, true>  $deletedNodes  node ids of devices LibreNMS no longer has
     */
    public static function neutral(array &$result, array $states, array $deletedNodes = []): void
    {
        foreach ($result['edges'] as &$edge) {
            $edge['status'] = 'unknown';
            foreach (['util' => null, 'in_bps' => 0, 'out_bps' => 0, 'errors' => 0] as $key => $value) {
                if (array_key_exists($key, $edge)) {
                    $edge[$key] = $value;
                }
            }
            foreach ($edge['members'] ?? [] as $index => $member) {
                $edge['members'][$index]['up'] = null;
            }
            if (isset($edge['lag']['up'])) {
                $edge['lag']['up'] = null;
            }
            if (isset($edge['group']['down'])) {
                $edge['group']['down'] = 0;
            }
            foreach (['a', 'b'] as $side) {
                if (is_array($edge[$side] ?? null)) {
                    foreach (['in_bps' => 0, 'out_bps' => 0] as $key => $value) {
                        if (array_key_exists($key, $edge[$side])) {
                            $edge[$side][$key] = $value;
                        }
                    }
                }
            }
        }
        unset($edge);

        foreach ($result['nodes'] as &$node) {
            if (($node['type'] ?? '') === 'device' && isset($node['device_id'])) {
                $state = $states[(int) $node['device_id']] ?? 'unknown';
                $node['status'] = $state === 'new' ? 'unknown' : $state;
                if ($state === 'new') {
                    $node['note'] = 'Not in LibreNMS yet at that time';
                }
            } elseif (isset($deletedNodes[$node['id']])) {
                $node['note'] = 'No longer in LibreNMS';
            }
            if (is_array($node['stp'] ?? null) && empty($node['stp']['suspected'])) {
                $node['stp'] = ['vlan' => $node['stp']['vlan'], 'root' => $node['stp']['root'], 'designatedRoot' => $node['stp']['designatedRoot'], 'recorded' => true];
            }
        }
        unset($node);

        if (isset($result['stats']['down'])) {
            $result['stats']['down'] = 0;
        }
    }

    /**
     * Facts of the snapshot of these kinds the user's role may read.
     *
     * @param  string[]  $kinds
     * @param  int[]  $ids
     * @return iterable<int, array>
     */
    private function facts(array $kinds, array $ids, bool $reporting = false): iterable
    {
        $kinds = array_values(array_intersect($kinds, $this->kinds));

        return $kinds ? $this->history->factsAt($this->snapshot['started_at'], $ids, $kinds, $reporting) : [];
    }

    /**
     * Remember the stored name of a port a fact named.
     */
    private function notePort(int $deviceId, ?int $portId, string $name): void
    {
        if ($portId && $name !== '' && ! isset($this->portNames[$portId])) {
            $this->portNames[$portId] = [$deviceId, InterfaceName::short($name)];
        }
    }
}
