<?php

namespace Agoodley\LibrenmsAnsible\Runs;

/**
 * The result of each target of a run, from the PLAY RECAP: unreachable, failed, changed, ok or skipped, and
 * "not run" for a target the recap does not name (the run stopped before it, or the runner did not find it).
 * Without a recap (a stopped, killed or lost run) the results come from Ansible's task result lines (TaskLines):
 * "unknown" where the evidence is partial, "not run" for a target a complete log never names. A host the runner
 * ran on that is not a target means the runner ran on more than the plugin chose: the run is flagged.
 */
final class RunResults
{
    public const NOT_RUN = 'not_run';

    /** Some of the run is known for the target, not how it ended (no PLAY RECAP), or nothing at all (the plugin lost track). */
    public const UNKNOWN = 'unknown';

    /** Results from worst to best, as the pages count them. */
    public const ORDER = ['unreachable', 'failed', self::UNKNOWN, self::NOT_RUN, 'changed', 'ok', 'skipped'];

    public const LABELS = ['unreachable' => 'Unreachable', 'failed' => 'Failed', self::UNKNOWN => 'Unknown', self::NOT_RUN => 'Not run',
        'changed' => 'Changed', 'ok' => 'OK', 'skipped' => 'Skipped'];

    /** Eventlog severity of each result. */
    public const SEVERITY = ['unreachable' => 'error', 'failed' => 'error', self::UNKNOWN => 'warning', self::NOT_RUN => 'warning',
        'changed' => 'notice', 'ok' => 'ok', 'skipped' => 'info'];

    /** Results of a target that did what the run asked. */
    public const DONE = ['ok', 'changed', 'skipped'];

    /**
     * Each target's result from the log of an ended run: from the PLAY RECAP when there is one, else from the task
     * result lines. $complete says whether the log is the whole log of the task: in a partial one (the plugin lost
     * track of the run) a target that is never named may still have run, so it is unknown rather than not run.
     *
     * @param  array<int, string>  $targets  device_id => inventory name
     * @param  string[]  $lines
     * @return array{results: array<int, array{result: string, counts: array<string, int>|null}>, unexpected: array<string, array{result: string, counts: array<string, int>|null}>, summary: array<string, int>, recap: bool}
     */
    public static function evaluate(array $targets, array $lines, bool $complete = true): array
    {
        $recap = RecapParser::parse($lines);
        if ($recap !== null) {
            $combined = self::combine($targets, $recap, RecapParser::connectionFailures($lines));
            $unexpected = [];
            foreach ($combined['unexpected'] as $host) {
                $unexpected[$host] = ['result' => RecapParser::result($recap[$host]), 'counts' => $recap[$host]];
            }

            return ['results' => $combined['results'], 'unexpected' => $unexpected, 'summary' => $combined['summary'], 'recap' => true];
        }
        $hosts = TaskLines::parse($lines);
        $results = [];
        $summary = array_fill_keys(self::ORDER, 0);
        foreach ($targets as $deviceId => $name) {
            $result = isset($hosts[$name]) ? TaskLines::result($hosts[$name]) : ($complete ? self::NOT_RUN : self::UNKNOWN);
            $results[$deviceId] = ['result' => $result, 'counts' => null];
            $summary[$result]++;
        }
        $unexpected = [];
        foreach (array_diff(array_keys($hosts), array_values($targets)) as $host) {
            $unexpected[(string) $host] = ['result' => TaskLines::result($hosts[$host]), 'counts' => null];
        }
        ksort($unexpected);

        return ['results' => $results, 'unexpected' => $unexpected, 'summary' => array_filter($summary), 'recap' => false];
    }

    /**
     * A stopped run's results, where the step a target was in may still run on the runner (TaskLines::inProgress()):
     * unknown for those targets rather than changed, ok or not run, as nobody can tell what that step does; a failure
     * stays a failure.
     *
     * @param  array{results: array<int, array{result: string, counts: array<string, int>|null}>, summary: array<string, int>}  $evaluation  evaluate()
     * @param  array<int, string>  $targets  device_id => inventory name
     * @param  string[]  $inProgress  inventory names
     * @return array{results: array<int, array{result: string, counts: array<string, int>|null}>, summary: array<string, int>}
     */
    public static function afterStop(array $evaluation, array $targets, array $inProgress): array
    {
        $running = array_flip($inProgress);
        $summary = array_fill_keys(self::ORDER, 0);
        foreach ($evaluation['results'] as $deviceId => $result) {
            if (isset($running[$targets[$deviceId] ?? '']) && ! in_array($result['result'], ['failed', 'unreachable'], true)) {
                $result = $evaluation['results'][$deviceId] = ['result' => self::UNKNOWN, 'counts' => $result['counts']];
            }
            $summary[$result['result']]++;
        }
        $evaluation['summary'] = array_filter($summary);

        return $evaluation;
    }

    /**
     * The run's status when the runner ended its task: success only when every target did what it was asked
     * (in the PLAY RECAP, without a failure); a task the runner reports successful while targets did not run or
     * failed is incomplete.
     *
     * @param  array<int, array{result: string, counts: array<string, int>|null}>  $results
     */
    public static function status(string $taskStatus, array $results): string
    {
        if ($taskStatus !== RunStatus::SUCCESS) {
            return $taskStatus;
        }
        foreach ($results as $result) {
            if (! in_array($result['result'], self::DONE, true)) {
                return RunStatus::INCOMPLETE;
            }
        }

        return RunStatus::SUCCESS;
    }

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
            'changed', self::UNKNOWN => 'warning',
            'failed', 'unreachable' => 'danger',
            default => 'default',
        };
    }
}
