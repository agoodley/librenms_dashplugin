<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Runner\Runner;
use Agoodley\LibrenmsAnsible\Runner\Semaphore\SemaphoreRunner;
use Agoodley\LibrenmsAnsible\Runs\RunLifecycle;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Support\ErrorText;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Follows runs on the runner through their lifecycle (Runs\RunLifecycle), for the run page and the sync command: a
 * lock per run keeps two requests from asking the runner about the same run at once (its TTL outlasts the longest
 * refresh), a run is asked at most once every INTERVAL seconds, and every write is a compare-and-set, so a refresh
 * that took long never writes over a newer one.
 */
final class RunFollower
{
    /** Least seconds between two refreshes of a run from the runner. */
    public const INTERVAL = 2;

    /**
     * Most runner calls one refresh makes: task, output, role or stop, and the whole log at the end; or a search for
     * a launch's task (SEARCH_PAGES pages) and the log of the task it found.
     */
    public const CALLS = SemaphoreRunner::SEARCH_PAGES + 2;

    public function __construct(
        private readonly Plugin $plugin,
        private readonly RunRepository $runs,
        private readonly PluginSettings $settings,
        private readonly Eventlog $eventlog = new Eventlog(),
        private readonly ?Runner $runner = null,
        private readonly ?InventoryService $inventory = null,
    ) {
    }

    /**
     * The lifecycle of the plugin's runs, with the stored runner connection.
     */
    public function lifecycle(): RunLifecycle
    {
        $inventory = $this->inventory;

        return new RunLifecycle($this->runs, fn () => $this->runner ?? $this->plugin->runner(), $this->eventlog, $this->settings->outputMaxBytes(),
            $this->settings->stopAfterSeconds(), $inventory === null ? null : fn (array $names) => $inventory->devicesNamed($names),
            $this->settings->stopHoldSeconds());
    }

    /**
     * Refresh a run from the runner, unless it was refreshed less than INTERVAL seconds ago (or another request is
     * refreshing it). Returns the run as stored afterwards, null when there is no such run.
     */
    public function refresh(int $runId, bool $throttle = true): ?array
    {
        $run = $this->runs->find($runId);
        if ($run === null || (RunStatus::isFinal($run['status']) && ! RunLifecycle::watched($run, time()))) {
            return $run;
        }
        if ($throttle && $run['polled_at'] !== null && time() - strtotime((string) $run['polled_at']) < self::INTERVAL) {
            return $run;
        }
        $lock = Cache::lock("ansible-plugin-run-$runId", $this->lockSeconds());
        if (! $lock->get()) {
            return $run;
        }
        try {
            return $this->lifecycle()->refresh($runId, time()) ?? $run;
        } catch (\Throwable $e) {
            Log::error("ansible plugin: following run #$runId failed: " . ErrorText::forLog($e));

            return $this->runs->find($runId) ?? $run;
        } finally {
            $lock->release();
        }
    }

    /**
     * How long the lock of a refresh is held at most.
     */
    private function lockSeconds(): int
    {
        return self::lockSecondsFor($this->plugin->connection()->timeout);
    }

    /**
     * The lock's time for a connection timeout: longer than the slowest refresh, in which each runner call may take
     * the whole timeout, so a second request never asks the runner about the same run at once.
     */
    public static function lockSecondsFor(int $timeout): int
    {
        return max(60, self::CALLS * $timeout + 60);
    }

    /**
     * Has the runner reported the run finished, but its log may still be incomplete (refresh it again soon)?
     */
    public static function settling(array $run): bool
    {
        return ! RunStatus::isFinal($run['status']) && $run['runner_final_at'] !== null;
    }

    /**
     * Ask the runner to stop a run; null when it was asked, else why it cannot be stopped.
     */
    public function stop(int $runId, string $username): ?string
    {
        return $this->lifecycle()->stop($runId, $username, time());
    }

    /**
     * Release the devices of a run the runner could not be asked about for a while, or those a stopped run holds;
     * null when released, else why not.
     */
    public function release(int $runId, string $username): ?string
    {
        $lock = Cache::lock("ansible-plugin-run-$runId", $this->lockSeconds());
        if (! $lock->get()) {
            return 'The plugin is asking the runner about this run right now: try again in a moment.';
        }
        try {
            return $this->lifecycle()->release($runId, $username, time());
        } finally {
            $lock->release();
        }
    }
}
