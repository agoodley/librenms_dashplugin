<?php

namespace Agoodley\LibrenmsAnsible\Runs;

/**
 * The output the plugin stores for a run: whole lines as the runner sent them (ANSI codes kept for the
 * colours), at most a configured number of bytes. Lines past the cap are counted but not stored; the runner
 * keeps the whole log.
 */
final class OutputBuffer
{
    /**
     * Append lines to the stored output.
     *
     * @param  string[]  $lines
     * @return array{text: string, truncated: bool} the new stored output, and whether lines were dropped (now or before)
     */
    public static function append(string $stored, array $lines, int $maxBytes, bool $truncated = false): array
    {
        if ($truncated) {
            return ['text' => $stored, 'truncated' => true];
        }
        foreach ($lines as $line) {
            $line = mb_scrub($line, 'UTF-8');
            $add = ($stored === '' ? '' : "\n") . $line;
            if (strlen($stored) + strlen($add) > $maxBytes) {
                return ['text' => $stored, 'truncated' => true];
            }
            $stored .= $add;
        }

        return ['text' => $stored, 'truncated' => false];
    }

    /**
     * The stored output as lines.
     *
     * @return string[]
     */
    public static function lines(string $stored): array
    {
        return $stored === '' ? [] : explode("\n", $stored);
    }
}
