<?php

namespace Agoodley\LibrenmsTopology\History;

/**
 * What earlier runs of the scheduled jobs recorded. The latest finished run carries the list of
 * fact kinds (snapshot) or analyses (checks) recorded so far, so a kind or an analysis is only
 * "initial" (recorded without reporting it as news) the first time it was recorded successfully,
 * whatever was pruned since and whatever runs failed.
 */
class Runs
{
    /**
     * Is $item (a fact kind or an analysis) recorded for the first time?
     *
     * @param  array|null  $stats  stats of the latest finished run of the job
     */
    public static function initial(?array $stats, string $item): bool
    {
        return ! in_array($item, self::recorded($stats), true);
    }

    /**
     * The list to store with this run: what earlier runs recorded plus what this run recorded.
     *
     * @param  string[]  $done  kinds or analyses this run recorded successfully
     * @return string[]
     */
    public static function record(?array $stats, array $done): array
    {
        $recorded = array_values(array_unique(array_merge(self::recorded($stats), $done)));
        sort($recorded);

        return $recorded;
    }

    /**
     * @return string[]
     */
    private static function recorded(?array $stats): array
    {
        return is_array($stats['recorded'] ?? null) ? array_map('strval', $stats['recorded']) : [];
    }
}
