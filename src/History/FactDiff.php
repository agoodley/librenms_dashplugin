<?php

namespace Agoodley\LibrenmsTopology\History;

/**
 * What one snapshot writes: facts that appeared, facts that are gone and facts whose details
 * changed. Facts that did not change cause no write at all.
 */
class FactDiff
{
    /**
     * @param  array<int, array{id: int, hash: string, data_hash: string}>  $open  facts present at the previous snapshot
     *                                                                                 (removed_at null), oldest first
     * @param  array<string, array>  $current  hash => fact (see FactCollector)
     * @param  bool  $replaceChanged  a fact whose data changed starts a new period instead of being updated (spanning
     *                                tree facts, whose VLANs are in the data: the Changes page compares the periods)
     * @param  string[]  $leftOut  hashes of facts that are there but no longer recorded (links to phones): dropped, not
     *                             closed, so no change is reported
     * @return array{insert: array<string, array>, remove: int[], update: array<int, array>, drop: int[]} facts to insert,
     *     ids to close, id => fact with new data, ids to delete
     */
    public static function diff(array $open, array $current, bool $replaceChanged = false, array $leftOut = []): array
    {
        $insert = $current;
        $remove = [];
        $update = [];
        $drop = [];
        $kept = [];
        $leftOut = array_flip($leftOut);
        foreach ($open as $row) {
            $hash = $row['hash'];
            if (! isset($current[$hash]) && isset($leftOut[$hash])) {
                $drop[] = (int) $row['id'];
                continue;
            }
            if (! isset($current[$hash]) || isset($kept[$hash])) {
                $remove[] = (int) $row['id']; // gone, or a duplicate open period of the same fact
                continue;
            }
            $kept[$hash] = true;
            if ($row['data_hash'] === $current[$hash]['data_hash']) {
                unset($insert[$hash]);
            } elseif ($replaceChanged) {
                $remove[] = (int) $row['id'];
            } else {
                unset($insert[$hash]);
                $update[(int) $row['id']] = $current[$hash];
            }
        }

        return ['insert' => $insert, 'remove' => $remove, 'update' => $update, 'drop' => $drop];
    }
}
