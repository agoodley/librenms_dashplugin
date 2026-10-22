<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Runs\RunStatus;

/**
 * Does a batch that ended let the next one follow? A batch whose run was not launched, was lost, released or stopped,
 * ran on hosts that were not its targets, ran another commit than the dry run, or has at least the plan's threshold of
 * failed devices stops the change; its remaining devices are not run. Failed, unreachable, unknown and not run count
 * as failed: none of them is known to have done what was asked.
 */
final class BatchOutcome
{
    /** Results that count as a failed device. */
    public const FAILING = ['failed', 'unreachable', 'unknown', 'not_run'];

    /**
     * The devices of a batch that failed.
     *
     * @param  array<int, ?string>  $results  device_id => result
     * @return int[]
     */
    public static function failed(array $results): array
    {
        return array_keys(array_filter($results, fn ($result) => $result === null || in_array($result, self::FAILING, true)));
    }

    /**
     * Why the batch stops the change; null when the next batch may follow.
     *
     * @param  array{status: string, unexpected_hosts: ?array, commit_hash?: ?string, error?: ?string}  $run  the batch's run
     * @param  array<int, ?string>  $results  device_id => result
     * @param  string|null  $dryRunCommit  the commit the dry run ran, when the runner told
     */
    public static function stopReason(int $batch, array $run, array $results, int $stopAt, ?string $dryRunCommit): ?string
    {
        $status = (string) $run['status'];
        if ($status === RunStatus::NOT_LAUNCHED) {
            return "batch $batch was not launched" . (! empty($run['error']) ? ': ' . $run['error'] : '');
        }
        if (in_array($status, [RunStatus::LOST, RunStatus::RELEASED], true)) {
            return "the plugin lost track of batch $batch (" . strtolower(RunStatus::label($status)) . '): what it did is not known';
        }
        if ($status === RunStatus::STOPPED) {
            return "batch $batch was stopped";
        }
        if (! empty($run['unexpected_hosts'])) {
            return "the runner ran batch $batch on hosts that were not its targets (" . implode(', ', array_slice($run['unexpected_hosts'], 0, 5)) . ')';
        }
        $commit = $run['commit_hash'] ?? null;
        if ($dryRunCommit !== null && $commit !== $dryRunCommit) {
            return "batch $batch ran " . ($commit === null ? 'without telling its commit' : 'commit ' . substr((string) $commit, 0, 12))
                . ', not commit ' . substr($dryRunCommit, 0, 12) . ' of the dry run';
        }
        $failed = self::failed($results);
        if (count($failed) >= $stopAt) {
            return count($failed) . ' ' . (count($failed) === 1 ? 'device' : 'devices') . " of batch $batch failed (the plan stops at $stopAt)";
        }

        return null;
    }
}
