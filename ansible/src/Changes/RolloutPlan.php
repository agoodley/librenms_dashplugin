<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;

/**
 * A change's batch plan made by the topology plugin's rollout planner (its planning API): the planner's order
 * (farthest from the core first, the core last, never two devices that back each other up in one batch) within the
 * change's batch size, with the plugin's canary (the first batch, alone) taken from the planner's first batch: the
 * devices ticked "first" that are in it, else its first devices, as many as the First batch setting; the rest of that
 * batch follows as the second. A subset of a batch keeps its constraints, so the planner's rules hold in what is
 * applied. The devices the planner cannot plan (down, without LLDP/CDP neighbors, without a path to the core, unknown
 * to it) are left out of the change, or put in final batches planned by hand, marked as such: nothing tells what
 * depends on them. Each batch carries its blast radius as the topology plugin computes it for its devices (the same
 * computation as before each batch, so the two compare), and the whole is part of what is dry-run and approved.
 */
final class RolloutPlan
{
    /** The devices the planner cannot plan are left out of the change. */
    public const LEAVE = 'leave';

    /** They go in final batches planned by hand (of at most the batch size). */
    public const HAND = 'hand';

    public const CHOICES = [self::LEAVE, self::HAND];

    /**
     * The batch plan of the targets from the planner's answer.
     *
     * @param  int[]  $deviceIds  the targets, in the order chosen
     * @param  int[]  $canary  the devices ticked for the first batch
     * @param  array  $planner  the planning API's rollout() answer for $deviceIds in batches of $batchSize
     * @param  string  $unplanned  LEAVE or HAND
     * @param  \Closure(int[]): array  $blast  the planning API's blastRadius() answer for a batch's devices
     * @return array{plan: ?BatchPlan, left_out: list<array{device_id: int, reason: string}>, notes: string[], error: ?string}
     *     plan: null when nothing can be planned (error says why); left_out: the devices left out of the change, with the
     *     planner's reason; notes: what the preview tells the admin (a ticked device the planner puts later)
     *
     * @throws \InvalidArgumentException when the planner's answer, or a blast radius, is not one for these devices
     */
    public static function make(array $deviceIds, array $canary, int $canarySize, int $batchSize, int $stopAt, bool $waitAfterFirst, int $pauseMinutes,
        array $planner, string $unplanned, \Closure $blast): array
    {
        $deviceIds = array_values(array_unique(array_map('intval', $deviceIds)));
        $ticked = array_values(array_intersect(array_unique(array_map('intval', $canary)), $deviceIds));
        [$planned, $skipped] = self::read($planner, $deviceIds);
        $names = BlastRadius::names($planner);
        // the core the plan is measured from, of the parts of the network of these devices, and how the topology plugin chose
        // it (its planning API's core_modes, since 1.1; its core names the core of every part the admin sees)
        $modes = is_array($planner['core_modes'] ?? null) ? $planner['core_modes'] : [];
        [$core, $coreModes] = TopologyPlan::coreOf(array_values(array_filter(array_map(fn ($id) => preg_match('/^\d{1,10}$/D', (string) $id) ? (int) $id : 0,
            array_keys($modes)))), $modes);
        $notes = [];

        // the canary from the planner's first batch, the rest of that batch after it
        $batches = [];
        $tiers = [];
        if ($planned) {
            $first = $planned[0]['devices'];
            $chosen = array_values(array_intersect($first, $ticked));
            foreach (array_diff($ticked, $first) as $deviceId) {
                $notes[] = self::name($deviceId, $names) . ' is ticked for the first batch, but ' . (isset($skipped[$deviceId])
                    ? 'the planner cannot plan it (' . TopologyPlan::reasonText($skipped[$deviceId]) . ')'
                    : 'the planner puts it later (' . TopologyPlan::tierText(self::batchTier($planned, $deviceId)) . ')') . ': the first batch is taken from the planner\'s first batch.';
            }
            if (! $chosen) {
                $chosen = array_slice($first, 0, max(1, $canarySize));
            }
            $batches[] = $chosen;
            $tiers[] = $planned[0]['tier'];
            $rest = array_values(array_diff($first, $chosen));
            if ($rest) {
                $batches[] = $rest;
                $tiers[] = $planned[0]['tier'];
            }
            foreach (array_slice($planned, 1) as $batch) {
                $batches[] = $batch['devices'];
                $tiers[] = $batch['tier'];
            }
        }

        // the devices the planner cannot plan, in the order chosen: final batches planned by hand, or left out
        $unplannable = array_values(array_filter($deviceIds, fn (int $deviceId) => isset($skipped[$deviceId])));
        $leftOut = [];
        if ($unplannable && $unplanned === self::HAND) {
            // with no planned batch before them, the first of them is the canary, as in a plan of the settings
            $hand = $planned ? array_chunk($unplannable, max(1, $batchSize))
                : BatchPlan::make($unplannable, $ticked, $canarySize, $batchSize, $stopAt, $waitAfterFirst, $pauseMinutes)->batches;
            foreach ($hand as $devices) {
                $batches[] = array_values($devices);
                $tiers[] = null;
            }
        } else {
            foreach ($unplannable as $deviceId) {
                $leftOut[] = ['device_id' => $deviceId, 'reason' => $skipped[$deviceId]];
            }
        }
        if (! $batches) {
            return ['plan' => null, 'left_out' => $leftOut, 'notes' => $notes, 'error' => 'The topology plugin cannot plan any of these devices ('
                . self::reasonsText($leftOut, $names) . '): put them in final batches planned by hand (the choice below the preview), or choose others.'];
        }

        // each batch's blast radius, as the topology plugin computes it for its devices
        $entries = [];
        $mentioned = array_column($leftOut, 'device_id');
        foreach ($batches as $index => $devices) {
            $answer = BlastRadius::fromApi($blast($devices));
            $entries[] = $answer['blast'] + ['tier' => $tiers[$index], 'hand' => $tiers[$index] === null];
            $names += $answer['names'];
            array_push($mentioned, ...$answer['blast']['lost'], ...array_column($answer['blast']['skipped'], 'device_id'));
        }
        array_push($mentioned, ...$core);
        $topology = new TopologyPlan(mb_substr((string) $planner['version'], 0, 16), $entries, $leftOut,
            array_intersect_key($names, array_flip(array_unique($mentioned))), $core, $coreModes);
        array_push($notes, ...$topology->coreWarnings());
        $plan = new BatchPlan($batches, max(1, $stopAt), $waitAfterFirst, max(0, $pauseMinutes), BatchPlan::TOPOLOGY, $topology);
        $problem = $plan->problem(array_merge(...$batches));
        if ($problem !== null) {
            return ['plan' => null, 'left_out' => $leftOut, 'notes' => $notes, 'error' => ucfirst($problem) . '.'];
        }

        return ['plan' => $plan, 'left_out' => $leftOut, 'notes' => $notes, 'error' => null];
    }

    /**
     * The planner's batches and the devices it cannot plan, checked against the targets: every target in one batch or
     * skipped, nothing else.
     *
     * @param  int[]  $deviceIds
     * @return array{0: list<array{devices: int[], tier: int|string}>, 1: array<int, string>} batches, device_id => reason
     *
     * @throws \InvalidArgumentException
     */
    private static function read(array $planner, array $deviceIds): array
    {
        if (! is_string($planner['version'] ?? null) || ! is_array($planner['batches'] ?? null) || ! array_is_list($planner['batches'])
            || ! is_array($planner['skipped'] ?? null)) {
            throw new \InvalidArgumentException('the topology plugin\'s rollout plan is not one');
        }
        $seen = [];
        $batches = [];
        foreach ($planner['batches'] as $batch) {
            $tier = is_array($batch) ? ($batch['tier'] ?? null) : null;
            if (! is_array($batch) || ! is_array($batch['devices'] ?? null) || ! $batch['devices'] || ! ($tier === TopologyPlan::CORE || (is_int($tier) && $tier >= 0))) {
                throw new \InvalidArgumentException('the topology plugin\'s rollout plan has a batch that is not one');
            }
            $devices = [];
            foreach ($batch['devices'] as $deviceId) {
                if (! is_int($deviceId) || ! in_array($deviceId, $deviceIds, true) || isset($seen[$deviceId])) {
                    throw new \InvalidArgumentException('the topology plugin\'s rollout plan has a device that is not a target, or one twice');
                }
                $seen[$deviceId] = true;
                $devices[] = $deviceId;
            }
            $batches[] = ['devices' => $devices, 'tier' => $tier];
        }
        $skipped = [];
        foreach (TopologyPlan::skippedOf(array_values($planner['skipped']), 'the devices it cannot plan') as $entry) {
            if (! in_array($entry['device_id'], $deviceIds, true) || isset($seen[$entry['device_id']])) {
                throw new \InvalidArgumentException('the topology plugin\'s rollout plan skips a device that is not a target, or one it plans');
            }
            $seen[$entry['device_id']] = true;
            $skipped[$entry['device_id']] = $entry['reason'];
        }
        if (count($seen) !== count($deviceIds)) {
            throw new \InvalidArgumentException('the topology plugin\'s rollout plan leaves out targets');
        }

        return [$batches, $skipped];
    }

    /**
     * The tier of the planner's batch of a device.
     *
     * @param  list<array{devices: int[], tier: int|string}>  $batches
     */
    private static function batchTier(array $batches, int $deviceId): int|string|null
    {
        foreach ($batches as $batch) {
            if (in_array($deviceId, $batch['devices'], true)) {
                return $batch['tier'];
            }
        }

        return null;
    }

    /**
     * Devices left out with why: "lab-dead (down now), lab-other (no LLDP/CDP neighbors: no topology data)".
     *
     * @param  list<array{device_id: int, reason: string}>  $leftOut
     * @param  array<int, string>  $names
     */
    public static function reasonsText(array $leftOut, array $names, int $max = 10): string
    {
        $words = array_map(fn (array $entry) => self::name($entry['device_id'], $names) . ' (' . TopologyPlan::reasonText($entry['reason']) . ')',
            array_slice($leftOut, 0, $max));

        return implode(', ', $words) . (count($leftOut) > $max ? ' and ' . (count($leftOut) - $max) . ' more' : '');
    }

    /**
     * A device's name as the topology plugin gave it, else its id.
     *
     * @param  array<int, string>  $names
     */
    private static function name(int $deviceId, array $names): string
    {
        return UntrustedText::line((string) (($names[$deviceId] ?? '') ?: "device #$deviceId"), 80);
    }
}
