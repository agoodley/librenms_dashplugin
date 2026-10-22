<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * What the change lifecycle needs from the plugin's services (Services\ChangeActions; a fake in the tests): launching
 * the runs of a change through phase 1's launcher and run lifecycle, the template's snapshot from the runner, a run's
 * whole log, stopping a run, and the checks of users and of other changes.
 */
interface ChangeRunner
{
    /**
     * Launch the dry run (kind "dry_run": all targets, --check --diff) or a batch (kind "batch") of a change, checked like
     * every launch (the token, the template as the runner has it now, the targets, the answers) and by $verify with the
     * template's snapshot parts (TemplateSnapshot) right before the run is recorded. Each run of a change has a one-time
     * token of its own (change, kind, number), so it is launched once at most. The run's id and the snapshot it was
     * checked with, or why nothing was launched.
     *
     * @param  int[]  $deviceIds
     * @param  array{user_id: int, username: string}  $user  who launches it
     * @param  array<string, string>  $secrets  the secret answers given now (never stored)
     * @param  \Closure(array): ?string  $verify  why the run may not be launched with this snapshot, null when it may
     * @return array{run_id: ?int, errors: string[], parts: ?array}
     */
    public function launch(array $change, string $kind, int $number, array $deviceIds, array $user, #[\SensitiveParameter] array $secrets, \Closure $verify): array;

    /**
     * The run of a change's dry run or batch recorded by an earlier launch (its one-time token), null when there is none.
     */
    public function findRun(int $changeId, string $kind, int $number): ?int;

    /**
     * The template's snapshot parts as the runner has it now, or why it cannot be read.
     *
     * @return array{parts: ?array, error: ?string}
     */
    public function snapshot(array $change): array;

    /**
     * The whole log of an ended run: the stored one, or the runner's when the stored one was cut; null when the runner
     * cannot be asked now.
     *
     * @return string[]|null
     */
    public function wholeLog(int $runId): ?array;

    /**
     * Ask the runner to stop a run (phase 1's stop); null when it was asked, else why not.
     */
    public function stopRun(int $runId, string $username): ?string;

    /**
     * Why a user may not (or no longer) do what a permission allows (run: launch and stop, approve: approve), e.g. no
     * longer an admin or no longer holding the permission; null when they may.
     */
    public function userProblem(int $userId, string $permission): ?string;

    /**
     * The devices among $deviceIds that a run may have changed since $since, not counting the change's own runs: every
     * run but a dry run proven to be one (or not ended yet): device_id => run id.
     *
     * @param  int[]  $deviceIds
     * @return array<int, int>
     */
    public function changedSince(array $deviceIds, int $since, int $changeId): array;

    /**
     * Record on a dry run whether it is proven to be one: a dry run that is not counts as a run that may have changed
     * its devices (changedSince()).
     */
    public function recordProof(int $runId, bool $verified): void;
}
