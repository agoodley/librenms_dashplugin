<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * Which device of a change goes in which batch, and when a change stops or waits between batches. Every apply goes in
 * batches: the first (the canary) alone, the rest in batches of the batch size, each one launched only when the one
 * before ended well. The plan is plain data (toArray(), fromArray()), shown before the dry run and part of what is
 * approved, so another component can produce it later, e.g. a rollout plan from the topology plugin (its "source").
 */
final class BatchPlan
{
    /** Version of the plan's data. */
    public const VERSION = 1;

    /** Most batches of one change. */
    public const MAX_BATCHES = 100;

    /**
     * @param  array<int, int[]>  $batches  device ids per batch, in launch order (batch 1 first)
     * @param  int  $stopAt  failed devices in one batch that stop the change (failed, unreachable, unknown and not run count)
     * @param  bool  $waitAfterFirst  wait for an admin to continue after the first batch
     * @param  int  $pauseMinutes  pause between two batches
     * @param  string  $source  who made the plan ("plugin": the plugin's settings)
     */
    public function __construct(
        public readonly array $batches,
        public readonly int $stopAt,
        public readonly bool $waitAfterFirst,
        public readonly int $pauseMinutes,
        public readonly string $source = 'plugin',
    ) {
    }

    /**
     * The plan of the plugin's settings: the canary devices first (those chosen, else the first $canarySize targets),
     * then the other targets in their order, $batchSize per batch.
     *
     * @param  int[]  $deviceIds  the targets, in the order they are listed
     * @param  int[]  $canary  the devices an admin chose for the first batch (none: the first ones)
     */
    public static function make(array $deviceIds, array $canary, int $canarySize, int $batchSize, int $stopAt, bool $waitAfterFirst, int $pauseMinutes): self
    {
        $deviceIds = array_values(array_unique(array_map('intval', $deviceIds)));
        $canary = array_values(array_intersect(array_unique(array_map('intval', $canary)), $deviceIds));
        if (! $canary) {
            $canary = array_slice($deviceIds, 0, max(1, $canarySize));
        }
        $rest = array_values(array_diff($deviceIds, $canary));
        $batches = $canary ? [$canary] : [];
        foreach (array_chunk($rest, max(1, $batchSize)) as $chunk) {
            $batches[] = $chunk;
        }

        return new self($batches, max(1, $stopAt), $waitAfterFirst, max(0, $pauseMinutes));
    }

    /**
     * A plan from its data, checked against the change's targets: every target in exactly one batch, nothing else.
     *
     * @param  int[]  $targetIds
     *
     * @throws \InvalidArgumentException with what is wrong
     */
    public static function fromArray(mixed $data, array $targetIds): self
    {
        if (! is_array($data) || ($data['version'] ?? null) !== self::VERSION || ! is_array($data['batches'] ?? null) || ! array_is_list($data['batches'])) {
            throw new \InvalidArgumentException('the batch plan is not a plan of version ' . self::VERSION);
        }
        $batches = [];
        foreach ($data['batches'] as $index => $batch) {
            if (! is_array($batch) || ! array_is_list($batch) || ! $batch) {
                throw new \InvalidArgumentException('batch ' . ($index + 1) . ' of the plan is empty or not a list of devices');
            }
            $ids = [];
            foreach ($batch as $id) {
                if (! is_int($id) || $id < 1) {
                    throw new \InvalidArgumentException('batch ' . ($index + 1) . ' of the plan has an entry that is not a device id');
                }
                $ids[] = $id;
            }
            $batches[] = $ids;
        }
        $plan = new self($batches, is_int($data['stop_at'] ?? null) ? $data['stop_at'] : 0, ($data['wait_after_first'] ?? null) === true,
            is_int($data['pause_minutes'] ?? null) ? $data['pause_minutes'] : -1, is_string($data['source'] ?? null) ? mb_substr($data['source'], 0, 40) : 'plugin');
        $problem = $plan->problem($targetIds);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }

        return $plan;
    }

    /**
     * What is wrong with the plan for these targets, null when nothing is.
     *
     * @param  int[]  $targetIds
     */
    public function problem(array $targetIds): ?string
    {
        if (! $this->batches) {
            return 'the batch plan has no batch';
        }
        if (count($this->batches) > self::MAX_BATCHES) {
            return 'the batch plan has more than ' . self::MAX_BATCHES . ' batches';
        }
        if ($this->stopAt < 1 || $this->pauseMinutes < 0) {
            return 'the batch plan has no failure threshold or a negative pause';
        }
        $all = array_merge(...$this->batches);
        if (count($all) !== count(array_unique($all))) {
            return 'a device is in more than one batch';
        }
        $targets = array_map('intval', $targetIds);
        if (array_diff($all, $targets)) {
            return 'the batch plan has devices that are not targets of the change';
        }
        if (array_diff($targets, $all)) {
            return 'the batch plan leaves out targets of the change';
        }

        return null;
    }

    /**
     * The plan as data (stored with the change, part of its fingerprint).
     *
     * @return array{version: int, source: string, batches: array<int, int[]>, stop_at: int, wait_after_first: bool, pause_minutes: int}
     */
    public function toArray(): array
    {
        return ['version' => self::VERSION, 'source' => $this->source, 'batches' => $this->batches, 'stop_at' => $this->stopAt,
            'wait_after_first' => $this->waitAfterFirst, 'pause_minutes' => $this->pauseMinutes];
    }

    /**
     * Number of batches.
     */
    public function count(): int
    {
        return count($this->batches);
    }

    /**
     * The devices of batch $number (1 is the first), none for a batch that does not exist.
     *
     * @return int[]
     */
    public function devices(int $number): array
    {
        return $this->batches[$number - 1] ?? [];
    }

    /**
     * The batch of a device (1 is the first), null when it is not in the plan.
     */
    public function batchOf(int $deviceId): ?int
    {
        foreach ($this->batches as $index => $batch) {
            if (in_array($deviceId, $batch, true)) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * The plan in words: "3 batches: 1, then 5, then 2 devices; stops at 1 failed device in a batch; waits for an admin
     * after the first batch".
     */
    public function describe(): string
    {
        $sizes = array_map('count', $this->batches);
        $text = count($sizes) . (count($sizes) === 1 ? ' batch' : ' batches') . ': ' . implode(', then ', $sizes) . ' ' . (array_sum($sizes) === 1 ? 'device' : 'devices')
            . '; stops at ' . $this->stopAt . ' failed ' . ($this->stopAt === 1 ? 'device' : 'devices') . ' in a batch';
        if ($this->waitAfterFirst && count($sizes) > 1) {
            $text .= '; waits for an admin to continue after the first batch';
        }
        if ($this->pauseMinutes > 0 && count($sizes) > 1) {
            $text .= '; ' . $this->pauseMinutes . ' min between batches';
        }

        return $text;
    }
}
