<?php

namespace Agoodley\LibrenmsAnsible\Runs;

/**
 * The log the plugin stores for a run: whole lines as the runner sent them (ANSI codes kept for the colours), at
 * most a configured number of bytes. While the whole log fits it is kept whole and each refresh only appends the new
 * text; past that the head (the first half of the size) stays, the tail (at most the last half) follows the end of
 * the log, and the lines in between are only counted. So the end of a long log (the PLAY RECAP, the errors) always
 * survives; the runner keeps the whole log. The tail too only gets the new text appended, until it is full: then it
 * is cut to its last three quarters at once, so it is rewritten once per quarter of its size, not at every refresh.
 * Values bigger than CHUNK are written in pieces (RunRepository), so a database packet never has to hold a whole log.
 */
final class OutputBuffer
{
    /** What ends a line the plugin cut (longer than it keeps one): RecapParser knows it. */
    public const LINE_CUT = ' … (line cut by the plugin)';

    /** Most bytes of the log sent to the database in one statement. */
    public const CHUNK = 524288;

    /** Most bytes of one line: longer ones are cut. */
    private const LINE_MAX = 65536;

    /**
     * The runner's new lines added to a run's stored log: the text to append to the log (output) or to its tail
     * (output_tail), or the columns to rewrite when the log no longer fits (the head once, then the tail when full).
     *
     * @param  array{output?: ?string, output_tail?: ?string, output_truncated?: mixed, output_omitted?: mixed}  $run
     * @param  string[]  $lines
     * @return array{append: array<string, string>, values: array<string, mixed>}
     */
    public static function add(array $run, array $lines, int $maxBytes): array
    {
        if (! $lines) {
            return ['append' => [], 'values' => []];
        }
        $half = intdiv($maxBytes, 2);
        $lines = array_map(fn ($line) => self::line($line, $half), $lines);
        if (empty($run['output_truncated'])) {
            $head = (string) ($run['output'] ?? '');
            $add = ($head === '' ? '' : "\n") . implode("\n", $lines);
            if (strlen($head) + strlen($add) <= $maxBytes) {
                return ['append' => ['output' => $add], 'values' => []];
            }

            return ['append' => [], 'values' => self::cut(array_merge(self::lines($head), $lines), $half, 0)];
        }
        $tail = (string) ($run['output_tail'] ?? '');
        $add = ($tail === '' ? '' : "\n") . implode("\n", $lines);
        if (strlen($tail) + strlen($add) <= $half) {
            return ['append' => ['output_tail' => $add], 'values' => []];
        }
        $all = array_merge(self::lines($tail), $lines);
        $kept = self::lastFitting($all, self::tailKept($half));

        return ['append' => [], 'values' => ['output_tail' => implode("\n", $kept),
            'output_omitted' => (int) ($run['output_omitted'] ?? 0) + count($all) - count($kept)]];
    }

    /**
     * A run with a change and its appended text applied, as the database will hold it.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $append
     */
    public static function applied(array $run, array $values, array $append): array
    {
        $run = array_merge($run, $values);
        foreach ($append as $column => $text) {
            $run[$column] = (string) ($run[$column] ?? '') . $text;
        }

        return $run;
    }

    /**
     * How much of its size a tail keeps when it is cut: the rest is room for the next lines.
     */
    private static function tailKept(int $half): int
    {
        return intdiv($half * 3, 4);
    }

    /**
     * The columns of a log stored anew from all of its lines (a late end read from the runner).
     *
     * @param  string[]  $lines
     * @return array<string, mixed>
     */
    public static function replace(array $lines, int $maxBytes): array
    {
        $half = intdiv($maxBytes, 2);
        $lines = array_map(fn ($line) => self::line($line, $half), $lines);
        if (strlen(implode("\n", $lines)) <= $maxBytes) {
            return ['output' => implode("\n", $lines), 'output_tail' => null, 'output_truncated' => false, 'output_omitted' => 0];
        }

        return self::cut($lines, $half, 0);
    }

    /**
     * A log that no longer fits: its head and its tail, each at most $half bytes.
     *
     * @param  string[]  $lines
     * @return array<string, mixed>
     */
    private static function cut(array $lines, int $half, int $omitted): array
    {
        $head = [];
        $size = -1;
        foreach ($lines as $line) {
            if ($size + 1 + strlen($line) > $half) {
                break;
            }
            $head[] = $line;
            $size += 1 + strlen($line);
        }
        $tail = self::lastFitting(array_slice($lines, count($head)), self::tailKept($half));

        return ['output' => implode("\n", $head), 'output_tail' => implode("\n", $tail), 'output_truncated' => true,
            'output_omitted' => $omitted + count($lines) - count($head) - count($tail)];
    }

    /**
     * The last lines that fit in $bytes.
     *
     * @param  string[]  $lines
     * @return string[]
     */
    private static function lastFitting(array $lines, int $bytes): array
    {
        $kept = [];
        $size = -1;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if ($size + 1 + strlen($lines[$i]) > $bytes) {
                break;
            }
            $kept[] = $lines[$i];
            $size += 1 + strlen($lines[$i]);
        }

        return array_reverse($kept);
    }

    /**
     * A line as it is stored: valid UTF-8, at most LINE_MAX bytes (and at most half the output size).
     */
    private static function line(string $line, int $half): string
    {
        $line = mb_scrub($line, 'UTF-8');
        $max = min(self::LINE_MAX, max(64, $half));

        return strlen($line) > $max ? mb_strcut($line, 0, $max - 32, 'UTF-8') . self::LINE_CUT : $line;
    }

    /**
     * A stored text as lines.
     *
     * @return string[]
     */
    public static function lines(string $stored): array
    {
        return $stored === '' ? [] : explode("\n", $stored);
    }

    /**
     * The stored log as the pages show it: the head, a line that says how many lines were left out, and the tail.
     *
     * @return string[]
     */
    public static function display(array $run): array
    {
        $head = self::lines((string) ($run['output'] ?? ''));
        if (empty($run['output_truncated'])) {
            return $head;
        }

        return array_merge($head, [self::marker((int) ($run['output_omitted'] ?? 0))], self::lines((string) ($run['output_tail'] ?? '')));
    }

    /**
     * The lines a page that has shown the runner's lines before $after still needs, with a marker where lines were
     * left out, and the runner's line number to ask from next time.
     *
     * @return array{lines: string[], next: int}
     */
    public static function since(array $run, int $after): array
    {
        $head = self::lines((string) ($run['output'] ?? ''));
        if (empty($run['output_truncated'])) {
            return ['lines' => array_slice($head, max(0, $after)), 'next' => count($head)];
        }
        $tail = self::lines((string) ($run['output_tail'] ?? ''));
        $next = max((int) ($run['output_lines'] ?? 0), count($head) + count($tail));
        $tailStart = $next - count($tail);
        $after = max(0, min($after, $next));
        $lines = array_slice($head, $after);
        if ($after < $tailStart) {
            $lines[] = self::marker($tailStart - max($after, count($head)));
        }

        return ['lines' => array_merge($lines, array_slice($tail, max(0, $after - $tailStart))), 'next' => $next];
    }

    /**
     * The line that stands for the lines left out (yellow).
     */
    public static function marker(int $omitted): string
    {
        return "\e[0;33m… " . number_format($omitted) . ' ' . ($omitted === 1 ? 'line' : 'lines') . ' left out here (Output size setting): the whole log is in the runner …' . "\e[0m";
    }

    /**
     * A text in pieces of at most CHUNK bytes, cut between characters.
     *
     * @return string[]
     */
    public static function pieces(string $text): array
    {
        $pieces = [];
        while (strlen($text) > self::CHUNK) {
            $piece = mb_strcut($text, 0, self::CHUNK, 'UTF-8');
            $pieces[] = $piece;
            $text = substr($text, strlen($piece));
        }
        $pieces[] = $text;

        return $pieces;
    }
}
