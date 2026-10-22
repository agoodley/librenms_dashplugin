<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Support\Clock;

/**
 * Texts the plugin writes about a change outside its page: the eventlog of each target and the change's timeline.
 * They name the change, the template and the users, never an answer (answers can be secret). Free text from users (a
 * change's reference, an approval's comment) and from the runner is cleaned and cut; LibreNMS and the plugin's pages
 * escape what they show. Times carry their zone.
 */
final class ChangeText
{
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
                . ($until !== null ? ' until ' . Clock::show($until, 'Y-m-d H:i T') . ', then the change ends' : ''),
            default => 'until ' . ($until !== null ? Clock::show($until, 'Y-m-d H:i T') : 'the pause ends') . ' (pause between batches)',
        };
    }

    public static function windowOpened(array $change, int $batch, int $scheduleId, int $until): string
    {
        return self::name($change) . ", batch $batch: maintenance window #$scheduleId opened until " . Clock::show($until, 'Y-m-d H:i T');
    }

    public static function windowClosed(array $change, int $batch, int $scheduleId, int $at): string
    {
        return self::name($change) . ", batch $batch: maintenance window #$scheduleId closed at " . Clock::show($at, 'Y-m-d H:i T');
    }

    public static function windowGone(array $change, int $batch, int $scheduleId): string
    {
        return self::name($change) . ", batch $batch: maintenance window #$scheduleId was removed in LibreNMS before the plugin closed it";
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
        $text = self::name($change) . ' ' . match ($status) {
            ChangeStatus::APPLIED => 'applied',
            ChangeStatus::FAILED => 'failed',
            ChangeStatus::STOPPED => 'was stopped',
            ChangeStatus::REJECTED => 'was rejected',
            ChangeStatus::CANCELLED => 'was cancelled',
            default => 'ended',
        } . ', template ' . self::quote($change['template_name']);

        return $reason !== null && $reason !== '' ? $text . ': ' . UntrustedText::line($reason, 400) : $text;
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
     * The notes of a batch's maintenance window: who opened it, why, and where to look.
     *
     * @param  string[]  $devices  the batch's devices (display names)
     */
    public static function windowNotes(array $change, int $batch, int $batches, array $devices, string $url): string
    {
        return 'Opened by the LibreNMS Ansible plugin for ' . self::name($change) . ", batch $batch of $batches (template "
            . self::quote($change['template_name']) . ', requested by ' . self::user($change['username']) . '): '
            . UntrustedText::line(implode(', ', array_slice($devices, 0, 20)), 600) . (count($devices) > 20 ? ', ...' : '')
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

    private static function comment(string $comment): string
    {
        $comment = UntrustedText::line($comment, 300);

        return $comment !== '' ? ": \"$comment\"" : '';
    }
}
