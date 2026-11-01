<?php

namespace Agoodley\LibrenmsInventory\Support;

/**
 * Times as the plugin keeps them, UTC in its tables ('Y-m-d H:i:s'), and as its pages show them: in the logged-in
 * user's time zone (LibreNMS' session preference), else in LibreNMS' (PHP's).
 */
final class Clock
{
    /** @var (\Closure(): mixed)|null the logged-in user's time zone (set by the service provider) */
    private static ?\Closure $userZone = null;

    /**
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
     * A stored time as the pages show it; null for none.
     */
    public static function local(?string $stored, string $format = 'Y-m-d H:i'): ?string
    {
        $time = self::unix($stored);

        return $time === null ? null : self::show($time, $format);
    }

    /**
     * A Unix time as the pages show it.
     */
    public static function show(int $time, string $format = 'Y-m-d H:i'): string
    {
        return (new \DateTimeImmutable('@' . $time))->setTimezone(self::zone())->format($format);
    }

    /**
     * "3 min ago", "2 h ago", "4 d ago".
     */
    public static function ago(?string $stored, ?int $now = null): ?string
    {
        $time = self::unix($stored);
        if ($time === null) {
            return null;
        }
        $seconds = max(0, ($now ?? time()) - $time);

        return match (true) {
            $seconds < 90 => 'just now',
            $seconds < 5400 => round($seconds / 60) . ' min ago',
            $seconds < 129600 => round($seconds / 3600) . ' h ago',
            default => round($seconds / 86400) . ' d ago',
        };
    }

    /**
     * The time zone the pages show times in.
     */
    public static function zone(): \DateTimeZone
    {
        try {
            $zone = self::$userZone !== null ? (self::$userZone)() : null;
            if (is_string($zone) && $zone !== '') {
                return new \DateTimeZone($zone);
            }
        } catch (\Throwable) {
            // no session (the scheduled check), or not a time zone
        }

        return new \DateTimeZone(date_default_timezone_get());
    }
}
