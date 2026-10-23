<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Agoodley\LibrenmsAnsible\Support\Format;

/**
 * Texts the plugin writes about a change outside its page: the eventlog of each target and the change's timeline.
 * They name the change, the template and the users, never an answer (answers can be secret). Free text from users (a
 * change's reference, an approval's comment) and from the runner is cleaned and cut; LibreNMS and the plugin's pages
 * escape what they show. Times carry their zone.
 */
final class ChangeText
{
    /**
     * The state of a change's dry run, as the changes list (page and JSON) shows it: the label and its colour. A dry run
     * not proven to be one is NOT PROVEN: it may have changed its devices.
     */
    public const DRY_STATES = ['verified' => ['success', 'verified'], 'unverified' => ['danger', 'NOT PROVEN'], 'failed' => ['danger', 'failed'],
        'running' => ['info', 'running'], 'launching' => ['info', 'launching'], 'void' => ['default', 'no longer counts'], 'not_launched' => ['warning', 'not launched']];

    /** Labels of a target's dry-run result. */
    public const DRY_RESULTS = ['changed' => 'would change', 'ok' => 'no change', 'skipped' => 'no change (skipped)', 'failed' => 'failed',
        'unreachable' => 'unreachable', 'not_run' => 'not run', 'unknown' => 'unknown'];

    /**
     * Eventlog reference of a change: the path of its page, relative to LibreNMS' URL.
     */
    public static function reference(int $changeId): string
    {
        return 'plugin/ansible/changes/' . $changeId;
    }

    /**
     * "Ansible change #12 (CHG-123)": the change and its reference.
     */
    public static function name(array $change): string
    {
        $reference = UntrustedText::line($change['reference'] ?? '', 80);

        return 'Ansible change #' . (int) $change['id'] . ($reference !== '' ? " ($reference)" : '');
    }

    public static function created(array $change, int $devices, int $batches): string
    {
        return self::name($change) . ' created by ' . self::user($change['username']) . ': template ' . self::quote($change['template_name']) . ", $devices "
            . ($devices === 1 ? 'device' : 'devices') . " in $batches " . ($batches === 1 ? 'batch' : 'batches');
    }

    public static function dryRunLaunched(array $change, int $runId, string $by): string
    {
        return self::name($change) . ": dry run (run #$runId) launched by " . self::user($by);
    }

    /**
     * A target's result in a dry run that was proven to be one.
     */
    public static function dryRunResult(array $change, int $runId, string $result): string
    {
        return self::name($change) . ", dry run #$runId: " . (self::DRY_RESULTS[$result] ?? $result) . ', template ' . self::quote($change['template_name']);
    }

    /**
     * A dry run that was not proven to be one: it may have changed the device.
     *
     * @param  string[]  $findings
     */
    public static function dryRunUnverified(array $change, int $runId, array $findings): string
    {
        return self::name($change) . ": dry run #$runId is NOT PROVEN to be a dry run, so it may have changed this device: "
            . UntrustedText::line(implode(' ', $findings), 400) . ' It can be neither approved nor applied.';
    }

    public static function dryRunEnded(array $change, int $runId, string $state, array $summary): string
    {
        $counts = [];
        foreach (self::DRY_RESULTS as $result => $label) {
            if (! empty($summary[$result])) {
                $counts[] = $summary[$result] . ' ' . $label;
            }
        }

        return match ($state) {
            'verified' => "Dry run #$runId ended, verified: " . ($counts ? implode(', ', $counts) : 'no device') . '.',
            'unverified' => "Dry run #$runId is not proven to be a dry run: it may have changed the devices. It can be neither approved nor applied.",
            default => "Dry run #$runId ended with failures (" . ($counts ? implode(', ', $counts) : 'no result') . '): it cannot be approved or applied.',
        };
    }

    public static function approvalRequested(array $change): string
    {
        return self::name($change) . ': its dry run was verified, approval requested';
    }

    public static function approved(array $change, string $by, string $comment): string
    {
        return self::name($change) . ' approved by ' . self::user($by) . self::comment($comment);
    }

    public static function rejected(array $change, string $by, string $comment): string
    {
        return self::name($change) . ' rejected by ' . self::user($by) . self::comment($comment);
    }

    /**
     * The approval no longer counts (expired, its approver lost the permission, the settings changed).
     */
    public static function approvalVoid(array $change, string $reason): string
    {
        return self::name($change) . ': its approval no longer counts: ' . UntrustedText::line($reason, 300) . '. It needs a new approval.';
    }

    /**
     * The dry run no longer counts (expired, the template changed, another change was applied to its devices).
     */
    public static function dryRunVoid(array $change, string $reason): string
    {
        return self::name($change) . ': its dry run no longer counts: ' . UntrustedText::line($reason, 300) . '. It needs a new dry run.';
    }

    public static function applyStarted(array $change, string $by, int $batches): string
    {
        return self::name($change) . ': apply started by ' . self::user($by) . " ($batches " . ($batches === 1 ? 'batch' : 'batches') . ')';
    }

    public static function batchLaunched(array $change, int $batch, int $batches, int $runId, string $by): string
    {
        return self::name($change) . ": batch $batch of $batches launched by " . self::user($by) . " (run #$runId)";
    }

    /**
     * @param  array<string, int>  $summary  result => count
     */
    public static function batchEnded(array $change, int $batch, int $batches, int $runId, array $summary, ?string $stop): string
    {
        $counts = [];
        foreach ($summary as $result => $count) {
            $counts[] = "$count " . str_replace('_', ' ', (string) $result);
        }

        return self::name($change) . ": batch $batch of $batches ended (run #$runId" . ($counts ? ': ' . implode(', ', $counts) : '') . ')'
            . ($stop !== null ? ': ' . UntrustedText::line($stop, 300) : '');
    }

    /**
     * The change waits between batches: for an admin, for the secret answers, or for the pause to end.
     */
    public static function waiting(array $change, int $next, string $why, ?int $until = null): string
    {
        return self::name($change) . ": batch $next waits " . match ($why) {
            'canary' => 'for an admin to continue after the first batch',
            'secrets' => 'for an admin to enter the secret answers again (the plugin never keeps them)'
                . ($until !== null ? ' until ' . Clock::text($until) . ', then the change ends' : ''),
            'blast' => 'for an approver to accept its new blast radius (it grew since the approval)',
            'blast_unknown' => 'for an admin to continue it without a blast radius (it cannot be computed now)',
            default => 'until ' . ($until !== null ? Clock::text($until) : 'the pause ends') . ' (pause between batches)',
        };
    }

    /**
     * What a batch's blast radius is held to, in words: [since when, its name]: since an admin accepted it ("accepted"),
     * since the approval ("approved"), or, for a change applied without an approval, since the change was made
     * ("planned").
     *
     * @param  array{approved_at?: ?string}  $change
     * @return array{0: string, 1: string}
     */
    public static function held(array $change, bool $accepted): array
    {
        return match (true) {
            $accepted => ['it was accepted', 'accepted'],
            ($change['approved_at'] ?? null) !== null => ['the approval', 'approved'],
            default => ['the change was made', 'planned'],
        };
    }

    /** What a blast radius measured from the largest piece left takes down, after what it cuts off. */
    public const LARGEST_PART = ', measured from the largest piece left (it takes down every core device of its part)';

    /** A batch that now takes down every core device of its part, while it was held to leave one standing. */
    private const CORE_LOST = 'it now takes down every core device of its part, so what it cuts off is measured from the largest piece left';

    /**
     * How a blast radius grew, in words: "it would now also cut off lab-sw5", "it now takes down every core device of its
     * part, so what it cuts off is measured from the largest piece left[, and it would also cut off lab-sw5]".
     *
     * @param  string  $added  the devices it cuts off now that it was not held to, in words ("" for none)
     */
    public static function grewText(string $added, bool $coreLost): string
    {
        return match (true) {
            $coreLost && $added !== '' => self::CORE_LOST . ', and it would also cut off ' . UntrustedText::line($added, 400),
            $coreLost => self::CORE_LOST,
            default => 'it would now also cut off ' . UntrustedText::line($added, 400),
        };
    }

    /**
     * grewText() in counts, for the eventlog: "it would now cut off 2 more devices than approved".
     */
    public static function grewCount(int $added, bool $coreLost, string $word): string
    {
        $more = 'cut off ' . Format::count($added, 'more device', 'more devices') . " than $word";

        return match (true) {
            $coreLost && $added > 0 => self::CORE_LOST . ", and it would $more",
            $coreLost => self::CORE_LOST,
            default => "it would now $more",
        };
    }

    /**
     * A batch's blast radius grew since the approval (or the acceptance, or the change was made): it does not start.
     *
     * @param  string  $added  the devices it cuts off now that it was not held to, in words ("" for none: it now takes down every core device of its part)
     * @param  string  $held  what it was held to cut off, in words
     * @param  bool  $accepted  the blast radius it is held to was accepted by an admin since
     * @param  string  $accepter  who accepts the new one (ApprovalRules::accepter())
     * @param  bool  $coreLost  it now takes down every core device of its part, while it was held to leave one standing
     */
    public static function blastGrew(array $change, int $batch, string $added, string $held, bool $refused, bool $accepted = false,
        string $accepter = 'an admin with the approve permission', bool $coreLost = false): string
    {
        [$since, $word] = self::held($change, $accepted);

        return self::name($change) . ", batch $batch: its blast radius grew since $since: " . self::grewText($added, $coreLost)
            . " ($word: " . UntrustedText::line($held, 300) . '). '
            . ($refused ? 'The batch is refused (setting When the blast radius grew: refuse the batch).'
                : "The batch does not start until $accepter accepts the new blast radius.");
    }

    /**
     * A batch's blast radius cannot be computed now: it does not start.
     */
    public static function blastUnknown(array $change, int $batch, string $why, bool $refused): string
    {
        return self::name($change) . ", batch $batch: its blast radius cannot be computed now (" . UntrustedText::line($why, 300) . '). '
            . ($refused ? 'The batch is refused (setting When the blast radius cannot be computed: refuse the batch).'
                : 'The batch does not start until its blast radius can be computed again (checked every minute), or an admin continues it, without one '
                    . 'if it still cannot be computed.');
    }

    /**
     * A batch's blast radius shrank since the approval (or the acceptance, or the change was made): it goes on.
     */
    public static function blastShrank(array $change, int $batch, string $removed, bool $accepted = false): string
    {
        return self::name($change) . ", batch $batch: its blast radius shrank since " . self::held($change, $accepted)[0] . ': it no longer cuts off '
            . UntrustedText::line($removed, 400);
    }

    /**
     * An approver accepted a batch's new blast radius.
     *
     * @param  string  $lost  what it cuts off now, in words
     */
    public static function blastAccepted(array $change, int $batch, string $by, string $lost, string $comment): string
    {
        return self::name($change) . ", batch $batch: its new blast radius accepted by " . self::user($by) . ': it cuts off ' . UntrustedText::line($lost, 400)
            . self::comment($comment);
    }

    /**
     * An admin continued a batch whose blast radius cannot be computed.
     *
     * @param  string  $covered  the devices approved as cut off, which its window covers, in words
     */
    public static function blastOverride(array $change, int $batch, string $by, string $why, string $covered, bool $accepted = false): string
    {
        return self::name($change) . ", batch $batch: continued by " . self::user($by) . ' without a blast radius (it cannot be computed: ' . UntrustedText::line($why, 300)
            . '); its maintenance window covers its devices and the devices ' . self::coveredWord($change, $accepted) . ' as cut off: ' . UntrustedText::line($covered, 300);
    }

    /**
     * What the window of a batch continued without a blast radius covers, in words: "approved" (or "planned"), and
     * "or accepted" once a new blast radius was accepted (it covers both).
     */
    private static function coveredWord(array $change, bool $accepted): string
    {
        return self::held($change, false)[1] . ($accepted ? ' or accepted' : '');
    }

    // ------------------------------------------------------------------ the eventlog of phase 3: no other device named
    // LibreNMS shows a device's eventlog to whoever may see that device: its entries say what happened in counts, with the
    // change and batch numbers, and never name another device (the change page, its timeline and the window's notes do)

    /**
     * Devices in words, by their count: "2 devices and 3 unmanaged neighbors", "no other monitored device".
     */
    public static function devicesCount(int $devices, int $unmanaged = 0): string
    {
        $text = $devices > 0 ? Format::count($devices, 'device') : 'no other monitored device';

        return $text . ($unmanaged > 0 ? ($devices > 0 ? ' and ' : ', and ') . Format::count($unmanaged, 'unmanaged neighbor') : '');
    }

    /**
     * Eventlog entry of a batch whose blast radius grew: it does not start.
     *
     * @param  int  $added  the devices it would now cut off that it was not held to
     * @param  int  $lost  the monitored devices it would cut off in all
     * @param  bool  $coreLost  as for blastGrew()
     */
    public static function blastGrewEvent(array $change, int $batch, int $added, int $lost, int $unmanaged, bool $refused, bool $accepted, string $accepter,
        bool $coreLost = false): string
    {
        [$since, $word] = self::held($change, $accepted);

        return self::name($change) . ", batch $batch: its blast radius grew since $since: " . self::grewCount($added, $coreLost, $word)
            . ' (' . self::devicesCount($lost, $unmanaged) . ' in all). '
            . ($refused ? 'The batch is refused (setting When the blast radius grew: refuse the batch).'
                : "The batch does not start until $accepter accepts the new blast radius.");
    }

    /**
     * Eventlog entry of a batch whose blast radius shrank: it goes on.
     */
    public static function blastShrankEvent(array $change, int $batch, int $removed, bool $accepted): string
    {
        return self::name($change) . ", batch $batch: its blast radius shrank since " . self::held($change, $accepted)[0] . ': it cuts off '
            . Format::count($removed, 'device fewer', 'devices fewer') . '; it goes on';
    }

    /**
     * Eventlog entry of an admin's acceptance of a batch's new blast radius.
     */
    public static function blastAcceptedEvent(array $change, int $batch, string $by, int $lost, int $unmanaged, string $comment): string
    {
        return self::name($change) . ", batch $batch: its new blast radius accepted by " . self::user($by) . ': it cuts off ' . self::devicesCount($lost, $unmanaged)
            . self::comment($comment);
    }

    /**
     * Eventlog entry of an admin's decision to continue a batch whose blast radius cannot be computed.
     *
     * @param  int  $covered  the devices approved (or accepted) as cut off, which its window covers
     */
    public static function blastOverrideEvent(array $change, int $batch, string $by, string $why, int $covered, bool $accepted): string
    {
        return self::name($change) . ", batch $batch: continued by " . self::user($by) . ' without a blast radius (it cannot be computed: ' . UntrustedText::line($why, 300)
            . '); its maintenance window covers its devices and ' . ($covered > 0 ? 'the ' . Format::count($covered, 'device') . ' ' . self::coveredWord($change, $accepted)
                . ' as cut off' : 'no other device');
    }

    /**
     * Eventlog entry of a device a batch cuts off, when the batch's maintenance window starts to cover it (it names the
     * batch by its number only).
     */
    public static function windowCutOff(array $change, int $batch, int $scheduleId, int $until): string
    {
        return self::name($change) . ", batch $batch cuts this device off from the core while it runs: maintenance window #$scheduleId covers it until "
            . Clock::text($until);
    }

    /**
     * Eventlog entry of a device a batch no longer cuts off, taken out of its window before the batch's launch.
     */
    public static function windowUncovered(array $change, int $batch, int $scheduleId): string
    {
        return self::name($change) . ", batch $batch: maintenance window #$scheduleId no longer covers this device: the batch no longer cuts it off";
    }

    /**
     * Eventlog entry of a target Oxidized cannot back up, at the dry run (BackupCheck).
     */
    public static function backupCheck(array $change, string $check, bool $refuse): string
    {
        return self::name($change) . ': ' . ($check === BackupCheck::EXCLUDED ? 'LibreNMS leaves this device out of Oxidized' : 'Oxidized has no node for this device')
            . ', so its batch ' . ($refuse ? 'will be refused (setting Without a fresh backup: refuse)' : 'will go without a backup (setting Without a fresh backup: continue with a warning)');
    }

    public static function windowOpened(array $change, int $batch, int $scheduleId, int $until): string
    {
        return self::name($change) . ", batch $batch: maintenance window #$scheduleId opened until " . Clock::text($until);
    }

    public static function windowClosed(array $change, int $batch, int $scheduleId, int $at): string
    {
        return self::name($change) . ", batch $batch: maintenance window #$scheduleId closed at " . Clock::text($at);
    }

    public static function windowGone(array $change, int $batch, int $scheduleId): string
    {
        return self::name($change) . ", batch $batch: maintenance window #$scheduleId was removed in LibreNMS before the plugin closed it";
    }

    /**
     * Eventlog entry of a target Oxidized backed up before or after its batch, with the version it keeps.
     */
    public static function backedUp(array $change, int $batch, string $phase, ?string $version): string
    {
        return self::name($change) . ", batch $batch: Oxidized backed this device up " . ($phase === 'before' ? 'before' : 'after') . ' the batch'
            . ($version !== null && $version !== '' ? ' (version ' . UntrustedText::line(substr($version, 0, 40), 40) . ')' : '');
    }

    public static function backupFailed(array $change, int $batch, string $phase, string $state, ?string $error): string
    {
        return self::name($change) . ", batch $batch: no fresh backup " . ($phase === 'before' ? 'before' : 'after') . ' the batch ('
            . (BackupState::LABELS[$state] ?? $state) . ($error !== null && $error !== '' ? ': ' . UntrustedText::line($error, 200) : '') . ')';
    }

    public static function backupMessage(array $change, int $batch, string $phase): string
    {
        return 'LibreNMS ' . self::name($change) . ', ' . ($phase === 'before' ? 'before' : 'after') . " batch $batch";
    }

    public static function stopRequested(array $change, string $by): string
    {
        return self::name($change) . ': stop requested by ' . self::user($by);
    }

    /**
     * The end of a change, for the eventlog of its targets.
     */
    public static function finished(array $change, string $status, ?string $reason): string
    {
        $verb = match ($status) {
            ChangeStatus::APPLIED => 'applied',
            ChangeStatus::FAILED => 'failed',
            ChangeStatus::STOPPED => 'was stopped',
            ChangeStatus::REJECTED => 'was rejected',
            ChangeStatus::CANCELLED => 'was cancelled',
            default => 'ended',
        };
        $reason = $reason !== null && $reason !== '' ? UntrustedText::line($reason, 400) : null;
        // "was stopped by alice after batch 1", not "was stopped: stopped by alice after batch 1"
        $word = substr($verb, strrpos($verb, ' ') !== false ? strrpos($verb, ' ') + 1 : 0);
        if ($reason !== null && str_starts_with($reason, "$word ")) {
            return self::name($change) . ' ' . $verb . substr($reason, strlen($word)) . ', template ' . self::quote($change['template_name']);
        }
        $text = self::name($change) . " $verb, template " . self::quote($change['template_name']);

        return $reason !== null ? $text . ': ' . $reason : $text;
    }

    /**
     * How a change ended, as its page and timeline say it: "Stopped by alice after batch 1", "Failed: batch 2 was
     * refused: ...", "Applied." (a reason that starts with the status's own word is not said twice).
     */
    public static function outcome(string $status, ?string $reason): string
    {
        $label = ChangeStatus::label($status);
        if ($reason === null || $reason === '') {
            return ucfirst($label) . '.';
        }
        if (str_starts_with(strtolower($reason), strtolower($label) . ' ')) {
            return ucfirst($reason) . (preg_match('/[.!?]$/', $reason) ? '' : '.');
        }

        return ucfirst($label) . ': ' . $reason . (preg_match('/[.!?)"]$/', $reason) ? '' : '.');
    }

    public static function cancelled(array $change, string $by): string
    {
        return self::name($change) . ' cancelled by ' . self::user($by);
    }

    /**
     * The title of a batch's maintenance window: "Ansible change #12, batch 2: Port descriptions".
     */
    public static function windowTitle(array $change, int $batch): string
    {
        return mb_substr('Ansible change #' . (int) $change['id'] . ", batch $batch: " . UntrustedText::line($change['template_name'], 160), 0, 255);
    }

    /**
     * The notes of a batch's maintenance window: who opened it, why, the batch's devices and those it cuts off, and
     * where to look.
     *
     * @param  string[]  $devices  the batch's devices (display names)
     * @param  string[]  $cutOff  the monitored devices the batch cuts off, which the window covers too (display names)
     */
    public static function windowNotes(array $change, int $batch, int $batches, array $devices, string $url, array $cutOff = []): string
    {
        return 'Opened by the LibreNMS Ansible plugin for ' . self::name($change) . ", batch $batch of $batches (template "
            . self::quote($change['template_name']) . ', requested by ' . self::user($change['username']) . '): '
            . UntrustedText::line(implode(', ', array_slice($devices, 0, 20)), 600) . (count($devices) > 20 ? ', ...' : '')
            . ($cutOff ? '. Also the devices the batch cuts off (no path to the core while it runs): '
                . UntrustedText::line(implode(', ', array_slice($cutOff, 0, 20)), 600) . (count($cutOff) > 20 ? ', ...' : '') : '')
            . ". The plugin closes it after the batch. $url";
    }

    private static function user(mixed $user): string
    {
        return UntrustedText::line($user, 60);
    }

    private static function quote(mixed $name): string
    {
        return "'" . UntrustedText::line($name, 100) . "'";
    }

    /**
     * An admin's comment as the eventlog entries of a step quote it (as written, one line, cut): ': "..."', nothing without one.
     */
    public static function comment(string $comment): string
    {
        $comment = UntrustedText::line($comment, 300);

        return $comment !== '' ? ": \"$comment\"" : '';
    }
}
