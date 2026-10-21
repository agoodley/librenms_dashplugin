<?php

namespace Agoodley\LibrenmsTopology\History;

/**
 * When the history snapshot runs: the *History interval* setting as a cron expression.
 */
class Interval
{
    /**
     * Cron expression for an interval in minutes: a divisor of an hour below one hour, else whole
     * hours (a divisor of a day) at 7 minutes past, away from the pollers' full hours.
     */
    public static function cron(int $minutes): string
    {
        if ($minutes < 60) {
            $step = 5;
            foreach ([5, 10, 15, 20, 30] as $divisor) {
                if ($divisor <= $minutes) {
                    $step = $divisor;
                }
            }

            return "*/$step * * * *";
        }

        $hours = 1;
        foreach ([1, 2, 3, 4, 6, 8, 12, 24] as $divisor) {
            if ($divisor * 60 <= $minutes) {
                $hours = $divisor;
            }
        }

        return match ($hours) {
            24 => '7 3 * * *',
            1 => '7 * * * *',
            default => "7 */$hours * * *",
        };
    }
}
