<?php

namespace Agoodley\LibrenmsCompliance\Support;

/**
 * The plugin's errors in LibreNMS' log (logs/librenms.log); nowhere outside Laravel (the unit tests).
 */
final class Log
{
    public static function error(string $message): void
    {
        if (function_exists('logger')) {
            try {
                logger()->error('compliance plugin: ' . $message);
            } catch (\Throwable) {
                // no logger bound
            }
        }
    }
}
