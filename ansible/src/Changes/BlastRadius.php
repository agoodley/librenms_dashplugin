<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;

/**
 * The blast radius of a batch: what goes down while it runs, as the topology plugin's failure simulation says: the
 * monitored devices it cuts off the core (lost), how many of them are switches, routers or firewalls (network), the
 * unmanaged neighbors that go down with it (phones, access points: a count), whether the core is still standing
 * (reference: "largest part" when the batch takes every core device of its part down: what it cuts off is then
 * measured from the largest piece left, and a batch held to leave a core device standing grew), and the devices of
 * the batch the topology can tell nothing about (skipped: down, without LLDP/CDP neighbors, without a path to the
 * core, or unknown to it: what depends on them is unknown). The one approved with the change (TopologyPlan) is
 * compared with the one recomputed before each batch: a batch whose blast radius grew does not start without an
 * approver, nor one with a device the topology could tell about then and no longer can (unplanned(): its blast
 * radius cannot be computed) without an admin's explicit decision; its maintenance window covers its devices and the
 * monitored devices it cuts off.
 */
final class BlastRadius
{
    /** The path of the L2 map's failure simulation, relative to LibreNMS' URL, as the topology plugin gives it. */
    private const SIMULATE_PATH = '/^plugin\/topology\/l2\?fail=[A-Za-z0-9%._-]{1,4000}$/D';

    /**
     * The fields of a blast radius the plugin keeps, from the planning API's blastRadius() answer or a stored copy,
     * checked: anything of another shape is refused.
     *
     * @return array{lost: int[], network: int, unmanaged: int, reference: string, skipped: list<array{device_id: int, reason: string}>, simulate: ?string}
     *
     * @throws \InvalidArgumentException with what is wrong
     */
    public static function checked(mixed $data, string $what = 'the blast radius'): array
    {
        if (! is_array($data) || ! is_array($data['lost'] ?? null) || ! array_is_list($data['lost'])) {
            throw new \InvalidArgumentException("$what has no list of the devices it cuts off");
        }
        $lost = [];
        foreach ($data['lost'] as $deviceId) {
            if (! is_int($deviceId) || $deviceId < 1) {
                throw new \InvalidArgumentException("$what lists a device it cuts off that is not a device id");
            }
            $lost[$deviceId] = $deviceId;
        }
        foreach (['network', 'unmanaged'] as $count) {
            if (! is_int($data[$count] ?? null) || $data[$count] < 0) {
                throw new \InvalidArgumentException("$what has no count of $count devices");
            }
        }
        $simulate = $data['simulate'] ?? null;

        return [
            'lost' => array_values($lost),
            'network' => $data['network'],
            'unmanaged' => $data['unmanaged'],
            'reference' => ($data['reference'] ?? null) === 'largest part' ? 'largest part' : 'core',
            'skipped' => TopologyPlan::skippedOf($data['skipped'] ?? [], "the devices $what cannot tell about"),
            'simulate' => is_string($simulate) && preg_match(self::SIMULATE_PATH, $simulate) ? $simulate : null,
        ];
    }

    /**
     * A blast radius as the planning API answers it, with the names of the devices it mentions.
     *
     * @return array{blast: array{lost: int[], network: int, unmanaged: int, reference: string, skipped: list<array{device_id: int, reason: string}>, simulate: ?string},
     *     names: array<int, string>}
     *
     * @throws \InvalidArgumentException when the answer is not one
     */
    public static function fromApi(mixed $answer): array
    {
        return ['blast' => self::checked($answer, 'the topology plugin\'s blast radius'), 'names' => self::names($answer)];
    }

    /**
     * The names an answer of the planning API gives (device_id => name), cleaned.
     *
     * @return array<int, string>
     */
    public static function names(mixed $answer): array
    {
        $names = [];
        foreach (is_array($answer) && is_array($answer['names'] ?? null) ? $answer['names'] : [] as $deviceId => $name) {
            if (is_string($name) && preg_match('/^\d{1,10}$/D', (string) $deviceId) && (int) $deviceId > 0) {
                $names[(int) $deviceId] = TopologyPlan::name($name);
            }
        }

        return $names;
    }

    /**
     * What changed between the blast radius a batch is held to (approved with the change, or accepted by an approver
     * since) and the one recomputed now: the monitored devices it cuts off now that it did not (added: it grew), those
     * it no longer cuts off (removed: it shrank), and whether it now takes down every core device of its part while it
     * was held to leave one standing (core_lost: it grew, whatever it cuts off, as that is then measured from the
     * largest piece left rather than from the core). Unmanaged neighbors are counted, not compared: a phone plugged in
     * does not stop a change.
     *
     * @param  int[]  $held
     * @param  int[]  $now
     * @param  string  $heldReference  what the held blast radius is measured from: "core", or "largest part"
     * @param  string  $nowReference  what the one recomputed now is measured from
     * @return array{added: int[], removed: int[], core_lost: bool}
     */
    public static function compare(array $held, array $now, string $heldReference = 'core', string $nowReference = 'core'): array
    {
        $held = array_values(array_unique(array_map('intval', $held)));
        $now = array_values(array_unique(array_map('intval', $now)));

        return ['added' => array_values(array_diff($now, $held)), 'removed' => array_values(array_diff($held, $now)),
            'core_lost' => $heldReference !== 'largest part' && $nowReference === 'largest part'];
    }

    /**
     * The devices of a batch the topology plugin can no longer tell about (skipped now: down, without LLDP/CDP
     * neighbors, without a path to the core, unknown to it) that it could tell about when the change was made (not
     * skipped then): what depends on them is unknown now, so is the batch's blast radius. A device skipped then too (a
     * batch planned by hand) changes nothing: the batch is still held to what was approved for it.
     *
     * @param  list<array{device_id: int, reason: string}>  $approved  the devices skipped when the change was made
     * @param  list<array{device_id: int, reason: string}>  $now  the devices skipped now
     * @return list<array{device_id: int, reason: string}>
     */
    public static function unplanned(array $approved, array $now): array
    {
        $before = array_map('intval', array_column($approved, 'device_id'));

        return array_values(array_filter($now, fn (array $entry) => ! in_array((int) $entry['device_id'], $before, true)));
    }

    /**
     * The devices of a batch's maintenance window: the batch's devices, then the monitored devices it cuts off (each
     * once, none twice).
     *
     * @param  int[]  $batchDevices
     * @param  int[]  $lost
     * @return int[]
     */
    public static function windowDevices(array $batchDevices, array $lost): array
    {
        return array_values(array_unique(array_merge(array_map('intval', $batchDevices), array_map('intval', $lost))));
    }

    /**
     * The devices a window covers besides the batch's own: the monitored devices it cuts off, not those of the batch.
     *
     * @param  int[]  $batchDevices
     * @param  int[]  $lost
     * @return int[]
     */
    public static function cutOff(array $batchDevices, array $lost): array
    {
        return array_values(array_diff(self::windowDevices($batchDevices, $lost), array_map('intval', $batchDevices)));
    }

    /**
     * Devices in words: "lab-sw5, lab-sw6", "lab-sw1, ... and 3 more", "no other monitored device".
     *
     * @param  int[]  $deviceIds
     * @param  array<int, string>  $names  device_id => name
     */
    public static function listText(array $deviceIds, array $names, int $max = 10, string $none = 'no other monitored device'): string
    {
        if (! $deviceIds) {
            return $none;
        }
        $words = array_map(fn (int $id) => UntrustedText::line((string) (($names[$id] ?? '') ?: "device #$id"), 80), array_slice(array_values($deviceIds), 0, $max));

        return implode(', ', $words) . (count($deviceIds) > $max ? ' and ' . (count($deviceIds) - $max) . ' more' : '');
    }

    /**
     * What a blast radius takes down, in words: "lab-sw6 and 2 unmanaged neighbors", "no other monitored device".
     *
     * @param  array{lost: int[], unmanaged: int}  $blast
     * @param  array<int, string>  $names
     */
    public static function text(array $blast, array $names): string
    {
        $text = self::listText($blast['lost'], $names);
        if (($blast['unmanaged'] ?? 0) > 0) {
            $text .= ($blast['lost'] ? ' and ' : ', and ') . $blast['unmanaged'] . ' unmanaged ' . ($blast['unmanaged'] === 1 ? 'neighbor' : 'neighbors');
        }

        return $text;
    }
}
