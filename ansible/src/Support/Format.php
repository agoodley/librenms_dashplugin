<?php

namespace Agoodley\LibrenmsAnsible\Support;

/**
 * Small text formats of the pages.
 */
final class Format
{
    /**
     * A duration: "45 s", "3 min 05 s", "2 h 10 min", "3 d 4 h".
     */
    public static function duration(?int $seconds): string
    {
        if ($seconds === null || $seconds < 0) {
            return '-';
        }

        return match (true) {
            $seconds < 60 => "$seconds s",
            $seconds < 3600 => intdiv($seconds, 60) . ' min ' . sprintf('%02d', $seconds % 60) . ' s',
            $seconds < 86400 => intdiv($seconds, 3600) . ' h ' . sprintf('%02d', intdiv($seconds % 3600, 60)) . ' min',
            default => intdiv($seconds, 86400) . ' d ' . intdiv($seconds % 86400, 3600) . ' h',
        };
    }

    /**
     * How long a run took (or has been going), from its start (or creation) to its end (or now).
     *
     * @param  array{created_at?: ?string, started_at?: ?string, finished_at?: ?string}  $run
     */
    public static function runDuration(array $run, ?int $now = null): ?int
    {
        $start = $run['started_at'] ?? null ?: ($run['created_at'] ?? null);
        if (! $start) {
            return null;
        }
        $end = ($run['finished_at'] ?? null) ? strtotime((string) $run['finished_at']) : ($now ?? time());

        return max(0, $end - (int) strtotime((string) $start));
    }

    /**
     * "1 device", "3 devices".
     */
    public static function count(int $count, string $word, ?string $plural = null): string
    {
        return $count . ' ' . ($count === 1 ? $word : ($plural ?? $word . 's'));
    }
}
