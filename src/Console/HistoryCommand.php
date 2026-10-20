<?php

namespace Agoodley\LibrenmsTopology\Console;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\History\HistoryService;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\TopologyServiceProvider;
use Illuminate\Console\Command;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * Shared part of the commands of the topology history: they only run while the plugin is enabled
 * and its tables exist, with enough memory for a whole network (the CLI's php.ini may allow less
 * than LibreNMS' php_memory_limit, which only LibreNMS' own scripts apply), and never twice at once
 * (the schedule and a manual run).
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
        self::raiseMemoryLimit();

        return new HistoryService($history, TopologyRepository::system(), new PluginSettings($plugins->getSettings($name)));
    }

    /**
     * Run $work unless another run of the same job on this server holds its lock: a file lock, which
     * the system releases when the process ends, also when PHP stops on a fatal error (out of memory).
     * The schedule keeps runs on different servers apart (onOneServer).
     *
     * @param  callable(): void  $work
     */
    protected function locked(string $job, callable $work): bool
    {
        $handle = @fopen(storage_path("framework/topology-plugin-$job.lock"), 'c');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            $this->warn("Another $job run of the topology history is still running: skipped.");

            return false;
        }

        try {
            $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return true;
    }

    /**
     * Run the background checks the settings enable (of $only), each under its own lock.
     *
     * @param  string[]|null  $only
     */
    protected function checks(HistoryService $service, ?array $only = null): void
    {
        foreach ($service->enabledChecks($only) as $analysis) {
            $this->locked("checks-$analysis", function () use ($service, $analysis): void {
                $counts = $service->check($analysis);
                $this->info(isset($counts['error'])
                    ? "Background $analysis checks failed, see the LibreNMS log."
                    : sprintf('Background %s checks: %d findings, %d new, %d confirmed, %d resolved, %d eventlog entries%s.', $analysis, $counts['findings'],
                        $counts['new'], $counts['confirmed'], $counts['resolved'], $counts['eventlog'], $counts['initial'] ? ' (first run: recorded without eventlog entries)' : ''));
            });
        }
    }

    /**
     * LibreNMS' php_memory_limit (MB) if it is set, else MEMORY_MB, unless PHP already allows more.
     */
    private static function raiseMemoryLimit(): void
    {
        $configured = null;
        try {
            $configured = class_exists(\App\Facades\LibrenmsConfig::class) ? \App\Facades\LibrenmsConfig::get('php_memory_limit') : null;
        } catch (\Throwable) {
            // older LibreNMS: the default below
        }
        $wanted = (is_numeric($configured) && $configured > 0 ? (int) $configured : self::MEMORY_MB) * 1048576;
        $current = self::bytes((string) ini_get('memory_limit'));
        if ($current !== -1 && $current < $wanted) {
            ini_set('memory_limit', (string) $wanted);
        }
    }

    /**
     * A php.ini size ("128M", "1G", "-1") in bytes, -1 for no limit.
     */
    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1073741824,
            'M' => $number * 1048576,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
