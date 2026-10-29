<?php

namespace Agoodley\LibrenmsCompliance\Support;

/**
 * An exception as the plugin logs it and says it: for a database error only its code and the driver's message, never
 * the SQL and its values; for anything else its message, on one line, cut.
 */
final class ErrorText
{
    public static function forLog(\Throwable $e): string
    {
        if (class_exists(\Illuminate\Database\QueryException::class) && $e instanceof \Illuminate\Database\QueryException) {
            $info = $e->errorInfo ?? [];

            return self::line('database error ' . ($info[0] ?? $e->getCode()) . (isset($info[1]) ? '/' . $info[1] : '') . ': '
                . (string) ($info[2] ?? 'see the database log'), 300);
        }
        if ($e instanceof \PDOException) {
            return self::line('database error ' . $e->getCode(), 60);
        }

        return self::line(get_class($e) . ': ' . $e->getMessage(), 1000);
    }

    /**
     * A text on one line (control characters as spaces), at most $max characters.
     */
    public static function line(string $text, int $max = 300): string
    {
        $text = trim((string) preg_replace('~[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\s]+~u', ' ', mb_scrub($text, 'UTF-8')));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
