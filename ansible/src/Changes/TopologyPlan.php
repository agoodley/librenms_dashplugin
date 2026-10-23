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

    /** How the topology plugin chose a core device, in words (its planning API's core_modes). */
    public const CORE_MODES = [
        'configured' => 'the Core devices setting',
        'stp_preferred_roots' => 'the designated root bridges',
        'stp' => 'the spanning tree root',
        'automatic' => 'a guess: the most central switches',
    ];

    /** Most core devices kept with a plan (the core of every part of the network the admin sees). */
    private const CORE_MAX = 200;

    /** Where an admin sets the topology plugin's core. */
    public const CORE_SETTING = 'Core devices in the topology plugin\'s settings (Overview, Plugins, Plugin Admin, topology, Settings)';

    /**
     * @param  string  $api  the version of the topology plugin's planning API that planned it
     * @param  array<int, array{tier: int|string|null, hand: bool, lost: int[], network: int, unmanaged: int, reference: string,
     *     skipped: list<array{device_id: int, reason: string}>, simulate: ?string}>  $batches  per batch, in the plan's order (index 0 is batch 1);
     *     tier: hops from the core (1: cabled to a core device), CORE, or null for a batch planned by hand
     * @param  list<array{device_id: int, reason: string}>  $leftOut  devices chosen for the change that the planner could not plan, left out of it
     * @param  array<int, string>  $names  device_id => name, for the devices it mentions
     * @param  int[]  $core  the core devices the plan is measured from (of every part of the network the admin sees)
     * @param  array<int, string>  $coreModes  core device => how the topology plugin chose it (CORE_MODES)
     */
    public function __construct(
        public readonly string $api,
        public readonly array $batches,
        public readonly array $leftOut = [],
        public readonly array $names = [],
        public readonly array $core = [],
        public readonly array $coreModes = [],
    ) {
    }

    /**
     * The core the plan is measured from, in words: "lab-sw1 (the Core devices setting)"; "" when none was given.
     */
    public function coreText(int $max = 8): string
    {
        $groups = [];
        foreach ($this->core as $deviceId) {
            $groups[$this->coreModes[$deviceId] ?? ''][] = $deviceId;
        }
        $parts = [];
        foreach ($groups as $mode => $devices) {
            $parts[] = BlastRadius::listText($devices, $this->names, $max, '') . (isset(self::CORE_MODES[$mode]) ? ' (' . self::CORE_MODES[$mode] . ')' : '');
        }

        return implode('; ', $parts);
    }

    /**
     * Is (part of) the core a guess of the topology plugin, rather than set in its settings or by the spanning tree?
     */
    public function coreGuessed(): bool
    {
        return in_array('automatic', $this->coreModes, true);
    }

    /**
     * Does the plan start with the core (the planner put the first batch in the core's tier)?
     */
    public function startsWithCore(): bool
    {
        return ($this->batches[0]['tier'] ?? null) === self::CORE && ! ($this->batches[0]['hand'] ?? false);
    }

    /**
     * What a plan measured from this core should make the admin check, in words ([] when nothing): a first batch of the
     * core (the batches cannot go from the edge to the core), a core the topology plugin guessed.
     *
     * @return string[]
     */
    public function coreWarnings(): array
    {
        $warnings = [];
        if ($this->startsWithCore()) {
            $warnings[] = 'The first batch is of the core the topology plugin measures from (' . $this->coreText() . '): the batches do not go from the edge to the '
                . 'core. Check the core' . ($this->coreGuessed() ? '' : ', or choose devices beyond it') . '.';
        }
        if ($this->coreGuessed()) {
            $warnings[] = 'The core is a guess of the topology plugin (the most central switches): set ' . self::CORE_SETTING . ' for the plan to be measured from yours.';
        }

        return $warnings;
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
     * What each batch cuts off while it runs, in words, for the timeline: "batch 3 (lab-sw2): lab-sw6 and 2 unmanaged
     * neighbors; ...", the first $max batches.
     *
     * @param  int[][]  $batches  the plan's batches (device ids)
     * @param  array<int, string>  $displays  device_id => the name the change shows, for a batch's devices
     */
    public function summary(array $batches, array $displays, int $max = 10): string
    {
        $parts = [];
        foreach (array_slice($batches, 0, $max) as $index => $devices) {
            $entry = $this->batches[$index] ?? null;
            $names = BlastRadius::listText(array_map('intval', $devices), $displays + $this->names, 5, '');
            $parts[] = 'batch ' . ($index + 1) . " ($names): " . ($entry === null ? 'unknown'
                : ($entry['hand'] ? 'planned by hand, unknown' : BlastRadius::text(['lost' => $entry['lost'], 'unmanaged' => $entry['unmanaged']], $this->names)));
        }

        return implode('; ', $parts) . (count($batches) > $max ? '; and ' . (count($batches) - $max) . ' more batches (on the change page)' : '');
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

        $modes = [];
        foreach ($this->coreModes as $deviceId => $mode) {
            $modes[(string) $deviceId] = $mode;
        }

        return ['api' => $this->api, 'batches' => $this->batches, 'left_out' => $this->leftOut, 'names' => $names, 'core' => $this->core, 'core_modes' => (object) $modes];
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
        [$core, $modes] = self::coreOf($data['core'] ?? [], $data['core_modes'] ?? []);

        return new self(mb_substr($data['api'], 0, 16), $batches, $leftOut, $names, $core, $modes);
    }

    /**
     * The core devices and how each was chosen, checked (a plan of an earlier version has none).
     *
     * @return array{0: int[], 1: array<int, string>}
     *
     * @throws \InvalidArgumentException
     */
    public static function coreOf(mixed $core, mixed $modes): array
    {
        if (is_object($modes)) {
            $modes = (array) $modes;
        }
        if (! is_array($core) || ! array_is_list($core) || ! is_array($modes)) {
            throw new \InvalidArgumentException('the core of the topology part of the batch plan is not one');
        }
        $ids = [];
        foreach (array_slice($core, 0, self::CORE_MAX) as $deviceId) {
            if (! is_int($deviceId) || $deviceId < 1) {
                throw new \InvalidArgumentException('the core of the topology part of the batch plan has a device that is not one');
            }
            $ids[] = $deviceId;
        }
        $ids = array_values(array_unique($ids));
        $checked = [];
        foreach ($modes as $deviceId => $mode) {
            if (preg_match('/^\d{1,10}$/D', (string) $deviceId) && in_array((int) $deviceId, $ids, true) && is_string($mode) && isset(self::CORE_MODES[$mode])) {
                $checked[(int) $deviceId] = $mode;
            }
        }

        return [$ids, $checked];
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
