<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * What a change made from fixes is meant to do in each batch, from the fix it records (its "fix" column, made at its
 * creation from the topology plugin's fixes): pure.
 *
 * - Port descriptions: after a batch, the findings about its devices that the fix resolves are resolved.
 * - Spanning tree root priority: the change plans its own batches, the intended roots alone first, then their
 *   secondaries, so the root of each VLAN moves once; after every batch the root of each of the fix's VLANs is the
 *   intended one, for every switch that saw the root the fix moves away from (or the intended one), and the root
 *   findings the fix resolves are resolved. The root moving, and the ports that start or stop blocking with it, are
 *   expected.
 */
final class FixExpectation
{
    public const PORT_DESCRIPTIONS = 'port_descriptions';

    public const STP_ROOT_PRIORITY = 'stp_root_priority';

    /**
     * The expectation of a batch, null for a change that is no fix.
     *
     * @param  array|null  $fix  the change's fix record (FixAnswers::record())
     * @param  int[]  $devices  the batch's devices
     * @return array{kind: string, resolves: array<string, string>, roots?: list<array{vlans: int[], primary: int, primary_mac: string, old_macs: string[]}>}|null
     */
    public static function forBatch(?array $fix, array $devices): ?array
    {
        if (! is_array($fix) || ! in_array($fix['kind'] ?? null, [self::PORT_DESCRIPTIONS, self::STP_ROOT_PRIORITY], true)) {
            return null;
        }
        $findings = (array) ($fix['findings'] ?? []);
        if ($fix['kind'] === self::PORT_DESCRIPTIONS) {
            $resolves = [];
            foreach ($devices as $deviceId) {
                foreach ((array) ($fix['devices'][(string) $deviceId] ?? $fix['devices'][(int) $deviceId] ?? []) as $identity) {
                    $resolves[(string) $identity] = (string) ($findings[$identity]['title'] ?? $identity);
                }
            }

            return ['kind' => self::PORT_DESCRIPTIONS, 'resolves' => $resolves];
        }
        $resolves = [];
        foreach ($findings as $identity => $finding) {
            $resolves[(string) $identity] = (string) ($finding['title'] ?? $identity);
        }
        $roots = [];
        foreach ((array) ($fix['roots'] ?? []) as $root) {
            $roots[] = ['vlans' => array_values(array_map('intval', (array) ($root['vlans'] ?? []))), 'primary' => (int) ($root['primary']['device_id'] ?? 0),
                'primary_mac' => strtolower((string) ($root['primary']['mac'] ?? '')), 'old_macs' => array_values(array_map('strtolower', array_map('strval', (array) ($root['old_macs'] ?? []))))];
        }

        return ['kind' => self::STP_ROOT_PRIORITY, 'resolves' => $resolves, 'roots' => $roots];
    }
}
