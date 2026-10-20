<?php

namespace Agoodley\LibrenmsTopology\Redundancy;

/**
 * LibreNMS device dependencies derived from the topology. LibreNMS suppresses a device's alerts
 * while all its parent devices are down (a disabled parent never counts as down); here the parents
 * of a device come from the design graph of the redundancy analysis (every link, also those down
 * now):
 *  - its neighbours one hop closer to the core, through unmanaged devices (LibreNMS parents must be
 *    monitored devices, so an unmonitored switch is skipped to the next monitored one);
 *  - its switches, routers and firewalls at the same distance from the core that rank before it:
 *    lower spanning tree bridge priority (without the VLAN bits), then the switch cabled like a
 *    second core switch (see CoreAdvice), then lower device id. So a switch cabled to both switches
 *    of a core pair whose second switch is not set as core gets that second switch too, and of two
 *    peer-linked switches the later one gets the first. The order only depends on these devices,
 *    not on the rest of the cabling, so it stays the same when other switches come and go.
 * Parents always rank before their children in one strict order (distance, then that rank), so the
 * dependencies never form a loop; more parents only make suppression rarer, the safe side. Core
 * devices and devices without a path to a core get no parents: their current ones are kept.
 */
class DependencyPlanner
{
    /** Status of a device: a core device, parents derived, or no path to a core in the topology. */
    public const CORE = 'core';

    public const DERIVED = 'derived';

    public const NONE = 'none';

    /** Seconds between a device and its parents going down that still count as one outage (about one poll). */
    public const OUTAGE_TOLERANCE = 300;

    /** Bridge priority of devices without spanning tree data (the IEEE default). */
    private const DEFAULT_PRIORITY = 32768;

    /** @var array<string, array> node id => node, see RedundancyAnalyzer::nodes() */
    private array $nodes;

    /** @var array<string, int> graph node => hops from the core node in the design graph */
    private array $distance;

    /** @var array<string, array<int, string>> graph node => neighbours in the design graph */
    private array $adjacency = [];

    /** @var array<string, array<int, int>> graph node => monitored devices toward the core (memo) */
    private array $resolved = [];

    /** @var array<int, int> device_id => bridge priority without the VLAN bits */
    private array $priority = [];

    /** @var array<int, true> device_id => cabled like a second core switch (see CoreAdvice) */
    private array $lookalike = [];

    public function __construct(private RedundancyAnalyzer $analyzer)
    {
        $this->nodes = $analyzer->nodes();
        $graph = $analyzer->graph();
        foreach ($graph->nodes() as $node) {
            $this->adjacency[(string) $node] = array_map('strval', $graph->neighbours($node));
        }
        $this->distance = array_map('intval', $graph->distances(RedundancyAnalyzer::CORE));
        foreach ($analyzer->bridgePriorities() as $deviceId => $priority) {
            $this->priority[(int) $deviceId] = (int) $priority & 0xF000;
        }
        foreach (CoreAdvice::singleCores($analyzer) as $advice) {
            if ($advice['lookalike'] !== null) {
                $this->lookalike[$advice['lookalike']] = true;
            }
        }
    }

    /**
     * Parents of every monitored device.
     *
     * @return array<int, array{status: string, parents: int[], hops: ?int}> device_id => status (CORE, DERIVED or
     *                                                                         NONE), parent device ids and hops from the core (0 for core devices)
     */
    public function derive(): array
    {
        $core = $this->analyzer->coreNodes();
        $copies = $this->analyzer->copies();
        $result = [];
        foreach ($this->nodes as $id => $node) {
            if (! $node['managed'] || isset($node['of'])) {
                continue;
            }
            $id = (string) $id;
            $deviceId = (int) $node['device_id'];
            $hops = null;
            $parents = [];
            foreach ($copies[$id] ?? [$id] as $graphNode) {
                if (isset($this->distance[$graphNode])) {
                    $hops = $hops === null ? $this->distance[$graphNode] - 1 : min($hops, $this->distance[$graphNode] - 1);
                    $parents += array_fill_keys($this->toward($graphNode), true);
                }
            }
            if ($node['network'] && isset($this->distance[$id])) {
                $parents += array_fill_keys($this->peers($id), true);
            }
            unset($parents[$deviceId]);
            $parents = array_keys($parents);
            sort($parents);

            $status = match (true) {
                isset($core[$id]) => self::CORE,
                $hops === null || ! $parents => self::NONE,
                default => self::DERIVED,
            };
            $result[$deviceId] = ['status' => $status, 'parents' => $status === self::DERIVED ? $parents : [], 'hops' => $hops];
        }
        ksort($result);

        return $result;
    }

    /**
     * Monitored devices one hop closer to the core than the node, through unmanaged ones.
     *
     * @return array<int, int> device ids
     */
    private function toward(string $node): array
    {
        if (isset($this->resolved[$node])) {
            return $this->resolved[$node];
        }
        $this->resolved[$node] = []; // guards against a revisit (cannot happen: distances only go down)
        $devices = [];
        foreach ($this->adjacency[$node] ?? [] as $next) {
            if ($next === RedundancyAnalyzer::CORE || ($this->distance[$next] ?? null) !== $this->distance[$node] - 1) {
                continue;
            }
            $info = $this->nodes[$next] ?? null;
            if ($info && $info['managed'] && ! isset($info['of'])) {
                $devices[(int) $info['device_id']] = true;
            } else {
                $devices += array_fill_keys($this->toward($next), true);
            }
        }

        return $this->resolved[$node] = array_keys($devices);
    }

    /**
     * Switches, routers and firewalls next to a network device, at the same distance from the core,
     * that rank before it.
     *
     * @return array<int, int> device ids
     */
    private function peers(string $node): array
    {
        $peers = [];
        foreach ($this->adjacency[$node] ?? [] as $next) {
            $info = $this->nodes[$next] ?? null;
            if ($info && $info['managed'] && $info['network'] && ! isset($info['of'])
                && ($this->distance[$next] ?? null) === $this->distance[$node] && $this->ranksBefore($next, $node)) {
                $peers[] = (int) $info['device_id'];
            }
        }

        return $peers;
    }

    /**
     * Strict order of two network devices at the same distance from the core: lower bridge priority,
     * then the switch cabled like a second core switch, then lower device id.
     */
    private function ranksBefore(string $a, string $b): bool
    {
        $deviceA = (int) $this->nodes[$a]['device_id'];
        $deviceB = (int) $this->nodes[$b]['device_id'];
        $order = ($this->priority[$deviceA] ?? self::DEFAULT_PRIORITY) <=> ($this->priority[$deviceB] ?? self::DEFAULT_PRIORITY)
            ?: isset($this->lookalike[$deviceB]) <=> isset($this->lookalike[$deviceA])
            ?: $deviceA <=> $deviceB;

        return $order < 0;
    }

    /**
     * Derived dependencies next to the current ones, one row per device of $scope.
     *
     * @param  array<int, array{status: string, parents: int[], hops: ?int}>  $derived  see derive()
     * @param  array<int, int[]>  $current  device_id => current parent device ids
     * @param  int[]  $scope  devices to show
     * @return array<int, array{device_id: int, status: string, hops: ?int, current: int[], derived: int[], change: string}>
     *     change: 'same', 'new' (no parents now), 'changed', or 'kept' (core devices and devices without topology)
     */
    public static function compare(array $derived, array $current, array $scope): array
    {
        $rows = [];
        foreach (array_values(array_unique(array_map('intval', $scope))) as $deviceId) {
            $entry = $derived[$deviceId] ?? ['status' => self::NONE, 'parents' => [], 'hops' => null];
            $now = array_values(array_unique(array_map('intval', $current[$deviceId] ?? [])));
            sort($now);
            $change = match (true) {
                $entry['status'] !== self::DERIVED => 'kept',
                $now === $entry['parents'] => 'same',
                ! $now => 'new',
                default => 'changed',
            };
            $rows[] = ['device_id' => $deviceId, 'status' => $entry['status'], 'hops' => $entry['hops'], 'current' => $now,
                'derived' => $entry['parents'], 'change' => $change];
        }

        return $rows;
    }

    /**
     * device_id => derived parents of the rows that change something.
     *
     * @param  array<int, array>  $rows  see compare()
     * @return array<int, int[]>
     */
    public static function changes(array $rows): array
    {
        $changes = [];
        foreach ($rows as $row) {
            if ($row['change'] === 'new' || $row['change'] === 'changed') {
                $changes[$row['device_id']] = $row['derived'];
            }
        }
        ksort($changes);

        return $changes;
    }

    /**
     * What to write for a selection: the changes of the selected rows, except devices whose derived
     * parents are not the ones the admin saw (stale: the topology changed since the preview) and
     * devices that would be on a loop of dependencies afterwards (refused). Refusing a change keeps
     * the device's current parents, which can put another device on a loop: checked again until
     * none is left.
     *
     * @param  array<int, array>  $rows  see compare(), of the selected devices
     * @param  array<int, int[]|null>  $previewed  device_id => parents shown in the preview, null when not known
     * @param  array<int, int[]>  $current  every device's current parents
     * @return array{apply: array<int, int[]>, stale: int[], refused: int[], loops: array<int, int[]>} loops: one
     *     through each refused device, see loops()
     */
    public static function select(array $rows, array $previewed, array $current): array
    {
        $changes = self::changes($rows);
        $apply = [];
        $stale = [];
        foreach ($rows as $row) {
            $deviceId = $row['device_id'];
            $seen = $previewed[$deviceId] ?? null;
            if ($seen !== null) {
                $seen = array_values(array_unique(array_map('intval', $seen)));
                sort($seen);
            }
            if ($seen !== null && $seen !== $row['derived']) {
                $stale[] = $deviceId; // also a change that is no longer derived at all
            } elseif (isset($changes[$deviceId])) {
                $apply[$deviceId] = $changes[$deviceId];
            }
        }
        sort($stale);

        $refused = [];
        $loops = [];
        while ($apply) {
            $after = self::after($current, $apply);
            $cyclic = array_intersect_key($apply, self::onLoops($after));
            if (! $cyclic) {
                break;
            }
            foreach (array_keys($cyclic) as $deviceId) {
                $loop = self::loopThrough($after, $deviceId);
                $loops[implode('>', $loop)] = $loop;
                $refused[] = $deviceId;
                unset($apply[$deviceId]);
            }
        }
        sort($refused);

        return ['apply' => $apply, 'stale' => $stale, 'refused' => $refused, 'loops' => array_values($loops)];
    }

    /**
     * Devices on a loop of dependencies: in a strongly connected component of several devices, or
     * their own parent (Tarjan's algorithm without recursion).
     *
     * @param  array<int, int[]>  $parents  device_id => parents
     * @return array<int, true>
     */
    private static function onLoops(array $parents): array
    {
        $index = [];
        $low = [];
        $stack = [];
        $onStack = [];
        $result = [];
        $counter = 0;
        foreach (array_keys($parents) as $start) {
            if (isset($index[$start])) {
                continue;
            }
            $index[$start] = $low[$start] = $counter++;
            $stack[] = $start;
            $onStack[$start] = true;
            $work = [[$start, 0]]; // device, position in its parents
            while ($work) {
                $top = count($work) - 1;
                [$device, $position] = $work[$top];
                $next = array_values($parents[$device] ?? []);
                if ($position < count($next)) {
                    $work[$top][1]++;
                    $parent = (int) $next[$position];
                    if ($parent === $device) {
                        $result[$device] = true;
                    } elseif (! isset($index[$parent])) {
                        $index[$parent] = $low[$parent] = $counter++;
                        $stack[] = $parent;
                        $onStack[$parent] = true;
                        $work[] = [$parent, 0];
                    } elseif (isset($onStack[$parent])) {
                        $low[$device] = min($low[$device], $index[$parent]);
                    }
                    continue;
                }
                array_pop($work);
                if ($work) {
                    $low[$work[$top - 1][0]] = min($low[$work[$top - 1][0]], $low[$device]);
                }
                if ($low[$device] === $index[$device]) {
                    $component = [];
                    do {
                        $member = array_pop($stack);
                        unset($onStack[$member]);
                        $component[] = $member;
                    } while ($member !== $device);
                    if (count($component) > 1) {
                        $result += array_fill_keys($component, true);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * A shortest loop of dependencies through a device that is on one, as loops() lists them.
     *
     * @param  array<int, int[]>  $parents  device_id => parents
     * @return int[]
     */
    private static function loopThrough(array $parents, int $device): array
    {
        $from = [];
        $queue = [$device];
        for ($i = 0; $i < count($queue); $i++) {
            foreach ($parents[$queue[$i]] ?? [] as $next) {
                $next = (int) $next;
                if ($next === $device) {
                    $path = [];
                    for ($at = $queue[$i]; $at !== $device; $at = $from[$at]) {
                        $path[] = $at;
                    }
                    $loop = [$device, ...array_reverse($path)];
                    $first = (int) array_search(min($loop), $loop, true);

                    return array_merge(array_slice($loop, $first), array_slice($loop, 0, $first));
                }
                if (! isset($from[$next])) {
                    $from[$next] = $queue[$i];
                    $queue[] = $next;
                }
            }
        }

        return [$device];
    }

    /**
     * Parents of every device once the changes of $apply are made: the derived parents for those,
     * the current ones for the others.
     *
     * @param  array<int, int[]>  $current  device_id => current parents
     * @param  array<int, int[]>  $apply  device_id => new parents
     * @return array<int, int[]>
     */
    public static function after(array $current, array $apply): array
    {
        foreach ($apply as $deviceId => $parents) {
            $current[$deviceId] = $parents;
        }

        return array_filter($current);
    }

    /**
     * Loops in a set of dependencies (a device that is its own ancestor). LibreNMS would suppress the
     * alerts of every device of a loop while one of them is down.
     *
     * @param  array<int, int[]>  $parents  device_id => parents
     * @return array<int, int[]> each loop as device ids, from the device with the lowest id
     */
    public static function loops(array $parents): array
    {
        $state = []; // 1 on the path, 2 done
        $loops = [];
        foreach (array_keys($parents) as $start) {
            if (isset($state[$start])) {
                continue;
            }
            // iterative depth first search over the parent edges
            $path = [$start];
            $stack = [[$start, array_values($parents[$start] ?? [])]];
            $state[$start] = 1;
            while ($stack) {
                $top = count($stack) - 1;
                if (! $stack[$top][1]) {
                    $state[$stack[$top][0]] = 2;
                    array_pop($stack);
                    array_pop($path);
                    continue;
                }
                $next = (int) array_shift($stack[$top][1]);
                if (($state[$next] ?? 0) === 1) {
                    $loop = array_slice($path, (int) array_search($next, $path, true));
                    $first = (int) array_search(min($loop), $loop, true);
                    $loop = array_merge(array_slice($loop, $first), array_slice($loop, 0, $first));
                    $loops[implode('>', $loop)] = $loop;
                } elseif (! isset($state[$next])) {
                    $state[$next] = 1;
                    $path[] = $next;
                    $stack[] = [$next, array_values($parents[$next] ?? [])];
                }
            }
        }

        return array_values($loops);
    }

    /**
     * Outages of each device since $since and how many of them each set of dependencies explains: all
     * the device's parents were down when it went down, so LibreNMS would have suppressed its alerts.
     * The outages of every device are indexed once (sorted starts, binary search).
     *
     * @param  array<string, array<int, int[]>>  $sets  name => device_id => parents
     * @param  array<int, array<int, array{0: int, 1: ?int}>>  $outages  device_id => [going down, up again or null]
     * @return array<int, array{outages: int, explained: array<string, int>}> devices with outages since $since
     */
    public static function explained(array $sets, array $outages, int $since): array
    {
        // per device: starts in order and the latest end so far (an open outage never ends)
        $index = [];
        foreach ($outages as $deviceId => $list) {
            usort($list, fn ($a, $b) => $a[0] <=> $b[0]);
            $starts = [];
            $ends = [];
            $latest = PHP_INT_MIN;
            foreach ($list as [$start, $end]) {
                $starts[] = (int) $start;
                $latest = max($latest, $end === null ? PHP_INT_MAX : (int) $end);
                $ends[] = $latest;
            }
            $index[$deviceId] = [$starts, $ends];
        }
        $down = function (int $deviceId, int $at) use ($index): bool {
            if (! isset($index[$deviceId])) {
                return false;
            }
            [$starts, $ends] = $index[$deviceId];
            // the last outage that started by $at (give or take the tolerance)
            $low = 0;
            $high = count($starts) - 1;
            $found = -1;
            while ($low <= $high) {
                $middle = intdiv($low + $high, 2);
                if ($starts[$middle] <= $at + self::OUTAGE_TOLERANCE) {
                    $found = $middle;
                    $low = $middle + 1;
                } else {
                    $high = $middle - 1;
                }
            }

            return $found >= 0 && $ends[$found] >= $at - self::OUTAGE_TOLERANCE;
        };

        $result = [];
        foreach ($outages as $deviceId => $list) {
            $counts = array_fill_keys(array_keys($sets), 0);
            $count = 0;
            foreach ($list as [$start]) {
                if ($start < $since) {
                    continue;
                }
                $count++;
                foreach ($sets as $name => $parents) {
                    $own = $parents[$deviceId] ?? [];
                    if ($own && ! array_filter($own, fn ($parent) => ! $down((int) $parent, (int) $start))) {
                        $counts[$name]++;
                    }
                }
            }
            if ($count) {
                $result[$deviceId] = ['outages' => $count, 'explained' => $counts];
            }
        }

        return $result;
    }
}
