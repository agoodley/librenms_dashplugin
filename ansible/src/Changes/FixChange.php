<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;

/**
 * A change made from fixes (the Fixes page): the fixes chosen among the topology plugin's answer (its verification
 * API's fixes(), as the admin sees the network), the devices they change, and for a root priority fix its own batches:
 * the intended roots alone first, then their secondaries, so that the root of each VLAN moves once. Pure.
 */
final class FixChange
{
    /** Most fixes one change takes. */
    public const MAX_FIXES = 200;

    /**
     * The fixes of these ids among the API's answer, in the answer's order; why not when one is no longer there or not of
     * the kind (the findings changed since the Fixes page was loaded, or the request is not one of its).
     *
     * @param  string[]  $ids
     * @return array{fixes: list<array>, error: ?string}
     */
    public static function choose(string $kind, array $ids, array $answer): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn (string $id) => preg_match('/^[0-9a-f]{40}$/D', $id) === 1)));
        if (! in_array($kind, FixAnswers::KINDS, true) || ! $ids) {
            return ['fixes' => [], 'error' => 'Choose fixes of one kind on the Fixes page.'];
        }
        if (count($ids) > self::MAX_FIXES) {
            return ['fixes' => [], 'error' => 'A change takes at most ' . self::MAX_FIXES . ' fixes.'];
        }
        $byId = [];
        foreach ((array) ($answer['fixes'] ?? []) as $fix) {
            if (is_array($fix) && is_string($fix['id'] ?? null)) {
                $byId[$fix['id']] = $fix;
            }
        }
        $fixes = [];
        foreach ($ids as $id) {
            $fix = $byId[$id] ?? null;
            if ($fix === null) {
                return ['fixes' => [], 'error' => 'A fix you chose is no longer offered (its findings were resolved or changed since you loaded the Fixes page): look again.'];
            }
            if (($fix['kind'] ?? null) !== $kind) {
                return ['fixes' => [], 'error' => 'A change is made from fixes of one kind.'];
            }
            $fixes[] = $fix;
        }

        return ['fixes' => $fixes, 'error' => null];
    }

    /**
     * The devices the fixes change, in their order (the root priority fixes: the intended roots, then their secondaries).
     *
     * @param  list<array>  $fixes
     * @return int[]
     */
    public static function devices(string $kind, array $fixes): array
    {
        if ($kind === FixAnswers::STP_ROOT_PRIORITY) {
            [$primaries, $secondaries] = self::rootBatches($fixes);

            return array_values(array_unique([...$primaries, ...$secondaries]));
        }

        return array_values(array_unique(array_map('intval', array_merge([], ...array_map(fn (array $fix) => (array) ($fix['devices'] ?? []), $fixes)))));
    }

    /**
     * The batches of root priority fixes: the intended roots (alone in the first batch), then their secondaries.
     *
     * @param  list<array>  $fixes
     * @return array{0: int[], 1: int[]}
     */
    public static function rootBatches(array $fixes): array
    {
        $primaries = [];
        $secondaries = [];
        foreach ($fixes as $fix) {
            if (isset($fix['primary']['device_id'])) {
                $primaries[(int) $fix['primary']['device_id']] = true;
            }
            if (isset($fix['secondary']['device_id'])) {
                $secondaries[(int) $fix['secondary']['device_id']] = true;
            }
        }
        // a switch that is the intended root of one domain and the secondary of another is set as a root, in the first batch
        $secondaries = array_diff_key($secondaries, $primaries);

        return [array_keys($primaries), array_keys($secondaries)];
    }

    /**
     * The topology plugin's rollout plan of a root priority fix's devices, reordered: its intended roots first, alone, then
     * their secondaries, each batch with the planner's place of its devices (its tier) and the devices it cannot plan as it
     * says; why, in words, when the planner's own order was another.
     *
     * @param  array  $planner  the planning API's rollout() answer for the fix's devices, in batches of one
     * @param  int[]  $primaries
     * @param  int[]  $secondaries
     * @param  array<int, string>  $names
     * @return array{planner: array, note: ?string}
     */
    public static function rootPlanner(array $planner, array $primaries, array $secondaries, array $names): array
    {
        $tiers = [];
        $order = [];
        foreach ((array) ($planner['batches'] ?? []) as $batch) {
            foreach ((array) ($batch['devices'] ?? []) as $deviceId) {
                $tiers[(int) $deviceId] = $batch['tier'] ?? null;
                $order[] = (int) $deviceId;
            }
        }
        $skipped = [];
        foreach ((array) ($planner['skipped'] ?? []) as $entry) {
            $skipped[(int) ($entry['device_id'] ?? 0)] = true;
        }
        $batches = [];
        foreach ([$primaries, $secondaries] as $devices) {
            $planned = array_values(array_filter($devices, fn (int $deviceId) => ! isset($skipped[$deviceId]) && array_key_exists($deviceId, $tiers)));
            if ($planned) {
                // the batch's place: the one nearest the core among its devices (the core itself, else the lowest tier)
                $tier = in_array(TopologyPlan::CORE, array_map(fn (int $deviceId) => $tiers[$deviceId], $planned), true) ? TopologyPlan::CORE
                    : min(array_map(fn (int $deviceId) => (int) $tiers[$deviceId], $planned));
                $batches[] = ['devices' => $planned, 'tier' => $tier];
            }
        }
        $note = null;
        $first = $order[0] ?? null;
        if ($first !== null && ! in_array($first, $primaries, true)) {
            $note = 'The fix sets the intended root first, alone, then its secondary, so that the root of each VLAN moves once (to '
                . self::list($primaries, $names) . '); the topology plugin\'s planner would have started with ' . self::list([$first], $names)
                . ' (farthest from the core first). What each batch cuts off is computed as for any change.';
        }

        return ['planner' => ['batches' => $batches] + $planner, 'note' => $note];
    }

    /**
     * @param  int[]  $devices
     * @param  array<int, string>  $names
     */
    private static function list(array $devices, array $names): string
    {
        return implode(', ', array_map(fn (int $deviceId) => UntrustedText::line((string) (($names[$deviceId] ?? '') ?: "device #$deviceId"), 80), array_slice($devices, 0, 5)))
            . (count($devices) > 5 ? ' and ' . (count($devices) - 5) . ' more' : '');
    }
}
