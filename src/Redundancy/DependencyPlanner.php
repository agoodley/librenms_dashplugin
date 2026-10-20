<?php

namespace Agoodley\LibrenmsTopology\Redundancy;

/**
 * LibreNMS device dependencies derived from the topology. LibreNMS suppresses a device's alerts
 * while all its parent devices are down; here the parents of a device are its neighbours one hop
 * closer to the core in the design graph of the redundancy analysis (every link, also those down
 * now):
 *  - a dual-homed device gets both uplink devices, so its alerts are only suppressed when both are
 *    down;
 *  - parents are always closer to the core, so the dependencies never form a loop;
 *  - an unmanaged device in between (an unmonitored switch) is skipped to the next monitored device
 *    toward the core, since LibreNMS parents must be monitored devices.
 * Core devices and devices without a path to a core get no parents: their current ones are kept.
 */
class DependencyPlanner
{
    /** Status of a device: a core device, parents derived, or no path to a core in the topology. */
    public const CORE = 'core';

    public const DERIVED = 'derived';

    public const NONE = 'none';

    /** Seconds between a device and its parents going down that still count as one outage (about one poll). */
    public const OUTAGE_TOLERANCE = 300;

    /** @var array<string, array> node id => node, see RedundancyAnalyzer::nodes() */
    private array $nodes;

    /** @var array<string, int> graph node => hops from the core node in the design graph */
    private array $distance;

    /** @var array<string, array<int, string>> graph node => neighbours in the design graph */
    private array $adjacency = [];

    /** @var array<string, array<int, int>> graph node => monitored devices toward the core (memo) */
    private array $resolved = [];

    public function __construct(private RedundancyAnalyzer $analyzer)
    {
        $this->nodes = $analyzer->nodes();
        $graph = $analyzer->graph();
        foreach ($graph->nodes() as $node) {
            $this->adjacency[(string) $node] = array_map('strval', $graph->neighbours($node));
        }
        $this->distance = array_map('intval', $graph->distances(RedundancyAnalyzer::CORE));
    }

    /**
     * Parents of every monitored device.
     *
     * @return array<int, array{status: string, parents: int[], hops: ?int}> device_id => status (CORE, DERIVED or
     *                                                                         NONE), parent device ids and hops from the core
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
            $graphNodes = $copies[$id] ?? [$id];
            $hops = null;
            $parents = [];
            foreach ($graphNodes as $graphNode) {
                if (isset($this->distance[$graphNode])) {
                    $hops = $hops === null ? $this->distance[$graphNode] : min($hops, $this->distance[$graphNode]);
                    $parents += array_fill_keys($this->toward($graphNode), true);
                }
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
     * Outages of each device since $since and how many of them a set of dependencies explains: all
     * its parents were down when it went down, so LibreNMS would have suppressed its alerts.
     *
     * @param  array<int, int[]>  $parents  device_id => parents
     * @param  array<int, array<int, array{0: int, 1: ?int}>>  $outages  device_id => [going down, up again or null]
     * @return array<int, array{outages: int, explained: int, last: ?int}> device_id => counts and the last explained outage start
     */
    public static function explained(array $parents, array $outages, int $since): array
    {
        $down = function (int $deviceId, int $at) use ($outages): bool {
            foreach ($outages[$deviceId] ?? [] as [$start, $end]) {
                if ($start <= $at + self::OUTAGE_TOLERANCE && ($end === null || $end >= $at - self::OUTAGE_TOLERANCE)) {
                    return true;
                }
            }

            return false;
        };

        $result = [];
        foreach ($outages as $deviceId => $list) {
            $count = 0;
            $explained = 0;
            $last = null;
            foreach ($list as [$start]) {
                if ($start < $since) {
                    continue;
                }
                $count++;
                $own = $parents[$deviceId] ?? [];
                if ($own && ! array_filter($own, fn ($parent) => ! $down((int) $parent, $start))) {
                    $explained++;
                    $last = max($last ?? 0, $start);
                }
            }
            if ($count) {
                $result[$deviceId] = ['outages' => $count, 'explained' => $explained, 'last' => $last];
            }
        }

        return $result;
    }
}
