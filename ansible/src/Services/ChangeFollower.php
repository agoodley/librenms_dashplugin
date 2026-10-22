<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Changes\ChangeLifecycle;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Changes\ConfigBackups;
use Agoodley\LibrenmsAnsible\Changes\MaintenanceWindows;
use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Support\ErrorText;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Follows changes through their lifecycle (Changes\ChangeLifecycle), for the change page and the sync command, and
 * carries out users' actions on them. One lock per change keeps two requests from moving the same change at once:
 * the sync command, page polls, Stop, Approve and Continue all take it (a poll skips a change another request holds,
 * an action waits a few seconds for it). Inside, every write is a compare-and-set, and a batch's run has a one-time
 * token, so even without the lock a batch would not be launched twice.
 */
final class ChangeFollower
{
    /** Least seconds between two refreshes of a change by page polls. */
    public const INTERVAL = 2;

    /** Seconds a user's action waits for the change's lock. */
    private const WAIT = 15;

    public function __construct(
        private readonly Plugin $plugin,
        private readonly RunRepository $runs,
        private readonly ChangeRepository $changes,
        private readonly PluginSettings $settings,
        private readonly InventoryService $inventory,
        private readonly Eventlog $eventlog = new Eventlog(),
        private readonly ?ConfigBackups $backups = null,
        private readonly ?MaintenanceWindows $windows = null,
    ) {
    }

    /**
     * The lifecycle of the plugin's changes, with the stored settings.
     */
    public function lifecycle(): ChangeLifecycle
    {
        $url = function_exists('url') ? rtrim((string) url('plugin/ansible/changes'), '/') . '/%d' : 'plugin/ansible/changes/%d';

        return new ChangeLifecycle($this->changes, $this->runs, new ChangeActions($this->plugin, $this->runs, $this->changes, $this->inventory, $this->settings, $this->eventlog),
            $this->backups ?? new OxidizedBackups(), $this->windows ?? new LibrenmsMaintenance(), $this->eventlog, $this->settings->changeSettings($url));
    }

    /**
     * Refresh a change: the run it follows now (its dry run or batch) from the runner, then the change one step, unless
     * another request is at it or a page refreshed it less than INTERVAL seconds ago. Returns the change as stored
     * afterwards, null when there is none.
     */
    public function refresh(int $id, bool $throttle = true): ?array
    {
        $change = $this->changes->find($id);
        if ($change === null) {
            return null;
        }
        if ($throttle && ! Cache::add("ansible-plugin-change-polled-$id", 1, self::INTERVAL)) {
            return $change;
        }
        $runId = $this->currentRun($change);
        if ($runId !== null) {
            (new RunFollower($this->plugin, $this->runs, $this->settings, $this->eventlog, null, $this->inventory))->refresh($runId, $throttle);
        }
        $lock = Cache::lock("ansible-plugin-change-$id", $this->lockSeconds());
        if (! $lock->get()) {
            return $change;
        }
        try {
            return $this->lifecycle()->advance($id, time()) ?? $change;
        } catch (\Throwable $e) {
            Log::error("ansible plugin: following change #$id failed: " . ErrorText::forLog($e));

            return $this->changes->find($id) ?? $change;
        } finally {
            $lock->release();
        }
    }

    /**
     * Carry out a user's action on a change under its lock; null when it was done, else why not.
     *
     * @param  \Closure(ChangeLifecycle): ?string  $action
     */
    public function act(int $id, \Closure $action): ?string
    {
        $lock = Cache::lock("ansible-plugin-change-$id", $this->lockSeconds());
        try {
            $lock->block(self::WAIT);
        } catch (LockTimeoutException) {
            return 'The plugin is working on this change right now (a launch or a refresh): try again in a moment.';
        }
        try {
            return $action($this->lifecycle());
        } finally {
            $lock->release();
        }
    }

    /**
     * Does the change still need the sync command (unfinished, or a window or backup it follows)?
     */
    public function pending(int $id): bool
    {
        return $this->lifecycle()->pending($id);
    }

    /**
     * The run the change follows now, or its last one: its dry run while it runs, else the run of its current batch or
     * of the last batch before it that has one, else its dry run.
     */
    public function currentRun(array $change): ?int
    {
        $dryRun = $change['dry_run_id'] !== null ? (int) $change['dry_run_id'] : null;
        if ($change['status'] === ChangeStatus::DRY_RUN) {
            return $dryRun;
        }
        $batches = $this->changes->batches((int) $change['id']);
        for ($number = (int) $change['current_batch']; $number >= 1; $number--) {
            if (($batches[$number]['run_id'] ?? null) !== null) {
                return (int) $batches[$number]['run_id'];
            }
        }

        return $dryRun;
    }

    /**
     * How long the lock of a change is held at most: longer than a launch (each runner call may take the whole timeout)
     * and the calls to Oxidized of one refresh.
     */
    private function lockSeconds(): int
    {
        return 2 * RunFollower::lockSecondsFor($this->plugin->connection()->timeout) + 300;
    }
}
