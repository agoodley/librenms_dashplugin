<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * When a batch's maintenance window ends. It opens before the batch, until an expected end (Expected batch time
 * setting); while the batch runs the plugin moves the end on whenever less than a quarter of that time (at least five
 * minutes) is left; once the batch ended, the window ends a settle time after it, or after the hold of a stopped batch
 * (the step in progress at a stop runs on). A window of a batch that never ran ends at once.
 */
final class MaintenanceTimes
{
    /** Least seconds left before the plugin extends a window. */
    private const MARGIN = 300;

    /**
     * The end of a window opened at $now.
     */
    public static function opening(int $now, int $expectedSeconds): int
    {
        return $now + max(self::MARGIN, $expectedSeconds);
    }

    /**
     * The new end of the window of a running batch, null when it is far enough away.
     */
    public static function extension(int $end, int $now, int $expectedSeconds): ?int
    {
        $margin = max(self::MARGIN, intdiv($expectedSeconds, 4));

        return $end - $now < $margin ? $now + max(self::MARGIN, $expectedSeconds) : null;
    }

    /**
     * The end of the window of a batch that ended at $endedAt (and holds its devices until $heldUntil after a stop).
     */
    public static function closing(int $endedAt, ?int $heldUntil, int $settleSeconds): int
    {
        return max($endedAt, $heldUntil ?? 0) + max(0, $settleSeconds);
    }
}
