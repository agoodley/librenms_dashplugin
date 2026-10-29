<?php

namespace Agoodley\LibrenmsAnsible\Reports;

use Agoodley\LibrenmsAnsible\Runs\Ansi;
use Agoodley\LibrenmsAnsible\Runs\RunResults;
use Agoodley\LibrenmsAnsible\Support\Clock;

/**
 * What the reports of runs and changes say alike: times in the reader's zone, who exported it and when, the results of
 * a run, the survey answers (never a secret one: the plugin does not keep them), the name of a device.
 */
final class ReportText
{
    /** The counters of a target's result, in the pages' order. */
    public const COUNTERS = ['ok', 'changed', 'unreachable', 'failed', 'skipped'];

    /**
     * The tone of a page's label class.
     */
    public static function tone(string $css): string
    {
        return ['success' => 'success', 'warning' => 'warning', 'danger' => 'danger', 'default' => 'muted'][$css] ?? '';
    }

    /**
     * A stored time as the pages show it, '-' for none.
     */
    public static function time(?string $stored): string
    {
        return Clock::local($stored) ?? '-';
    }

    /**
     * Who exported the report, when, from which page, and the zone of its times.
     *
     * @param  array{by: string, at: int, url: string}  $export
     */
    public static function footer(array $export): string
    {
        return 'Exported by ' . $export['by'] . ' on ' . Clock::show($export['at'], 'Y-m-d H:i T') . ($export['url'] !== '' ? ' from ' . $export['url'] : '')
            . '. Times are in ' . Clock::zone()->getName() . '.';
    }

    /**
     * A run's targets per result, worst first: "1 failed, 2 ok" (a run in check mode in its own words: "2 no change");
     * one not proven to be in check mode says that it may have changed its devices.
     *
     * @param  array<string, int>  $summary
     */
    public static function results(array $summary, bool $checkMode, bool $unproven): string
    {
        if ($unproven) {
            return 'NOT PROVEN: may have changed its devices';
        }
        $parts = [];
        foreach (RunResults::ORDER as $result) {
            if (! empty($summary[$result])) {
                $parts[] = (int) $summary[$result] . ' ' . strtolower(RunResults::label($result, $checkMode));
            }
        }

        return $parts ? implode(', ', $parts) : '-';
    }

    /**
     * The tone of a run's results: its worst.
     *
     * @param  array<string, int>  $summary
     */
    public static function resultsTone(array $summary, bool $unproven): string
    {
        if ($unproven) {
            return 'danger';
        }
        foreach (RunResults::ORDER as $result) {
            if (! empty($summary[$result])) {
                return self::tone(RunResults::css($result));
            }
        }

        return '';
    }

    /**
     * The survey answers as facts: a secret one says it is not kept.
     *
     * @param  array<int, mixed>  $variables  as the run or change keeps them
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public static function answers(array $variables, string $secret): array
    {
        $rows = [];
        foreach ($variables as $variable) {
            if (! is_array($variable)) {
                continue;
            }
            $label = trim((string) ($variable['label'] ?? '') . ' (' . (string) ($variable['name'] ?? '') . ')');
            $value = $variable['value'] ?? null;
            $rows[] = ! empty($variable['secret']) ? [$label, $secret, 'muted']
                : ($value === null || is_array($value) ? [$label, '(not given)', 'muted'] : [$label, (string) $value, '']);
        }

        return $rows;
    }

    /**
     * A log's lines as text (no ANSI code nor control character), without the empty line after its last newline.
     *
     * @param  string[]  $lines
     * @return string[]
     */
    public static function log(array $lines): array
    {
        $lines = array_map(fn (string $line) => Ansi::strip($line), $lines);
        while ($lines && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * A target's name as the pages show it: its display name, else its hostname, else its inventory name.
     */
    public static function device(array $target): string
    {
        return (string) (($target['display'] ?? '') ?: (($target['hostname'] ?? '') ?: ($target['inventory_name'] ?? '')));
    }

    /**
     * A file of a device in an archive: "devices/12-core-sw1.txt".
     */
    public static function deviceFile(string $folder, int $deviceId, string $name, string $extension): string
    {
        return $folder . '/' . $deviceId . '-' . Archive::name($name, 60) . '.' . $extension;
    }
}
