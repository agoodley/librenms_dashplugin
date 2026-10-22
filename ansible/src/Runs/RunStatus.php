<?php

namespace Agoodley\LibrenmsAnsible\Runs;

/**
 * Status of a run in the plugin's terms, whatever the runner calls it. A run is unfinished (its targets are busy,
 * the sync command follows it) until it reaches a final status, and it only gets one on clear evidence: the
 * runner's own final status for its task, a stop the plugin asked for and the runner confirmed, a launch the runner
 * refused, a task the runner keeps not finding while the token still sees the project, or an admin who released
 * the devices. Lost and released runs are still watched for a while, so a later real end is recorded.
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

    /** The runner ended the task with success, and every target is in the PLAY RECAP without a failure. */
    public const SUCCESS = 'success';

    /** The runner ended the task with success, but some targets did not run (or failed): see the run's alert. */
    public const INCOMPLETE = 'incomplete';

    /** The runner ended the task with an error (a host failed or was unreachable, or the playbook could not start). */
    public const FAILED = 'failed';

    public const STOPPED = 'stopped';

    /** The runner keeps not finding the task while the token still sees the project (it was deleted). */
    public const LOST = 'lost';

    /** The runner could not be asked about the run for long, and an admin released its devices. */
    public const RELEASED = 'released';

    /** The runner refused the launch, or has no task for a launch whose answer never came: nothing ran. */
    public const NOT_LAUNCHED = 'not_launched';

    public const UNFINISHED = [self::PENDING, self::QUEUED, self::RUNNING, self::STOPPING];

    public const FINAL = [self::SUCCESS, self::INCOMPLETE, self::FAILED, self::STOPPED, self::LOST, self::RELEASED, self::NOT_LAUNCHED];

    /** Final statuses that do not come from the runner's end of the task: the run is still checked for a late end. */
    public const WATCHED = [self::LOST, self::RELEASED];

    /** Labels for the pages. */
    public const LABELS = [
        self::PENDING => 'Launching', self::QUEUED => 'Queued', self::RUNNING => 'Running', self::STOPPING => 'Stopping',
        self::SUCCESS => 'Success', self::INCOMPLETE => 'Incomplete', self::FAILED => 'Failed', self::STOPPED => 'Stopped', self::LOST => 'Lost',
        self::RELEASED => 'Released', self::NOT_LAUNCHED => 'Not launched',
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
            self::INCOMPLETE, self::STOPPED, self::STOPPING, self::RELEASED => 'warning',
            default => 'info',
        };
    }
}
