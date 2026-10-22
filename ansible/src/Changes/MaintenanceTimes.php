<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * When a batch's maintenance window ends. It opens before the batch, for the expected time of a batch (Expected batch
 * time setting, at least five minutes); while the batch runs, the plugin moves the end to that time from then once less
 * than a quarter of it (at least two minutes, the sync command's pace with room to spare) is left, so a window is
 * rewritten every three quarters of that time at most; once the batch ended, the window ends a settle time after it,
 * or after the hold of a stopped batch (the step in progress at a stop runs on). A window of a batch that never ran
 * ends at once.
 */
final class MaintenanceTimes
{
    /** Least seconds a window lasts. */
    private const LEAST = 300;

    /** Least seconds left before the plugin extends a window. */
    private const MARGIN = 120;

    /**
     * The end of a window opened at $now.
     */
    public static function opening(int $now, int $expectedSeconds): int
    {
        return $now + self::length($expectedSeconds);
    }

    /**
     * The new end of the window of a running batch, null when it is far enough away.
     */
    public static function extension(int $end, int $now, int $expectedSeconds): ?int
    {
        $length = self::length($expectedSeconds);

        return $end - $now < max(self::MARGIN, intdiv($length, 4)) ? $now + $length : null;
    }

    /**
     * How long a window lasts from its opening or its extension.
     */
    private static function length(int $expectedSeconds): int
    {
        return max(self::LEAST, $expectedSeconds);
    }

    /**
     * The end of the window of a batch that ended at $endedAt (and holds its devices until $heldUntil after a stop).
     */
    public static function closing(int $endedAt, ?int $heldUntil, int $settleSeconds): int
    {
        return max($endedAt, $heldUntil ?? 0) + max(0, $settleSeconds);
    }
}
