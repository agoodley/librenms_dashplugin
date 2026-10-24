<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * An exception in one line, as the verification API gives it to its callers (a check that failed, facts that could not
 * be read): for a database error its code and the driver's message, never the SQL and its bindings (also when another
 * exception wrapped it: its message up to them, then the database error's words); for anything else its class and its
 * message. Terminal escape sequences are dropped, control and format characters become spaces, at most $max characters.
 * The details stay in LibreNMS' log.
 */
final class ErrorText
{
    public static function line(\Throwable $e, int $max = 200): string
    {
        $database = null;
        for ($inner = $e; $inner !== null && $database === null; $inner = $inner->getPrevious()) {
            $database = self::database($inner);
        }
        if ($database !== null && self::database($e) !== null) {
            $text = $database;
        } else {
            $message = $e->getMessage();
            foreach ([' (Connection: ', ' (SQL: ', 'SQLSTATE['] as $cut) {
                $at = strpos($message, $cut);
                if ($at !== false) {
                    $message = rtrim(substr($message, 0, $at), ' :');
                }
            }
            $text = (new \ReflectionClass($e))->getShortName() . ': ' . $message . ($database !== null ? ($message !== '' ? ': ' : '') . $database : '');
        }
        // terminal escape sequences out, then every control and format character a space
        $text = preg_replace(['/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '/[\p{Cc}\p{Cf}]+/u'], ['', ' '], mb_scrub($text, 'UTF-8')) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    /**
     * A database error in words, null for another exception.
     */
    private static function database(\Throwable $e): ?string
    {
        if (! $e instanceof \PDOException) {
            return null; // Laravel's QueryException is one, with the driver's errorInfo
        }
        $info = (array) ($e->errorInfo ?? []);
        // without the driver's errorInfo, the SQLSTATE its message starts with (never the rest: it may carry the SQL)
        $state = $info[0] ?? (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $e->getMessage(), $m) ? $m[1] : $e->getCode());

        return 'database error ' . $state . (isset($info[1]) ? '/' . $info[1] : '') . (isset($info[2]) ? ': ' . $info[2] : '');
    }
}
