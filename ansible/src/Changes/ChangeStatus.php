<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * Status of a change. A change is prepared (draft), dry-run, approved when the settings ask for it, and applied in
 * batches; it ends applied, failed (a batch failed or a check stopped it before a batch), stopped (by an admin),
 * rejected or cancelled. A draft has no valid dry run: none yet, or one that failed, was not proven to be a dry run,
 * expired, or was voided (the template changed, another change was applied to its devices). From the first batch to
 * its end a change reserves its devices: no other change may take them in between.
 */
final class ChangeStatus
{
    public const DRAFT = 'draft';

    /** The dry run is launched and followed. */
    public const DRY_RUN = 'dry_run';

    /** The dry run was verified and clean; an approval is needed. */
    public const AWAITING = 'awaiting_approval';

    /** The dry run was verified and clean, and approved (or the settings need no approval): it may be applied. */
    public const READY = 'ready';

    /** A batch is being prepared (maintenance window, backups), launched or followed. */
    public const APPLYING = 'applying';

    /**
     * Waiting between two batches (WAITS): for an admin to continue, for the secret answers, for the pause to end, before
     * a batch whose blast radius grew (until an admin accepts it) or cannot be computed, or after a batch whose
     * verification found unexpected changes or could not be made (until an admin accepts it).
     */
    public const PAUSED = 'paused';

    /** A stop was asked for: the running batch is being stopped. */
    public const STOPPING = 'stopping';

    public const APPLIED = 'applied';

    public const FAILED = 'failed';

    public const STOPPED = 'stopped';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const FINAL = [self::APPLIED, self::FAILED, self::STOPPED, self::REJECTED, self::CANCELLED];

    /** Statuses in which the change holds its devices for its batches. */
    public const RESERVING = [self::APPLYING, self::PAUSED, self::STOPPING];

    /** Statuses before the apply. */
    public const PREPARING = [self::DRAFT, self::DRY_RUN, self::AWAITING, self::READY];

    /** What a paused change waits for (its waiting column), as the lists say it. */
    public const WAITS = [
        'canary' => 'waits for an admin',
        'secrets' => 'waits for the secret answers',
        'timer' => 'pause',
        'blast' => 'waits for its new blast radius to be accepted',
        'blast_unknown' => 'waits: its blast radius cannot be computed',
        'verification' => 'waits for the unexpected changes of a batch to be accepted',
        'verify_unknown' => 'waits: a batch cannot be verified',
    ];

    public const LABELS = [
        self::DRAFT => 'Draft', self::DRY_RUN => 'Dry run', self::AWAITING => 'Awaiting approval', self::READY => 'Ready to apply',
        self::APPLYING => 'Applying', self::PAUSED => 'Paused', self::STOPPING => 'Stopping', self::APPLIED => 'Applied', self::FAILED => 'Failed',
        self::STOPPED => 'Stopped', self::REJECTED => 'Rejected', self::CANCELLED => 'Cancelled',
    ];

    /**
     * Has the change ended?
     */
    public static function isFinal(string $status): bool
    {
        return in_array($status, self::FINAL, true);
    }

    /**
     * The label of a status.
     */
    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst($status);
    }

    /**
     * What a change waits for, in words; null when it waits for nothing (a value of another version: "waits").
     */
    public static function waitingText(?string $waiting): ?string
    {
        return $waiting === null || $waiting === '' ? null : (self::WAITS[$waiting] ?? 'waits');
    }

    /**
     * Bootstrap class of a status label.
     */
    public static function css(string $status): string
    {
        return match ($status) {
            self::APPLIED => 'success',
            self::FAILED, self::REJECTED => 'danger',
            self::STOPPED, self::STOPPING, self::PAUSED, self::AWAITING => 'warning',
            self::READY, self::APPLYING, self::DRY_RUN => 'info',
            default => 'default',
        };
    }
}
