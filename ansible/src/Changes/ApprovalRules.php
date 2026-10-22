<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * Who may approve a change, and how long an approval counts. The settings ask for no approval, an approval, or an
 * approval from a second admin (not the requester). Approvers are LibreNMS admins who hold the plugin's approve
 * permission (a direct permission, like the run permission). Only a change whose dry run was verified without
 * failures can be approved or rejected; an approval counts for the Approval valid setting, and only while its approver
 * still holds the permission and, with a second admin required, is not the requester.
 */
final class ApprovalRules
{
    public const OFF = 'off';

    public const REQUIRED = 'required';

    public const SECOND = 'second';

    public const MODES = [self::OFF, self::REQUIRED, self::SECOND];

    public const LABELS = [self::OFF => 'no approval', self::REQUIRED => 'an approval', self::SECOND => 'an approval from a second admin'];

    /**
     * Who a change awaiting approval waits for, as its page says it.
     */
    public static function waitsFor(string $mode): string
    {
        return $mode === self::SECOND ? 'Waits for an approval by a second admin with the approve permission (not the requester).'
            : 'Waits for an approval by an admin with the approve permission.';
    }

    /**
     * Does a change need an approval before it is applied?
     */
    public static function needed(string $mode): bool
    {
        return $mode !== self::OFF;
    }

    /**
     * Why the user may not approve (or reject) the change now; null when they may.
     *
     * @param  array{status: string, user_id: int|string|null}  $change
     * @param  bool  $holds  the user is an admin holding the approve permission
     */
    public static function refusal(string $mode, array $change, int $userId, bool $holds, bool $reject = false): ?string
    {
        if (! $holds) {
            return 'Approving and rejecting changes needs the Ansible plugin\'s approve permission (ansible.approve): an admin grants it on the plugin\'s settings page.';
        }
        if ($change['status'] !== ChangeStatus::AWAITING) {
            return match ($change['status']) {
                ChangeStatus::DRAFT, ChangeStatus::DRY_RUN => 'Only a change whose dry run was verified without failures can be approved or rejected.',
                ChangeStatus::READY => self::needed($mode) ? 'The change is approved already.' : 'The settings need no approval: the change can be applied as it is.',
                default => 'The change is ' . strtolower(ChangeStatus::label($change['status'])) . ': it can no longer be approved or rejected.',
            };
        }
        if (! $reject && $mode === self::SECOND && $change['user_id'] !== null && (int) $change['user_id'] === $userId) {
            return 'The settings need the approval of a second admin: you requested this change, so another admin with the approve permission approves it.';
        }

        return null;
    }

    /**
     * Why the change's approval does not count now; null when it does (or none is needed).
     *
     * @param  array{user_id: int|string|null, approved_user_id: int|string|null, approved_at: ?string}  $change
     * @param  string|null  $approverProblem  why the approver no longer may approve (no longer an admin with the permission)
     */
    public static function problem(string $mode, array $change, int $validSeconds, int $now, ?string $approverProblem): ?string
    {
        if (! self::needed($mode)) {
            return null;
        }
        if (($change['approved_at'] ?? null) === null) {
            return 'it has not been approved';
        }
        $at = \Agoodley\LibrenmsAnsible\Support\Clock::unix((string) $change['approved_at']);
        if ($at === null || $now - $at > $validSeconds) {
            return 'its approval expired (Approval valid setting)';
        }
        if ($mode === self::SECOND && $change['approved_user_id'] !== null && (int) $change['approved_user_id'] === (int) $change['user_id']) {
            return 'the settings need the approval of a second admin, and it was approved by its requester';
        }
        if ($approverProblem !== null) {
            $approver = \Agoodley\LibrenmsAnsible\Inventory\UntrustedText::line((string) ($change['approved_by'] ?? ''), 60);

            return 'its approver ' . ($approver !== '' ? $approver . ' ' : '') . $approverProblem;
        }

        return null;
    }
}
