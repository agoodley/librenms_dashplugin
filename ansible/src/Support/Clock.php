<?php

namespace Agoodley\LibrenmsAnsible\Support;

/**
 * Times of the runs as the plugin keeps them: UTC in its tables ('Y-m-d H:i:s'), whatever the PHP time zone of the
 * process that writes or reads them, so the deadlines of the run lifecycle (Stop after, the hold after a stop, the
 * watch of a lost run...) mean the same to the web server and to the sync command even when their time zones differ.
 * The pages show them in LibreNMS' time zone (PHP's).
 */
final class Clock
{
    /**
     * A Unix time as the plugin stores it.
     */
    public static function store(int $time): string
    {
        return gmdate('Y-m-d H:i:s', $time);
    }

    /**
     * A stored time as a Unix time; null for none.
     */
    public static function unix(?string $stored): ?int
    {
        if ($stored === null || trim($stored) === '') {
            return null;
        }
        $time = strtotime(trim($stored) . ' UTC');

        return $time === false ? null : $time;
    }

    /**
     * A stored time in LibreNMS' time zone, for the pages; null for none.
     */
    public static function local(?string $stored, string $format = 'Y-m-d H:i:s'): ?string
    {
        $time = self::unix($stored);

        return $time === null ? null : date($format, $time);
    }
}
