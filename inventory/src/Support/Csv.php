<?php

namespace Agoodley\LibrenmsInventory\Support;

/**
 * CSV of rows (comma separated, quoted where needed, CRLF line ends). A cell a spreadsheet would read as a formula
 * (=, +, -, @, a tab or a carriage return first) gets an apostrophe first: device names, models, serial numbers and port
 * descriptions come from the devices.
 */
final class Csv
{
    /**
     * @param  array<int, array<int, string|int|float|bool|null>>  $rows  a yes or no is written as yes or no
     */
    public static function of(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $cell = is_bool($cell) ? ($cell ? 'yes' : 'no') : (string) $cell;
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
