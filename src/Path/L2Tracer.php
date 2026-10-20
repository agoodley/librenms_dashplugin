<?php

namespace Agoodley\LibrenmsTopology\Path;

use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Topology\L2Builder;

/**
 * The Layer 2 path between two devices in one VLAN: the LLDP/CDP links (one per port-channel, as on
 * the map) whose ports are up, carry the VLAN and forward in the spanning tree instance carrying it.
 * The active spanning tree has one path between two switches; where ports have no spanning tree data
 * (or a port-channel spans a switch pair) several paths of the same length remain, and the one shown
 * prefers links with known forwarding ports, then faster links. Only switches pass frames on:
 * routers, firewalls and end devices are never a hop in the middle, nor is a routed port.
 *
 * Node ids are the map's (d:<device_id>, u:<neighbor name>); a monitored device the user may not see
 * is h:<n>, joined to the links of the visible devices that report it.
 */
class L2Tracer
{
    /** Marker of a device out of the user's sight in a link's remote_hostname (see PathRepository::links()). */
    public const HIDDEN = "\0hidden:";

    /** Roles that bridge between their links. */
    private const TRANSIT_ROLES = [DeviceRole::SWITCH, DeviceRole::L3SWITCH, DeviceRole::UNKNOWN];

    /** Spanning tree states that do not forward frames. */
    private const NOT_FORWARDING = ['blocking', 'discarding', 'listening', 'learning', 'broken'];

    /** Paths of the same length counted at most. */
    private const MAX_COUNT = 1000;

    /** @var array<string, array> edge id => edge: from, to, a/b_device, a/b_port (aggregate of a port-channel), members, lag */
    private array $edges = [];

    /** @var array<string, string[]> node => edge ids */
    private array $incident = [];

    /** @var array<string, array{kind: string, role: string, name: string}> */
    private array $nodes = [];

    /**
     * @param  array<int, array>  $devices  device_id => row with role and display name (devices the user may see)
     * @param  array<int, array>  $links  rows of the links table; a remote device out of sight has remote_device_id 0 and
     *                                    remote_hostname HIDDEN . <n>
     * @param  array<int, array>  $ports  port_id => port row (link ports, their aggregates and members)
     * @param  array<int, int>  $lagParent  member port_id => aggregate port_id
     * @param  array<int, array<int, bool>>  $portVlans  port_id => [vlan => untagged]
     * @param  array<int, true>  $routed  ports with an IP address that carry no VLAN
     */
    public function __construct(
        array $devices,
        array $links,
        private readonly array $ports,
        private readonly array $lagParent,
        private StpIndex $stp,
        private readonly array $portVlans = [],
        private readonly array $routed = [],
    ) {
        foreach ($devices as $deviceId => $device) {
            $this->nodes['d:' . $deviceId] = ['kind' => 'device', 'role' => (string) ($device['role'] ?? DeviceRole::SWITCH),
                'name' => (string) (($device['display'] ?? '') ?: ($device['hostname'] ?? $deviceId))];
        }
        $this->buildEdges($devices, $links);
    }

    /**
     * The same links with the spanning tree instances of another VLAN.
     */
    public function withStp(StpIndex $stp): self
    {
        $copy = clone $this;
        $copy->stp = $stp;

        return $copy;
    }

    /**
     * Shortest forwarding path between two devices.
     *
     * @param  int|null  $vlan  null: the default spanning tree instance, VLANs not checked
     * @param  array{leave?: ?int, arrive?: ?int, enter?: ?int, exit?: ?int}  $ports  leave: the port the path must leave the
     *     first device by, arrive: the one it must reach the last device on (a router's interface: may be routed); enter /
     *     exit: where the path comes into the first device and goes out of the last one (a host's access port), recorded only
     * @return array{found: bool, hops: array<int, array>, alternatives: int, unknown_stp: bool, mlag: bool}
     *     hops: node, kind (device / unmanaged / hidden), device_id, name, role, in_edge / out_edge (edge rows) and in_port /
     *     out_port (this hop's port on them, the aggregate of a port-channel)
     */
    public function trace(int $fromDevice, int $toDevice, ?int $vlan, array $ports = []): array
    {
        $source = 'd:' . $fromDevice;
        $target = 'd:' . $toDevice;
        $leave = $ports['leave'] ?? null;
        $arrive = $ports['arrive'] ?? null;
        $none = ['found' => false, 'hops' => [], 'alternatives' => 0, 'unknown_stp' => false, 'mlag' => false];
        if (! isset($this->nodes[$source], $this->nodes[$target])) {
            return $none;
        }
        if ($source === $target) {
            return ['found' => true, 'hops' => [$this->hop($source, null, null, $ports['enter'] ?? null, $ports['exit'] ?? null)]] + $none;
        }

        // breadth first, counting the shortest paths to every node (capped)
        $distance = [$source => 0];
        $count = [$source => 1];
        $predecessors = [];
        $queue = [$source];
        for ($i = 0; $i < count($queue); $i++) {
            $node = $queue[$i];
            if ($node === $target || ($node !== $source && ! $this->transit($node))) {
                continue; // routers and end devices do not pass frames on
            }
            foreach ($this->incident[$node] ?? [] as $edgeId) {
                $edge = $this->edges[$edgeId];
                $next = $edge['from'] === $node ? $edge['to'] : $edge['from'];
                if (($node === $source && $leave !== null && ! $this->onPort($edge, $node, $leave))
                    || ($next === $target && $arrive !== null && ! $this->onPort($edge, $next, $arrive))
                    || ! $this->usable($edge, $vlan, [$node === $source ? $leave : null, $next === $target ? $arrive : null])) {
                    continue;
                }
                if (! isset($distance[$next])) {
                    $distance[$next] = $distance[$node] + 1;
                    $count[$next] = 0;
                    $queue[] = $next;
                }
                if ($distance[$next] === $distance[$node] + 1) {
                    $count[$next] = min(self::MAX_COUNT, $count[$next] + $count[$node]);
                    $predecessors[$next][] = [$node, $edgeId];
                }
            }
        }
        if (! isset($distance[$target])) {
            return $none;
        }

        // walk back from the target, preferring links with known forwarding ports, then faster ones
        $path = [];
        $node = $target;
        $unknown = false;
        $mlag = false;
        while ($node !== $source) {
            $choices = $predecessors[$node];
            usort($choices, fn ($a, $b) => $this->preference($this->edges[$a[1]], $vlan) <=> $this->preference($this->edges[$b[1]], $vlan) ?: strcmp($a[0], $b[0]));
            [$previous, $edgeId] = $choices[0];
            foreach ($choices as [, $otherId]) {
                $unknown = $unknown || ! $this->stpKnown($this->edges[$otherId], $vlan);
                // two equal links into this node through one of its port-channels: it spans a switch pair (MLAG / vPC)
                $mlag = $mlag || ($otherId !== $edgeId && $this->edges[$edgeId]['lag']
                    && self::portOn($this->edges[$edgeId], $node) === self::portOn($this->edges[$otherId], $node));
            }
            $path[] = [$previous, $edgeId];
            $node = $previous;
        }
        $path = array_reverse($path);

        $hops = [];
        $inEdge = null;
        foreach ($path as $index => [$node, $edgeId]) {
            $hops[] = $this->hop($node, $inEdge, $this->edges[$edgeId], $index === 0 ? ($ports['enter'] ?? null) : null, null);
            $inEdge = $this->edges[$edgeId];
        }
        $hops[] = $this->hop($target, $inEdge, null, null, $ports['exit'] ?? null);

        return ['found' => true, 'hops' => $hops, 'alternatives' => max(0, $count[$target] - 1), 'unknown_stp' => $unknown, 'mlag' => $mlag];
    }

    /**
     * Ports that stop the VLAN on the shortest chains of links between two devices, whatever their state: down,
     * blocking, not carrying it or routed. Tells why there is no forwarding path (empty when the devices are not linked).
     *
     * @return array<int, array{device_id: int, port_id: int, why: string}>
     */
    public function blockers(int $fromDevice, int $toDevice, ?int $vlan): array
    {
        $source = 'd:' . $fromDevice;
        $target = 'd:' . $toDevice;
        $fromSource = $this->distances($source, null, null, [], false);
        if (! isset($fromSource[$target])) {
            return [];
        }
        $toTarget = $this->distances($target, null, null, [], false);
        $length = $fromSource[$target];

        $blockers = [];
        foreach ($this->edges as $edge) {
            foreach ([[$edge['from'], $edge['to']], [$edge['to'], $edge['from']]] as [$near, $far]) {
                // on a shortest chain: the source or a switch on one end, the target or a switch on the other
                if (! isset($fromSource[$near], $toTarget[$far]) || $fromSource[$near] + 1 + $toTarget[$far] !== $length
                    || ($near !== $source && ! $this->transit($near)) || ($far !== $target && ! $this->transit($far))) {
                    continue;
                }
                foreach (['a', 'b'] as $side) {
                    $deviceId = $edge[$side . '_device'];
                    $why = $deviceId ? $this->endProblem($deviceId, $edge[$side . '_port'] ? (int) $edge[$side . '_port'] : null, self::sideMembers($edge, $side), $vlan, []) : null;
                    if ($why !== null && count($blockers) < 20) {
                        $blockers[$deviceId . ':' . $edge[$side . '_port']] = ['device_id' => $deviceId, 'port_id' => (int) $edge[$side . '_port'], 'why' => $why];
                    }
                }
                break;
            }
        }

        return array_values($blockers);
    }

    /**
     * How far from a node every switch on the way is loaded (its links known): all transit nodes within radius hops of
     * it (over links that pass the VLAN, or any link) are in $expanded; frontier holds the ones one hop further whose
     * links are still to be read. radius null: nothing left to read, every path from the node is known.
     *
     * @param  array<string, true>  $expanded  nodes whose links are loaded
     * @param  array<int, ?int>  $ends  ports a path may start or end on (routed ones allowed)
     * @return array{radius: ?int, frontier: string[], distances: array<string, int>}
     */
    public function horizon(string $start, ?int $vlan, ?int $startPort, array $ends, array $expanded, bool $usable = true): array
    {
        $distances = $this->distances($start, $vlan, $startPort, $ends, $usable);
        $nearest = null;
        $frontier = [];
        foreach ($distances as $node => $distance) {
            if (isset($expanded[$node]) || ($node !== $start && ! $this->transit($node))) {
                continue;
            }
            if ($nearest === null || $distance < $nearest) {
                $nearest = $distance;
                $frontier = [];
            }
            if ($distance === $nearest) {
                $frontier[] = $node;
            }
        }

        return ['radius' => $nearest === null ? null : $nearest - 1, 'frontier' => $frontier, 'distances' => $distances];
    }

    /**
     * The link between two devices on the given ports (members or aggregates), any link between them for null ports;
     * null when they are not linked that way.
     */
    public function directEdge(int $a, int $b, ?int $portA = null, ?int $portB = null): ?array
    {
        foreach ($this->incident['d:' . $a] ?? [] as $edgeId) {
            $edge = $this->edges[$edgeId];
            if (($edge['from'] === 'd:' . $a ? $edge['to'] : $edge['from']) === 'd:' . $b
                && ($portA === null || $this->onPort($edge, 'd:' . $a, $portA)) && ($portB === null || $this->onPort($edge, 'd:' . $b, $portB))) {
                return $edge;
            }
        }

        return null;
    }

    /**
     * Speed of a link: the aggregate's own speed, else the sum of its members (the slower end of each).
     */
    public function speed(array $edge): int
    {
        return self::linkSpeed($edge, $this->ports);
    }

    /**
     * Speed of a link with these port rows (see speed()).
     *
     * @param  array<int, array>  $ports
     */
    public static function linkSpeed(array $edge, array $ports): int
    {
        if ($edge['lag']) {
            foreach (['a_port', 'b_port'] as $end) {
                $port = $ports[(int) $edge[$end]] ?? [];
                if ((int) ($port['ifSpeed'] ?? 0) > 0 && ($port['ifType'] ?? '') === 'ieee8023adLag') {
                    return (int) $port['ifSpeed'];
                }
            }
        }
        $total = 0;
        foreach ($edge['members'] as $member) {
            $speeds = array_filter([(int) ($ports[(int) $member['a']]['ifSpeed'] ?? 0), (int) ($ports[(int) $member['b']]['ifSpeed'] ?? 0)]);
            $total += $speeds ? min($speeds) : 0;
        }

        return $total;
    }

    /**
     * The port of a link on one of its nodes (the aggregate of a port-channel).
     */
    public static function portOn(array $edge, string $node): ?int
    {
        $port = $edge['from'] === $node ? $edge['a_port'] : ($edge['to'] === $node ? $edge['b_port'] : null);

        return $port ? (int) $port : null;
    }

    /**
     * Member ports of a link on one of its nodes.
     *
     * @return int[]
     */
    public static function membersOn(array $edge, string $node): array
    {
        return self::sideMembers($edge, $edge['from'] === $node ? 'a' : 'b');
    }

    /**
     * One hop of a path: the node and the ports it enters and leaves by.
     */
    private function hop(string $node, ?array $inEdge, ?array $outEdge, ?int $enter, ?int $exit): array
    {
        $info = $this->nodes[$node];

        return [
            'node' => $node,
            'kind' => $info['kind'],
            'device_id' => $info['kind'] === 'device' ? (int) substr($node, 2) : null,
            'name' => $info['name'],
            'role' => $info['role'],
            'in_edge' => $inEdge,
            'out_edge' => $outEdge,
            'in_port' => $inEdge ? self::portOn($inEdge, $node) : $enter,
            'out_port' => $outEdge ? self::portOn($outEdge, $node) : $exit,
        ];
    }

    /**
     * The links between nodes, one per port-channel (as L2Builder groups them on the map).
     *
     * @param  array<int, array>  $devices
     * @param  array<int, array>  $links
     */
    private function buildEdges(array $devices, array $links): void
    {
        foreach (L2Builder::uniqueLinks($links, $devices, $this->ports) as $link) {
            $a = 'd:' . $link['a_device'];
            if ($link['b_device']) {
                $b = 'd:' . $link['b_device'];
            } elseif (str_starts_with($link['b_host'], self::HIDDEN)) {
                $b = 'h:' . substr($link['b_host'], strlen(self::HIDDEN));
                $this->nodes[$b] ??= ['kind' => 'hidden', 'role' => DeviceRole::UNKNOWN, 'name' => 'a device you may not see'];
            } else {
                $b = L2Builder::unmanagedNodeId($link);
                $this->nodes[$b] ??= ['kind' => 'unmanaged', 'role' => DeviceRole::fromNeighbor($link['b_platform'], $link['b_version'], $link['b_host']),
                    'name' => $link['b_host'] !== '' ? $link['b_host'] : 'unknown neighbor'];
            }
            if ($a === $b) {
                continue;
            }

            $aParent = $link['a_port'] ? ($this->lagParent[$link['a_port']] ?? null) : null;
            $bParent = $link['b_port'] ? ($this->lagParent[$link['b_port']] ?? null) : null;
            $ends = [$a . '#' . ($aParent ?? '-'), $b . '#' . ($bParent ?? '-')];
            sort($ends);
            $key = $aParent || $bParent ? 'lag|' . implode('|', $ends) : 'link|' . count($this->edges);
            if (! isset($this->edges[$key])) {
                $this->edges[$key] = [
                    'id' => $key, 'from' => $a, 'to' => $b,
                    'a_device' => (int) $link['a_device'], 'b_device' => $link['b_device'] ? (int) $link['b_device'] : null,
                    'a_port' => $aParent ?? $link['a_port'], 'b_port' => $bParent ?? $link['b_port'],
                    'b_name' => $link['b_name'], 'lag' => (bool) ($aParent || $bParent), 'members' => [],
                ];
                $this->incident[$a][] = $key;
                $this->incident[$b][] = $key;
            }
            // members oriented like the edge
            $this->edges[$key]['members'][] = $this->edges[$key]['from'] === $a
                ? ['a' => $link['a_port'], 'b' => $link['b_port'], 'b_name' => $link['b_name']]
                : ['a' => $link['b_port'], 'b' => $link['a_port'], 'b_name' => ''];
        }
    }

    /**
     * Can frames of the VLAN cross the link: every monitored end passes it (see endProblem()).
     *
     * @param  array<int, ?int>  $ends  ports the path starts or ends on (may be routed)
     */
    private function usable(array $edge, ?int $vlan, array $ends): bool
    {
        foreach (['a', 'b'] as $side) {
            $deviceId = $edge[$side . '_device'];
            if ($deviceId && $this->endProblem($deviceId, $edge[$side . '_port'] ? (int) $edge[$side . '_port'] : null, self::sideMembers($edge, $side), $vlan, $ends) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Why one end of a link does not pass the VLAN, null when it does or nothing is known (unmanaged or out of sight).
     *
     * @param  int[]  $members
     * @param  array<int, ?int>  $ends  ports a path starts or ends on (routed ones allowed)
     */
    private function endProblem(int $deviceId, ?int $portId, array $members, ?int $vlan, array $ends): ?string
    {
        if (! $portId) {
            return null;
        }
        if (! $this->isUp($portId, $members)) {
            return 'down';
        }
        $status = $this->stp->portStatus($deviceId, $portId, $vlan);
        if ($status !== null && in_array($status['state'], self::NOT_FORWARDING, true)) {
            return StpIndex::isBlocking($status['state']) ? 'blocking' : $status['state'];
        }
        $ends = array_values(array_filter($ends));
        if (isset($this->routed[$portId]) && ! in_array($portId, $ends, true) && ! array_intersect($members, $ends)) {
            return 'routed port';
        }
        if ($vlan !== null && $this->carries($portId, $members, $vlan) === false) {
            return 'does not carry VLAN ' . $vlan;
        }

        return null;
    }

    /**
     * A port is up when it says so; a port-channel without a status of its own when one member is.
     *
     * @param  int[]  $members
     */
    private function isUp(int $portId, array $members): bool
    {
        $status = (string) ($this->ports[$portId]['ifOperStatus'] ?? '');
        if ($status === 'up') {
            return true;
        }
        if ($status !== '' && $status !== 'unknown') {
            return false;
        }
        foreach ($members as $member) {
            if (($this->ports[$member]['ifOperStatus'] ?? 'up') === 'up') {
                return true;
            }
        }

        return ! $members;
    }

    /**
     * Does the port (or a member of the port-channel) carry the VLAN? null when unknown (a trunk without VLAN data).
     *
     * @param  int[]  $members
     */
    private function carries(int $portId, array $members, int $vlan): ?bool
    {
        $answer = null;
        foreach (array_merge([$portId], $members) as $id) {
            $port = $this->ports[$id] ?? [];
            $native = is_numeric($port['ifVlan'] ?? null) ? (int) $port['ifVlan'] : 0;
            if (isset($this->portVlans[$id])) {
                if (isset($this->portVlans[$id][$vlan]) || $native === $vlan) {
                    return true;
                }
                $answer = false;
            } elseif ($native === $vlan) {
                return true;
            } elseif ($native > 0 && empty($port['ifTrunk'])) {
                $answer ??= false; // access port in another VLAN
            }
        }

        return $answer;
    }

    /**
     * Hops from a node to every node it reaches through switches (over links that pass the VLAN, or any link), the
     * first link on $startPort when given.
     *
     * @param  array<int, ?int>  $ends
     * @return array<string, int>
     */
    private function distances(string $start, ?int $vlan, ?int $startPort, array $ends, bool $usable): array
    {
        if (! isset($this->nodes[$start])) {
            return [];
        }
        $distance = [$start => 0];
        $queue = [$start];
        for ($i = 0; $i < count($queue); $i++) {
            $node = $queue[$i];
            if ($node !== $start && ! $this->transit($node)) {
                continue;
            }
            foreach ($this->incident[$node] ?? [] as $edgeId) {
                $edge = $this->edges[$edgeId];
                $next = $edge['from'] === $node ? $edge['to'] : $edge['from'];
                if (isset($distance[$next]) || ($node === $start && $startPort !== null && ! $this->onPort($edge, $node, $startPort))
                    || ($usable && ! $this->usable($edge, $vlan, $ends))) {
                    continue;
                }
                $distance[$next] = $distance[$node] + 1;
                $queue[] = $next;
            }
        }

        return $distance;
    }

    private function transit(string $node): bool
    {
        $info = $this->nodes[$node] ?? null;

        return $info !== null && ($info['kind'] === 'hidden' || in_array($info['role'], self::TRANSIT_ROLES, true));
    }

    /**
     * Is the port (a member or the aggregate) the link's end on that node?
     */
    private function onPort(array $edge, string $node, int $portId): bool
    {
        $side = $edge['from'] === $node ? 'a' : 'b';
        $end = (int) $edge[$side . '_port'];

        return $end === $portId || ($this->lagParent[$portId] ?? null) === $end || in_array($portId, self::sideMembers($edge, $side), true);
    }

    /**
     * Sort key of a link among equal paths: known spanning tree state first, then the faster link.
     */
    private function preference(array $edge, ?int $vlan): array
    {
        return [$this->stpKnown($edge, $vlan) ? 0 : 1, -$this->speed($edge)];
    }

    /**
     * Do both ends of the link have spanning tree data for the VLAN (both monitored)?
     */
    private function stpKnown(array $edge, ?int $vlan): bool
    {
        foreach (['a', 'b'] as $side) {
            $deviceId = $edge[$side . '_device'];
            $status = $deviceId && $edge[$side . '_port'] ? $this->stp->portStatus($deviceId, (int) $edge[$side . '_port'], $vlan) : null;
            if ($status === null || ! in_array($status['state'], ['forwarding', ...self::NOT_FORWARDING], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return int[]
     */
    private static function sideMembers(array $edge, string $side): array
    {
        return array_values(array_filter(array_map(fn ($member) => $member[$side] ? (int) $member[$side] : null, $edge['members'])));
    }
}
