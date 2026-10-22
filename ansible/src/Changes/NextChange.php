<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Runs\TargetSelection;

/**
 * A new change made from an earlier one, never a resume: the same template, answers (not the secret ones), reference
 * and first batch, on the devices the earlier change left to do: after a failure or a stop, the targets it did not
 * apply (REMAINING); after a failed dry run, the targets its dry run did not fail on (PASSED). Devices that are busy
 * now (a run, or the hold after a stop) are left out and named, as the new change could not take them yet. The new
 * change is dry-run and approved again.
 */
final class NextChange
{
    public const REMAINING = 'remaining';

    public const PASSED = 'passed';

    /** Results of a target its change did not apply (or may not have). */
    private const NOT_APPLIED = [null, 'not_run', 'failed', 'unreachable', 'unknown'];

    /** Dry-run results of a target that did what was asked. */
    private const DRY_PASSED = ['changed', 'ok', 'skipped'];

    /**
     * The targets a new change takes, in the earlier change's order (batch, then position).
     *
     * @param  array<int, array>  $targets  ChangeStore::targets() of the earlier change
     * @return int[]
     */
    public static function devices(array $targets, string $kind): array
    {
        uasort($targets, fn ($a, $b) => [(int) $a['batch'], (int) ($a['position'] ?? 0)] <=> [(int) $b['batch'], (int) ($b['position'] ?? 0)]);
        $devices = [];
        foreach ($targets as $deviceId => $target) {
            if ($kind === self::PASSED ? in_array($target['dry_result'] ?? null, self::DRY_PASSED, true) : in_array($target['result'] ?? null, self::NOT_APPLIED, true)) {
                $devices[] = (int) $deviceId;
            }
        }

        return $devices;
    }

    /**
     * The targets the earlier change's dry run failed on (failed, unreachable, unknown, not run).
     *
     * @param  array<int, array>  $targets
     * @return int[]
     */
    public static function dryRunFailures(array $targets): array
    {
        return array_values(array_map('intval', array_keys(array_filter($targets, fn ($t) => ($t['dry_result'] ?? null) !== null
            && ! in_array($t['dry_result'], self::DRY_PASSED, true)))));
    }

    /**
     * What the new change page fills in: the devices (busy ones left out, with why), the first batch among them, the
     * answers that are not secret, the reference, and what it is in words.
     *
     * @param  array<int, array>  $targets  ChangeStore::targets() of the earlier change
     * @param  array<int, array{run: int, held_until: ?string}>  $busy  RunRepository::busy()
     * @return array{from: int, kind: string, devices: int[], canary: int[], left_out: string[], vars: array<string, string>, reference: string, text: string}
     */
    public static function prefill(array $change, array $targets, string $kind, array $busy): array
    {
        $devices = [];
        $leftOut = [];
        foreach (self::devices($targets, $kind) as $deviceId) {
            if (isset($busy[$deviceId])) {
                $leftOut[] = self::name($targets[$deviceId]) . ': ' . TargetSelection::busyRefusal($busy[$deviceId])['text'];
            } else {
                $devices[] = $deviceId;
            }
        }
        $first = array_map('intval', (array) (((array) ($change['plan'] ?? []))['batches'][0] ?? []));
        $vars = [];
        foreach ((array) ($change['variables'] ?? []) as $variable) {
            if (is_array($variable) && empty($variable['secret']) && isset($variable['name']) && is_scalar($variable['value'] ?? null)) {
                $vars[(string) $variable['name']] = (string) $variable['value'];
            }
        }
        $id = (int) $change['id'];
        $text = $kind === self::PASSED
            ? "change #$id without the devices its dry run failed on (" . UntrustedText::line(implode(', ', array_map(fn ($d) => self::name($targets[$d]),
                self::dryRunFailures($targets))), 300) . ')'
            : "the remaining devices of change #$id";

        return ['from' => $id, 'kind' => $kind, 'devices' => $devices, 'canary' => array_values(array_intersect($first, $devices)), 'left_out' => $leftOut,
            'vars' => $vars, 'reference' => (string) ($change['reference'] ?? ''), 'text' => $text];
    }

    /**
     * A target's name to show.
     */
    public static function name(array $target): string
    {
        return (string) (($target['display'] ?? '') ?: (($target['hostname'] ?? '') ?: ($target['inventory_name'] ?? '')));
    }
}
