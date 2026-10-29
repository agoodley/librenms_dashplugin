<?php

namespace Agoodley\LibrenmsAnsible\Reports;

/**
 * What the exports pack: a ZIP archive (PHP's zip extension, which LibreNMS' installation needs), CSV files a
 * spreadsheet opens safely, and file names made of safe characters.
 */
final class Archive
{
    /**
     * A ZIP archive of the files, in their order.
     *
     * @param  array<string, string>  $files  path in the archive => content
     *
     * @throws \RuntimeException without PHP's zip extension, or when the archive cannot be written
     */
    public static function zip(array $files): string
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP\'s zip extension is missing (php-zip, which LibreNMS needs): install it for the Word and ZIP exports');
        }
        $path = tempnam(sys_get_temp_dir(), 'ansible-export-');
        if ($path === false) {
            throw new \RuntimeException('no temporary file for the archive');
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('the archive cannot be written');
            }
            foreach ($files as $name => $content) {
                $zip->addFromString((string) $name, $content);
            }
            if (! $zip->close()) {
                throw new \RuntimeException('the archive cannot be written');
            }

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * A file name of the text: letters, digits, dot, dash and underscore (anything else a dash), at most $max
     * characters, never empty, never hidden.
     */
    public static function name(string $text, int $max = 60): string
    {
        $name = rtrim(substr(trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $text), '.-'), 0, max(1, $max)), '.-');

        return $name !== '' ? $name : 'unnamed';
    }

    /**
     * CSV of the rows (comma separated, quoted where needed, CRLF line ends). A cell a spreadsheet would read as a
     * formula (=, +, -, @, a tab or a carriage return first) gets an apostrophe first: device names and messages come from
     * devices.
     *
     * @param  array<int, array<int, string|int|null>>  $rows
     */
    public static function csv(array $rows): string
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
