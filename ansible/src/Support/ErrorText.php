<?php

namespace Agoodley\LibrenmsAnsible\Support;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Illuminate\Database\QueryException;

/**
 * An exception as the plugin logs it: for a database error only its code and the driver's message, never the SQL
 * and its bindings (a run's output, device data); for anything else the message, cut.
 */
final class ErrorText
{
    public static function forLog(\Throwable $e): string
    {
        if ($e instanceof QueryException) {
            $info = $e->errorInfo ?? [];

            return 'database error ' . UntrustedText::line(($info[0] ?? $e->getCode()) . (isset($info[1]) ? '/' . $info[1] : '') . ': '
                . (string) ($info[2] ?? 'see the database log'), 300);
        }
        if ($e instanceof \PDOException) {
            return 'database error ' . UntrustedText::line((string) $e->getCode(), 40);
        }

        return UntrustedText::line(get_class($e) . ': ' . $e->getMessage(), 1000);
    }
}
