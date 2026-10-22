<?php

namespace Agoodley\LibrenmsAnsible\Support;

/**
 * Times of the runs as the plugin keeps them: UTC in its tables ('Y-m-d H:i:s'), whatever the PHP time zone of the
 * process that writes or reads them, so the deadlines of the run lifecycle (Stop after, the hold after a stop, the
 * watch of a lost run...) mean the same to the web server and to the sync command even when their time zones differ.
 * The pages show them as LibreNMS shows its own times: in the logged-in user's time zone (LibreNMS' session
 * preference, which its pages set from the browser), else in LibreNMS' (PHP's). A time in a text the plugin stores (a
 * timeline or eventlog entry, an error) is in UTC with its zone, whoever writes it (a user's request or the sync
 * command), and the pages show such a time in the reader's zone (localize()).
 */
final class Clock
{
    /** @var (\Closure(): mixed)|null the logged-in user's time zone (set by the service provider) */
    private static ?\Closure $userZone = null;

    /**
     * Where the pages find the logged-in user's time zone (null: they use PHP's).
     *
     * @param  (\Closure(): mixed)|null  $resolver
     */
    public static function useUserZone(?\Closure $resolver): void
    {
        self::$userZone = $resolver;
    }

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
     * A stored time as the pages show it (in the user's time zone); null for none. A deadline gets its zone: 'Y-m-d H:i T'.
     */
    public static function local(?string $stored, string $format = 'Y-m-d H:i:s'): ?string
    {
        $time = self::unix($stored);

        return $time === null ? null : self::show($time, $format);
    }

    /**
     * A Unix time as the pages show it (in the user's time zone).
     */
    public static function show(int $time, string $format = 'Y-m-d H:i:s'): string
    {
        return (new \DateTimeImmutable('@' . $time))->setTimezone(self::zone())->format($format);
    }

    /**
     * A time in a text the plugin stores: UTC, with its zone ("2026-10-22 10:00 UTC").
     */
    public static function text(int $time, string $format = 'Y-m-d H:i'): string
    {
        return gmdate($format, $time) . ' UTC';
    }

    /**
     * A stored text as a page shows it: each time in UTC with its zone (text()) in the user's time zone instead.
     */
    public static function localize(?string $text): string
    {
        return (string) preg_replace_callback('/\b(\d{4}-\d\d-\d\d \d\d:\d\d)(:\d\d)? UTC\b/', function (array $m): string {
            $time = strtotime($m[1] . ($m[2] ?? '') . ' UTC');

            return $time === false ? $m[0] : self::show($time, 'Y-m-d H:i' . (($m[2] ?? '') !== '' ? ':s' : '') . ' T');
        }, (string) $text);
    }

    /**
     * The time zone the pages show times in: the user's when LibreNMS knows it, else PHP's.
     */
    public static function zone(): \DateTimeZone
    {
        try {
            $zone = self::$userZone !== null ? (self::$userZone)() : null;
            if (is_string($zone) && $zone !== '') {
                return new \DateTimeZone($zone);
            }
        } catch (\Throwable) {
            // no session (the sync command), or not a time zone
        }

        return new \DateTimeZone(date_default_timezone_get());
    }
}
