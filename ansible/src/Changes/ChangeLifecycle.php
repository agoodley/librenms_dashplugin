<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Runs\EventSink;
use Agoodley\LibrenmsAnsible\Runs\RecapParser;
use Agoodley\LibrenmsAnsible\Runs\RunResults;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Runs\RunStore;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Agoodley\LibrenmsAnsible\Support\Format;

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
 * - A change planned by the topology plugin (RolloutPlan) carries each batch's approved blast radius. Before a batch
 *   starts, and again right before its launch, its blast radius is computed again on the network as it is then (as the
 *   admin who starts it sees it): when it grew (devices would be cut off that were not approved, or accepted since), the
 *   batch does not start and the change pauses until an approver accepts the new one (or ends, as set); when it cannot
 *   be computed, the batch does not start either, until an admin continues it without one (or the change ends); when it
 *   shrank, the batch goes on and the timeline says so. The batch's maintenance window covers its devices and the
 *   monitored devices it cuts off, as last computed.
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

    /**
     * Seconds between two checks of a batch whose blast radius could not be computed (the page's poll and the sync command
     * check it again: once it can be computed, the batch starts as any).
     */
    public const BLAST_RECHECK = 60;

    /** Why an acceptance, or a Continue without a blast radius, given for a check the batch no longer has is refused. */
    public const SEEN_CHANGED = 'The blast radius of the batch changed since you loaded the page: look again.';

    /** A target's record of what the inventory endpoint served the runner (InventoryGuard), cleared when its dry run or batch begins. */
    private const UNSERVED = ['served_hash' => null, 'served_at' => null, 'served_note' => null];

    /**
     * @var (\Closure(): void)|null called during long work (before calls to the runner, to Oxidized and to LibreNMS' maintenance): the follower
     *                              renews its lock, and throws LockLost when another request took the change over (the step stops there)
     */
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
        private readonly ?Topology $topology = null,
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
        // what the inventory endpoint serves the runner during this dry run is recorded anew
        $this->changes->updateTargets($id, array_fill_keys(array_keys($targets), self::UNSERVED));
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
                return self::unreadableText($template['unreadable'], false, $template['missing']);
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
     * Accept the new blast radius of a batch that does not start because it grew since the approval (or since the change
     * was made, without one): an admin with the approve permission (not the requester when the settings ask for a second
     * admin), or, while the settings ask for no approval, with the run permission (ApprovalRules::acceptPermission())
     * accepts what it cuts off now, which the batch is held to from then on, and the batch starts again (its blast radius
     * computed once more). It accepts the blast radius the change page showed ($seen: BlastRadius::checkId() of the
     * check it showed): when the batch's blast radius changed since (another admin accepted it, the batch started again
     * and grew once more), the acceptance is refused. Null when it was accepted, else why not.
     *
     * @param  array{user_id: int, username: string}  $user
     * @param  bool  $holds  the user is an admin holding the permission the settings ask for
     */
    public function acceptBlast(int $id, array $user, bool $holds, string $comment, int $now, string $seen = ''): ?string
    {
        $change = $this->changes->find($id);
        if ($change === null) {
            return 'There is no such change.';
        }
        $refusal = ApprovalRules::acceptRefusal($this->settings->approval, $change, (int) $user['user_id'], $holds);
        if ($refusal !== null) {
            return $refusal;
        }
        $number = (int) $change['current_batch'];
        $blast = $this->changes->batches($id)[$number]['blast'] ?? null;
        if (! is_array($blast) || ($blast['state'] ?? null) !== 'grew') {
            return 'Batch ' . $number . ' has no new blast radius to accept: reload the page.';
        }
        if (! hash_equals(BlastRadius::checkId($blast, $id, $number), $seen)) {
            return self::SEEN_CHANGED;
        }
        $resumed = $this->update($change, ['status' => ChangeStatus::APPLYING, 'waiting' => null, 'resume_at' => null]);
        if ($resumed === null) {
            return 'The change changed meanwhile: reload the page.';
        }
        $comment = self::comment($comment);
        // what it cuts off now; while a device of the batch is skipped (what depends on it is unknown), also what the batch was held to:
        // the check's window. An acceptance never drops a device the batch was held to without the blast radius that says so
        $lost = array_values(array_unique(array_map('intval', (array) (empty($blast['unplanned']) ? ($blast['lost'] ?? []) : ($blast['window'] ?? $blast['lost'] ?? [])))));
        $previous = $this->changes->batches($id)[$number]['blast_accepted'] ?? null;
        $all = array_values(array_unique([...array_map('intval', (array) (is_array($previous) ? ($previous['all'] ?? $previous['lost'] ?? []) : [])), ...$lost]));
        $reference = ($blast['reference'] ?? 'core') === 'largest part' ? 'largest part' : 'core';
        $this->changes->updateBatch($id, $number, ['blast_accepted' => ['lost' => $lost, 'all' => $all, 'unmanaged' => (int) ($blast['unmanaged'] ?? 0), 'reference' => $reference,
            'added' => array_values(array_map('intval', (array) ($blast['added'] ?? []))), 'by' => mb_substr($user['username'], 0, 255),
            'user_id' => (int) $user['user_id'], 'at' => Clock::store($now), 'comment' => $comment]]);
        $text = ChangeText::blastAccepted($resumed, $number, $user['username'], BlastRadius::text(['lost' => $lost, 'unmanaged' => (int) ($blast['unmanaged'] ?? 0)],
            $this->blastNames($resumed, $number)) . ($reference === 'largest part' ? ChangeText::LARGEST_PART : ''), $comment);
        $this->timeline($resumed, 'blast', $text, $user['username'], $now);
        // the eventlog of each target counts the devices: it names no other device
        $this->eventAll($resumed, ChangeText::blastAcceptedEvent($resumed, $number, $user['username'], count($lost), (int) ($blast['unmanaged'] ?? 0), $comment),
            'notice', $user['username']);
        // started as the admin who started it before (who applied the change or continued it to this batch)
        $this->startBatch($resumed, $number, $this->starter($resumed, $number), null, $now);

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
                return self::unreadableText($template['unreadable'], ApprovalRules::needed($this->settings->approval), $template['missing']);
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
            // a device of batch 1 busy in another run (or held after a stop) would fail the batch at its start: refused now, the
            // change stays ready (checked under the lock too, so no run launched meanwhile takes one)
            $busy = $this->busyRefusal($change, 1, 'the change is still ready; apply it');
            if ($busy !== null) {
                return $busy;
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
        // a device of a later batch busy in another run now: that batch will wait for it (busyWaits()), or end the change; said
        // before the first batch runs
        $later = $this->laterBusyText($started, 1);
        if ($later !== null) {
            $this->timeline($started, 'apply', "Devices of later batches are busy now: $later. A batch waits for its devices when it starts, at most "
                . intdiv($this->secretsWait(), 60) . ' minutes; then the change ends.', null, $now);
        }
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
     * Continue a paused change: the next batch after the first one or a pause, the prepared batch with the secret
     * answers, or the batch whose blast radius could not be computed: computed first, it starts as any once it can be,
     * and goes without one (recorded) only while it still cannot; such a Continue names the check the change page
     * showed ($seen, as for acceptBlast()), and is refused when it changed since, also when the check it makes then
     * finds another reason or another window than the page showed (kept for the page to show). A batch whose blast
     * radius grew is not continued: an admin accepts the new one (acceptBlast()). Null when it continued, else why not.
     *
     * @param  array{user_id: int, username: string}  $user
     * @param  array<string, string>  $secrets
     */
    public function resume(int $id, array $user, #[\SensitiveParameter] array $secrets, int $now, string $seen = ''): ?string
    {
        $change = $this->changes->find($id);
        if ($change === null) {
            return 'There is no such change.';
        }
        if ($change['status'] !== ChangeStatus::PAUSED) {
            return 'Only a paused change can be continued.';
        }
        $batch = (int) $change['current_batch'];
        if ($change['waiting'] === 'blast') {
            return "Batch $batch waits for an admin with the approve permission to accept its new blast radius (it grew since the approval): it does not start "
                . 'before. Stop the change rather than run it with a blast radius nobody approved.';
        }
        $problem = $this->runner->userProblem((int) $user['user_id'], 'run');
        if ($problem !== null) {
            return 'You may not continue the change: ' . $problem . '.';
        }
        if ($change['waiting'] === 'blast_unknown') {
            $record = $this->changes->batches($id)[$batch]['blast'] ?? null;
            if (! is_array($record) || ! hash_equals(BlastRadius::checkId($record, $id, $batch), $seen)) {
                return self::SEEN_CHANGED;
            }
        }
        // the batch the change waits to start again (with the secret answers, or without a blast radius), else the next one
        $again = in_array($change['waiting'], ['secrets', 'blast_unknown'], true);
        $missing = self::missingSecrets($change, $secrets);
        if ($missing) {
            // nothing changes: a batch that waits for them keeps waiting (its window open, its backups taken)
            return $change['waiting'] === 'secrets'
                ? self::secretsText($missing, "to launch batch $batch") . ' The batch keeps waiting for them until '
                    . ($change['resume_at'] !== null ? Clock::show(self::at($change['resume_at']), 'Y-m-d H:i T') : 'its deadline') . '.'
                : self::secretsText($missing, 'to continue with batch ' . ($again ? $batch : $batch + 1));
        }
        // a batch whose blast radius could not be computed: computed first, as the admin who continues it sees the network. Still not:
        // it goes without one only for what the page showed; another reason, or another window, is shown first (look again)
        $failure = null;
        if ($change['waiting'] === 'blast_unknown') {
            $failure = $this->blastProbe($change, $batch, $user, $now);
            if ($failure !== null && ! hash_equals(BlastRadius::checkId($failure, $id, $batch), $seen)) {
                $this->changes->updateBatch($id, $batch, ['blast' => $failure]);

                return self::SEEN_CHANGED;
            }
        }
        // a device of the batch busy in another run (or held after a stop) would fail it: refused now, the change keeps waiting
        // (checked and the change moved under the lock of the launches, as at the apply)
        $next = $again ? $batch : $batch + 1;
        $resumed = null;
        $refusal = $this->runner->exclusive(function () use ($change, $batch, $next, $again, &$resumed): ?string {
            $busy = $this->busyRefusal($change, $next, 'the change keeps waiting; continue it');
            if ($busy !== null) {
                return $busy;
            }
            $resumed = $this->update($change, ['status' => ChangeStatus::APPLYING, 'waiting' => null, 'resume_at' => null]
                + ($again ? [] : ['current_batch' => $batch + 1]));

            return $resumed === null ? 'The change changed meanwhile: reload the page.' : null;
        });
        if ($refusal !== null || $resumed === null) {
            return $refusal ?? 'The change changed meanwhile: reload the page.';
        }
        if ($change['waiting'] === 'secrets') {
            $this->launchBatch($resumed, $batch, $user, $secrets, $now);

            return null;
        }
        if ($change['waiting'] === 'blast_unknown') {
            $row = $this->changes->batches($id)[$batch] ?? [];
            // it can be computed now (the cause was fixed): the batch starts as any, checked against its blast radius
            if ($failure === null) {
                $this->timeline($resumed, 'blast', "Batch $batch: its blast radius can be computed again; continued by " . UntrustedText::line($user['username'], 60) . '.',
                    $user['username'], $now);
                $this->startBatch($resumed, $batch, $user, $secrets, $now);

                return null;
            }
            // the admin's explicit decision, recorded on the batch: while its blast radius still cannot be computed, it goes on with
            // its window covering its devices and the devices approved (or accepted) as cut off (with what it cuts off now as far as
            // the topology plugin can tell: never more, as a blast radius that grew waits for its acceptance first)
            $this->changes->updateBatch($id, $batch, ['blast_override' => ['by' => mb_substr($user['username'], 0, 255), 'user_id' => (int) $user['user_id'],
                'at' => Clock::store($now), 'why' => (string) $failure['error']]]);
            $window = array_values(array_map('intval', (array) $failure['window']));
            $text = ChangeText::blastOverride($resumed, $batch, $user['username'], (string) $failure['error'],
                BlastRadius::listText($window, (array) ($failure['names'] ?? []) + $this->blastNames($resumed, $batch)), is_array($row['blast_accepted'] ?? null));
            $this->timeline($resumed, 'blast', $text, $user['username'], $now);
            $this->eventAll($resumed, ChangeText::blastOverrideEvent($resumed, $batch, $user['username'], (string) $failure['event_error'], count($window),
                is_array($row['blast_accepted'] ?? null)), 'warning', $user['username']);
            $this->startBatch($resumed, $batch, $user, $secrets, $now);

            return null;
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
        // a log that says nothing ran counts only with the guard's own record: it left every target out of what it served
        $proof = $lines !== null ? DryRunProof::check($lines, $recap, InventoryGuard::everyTargetLeftOut($this->changes->targets($id)))
            : ['verified' => false, 'findings' => ['The plugin could not read the dry run\'s whole log from the runner, so nothing proves it was a dry run.'],
                'nothing_ran' => false];
        if (! $proof['nothing_ran']) {
            // a dry run that did not run on any device has nothing to prove: no proof is recorded, so its run is not shown as one
            // that may have changed its devices
            $this->runner->recordProof((int) $run['id'], (bool) $proof['verified']);
        }
        $targets = [];
        foreach ($this->runs->targets((int) $run['id']) as $deviceId => $target) {
            if (empty($target['unexpected'])) {
                $targets[(int) $deviceId] = $target;
            }
        }
        // what the runner got for the targets from the inventory endpoint during the dry run: one left out may not have run, one served
        // another connection than the one recorded at its launch may have been another device
        $served = $this->servedTo($change, array_keys($this->changes->targets($id)));
        $ranClean = $run['status'] === RunStatus::SUCCESS && empty($run['unexpected_hosts']);
        $state = $proof['nothing_ran'] ? 'failed'
            : (! $proof['verified'] ? 'unverified' : ($ranClean && ! $served['left'] && ! $served['other'] ? 'verified' : 'failed'));
        $next = $state === 'verified' ? (ApprovalRules::needed($this->settings->approval) ? ChangeStatus::AWAITING : ChangeStatus::READY) : ChangeStatus::DRAFT;
        $findings = $proof['findings'];
        if ($served['left'] || $served['other']) {
            $findings[] = 'The runner did not get every target as recorded when the dry run was launched (' . implode('; ', [...$served['left'], ...$served['other']])
                . ($served['left'] ? '): the plugin left ' . (count($served['left']) === 1 ? 'a host' : 'hosts') . ' out of an inventory the runner fetched meanwhile'
                    . ($proof['nothing_ran'] ? '.' : ', so the dry run may not have run there.') : ').');
        }
        if ($state === 'failed' && ! $ranClean && ! $proof['nothing_ran']) {
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
            'error' => $state === 'verified' ? null : ($state === 'unverified' ? 'The dry run is not proven to be one.' : ($proof['nothing_ran']
                ? 'The dry run did not run on any device: none of the targets was in the inventory the runner fetched.' : ($ranClean
                ? 'The runner did not get every target as recorded when the dry run was launched.' : 'The dry run did not succeed on every target.')))]);
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
                $this->prepare($change, $number, $now);
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
            case 'done':
            case 'failed':
            case 'stopped':
                // its end is recorded, but the step that recorded it ended before it moved the change on
                $this->batchRecorded($change, $number, $batch, $now);
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
            // the batch it waits to start: the current one again (secret answers, a blast radius), else the next one
            $again = in_array($change['waiting'], ['secrets', 'blast', 'blast_unknown'], true);
            $this->finish($change, ChangeStatus::FAILED, 'while it waited before batch ' . ((int) $change['current_batch'] + ($again ? 0 : 1)) . ", $problem", $now);

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
        if ($change['waiting'] === 'blast_unknown') {
            $this->blastRecheck($change, $now);

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
        if ($batch !== null && in_array($batch['status'], ['done', 'failed', 'stopped'], true)) {
            // the batch's end is recorded: what its step left is done, and the change ends as that end decides (a last batch
            // that did its work leaves the change applied, a failed one failed)
            $this->batchRecorded($change, $number, $batch, $now, $by);

            return;
        }
        $this->finish($change, ChangeStatus::STOPPED, 'stopped by ' . UntrustedText::line($by, 60) . " before batch $number was launched", $now, $by);
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
        if ($secrets === null && $this->busyWaits($change, $number, $now)) {
            return;
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
        // its blast radius on the network as it is now, against the one approved (or accepted) for it: what its window covers
        $cutOff = $this->blastCheck($change, $number, $user, $now, false);
        if ($cutOff === null) {
            return; // it does not start: the change paused or ended
        }
        $devices = $this->batchDevices($change, $number);
        $this->changes->updateBatch($id, $number, ['status' => 'preparing', 'started_at' => Clock::store($now), 'user_id' => (int) $user['user_id'],
            'username' => mb_substr($user['username'], 0, 255), 'note' => null]);
        if ($this->settings->maintenance) {
            $error = $this->openWindow($change, $number, $devices, $cutOff, $now);
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
     * A batch being prepared, at a later step: its window and the backups before it as startBatch() opened and asked
     * for them, and what a request that stopped in the middle (it died, or another request took the change over:
     * LockLost) left undone, done now; then the batch launches once its backups are in.
     */
    private function prepare(array $change, int $number, int $now): void
    {
        $id = (int) $change['id'];
        $devices = $this->batchDevices($change, $number);
        // a window never opened for this start of the batch (one an admin removed in LibreNMS meanwhile is not opened again),
        // on the devices its blast check said it cuts off
        $batch = $this->changes->batches($id)[$number] ?? [];
        if ($this->settings->maintenance && ! $this->startedWindow($id, $number, $batch)) {
            $error = $this->openWindow($change, $number, $devices, self::windowCutOff($batch), $now);
            if ($error !== null) {
                $this->changes->updateBatch($id, $number, ['status' => 'failed', 'ended_at' => Clock::store($now), 'note' => UntrustedText::line($error, 500)]);
                $this->finish($change, ChangeStatus::FAILED, "the maintenance window of batch $number could not be opened: $error", $now);

                return;
            }
        }
        if ($this->settings->backups && $this->backups->enabled()) {
            $targets = $this->changes->targets($id);
            $unasked = array_values(array_filter($devices, fn (int $deviceId) => ($targets[$deviceId]['before_status'] ?? null) === null));
            if ($unasked) {
                $this->requestBackups($change, $number, $unasked, 'before', $now);
            }
            $this->followBackups($id, $now);
        }
        $this->backupsBefore($change, $number, $now);
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
        if (! $this->settings->backups || ! $this->backups->enabled()) {
            // no backups are taken (the settings, or LibreNMS' Oxidized integration is off): nothing to wait for
            $this->launchBatch($this->changes->find($id) ?? $change, $number, $this->starter($change, $number), $secrets, $now);

            return;
        }
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
        // its blast radius again, on the network as it is now: a batch that would cut off more than was approved does not launch,
        // and its window covers what it cuts off now
        $cutOff = $this->blastCheck($change, $number, $user, $now, true);
        if ($cutOff === null) {
            return; // it does not launch: the change paused (its window closed) or ended
        }
        if ($this->settings->maintenance) {
            $error = $this->coverWindow($change, $number, $cutOff, $now);
            if ($error !== null) {
                $this->changes->updateBatch($id, $number, ['status' => 'failed', 'ended_at' => Clock::store($now), 'note' => UntrustedText::line($error, 500)]);
                $this->finish($change, ChangeStatus::FAILED, "batch $number was not launched: its maintenance window could not cover what it cuts off: $error", $now);

                return;
            }
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
        $this->beat();
        // what the inventory endpoint serves the runner during this batch is recorded anew, before the batch counts as in progress
        $this->changes->updateTargets($id, array_fill_keys($this->batchDevices($change, $number), self::UNSERVED));
        $this->changes->updateBatch($id, $number, ['status' => 'launching', 'started_at' => Clock::store($now), 'note' => null]);
        // a Stop takes no lock: one recorded while this launch is checked keeps it from the runner (asked again right before the
        // run is recorded, under the launch lock, and right before the runner is asked)
        // (a change another request ended meanwhile launches nothing either)
        $halt = function () use ($id): ?string {
            $current = $this->changes->find($id);
            if ($current === null || ChangeStatus::isFinal($current['status'])) {
                return 'the change ended before the batch was launched';
            }

            return self::stopAsked($current) ? 'the change was stopped before the batch was launched' : null;
        };
        $result = $this->runner->launch($change, 'batch', $number, $this->batchDevices($change, $number), $user, $secrets ?? [], $this->batchVerify($change), $halt);
        if ($result['run_id'] === null) {
            $current = $this->changes->find($id) ?? $change;
            if (self::stopAsked($current) || ChangeStatus::isFinal($current['status'])) {
                // the stop came while the launch was checked: nothing was recorded or launched; the stop ends the change
                $this->changes->updateBatch($id, $number, ['status' => 'cancelled', 'ended_at' => Clock::store($now), 'note' => 'not launched: the change was stopped']);

                return;
            }
            $error = UntrustedText::line(implode(' ', $result['errors']) ?: 'the launch was refused', 1000);
            $this->changes->updateBatch($id, $number, ['status' => 'failed', 'ended_at' => Clock::store($now), 'note' => UntrustedText::line($error, 500)]);
            $this->finish($this->changes->find($id) ?? $change, ChangeStatus::FAILED, "batch $number was not launched: $error", $now);

            return;
        }
        $this->changes->updateBatch($id, $number, ['status' => 'running', 'run_id' => (int) $result['run_id']]);
        $run = $this->runs->find((int) $result['run_id']);
        if ($run !== null && $run['status'] === RunStatus::NOT_LAUNCHED) {
            return; // recorded, then kept from the runner (a stop came at the last moment): its end is taken in at the next step
        }
        $this->timeline($change, 'batch', ChangeText::batchLaunched($change, $number, (int) $change['batch_count'], (int) $result['run_id'], $user['username'])
            . $this->launchedBlast($change, $number), $user['username'], $now);
        // a stop recorded while the runner was asked (after the last check): the task it just got is stopped at once
        $current = $this->changes->find($id);
        if ($current !== null && self::stopAsked($current)) {
            $this->runner->stopRun((int) $result['run_id'], (string) (($current['stop_requested_by'] ?? null) ?: $user['username']));
        }
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

                return self::differsText($what) . ': its approval no longer counts';
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
        return self::waitSeconds($this->settings);
    }

    /**
     * Seconds a batch waits, at most, for what it needs to start or launch (the secret answers, a device busy in another
     * run): the expected time of a batch, at least SECRETS_WAIT_MIN.
     */
    public static function waitSeconds(ChangeSettings $settings): int
    {
        return max(self::SECRETS_WAIT_MIN, $settings->expectedSeconds);
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
        if ($stoppedBy !== null && $run['status'] === RunStatus::NOT_LAUNCHED) {
            // the stop kept the batch's run from the runner: nothing ran on its devices, its window ends now (finish())
            $this->changes->updateTargets($id, array_fill_keys($this->batchDevices($change, $number), ['result' => 'not_run', 'counts' => null]));
            $this->changes->updateBatch($id, $number, ['status' => 'cancelled', 'ended_at' => Clock::store($now), 'note' => 'not launched: the change was stopped']);
            $this->finish($change, ChangeStatus::STOPPED, 'stopped by ' . UntrustedText::line($stoppedBy, 60) . " before batch $number was launched", $now, $stoppedBy);

            return;
        }
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
        // what the runner got for the devices from the inventory endpoint while the batch was in progress, not LibreNMS as it is now
        $served = $this->servedTo($change, $devices);
        if ($served['other']) {
            $stop = self::movedText([...$served['left'], ...$served['other']], "during batch $number") . ': the batch may have run on other devices than the ones dry-run';
        } elseif ($served['left']) {
            $one = count($served['left']) === 1;
            $stop = self::movedText($served['left'], "during batch $number") . ': the plugin left ' . ($one ? 'it' : 'them') . ' out of an inventory the runner fetched '
                . 'meanwhile, so the batch may not have run on ' . ($one ? 'it' : 'them');
        } elseif ($stop === null && $served['unserved']) {
            // no fetch was recorded for them while the batch was in progress: as the inventory reaches them now
            $moved = Connections::differences(self::recorded($change), $this->runner->connections($served['unserved']), self::displays($this->changes->targets($id)));
            if ($moved) {
                $stop = self::movedText($moved, "by the end of batch $number") . ': the batch may have run on other devices than the ones dry-run';
            }
        }
        $status = $stoppedBy !== null && $run['status'] === RunStatus::STOPPED ? 'stopped' : ($stop === null ? 'done' : 'failed');
        $this->recordBatch($change, $number, $run, $status, $status === 'failed' ? $stop : null, $now);
        $this->batchTail($change, $number, $run, $now);
        $this->afterBatch($change, $number, $status, $status === 'failed' ? $stop : null, $now, $stoppedBy);
    }

    /**
     * A batch whose end is recorded while its change still applies or stops: the step that recorded it ended before it
     * moved the change on (another request took its lock over, the web server restarted, a time limit, an error while it
     * set the end of the batch's window or asked Oxidized for the backups after it). What that step left is done now,
     * and the change moves on as the end of the batch decided: a step that stopped there never leaves it applying.
     */
    private function batchRecorded(array $change, int $number, array $batch, int $now, ?string $stoppedBy = null): void
    {
        $run = $batch['run_id'] !== null ? $this->runs->find((int) $batch['run_id']) : null;
        if ($run !== null) {
            $this->batchTail($change, $number, $run, $now);
        }
        $note = UntrustedText::line((string) ($batch['note'] ?? ''), 500);
        $stop = $batch['status'] !== 'failed' ? null : ($run === null ? "batch $number was not started" . ($note !== '' ? ": $note" : '') : ($note ?: "batch $number failed"));
        $this->afterBatch($change, $number, $batch['status'], $stop, $now, $stoppedBy ?? (self::stopAsked($change)
            ? (string) (($change['stop_requested_by'] ?? null) ?: 'an admin') : null));
    }

    /**
     * What follows the record of a batch's end: the end of its windows (a settle time after the batch, or after the hold
     * of a stopped one) and the backups after it that were not asked yet. Done again by a step that finds it undone.
     */
    private function batchTail(array $change, int $number, array $run, int $now): void
    {
        $id = (int) $change['id'];
        $batch = $this->changes->batches($id)[$number] ?? [];
        $ended = self::at($batch['ended_at'] ?? null) ?: (self::at($run['finished_at'] ?? null) ?: $now);
        $held = ($run['held_until'] ?? null) !== null ? self::at($run['held_until']) : null;
        $this->closeWindows($change, $number, MaintenanceTimes::closing($ended, $held, $this->settings->settleSeconds), $now);
        if ($this->settings->backups && $this->backups->enabled()) {
            $targets = $this->changes->targets($id);
            $unasked = array_values(array_filter($this->batchDevices($change, $number), fn (int $deviceId) => ($targets[$deviceId]['after_status'] ?? null) === null));
            if ($unasked) {
                $this->requestBackups($change, $number, $unasked, 'after', $now);
            }
        }
    }

    /**
     * Move the change on from a batch whose end is recorded (done, failed: $stop, stopped): end it, wait (after an admin,
     * a pause) or start the next batch. Each move is a compare-and-set of the change: of two requests, one moves it.
     */
    private function afterBatch(array $change, int $number, string $status, ?string $stop, int $now, ?string $stoppedBy): void
    {
        $count = (int) $change['batch_count'];
        $plan = (array) $change['plan'];
        if ($status === 'stopped') {
            // the stop asked for stopped the batch's run
            $this->finish($change, ChangeStatus::STOPPED, 'stopped by ' . UntrustedText::line((string) ($stoppedBy ?? 'an admin'), 60) . " during batch $number", $now,
                $stoppedBy);
        } elseif ($stop !== null) {
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
     * What a batch's run did: each device's result and the batch's end, said in the timeline (batchTail() follows).
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
        $this->timeline($change, 'batch', ChangeText::batchEnded($change, $number, (int) $change['batch_count'], (int) $run['id'], $summary, $stop), null, $now);
    }

    /**
     * End a change: its remaining batches are cancelled, the windows of batches that never ran close now, the eventlog
     * of every target says how it ended.
     */
    private function finish(array $change, string $status, ?string $reason, int $now, ?string $user = null, ?string $eventReason = null): void
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
        // the eventlog of each target gets the reason without other devices' names, when it has them ($eventReason)
        $this->eventAll($done, ChangeText::finished($done, $status, $eventReason ?? $reason), match ($status) {
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
        return Connections::recordedIn($change);
    }

    /**
     * What the inventory endpoint served the runner for the devices while their dry run or batch was in progress
     * (InventoryGuard): the devices it left out (why), those it served another connection than the one the dry run
     * recorded, and those no fetch served (by device id).
     *
     * @param  int[]  $devices
     * @return array{left: string[], other: string[], unserved: int[]}
     */
    private function servedTo(array $change, array $devices): array
    {
        $recorded = self::recorded($change);
        $targets = $this->changes->targets((int) $change['id']);
        $names = self::displays($targets);
        $found = ['left' => [], 'other' => [], 'unserved' => []];
        foreach ($devices as $deviceId) {
            $hash = $targets[$deviceId]['served_hash'] ?? null;
            $name = UntrustedText::line((string) ($names[$deviceId] ?? "device #$deviceId"), 80);
            $was = $recorded[$deviceId] ?? null;
            if ($hash === null) {
                $found['unserved'][] = (int) $deviceId;
            } elseif ($hash === InventoryGuard::LEFT_OUT) {
                $found['left'][] = UntrustedText::line((string) (($targets[$deviceId]['served_note'] ?? '') ?: "$name was left out"), 400);
            } elseif ($hash === InventoryGuard::MIXED || ! (is_array($was) || is_object($was)) || $hash !== Connections::hash($was)) {
                $found['other'][] = "$name: the runner got another connection than the one recorded at the dry run";
            }
        }

        return $found;
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
            return ['unreadable' => UntrustedText::line((string) ($snapshot['error'] ?? 'no answer'), 300), 'missing' => ! empty($snapshot['missing'])];
        }
        $parts = Fingerprint::parts($snapshot['parts'], self::names($this->changes->targets((int) $change['id'])), self::recorded($change), self::answers($change),
            (array) $change['plan']);
        if (Fingerprint::of($parts) === $change['plan_fingerprint']) {
            return null;
        }
        $what = Fingerprint::differences((array) ($change['fingerprint_parts'] ?? []), $parts);

        return ['changed' => self::differsText($what)];
    }

    /**
     * What differs from the dry run, in words: the template in the runner, or only what the change itself keeps (its
     * targets, answers, batch plan with what each batch cuts off, how the runner reached the targets), which the plugin
     * never alters after the dry run.
     *
     * @param  string[]  $what  Fingerprint::differences()
     */
    private static function differsText(array $what): string
    {
        $own = $what && ! array_diff($what, ['the targets', 'the answers', 'the batch plan', 'how the runner reaches the targets']);

        return ($own ? 'the change is no longer what was dry-run' : 'the template changed in the runner since the dry run') . ($what ? ' (' . implode(', ', $what) . ')' : '');
    }

    /**
     * Why an approval or an apply is refused while the runner cannot give the template.
     */
    private static function unreadableText(string $error, bool $approved, bool $missing = false): string
    {
        $counts = 'The dry run' . ($approved ? ' and its approval still count' : ' still counts');
        if ($missing) {
            // a 404 is no passing failure: say what to do rather than to try again
            return 'The runner does not know the template (' . $error . '): it may have been deleted in Semaphore UI. While it is missing the change can be '
                . 'neither approved nor applied: restore the template (it is compared with the one dry-run), or cancel the change and make a new one. ' . $counts
                . ' until ' . ($approved ? 'they expire' : 'it expires') . '.';
        }

        return 'The template cannot be read from the runner now (' . $error . '): try again in a moment. ' . $counts
            . '; once the template can be read, it is compared with the one dry-run.';
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
     * A batch the plugin starts on its own (after the previous one, a pause, at a later step) whose device is busy in
     * another run, one that began before the apply reserved the devices or holds them after a stop: it waits, pending,
     * at most the expected time of a batch (at least 15 minutes) instead of ending the change at once; the wait is
     * recorded as the batch's start and note, and said in the timeline once. True while it waits; past the wait the
     * change ends as failed (true as well).
     */
    private function busyWaits(array $change, int $number, int $now): bool
    {
        $id = (int) $change['id'];
        $busy = $this->runner->busy($this->batchDevices($change, $number));
        if (! $busy) {
            return false;
        }
        $what = $this->busyText($change, $busy);
        $batch = $this->changes->batches($id)[$number] ?? [];
        $since = ($batch['started_at'] ?? null) !== null ? self::at($batch['started_at']) : null;
        if ($since === null) {
            $until = $now + $this->secretsWait();
            $this->changes->updateBatch($id, $number, ['started_at' => Clock::store($now), 'note' => UntrustedText::line("waits: $what", 500)]);
            $this->timeline($change, 'batch', "Batch $number waits: $what. It starts once " . (count($busy) === 1 ? 'it is' : 'they are') . ' free; if '
                . (count($busy) === 1 ? 'it is' : 'they are') . ' not by ' . Clock::text($until) . ', the change ends.', null, $now);

            return true;
        }
        if ($now - $since < $this->secretsWait()) {
            return true;
        }
        $this->changes->updateBatch($id, $number, ['status' => 'failed', 'ended_at' => Clock::store($now), 'note' => UntrustedText::line($what, 500)]);
        $this->finish($change, ChangeStatus::FAILED, "batch $number was not started: $what, still after a wait of " . intdiv($this->secretsWait(), 60) . ' minutes', $now);

        return true;
    }

    /**
     * The devices of the batches after batch $after that are busy in other runs now, in words with their batch
     * ("lab-sw3 (batch 2) is busy in run #92 (...), which has not finished"); null when none is.
     */
    private function laterBusyText(array $change, int $after): ?string
    {
        $targets = $this->changes->targets((int) $change['id']);
        $later = [];
        foreach ($targets as $deviceId => $target) {
            if ((int) $target['batch'] > $after) {
                $later[(int) $deviceId] = (int) $target['batch'];
            }
        }
        $busy = $later ? $this->runner->busy(array_keys($later)) : [];
        if (! $busy) {
            return null;
        }
        $names = self::displays($targets);
        $what = array_map(fn ($deviceId, $why) => UntrustedText::line((string) ($names[$deviceId] ?? "device #$deviceId"), 80) . ' (batch ' . $later[$deviceId] . ') is '
            . UntrustedText::line($why, 300), array_keys($busy), $busy);

        return implode('; ', array_slice($what, 0, 5)) . (count($what) > 5 ? '; ' . (count($what) - 5) . ' more' : '');
    }

    /**
     * Devices busy in other runs, in words ("lab-sw1 is busy in run #12, which has not finished").
     *
     * @param  array<int, string>  $busy  device_id => why (ChangeRunner::busy())
     */
    private function busyText(array $change, array $busy): string
    {
        $names = self::displays($this->changes->targets((int) $change['id']));
        $what = array_map(fn ($deviceId, $why) => UntrustedText::line((string) ($names[$deviceId] ?? "device #$deviceId"), 80) . ' is ' . UntrustedText::line($why, 300),
            array_keys($busy), $busy);

        return implode('; ', array_slice($what, 0, 5)) . (count($what) > 5 ? '; ' . (count($what) - 5) . ' more' : '');
    }

    /**
     * Why an action may not start batch $number now: a device of it is busy in an unfinished run or held after a stop
     * ("Batch 1 cannot start now: lab-sw1 is busy in run #12, which has not finished. Nothing changed: the change is still
     * ready; apply it once it is free."); null when none is.
     */
    private function busyRefusal(array $change, int $number, string $then): ?string
    {
        $busy = $this->runner->busy($this->batchDevices($change, $number));
        if (! $busy) {
            return null;
        }

        return "Batch $number cannot start now: " . $this->busyText($change, $busy) . ". Nothing changed: $then once " . (count($busy) === 1 ? 'it is' : 'they are') . ' free.';
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

    // ------------------------------------------------------------------ blast radius

    /**
     * The blast radius of batch $number on the network as it is now (as $user, who starts the batch, sees it), against
     * the one it is held to: the one approved with the change, or the one an approver accepted since. Before the batch
     * starts ($atLaunch false) and again right before its launch. It grew: the batch does not start (blastStop()). It
     * cannot be computed (the topology plugin cannot answer, or can no longer tell what depends on a device of the
     * batch it could tell about when the change was made): the batch does not start either, unless an admin continued
     * it without one. It shrank: it goes on, and the timeline says so. A plan of the plugin's settings has no blast
     * radius: its batches go as in phase 2.
     *
     * @param  array{user_id: int, username: string}  $user
     * @return int[]|null the monitored devices the batch's window covers besides its own devices (what it cuts off now;
     *                    when an admin went on without a blast radius, what was approved or accepted as cut off and what
     *                    it cuts off now as far as the topology plugin can tell); null when it does not go on
     */
    private function blastCheck(array $change, int $number, array $user, int $now, bool $atLaunch): ?array
    {
        $plan = $change['plan'] ?? null;
        if (! BatchPlan::isTopology($plan)) {
            return []; // planned by the plugin's settings: its blast radius is unknown (the change's page says so)
        }
        $id = (int) $change['id'];
        if (BatchPlan::topologyOf($plan, $number) === null) {
            $why = 'the blast radius approved with the change cannot be read';
            $this->changes->updateBatch($id, $number, ['status' => 'failed', 'ended_at' => Clock::store($now), 'note' => $why]);
            $this->finish($change, ChangeStatus::FAILED, "batch $number was not started: $why", $now);

            return null;
        }
        $batch = $this->changes->batches($id)[$number] ?? [];
        $record = $this->blastNow($change, $number, $batch, $user, $now);
        if ($record['state'] === 'grew') {
            $this->blastStop($change, $number, 'blast', $record, $user, $now);

            return null;
        }
        if ($record['state'] === 'unknown') {
            if (is_array($batch['blast_override'] ?? null)) {
                // an admin continued it without a blast radius (the timeline and the eventlog have the decision): its window
                // covers what was approved (or accepted) as cut off, and what it cuts off now as far as that can be computed
                $this->changes->updateBatch($id, $number, ['blast' => ['state' => 'override'] + $record]);

                return $record['window'];
            }
            $this->blastStop($change, $number, 'blast_unknown', $record, $user, $now);

            return null;
        }
        $previous = $batch['blast'] ?? null;
        $this->changes->updateBatch($id, $number, ['blast' => $record]);
        if ($record['removed'] && ! ($atLaunch && is_array($previous) && ($previous['removed'] ?? null) == $record['removed'])) {
            // said once: not again right before the launch when its start said the same
            $text = ChangeText::blastShrank($change, $number, BlastRadius::listText($record['removed'], $record['names']), $record['accepted']);
            $this->timeline($change, 'blast', $text . ($atLaunch ? ' (right before its launch)' : '') . '; it goes on.', null, $now);
            $event = ChangeText::blastShrankEvent($change, $number, count($record['removed']), $record['accepted']);
            foreach ($this->batchDevices($change, $number) as $deviceId) {
                $this->events->write($event, $deviceId, 'info', ChangeText::reference($id), $user['username']);
            }
        }

        return $record['lost'];
    }

    /**
     * The blast radius of batch $number computed now, as $user sees the network, against the one the batch is held to:
     * the check kept on the batch. Its state: "grew" (it would cut off a monitored device it is not held to, or it now
     * takes down every core device of its part while it was held to leave one standing: core_lost), "unknown"
     * (the topology plugin cannot answer, or can no longer tell what depends on a device of the batch it could tell
     * about when the change was made: BlastRadius::unplanned(), named with the reason in its error), "shrank" or
     * "same"; a blast radius that grew is said so first, also while a device is skipped (an approver accepts it, then
     * the batch waits for an admin's decision to go on without a blast radius). Its window: what the batch's window
     * covers besides the batch's devices if the batch goes on (when the blast radius cannot be computed, or a device of
     * the batch is skipped: what the batch is held to cut off, what was approved and everything accepted since, and what
     * it cuts off now as far as the topology plugin can tell). Its error names devices, for the change page and its
     * timeline; its event_error counts them, for the eventlog.
     *
     * @param  array<string, mixed>  $batch  the batch's row
     * @param  array{user_id: int, username: string}  $user
     * @return array<string, mixed>
     */
    private function blastNow(array $change, int $number, array $batch, array $user, int $now): array
    {
        $held = self::heldOf($change, $number, $batch);
        $heldReference = self::heldReferenceOf($change, $number, $batch);
        // what a window covers when the blast radius cannot be computed: what the batch is held to, what was approved and everything
        // accepted since (an acceptance never makes it cover less than that), then what it cuts off now as far as that can be computed
        $covered = array_values(array_unique([...$held, ...array_map('intval', BatchPlan::topologyOf($change['plan'] ?? null, $number)['lost'] ?? []),
            ...array_map('intval', (array) ($batch['blast_accepted']['all'] ?? $batch['blast_accepted']['lost'] ?? []))]));
        $base = ['held' => $held, 'held_reference' => $heldReference, 'accepted' => is_array($batch['blast_accepted'] ?? null), 'at' => Clock::store($now)];
        $this->beat();
        try {
            if ($this->topology === null) {
                throw new TopologyError('the topology plugin is not available to this plugin');
            }
            $answer = BlastRadius::fromApi($this->topology->blastRadius($this->batchDevices($change, $number), (int) $user['user_id']));
        } catch (LockLost $e) {
            throw $e;
        } catch (TopologyError|\InvalidArgumentException $e) {
            $why = self::blastError($e);

            return ['state' => 'unknown', 'error' => $why, 'event_error' => $why, 'lost' => $held, 'window' => $covered] + $base;
        }
        $blast = $answer['blast'];
        // a batch held to leave a core device of its part standing that now takes them all down grew, whatever it cuts off (that is
        // measured from the largest piece left then, not from the core)
        $diff = BlastRadius::compare($held, $blast['lost'], $heldReference, $blast['reference']);
        $unplanned = BlastRadius::unplanned(BatchPlan::topologyOf($change['plan'] ?? null, $number)['skipped'] ?? [], $blast['skipped']);
        $mentioned = array_unique([...$blast['lost'], ...$diff['removed'], ...array_column($blast['skipped'], 'device_id')]);
        $record = $blast + ['state' => $diff['added'] || $diff['core_lost'] ? 'grew' : ($unplanned ? 'unknown' : ($diff['removed'] ? 'shrank' : 'same')),
            'added' => $diff['added'], 'removed' => $diff['removed'], 'core_lost' => $diff['core_lost'], 'unplanned' => $unplanned,
            'window' => $unplanned ? array_values(array_unique([...$covered, ...$blast['lost']])) : $blast['lost'],
            'names' => array_intersect_key($answer['names'] + $this->blastNames($change, $number), array_flip($mentioned))] + $base;
        if ($unplanned) {
            // what depends on them is not in what it cuts off: the batch's blast radius cannot be computed (even when it shrank)
            $record['error'] = 'the topology plugin can no longer tell what depends on ' . RolloutPlan::reasonsText($unplanned, $record['names']);
            $record['event_error'] = 'the topology plugin can no longer tell what depends on ' . Format::count(count($unplanned), 'device') . ' of the batch ('
                . implode(', ', array_unique(array_map(fn (array $entry) => TopologyPlan::reasonText($entry['reason']), $unplanned))) . ')';
        }

        return $record;
    }

    /**
     * Why a blast radius cannot be computed, in words.
     */
    private static function blastError(TopologyError|\InvalidArgumentException $e): string
    {
        return UntrustedText::line($e instanceof TopologyError ? $e->getMessage() : 'the topology plugin\'s answer is not one: ' . $e->getMessage(), 300);
    }

    /**
     * Can the blast radius of batch $number be computed now, as $user sees the network? Null when it can, else the check
     * that says why not (of state "unknown": blastNow()).
     *
     * @param  array{user_id: int, username: string}  $user
     * @return array<string, mixed>|null
     */
    private function blastProbe(array $change, int $number, array $user, int $now): ?array
    {
        $record = $this->blastNow($change, $number, $this->changes->batches((int) $change['id'])[$number] ?? [], $user, $now);

        return $record['state'] === 'unknown' ? $record : null;
    }

    /**
     * A change waiting because the blast radius of its batch could not be computed, checked again every BLAST_RECHECK
     * seconds as the admin who started the batch: once it can be computed (the topology plugin enabled again), the batch
     * starts as any, checked against its blast radius; until then the batch keeps its last check (when, why not, what its
     * window would cover).
     */
    private function blastRecheck(array $change, int $now): void
    {
        $id = (int) $change['id'];
        $number = (int) $change['current_batch'];
        $batch = $this->changes->batches($id)[$number] ?? null;
        $record = is_array($batch['blast'] ?? null) ? $batch['blast'] : [];
        $last = self::at($record['at'] ?? null);
        if ($batch === null || ($last !== null && $now - $last < self::BLAST_RECHECK)) {
            return;
        }
        $starter = $this->starter($change, $number);
        $failure = $this->blastProbe($change, $number, $starter, $now);
        if ($failure !== null) {
            // still not: the page says when it was checked last, why not, and what the batch's window would cover
            $this->changes->updateBatch($id, $number, ['blast' => $failure]);

            return;
        }
        $resumed = $this->update($change, ['status' => ChangeStatus::APPLYING, 'waiting' => null, 'resume_at' => null]);
        if ($resumed === null) {
            return;
        }
        $this->timeline($resumed, 'blast', "Batch $number: its blast radius can be computed again: the batch starts.", null, $now);
        $this->startBatch($resumed, $number, $starter, null, $now);
    }

    /**
     * What a batch launched now cuts off while it runs, for its line in the timeline ("" for a plan of the settings).
     */
    private function launchedBlast(array $change, int $number): string
    {
        $approved = BatchPlan::topologyOf($change['plan'] ?? null, $number);
        if ($approved === null) {
            return '';
        }
        if ($approved['hand']) {
            return ': planned by hand, nothing tells what depends on its devices';
        }
        $record = $this->changes->batches((int) $change['id'])[$number]['blast'] ?? null;
        if (! is_array($record)) {
            return '';
        }
        $names = $this->blastNames($change, $number);
        if (($record['state'] ?? null) === 'override') {
            return ': its blast radius unknown (continued without one), its window covers ' . BlastRadius::listText(array_map('intval', (array) ($record['window'] ?? [])), $names, 10, 'its devices only');
        }

        return ': while it runs it cuts off ' . BlastRadius::text(['lost' => array_map('intval', (array) ($record['lost'] ?? [])), 'unmanaged' => (int) ($record['unmanaged'] ?? 0)], $names);
    }

    /**
     * A batch that does not start because its blast radius grew ($waiting "blast") or cannot be computed ("blast_unknown"):
     * the change ends (the settings refuse such a batch), or pauses until an approver accepts the new blast radius, or an
     * admin continues without one. A window opened for this start closes now: when the batch starts again, it opens one
     * on what the batch cuts off then, and asks for fresh backups.
     *
     * @param  array<string, mixed>  $record  the blast radius computed now (or why it cannot be), kept on the batch
     * @param  array{user_id: int, username: string}  $user  who starts the batch (it is started as them again)
     */
    private function blastStop(array $change, int $number, string $waiting, array $record, array $user, int $now): void
    {
        $id = (int) $change['id'];
        $grew = $waiting === 'blast';
        $refuse = $grew ? $this->settings->refuseGrownBlast : $this->settings->refuseUnknownBlast;
        $names = (array) ($record['names'] ?? []) + $this->blastNames($change, $number);
        // the change page and its timeline name the devices; the eventlog of each target counts them (it names no other device)
        if ($grew) {
            [$since, $word] = ChangeText::held($change, ! empty($record['accepted']));
            $added = (array) $record['added'];
            $coreLost = ! empty($record['core_lost']);
            $why = "its blast radius grew since $since: " . ChangeText::grewText($added ? BlastRadius::listText($added, $names) : '', $coreLost);
            $eventWhy = "its blast radius grew since $since: " . ChangeText::grewCount(count($added), $coreLost, $word);
            $text = ChangeText::blastGrew($change, $number, $added ? BlastRadius::listText($added, $names) : '',
                BlastRadius::listText((array) $record['held'], $names), $refuse, ! empty($record['accepted']), ApprovalRules::accepter($this->settings->approval), $coreLost);
            $event = ChangeText::blastGrewEvent($change, $number, count($added), count((array) ($record['lost'] ?? [])), (int) ($record['unmanaged'] ?? 0), $refuse,
                ! empty($record['accepted']), ApprovalRules::accepter($this->settings->approval), $coreLost);
        } else {
            $why = 'its blast radius cannot be computed now (' . $record['error'] . ')';
            $eventWhy = 'its blast radius cannot be computed now (' . ($record['event_error'] ?? $record['error']) . ')';
            $text = ChangeText::blastUnknown($change, $number, (string) $record['error'], $refuse);
            $event = ChangeText::blastUnknown($change, $number, (string) ($record['event_error'] ?? $record['error']), $refuse);
        }
        if ($refuse) {
            $setting = ' (setting ' . ($grew ? 'When the blast radius grew' : 'When the blast radius cannot be computed') . ': refuse the batch)';
            $this->changes->updateBatch($id, $number, ['status' => 'failed', 'ended_at' => Clock::store($now), 'note' => UntrustedText::line($why, 500), 'blast' => $record]);
            $this->timeline($change, 'blast', $text, null, $now);
            $this->finish($change, ChangeStatus::FAILED, "batch $number was not started: $why$setting", $now, null, "batch $number was not started: $eventWhy$setting");

            return;
        }
        $paused = $this->update($change, ['status' => ChangeStatus::PAUSED, 'waiting' => $waiting, 'resume_at' => null]);
        if ($paused === null) {
            return;
        }
        $this->changes->updateBatch($id, $number, ['status' => 'pending', 'started_at' => null, 'user_id' => (int) $user['user_id'],
            'username' => mb_substr($user['username'], 0, 255), 'note' => UntrustedText::line(ucfirst($why), 500), 'blast' => $record]);
        foreach ($this->changes->windows($id) as $windowId => $window) {
            if ((int) $window['batch'] === $number && $window['status'] === 'open' && empty($window['closing'])) {
                $this->moveWindow($paused, (int) $windowId, $window, $now, $now, true);
            }
        }
        $this->timeline($paused, 'blast', $text, null, $now);
        $this->eventAll($paused, $event, 'warning', $this->batchUser($paused, $number));
    }

    /**
     * The monitored devices batch $number is held to cut off: as an approver accepted them when its blast radius grew,
     * else as approved with the change.
     *
     * @return int[]
     */
    private function heldLost(array $change, int $number): array
    {
        return self::heldOf($change, $number, $this->changes->batches((int) $change['id'])[$number] ?? []);
    }

    /**
     * What the blast radius batch $number is held to is measured from, as accepted or approved: "core", or "largest
     * part" (it takes every core device of its part down).
     *
     * @param  array<string, mixed>  $batch  the batch's row
     */
    private static function heldReferenceOf(array $change, int $number, array $batch): string
    {
        $accepted = $batch['blast_accepted'] ?? null;
        $reference = is_array($accepted) ? ($accepted['reference'] ?? 'core') : (BatchPlan::topologyOf($change['plan'] ?? null, $number)['reference'] ?? 'core');

        return $reference === 'largest part' ? 'largest part' : 'core';
    }

    /**
     * heldLost() of the batch's row.
     *
     * @param  array<string, mixed>  $batch
     * @return int[]
     */
    private static function heldOf(array $change, int $number, array $batch): array
    {
        $accepted = $batch['blast_accepted'] ?? null;
        if (is_array($accepted)) {
            return array_values(array_map('intval', (array) ($accepted['lost'] ?? [])));
        }

        return BatchPlan::topologyOf($change['plan'] ?? null, $number)['lost'] ?? [];
    }

    /**
     * The names the topology plugin gave the devices it said batch $number cuts off (approved with the plan, or computed
     * since), and the targets' own.
     *
     * @return array<int, string>
     */
    private function blastNames(array $change, int $number): array
    {
        $batch = $this->changes->batches((int) $change['id'])[$number] ?? [];

        return (array) ($batch['blast']['names'] ?? []) + (BatchPlan::topologyPart($change['plan'] ?? null)?->names ?? [])
            + array_filter(self::displays($this->changes->targets((int) $change['id'])));
    }

    /**
     * The devices the window of a batch's current start covers besides its own, as its blast check said.
     *
     * @return int[]
     */
    private static function windowCutOff(array $batch): array
    {
        return array_values(array_map('intval', (array) ($batch['blast']['window'] ?? [])));
    }

    /**
     * Has the current start of batch $number a window (open, or removed by an admin in LibreNMS: not opened again)? Windows of
     * an earlier start, closed when the batch did not start, do not count.
     */
    private function startedWindow(int $changeId, int $number, array $batch): bool
    {
        $since = self::at($batch['started_at'] ?? null);
        foreach ($this->changes->windows($changeId) as $window) {
            if ((int) $window['batch'] === $number && self::at($window['opened_at'] ?? null) >= $since
                && (($window['status'] === 'open' && empty($window['closing'])) || $window['status'] === 'gone')) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ maintenance windows

    /**
     * Open the maintenance window of a batch, on its devices and the monitored devices it cuts off; null when it is
     * open, else why not.
     *
     * @param  int[]  $devices
     * @param  int[]  $cutOff  the monitored devices the batch cuts off (its blast radius)
     */
    private function openWindow(array $change, int $number, array $devices, array $cutOff, int $now): ?string
    {
        $id = (int) $change['id'];
        foreach ($this->changes->windows($id) as $window) {
            if ((int) $window['batch'] === $number && $window['status'] === 'open' && empty($window['closing'])) {
                return null; // opened by an earlier attempt
            }
        }
        $targets = $this->changes->targets($id);
        $names = array_map(fn ($deviceId) => (string) (($targets[$deviceId]['display'] ?? '') ?: ($targets[$deviceId]['hostname'] ?? "#$deviceId")), $devices);
        $cutOff = BlastRadius::cutOff($devices, $cutOff);
        $known = $this->blastNames($change, $number);
        $cutNames = array_map(fn (int $deviceId) => (string) ($known[$deviceId] ?? "#$deviceId"), $cutOff);
        $end = MaintenanceTimes::opening($now, $this->settings->expectedSeconds);
        $this->beat(); // a window is opened once: by the request that still holds the change
        try {
            $scheduleId = $this->windows->open(ChangeText::windowTitle($change, $number), ChangeText::windowNotes($change, $number, (int) $change['batch_count'], $names,
                sprintf($this->settings->changeUrl, $id), $cutNames), BlastRadius::windowDevices($devices, $cutOff), $now, $end, $this->settings->maintenanceBehavior);
        } catch (\RuntimeException $e) {
            return UntrustedText::line($e->getMessage(), 300);
        }
        $this->changes->addWindow(['change_id' => $id, 'batch' => $number, 'schedule_id' => $scheduleId, 'status' => 'open', 'opened_at' => $now, 'ends_at' => $end,
            'cut_off' => $cutOff]);
        $this->timeline($change, 'window', "Maintenance window #$scheduleId opened for batch $number until " . Clock::text($end)
            . ($cutOff ? ', also on the devices it cuts off: ' . BlastRadius::listText($cutOff, $known) : '') . '.', null, $now);
        foreach ($devices as $deviceId) {
            $this->events->write(ChangeText::windowOpened($change, $number, $scheduleId, $end), $deviceId, 'info', ChangeText::reference($id), $this->batchUser($change, $number));
        }
        foreach ($cutOff as $deviceId) {
            $this->events->write(ChangeText::windowCutOff($change, $number, $scheduleId, $end), $deviceId, 'info', ChangeText::reference($id),
                $this->batchUser($change, $number));
        }

        return null;
    }

    /**
     * Right before a batch's launch: its window covers its devices and the monitored devices it cuts off now (its blast
     * radius computed again), and only those; the devices added and taken out are said in the timeline and in their
     * eventlog. Null when it does (or the batch has no open window: maintenance windows off, or removed in LibreNMS),
     * else why LibreNMS could not change it.
     *
     * @param  int[]  $cutOff
     */
    private function coverWindow(array $change, int $number, array $cutOff, int $now): ?string
    {
        $id = (int) $change['id'];
        $devices = $this->batchDevices($change, $number);
        $cutOff = BlastRadius::cutOff($devices, $cutOff);
        foreach ($this->changes->windows($id) as $windowId => $window) {
            if ((int) $window['batch'] !== $number || $window['status'] !== 'open' || ! empty($window['closing'])) {
                continue;
            }
            $had = array_values(array_map('intval', (array) ($window['cut_off'] ?? [])));
            $added = array_values(array_diff($cutOff, $had));
            $removed = array_values(array_diff($had, $cutOff));
            if (! $added && ! $removed) {
                return null;
            }
            $known = $this->blastNames($change, $number);
            $targets = $this->changes->targets($id);
            $names = array_map(fn ($deviceId) => (string) (($targets[$deviceId]['display'] ?? '') ?: ($targets[$deviceId]['hostname'] ?? "#$deviceId")), $devices);
            $this->beat();
            try {
                $exists = $this->windows->setDevices((int) $window['schedule_id'], BlastRadius::windowDevices($devices, $cutOff), ChangeText::windowNotes($change, $number,
                    (int) $change['batch_count'], $names, sprintf($this->settings->changeUrl, $id), array_map(fn (int $deviceId) => (string) ($known[$deviceId] ?? "#$deviceId"), $cutOff)));
            } catch (\RuntimeException $e) {
                return UntrustedText::line($e->getMessage(), 300);
            }
            if (! $exists) {
                $this->changes->updateWindow((int) $windowId, ['status' => 'gone', 'closed_at' => Clock::store($now)]);
                $this->timeline($change, 'window', ChangeText::windowGone($change, $number, (int) $window['schedule_id']) . '.', null, $now);

                return null;
            }
            $this->changes->updateWindow((int) $windowId, ['cut_off' => $cutOff]);
            $this->timeline($change, 'window', "Maintenance window #{$window['schedule_id']} of batch $number now covers what the batch cuts off right before its launch"
                . ($added ? ': also ' . BlastRadius::listText($added, $known) : '') . ($removed ? ($added ? '; ' : ': ') . 'no longer ' . BlastRadius::listText($removed, $known) : '') . '.',
                null, $now);
            $until = self::at($window['ends_at'] ?? null);
            foreach ($added as $deviceId) {
                $this->events->write(ChangeText::windowCutOff($change, $number, (int) $window['schedule_id'], $until), $deviceId, 'info',
                    ChangeText::reference($id), $this->batchUser($change, $number));
            }
            foreach ($removed as $deviceId) {
                $this->events->write(ChangeText::windowUncovered($change, $number, (int) $window['schedule_id']), $deviceId, 'info', ChangeText::reference($id),
                    $this->batchUser($change, $number));
            }

            return null;
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
                $covered = BlastRadius::windowDevices($this->batchDevices($change, (int) $window['batch']), array_map('intval', (array) ($window['cut_off'] ?? [])));
                foreach ($covered as $deviceId) {
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
            try {
                $this->beat();
            } catch (LockLost $e) {
                // another request holds the change now: what was asked so far is recorded for it, the rest is not asked
                $this->changes->updateTargets((int) $change['id'], $values);

                throw $e;
            }
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
        $fresh = [];
        foreach ($values as $deviceId => $value) {
            $state = $value["{$phase}_status"] ?? null;
            if ($state === BackupState::FRESH) {
                $fresh[$deviceId] = $value["{$phase}_version"] ?? null;
                $this->events->write(ChangeText::backedUp($change, $number, $phase, $fresh[$deviceId]), $deviceId, 'info', ChangeText::reference((int) $change['id']),
                    $this->batchUser($change, $number));
            } elseif ($state !== null && $state !== BackupState::REQUESTED) {
                $failed[] = $deviceId;
                $this->events->write(ChangeText::backupFailed($change, $number, $phase, $state, $value["{$phase}_error"] ?? null), $deviceId, 'warning',
                    ChangeText::reference((int) $change['id']), $this->batchUser($change, $number));
            }
        }
        if ($fresh) {
            $names = self::displays(array_intersect_key($this->changes->targets((int) $change['id']), $fresh));
            $this->timeline($change, 'backup', 'Oxidized backed up ' . (count($fresh) <= 5
                ? implode(', ', array_map(fn ($deviceId) => UntrustedText::line($names[$deviceId] ?? "#$deviceId", 80) . ($fresh[$deviceId] !== null
                    ? ' (version ' . substr((string) $fresh[$deviceId], 0, 10) . ')' : ''), array_keys($fresh)))
                : count($fresh) . ' devices') . " $phase batch $number.", null, $now);
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
