<?php

namespace Agoodley\LibrenmsTopology\Topology;

use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\Graph;
use Agoodley\LibrenmsTopology\Support\InterfaceName;

/**
 * Builds the Layer 2 map (devices joined by LLDP/CDP/... links) from plain arrays.
 */
class L2Builder
{
    private const ROLE_RANK = [
        DeviceRole::FIREWALL => 4,
        DeviceRole::ROUTER => 4,
        DeviceRole::L3SWITCH => 3,
        DeviceRole::SWITCH => 2,
    ];

    /** Factory default names that many unrelated devices advertise. */
    public const GENERIC_HOSTNAMES = [
        'switch', 'router', 'ap', 'phone', 'host', 'localhost', 'localhost.localdomain', 'none', '(none)',
        'unknown', 'default', 'openwrt', 'ubnt', 'mikrotik', 'linux', 'esxi', 'debian', 'ubuntu',
    ];

    /**
     * @param  array<int, array>  $devices  device_id => device row (with 'role', 'url')
     * @param  array<int, array>  $links  rows of the links table
     * @param  array<int, array>  $ports  port_id => port row (link ports and their aggregates)
     * @param  array<int, array<int, bool>>  $portVlans  port_id => [vlan => untagged]
     * @param  array<int, int>  $lagParent  member port_id => aggregate port_id
     * @param  array<int, array<int, string>>  $deviceVlans  device_id => [vlan => name]
     */
    public function __construct(
        private array $devices,
        private array $links,
        private array $ports,
        private array $portVlans,
        private array $lagParent,
        private StpIndex $stp,
        private array $deviceVlans = [],
    ) {
    }

    /**
     * Restrict the device set to $depth hops around $root using managed-to-managed links.
     *
     * @param  array<int, array>  $links
     * @param  int[]  $deviceIds
     * @return int[] device ids ordered by distance from the root
     */
    public static function scopeDevices(array $links, array $deviceIds, ?int $root, ?int $depth): array
    {
        if (! $root || ! in_array($root, $deviceIds, true)) {
            return $deviceIds;
        }

        $allowed = array_flip($deviceIds);
        $graph = new Graph;
        $graph->addNode($root);
        foreach ($links as $link) {
            $a = (int) $link['local_device_id'];
            $b = (int) ($link['remote_device_id'] ?? 0);
            if ($b && $a !== $b && isset($allowed[$a], $allowed[$b])) {
                $graph->addEdge($a, $b);
            }
        }

        $distances = $graph->distances($root, $depth);
        asort($distances);

        return array_keys($distances);
    }

    /**
     * Build the map data returned by api/l2: nodes, edges, VLANs in scope and statistics.
     *
     * @param  array{vlan?: int|null, unmanaged?: bool, collapseLag?: bool, root?: int|null}  $options
     *     vlan: VLAN to highlight (also selects the STP instance shown), unmanaged: draw LLDP/CDP
     *     neighbours LibreNMS does not monitor, collapseLag: one edge per aggregate,
     *     root: centered device (top of the hierarchy)
     */
    public function build(array $options = []): array
    {
        $vlan = isset($options['vlan']) && $options['vlan'] !== '' ? (int) $options['vlan'] : null;
        $showUnmanaged = $options['unmanaged'] ?? true;
        $collapseLag = $options['collapseLag'] ?? true;

        $nodes = [];
        foreach ($this->devices as $deviceId => $device) {
            $nodes['d:' . $deviceId] = $this->deviceNode((int) $deviceId, $device, $vlan);
        }

        $groups = [];
        foreach (self::uniqueLinks($this->links, $this->devices, $this->ports) as $index => $link) {
            $aNode = 'd:' . $link['a_device'];
            if ($link['b_device']) {
                $bNode = 'd:' . $link['b_device'];
            } else {
                if (! $showUnmanaged) {
                    continue;
                }
                $bNode = self::unmanagedNodeId($link);
                $nodes[$bNode] ??= $this->unmanagedNode($bNode, $link);
            }

            $aParent = $link['a_port'] ? ($this->lagParent[$link['a_port']] ?? null) : null;
            $bParent = $link['b_port'] ? ($this->lagParent[$link['b_port']] ?? null) : null;

            if ($collapseLag && ($aParent || $bParent)) {
                $key = 'lag|' . $aNode . '#' . ($aParent ?? '-') . '|' . $bNode . '#' . ($bParent ?? '-');
                if (strcmp($aNode, $bNode) > 0) {
                    $key = 'lag|' . $bNode . '#' . ($bParent ?? '-') . '|' . $aNode . '#' . ($aParent ?? '-');
                }
            } else {
                $key = 'link|' . $index;
            }

            // orient every member of a group the same way (lowest node id on side A)
            if (strcmp($aNode, $bNode) > 0) {
                $link = self::swap($link);
                [$aNode, $bNode] = [$bNode, $aNode];
            }

            $groups[$key]['from'] = $aNode;
            $groups[$key]['to'] = $bNode;
            $groups[$key]['links'][] = $link;
        }

        $edges = [];
        foreach ($groups as $group) {
            $edges[] = $this->edge('e' . count($edges), $group['from'], $group['to'], $group['links'], $vlan, $collapseLag);
        }

        $this->markSuspectedRoots($nodes, $edges, $vlan);
        $this->assignParallelIndexes($edges);
        $this->assignLevels($nodes, $edges, $options['root'] ?? null);

        // VLAN membership of devices (for highlighting) and list of VLANs in scope
        $vlans = [];
        foreach ($this->devices as $deviceId => $device) {
            foreach ($this->deviceVlans[$deviceId] ?? [] as $vlanId => $name) {
                $vlans[$vlanId] ??= $name;
            }
        }
        ksort($vlans);

        return [
            'nodes' => array_values($nodes),
            'edges' => $edges,
            'vlans' => $vlans,
            'stats' => [
                'devices' => count($this->devices),
                'unmanaged' => count($nodes) - count($this->devices),
                'links' => count($edges),
                'down' => count(array_filter($edges, fn ($e) => $e['status'] === 'down')),
                'blocked' => count(array_filter($edges, fn ($e) => $e['blocked'])),
                'stp_roots' => count(array_filter($nodes, fn ($n) => ! empty($n['stp']['root']))),
            ],
        ];
    }

    /**
     * Collapse the links table into one entry per physical link: both neighbours usually report
     * the same link and LLDP + CDP may both be present. Also used by the redundancy analysis, so
     * the map and the analysis agree on links and unmanaged neighbours.
     *
     * @param  array<int, array>  $links  rows of the links table
     * @param  array<int, mixed>  $devices  device_id => row; links of other devices are skipped
     * @param  array<int, array>  $ports  port_id => port row (local port names)
     * @return array<int, array> normalized links, see rawLink()
     */
    public static function uniqueLinks(array $links, array $devices, array $ports): array
    {
        $result = [];
        $byPortPair = [];
        $portToDevice = [];
        $partial = [];

        foreach ($links as $link) {
            $ld = (int) $link['local_device_id'];
            $lp = (int) ($link['local_port_id'] ?? 0);
            $rd = (int) ($link['remote_device_id'] ?? 0);
            $rp = (int) ($link['remote_port_id'] ?? 0);

            if (! isset($devices[$ld]) || ($rd && ! isset($devices[$rd]))) {
                continue; // local or remote device outside the map scope
            }

            if ($rd && $lp && $rp) {
                if ($lp === $rp) {
                    continue;
                }
                $key = min($lp, $rp) . '-' . max($lp, $rp);
                if (isset($byPortPair[$key])) {
                    self::addProtocol($result[$byPortPair[$key]], $link);
                    continue;
                }
                $byPortPair[$key] = count($result);
                $portToDevice[$lp][$rd] = $byPortPair[$key];
                $portToDevice[$rp][$ld] = $byPortPair[$key];
                $result[] = self::rawLink($link, $ld, $lp, $rd, $rp);
            } else {
                $partial[] = $link;
            }
        }

        $partialSeen = [];
        $partialByPair = [];
        foreach ($partial as $link) {
            $ld = (int) $link['local_device_id'];
            $lp = (int) ($link['local_port_id'] ?? 0);
            $rd = (int) ($link['remote_device_id'] ?? 0);
            $remoteName = InterfaceName::normalize($link['remote_port'] ?? '');
            $localName = InterfaceName::normalize($lp ? InterfaceName::label($ports[$lp] ?? []) : '');

            if ($rd) {
                if ($lp && isset($portToDevice[$lp][$rd])) {
                    self::addProtocol($result[$portToDevice[$lp][$rd]], $link);
                    continue; // the neighbour already reported this link with full detail
                }

                // the neighbour may have reported the same link without resolving our port either
                foreach ($partialByPair["$rd>$ld"] ?? [] as [$index, $revLocal, $revRemote]) {
                    if ($localName !== '' && $revRemote === $localName && ($remoteName === '' || $revLocal === $remoteName)) {
                        if (! $result[$index]['b_port'] && $lp) {
                            $result[$index]['b_port'] = $lp;
                        }
                        self::addProtocol($result[$index], $link);
                        continue 2;
                    }
                }

                $key = "$ld|$lp|$rd|$remoteName";
            } else {
                $key = "$ld|$lp|u|" . self::hostKey($link['remote_hostname'] ?? '') . '|' . ($lp ? '' : $remoteName);
            }

            if (isset($partialSeen[$key])) {
                self::addProtocol($result[$partialSeen[$key]], $link);
                continue;
            }

            $partialSeen[$key] = count($result);
            if ($rd) {
                $partialByPair["$ld>$rd"][] = [count($result), $localName, $remoteName];
            }
            $result[] = self::rawLink($link, $ld, $lp, $rd, 0);
        }

        return $result;
    }

    /**
     * Normalized link: side a is the reporting (local) device, side b the neighbour; b_device is
     * null for unmanaged neighbours.
     */
    private static function rawLink(array $link, int $ld, int $lp, int $rd, int $rp): array
    {
        return [
            'a_device' => $ld,
            'a_port' => $lp ?: null,
            'a_name' => '',
            'b_device' => $rd ?: null,
            'b_port' => $rp ?: null,
            'b_name' => (string) ($link['remote_port'] ?? ''),
            'b_host' => (string) ($link['remote_hostname'] ?? ''),
            'b_platform' => (string) ($link['remote_platform'] ?? ''),
            'b_version' => (string) ($link['remote_version'] ?? ''),
            'protocols' => array_filter([strtolower((string) ($link['protocol'] ?? ''))]),
        ];
    }

    /**
     * Remember every discovery protocol (lldp, cdp...) that reported the link.
     */
    private static function addProtocol(array &$raw, array $link): void
    {
        $protocol = strtolower((string) ($link['protocol'] ?? ''));
        if ($protocol !== '' && ! in_array($protocol, $raw['protocols'], true)) {
            $raw['protocols'][] = $protocol;
        }
    }

    /**
     * Same link seen from the other side (a and b exchanged).
     */
    public static function swap(array $link): array
    {
        return array_merge($link, [
            'a_device' => $link['b_device'],
            'a_port' => $link['b_port'],
            'a_name' => $link['b_name'],
            'b_device' => $link['a_device'],
            'b_port' => $link['a_port'],
            'b_name' => $link['a_name'],
        ]);
    }

    /**
     * Short host name, used to recognize the same neighbour reported by LLDP (often short name)
     * and CDP (often FQDN) on one local port.
     */
    private static function hostKey(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            return $hostname;
        }

        return explode('.', $hostname)[0];
    }

    /**
     * Node id of an unmanaged neighbour: one node per advertised name, so a device seen by
     * several switches is drawn once. Missing or factory default names ("Switch", also with a
     * domain: "switch.local") would merge unrelated devices and get one node per local port instead.
     */
    public static function unmanagedNodeId(array $link): string
    {
        $host = strtolower(trim($link['b_host']));
        if ($host === '' || in_array($host, self::GENERIC_HOSTNAMES, true) || in_array(self::shortHost($host), self::GENERIC_HOSTNAMES, true)) {
            return 'u:' . $link['a_device'] . ':' . ($link['a_port'] ?: InterfaceName::normalize($link['b_name']));
        }

        return 'u:' . $host;
    }

    /**
     * Map node for a neighbour LibreNMS does not monitor, typed from its LLDP/CDP platform string.
     */
    private function unmanagedNode(string $id, array $link): array
    {
        $name = $link['b_host'] !== '' ? $link['b_host'] : 'unknown neighbor';

        return [
            'id' => $id,
            'type' => 'unmanaged',
            'managed' => false,
            'device_id' => null,
            'label' => self::shortHost($name),
            'name' => $name,
            'role' => DeviceRole::fromNeighbor($link['b_platform'], $link['b_version'], $link['b_host']),
            'status' => 'unknown',
            'platform' => $link['b_platform'],
            'version' => mb_substr($link['b_version'], 0, 200),
            'stp' => null,
            'vlanMember' => null,
        ];
    }

    /**
     * Map node for a monitored device, including its STP state for the instance that carries the
     * selected VLAN (root bridge, bridge ID, root port...).
     */
    private function deviceNode(int $deviceId, array $device, ?int $vlan): array
    {
        $stp = null;
        $key = $this->stp->keyFor($deviceId, $vlan);
        if ($key !== null && ($instance = $this->stp->instance($deviceId, $key))) {
            $rootPortName = null;
            if ($instance['rootPort'] && ! StpIndex::isRoot($instance)) {
                $rootPortId = $this->stp->rootPortId($deviceId, $vlan);
                $rootPortName = $rootPortId && isset($this->ports[$rootPortId])
                    ? InterfaceName::short(InterfaceName::label($this->ports[$rootPortId]))
                    : 'bridge port ' . $instance['rootPort'];
            }
            $stp = [
                'vlan' => $key,
                'root' => StpIndex::isRoot($instance),
                'priority' => $instance['priority'],
                'bridge' => StpIndex::formatMac($instance['bridgeAddress']),
                'designatedRoot' => StpIndex::formatMac($instance['designatedRoot']),
                'rootCost' => $instance['rootCost'],
                'rootPort' => $rootPortName,
                'protocol' => $instance['protocolSpecification'],
                'topChanges' => $instance['topChanges'],
                'lastTopologyChange' => $instance['timeSinceTopologyChange'],
            ];
        }

        $vlanMember = null;
        if ($vlan !== null && isset($this->deviceVlans[$deviceId])) {
            $vlanMember = isset($this->deviceVlans[$deviceId][$vlan]);
        }

        $display = (string) ($device['display'] ?? '') ?: (string) ($device['hostname'] ?? $deviceId);

        return [
            'id' => 'd:' . $deviceId,
            'type' => 'device',
            'managed' => true,
            'device_id' => $deviceId,
            'label' => self::shortHost($display),
            'name' => $display,
            'hostname' => $device['hostname'] ?? '',
            'sysName' => $device['sysName'] ?? '',
            'role' => $device['role'] ?? DeviceRole::SWITCH,
            'status' => self::deviceStatus($device),
            'hardware' => $device['hardware'] ?? '',
            'os' => $device['os'] ?? '',
            'version' => $device['version'] ?? '',
            'location' => $device['location'] ?? '',
            'url' => $device['url'] ?? null,
            'stp' => $stp,
            'vlanMember' => $vlanMember,
        ];
    }

    /**
     * up / down / disabled from a devices row.
     */
    public static function deviceStatus(array $device): string
    {
        if (! empty($device['disabled'])) {
            return 'disabled';
        }

        return ! empty($device['status']) ? 'up' : 'down';
    }

    /**
     * Diagram label: host part of an FQDN (full name stays in tooltips), anything else untouched.
     */
    public static function shortHost(string $name): string
    {
        if (filter_var($name, FILTER_VALIDATE_IP)) {
            return $name;
        }

        if (preg_match('/^([a-z0-9_-]+)(\.[a-z0-9_-]+)+\.?$/i', $name, $matches)) {
            return $matches[1];
        }

        return $name;
    }

    /**
     * One map edge for a physical link, or for all member links of an aggregate (LAG): endpoints,
     * speed (sum of the members), status, common VLANs, utilization (traffic measured on side A)
     * and whether STP blocks either end.
     *
     * @param  array<int, array>  $members  raw links oriented a=from b=to
     */
    private function edge(string $id, string $from, string $to, array $members, ?int $vlan, bool $collapseLag): array
    {
        $first = $members[0];
        $aParent = $collapseLag && $first['a_port'] ? ($this->lagParent[$first['a_port']] ?? null) : null;
        $bParent = $collapseLag && $first['b_port'] ? ($this->lagParent[$first['b_port']] ?? null) : null;
        $isLag = $aParent || $bParent || count($members) > 1;

        $a = $this->endpoint($first['a_device'], $aParent ?? $first['a_port'], $first['a_name'], $vlan);
        $b = $first['b_device']
            ? $this->endpoint($first['b_device'], $bParent ?? $first['b_port'], $first['b_name'], $vlan)
            : $this->unmanagedEndpoint($first);

        $memberDetails = [];
        $speed = 0;
        $up = 0;
        $inBps = 0;
        $outBps = 0;
        $errors = 0;
        $protocols = [];
        foreach ($members as $member) {
            $pa = $member['a_port'] ? ($this->ports[$member['a_port']] ?? null) : null;
            $pb = $member['b_port'] ? ($this->ports[$member['b_port']] ?? null) : null;
            $memberSpeed = self::linkSpeed($pa, $pb);
            $speed += $memberSpeed;
            $isUp = self::isUp($pa, $pb);
            $up += $isUp ? 1 : 0;
            $inBps += (int) ($pa['ifInOctets_rate'] ?? 0) * 8;
            $outBps += (int) ($pa['ifOutOctets_rate'] ?? 0) * 8;
            $errors += (int) ($pa['ifInErrors_rate'] ?? 0) + (int) ($pa['ifOutErrors_rate'] ?? 0)
                + (int) ($pb['ifInErrors_rate'] ?? 0) + (int) ($pb['ifOutErrors_rate'] ?? 0);
            $protocols = array_merge($protocols, $member['protocols']);

            $nameA = $pa ? InterfaceName::label($pa) : $member['a_name'];
            $nameB = $pb ? InterfaceName::label($pb) : $member['b_name'];
            $memberDetails[] = [
                'a' => InterfaceName::short($nameA),
                'b' => InterfaceName::short($nameB),
                'a_port_id' => $member['a_port'],
                'b_port_id' => $member['b_port'],
                'speed' => $memberSpeed,
                'up' => $isUp,
            ];
        }

        // aggregate reports its own speed; fall back to the member sum
        if ($isLag && $aParent && ! empty($this->ports[$aParent]['ifSpeed'])) {
            $speed = max($speed, (int) $this->ports[$aParent]['ifSpeed']);
        }

        $status = match (true) {
            $up === count($members) => 'up',
            $up === 0 => 'down',
            default => 'degraded',
        };

        $carries = null;
        if ($vlan !== null) {
            $carries = self::combineVlanKnowledge($this->carriesVlan($a['port_id'], $vlan), $this->carriesVlan($b['port_id'], $vlan));
        }

        $vlans = self::intersectVlans($a['vlans'], $b['vlans']);
        $util = $speed > 0 ? round(max($inBps, $outBps) / $speed * 100, 1) : null;
        $blocked = StpIndex::isBlocking($a['stp']['state'] ?? null) || StpIndex::isBlocking($b['stp']['state'] ?? null);

        return [
            'id' => $id,
            'from' => $from,
            'to' => $to,
            'a' => $a,
            'b' => $b,
            'lag' => $isLag ? [
                'a' => $aParent ? $a['short'] : null,
                'b' => $bParent ? $b['short'] : null,
                'members' => count($members),
                'up' => $up,
            ] : null,
            'members' => $memberDetails,
            'speed' => $speed,
            'status' => $status,
            'trunk' => $a['trunk'] || $b['trunk'],
            'vlans' => $vlans,
            'native_vlan' => $a['native_vlan'] ?? $b['native_vlan'],
            'carriesVlan' => $carries,
            'blocked' => $blocked,
            'util' => $util,
            'in_bps' => $inBps,
            'out_bps' => $outBps,
            'errors' => $errors,
            'protocols' => array_values(array_unique($protocols)),
        ];
    }

    /**
     * One end of a link on a monitored device: interface name, speed, status, VLANs, traffic and
     * the STP state/role of the port (or of its aggregate) for the selected VLAN.
     */
    private function endpoint(int $deviceId, ?int $portId, string $fallbackName, ?int $vlan): array
    {
        $port = $portId ? ($this->ports[$portId] ?? null) : null;
        $name = $port ? InterfaceName::label($port) : $fallbackName;
        $vlans = $portId && isset($this->portVlans[$portId]) ? array_keys($this->portVlans[$portId]) : [];
        $native = isset($port['ifVlan']) && is_numeric($port['ifVlan']) && (int) $port['ifVlan'] > 0 ? (int) $port['ifVlan'] : null;
        if ($native !== null && ! in_array($native, $vlans, true)) {
            $vlans[] = $native;
        }
        sort($vlans);

        return [
            'device_id' => $deviceId,
            'port_id' => $portId,
            'managed' => true,
            'name' => $name,
            'short' => InterfaceName::short($name),
            'alias' => (string) ($port['ifAlias'] ?? ''),
            'speed' => (int) ($port['ifSpeed'] ?? 0),
            'oper' => $port['ifOperStatus'] ?? null,
            'admin' => $port['ifAdminStatus'] ?? null,
            'duplex' => $port['ifDuplex'] ?? null,
            'mtu' => isset($port['ifMtu']) ? (int) $port['ifMtu'] : null,
            'native_vlan' => $native,
            'trunk' => ! empty($port['ifTrunk']) || count($vlans) > 1,
            'vlans' => $vlans,
            'in_bps' => (int) ($port['ifInOctets_rate'] ?? 0) * 8,
            'out_bps' => (int) ($port['ifOutOctets_rate'] ?? 0) * 8,
            'url' => $port['url'] ?? null,
            'stp' => $this->stp->portStatus($deviceId, $portId, $vlan),
        ];
    }

    /**
     * Neighbour end of a link to an unmanaged device: only the port name LLDP/CDP reported is known.
     */
    private function unmanagedEndpoint(array $link): array
    {
        return [
            'device_id' => null,
            'port_id' => null,
            'managed' => false,
            'name' => $link['b_name'],
            'short' => InterfaceName::short($link['b_name']),
            'alias' => '',
            'speed' => 0,
            'oper' => null,
            'admin' => null,
            'duplex' => null,
            'mtu' => null,
            'native_vlan' => null,
            'trunk' => false,
            'vlans' => [],
            'in_bps' => 0,
            'out_bps' => 0,
            'url' => null,
            'stp' => null,
        ];
    }

    /**
     * true/false when VLAN data for the port exists, null when unknown.
     */
    private function carriesVlan(?int $portId, int $vlan): ?bool
    {
        if (! $portId) {
            return null;
        }

        $port = $this->ports[$portId] ?? [];
        $native = (int) ($port['ifVlan'] ?? 0);
        if (isset($this->portVlans[$portId])) {
            return isset($this->portVlans[$portId][$vlan]) || $native === $vlan;
        }

        if ($native === $vlan) {
            return true;
        }

        if ($native > 0 && empty($port['ifTrunk'])) {
            return false; // access port in another VLAN
        }

        return null; // trunk without per-VLAN data
    }

    /**
     * Does the link carry the VLAN? Both ends known: both must carry it; one end known: that end
     * decides; nothing known: null (unknown, drawn normally).
     */
    private static function combineVlanKnowledge(?bool $a, ?bool $b): ?bool
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a && $b;
    }

    /**
     * @param  int[]  $a
     * @param  int[]  $b
     * @return int[]
     */
    private static function intersectVlans(array $a, array $b): array
    {
        if (! $a) {
            return $b;
        }
        if (! $b) {
            return $a;
        }

        return array_values(array_intersect($a, $b));
    }

    /**
     * Speed of a link: the slower of its two ends (0 when unknown).
     */
    private static function linkSpeed(?array $a, ?array $b): int
    {
        $speeds = array_filter([(int) ($a['ifSpeed'] ?? 0), (int) ($b['ifSpeed'] ?? 0)]);

        return $speeds ? min($speeds) : 0;
    }

    /**
     * A link is up when every end with known status is up.
     */
    public static function isUp(?array $a, ?array $b): bool
    {
        $states = array_filter([$a['ifOperStatus'] ?? null, $b['ifOperStatus'] ?? null]);
        if (! $states) {
            return true; // nothing known: links table only holds neighbours seen recently
        }

        foreach ($states as $state) {
            if ($state !== 'up') {
                return false;
            }
        }

        return true;
    }

    /**
     * When the root bridge is not monitored and a root port points at an unmanaged neighbour,
     * that neighbour is (or leads to) the root: flag it on the map.
     */
    private function markSuspectedRoots(array &$nodes, array $edges, ?int $vlan): void
    {
        foreach ($edges as $edge) {
            $other = $nodes[$edge['to']] ?? null;
            if (! $other || $other['managed'] || ($edge['a']['stp']['role'] ?? null) !== 'root') {
                continue;
            }

            $deviceId = (int) $edge['a']['device_id'];
            $key = $this->stp->keyFor($deviceId, $vlan);
            $instance = $key !== null ? $this->stp->instance($deviceId, $key) : null;
            if ($instance && $instance['designatedRoot'] !== '' && $this->stp->bridgeOwner($instance['designatedRoot']) === null) {
                $nodes[$edge['to']]['stp'] = [
                    'vlan' => $key,
                    'root' => true,
                    'suspected' => true,
                    'designatedRoot' => StpIndex::formatMac($instance['designatedRoot']),
                ];
            }
        }
    }

    /**
     * Parallel links between the same pair of nodes are drawn as separate curves.
     */
    private function assignParallelIndexes(array &$edges): void
    {
        $pairs = [];
        foreach ($edges as $index => $edge) {
            $pairs[$edge['from'] . '|' . $edge['to']][] = $index;
        }

        foreach ($pairs as $indexes) {
            foreach ($indexes as $position => $index) {
                $edges[$index]['parallel'] = ['index' => $position, 'count' => count($indexes)];
            }
        }
    }

    /**
     * Hierarchy levels for the top-down layout (NetBrain style tiers):
     *  - centered map: distance from the chosen device;
     *  - otherwise the core tier is the STP root (or the best connected switch) plus its peer
     *    (same role, joined by an aggregate / fastest link), routers and firewalls attached to the
     *    core sit above it, everything else hangs below by distance from the core.
     */
    private function assignLevels(array &$nodes, array $edges, ?int $root): void
    {
        $graph = new Graph;
        $incident = [];
        foreach (array_keys($nodes) as $id) {
            $graph->addNode($id);
        }
        foreach ($edges as $edge) {
            $graph->addEdge($edge['from'], $edge['to'], $edge['id']);
            $incident[$edge['from']][] = $edge;
            $incident[$edge['to']][] = $edge;
        }

        $centered = $root && isset($nodes['d:' . $root]) ? 'd:' . $root : null;
        $stpRoots = array_keys(array_filter($nodes, fn ($n) => $n['managed'] && ! empty($n['stp']['root'])));

        $levels = [];
        foreach ($graph->components() as $component) {
            $start = null;
            if ($centered !== null && in_array($centered, $component, true)) {
                $start = $centered;
            } else {
                $start = array_values(array_intersect($stpRoots, $component))[0] ?? null;
            }

            if ($start === null) {
                $bestScore = -1;
                foreach ($component as $id) {
                    $node = $nodes[$id];
                    $rank = in_array($node['role'], [DeviceRole::L3SWITCH, DeviceRole::SWITCH], true) ? self::ROLE_RANK[$node['role']] + 2 : (self::ROLE_RANK[$node['role']] ?? 1);
                    $score = ($node['managed'] ? $rank : 0) * 10000 + $graph->degree($id);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $start = $id;
                    }
                }
            }

            if ($start === $centered) {
                foreach ($graph->distances($start) as $id => $distance) {
                    $levels[$id] = $distance;
                }
                continue;
            }

            // peer core devices: same role as the core, aggregate or fastest link, well connected
            $sources = [$start];
            $fastest = max(array_map(fn ($e) => $e['speed'], $incident[$start] ?? [['speed' => 0]]));
            foreach ($incident[$start] ?? [] as $edge) {
                $other = $edge['from'] === $start ? $edge['to'] : $edge['from'];
                if ($nodes[$other]['managed'] && $nodes[$other]['role'] === $nodes[$start]['role']
                    && ($edge['lag'] || ($fastest > 0 && $edge['speed'] >= $fastest)) && $graph->degree($other) >= 3) {
                    $sources[] = $other;
                }
            }

            foreach ($graph->distances($sources) as $id => $distance) {
                $level = $distance + 1;
                if ($level === 2 && $nodes[$id]['managed'] && in_array($nodes[$id]['role'], [DeviceRole::ROUTER, DeviceRole::FIREWALL], true)) {
                    $level = 0; // perimeter devices above the core
                }
                $levels[$id] = $level;
            }
        }

        $min = $levels ? min($levels) : 0;
        foreach ($nodes as $id => &$node) {
            $node['level'] = ($levels[$id] ?? $min) - $min;
            $node['degree'] = $graph->degree($id);
        }
    }
}
