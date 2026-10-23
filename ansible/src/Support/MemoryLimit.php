<?php

namespace Agoodley\LibrenmsAnsible\Support;

/**
 * PHP's memory limit for work on the topology plugin's analyses of the whole network (a state of a batch, the fixes):
 * LibreNMS' php_memory_limit (MB) when it is set, else a default, unless PHP already allows more. LibreNMS applies
 * php_memory_limit in its own scripts only, not to web requests or artisan commands of plugins.
 */
final class MemoryLimit
{
    public static function raise(int $defaultMb): void
    {
        $configured = null;
        try {
            $configured = class_exists(\App\Facades\LibrenmsConfig::class) ? \App\Facades\LibrenmsConfig::get('php_memory_limit') : null;
        } catch (\Throwable) {
            // an older LibreNMS: the default
        }
        $wanted = (is_numeric($configured) && (int) $configured > 0 ? (int) $configured : $defaultMb) * 1048576;
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

        return (int) $value * match (strtoupper(substr($value, -1))) {
            'G' => 1073741824,
            'M' => 1048576,
            'K' => 1024,
            default => 1,
        };
    }
}
