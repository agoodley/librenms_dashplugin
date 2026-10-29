<?php

namespace Agoodley\LibrenmsCompliance\Rules;

/**
 * A regular expression of a rule of the admin's, as the topology plugin reads its syslog patterns: a bare pattern
 * (^ntp server 10\.0\.0\.1$) is case-insensitive, one written with delimiters and flags (/^ntp server/i, #...#, ~...~)
 * is used as it is. A configuration is checked line by line, against the line without its indentation.
 */
final class Pattern
{
    /**
     * The regular expression of a pattern, null when it does not compile.
     */
    public static function compile(string $pattern): ?string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return null;
        }
        $regex = preg_match('{^([/#~]).*\1[a-zA-Z]*$}s', $pattern) === 1
            ? $pattern
            : '~' . preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)~/', '$1\\\\~', $pattern) . '~i'; // escape the delimiter unless it is escaped

        return @preg_match($regex, '') === false ? null : $regex;
    }

    /**
     * Does the line match? Throws when the expression fails on it (the backtracking limit, an invalid UTF-8 line with
     * the u flag): a rule whose pattern fails cannot say whether the line is there.
     *
     * @throws PatternFailed
     */
    public static function matches(string $regex, string $line): bool
    {
        $result = @preg_match($regex, $line);
        if ($result === false) {
            throw new PatternFailed($regex, preg_last_error_msg());
        }

        return $result === 1;
    }
}
