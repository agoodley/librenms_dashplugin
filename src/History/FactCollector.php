<?php

namespace Agoodley\LibrenmsTopology\History;

use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Topology\L2Builder;

/**
 * Topology facts of the history: what exists right now, one entry per identity. The identity
 * (`key`) names ports by device and normalized interface name rather than by port_id, so a port
 * that LibreNMS rediscovers under a new id is still the same port; the data only holds what the
 * Changes page shows, so it rarely changes while the fact lasts.
 *
 * Every fact: kind, key, hash (identity), device_a / device_b (monitored devices it involves, for
 * permissions), data and data_hash. Spanning tree facts are one per root bridge a switch sees and
 * one per blocked port, each with its VLANs in the data, so a PVST network of 50 VLANs does not
 * multiply them by 50. An unmanaged neighbor with a generic LLDP/CDP name ("Switch") is told apart
 * by the local port id (L2Builder::unmanagedNodeId()).
 */
class FactCollector
{
    public const LINK = 'link';
    public const LAG = 'lag';
    public const STP_ROOT = 'stp_root';
    public const STP_BLOCKED = 'stp_blocked';
    public const OSPF = 'ospf';
    public const BGP = 'bgp';

    public const KINDS = [self::LINK, self::LAG, self::STP_ROOT, self::STP_BLOCKED, self::OSPF, self::BGP];

    /** OSPF neighbor states of a working adjacency (DROther pairs stay in 2-way on broadcast networks). */
    private const OSPF_UP_STATES = ['full', 'twoway', '2way'];

    /**
     * @param  array<int, string>  $deviceNames  device_id => display name of the devices in scope
     * @param  array<int, array>  $ports  port_id => port row (ifName / ifDescr), for the ports below
     * @param  array<int, string[]>  $hostNames  device_id => other names (sysName, hostname) its neighbors may
     *                                           report it by
     */
    public function __construct(private readonly array $deviceNames, private readonly array $ports, private readonly array $hostNames = [])
    {
    }

    /**
     * Facts of the LLDP/CDP links (unmanaged neighbors included, phones left out), de-duplicated
     * as on the L2 map.
     *
     * @param  array<int, array>  $links  links table rows (see TopologyRepository::links())
     * @return array<string, array> hash => fact
     */
    public function links(array $links): array
    {
        $facts = [];
        foreach (L2Builder::uniqueLinks($links, $this->deviceNames, $this->ports) as $link) {
            $a = $this->monitoredEnd((int) $link['a_device'], $link['a_port'] ? (int) $link['a_port'] : null, '');
            if ($link['b_device']) {
                // the name its neighbor's LLDP/CDP reports stands in for it where the user may not see it
                $b = $this->monitoredEnd((int) $link['b_device'], $link['b_port'] ? (int) $link['b_port'] : null, (string) $link['b_name'])
                    + ['host' => mb_substr((string) $link['b_host'], 0, 120)];
            } else {
                $role = DeviceRole::fromPlatform($link['b_platform'], $link['b_host']);
                if ($role === DeviceRole::PHONE) {
                    continue; // end devices that move all day: no topology change, and tens of thousands of them
                }
                $node = L2Builder::unmanagedNodeId($link);
                $b = [
                    'key' => $node . ':' . InterfaceName::normalize($link['b_name']),
                    'device' => null,
                    'port' => null,
                    'port_name' => InterfaceName::short($link['b_name']),
                    'name' => $link['b_host'] !== '' ? (string) $link['b_host'] : 'unknown neighbor',
                    'node' => $node,
                    'role' => $role,
                    'platform' => mb_substr((string) $link['b_platform'], 0, 120),
                ];
            }
            if ($a['key'] === $b['key']) {
                continue;
            }
            if (strcmp($a['key'], $b['key']) > 0) {
                [$a, $b] = [$b, $a];
            }

            $key = $a['key'] . '|' . $b['key'];
            $data = ['a' => self::endData($a), 'b' => self::endData($b)];
            $facts += self::fact(self::LINK, $key, $a['device'] ?? $b['device'], $a['device'] !== null ? $b['device'] : null, $data);
        }

        return $this->withoutDoubleViews($facts);
    }

    /**
     * Do two ends describe the same neighbor port, one as a device LibreNMS monitors and one as an
     * unmanaged LLDP/CDP neighbor (same short host name and port)? That is one cable seen before and
     * after LibreNMS started (or stopped) monitoring the neighbor.
     *
     * @param  string[]  $names  other names of the monitored device
     */
    public static function sameNeighbor(array $x, array $y, array $names = []): bool
    {
        if (isset($x['device']) === isset($y['device'])) {
            return false;
        }
        [$managed, $unmanaged] = isset($x['device']) ? [$x, $y] : [$y, $x];
        $host = self::shortName((string) ($unmanaged['name'] ?? ''));
        $known = array_map(fn ($name) => self::shortName((string) $name), array_merge([$managed['name'] ?? '', $managed['host'] ?? ''], $names));

        return $host !== '' && in_array($host, $known, true)
            && InterfaceName::normalize($managed['port_name'] ?? '') === InterfaceName::normalize($unmanaged['port_name'] ?? '');
    }

    /**
     * A neighbor LibreNMS monitors keeps its unmanaged entry on the other side until that side's next
     * discovery: the same cable, kept once as the link between the two monitored devices.
     *
     * @param  array<string, array>  $facts  link facts
     * @return array<string, array>
     */
    private function withoutDoubleViews(array $facts): array
    {
        $managed = []; // end key => far monitored ends
        foreach ($facts as $fact) {
            if (isset($fact['data']['a']['device'], $fact['data']['b']['device'])) {
                [$a, $b] = explode('|', $fact['key'], 2);
                $managed[$a][] = $fact['data']['b'];
                $managed[$b][] = $fact['data']['a'];
            }
        }
        if (! $managed) {
            return $facts;
        }

        foreach ($facts as $hash => $fact) {
            if (isset($fact['data']['b']['node'])) {
                foreach ($managed[explode('|', $fact['key'], 2)[0]] ?? [] as $end) {
                    if (self::sameNeighbor($end, $fact['data']['b'], $this->hostNames[(int) $end['device']] ?? [])) {
                        unset($facts[$hash]);
                        break;
                    }
                }
            }
        }

        return $facts;
    }

    private static function shortName(string $name): string
    {
        return strtolower(L2Builder::shortHost(trim($name)));
    }

    /**
     * Port-channel members: one fact per member port of an aggregate.
     *
     * @param  array<int, int>  $lagParent  member port_id => aggregate port_id
     * @return array<string, array>
     */
    public function lagMembers(array $lagParent): array
    {
        $facts = [];
        foreach ($lagParent as $member => $aggregate) {
            $deviceId = (int) ($this->ports[$member]['device_id'] ?? 0);
            if (! isset($this->deviceNames[$deviceId]) || ! isset($this->ports[$aggregate])) {
                continue;
            }
            $key = "d$deviceId:" . $this->portKey((int) $aggregate) . '>' . $this->portKey((int) $member);
            $facts += self::fact(self::LAG, $key, $deviceId, null, [
                'device' => $deviceId,
                'device_name' => $this->deviceNames[$deviceId],
                'aggregate' => (int) $aggregate,
                'aggregate_name' => $this->portName((int) $aggregate),
                'member' => (int) $member,
                'member_name' => $this->portName((int) $member),
            ]);
        }

        return $facts;
    }

    /**
     * The root bridges each device sees: one fact per device and root bridge with the spanning tree
     * instances (VLAN keys) it is the root of. Only the observing device is involved (device_a): a
     * user who may see the root bridge does not learn about the switches behind it.
     *
     * @param  iterable<int, array>  $stp  stp table rows (device_id, vlan, designatedRoot, bridgeAddress), read once
     * @return array<string, array>
     */
    public function stpRoots(iterable $stp): array
    {
        $bridges = [];
        $roots = [];
        foreach ($stp as $row) {
            $mac = StpIndex::mac($row['bridgeAddress'] ?? '');
            if ($mac !== '') {
                $bridges[$mac] = (int) $row['device_id'];
            }
            $deviceId = (int) $row['device_id'];
            $root = StpIndex::mac($row['designatedRoot'] ?? '');
            if ($root !== '' && isset($this->deviceNames[$deviceId])) {
                $roots[$deviceId][$root][StpIndex::vlanKey($row['vlan'] ?? null)] = true;
            }
        }

        $facts = [];
        foreach ($roots as $deviceId => $byRoot) {
            foreach ($byRoot as $root => $vlans) {
                ksort($vlans);
                $owner = $bridges[$root] ?? null;
                $facts += self::fact(self::STP_ROOT, "d$deviceId|$root", $deviceId, null, [
                    'device' => $deviceId,
                    'device_name' => $this->deviceNames[$deviceId],
                    'root' => $root,
                    'root_device' => $owner,
                    'root_name' => $owner !== null ? ($this->deviceNames[$owner] ?? null) : null,
                    'vlans' => array_keys($vlans),
                ]);
            }
        }

        return $facts;
    }

    /**
     * Ports that spanning tree blocks: one fact per port with the instances (VLAN keys) it blocks.
     *
     * @param  iterable<int, array>  $rows  ports_stp rows (device_id, port_id, vlan, state), read once
     * @return array<string, array>
     */
    public function blockedPorts(iterable $rows): array
    {
        return $this->blockedPortFacts(self::blockingPorts($rows, $this->deviceNames));
    }

    /**
     * The instances (VLAN keys) each port of the devices blocks.
     *
     * @param  iterable<int, array>  $rows  ports_stp rows (device_id, port_id, vlan, state), read once
     * @param  array<int, mixed>  $devices  device_id => anything
     * @return array<int, array<int, int[]>> device_id => port_id => VLAN keys
     */
    public static function blockingPorts(iterable $rows, array $devices): array
    {
        $ports = [];
        foreach ($rows as $row) {
            $deviceId = (int) $row['device_id'];
            if (StpIndex::isBlocking($row['state'] ?? null) && isset($devices[$deviceId])) {
                $ports[$deviceId][(int) $row['port_id']][StpIndex::vlanKey($row['vlan'] ?? null)] = true;
            }
        }

        return array_map(fn ($byPort) => array_map(function ($vlans) {
            ksort($vlans);

            return array_keys($vlans);
        }, $byPort), $ports);
    }

    /**
     * Facts of blocking ports (blockingPorts()).
     *
     * @param  array<int, array<int, int[]>>  $ports  device_id => port_id => VLAN keys
     * @return array<string, array>
     */
    public function blockedPortFacts(array $ports): array
    {
        $facts = [];
        foreach ($ports as $deviceId => $byPort) {
            foreach ($byPort as $portId => $vlans) {
                $facts += self::fact(self::STP_BLOCKED, "d$deviceId:" . $this->portKey($portId), $deviceId, null, [
                    'device' => $deviceId,
                    'device_name' => $this->deviceNames[$deviceId],
                    'port' => $portId,
                    'port_name' => $this->portName($portId),
                    'vlans' => $vlans,
                ]);
            }
        }

        return $facts;
    }

    /**
     * Working OSPF adjacencies as each router reports them. The neighbor is the device with the
     * neighbor's interface address, else the one with its router ID (a router ID can be duplicated).
     *
     * @param  array<int, array>  $neighbors  ospf_nbrs rows
     * @param  array<string, int>  $routerIds  OSPF router id => device_id
     * @param  array<string, int>  $addresses  IP address => device_id
     * @return array<string, array>
     */
    public function ospf(array $neighbors, array $routerIds, array $addresses): array
    {
        $facts = [];
        foreach ($neighbors as $row) {
            $deviceId = (int) $row['device_id'];
            $state = strtolower(str_replace(['-', ' ', '_'], '', (string) ($row['ospfNbrState'] ?? '')));
            if (! in_array($state, self::OSPF_UP_STATES, true) || ! isset($this->deviceNames[$deviceId])) {
                continue;
            }
            $routerId = (string) ($row['ospfNbrRtrId'] ?? '');
            $ip = (string) ($row['ospfNbrIpAddr'] ?? '');
            $context = (string) ($row['context_name'] ?? '');
            $neighbor = $addresses[$ip] ?? $routerIds[$routerId] ?? null;
            $neighbor = $neighbor !== null && $neighbor !== $deviceId && isset($this->deviceNames[$neighbor]) ? $neighbor : null;
            $portId = (int) ($row['port_id'] ?? 0);
            $facts += self::fact(self::OSPF, "d$deviceId|$context|$routerId|$ip", $deviceId, $neighbor, [
                'device' => $deviceId,
                'device_name' => $this->deviceNames[$deviceId],
                'context' => $context,
                'router_id' => $routerId,
                'ip' => $ip,
                'port_name' => $portId ? $this->portName($portId) : '',
                'neighbor' => $neighbor,
                'neighbor_name' => $neighbor !== null ? $this->deviceNames[$neighbor] : null,
            ]);
        }

        return $facts;
    }

    /**
     * Established BGP sessions as each router reports them.
     *
     * @param  array<int, array>  $peers  bgpPeers rows
     * @param  array<string, int>  $addresses  IP address => device_id
     * @return array<string, array>
     */
    public function bgp(array $peers, array $addresses): array
    {
        $facts = [];
        foreach ($peers as $row) {
            $deviceId = (int) $row['device_id'];
            if (strtolower((string) ($row['bgpPeerState'] ?? '')) !== 'established' || ! isset($this->deviceNames[$deviceId])) {
                continue;
            }
            $peer = strtolower((string) ($row['bgpPeerIdentifier'] ?? ''));
            $context = (string) ($row['context_name'] ?? '');
            $vrf = (int) ($row['vrf_id'] ?? 0);
            $neighbor = $addresses[$peer] ?? null;
            $neighbor = $neighbor !== null && $neighbor !== $deviceId && isset($this->deviceNames[$neighbor]) ? $neighbor : null;
            // the same peer address can exist in several VRFs of a router
            $facts += self::fact(self::BGP, "d$deviceId|$context|" . ($vrf ? "vrf$vrf|" : '') . $peer, $deviceId, $neighbor, [
                'device' => $deviceId,
                'device_name' => $this->deviceNames[$deviceId],
                'context' => $context,
                'peer' => $peer,
                'remote_as' => (int) ($row['bgpPeerRemoteAs'] ?? 0),
                'description' => mb_substr((string) ($row['bgpPeerDescr'] ?? ''), 0, 120),
                'neighbor' => $neighbor,
                'neighbor_name' => $neighbor !== null ? $this->deviceNames[$neighbor] : null,
            ]);
        }

        return $facts;
    }

    /**
     * One fact entry keyed by its hash.
     *
     * @return array<string, array>
     */
    public static function fact(string $kind, string $key, ?int $deviceA, ?int $deviceB, array $data): array
    {
        $hash = self::hash($kind, $key);
        $json = self::json($data);

        return [$hash => [
            'kind' => $kind,
            'key' => mb_substr($key, 0, 512),
            'hash' => $hash,
            'device_a' => $deviceA,
            'device_b' => $deviceB,
            'data' => $data,
            'data_hash' => md5($json),
        ]];
    }

    public static function hash(string $kind, string $key): string
    {
        return sha1($kind . '|' . $key);
    }

    /**
     * JSON of fact data as stored (stable for equal data).
     */
    public static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * End of a link on a monitored device: identity, ids and names.
     */
    private function monitoredEnd(int $deviceId, ?int $portId, string $reportedName): array
    {
        $name = $portId && isset($this->ports[$portId]) ? $this->portName($portId) : InterfaceName::short($reportedName);

        return [
            'key' => "d$deviceId:" . ($portId && isset($this->ports[$portId]) ? $this->portKey($portId) : InterfaceName::normalize($reportedName)),
            'device' => $deviceId,
            'port' => $portId,
            'port_name' => $name,
            'name' => $this->deviceNames[$deviceId] ?? "device #$deviceId",
        ];
    }

    /**
     * Data stored for one end of a link.
     */
    private static function endData(array $end): array
    {
        return array_filter([
            'device' => $end['device'],
            'port' => $end['port'],
            'port_name' => $end['port_name'],
            'name' => $end['name'],
            'host' => $end['host'] ?? null,
            'node' => $end['node'] ?? null,
            'role' => $end['role'] ?? null,
            'platform' => $end['platform'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function portKey(int $portId): string
    {
        $name = InterfaceName::normalize(InterfaceName::label($this->ports[$portId] ?? []));

        return $name !== '' ? $name : "port$portId";
    }

    private function portName(int $portId): string
    {
        $name = InterfaceName::short(InterfaceName::label($this->ports[$portId] ?? []));

        return $name !== '' ? $name : "port #$portId";
    }
}
