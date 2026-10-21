<?php

namespace Agoodley\LibrenmsAnsible\Runs;

/**
 * Status of a run in the plugin's terms, whatever the runner calls it. A run is unfinished (its targets are
 * busy, the sync command follows it) until it reaches a final status.
 */
final class RunStatus
{
    /** Recorded by the plugin, the runner was not asked yet (or the answer never came). */
    public const PENDING = 'pending';

    /** The runner has the task but has not started it. */
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    /** A stop was asked for. */
    public const STOPPING = 'stopping';

    public const SUCCESS = 'success';

    /** The runner ended the task with an error (a host failed or was unreachable, or the playbook could not start). */
    public const FAILED = 'failed';

    public const STOPPED = 'stopped';

    /** The runner no longer knows the task, or it stayed unfinished past the timeout. */
    public const LOST = 'lost';

    /** The runner refused or could not be reached at launch: nothing ran. */
    public const NOT_LAUNCHED = 'not_launched';

    public const UNFINISHED = [self::PENDING, self::QUEUED, self::RUNNING, self::STOPPING];

    public const FINAL = [self::SUCCESS, self::FAILED, self::STOPPED, self::LOST, self::NOT_LAUNCHED];

    /** Labels for the pages. */
    public const LABELS = [
        self::PENDING => 'Launching', self::QUEUED => 'Queued', self::RUNNING => 'Running', self::STOPPING => 'Stopping',
        self::SUCCESS => 'Success', self::FAILED => 'Failed', self::STOPPED => 'Stopped', self::LOST => 'Lost',
        self::NOT_LAUNCHED => 'Not launched',
    ];

    /**
     * Semaphore UI task statuses (2.19). "rejected" is a confirmation that was refused: the task never runs.
     */
    private const SEMAPHORE = [
        'waiting' => self::QUEUED, 'starting' => self::QUEUED, 'waiting_confirmation' => self::QUEUED, 'confirmed' => self::QUEUED,
        'running' => self::RUNNING, 'stopping' => self::STOPPING,
        'success' => self::SUCCESS, 'error' => self::FAILED, 'rejected' => self::FAILED, 'stopped' => self::STOPPED,
    ];

    /**
     * The plugin's status of a Semaphore task status; an unknown one counts as running, so the run is followed
     * on (and ends as lost at the timeout if it never finishes).
     */
    public static function fromSemaphore(string $status): string
    {
        return self::SEMAPHORE[strtolower(trim($status))] ?? self::RUNNING;
    }

    /**
     * Has the run ended?
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
     * Bootstrap class of a status label.
     */
    public static function css(string $status): string
    {
        return match ($status) {
            self::SUCCESS => 'success',
            self::FAILED, self::LOST, self::NOT_LAUNCHED => 'danger',
            self::STOPPED, self::STOPPING => 'warning',
            default => 'info',
        };
    }
}
