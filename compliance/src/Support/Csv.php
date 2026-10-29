<?php

namespace Agoodley\LibrenmsCompliance\Support;

/**
 * CSV of rows (comma separated, quoted where needed, CRLF line ends). A cell a spreadsheet would read as a formula
 * (=, +, -, @, a tab or a carriage return first) gets an apostrophe first: device names and port names come from the
 * devices.
 */
final class Csv
{
    /**
     * @param  array<int, array<int, string|int|null>>  $rows
     */
    public static function of(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $cell = (string) $cell;
                if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($cell)) {
                    $cell = "'" . $cell;
                }
                $cells[] = preg_match('/[",\r\n]/', $cell) ? '"' . str_replace('"', '""', $cell) . '"' : $cell;
            }
            $lines[] = implode(',', $cells);
        }

        return implode("\r\n", $lines) . "\r\n";
    }
}
