<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Changes\ChangeLifecycle;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Changes\ConfigBackups;
use Agoodley\LibrenmsAnsible\Changes\LockLost;
use Agoodley\LibrenmsAnsible\Changes\MaintenanceWindows;
use Agoodley\LibrenmsAnsible\Changes\Topology;
use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Runs\RunLifecycle;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Support\ErrorText;
use Agoodley\LibrenmsAnsible\Support\LockKeeper;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Follows changes through their lifecycle (Changes\ChangeLifecycle), for the change page and the sync command, and
 * carries out users' actions on them. Only the sync command's follower takes the states of the batches' verification
 * ($takesStates); a page's request leaves them to it. One lock per change keeps two requests from moving the same change at once:
 * the sync command, page polls, Stop, Approve and Continue all take it (a poll skips a change another request holds,
 * an action waits a few seconds for it). The lock is short and renewed during long work; a step whose lock another
 * request took meanwhile stops there (Changes\LockLost), so what is not a compare-and-set (a window opened, a backup
 * asked for) is done once. Inside, every write is a compare-and-set, and a batch's run has a one-time token, so even
 * without the lock a batch would not be launched twice.
 */
final class ChangeFollower
{
    /** Least seconds between two refreshes of a change by page polls. */
    public const INTERVAL = 2;

    /** Seconds a user's action waits for the change's lock. */
    private const WAIT = 15;

    /**
     * Most runner calls a step makes between two heartbeats: a batch's launch checks the token (info, project, role, user),
     * reads the template, the inventory, the variable groups and the repository, and asks the runner to start the task (10),
     * with a margin. The change's lock outlasts them, each up to the connection's timeout.
     */
    public const LAUNCH_CALLS = 12;

    public function __construct(
        private readonly Plugin $plugin,
        private readonly RunRepository $runs,
        private readonly ChangeRepository $changes,
        private readonly PluginSettings $settings,
        private readonly InventoryService $inventory,
        private readonly Eventlog $eventlog = new Eventlog(),
        private readonly ?ConfigBackups $backups = null,
        private readonly ?MaintenanceWindows $windows = null,
        private readonly ?Topology $topology = null,
        private readonly bool $takesStates = false,
        private readonly ?\Agoodley\LibrenmsAnsible\Changes\Verifier $verifier = null,
        private readonly ?\Agoodley\LibrenmsAnsible\Changes\DataRefresh $refresh = null,
    ) {
    }

    /**
     * The lifecycle of the plugin's changes, with the stored settings.
     */
    public function lifecycle(): ChangeLifecycle
    {
        $url = function_exists('url') ? rtrim((string) url('plugin/ansible/changes'), '/') . '/%d' : 'plugin/ansible/changes/%d';

        // the batches' verification: the states are taken by the sync command only ($takesStates), each in a process of its own
        return new ChangeLifecycle($this->changes, $this->runs, new ChangeActions($this->plugin, $this->runs, $this->changes, $this->inventory, $this->settings, $this->eventlog),
            $this->backups ?? new OxidizedBackups(), $this->windows ?? new LibrenmsMaintenance(), $this->eventlog, $this->settings->changeSettings($url),
            $this->topology ?? new TopologyPlanning(), $this->verifier ?? new TopologyVerification($this->takesStates), $this->refresh ?? new LibrenmsRefresh());
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
        // a run that ended (and is not watched for a late end) needs nothing from the runner: its log is not read for that
        $light = $runId !== null ? ($this->runs->summaries([$runId])[$runId] ?? null) : null;
        if ($light !== null && (! RunStatus::isFinal($light['status']) || RunLifecycle::watched($light, time()))) {
            (new RunFollower($this->plugin, $this->runs, $this->settings, $this->eventlog, null, $this->inventory))->refresh($runId, $throttle);
        }
        $lock = Cache::lock("ansible-plugin-change-$id", $this->lockSeconds());
        if (! $lock->get()) {
            return $change;
        }
        try {
            return $this->held($lock)->advance($id, time()) ?? $change;
        } catch (LockLost $e) {
            Log::warning("ansible plugin: following change #$id stopped: " . $e->getMessage());

            return $this->changes->find($id) ?? $change;
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
            return $action($this->held($lock));
        } catch (LockLost $e) {
            Log::warning("ansible plugin: an action on change #$id stopped: " . $e->getMessage());

            return 'Another request took over this change while the plugin worked on it: what was done is recorded, and the change goes on from there. '
                . 'Reload the page.';
        } finally {
            $lock->release();
        }
    }

    /**
     * Stop a change without its lock (Changes\ChangeLifecycle::stop()): the emergency brake works while another request
     * is busy with the change, or died holding its lock. The change then moves on at once when its lock is free, else
     * at its follower's next step. Null when the stop was asked for, else why not.
     */
    public function stop(int $id, string $username): ?string
    {
        $error = $this->lifecycle()->stop($id, $username, time());
        if ($error === null) {
            $this->refresh($id, false);
        }

        return $error;
    }

    /**
     * The lifecycle for a request that holds the change's lock: during long work it renews the lock, so the lock can be
     * short (a request that dies holds the change only that long).
     */
    private function held(\Illuminate\Contracts\Cache\Lock $lock): ChangeLifecycle
    {
        $lifecycle = $this->lifecycle();
        $seconds = $this->lockSeconds();
        // a step whose lock another request took meanwhile stops before its next call to the runner, Oxidized or LibreNMS'
        // maintenance (LockLost): the writes that are not a compare-and-set (a window, a backup request) are not made twice
        $lifecycle->setHeartbeat(fn () => LockKeeper::renew($lock, $seconds));

        return $lifecycle;
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
     * The run the change page shows in its Log panel: the dry run until the apply starts; then the current batch's run,
     * or none while that batch has none yet (it prepares its window and backups, waits for the secret answers, is being
     * launched: the panel says so, rather than show the dry run's or an earlier batch's end as if the apply had ended);
     * after the change's end, the last batch that ran.
     *
     * @param  array<int, array>  $batches  the change's batches (ChangeStore::batches())
     */
    public static function shownRun(array $change, array $batches): ?int
    {
        $dryRun = $change['dry_run_id'] !== null ? (int) $change['dry_run_id'] : null;
        if ($change['status'] === ChangeStatus::DRY_RUN || ($change['apply_started_at'] ?? null) === null) {
            return $dryRun;
        }
        $number = (int) $change['current_batch'];
        if (($batches[$number]['run_id'] ?? null) !== null) {
            return (int) $batches[$number]['run_id'];
        }
        if (! ChangeStatus::isFinal($change['status'])) {
            return null;
        }
        for ($number--; $number >= 1; $number--) {
            if (($batches[$number]['run_id'] ?? null) !== null) {
                return (int) $batches[$number]['run_id'];
            }
        }

        return null;
    }

    /**
     * How long the lock of a change is held at most, renewed during long work (each launch, each call to Oxidized): the
     * longest work between two renewals, a launch's calls to the runner (each may take the whole timeout).
     */
    private function lockSeconds(): int
    {
        return self::lockSecondsFor($this->plugin->connection()->timeout);
    }

    /**
     * The change's lock's time for a connection timeout: longer than a refresh of its run (RunFollower), a launch's runner
     * calls between two heartbeats (LAUNCH_CALLS) and three calls to Oxidized.
     */
    public static function lockSecondsFor(int $timeout): int
    {
        return max(RunFollower::lockSecondsFor($timeout), self::LAUNCH_CALLS * $timeout + 60, 3 * OxidizedBackups::TIMEOUT + 30);
    }
}
