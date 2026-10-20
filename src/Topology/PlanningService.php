<?php

namespace Agoodley\LibrenmsTopology\Topology;

use Agoodley\LibrenmsTopology\Data\DependencyRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Redundancy\CoreAdvice;
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
     * Rollout batches for the picked devices, else for the devices of the group / location filter
     * (only switches, routers and firewalls with $networkOnly).
     *
     * @param  array{group: ?int, location: ?int}  $p  see MapParams::parse()
     * @param  int[]  $picked  devices picked one by one; those that cannot be planned are listed as skipped
     */
    public function rollout(array $p, array $picked, int $batchSize, bool $networkOnly): array
    {
        $nodes = $this->analyzer()->nodes();
        $picked = self::ids($picked);
        $batchSize = max(0, $batchSize);
        $devices = self::rolloutDevices($picked, fn () => $this->repo->deviceIds($p['group'] ?? null, $p['location'] ?? null), $nodes, $networkOnly);

        $plan = (new RolloutPlanner($this->analyzer()))->plan($devices, $batchSize);
        $core = $this->coreIds();
        $advice = CoreAdvice::singleCores($this->analyzer());
        $info = $this->deviceInfo(array_merge($devices, $core, array_column($advice, 'lookalike'), ...array_column($plan['batches'], 'lost')));
        foreach ($plan['batches'] as $index => &$batch) {
            $batch['number'] = $index + 1;
            $batch['hostnames'] = array_map(fn ($id) => $info[$id]['hostname'] ?? (string) $id, $batch['devices']);
            $batch['simulate'] = count($batch['refs']) <= self::SIMULATE_LIMIT;
        }
        unset($batch);

        $sizes = array_map(fn ($b) => count($b['devices']), $plan['batches']);

        return [
            'batches' => $plan['batches'],
            'skipped' => $plan['skipped'],
            'devices' => $info,
            'core' => $core,
            'advice' => $advice,
            'summary' => [
                'devices' => array_sum($sizes),
                'batches' => count($plan['batches']),
                'largest' => $sizes ? max($sizes) : 0,
                'skipped' => count($plan['skipped']),
                'most_down' => $plan['batches'] ? max(array_map(fn ($b) => count($b['lost']), $plan['batches'])) : 0,
            ],
            'selection' => ['picked' => $picked, 'batch_size' => $batchSize, 'network_only' => $networkOnly],
        ];
    }

    /**
     * Devices to plan: the picked ones as they are (the planner lists the ones it cannot plan), else
     * the devices of the filter, only switches, routers and firewalls with $networkOnly.
     *
     * @param  int[]  $picked
     * @param  callable(): int[]  $filtered  devices of the group / location filter
     * @param  array<string, array>  $nodes  see RedundancyAnalyzer::nodes()
     * @return int[]
     */
    public static function rolloutDevices(array $picked, callable $filtered, array $nodes, bool $networkOnly): array
    {
        if ($picked) {
            return $picked;
        }
        $devices = array_map('intval', $filtered());
        if ($networkOnly) {
            $devices = array_filter($devices, fn ($id) => $nodes['d:' . $id]['network'] ?? false);
        }

        return array_values($devices);
    }

    /**
     * Derived device dependencies next to the current ones for the devices of the filter (with a
     * device: that device and the devices that depend on it), loops the changes would create, how
     * many outages each set of dependencies explains and the fingerprint of the changes (to apply
     * every change of the view as previewed).
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     */
    public function dependencies(array $p): array
    {
        [$rows, $known, $derived] = $this->view($p, $this->repo->deviceIds());
        $current = $known['parents'];
        $changes = DependencyPlanner::changes($rows);

        // outages of the devices of the view and of their parents, now and with the derived ones
        $days = $this->settings->int('dependencies_outage_days', 1, 3650);
        $since = time() - $days * 86400;
        $ids = array_merge(array_column($rows, 'device_id'), ...array_column($rows, 'current'), ...array_column($rows, 'derived'));
        $outages = $this->dependencies->outages(array_values(array_unique($ids)), $since - DependencyPlanner::OUTAGE_TOLERANCE);
        $explained = DependencyPlanner::explained(['now' => $current, 'after' => DependencyPlanner::after($current, $changes)], $outages, $since);
        foreach ($rows as &$row) {
            $id = $row['device_id'];
            $row['hidden'] = $known['hidden'][$id] ?? 0;
            $row['outages'] = $explained[$id]['outages'] ?? 0;
            $row['explained_now'] = $explained[$id]['explained']['now'] ?? 0;
            $row['explained_after'] = $explained[$id]['explained']['after'] ?? 0;
        }
        unset($row);

        $disabled = array_values(array_intersect(array_keys($known['disabled']), array_merge([], ...array_column($rows, 'current'))));
        $core = [];
        foreach ($this->analyzer()->coreNodes() as $node => $mode) {
            if ($deviceId = $this->analyzer()->nodes()[$node]['device_id'] ?? null) {
                $core[] = ['device_id' => (int) $deviceId, 'mode' => $mode];
            }
        }
        // the parents a switch cabled like a second core switch gets until it is set as core
        $advice = array_map(fn ($entry) => $entry + ['parents' => $derived[$entry['lookalike']]['parents'] ?? []], CoreAdvice::singleCores($this->analyzer()));
        $names = array_map(fn ($info) => $info['name'], $this->deviceInfo(array_merge($ids, array_column($core, 'device_id'),
            array_column($advice, 'lookalike'), ...array_column($advice, 'parents'))));
        $count = fn (string $change) => count(array_filter($rows, fn ($r) => $r['change'] === $change));

        return [
            'rows' => $rows,
            'device_names' => $names + array_intersect_key($known['disabled'], array_flip($disabled)),
            'disabled' => $disabled,
            'core' => $core,
            'advice' => $advice,
            'loops' => DependencyPlanner::select($rows, [], $current)['loops'],
            'fingerprint' => self::fingerprint($changes),
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
     * Set the derived parents of the picked devices (device_id => the parents the preview showed),
     * or of every change of a view (its filter and the fingerprint of the changes the preview
     * showed). The parents are derived again here, never taken from the request: a picked device
     * whose derived parents are no longer the ones shown is skipped, a view whose changes are no
     * longer the ones shown is not applied at all, and devices that would be on a loop of
     * dependencies are refused.
     *
     * @param  array<int, int[]>  $picked  device_id => derived parents shown in the preview
     * @param  array{device: ?int, group: ?int, location: ?int}|null  $view  apply every change of this view instead
     * @return array{changed: int[], stale: int[], refused: int[], message: string, error: bool}
     */
    public function applyDependencies(array $picked, ?array $view = null, ?string $fingerprint = null): array
    {
        $all = $this->repo->deviceIds();
        $result = ['changed' => [], 'stale' => [], 'refused' => [], 'message' => '', 'error' => true];
        if ($view !== null) {
            [$rows, $known] = $this->view($view, $all);
            $previewed = DependencyPlanner::changes($rows);
            if ($fingerprint === null || ! hash_equals(self::fingerprint($previewed), $fingerprint)) {
                return ['message' => 'Nothing changed: the derived or the current dependencies of this view changed since the preview. Check the changes below and apply again.'] + $result;
            }
        } else {
            $picked = array_intersect_key($picked, array_flip($all));
            if (! $picked) {
                return ['message' => 'Select at least one device.'] + $result;
            }
            $known = $this->dependencies->parents($all);
            $rows = DependencyPlanner::compare((new DependencyPlanner($this->analyzer()))->derive(), $known['parents'], array_keys($picked));
            $previewed = $picked;
        }

        $current = $known['parents'];
        $selection = DependencyPlanner::select($rows, $previewed, $current);
        $refused = $selection['refused'];
        if (! $selection['apply'] && ! $selection['stale'] && ! $refused) {
            return ['message' => 'Nothing to change: the selected devices already have their derived parents.', 'error' => false] + $result;
        }

        $ids = array_merge(array_keys($selection['apply']), $selection['stale'], ...array_values($selection['apply']),
            ...array_values(array_intersect_key($current, $selection['apply'])), ...$selection['loops']);
        $names = array_map(fn ($info) => $info['name'], $this->deviceInfo($ids)) + $known['disabled'];
        $changed = $this->dependencies->setParents($selection['apply'], $current, $names);

        $list = fn (array $devices) => implode(', ', array_map(fn ($id) => $names[$id] ?? '#' . $id, array_slice($devices, 0, 10)))
            . (count($devices) > 10 ? ' and ' . (count($devices) - 10) . ' more' : '');
        $plural = fn (int $count, string $word) => $count . ' ' . $word . ($count === 1 ? '' : 's');
        $message = [];
        if ($changed) {
            $message[] = 'Set the parents of ' . $plural(count($changed), 'device') . ' from the topology.';
        }
        if ($selection['stale']) {
            $message[] = 'Skipped ' . $plural(count($selection['stale']), 'device') . ' whose derived parents changed since the preview ('
                . $list($selection['stale']) . '): check them again.';
        }
        if ($refused) {
            $loops = array_map(fn ($loop) => implode(' → ', array_map(fn ($id) => $names[$id] ?? '#' . $id, [...$loop, $loop[0]])), $selection['loops']);
            $message[] = 'Refused ' . $plural(count($refused), 'device') . ' that would be on a loop of dependencies (' . implode('; ', array_slice($loops, 0, 5))
                . (count($loops) > 5 ? '...' : '') . '): change the current dependencies of these devices in LibreNMS first.';
        }

        return ['changed' => $changed, 'stale' => $selection['stale'], 'refused' => $refused, 'message' => implode(' ', $message), 'error' => ! $changed];
    }

    /**
     * Fingerprint of a set of changes (device_id => new parents): what "apply every change of this
     * view" checks the view against.
     *
     * @param  array<int, int[]>  $changes
     */
    public static function fingerprint(array $changes): string
    {
        ksort($changes);

        return sha1((string) json_encode(array_map(fn ($parents) => array_map('intval', $parents), $changes)));
    }

    /**
     * Rows of a view (the devices of the filter; with a device: that device and the devices that
     * depend on it now or would with the derived parents), what changes first, and the current
     * parents of every device.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     * @param  int[]  $all  every device the user may see
     * @return array{0: array<int, array>, 1: array{parents: array<int, int[]>, disabled: array<int, string>, hidden: array<int, int>},
     *     2: array<int, array>} rows (see DependencyPlanner::compare()), DependencyRepository::parents() and
     *     DependencyPlanner::derive()
     */
    private function view(array $p, array $all): array
    {
        $derived = (new DependencyPlanner($this->analyzer()))->derive();
        $known = $this->dependencies->parents($all);
        $current = $known['parents'];

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
        usort($rows, fn ($a, $b) => [self::CHANGE_ORDER[$a['change']] ?? 9, $a['hops'] ?? PHP_INT_MAX] <=> [self::CHANGE_ORDER[$b['change']] ?? 9, $b['hops'] ?? PHP_INT_MAX]
            ?: strnatcasecmp($this->name($a['device_id']), $this->name($b['device_id'])));

        return [$rows, $known, $derived];
    }

    /**
     * Positive device ids, each once, in the given order.
     *
     * @param  array<int, mixed>  $ids
     * @return int[]
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', array_filter($ids, 'is_scalar')), fn ($id) => $id > 0)));
    }

    /**
     * Core devices of the redundancy analysis.
     *
     * @return int[]
     */
    private function coreIds(): array
    {
        $nodes = $this->analyzer()->nodes();

        return array_values(array_filter(array_map(fn ($node) => $nodes[$node]['device_id'] ?? null, array_keys($this->analyzer()->coreNodes()))));
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
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($ids, fn ($id) => $id !== null)), fn ($id) => $this->repo->canSee($id))));
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
