<?php

namespace Agoodley\LibrenmsAnsible\Runs;

/**
 * The result of each target of a run, from the PLAY RECAP: unreachable, failed, changed, ok or skipped, and
 * "not run" for a target the recap does not name (the run stopped before it, or the runner did not find it).
 * A host in the recap that is not a target means the runner ran on more than the plugin chose: the run is
 * flagged.
 */
final class RunResults
{
    public const NOT_RUN = 'not_run';

    /** Results from worst to best, as the pages count them. */
    public const ORDER = ['unreachable', 'failed', self::NOT_RUN, 'changed', 'ok', 'skipped'];

    public const LABELS = ['unreachable' => 'Unreachable', 'failed' => 'Failed', self::NOT_RUN => 'Not run', 'changed' => 'Changed',
        'ok' => 'OK', 'skipped' => 'Skipped'];

    /** Eventlog severity of each result. */
    public const SEVERITY = ['unreachable' => 'error', 'failed' => 'error', self::NOT_RUN => 'warning', 'changed' => 'notice',
        'ok' => 'ok', 'skipped' => 'info'];

    /**
     * Each target's result. A target that failed without any task succeeding, on its connection
     * (RecapParser::connectionFailures()), counts as unreachable.
     *
     * @param  array<int, string>  $targets  device_id => inventory name
     * @param  array<string, array<string, int>>|null  $recap  RecapParser::parse()
     * @param  array<string, true>  $connectionFailures  RecapParser::connectionFailures()
     * @return array{results: array<int, array{result: string, counts: array<string, int>|null}>, unexpected: string[], summary: array<string, int>}
     */
    public static function combine(array $targets, ?array $recap, array $connectionFailures = []): array
    {
        $recap ??= [];
        $results = [];
        $summary = array_fill_keys(self::ORDER, 0);
        foreach ($targets as $deviceId => $name) {
            $counts = $recap[$name] ?? null;
            $result = $counts === null ? self::NOT_RUN : RecapParser::result($counts);
            if ($result === 'failed' && isset($connectionFailures[$name]) && $counts['ok'] === 0 && $counts['changed'] === 0) {
                $result = 'unreachable';
            }
            $results[$deviceId] = ['result' => $result, 'counts' => $counts];
            $summary[$result]++;
        }
        $unexpected = array_values(array_diff(array_keys($recap), array_values($targets)));
        sort($unexpected);

        return ['results' => $results, 'unexpected' => $unexpected, 'summary' => array_filter($summary)];
    }

    /**
     * Bootstrap class of a result label.
     */
    public static function css(?string $result): string
    {
        return match ($result) {
            'ok' => 'success',
            'changed' => 'warning',
            'failed', 'unreachable' => 'danger',
            default => 'default',
        };
    }
}
