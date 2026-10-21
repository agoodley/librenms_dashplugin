<?php

namespace Agoodley\LibrenmsTopology\Console;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\History\HistoryService;
use Agoodley\LibrenmsTopology\History\Jobs;
use Agoodley\LibrenmsTopology\Support\MemoryLimit;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\TopologyServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * Shared part of the commands of the topology history: they only run while the plugin is enabled
 * and its tables exist, with enough memory for a whole network (the CLI's php.ini may allow less
 * than LibreNMS' php_memory_limit, which only LibreNMS' own scripts apply), and never twice at once
 * (the schedule and a manual run, on any server).
 */
abstract class HistoryCommand extends Command
{
    /** Memory for a run over the whole network when LibreNMS sets no php_memory_limit (MB). */
    private const MEMORY_MB = 512;

    /**
     * The history service in the system scope, or null (and why) when there is nothing to do.
     */
    protected function service(PluginManagerInterface $plugins): ?HistoryService
    {
        $name = TopologyServiceProvider::PLUGIN_NAME;
        if (! $plugins->pluginEnabled($name)) {
            $this->warn('The topology plugin is disabled: nothing to do.');

            return null;
        }

        $history = HistoryRepository::system();
        if (! $history->available()) {
            $this->error('The topology history tables do not exist yet: run ./lnms migrate');

            return null;
        }
        MemoryLimit::raise(self::MEMORY_MB);

        return new HistoryService($history, TopologyRepository::system(), new PluginSettings($plugins->getSettings($name)));
    }

    /**
     * Run $work unless another run of the same job holds its lock, on this server or another one: a
     * lock in LibreNMS' cache (the database by default, which the pollers share). It lasts longer
     * than the longest run (Jobs::LOCK_SECONDS) and is released when the run ends, also when PHP
     * stops on a fatal error (out of memory); topology:snapshot releases the lock of a check it
     * stopped or that was killed.
     *
     * @param  callable(): void  $work
     */
    protected function locked(string $job, callable $work): bool
    {
        $lock = Cache::lock(self::lockName($job), Jobs::LOCK_SECONDS);
        if (! $lock->get()) {
            $this->warn("Another $job run of the topology history is still running: skipped.");

            return false;
        }

        $released = false;
        $release = function () use ($lock, &$released): void {
            if (! $released) {
                $released = true;
                $lock->release();
            }
        };
        register_shutdown_function($release);
        try {
            $work();
        } finally {
            $release();
        }

        return true;
    }

    /**
     * Release the lock of a job whose process was stopped (it could not release it itself).
     */
    protected static function releaseLock(string $job): void
    {
        Cache::lock(self::lockName($job))->forceRelease();
    }

    private static function lockName(string $job): string
    {
        return "topology-plugin-$job";
    }

    /**
     * Run the background checks the settings enable (of $only), each under its own lock.
     *
     * @param  string[]|null  $only
     * @return bool false when one of them failed
     */
    protected function checks(HistoryService $service, ?array $only = null): bool
    {
        $ok = true;
        foreach ($service->enabledChecks($only) as $analysis) {
            $this->locked("checks-$analysis", function () use ($service, $analysis, &$ok): void {
                $counts = $service->check($analysis);
                $ok = $ok && ! isset($counts['error']);
                $this->info(isset($counts['error'])
                    ? "Background $analysis checks failed, see the LibreNMS log."
                    : sprintf('Background %s checks: %d findings, %d new, %d confirmed, %d resolved, %d eventlog entries%s.', $analysis, $counts['findings'],
                        $counts['new'], $counts['confirmed'], $counts['resolved'], $counts['eventlog'], $counts['initial'] ? ' (first run: recorded without eventlog entries)' : ''));
            });
        }

        return $ok;
    }
}
