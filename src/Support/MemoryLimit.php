<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * PHP memory limit for work on a whole network: LibreNMS' php_memory_limit (MB) when it is set,
 * else a default, unless PHP already allows more. LibreNMS applies php_memory_limit in its own
 * scripts only, not to web requests or artisan commands of plugins.
 */
class MemoryLimit
{
    public static function raise(int $defaultMb): void
    {
        $configured = null;
        try {
            $configured = class_exists(\App\Facades\LibrenmsConfig::class) ? \App\Facades\LibrenmsConfig::get('php_memory_limit') : null;
        } catch (\Throwable) {
            // older LibreNMS: the default below
        }
        $wanted = (is_numeric($configured) && $configured > 0 ? (int) $configured : $defaultMb) * 1048576;
        $current = self::bytes((string) ini_get('memory_limit'));
        if ($current !== -1 && $current < $wanted) {
            ini_set('memory_limit', (string) $wanted);
        }
    }

    /**
     * A php.ini size ("128M", "1G", "-1") in bytes, -1 for no limit.
     */
    public static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1073741824,
            'M' => $number * 1048576,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
