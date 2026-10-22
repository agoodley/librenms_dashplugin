<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;

/**
 * What the topology plugin said about a change's batches when the change was made (RolloutPlan): per batch its tier
 * (hops from the core, the core, or planned by hand) and its blast radius (BlastRadius: the monitored devices it cuts
 * off, the unmanaged neighbors that go down, the devices the topology could tell nothing about), the devices chosen
 * for the change that were left out because the planner could not plan them, and the names of the devices it
 * mentions. It is part of the change's batch plan, so of its fingerprint: what is dry-run and approved. Plain data,
 * checked when it is read back.
 */
final class TopologyPlan
{
    /** Tier of a batch of the core devices. */
    public const CORE = 'core';

    /** Most characters of a stored device name. */
    private const NAME_MAX = 120;

    /**
     * @param  string  $api  the version of the topology plugin's planning API that planned it
     * @param  array<int, array{tier: int|string|null, hand: bool, lost: int[], network: int, unmanaged: int, reference: string,
     *     skipped: list<array{device_id: int, reason: string}>, simulate: ?string}>  $batches  per batch, in the plan's order (index 0 is batch 1);
     *     tier: hops from the core (1: cabled to a core device), CORE, or null for a batch planned by hand
     * @param  list<array{device_id: int, reason: string}>  $leftOut  devices chosen for the change that the planner could not plan, left out of it
     * @param  array<int, string>  $names  device_id => name, for the devices it mentions
     */
    public function __construct(
        public readonly string $api,
        public readonly array $batches,
        public readonly array $leftOut = [],
        public readonly array $names = [],
    ) {
    }

    /**
     * The blast radius approved for batch $number (1 is the first), null when the plan has no such batch.
     *
     * @return array{tier: int|string|null, hand: bool, lost: int[], network: int, unmanaged: int, reference: string,
     *     skipped: list<array{device_id: int, reason: string}>, simulate: ?string}|null
     */
    public function batch(int $number): ?array
    {
        return $this->batches[$number - 1] ?? null;
    }

    /**
     * As stored with the change.
     *
     * @return array{api: string, batches: array, left_out: array, names: array<string, string>}
     */
    public function toArray(): array
    {
        $names = [];
        foreach ($this->names as $deviceId => $name) {
            $names[(string) $deviceId] = $name; // a JSON object, whatever the ids
        }

        return ['api' => $this->api, 'batches' => $this->batches, 'left_out' => $this->leftOut, 'names' => $names];
    }

    /**
     * The topology part of a stored plan with $batchCount batches, checked: anything of another shape is refused.
     *
     * @throws \InvalidArgumentException with what is wrong
     */
    public static function fromArray(mixed $data, int $batchCount): self
    {
        if (! is_array($data) || ! is_string($data['api'] ?? null) || ! is_array($data['batches'] ?? null) || ! array_is_list($data['batches'])) {
            throw new \InvalidArgumentException('the topology part of the batch plan is not one');
        }
        if (count($data['batches']) !== $batchCount) {
            throw new \InvalidArgumentException('the topology part of the batch plan does not have one entry per batch');
        }
        $batches = [];
        foreach ($data['batches'] as $index => $batch) {
            $batches[] = self::batchOf($batch, $index + 1);
        }
        $leftOut = self::skippedOf($data['left_out'] ?? [], 'its devices left out');
        $names = [];
        foreach (is_array($data['names'] ?? null) ? $data['names'] : [] as $deviceId => $name) {
            if (is_string($name) && preg_match('/^\d{1,10}$/D', (string) $deviceId)) {
                $names[(int) $deviceId] = self::name($name);
            }
        }

        return new self(mb_substr($data['api'], 0, 16), $batches, $leftOut, $names);
    }

    /**
     * One batch's entry, checked.
     *
     * @return array{tier: int|string|null, hand: bool, lost: int[], network: int, unmanaged: int, reference: string,
     *     skipped: list<array{device_id: int, reason: string}>, simulate: ?string}
     */
    private static function batchOf(mixed $batch, int $number): array
    {
        if (! is_array($batch)) {
            throw new \InvalidArgumentException("the topology part of batch $number is not one");
        }
        $tier = $batch['tier'] ?? null;
        if (! ($tier === null || $tier === self::CORE || (is_int($tier) && $tier >= 0))) {
            throw new \InvalidArgumentException("the topology part of batch $number has no tier");
        }
        $hand = ($batch['hand'] ?? null) === true;
        if ($hand !== ($tier === null)) {
            throw new \InvalidArgumentException("batch $number is planned by hand only without a tier");
        }

        return BlastRadius::checked($batch, "batch $number") + ['tier' => $tier, 'hand' => $hand];
    }

    /**
     * A list of devices with a reason, checked.
     *
     * @return list<array{device_id: int, reason: string}>
     */
    public static function skippedOf(mixed $list, string $what): array
    {
        if (! is_array($list) || ! array_is_list($list)) {
            throw new \InvalidArgumentException("the topology part of the batch plan: $what are not a list");
        }
        $skipped = [];
        foreach ($list as $entry) {
            if (! is_array($entry) || ! is_int($entry['device_id'] ?? null) || $entry['device_id'] < 1 || ! is_string($entry['reason'] ?? null)) {
                throw new \InvalidArgumentException("the topology part of the batch plan: $what have an entry that is not a device and a reason");
            }
            $skipped[] = ['device_id' => $entry['device_id'], 'reason' => self::reason($entry['reason'])];
        }

        return $skipped;
    }

    /**
     * A reason of the planner, as a short word (unknown, down, isolated, unconnected, or another word of a later API).
     */
    public static function reason(string $reason): string
    {
        return preg_match('/^[a-z_]{1,24}$/D', $reason) ? $reason : 'unknown';
    }

    /**
     * A device name the topology plugin gave: one line, cut.
     */
    public static function name(string $name): string
    {
        return UntrustedText::line($name, self::NAME_MAX);
    }

    /**
     * What a reason means, as the change's pages say it.
     */
    public static function reasonText(string $reason): string
    {
        return match ($reason) {
            'down' => 'down now',
            'isolated' => 'no LLDP/CDP neighbors: no topology data',
            'unconnected' => 'no path to the core now',
            'unknown' => 'unknown to the topology plugin (or not visible)',
            default => $reason,
        };
    }

    /**
     * A batch's place, in words: "2 hops from the core", "1 hop from the core", "the core", "planned by hand".
     */
    public static function tierText(int|string|null $tier): string
    {
        return match (true) {
            $tier === null => 'planned by hand',
            $tier === self::CORE => 'the core',
            default => $tier . ' hop' . ($tier === 1 ? '' : 's') . ' from the core',
        };
    }
}
