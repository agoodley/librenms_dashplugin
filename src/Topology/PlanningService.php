<?php

namespace Agoodley\LibrenmsTopology\Topology;

use Agoodley\LibrenmsTopology\Data\DependencyRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Redundancy\DependencyPlanner;
use Agoodley\LibrenmsTopology\Redundancy\RedundancyAnalyzer;
use Agoodley\LibrenmsTopology\Redundancy\RolloutPlanner;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * The rollout planner and the device dependencies: plans on the redundancy analysis of every device
 * the user may see (TopologyService::redundancyAnalyzer(), so they follow the same links as the
 * redundancy page and the map's failure simulation).
 */
class PlanningService
{
    /** Devices of the map's failure simulation (api/simulate) for one batch. */
    public const SIMULATE_LIMIT = 50;

    /** Order of the dependency rows: what changes first. */
    private const CHANGE_ORDER = ['changed' => 0, 'new' => 1, 'same' => 2, 'kept' => 3];

    private ?RedundancyAnalyzer $analyzer = null;

    public function __construct(
        private readonly TopologyRepository $repo,
        private readonly DependencyRepository $dependencies,
        private readonly PluginSettings $settings,
    ) {
    }

    /**
     * Rollout batches for the chosen devices, else for the devices of the group / location filter
     * (only switches, routers and firewalls with $networkOnly).
     *
     * @param  array{group: ?int, location: ?int}  $p  see MapParams::parse()
     * @param  int[]  $chosen  devices picked one by one
     */
    public function rollout(array $p, array $chosen, int $batchSize, bool $networkOnly): array
    {
        $nodes = $this->analyzer()->nodes();
        $chosen = array_values(array_unique(array_filter(array_map('intval', $chosen), fn ($id) => $this->repo->canSee($id))));
        $devices = $chosen;
        if (! $devices) {
            $devices = $this->repo->deviceIds($p['group'] ?? null, $p['location'] ?? null);
            if ($networkOnly) {
                $devices = array_values(array_filter($devices, fn ($id) => $nodes['d:' . $id]['network'] ?? false));
            }
        }

        $plan = (new RolloutPlanner($this->analyzer()))->plan($devices, max(0, $batchSize));
        $core = array_values(array_filter(array_map(fn ($node) => $nodes[$node]['device_id'] ?? null, array_keys($this->analyzer()->coreNodes()))));
        $info = $this->deviceInfo(array_merge($devices, $core, ...array_column($plan['batches'], 'lost')));
        foreach ($plan['batches'] as $index => &$batch) {
            $batch['number'] = $index + 1;
            $batch['hostnames'] = array_map(fn ($id) => $info[$id]['hostname'] ?? (string) $id, $batch['devices']);
            $batch['simulate'] = count($batch['refs']) <= self::SIMULATE_LIMIT;
        }
        unset($batch);

        $planned = array_sum(array_map(fn ($b) => count($b['devices']), $plan['batches']));

        return [
            'batches' => $plan['batches'],
            'skipped' => $plan['skipped'],
            'devices' => $info,
            'core' => $core,
            'summary' => [
                'devices' => $planned,
                'batches' => count($plan['batches']),
                'largest' => $plan['batches'] ? max(array_map(fn ($b) => count($b['devices']), $plan['batches'])) : 0,
                'skipped' => count($plan['skipped']),
                'most_down' => $plan['batches'] ? max(array_map(fn ($b) => count($b['lost']), $plan['batches'])) : 0,
            ],
            'selection' => ['chosen' => $chosen, 'batch_size' => max(0, $batchSize), 'network_only' => $networkOnly],
        ];
    }

    /**
     * Derived device dependencies next to the current ones for the devices of the filter (with a
     * device: that device and the devices that depend on it), loops the changes would create and how
     * many outages each set of dependencies explains.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     */
    public function dependencies(array $p): array
    {
        $all = $this->repo->deviceIds();
        $derived = (new DependencyPlanner($this->analyzer()))->derive();
        [$current, $hidden] = $this->dependencies->parents($all);

        [$scope, $device] = $this->repo->scope($p);
        if ($device) {
            $scope = [$device];
            foreach ($all as $id) {
                if (in_array($device, $derived[$id]['parents'] ?? [], true) || in_array($device, $current[$id] ?? [], true)) {
                    $scope[] = $id;
                }
            }
        }
        $rows = DependencyPlanner::compare($derived, $current, $scope);

        // what the network would look like with every proposed change, and what it explains
        $changes = self::changes(DependencyPlanner::compare($derived, $current, $all));
        $after = DependencyPlanner::after($current, $changes);
        $days = $this->settings->int('dependencies_outage_days', 1, 3650);
        $since = time() - $days * 86400;
        $outages = $this->dependencies->outages($all, $since - DependencyPlanner::OUTAGE_TOLERANCE);
        $now = DependencyPlanner::explained($current, $outages, $since);
        $then = DependencyPlanner::explained($after, $outages, $since);
        foreach ($rows as &$row) {
            $id = $row['device_id'];
            $row['hidden'] = $hidden[$id] ?? 0;
            $row['outages'] = $now[$id]['outages'] ?? 0;
            $row['explained_now'] = $now[$id]['explained'] ?? 0;
            $row['explained_after'] = $then[$id]['explained'] ?? 0;
        }
        unset($row);
        usort($rows, fn ($a, $b) => [self::CHANGE_ORDER[$a['change']] ?? 9, $a['hops'] ?? PHP_INT_MAX] <=> [self::CHANGE_ORDER[$b['change']] ?? 9, $b['hops'] ?? PHP_INT_MAX]
            ?: strnatcasecmp($this->name($a['device_id']), $this->name($b['device_id'])));

        $ids = array_merge(array_column($rows, 'device_id'), ...array_column($rows, 'current'), ...array_column($rows, 'derived'));
        $count = fn (string $change) => count(array_filter($rows, fn ($r) => $r['change'] === $change));
        $newLoops = self::newLoops($current, $after);

        return [
            'rows' => $rows,
            'device_names' => array_map(fn ($info) => $info['name'], $this->deviceInfo($ids)),
            'loops' => $newLoops,
            'days' => $days,
            'summary' => [
                'devices' => count($rows),
                'derived' => count(array_filter($rows, fn ($r) => $r['status'] === DependencyPlanner::DERIVED)),
                'new' => $count('new'),
                'changed' => $count('changed'),
                'same' => $count('same'),
                'kept' => $count('kept'),
                'outages' => array_sum(array_column($rows, 'outages')),
                'explained_now' => array_sum(array_column($rows, 'explained_now')),
                'explained_after' => array_sum(array_column($rows, 'explained_after')),
            ],
        ];
    }

    /**
     * Set the derived parents of the given devices where they differ from the current ones. The
     * parents are derived again here, never taken from the request; changes that would create a loop
     * are refused as a whole.
     *
     * @param  int[]  $deviceIds
     * @return array{changed: int[], message: string, error: bool}
     */
    public function applyDependencies(array $deviceIds): array
    {
        $all = $this->repo->deviceIds();
        $deviceIds = array_values(array_intersect(array_map('intval', $deviceIds), $all));
        $derived = (new DependencyPlanner($this->analyzer()))->derive();
        [$current] = $this->dependencies->parents($all);
        $changes = self::changes(DependencyPlanner::compare($derived, $current, $deviceIds));
        if (! $changes) {
            return ['changed' => [], 'message' => 'Nothing to change: the selected devices already have their derived parents.', 'error' => false];
        }

        $loops = self::newLoops($current, DependencyPlanner::after($current, $changes));
        if ($loops) {
            $names = array_map(fn ($loop) => implode(' → ', array_map(fn ($id) => $this->name($id), [...$loop, $loop[0]])), $loops);

            return ['changed' => [], 'message' => 'Nothing changed: the selected changes would make a loop of dependencies (' . implode('; ', $names)
                . '). Change the dependencies of these devices in LibreNMS first.', 'error' => true];
        }

        $names = array_map(fn ($info) => $info['name'], $this->deviceInfo(array_merge(...array_values($changes))));
        $changed = $this->dependencies->setParents($changes, $names);

        return ['changed' => $changed, 'message' => 'Set the parents of ' . count($changed) . ' device' . (count($changed) === 1 ? '' : 's') . ' from the topology.', 'error' => false];
    }

    /**
     * device_id => derived parents of the rows that change.
     *
     * @return array<int, int[]>
     */
    private static function changes(array $rows): array
    {
        $changes = [];
        foreach ($rows as $row) {
            if ($row['change'] === 'new' || $row['change'] === 'changed') {
                $changes[$row['device_id']] = $row['derived'];
            }
        }

        return $changes;
    }

    /**
     * Loops of the dependencies after the changes that are not there already.
     *
     * @return array<int, int[]>
     */
    private static function newLoops(array $current, array $after): array
    {
        $old = array_map(fn ($loop) => implode('>', $loop), DependencyPlanner::loops(array_filter($current)));

        return array_values(array_filter(DependencyPlanner::loops($after), fn ($loop) => ! in_array(implode('>', $loop), $old, true)));
    }

    /**
     * The redundancy analysis of every device the user may see, built once per request.
     */
    private function analyzer(): RedundancyAnalyzer
    {
        return $this->analyzer ??= (new TopologyService($this->repo, $this->settings))->redundancyAnalyzer($this->repo->deviceIds(), false);
    }

    /**
     * Name, hostname and location of devices the user may see.
     *
     * @param  int[]  $ids
     * @return array<int, array{name: string, hostname: string, location: ?string}>
     */
    private function deviceInfo(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $this->repo->canSee($id))));
        $info = [];
        foreach ($this->repo->devices($ids, roles: false) as $id => $device) {
            $info[(int) $id] = [
                'name' => (string) (($device['display'] ?? '') ?: $device['hostname']),
                'hostname' => (string) $device['hostname'],
                'location' => $device['location'] ?? null,
            ];
        }

        return $info;
    }

    private function name(int $deviceId): string
    {
        return $this->analyzer()->nodes()['d:' . $deviceId]['name'] ?? '#' . $deviceId;
    }
}
