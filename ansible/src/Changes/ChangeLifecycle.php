<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Runs\EventSink;
use Agoodley\LibrenmsAnsible\Runs\RecapParser;
use Agoodley\LibrenmsAnsible\Runs\RunResults;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Runs\RunStore;
use Agoodley\LibrenmsAnsible\Support\Clock;

/**
 * The life of a change, as transitions on a ChangeStore: users start the dry run, approve or reject, apply, continue
 * and stop; the change page and the sync command call advance(), which follows the dry run and the batches (each a
 * run with phase 1's lifecycle, unchanged), the backups and the maintenance windows.
 *
 * - The dry run runs every target at once with --check --diff. When it ends, its whole log must prove it was one
 *   (DryRunProof), else it is unverified: a critical finding on the page and in the eventlog of every target, and the
 *   change can be neither approved nor applied. Its diffs are attributed per device (DiffParser); the results come from
 *   the PLAY RECAP.
 * - A verified dry run without failures can be approved (ApprovalRules) when the settings ask for it. What is approved
 *   is the change's fingerprint (Fingerprint): the template as the runner had it at the dry run, the targets, the
 *   answers, the batch plan, the dry run and its commit. A dry run counts for the Dry run valid setting and only until
 *   another change is applied to one of its devices; an approval for the Approval valid setting and while its
 *   approver may approve.
 * - The apply goes in batches (BatchPlan). Before each batch the plugin checks all of that again (the template re-read
 *   from the runner), opens a maintenance window, asks Oxidized for backups and waits for them, and launches the batch
 *   pinned to the dry run's commit. A batch that ends badly (BatchOutcome) stops the change; the remaining devices are
 *   not run. After a batch the window ends a settle time later and Oxidized is asked again. The change may wait for an
 *   admin after the first batch, for the secret answers (never kept), or a pause.
 * - Stop stops the running batch (phase 1's stop and hold) and cancels the rest. Windows the plugin opened are always
 *   closed and backups it asked for always recorded, whatever the change's end.
 *
 * Every write of the change's row is a compare-and-set on its version, and only the request whose write succeeded
 * writes the rest (batches, events); a batch's run has a one-time token, so it is launched once at most. The callers
 * (Services\ChangeFollower) hold a lock per change around advance() and the users' actions.
 */
final class ChangeLifecycle
{
    /** Seconds after a dry run ended during which an unreadable whole log is asked for again before the run counts as unverified. */
    public const LOG_GRACE = 1800;

    /** Seconds after which a launch that recorded no run is taken as not launched (the request that launched it died). */
    public const LAUNCH_GRACE = 600;

    /**
     * Least seconds a prepared batch (its window open, its backups taken) waits for an admin to enter the secret answers;
     * it waits the expected time of a batch (maintenance setting) when longer, then the change ends.
     */
    public const SECRETS_WAIT_MIN = 900;

    /**
     * Seconds a prepared batch waits for the secret answers before its timeline says so: the change page that holds them
     * (in its memory) launches it with them within seconds, and then it never waited.
     */
    public const SECRETS_ANNOUNCE = 30;

    /** @var (\Closure(): void)|null called during long work (calls to the runner and to Oxidized): the follower renews its lock */
    private ?\Closure $heartbeat = null;

    /**
     * What to call during long work (the follower renews the change's lock with it, so the lock can be short).
     */
    public function setHeartbeat(?\Closure $heartbeat): void
    {
        $this->heartbeat = $heartbeat;
    }

    private function beat(): void
    {
        if ($this->heartbeat !== null) {
            ($this->heartbeat)();
        }
    }

    public function __construct(
        private readonly ChangeStore $changes,
        private readonly RunStore $runs,
        private readonly ChangeRunner $runner,
        private readonly ConfigBackups $backups,
        private readonly MaintenanceWindows $windows,
        private readonly EventSink $events,
        private readonly ChangeSettings $settings,
    ) {
    }

    // ------------------------------------------------------------------ users' actions

    /**
     * Launch the dry run of a change (again: a new dry run voids the earlier one and its approval). Null when it was
     * launched, else why not.
     *
     * @param  array{user_id: int, username: string}  $user
     * @param  array<string, string>  $secrets  the secret answers (never stored)
     */
    public function startDryRun(int $id, array $user, #[\SensitiveParameter] array $secrets, int $now): ?string
    {
        $change = $this->changes->find($id);
        if ($change === null) {
            return 'There is no such change.';
        }
        if ($change['status'] === ChangeStatus::DRY_RUN) {
            return 'The dry run of this change is running.';
        }
        if (! in_array($change['status'], [ChangeStatus::DRAFT, ChangeStatus::AWAITING, ChangeStatus::READY], true)) {
            return 'The change is ' . strtolower(ChangeStatus::label($change['status'])) . ': a dry run is only for a change that has not been applied.';
        }
        $problem = $this->runner->userProblem((int) $user['user_id'], 'run');
        if ($problem !== null) {
            return 'You may not launch the dry run: ' . $problem . '.';
        }
        $missing = self::missingSecrets($change, $secrets, true);
        if ($missing) {
            return self::secretsText($missing, 'for the dry run');
        }
        $hadApproval = $change['approved_at'] !== null;
        $change = $this->update($change, ['status' => ChangeStatus::DRY_RUN, 'dry_runs' => (int) $change['dry_runs'] + 1, 'dry_run_id' => null,
            'dry_run_state' => 'launching', 'dry_run_at' => Clock::store($now), 'dry_run_ended_at' => null, 'dry_run_commit' => null, 'dry_run_findings' => null,
            'approved_by' => null, 'approved_user_id' => null, 'approved_at' => null, 'approval_fingerprint' => null, 'error' => null]);
        if ($change === null) {
            return 'The change changed meanwhile: reload the page.';
        }
        if ($hadApproval) {
            $this->changes->voidApprovals($id, 'a new dry run was launched', $now);
        }
        $targets = $this->changes->targets($id);
        $answers = self::answers($change);
        $this->beat();
        $result = $this->runner->launch($change, 'dry_run', (int) $change['dry_runs'], array_keys($targets), $user, $secrets,
            fn (array $snapshot, array $connections) => null);
        if ($result['run_id'] === null) {
            $error = UntrustedText::line(implode(' ', $result['errors']) ?: 'the launch was refused', 1000);
            $this->update($change, ['status' => ChangeStatus::DRAFT, 'dry_run_state' => 'not_launched', 'error' => 'The dry run was not launched: ' . $error]);
            $this->timeline($change, 'dry_run', 'The dry run was not launched: ' . $error, $user['username'], $now);

            return 'The dry run was not launched: ' . $error;
        }
        // how the runner reaches each target, as the launch saw it right before the run was recorded
        $connections = $result['connections'] ?? $this->runner->connections(array_keys($targets));
        $parts = $result['parts'] !== null ? Fingerprint::parts($result['parts'], self::names($targets), $connections, $answers, (array) $change['plan']) : null;
        $this->update($change, ['dry_run_id' => $result['run_id'], 'dry_run_state' => 'running', 'fingerprint_parts' => $parts,
            'plan_fingerprint' => $parts !== null ? Fingerprint::of($parts) : null]);
        $this->timeline($change, 'dry_run', 'Dry run launched (run #' . $result['run_id'] . ').', $user['username'], $now);
        $this->eventAll($change, ChangeText::dryRunLaunched($change, (int) $result['run_id'], $user['username']), 'info', $user['username']);
        $this->checkBackups($change, $targets, $user['username'], $now);

        return null;
    }

    /**
     * Approve a change whose verified dry run awaits approval. The template is read again from the runner: when it
     * changed since the dry run, the dry run no longer counts. Null when approved, else why not.
     *
     * @param  array{user_id: int, username: string}  $user
     * @param  bool  $holds  the user is an admin holding the approve permission
     */
    public function approve(int $id, array $user, bool $holds, string $comment, int $now): ?string
    {
        $change = $this->changes->find($id);
        if ($change === null) {
            return 'There is no such change.';
        }
        $refusal = ApprovalRules::refusal($this->settings->approval, $change, (int) $user['user_id'], $holds);
        if ($refusal !== null) {
            return $refusal;
        }
        $problem = $this->dryRunProblem($change, $now);
        if ($problem === null) {
            $template = $this->templateCheck($change);
            if (isset($template['unreadable'])) {
                return self::unreadableText($template['unreadable'], false);
            }
            $problem = $template['changed'] ?? null;
        }
        if ($problem !== null) {
            $this->voidDryRun($change, $problem, $now, $user['username']);

            return 'The dry run no longer counts (' . $problem . '): launch it again.';
        }
        $fingerprint = Fingerprint::approval((string) $change['plan_fingerprint'], (int) $change['dry_run_id'], $change['dry_run_commit']);
        $approved = $this->update($change, ['status' => ChangeStatus::READY, 'approved_by' => mb_substr($user['username'], 0, 255),
            'approved_user_id' => (int) $user['user_id'], 'approved_at' => Clock::store($now), 'approval_fingerprint' => $fingerprint]);
        if ($approved === null) {
            return 'The change changed meanwhile: reload the page.';
        }
        $comment = self::comment($comment);
        $this->changes->addApproval(['change_id' => $id, 'user_id' => (int) $user['user_id'], 'username' => $user['username'], 'decision' => 'approved',
            'comment' => $comment, 'fingerprint' => $fingerprint, 'dry_run_id' => (int) $change['dry_run_id'], 'created_at' => $now]);
        $this->timeline($change, 'approval', 'Approved' . ($comment !== '' ? ": \"$comment\"" : '.'), $user['username'], $now);
        $this->eventAll($change, ChangeText::approved($change, $user['username'], $comment), 'notice', $user['username']);

        return null;
    }

    /**
     * Reject a change awaiting approval: it ends. Null when rejected, else why not.
     *
     * @param  array{user_id: int, username: string}  $user
     */
    public function reject(int $id, array $user, bool $holds, string $comment, int $now): ?string
    {
        $change = $this->changes->find($id);
        if ($change === null) {
            return 'There is no such change.';
        }
        $refusal = ApprovalRules::refusal($this->settings->approval, $change, (int) $user['user_id'], $holds, true);
        if ($refusal !== null) {
            return $refusal;
        }
        $comment = self::comment($comment);
        if ($this->update($change, ['status' => ChangeStatus::REJECTED, 'finished_at' => Clock::store($now), 'error' => 'rejected by '
            . UntrustedText::line($user['username'], 60) . ($comment !== '' ? ": \"$comment\"" : '')]) === null) {
            return 'The change changed meanwhile: reload the page.';
        }
        $this->changes->addApproval(['change_id' => $id, 'user_id' => (int) $user['user_id'], 'username' => $user['username'], 'decision' => 'rejected',
            'comment' => $comment, 'fingerprint' => null, 'dry_run_id' => $change['dry_run_id'] !== null ? (int) $change['dry_run_id'] : null, 'created_at' => $now]);
        $this->timeline($change, 'approval', 'Rejected' . ($comment !== '' ? ": \"$comment\"" : '.'), $user['username'], $now);
        $this->eventAll($change, ChangeText::rejected($change, $user['username'], $comment), 'warning', $user['username']);

        return null;
    }

    /**
     * Apply a change that is ready (verified dry run, approved when needed): everything is checked again, then the first
     * batch starts. Null when it started, else why not.
     *
     * @param  array{user_id: int, username: string}  $user
     * @param  array<string, string>  $secrets
     */
    public function apply(int $id, array $user, #[\SensitiveParameter] array $secrets, int $now): ?string
    {
        $change = $this->changes->find($id);
        if ($change === null) {
            return 'There is no such change.';
        }
        if ($change['status'] !== ChangeStatus::READY) {
            return match ($change['status']) {
                ChangeStatus::AWAITING => 'The change needs ' . ApprovalRules::LABELS[$this->settings->approval] . ' first.',
                ChangeStatus::DRAFT, ChangeStatus::DRY_RUN => 'The change needs a verified dry run without failures first.',
                default => 'The change is ' . strtolower(ChangeStatus::label($change['status'])) . '.',
            };
        }
        $problem = $this->runner->userProblem((int) $user['user_id'], 'run');
        if ($problem !== null) {
            return 'You may not apply the change: ' . $problem . '.';
        }
        $missing = self::missingSecrets($change, $secrets);
        if ($missing) {
            return self::secretsText($missing, 'to apply the change');
        }
        $dryRun = $this->dryRunProblem($change, $now);
        if ($dryRun === null) {
            $template = $this->templateCheck($change);
            if (isset($template['unreadable'])) {
                return self::unreadableText($template['unreadable'], ApprovalRules::needed($this->settings->approval));
            }
            $dryRun = $template['changed'] ?? null;
        }
        if ($dryRun !== null) {
            $this->voidDryRun($change, $dryRun, $now, $user['username']);

            return 'The dry run no longer counts (' . $dryRun . '): launch it again.';
        }
        if ($this->approvalSwitchedOn($change, $now, $user['username'])) {
            return 'The settings ask for ' . ApprovalRules::LABELS[$this->settings->approval] . ' now: the change awaits one.';
        }
        $approval = $this->approvalProblem($change, $now);
        if ($approval !== null) {
            $this->voidApproval($change, $approval, $now, $user['username']);

            return 'The approval no longer counts (' . $approval . '): the change needs a new approval.';
        }
        // the switch to applying reserves the change's devices: checked and written under the lock of the launches, so
        // neither another change's apply nor a run takes one of them in between
        $targets = $this->changes->targets($id);
        $started = null;
        $refusal = $this->runner->exclusive(function () use ($id, $change, $user, $now, $targets, &$started): ?string {
            $reserved = array_intersect_key($this->changes->reserved($id), $targets);
            if ($reserved) {
                return self::reservedText($reserved, $targets);
            }
            $started = $this->update($change, ['status' => ChangeStatus::APPLYING, 'applied_by' => mb_substr($user['username'], 0, 255),
                'applied_user_id' => (int) $user['user_id'], 'apply_started_at' => Clock::store($now), 'current_batch' => 1, 'waiting' => null, 'resume_at' => null,
                'error' => null]);

            return $started === null ? 'The change changed meanwhile: reload the page.' : null;
        });
        if ($refusal !== null || $started === null) {
            return $refusal ?? 'The change changed meanwhile: reload the page.';
        }
        $this->timeline($started, 'apply', 'Apply started (' . self::planText($started, array_keys($targets)) . ').', $user['username'], $now);
        $this->eventAll($started, ChangeText::applyStarted($started, $user['username'], (int) $started['batch_count']), 'notice', $user['username']);
        $this->startBatch($started, 1, $user, $secrets, $now);

        return null;
    }

    /**
     * Why an apply is refused: other changes being applied reserve some of its devices.
     *
     * @param  array<int, int>  $reserved  device_id => the change that reserves it
     * @param  array<int, array>  $targets
     */
    private static function reservedText(array $reserved, array $targets): string
    {
        $byChange = [];
        foreach ($reserved as $deviceId => $changeId) {
            $byChange[$changeId][] = NextChange::name($targets[$deviceId] ?? []) ?: "#$deviceId";
        }
        $parts = [];
        foreach ($byChange as $changeId => $names) {
            $parts[] = UntrustedText::line(implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? ' and ' . (count($names) - 5) . ' more' : ''), 300)
                . " (change #$changeId)";
        }

        return 'Another change being applied reserves ' . (count($reserved) === 1 ? 'a device' : 'devices') . ' of this one until it ends: ' . implode('; ', $parts)
            . '. Apply this change once that change has ended.';
    }

    /**
     * Continue a paused change: the next batch after the first one or a pause, or the prepared batch with the secret
     * answers. Null when it continued, else why not.
     *
     * @param  array{user_id: int, username: string}  $user
     * @param  array<string, string>  $secrets
     */
    public function resume(int $id, array $user, #[\SensitiveParameter] array $secrets, int $now): ?string
    {
        $change = $this->changes->find($id);
        if ($change === null) {
            return 'There is no such change.';
        }
        if ($change['status'] !== ChangeStatus::PAUSED) {
            return 'Only a paused change can be continued.';
        }
        $problem = $this->runner->userProblem((int) $user['user_id'], 'run');
        if ($problem !== null) {
            return 'You may not continue the change: ' . $problem . '.';
        }
        $batch = (int) $change['current_batch'];
        $missing = self::missingSecrets($change, $secrets);
        if ($missing) {
            // nothing changes: a batch that waits for them keeps waiting (its window open, its backups taken)
            return $change['waiting'] === 'secrets'
                ? self::secretsText($missing, "to launch batch $batch") . ' The batch keeps waiting for them until '
                    . ($change['resume_at'] !== null ? Clock::show(self::at($change['resume_at']), 'Y-m-d H:i T') : 'its deadline') . '.'
                : self::secretsText($missing, 'to continue with batch ' . ($batch + 1));
        }
        if ($change['waiting'] === 'secrets') {
            $resumed = $this->update($change, ['status' => ChangeStatus::APPLYING, 'waiting' => null, 'resume_at' => null]);
            if ($resumed === null) {
                return 'The change changed meanwhile: reload the page.';
            }
            $this->launchBatch($resumed, $batch, $user, $secrets, $now);

            return null;
        }
        $resumed = $this->update($change, ['status' => ChangeStatus::APPLYING, 'waiting' => null, 'resume_at' => null, 'current_batch' => $batch + 1]);
        if ($resumed === null) {
            return 'The change changed meanwhile: reload the page.';
        }
        $this->timeline($resumed, 'apply', 'Continued to batch ' . ($batch + 1) . '.', $user['username'], $now);
        $this->startBatch($resumed, $batch + 1, $user, $secrets, $now);

        return null;
    }

    /**
     * Stop a change: the running batch is stopped (phase 1's stop: the devices stay held a while) and the remaining
     * batches are cancelled; a running dry run is stopped. It needs no lock, so it works while another request is busy
     * with the change (or died holding its lock): the request is recorded (compare-and-set), the runner is asked at
     * once to stop the batch's task when it is known, no batch launches once it is recorded, and the change's next step
     * ends the change (stopping()). Null when the stop was asked for, else why not.
     */
    public function stop(int $id, string $username, int $now): ?string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $change = $this->changes->find($id);
            if ($change === null) {
                return 'There is no such change.';
            }
            if ($change['status'] === ChangeStatus::DRY_RUN) {
                $runId = $change['dry_run_id'] !== null ? (int) $change['dry_run_id'] : $this->runner->findRun($id, 'dry_run', (int) $change['dry_runs']);
                if ($runId === null) {
                    return 'The dry run is still being launched: try again in a moment.';
                }
                $error = $this->runner->stopRun($runId, $username);
                if ($error === null) {
                    $this->timeline($change, 'dry_run', 'Stop of the dry run requested.', $username, $now);
                }

                return $error;
            }
            if ($change['status'] === ChangeStatus::STOPPING) {
                return 'The change is being stopped.';
            }
            if (! in_array($change['status'], [ChangeStatus::APPLYING, ChangeStatus::PAUSED], true)) {
                return 'Only a change being applied can be stopped' . (in_array($change['status'], ChangeStatus::PREPARING, true) ? ': cancel it instead.' : '.');
            }
            $stopping = $this->update($change, ['status' => ChangeStatus::STOPPING, 'stop_requested_by' => mb_substr($username, 0, 255),
                'stop_requested_at' => Clock::store($now)]);
            if ($stopping === null) {
                continue; // the change moved meanwhile (another request is at it): read it again
            }
            $this->timeline($stopping, 'stop', 'Stop requested.', $username, $now);
            $this->eventAll($stopping, ChangeText::stopRequested($stopping, $username), 'warning', $username);
            $run = $this->batchRun($stopping);
            if ($run !== null && ! RunStatus::isFinal($run['status'])) {
                $error = $this->runner->stopRun((int) $run['id'], $username);
                if ($error !== null) {
                    $this->timeline($stopping, 'stop', 'The runner could not be asked to stop batch ' . (int) $stopping['current_batch'] . ' yet ('
                        . UntrustedText::line($error, 300) . '): the plugin asks again.', null, $now);
                }
            }

            return null;
        }

        return 'The change kept changing while the stop was recorded: try again.';
    }

    /**
     * The run of the change's current batch: as recorded, or found by its one-time token (a launch whose request has not
     * recorded it on the batch yet); null when it has none.
     */
    private function batchRun(array $change): ?array
    {
        $number = (int) $change['current_batch'];
        $batch = $this->changes->batches((int) $change['id'])[$number] ?? null;
        if ($batch === null) {
            return null;
        }
        $runId = $batch['run_id'] ?? null;
        if ($runId === null && in_array($batch['status'], ['launching', 'running'], true)) {
            $runId = $this->runner->findRun((int) $change['id'], 'batch', $number);
            if ($runId !== null) {
                $this->changes->updateBatch((int) $change['id'], $number, ['status' => 'running', 'run_id' => $runId]);
            }
        }

        return $runId !== null ? $this->runs->find((int) $runId) : null;
    }

    /**
     * Cancel a change that has not been applied (draft, awaiting approval, ready). Null when cancelled, else why not.
     */
    public function cancel(int $id, string $username, int $now): ?string
    {
        $change = $this->changes->find($id);
        if ($change === null) {
            return 'There is no such change.';
        }
        if ($change['status'] === ChangeStatus::DRY_RUN) {
            return 'The dry run is running: stop it first, or wait for its end.';
        }
        if (! in_array($change['status'], [ChangeStatus::DRAFT, ChangeStatus::AWAITING, ChangeStatus::READY], true)) {
            return 'Only a change that has not been applied can be cancelled' . (in_array($change['status'], ChangeStatus::RESERVING, true) ? ': stop it instead.' : '.');
        }
        $cancelled = $this->update($change, ['status' => ChangeStatus::CANCELLED, 'finished_at' => Clock::store($now),
            'error' => 'cancelled by ' . UntrustedText::line($username, 60)]);
        if ($cancelled === null) {
            return 'The change changed meanwhile: reload the page.';
        }
        $this->changes->voidApprovals($id, 'the change was cancelled', $now);
        $this->timeline($cancelled, 'cancel', 'Cancelled.', $username, $now);
        $this->eventAll($cancelled, ChangeText::cancelled($cancelled, $username), 'info', $username);

        return null;
    }

    // ------------------------------------------------------------------ following

    /**
     * Follow a change one step: the end of its dry run, the validity of its dry run and approval, its batches, its
     * maintenance windows and backups. Returns the change as stored afterwards, null when there is none.
     */
    public function advance(int $id, int $now): ?array
    {
        $change = $this->changes->find($id);
        if ($change === null) {
            return null;
        }
        match ($change['status']) {
            ChangeStatus::DRY_RUN => $this->dryRunEnd($change, $now),
            ChangeStatus::AWAITING, ChangeStatus::READY => $this->revalidate($change, $now),
            ChangeStatus::APPLYING => $this->applying($change, $now),
            ChangeStatus::PAUSED => $this->paused($change, $now),
            ChangeStatus::STOPPING => $this->stopping($change, $now),
            default => null,
        };
        $this->followBackups($id, $now);
        $this->followWindows($id, $now);

        return $this->changes->find($id);
    }

    /**
     * Does the change still need the sync command: unfinished, or a window or backup the plugin still follows?
     */
    public function pending(int $id): bool
    {
        $change = $this->changes->find($id);

        return $change !== null && (ChangeStatus::isFinal($change['status']) ? self::pendingOf($change, $this->changes->windows($id), $this->changes->targets($id)) : true);
    }

    /**
     * pending() with the change's windows and targets at hand (the change page has them).
     *
     * @param  array<int, array>  $windows
     * @param  array<int, array>  $targets
     */
    public static function pendingOf(array $change, array $windows, array $targets): bool
    {
        if (! ChangeStatus::isFinal($change['status'])) {
            return true;
        }
        foreach ($windows as $window) {
            if ($window['status'] === 'open') {
                return true;
            }
        }
        foreach ($targets as $target) {
            if (($target['before_status'] ?? null) === BackupState::REQUESTED || ($target['after_status'] ?? null) === BackupState::REQUESTED) {
                return true;
            }
        }

        return false;
    }

    /**
     * A run that did nothing on its devices: the runner refused its launch, or it was stopped in the runner's queue
     * (the runner reports no start).
     */
    private static function neverRan(array $run): bool
    {
        return $run['status'] === RunStatus::NOT_LAUNCHED || ($run['status'] === RunStatus::STOPPED && ($run['started_at'] ?? null) === null);
    }

    /**
     * The dry run ended: prove it, take each target's result from the PLAY RECAP and its diffs from the log, and move the
     * change on (awaiting approval, ready) or back to draft.
     */
    private function dryRunEnd(array $change, int $now): void
    {
        $id = (int) $change['id'];
        if ($change['dry_run_id'] === null) {
            $runId = $this->runner->findRun($id, 'dry_run', (int) $change['dry_runs']);
            if ($runId !== null) {
                $this->update($change, ['dry_run_id' => $runId, 'dry_run_state' => 'running']);
            } elseif ($now - self::at($change['dry_run_at']) > self::LAUNCH_GRACE) {
                $this->update($change, ['status' => ChangeStatus::DRAFT, 'dry_run_state' => 'not_launched', 'error' => 'The dry run was not launched: the request '
                    . 'that launched it did not complete.']);
            }

            return;
        }
        $run = $this->runs->find((int) $change['dry_run_id']);
        if ($run === null || ! RunStatus::isFinal($run['status'])) {
            return;
        }
        if (self::neverRan($run)) {
            // nothing ran (the runner refused the launch, or it was stopped in the runner's queue): nothing to prove
            $values = array_fill_keys(array_keys($this->changes->targets($id)), ['dry_result' => 'not_run', 'dry_counts' => null]);
            $text = $run['status'] === RunStatus::NOT_LAUNCHED ? 'The dry run was not launched' . ($run['error'] ? ': ' . UntrustedText::line((string) $run['error'], 500) : '.')
                : 'The dry run was stopped before the runner started it: nothing ran.';
            if ($this->update($change, ['status' => ChangeStatus::DRAFT, 'dry_run_state' => 'not_launched', 'dry_run_ended_at' => Clock::store($now),
                'error' => $text]) !== null) {
                $this->changes->updateTargets($id, $values);
                $this->timeline($change, 'dry_run', "Dry run #{$run['id']}: " . lcfirst($text), null, $now);
            }

            return;
        }
        $lines = $this->runner->wholeLog((int) $run['id']);
        if ($lines === null && $now - self::at($run['finished_at'] ?? null) < self::LOG_GRACE) {
            return; // the runner cannot give the whole log now: asked again later
        }
        $recap = $lines !== null ? RecapParser::parse($lines) : null;
        $proof = $lines !== null ? DryRunProof::check($lines, $recap)
            : ['verified' => false, 'findings' => ['The plugin could not read the dry run\'s whole log from the runner, so nothing proves it was a dry run.']];
        $this->runner->recordProof((int) $run['id'], (bool) $proof['verified']);
        $targets = [];
        foreach ($this->runs->targets((int) $run['id']) as $deviceId => $target) {
            if (empty($target['unexpected'])) {
                $targets[(int) $deviceId] = $target;
            }
        }
        $clean = $run['status'] === RunStatus::SUCCESS && empty($run['unexpected_hosts']);
        $state = ! $proof['verified'] ? 'unverified' : ($clean ? 'verified' : 'failed');
        $next = $state === 'verified' ? (ApprovalRules::needed($this->settings->approval) ? ChangeStatus::AWAITING : ChangeStatus::READY) : ChangeStatus::DRAFT;
        $findings = $proof['findings'];
        if ($state === 'failed') {
            // the devices it did not succeed on, by name
            $bad = [];
            foreach ($this->changes->targets($id) as $deviceId => $row) {
                $result = $targets[$deviceId]['result'] ?? 'not_run';
                if (! in_array($result, RunResults::DONE, true)) {
                    $bad[] = NextChange::name($row) . ' (' . (ChangeText::DRY_RESULTS[$result] ?? $result) . ')';
                }
            }
            $findings[] = $run['status'] === RunStatus::SUCCESS && ! empty($run['unexpected_hosts'])
                ? 'The runner ran the dry run on hosts that were not its targets: ' . implode(', ', array_slice($run['unexpected_hosts'], 0, 5)) . '.'
                : 'The dry run ended ' . strtolower(RunStatus::label($run['status'])) . ($bad ? ' on ' . implode(', ', array_slice($bad, 0, 10))
                    . (count($bad) > 10 ? ' and ' . (count($bad) - 10) . ' more' : '') : '') . ': every target must do what was asked without failing.';
        }
        $ended = $this->update($change, ['status' => $next, 'dry_run_state' => $state, 'dry_run_ended_at' => Clock::store($now),
            'dry_run_commit' => $run['commit_hash'] ?? null, 'dry_run_findings' => $findings,
            'error' => $state === 'verified' ? null : ($state === 'unverified' ? 'The dry run is not proven to be one.' : 'The dry run did not succeed on every target.')]);
        if ($ended === null) {
            return;
        }
        $summary = [];
        $values = [];
        foreach ($this->changes->targets($id) as $deviceId => $row) {
            $result = $targets[$deviceId]['result'] ?? 'not_run';
            $values[$deviceId] = ['dry_result' => $result, 'dry_counts' => $targets[$deviceId]['counts'] ?? null];
            $summary[$result] = ($summary[$result] ?? 0) + 1;
        }
        $this->changes->updateTargets($id, $values);
        if ($lines !== null) {
            $this->changes->replaceDiffs($id, (int) $run['id'], self::diffRows(DiffParser::parse($lines, self::names($this->changes->targets($id)), $recap)));
        }
        $this->timeline($ended, 'dry_run', ChangeText::dryRunEnded($ended, (int) $run['id'], $state, $summary)
            . ($state !== 'verified' ? ' ' . implode(' ', $findings) : ''), null, $now);
        foreach ($values as $deviceId => $value) {
            $this->events->write($state === 'unverified' ? ChangeText::dryRunUnverified($ended, (int) $run['id'], $proof['findings'])
                : ChangeText::dryRunResult($ended, (int) $run['id'], $value['dry_result']), $deviceId,
                $state === 'unverified' ? 'error' : (in_array($value['dry_result'], ['failed', 'unreachable', 'not_run', 'unknown'], true) ? 'warning' : 'info'),
                ChangeText::reference($id), (string) $run['username']);
        }
        if ($next === ChangeStatus::AWAITING) {
            $this->timeline($ended, 'approval', 'Approval requested (' . ApprovalRules::LABELS[$this->settings->approval] . ').', null, $now);
            $this->eventAll($ended, ChangeText::approvalRequested($ended), 'info', (string) $run['username']);
        }
    }

    /**
     * A change awaiting approval or ready: its dry run must still count, and its approval too.
     */
    private function revalidate(array $change, int $now): void
    {
        $problem = $this->dryRunProblem($change, $now);
        if ($problem !== null) {
            $this->voidDryRun($change, $problem, $now);

            return;
        }
        if ($change['status'] === ChangeStatus::READY) {
            if ($this->approvalSwitchedOn($change, $now)) {
                return;
            }
            $approval = $this->approvalProblem($change, $now);
            if ($approval !== null) {
                $this->voidApproval($change, $approval, $now);
            }
        } elseif (! ApprovalRules::needed($this->settings->approval) && $this->update($change, ['status' => ChangeStatus::READY]) !== null) {
            $this->timeline($change, 'approval', 'The settings need no approval any more: the change can be applied.', null, $now);
        }
    }

    /**
     * A change being applied: the backups before its current batch, the launch, and the batch's end.
     */
    private function applying(array $change, int $now): void
    {
        $id = (int) $change['id'];
        $number = (int) $change['current_batch'];
        $batch = $this->changes->batches($id)[$number] ?? null;
        if ($batch === null) {
            $this->finish($change, ChangeStatus::FAILED, "the change has no batch $number", $now);

            return;
        }
        switch ($batch['status']) {
            case 'pending':
                $this->startBatch($change, $number, $this->starter($change, $number), null, $now);
                break;
            case 'preparing':
                $this->followBackups($id, $now);
                $this->backupsBefore($change, $number, $now);
                break;
            case 'launching':
                $runId = $this->runner->findRun($id, 'batch', $number);
                if ($runId !== null) {
                    $this->changes->updateBatch($id, $number, ['status' => 'running', 'run_id' => $runId]);
                } elseif ($now - self::at($batch['started_at'] ?? null) > self::LAUNCH_GRACE) {
                    $this->changes->updateBatch($id, $number, ['status' => 'failed', 'ended_at' => Clock::store($now), 'note' => 'not launched']);
                    $this->finish($change, ChangeStatus::FAILED, "batch $number was not launched: the request that launched it did not complete", $now);
                }
                break;
            case 'running':
                $run = $batch['run_id'] !== null ? $this->runs->find((int) $batch['run_id']) : null;
                if ($run === null) {
                    $this->finish($change, ChangeStatus::FAILED, "the run of batch $number is missing", $now);
                } elseif (RunStatus::isFinal($run['status'])) {
                    $this->batchEnd($change, $number, $run, $now);
                }
                break;
        }
    }

    /**
     * A paused change continues on its own only after a timed pause; the others wait for an admin, as long as the dry
     * run and the approval count: a wait past them ends the change (a new change takes the remaining devices).
     */
    private function paused(array $change, int $now): void
    {
        $problem = $this->dryRunProblem($change, $now) ?? $this->approvalProblem($change, $now);
        if ($problem !== null) {
            $this->finish($change, ChangeStatus::FAILED, 'while it waited before batch ' . ((int) $change['current_batch'] + ($change['waiting'] === 'secrets' ? 0 : 1))
                . ", $problem", $now);

            return;
        }
        if ($change['waiting'] === 'secrets' && $change['resume_at'] !== null && self::at($change['resume_at']) <= $now) {
            $this->finish($change, ChangeStatus::FAILED, 'while it waited before batch ' . (int) $change['current_batch']
                . ', nobody entered the secret answers by ' . Clock::text(self::at($change['resume_at'])), $now);

            return;
        }
        if ($change['waiting'] === 'secrets' && $change['resume_at'] !== null) {
            // the wait is said once it is real: the page that holds the secret answers launches the batch within seconds
            $number = (int) $change['current_batch'];
            $deadline = self::at($change['resume_at']);
            $batch = $this->changes->batches((int) $change['id'])[$number] ?? null;
            if ($batch !== null && ($batch['note'] ?? null) === null && $now >= $deadline - $this->secretsWait() + self::SECRETS_ANNOUNCE) {
                $this->changes->updateBatch((int) $change['id'], $number, ['note' => 'until ' . Clock::text($deadline) . ', then the change ends']);
                $this->timeline($change, 'apply', ChangeText::waiting($change, $number, 'secrets', $deadline), null, $now);
            }

            return;
        }
        if ($change['waiting'] !== 'timer' || $change['resume_at'] === null || self::at($change['resume_at']) > $now) {
            return;
        }
        $next = (int) $change['current_batch'] + 1;
        $resumed = $this->update($change, ['status' => ChangeStatus::APPLYING, 'waiting' => null, 'resume_at' => null, 'current_batch' => $next]);
        if ($resumed !== null) {
            $this->startBatch($resumed, $next, $this->applier($resumed), null, $now);
        }
    }

    /**
     * A change whose stop was asked for, at its next step: the batch's run is stopped (asked again while the runner did
     * not take it); a batch whose run ended (stopped, or before the stop came) is recorded as any batch end, with its
     * results, the backups after it and its window's settle time, and the change ends after it; without a batch run
     * (waiting before it, its window or backups pending) the change ends before it.
     */
    private function stopping(array $change, int $now): void
    {
        $id = (int) $change['id'];
        $number = (int) $change['current_batch'];
        $by = (string) (($change['stop_requested_by'] ?? null) ?: 'an admin');
        $batch = $this->changes->batches($id)[$number] ?? null;
        $run = $this->batchRun($change);
        if ($run === null && ($batch['status'] ?? null) === 'launching' && $now - self::at($batch['started_at'] ?? null) <= self::LAUNCH_GRACE) {
            return; // the request that launches it may still record its run
        }
        if ($run !== null && ! RunStatus::isFinal($run['status'])) {
            if ($run['status'] !== RunStatus::STOPPING) {
                $this->runner->stopRun((int) $run['id'], $by);
            }

            return;
        }
        $batch = $this->changes->batches($id)[$number] ?? $batch;
        if ($run !== null && in_array($batch['status'] ?? null, ['launching', 'running'], true)) {
            $this->batchEnd($change, $number, $run, $now, $by);

            return;
        }
        $this->finish($change, ChangeStatus::STOPPED, 'stopped by ' . UntrustedText::line($by, 60)
            . (in_array($batch['status'] ?? null, ['done', 'failed', 'stopped'], true) ? " after batch $number" : " before batch $number was launched"), $now, $by);
    }

    // ------------------------------------------------------------------ batches

    /**
     * Start batch $number: check that the dry run, the approval, the template and the user still count, open its
     * maintenance window, ask for its backups; launch it at once when no backup is awaited.
     *
     * @param  array{user_id: int, username: string}  $user  who launches it
     * @param  array<string, string>|null  $secrets  the secret answers given by $user now, null when the sync command starts it
     */
    private function startBatch(array $change, int $number, array $user, #[\SensitiveParameter] ?array $secrets, int $now): void
    {
        $id = (int) $change['id'];
        if (self::stopAsked($this->changes->find($id) ?? $change)) {
            return; // its stop ends it at its next step
        }
        // everything a launch checks, before the window opens and the backups are taken: the dry run, the approval, the users,
        // the template as the runner has it, the targets and how the runner reaches them
        $this->beat();
        $problem = $this->batchProblem($change, $user, $now) ?? $this->runner->preflight($change, 'batch', $number, $this->batchDevices($change, $number),
            $this->batchVerify($change));
        if ($problem !== null) {
            $this->changes->updateBatch($id, $number, ['status' => 'failed', 'ended_at' => Clock::store($now), 'note' => UntrustedText::line($problem, 500)]);
            $this->finish($change, ChangeStatus::FAILED, "batch $number was not started: $problem", $now);

            return;
        }
        $devices = $this->batchDevices($change, $number);
        $this->changes->updateBatch($id, $number, ['status' => 'preparing', 'started_at' => Clock::store($now), 'user_id' => (int) $user['user_id'],
            'username' => mb_substr($user['username'], 0, 255)]);
        if ($this->settings->maintenance) {
            $error = $this->openWindow($change, $number, $devices, $now);
            if ($error !== null) {
                $this->changes->updateBatch($id, $number, ['status' => 'failed', 'ended_at' => Clock::store($now), 'note' => UntrustedText::line($error, 500)]);
                $this->finish($change, ChangeStatus::FAILED, "the maintenance window of batch $number could not be opened: $error", $now);

                return;
            }
        }
        if ($this->settings->backups && $this->backups->enabled()) {
            $this->requestBackups($change, $number, $devices, 'before', $now);
            $this->followBackups($id, $now);
            // backups fresh at once: the batch launches in this request, with the secret answers given now
            $this->backupsBefore($change, $number, $now, $secrets);

            return;
        }
        $this->launchBatch($change, $number, $user, $secrets, $now);
    }

    /**
     * Who starts a batch: the admin who applied the change or continued it to this batch.
     *
     * @return array{user_id: int, username: string}
     */
    private function starter(array $change, int $number): array
    {
        $batch = $this->changes->batches((int) $change['id'])[$number] ?? null;

        return $batch !== null && ($batch['user_id'] ?? null) !== null
            ? ['user_id' => (int) $batch['user_id'], 'username' => (string) $batch['username']] : $this->applier($change);
    }

    /**
     * Launch the batch once each of its devices has a fresh backup (or the settings let it go without, with a warning);
     * refuse it, which ends the change, when a backup is missing.
     *
     * @param  array<string, string>|null  $secrets  the secret answers given in this request (an apply or a continue), null in a later one
     */
    private function backupsBefore(array $change, int $number, int $now, #[\SensitiveParameter] ?array $secrets = null): void
    {
        $id = (int) $change['id'];
        $missing = [];
        foreach ($this->changes->targets($id) as $deviceId => $target) {
            if ((int) $target['batch'] !== $number) {
                continue;
            }
            if (($target['before_status'] ?? null) === BackupState::REQUESTED) {
                return; // waiting for Oxidized
            }
            if (($target['before_status'] ?? null) !== BackupState::FRESH) {
                $missing[$deviceId] = ($target['display'] ?: $target['hostname']) . ' (' . (BackupState::LABELS[$target['before_status'] ?? ''] ?? 'no backup')
                    . ($target['before_error'] ? ': ' . $target['before_error'] : '') . ')';
            }
        }
        $change = $this->changes->find($id) ?? $change;
        if ($missing && $this->settings->refuseWithoutBackup) {
            $text = 'no fresh backup of ' . UntrustedText::line(implode(', ', array_slice($missing, 0, 10)), 600) . (count($missing) > 10 ? ', ...' : '')
                . ' (setting: refuse a batch without a fresh backup)';
            $this->changes->updateBatch($id, $number, ['status' => 'failed', 'ended_at' => Clock::store($now), 'note' => UntrustedText::line($text, 500)]);
            $this->finish($change, ChangeStatus::FAILED, "batch $number was refused: $text", $now);

            return;
        }
        if ($missing) {
            $this->timeline($change, 'backup', "Batch $number goes without a fresh backup of " . UntrustedText::line(implode(', ', $missing), 600)
                . ' (setting: continue with a warning).', null, $now);
        }
        $this->launchBatch($change, $number, $this->starter($change, $number), $secrets, $now);
    }

    /**
     * Launch a prepared batch, pinned to the dry run's commit and checked against the change's fingerprint. A template
     * with secret answers waits for an admin to enter them (the plugin never keeps them).
     *
     * @param  array{user_id: int, username: string}  $user
     * @param  array<string, string>|null  $secrets
     */
    private function launchBatch(array $change, int $number, array $user, #[\SensitiveParameter] ?array $secrets, int $now): void
    {
        $id = (int) $change['id'];
        // right before the launch (after the backups, a wait for the secret answers, a pause): a stop asked for meanwhile,
        // and everything startBatch() checked, again
        $change = $this->changes->find($id) ?? $change;
        if (self::stopAsked($change)) {
            return; // its stop ends it at its next step
        }
        $problem = $this->batchProblem($change, $user, $now);
        if ($problem !== null) {
            $this->changes->updateBatch($id, $number, ['status' => 'failed', 'ended_at' => Clock::store($now), 'note' => UntrustedText::line($problem, 500)]);
            $this->finish($change, ChangeStatus::FAILED, "batch $number was not launched: $problem", $now);

            return;
        }
        if (! empty($change['has_secrets']) && $secrets === null) {
            // its window is open and its backups taken: it waits at most the expected time of a batch, then the change ends;
            // the wait is said in the timeline only once it is real (paused())
            $deadline = $now + $this->secretsWait();
            if ($this->update($change, ['status' => ChangeStatus::PAUSED, 'waiting' => 'secrets', 'resume_at' => Clock::store($deadline)]) !== null) {
                $this->changes->updateBatch($id, $number, ['status' => 'prepared', 'note' => null]);
            }

            return;
        }
        $this->changes->updateBatch($id, $number, ['status' => 'launching', 'started_at' => Clock::store($now), 'note' => null]);
        $this->beat();
        $result = $this->runner->launch($change, 'batch', $number, $this->batchDevices($change, $number), $user, $secrets ?? [], $this->batchVerify($change));
        if ($result['run_id'] === null) {
            $error = UntrustedText::line(implode(' ', $result['errors']) ?: 'the launch was refused', 1000);
            $this->changes->updateBatch($id, $number, ['status' => 'failed', 'ended_at' => Clock::store($now), 'note' => UntrustedText::line($error, 500)]);
            $this->finish($this->changes->find($id) ?? $change, ChangeStatus::FAILED, "batch $number was not launched: $error", $now);

            return;
        }
        $this->changes->updateBatch($id, $number, ['status' => 'running', 'run_id' => (int) $result['run_id']]);
        $this->timeline($change, 'batch', ChangeText::batchLaunched($change, $number, (int) $change['batch_count'], (int) $result['run_id'], $user['username']),
            $user['username'], $now);
    }

    /**
     * The check of a batch's launch against what was dry-run and approved: the template as the runner has it now (its
     * snapshot, against the fingerprint) and how the inventory reaches the batch's devices now (against the dry run's).
     *
     * @return \Closure(array, array): ?string
     */
    private function batchVerify(array $change): \Closure
    {
        $expected = (string) $change['plan_fingerprint'];
        $targets = $this->changes->targets((int) $change['id']);
        $answers = self::answers($change);
        $plan = (array) $change['plan'];
        $recorded = self::recorded($change);

        return function (array $snapshot, array $connections) use ($expected, $targets, $recorded, $answers, $plan, $change): ?string {
            $parts = Fingerprint::parts($snapshot, self::names($targets), $recorded, $answers, $plan);
            if (Fingerprint::of($parts) !== $expected) {
                $what = Fingerprint::differences((array) ($change['fingerprint_parts'] ?? []), $parts);

                return 'the template changed in the runner since the dry run' . ($what ? ' (' . implode(', ', $what) . ')' : '') . ': its approval no longer counts';
            }
            $moved = Connections::differences($recorded, $connections, self::displays($targets));

            return $moved ? self::movedText($moved, 'since the dry run') . ': its approval no longer counts' : null;
        };
    }

    /**
     * Seconds a prepared batch waits for the secret answers: the expected time of a batch, at least SECRETS_WAIT_MIN.
     */
    private function secretsWait(): int
    {
        return max(self::SECRETS_WAIT_MIN, $this->settings->expectedSeconds);
    }

    /**
     * The required secret answers missing from $secrets, by label: as the survey of the template that was dry-run requires
     * them (its fingerprint), or, for a dry run, as the change recorded them from the survey. The launch checks them
     * again; this refuses an action that would only fail later (after a window and backups), and changes nothing.
     *
     * @param  array<string, string>  $secrets
     * @return string[]
     */
    public static function missingSecrets(array $change, #[\SensitiveParameter] array $secrets, bool $forDryRun = false): array
    {
        $labels = [];
        $required = [];
        foreach ((array) ($change['variables'] ?? []) as $variable) {
            if (is_array($variable) && ! empty($variable['secret']) && isset($variable['name'])) {
                $labels[(string) $variable['name']] = (string) (($variable['label'] ?? '') ?: $variable['name']);
                if (! empty($variable['required'])) {
                    $required[(string) $variable['name']] = true;
                }
            }
        }
        $survey = ((array) (((array) ($change['fingerprint_parts'] ?? []))['template'] ?? []))['survey'] ?? null;
        if (! $forDryRun && is_array($survey)) {
            $required = [];
            foreach ($survey as $var) {
                $var = (array) $var;
                if (($var['type'] ?? null) === 'secret' && ! empty($var['required']) && isset($var['name'])) {
                    $required[(string) $var['name']] = true;
                }
            }
        }
        $missing = [];
        foreach (array_keys($required) as $name) {
            if ((string) ($secrets[$name] ?? '') === '') {
                $missing[] = UntrustedText::line($labels[$name] ?? $name, 80);
            }
        }

        return $missing;
    }

    /**
     * Why an action lacks secret answers.
     *
     * @param  string[]  $missing
     */
    private static function secretsText(array $missing, string $for): string
    {
        return 'Enter the secret ' . (count($missing) === 1 ? 'answer' : 'answers') . ' ' . implode(', ', $missing) . " $for: the plugin never keeps secret answers, "
            . 'so each launch needs them.';
    }

    /**
     * Was a stop asked for (it ends the change at its next step, and no batch launches meanwhile)?
     */
    private static function stopAsked(array $change): bool
    {
        return $change['status'] === ChangeStatus::STOPPING || (($change['stop_requested_at'] ?? null) !== null && ! ChangeStatus::isFinal($change['status']));
    }

    /**
     * The batch's run ended: record what it did, close its window after the settle time, ask for the backups after it,
     * and stop the change, end it, or go on (after an admin, a pause, or at once).
     */
    private function batchEnd(array $change, int $number, array $run, int $now, ?string $stoppedBy = null): void
    {
        $id = (int) $change['id'];
        $results = [];
        foreach ($this->runs->targets((int) $run['id']) as $deviceId => $target) {
            if (empty($target['unexpected'])) {
                $results[(int) $deviceId] = $target['result'];
            }
        }
        $devices = $this->batchDevices($change, $number);
        $results = array_replace(array_fill_keys($devices, null), array_intersect_key($results, array_flip($devices)));
        $plan = (array) $change['plan'];
        $stop = BatchOutcome::stopReason($number, $run, $results, max(1, (int) ($plan['stop_at'] ?? 1)), $change['dry_run_commit']);
        if ($stop === null) {
            $moved = Connections::differences(self::recorded($change), $this->runner->connections($devices), self::displays($this->changes->targets($id)));
            if ($moved) {
                $stop = self::movedText($moved, "by the end of batch $number") . ': the batch may have run on other devices than the ones dry-run';
            }
        }
        $count = (int) $change['batch_count'];
        if ($stoppedBy !== null && $run['status'] === RunStatus::STOPPED) {
            // the stop asked for stopped the batch's run
            $this->recordBatch($change, $number, $run, 'stopped', null, $now);
            $this->finish($change, ChangeStatus::STOPPED, 'stopped by ' . UntrustedText::line($stoppedBy, 60) . " during batch $number", $now, $stoppedBy);

            return;
        }
        $this->recordBatch($change, $number, $run, $stop === null ? 'done' : 'failed', $stop, $now);
        if ($stop !== null) {
            $this->finish($change, ChangeStatus::FAILED, $stop, $now);
        } elseif ($stoppedBy !== null && $number < $count) {
            // the batch's run ended before the stop could stop it: the change ends after it
            $this->finish($change, ChangeStatus::STOPPED, 'stopped by ' . UntrustedText::line($stoppedBy, 60) . " after batch $number", $now, $stoppedBy);
        } elseif ($number >= $count) {
            $this->finish($change, ChangeStatus::APPLIED, null, $now);
        } elseif ($number === 1 && ! empty($plan['wait_after_first'])) {
            if ($this->update($change, ['status' => ChangeStatus::PAUSED, 'waiting' => 'canary']) !== null) {
                $this->timeline($change, 'apply', ChangeText::waiting($change, 2, 'canary'), null, $now);
                $this->eventAll($change, ChangeText::waiting($change, 2, 'canary'), 'info', $this->batchUser($change, $number));
            }
        } elseif ((int) ($plan['pause_minutes'] ?? 0) > 0) {
            $until = $now + 60 * (int) $plan['pause_minutes'];
            if ($this->update($change, ['status' => ChangeStatus::PAUSED, 'waiting' => 'timer', 'resume_at' => Clock::store($until)]) !== null) {
                $this->timeline($change, 'apply', ChangeText::waiting($change, $number + 1, 'timer', $until), null, $now);
            }
        } else {
            $next = $this->update($change, ['current_batch' => $number + 1]);
            if ($next !== null) {
                $this->startBatch($next, $number + 1, $this->applier($next), null, $now);
            }
        }
    }

    /**
     * What a batch's run did: each device's result, the batch's end, its window's end (a settle time after the batch, or
     * after the hold of a stopped one) and the backups after it.
     */
    private function recordBatch(array $change, int $number, array $run, string $status, ?string $stop, int $now): void
    {
        $id = (int) $change['id'];
        $devices = $this->batchDevices($change, $number);
        $values = [];
        $summary = [];
        $targets = $this->runs->targets((int) $run['id']);
        foreach ($devices as $deviceId) {
            $result = $targets[$deviceId]['result'] ?? null;
            $values[$deviceId] = ['result' => $result ?? 'not_run', 'counts' => $targets[$deviceId]['counts'] ?? null];
            $summary[$result ?? 'not_run'] = ($summary[$result ?? 'not_run'] ?? 0) + 1;
        }
        $this->changes->updateTargets($id, $values);
        $ended = self::at($run['finished_at'] ?? null) ?: $now;
        $this->changes->updateBatch($id, $number, ['status' => $status, 'ended_at' => Clock::store($ended),
            'failures' => count(BatchOutcome::failed(array_map(fn ($value) => $value['result'], $values))), 'note' => $stop !== null ? UntrustedText::line($stop, 500) : null]);
        $held = ($run['held_until'] ?? null) !== null ? self::at($run['held_until']) : null;
        $this->closeWindows($change, $number, MaintenanceTimes::closing($ended, $held, $this->settings->settleSeconds), $now);
        if ($this->settings->backups && $this->backups->enabled()) {
            $this->requestBackups($change, $number, $devices, 'after', $now);
        }
        $this->timeline($change, 'batch', ChangeText::batchEnded($change, $number, (int) $change['batch_count'], (int) $run['id'], $summary, $stop), null, $now);
    }

    /**
     * End a change: its remaining batches are cancelled, the windows of batches that never ran close now, the eventlog
     * of every target says how it ended.
     */
    private function finish(array $change, string $status, ?string $reason, int $now, ?string $user = null): void
    {
        $id = (int) $change['id'];
        $current = $this->changes->find($id) ?? $change;
        if (ChangeStatus::isFinal($current['status'])) {
            return;
        }
        $done = $this->update($current, ['status' => $status, 'finished_at' => Clock::store($now), 'waiting' => null, 'resume_at' => null,
            'error' => $reason !== null ? UntrustedText::line($reason, 1000) : null]);
        if ($done === null) {
            return;
        }
        foreach ($this->changes->batches($id) as $number => $batch) {
            if (in_array($batch['status'], ['pending', 'preparing', 'prepared', 'launching'], true)) {
                $this->changes->updateBatch($id, (int) $number, ['status' => 'cancelled', 'ended_at' => Clock::store($now)]);
            }
        }
        // the windows of batches that ran end a settle time after them (recordBatch()); the others now
        foreach ($this->changes->windows($id) as $windowId => $window) {
            if ($window['status'] === 'open' && empty($window['closing'])) {
                $this->moveWindow($done, (int) $windowId, $window, $now, $now, true);
            }
        }
        $this->timeline($done, $status, ChangeText::outcome($status, $reason !== null ? UntrustedText::line($reason, 1000) : null), null, $now);
        $this->eventAll($done, ChangeText::finished($done, $status, $reason), match ($status) {
            ChangeStatus::APPLIED => 'ok',
            ChangeStatus::FAILED => 'error',
            default => 'warning',
        }, $user);
    }

    // ------------------------------------------------------------------ checks

    /**
     * Why the change's dry run no longer counts, null when it does: not verified, expired, another run may have changed
     * one of its devices since, or the inventory no longer reaches one of them as at the dry run (another address,
     * another platform, no longer a host: Connections).
     */
    private function dryRunProblem(array $change, int $now): ?string
    {
        if (($change['dry_run_state'] ?? null) !== 'verified') {
            return 'it has no verified dry run';
        }
        $at = self::at($change['dry_run_at']);
        if ($now - $at > $this->settings->dryRunSeconds) {
            return 'its dry run expired (Dry run valid setting)';
        }
        $changed = $this->runner->changedSince(array_keys($this->changes->targets((int) $change['id'])), $at, (int) $change['id']);
        if ($changed) {
            $runs = array_values(array_unique($changed));

            return 'another run changed ' . count($changed) . ' of its devices since its dry run (run' . (count($runs) === 1 ? ' #' : 's #')
                . implode(', #', array_slice($runs, 0, 5)) . ')';
        }
        $targets = $this->changes->targets((int) $change['id']);
        $moved = Connections::differences(self::recorded($change), $this->runner->connections(array_keys($targets)), self::displays($targets));

        return $moved ? self::movedText($moved, 'since the dry run') : null;
    }

    /**
     * How the runner reached the change's devices at its dry run (Connections), as its fingerprint parts recorded it.
     *
     * @return array<int|string, mixed>
     */
    private static function recorded(array $change): array
    {
        $parts = (array) ($change['fingerprint_parts'] ?? []);
        $connections = $parts['connections'] ?? [];

        return is_array($connections) || is_object($connections) ? (array) $connections : [];
    }

    /**
     * Devices the runner no longer reaches as at the dry run, in words.
     *
     * @param  string[]  $what  Connections::differences()
     */
    private static function movedText(array $what, string $when): string
    {
        return 'how the runner reaches ' . (count($what) === 1 ? 'a device' : 'devices') . " changed $when (" . implode('; ', $what) . ')';
    }

    /**
     * The name of each target to show: its display name, else its hostname.
     *
     * @return array<int, string>
     */
    private static function displays(array $targets): array
    {
        return array_map(fn ($target) => (string) (($target['display'] ?? '') ?: ($target['hostname'] ?? '') ?: ($target['inventory_name'] ?? '')), $targets);
    }

    /**
     * Is the template, as the runner has it now, the one the dry run ran? Null when it is; ['changed' => why] when it is
     * not; ['unreadable' => why] when the runner cannot give it now (unreachable, an error, a timeout), which says
     * nothing about the template: the action is refused and the dry run and approval stand.
     *
     * @return array{changed?: string, unreadable?: string}|null
     */
    private function templateCheck(array $change): ?array
    {
        $this->beat();
        $snapshot = $this->runner->snapshot($change);
        if ($snapshot['parts'] === null) {
            return ['unreadable' => UntrustedText::line((string) ($snapshot['error'] ?? 'no answer'), 300)];
        }
        $parts = Fingerprint::parts($snapshot['parts'], self::names($this->changes->targets((int) $change['id'])), self::recorded($change), self::answers($change),
            (array) $change['plan']);
        if (Fingerprint::of($parts) === $change['plan_fingerprint']) {
            return null;
        }
        $what = Fingerprint::differences((array) ($change['fingerprint_parts'] ?? []), $parts);

        return ['changed' => 'the template changed in the runner since the dry run' . ($what ? ' (' . implode(', ', $what) . ')' : '')];
    }

    /**
     * Why an approval or an apply is refused while the runner cannot give the template.
     */
    private static function unreadableText(string $error, bool $approved): string
    {
        return 'The template cannot be read from the runner now (' . $error . '): try again in a moment. The dry run'
            . ($approved ? ' and its approval still count' : ' still counts') . '; once the template can be read, it is compared with the one dry-run.';
    }

    /**
     * Why the change's approval does not count now; null when it does or none is needed.
     */
    private function approvalProblem(array $change, int $now): ?string
    {
        $approver = $change['approved_user_id'] !== null ? $this->runner->userProblem((int) $change['approved_user_id'], 'approve') : null;
        $problem = ApprovalRules::problem($this->settings->approval, $change, $this->settings->approvalSeconds, $now, $approver);
        if ($problem === null && ApprovalRules::needed($this->settings->approval)
            && $change['approval_fingerprint'] !== Fingerprint::approval((string) $change['plan_fingerprint'], (int) $change['dry_run_id'], $change['dry_run_commit'])) {
            return 'it approved another dry run';
        }

        return $problem;
    }

    /**
     * Why the next batch may not start; null when it may.
     *
     * @param  array{user_id: int, username: string}  $user
     */
    private function batchProblem(array $change, array $user, int $now): ?string
    {
        $problem = $this->runner->userProblem((int) $user['user_id'], 'run');
        if ($problem !== null) {
            return UntrustedText::line($user['username'], 60) . ', who launches it, ' . $problem;
        }

        return $this->dryRunProblem($change, $now) ?? $this->approvalProblem($change, $now);
    }

    /**
     * A change ready without an approval (none was needed) whose settings now ask for one: it awaits one. Nothing was
     * voided, so it is said as a request, not as a warning. True when the change moved.
     */
    private function approvalSwitchedOn(array $change, int $now, ?string $user = null): bool
    {
        if ($change['approved_at'] !== null || ! ApprovalRules::needed($this->settings->approval)) {
            return false;
        }
        $awaiting = $this->update($change, ['status' => ChangeStatus::AWAITING]);
        if ($awaiting !== null) {
            $this->timeline($awaiting, 'approval', 'The settings ask for ' . ApprovalRules::LABELS[$this->settings->approval] . ' now: approval requested.', null, $now);
            $this->eventAll($awaiting, ChangeText::approvalRequested($awaiting), 'info', $user);
        }

        return true;
    }

    /**
     * The dry run no longer counts: back to draft, approvals voided, said in the timeline and the eventlog.
     */
    private function voidDryRun(array $change, string $reason, int $now, ?string $user = null): void
    {
        $void = $this->update($change, ['status' => ChangeStatus::DRAFT, 'dry_run_state' => 'void', 'approved_by' => null, 'approved_user_id' => null,
            'approved_at' => null, 'approval_fingerprint' => null, 'error' => 'The dry run no longer counts: ' . UntrustedText::line($reason, 500) . '.']);
        if ($void === null) {
            return;
        }
        $this->changes->voidApprovals((int) $change['id'], $reason, $now);
        $this->timeline($void, 'dry_run', 'The dry run no longer counts: ' . UntrustedText::line($reason, 500) . '.', null, $now);
        $this->eventAll($void, ChangeText::dryRunVoid($void, $reason), 'warning', $user);
    }

    /**
     * The approval no longer counts: the change awaits a new one.
     */
    private function voidApproval(array $change, string $reason, int $now, ?string $user = null): void
    {
        $void = $this->update($change, ['status' => ChangeStatus::AWAITING, 'approved_by' => null, 'approved_user_id' => null, 'approved_at' => null,
            'approval_fingerprint' => null]);
        if ($void === null) {
            return;
        }
        $this->changes->voidApprovals((int) $change['id'], $reason, $now);
        $this->timeline($void, 'approval', 'The approval no longer counts: ' . UntrustedText::line($reason, 500) . '. The change needs a new approval.', null, $now);
        $this->eventAll($void, ChangeText::approvalVoid($void, $reason), 'warning', $user);
    }

    // ------------------------------------------------------------------ maintenance windows

    /**
     * Open the maintenance window of a batch; null when it is open, else why not.
     *
     * @param  int[]  $devices
     */
    private function openWindow(array $change, int $number, array $devices, int $now): ?string
    {
        $id = (int) $change['id'];
        foreach ($this->changes->windows($id) as $window) {
            if ((int) $window['batch'] === $number && $window['status'] === 'open') {
                return null; // opened by an earlier attempt
            }
        }
        $targets = $this->changes->targets($id);
        $names = array_map(fn ($deviceId) => (string) (($targets[$deviceId]['display'] ?? '') ?: ($targets[$deviceId]['hostname'] ?? "#$deviceId")), $devices);
        $end = MaintenanceTimes::opening($now, $this->settings->expectedSeconds);
        try {
            $scheduleId = $this->windows->open(ChangeText::windowTitle($change, $number), ChangeText::windowNotes($change, $number, (int) $change['batch_count'], $names,
                sprintf($this->settings->changeUrl, $id)), $devices, $now, $end, $this->settings->maintenanceBehavior);
        } catch (\RuntimeException $e) {
            return UntrustedText::line($e->getMessage(), 300);
        }
        $this->changes->addWindow(['change_id' => $id, 'batch' => $number, 'schedule_id' => $scheduleId, 'status' => 'open', 'opened_at' => $now, 'ends_at' => $end]);
        $this->timeline($change, 'window', "Maintenance window #$scheduleId opened for batch $number until " . Clock::text($end) . '.', null, $now);
        foreach ($devices as $deviceId) {
            $this->events->write(ChangeText::windowOpened($change, $number, $scheduleId, $end), $deviceId, 'info', ChangeText::reference($id), $this->batchUser($change, $number));
        }

        return null;
    }

    /**
     * Set the end of a batch's open windows (the plugin closes them when it is reached).
     */
    private function closeWindows(array $change, int $number, int $end, int $now): void
    {
        foreach ($this->changes->windows((int) $change['id']) as $windowId => $window) {
            if ((int) $window['batch'] !== $number || $window['status'] !== 'open' || ! empty($window['closing'])) {
                continue;
            }
            $this->moveWindow($change, (int) $windowId, $window, $end, $now, true);
        }
    }

    /**
     * Extend the windows of running batches, and record the windows whose end passed as closed.
     */
    private function followWindows(int $changeId, int $now): void
    {
        $change = $this->changes->find($changeId);
        if ($change === null) {
            return;
        }
        $batches = $this->changes->batches($changeId);
        foreach ($this->changes->windows($changeId) as $windowId => $window) {
            if ($window['status'] !== 'open') {
                continue;
            }
            $end = self::at($window['ends_at']);
            if (empty($window['closing']) && $this->batchActive($batches[(int) $window['batch']] ?? null)) {
                $extended = MaintenanceTimes::extension($end, $now, $this->settings->expectedSeconds);
                if ($extended !== null) {
                    $this->moveWindow($change, (int) $windowId, $window, $extended, $now, false);
                }

                continue;
            }
            if ($end <= $now) {
                $this->changes->updateWindow((int) $windowId, ['status' => 'closed', 'closed_at' => Clock::store($end)]);
                $this->timeline($change, 'window', "Maintenance window #{$window['schedule_id']} of batch {$window['batch']} closed.", null, $now);
                foreach ($this->batchDevices($change, (int) $window['batch']) as $deviceId) {
                    $this->events->write(ChangeText::windowClosed($change, (int) $window['batch'], (int) $window['schedule_id'], $end), $deviceId, 'info',
                        ChangeText::reference($changeId), $this->batchUser($change, (int) $window['batch']));
                }
            }
        }
    }

    /**
     * Is a batch still to run or running (its window is kept open and extended)?
     */
    private function batchActive(?array $batch): bool
    {
        if ($batch === null || ! in_array($batch['status'], ['preparing', 'prepared', 'launching', 'running'], true)) {
            return false;
        }
        if ($batch['run_id'] === null) {
            return true;
        }
        $run = $this->runs->find((int) $batch['run_id']);

        return $run !== null && ! RunStatus::isFinal($run['status']);
    }

    /**
     * Move a window's end in LibreNMS and record it; a window removed in LibreNMS is recorded as gone.
     */
    private function moveWindow(array $change, int $windowId, array $window, int $end, int $now, bool $closing): void
    {
        try {
            $exists = $this->windows->setEnd((int) $window['schedule_id'], $end);
        } catch (\RuntimeException $e) {
            $this->changes->updateWindow($windowId, ['error' => UntrustedText::line($e->getMessage(), 500)]);

            return;
        }
        if (! $exists) {
            $this->changes->updateWindow($windowId, ['status' => 'gone', 'closed_at' => Clock::store($now)]);
            $this->timeline($change, 'window', ChangeText::windowGone($change, (int) $window['batch'], (int) $window['schedule_id']) . '.', null, $now);

            return;
        }
        $this->changes->updateWindow($windowId, ['ends_at' => Clock::store($end), 'error' => null] + ($closing ? ['closing' => true] : []));
    }

    // ------------------------------------------------------------------ backups

    /**
     * At the dry run: can Oxidized back up each target (BackupCheck)? Recorded per target; the devices it cannot back up
     * are said in the timeline and in their eventlog, so the batches a missing backup would refuse are known before
     * the apply.
     *
     * @param  array<int, array>  $targets
     */
    private function checkBackups(array $change, array $targets, string $username, int $now): void
    {
        if (! $this->settings->backups || ! $this->backups->enabled()) {
            return;
        }
        $result = BackupCheck::of($this->backups, array_keys($targets), fn () => $this->beat());
        $this->changes->updateTargets((int) $change['id'], array_map(fn ($check) => ['backup_check' => $check], $result['checks']));
        $missing = BackupCheck::missing($result['checks'], self::displays($targets));
        $warning = BackupCheck::warning($missing, $this->settings->refuseWithoutBackup, $result['error']);
        if ($warning === null) {
            return;
        }
        $this->timeline($change, 'backup', $warning, null, $now);
        foreach ($result['checks'] as $deviceId => $check) {
            if ($check === BackupCheck::NO_NODE || $check === BackupCheck::EXCLUDED) {
                $this->events->write(ChangeText::backupCheck($change, $check, $this->settings->refuseWithoutBackup), $deviceId, 'warning',
                    ChangeText::reference((int) $change['id']), $username);
            }
        }
    }

    /**
     * Ask Oxidized for a backup of each device of a batch, before or after it, as LibreNMS finds the device's node.
     *
     * @param  int[]  $devices
     */
    private function requestBackups(array $change, int $number, array $devices, string $phase, int $now): void
    {
        $values = [];
        // once Oxidized could not be reached, the other devices of the batch are not asked (each call may wait its timeout)
        $down = null;
        foreach ($devices as $deviceId) {
            $this->beat();
            $state = ['status' => BackupState::REQUESTED, 'requested_at' => Clock::store($now), 'at' => null, 'version' => null, 'error' => null];
            try {
                if ($down !== null) {
                    throw new BackupError($down);
                }
                $node = $this->backups->node($deviceId);
                if ($node === null) {
                    $state['status'] = BackupState::NO_NODE;
                } elseif (! empty($node['excluded'])) {
                    $state['status'] = BackupState::EXCLUDED;
                } elseif (! $this->backups->request((string) $node['name'], ChangeText::backupMessage($change, $number, $phase), (string) $change['username'])) {
                    [$state['status'], $state['error']] = [BackupState::UNAVAILABLE, 'Oxidized refused the request or cannot be reached (check LibreNMS\' oxidized.url setting)'];
                }
            } catch (BackupError $e) {
                [$state['status'], $state['error']] = [BackupState::UNAVAILABLE, UntrustedText::line($e->getMessage(), 200)];
                $down = $e->getMessage();
            }
            $values[$deviceId] = ['backup_node' => isset($node['full_name']) ? mb_substr((string) $node['full_name'], 0, 255) : null];
            foreach ($state as $key => $value) {
                $values[$deviceId]["{$phase}_$key"] = $value;
            }
            $node = null;
        }
        $this->changes->updateTargets((int) $change['id'], $values);
        if ($phase === 'after') {
            $this->timeline($change, 'backup', 'Backups after batch ' . $number . ' requested from Oxidized.', null, $now);
        } else {
            $this->timeline($change, 'backup', 'Backups before batch ' . $number . ' requested from Oxidized.', null, $now);
        }
        $this->reportBackups($change, $number, $phase, $values, $now);
    }

    /**
     * Follow the backups the plugin asked for (before and after batches), whatever the change's status.
     */
    private function followBackups(int $changeId, int $now): void
    {
        $change = $this->changes->find($changeId);
        if ($change === null) {
            return;
        }
        // once Oxidized could not be reached, the other backups are not asked about in this refresh
        $down = null;
        foreach (['before', 'after'] as $phase) {
            $values = [];
            foreach ($this->changes->targets($changeId) as $deviceId => $target) {
                if (($target["{$phase}_status"] ?? null) !== BackupState::REQUESTED) {
                    continue;
                }
                $this->beat();
                $last = null;
                $error = $down;
                if ($down === null) {
                    try {
                        $node = $this->backups->node($deviceId);
                        $last = is_array($node['last'] ?? null) ? $node['last'] : null;
                    } catch (BackupError $e) {
                        $error = $down = UntrustedText::line($e->getMessage(), 200);
                    }
                }
                $state = BackupState::evaluate(['requested_at' => self::at($target["{$phase}_requested_at"]), 'error' => $error ?? $target["{$phase}_error"]], $last, $now,
                    $this->settings->backupTimeoutSeconds);
                if ($state['status'] === BackupState::REQUESTED && $state['error'] === $target["{$phase}_error"]) {
                    continue;
                }
                $values[$deviceId] = ["{$phase}_status" => $state['status'], "{$phase}_error" => $state['error'],
                    "{$phase}_at" => $state['at'] !== null ? Clock::store($state['at']) : null];
                if ($state['status'] === BackupState::FRESH && ! empty($target['backup_node'])) {
                    $version = $this->backups->latestVersion((string) $target['backup_node']);
                    $values[$deviceId]["{$phase}_version"] = $version['oid'] ?? null;
                }
            }
            if ($values) {
                $this->changes->updateTargets($changeId, $values);
                $targets = $this->changes->targets($changeId);
                foreach (array_unique(array_map(fn ($deviceId) => (int) $targets[$deviceId]['batch'], array_keys($values))) as $number) {
                    $this->reportBackups($change, $number, $phase, array_filter($values, fn ($deviceId) => (int) $targets[$deviceId]['batch'] === $number,
                        ARRAY_FILTER_USE_KEY), $now);
                }
            }
        }
    }

    /**
     * Write the backups that ended without a fresh one to the eventlog of their device and the timeline.
     *
     * @param  array<int, array<string, mixed>>  $values  device_id => the backup columns just written
     */
    private function reportBackups(array $change, int $number, string $phase, array $values, int $now): void
    {
        $failed = [];
        foreach ($values as $deviceId => $value) {
            $state = $value["{$phase}_status"] ?? null;
            if ($state !== null && $state !== BackupState::REQUESTED && $state !== BackupState::FRESH) {
                $failed[] = $deviceId;
                $this->events->write(ChangeText::backupFailed($change, $number, $phase, $state, $value["{$phase}_error"] ?? null), $deviceId, 'warning',
                    ChangeText::reference((int) $change['id']), $this->batchUser($change, $number));
            }
        }
        if ($failed) {
            $this->timeline($change, 'backup', 'No fresh backup ' . $phase . ' batch ' . $number . ' of ' . count($failed) . ' ' . (count($failed) === 1 ? 'device' : 'devices') . '.',
                null, $now);
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Write the change's row if it is still at the version read; the row as written, null when it changed meanwhile.
     *
     * @param  array<string, mixed>  $values
     */
    private function update(array $change, array $values): ?array
    {
        if (! $this->changes->updateAt((int) $change['id'], (int) $change['version'], $values)) {
            return null;
        }

        return array_merge($change, $values, ['version' => (int) $change['version'] + 1]);
    }

    /**
     * Who launches the batches the sync command starts: the admin who applied the change.
     *
     * @return array{user_id: int, username: string}
     */
    private function applier(array $change): array
    {
        return ['user_id' => (int) ($change['applied_user_id'] ?? 0), 'username' => (string) ($change['applied_by'] ?? '')];
    }

    /**
     * The devices of a batch, as the change's targets record them.
     *
     * @return int[]
     */
    private function batchDevices(array $change, int $number): array
    {
        $devices = [];
        foreach ($this->changes->targets((int) $change['id']) as $deviceId => $target) {
            if ((int) $target['batch'] === $number) {
                $devices[] = (int) $deviceId;
            }
        }

        return $devices;
    }

    /**
     * The answers that are not secret, as the change keeps them (secret ones are never kept).
     *
     * @return array<string, string|int>
     */
    public static function answers(array $change): array
    {
        $answers = [];
        foreach ((array) ($change['variables'] ?? []) as $variable) {
            if (is_array($variable) && empty($variable['secret']) && ($variable['value'] ?? null) !== null) {
                $answers[(string) $variable['name']] = $variable['value'];
            }
        }

        return $answers;
    }

    /**
     * device_id => inventory name of the targets.
     *
     * @param  array<int, array>  $targets
     * @return array<int, string>
     */
    private static function names(array $targets): array
    {
        return array_map(fn ($target) => (string) $target['inventory_name'], $targets);
    }

    /**
     * The rows of the dry run's diffs to store.
     *
     * @return array<int, array{device_id: ?int, task: string, item: ?string, kind: string, lines: string[], cut: bool, host: ?string, reason: ?string}>
     */
    private static function diffRows(array $parsed): array
    {
        $rows = [];
        foreach ($parsed['devices'] as $deviceId => $blocks) {
            foreach ($blocks as $block) {
                $rows[] = ['device_id' => (int) $deviceId, 'host' => null, 'reason' => null] + $block;
            }
        }
        foreach ($parsed['unattributed'] as $block) {
            $rows[] = ['device_id' => null] + $block;
        }

        return $rows;
    }

    /**
     * The change's batch plan in words.
     *
     * @param  int[]  $targetIds
     */
    private static function planText(array $change, array $targetIds): string
    {
        try {
            return BatchPlan::fromArray($change['plan'], $targetIds)->describe();
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    /**
     * A user's comment: one line, cut.
     */
    private static function comment(string $comment): string
    {
        return UntrustedText::line($comment, 500);
    }

    /**
     * A timeline entry.
     */
    private function timeline(array $change, string $kind, string $message, ?string $username, int $now): void
    {
        // the timeline is the change's own: an eventlog text loses its "Ansible change #N (reference)" there, and ends as a sentence
        $name = ChangeText::name($change);
        if (str_starts_with($message, $name)) {
            $message = ucfirst(ltrim(substr($message, strlen($name)), ':, '));
        }
        if (preg_match('/[\p{L}\p{N}]$/u', $message)) {
            $message .= '.';
        }
        $this->changes->addEvent((int) $change['id'], $kind, UntrustedText::line($message, 2000), $username, $now);
    }

    /**
     * An entry in the eventlog of every target, with the user who did the step (LibreNMS' User column): the admin who
     * acted, else, for a step the plugin took on its own, the admin it took it for (who applied the change, or asked
     * for it).
     */
    private function eventAll(array $change, string $message, string $severity, ?string $user = null): void
    {
        $user ??= self::actor($change);
        foreach (array_keys($this->changes->targets((int) $change['id'])) as $deviceId) {
            $this->events->write($message, (int) $deviceId, $severity, ChangeText::reference((int) $change['id']), $user);
        }
    }

    /**
     * Who a step the plugin takes on its own is taken for: the admin who applied the change once it is applied (or
     * stopped it, for its end), else the requester.
     */
    private static function actor(array $change): string
    {
        return (string) (($change['stop_requested_by'] ?? null) ?: (($change['applied_by'] ?? null) ?: $change['username']));
    }

    /**
     * The admin who started a batch (applied the change, or continued it to that batch), else actor().
     */
    private function batchUser(array $change, int $number): string
    {
        $batch = $this->changes->batches((int) $change['id'])[$number] ?? null;

        return (string) (($batch['username'] ?? null) ?: self::actor($change));
    }

    /**
     * A kept time as a Unix time (0 for none).
     */
    private static function at(mixed $stored): int
    {
        return (int) Clock::unix($stored === null ? null : (string) $stored);
    }
}
