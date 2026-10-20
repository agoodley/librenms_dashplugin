<?php

namespace Agoodley\LibrenmsTopology\Console;

use Agoodley\LibrenmsTopology\History\Jobs;
use Agoodley\LibrenmsTopology\TopologyServiceProvider;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * `lnms topology:snapshot`: record the topology history, then run the background checks, each in
 * a process of its own and one after the other: they read the finished snapshot, a check that runs
 * out of memory does not stop the others, and only one process needs its memory at a time. The
 * plugin schedules it every *History interval* minutes.
 */
class SnapshotCommand extends HistoryCommand
{
    protected $signature = Jobs::SNAPSHOT;

    protected $description = 'Record the topology history of the topology plugin (and run its background checks)';

    public function handle(PluginManagerInterface $plugins): int
    {
        $service = $this->service($plugins);
        if ($service === null) {
            return $plugins->pluginEnabled(TopologyServiceProvider::PLUGIN_NAME) ? self::FAILURE : self::SUCCESS;
        }

        // a run that finds the other one still running skips (a warning, no failure)
        $done = $this->locked('snapshot', function () use ($service): void {
            $stats = $service->snapshot();
            $this->info(sprintf('Topology snapshot of %d devices: %d facts, %d new, %d gone, %d updated, %d old rows pruned%s (%.2f s).',
                $stats['devices'], $stats['facts'], $stats['added'], $stats['removed'], $stats['updated'], $stats['pruned'],
                isset($stats['dropped']) ? sprintf(', %d links to phones no longer recorded', $stats['dropped']) : '', $stats['seconds']));

            if ($this->option('baseline')) {
                $saved = $service->saveBaseline(['device' => null, 'group' => null, 'location' => null], 'lnms topology:snapshot');
                $message = sprintf('Baseline saved: %d facts (replacing %d).', $saved['saved'], $saved['removed']);
                Log::info("topology plugin: lnms topology:snapshot --baseline: $message");
                $this->info($message);
            }
        });
        if (! $done || $this->option('no-checks')) {
            return self::SUCCESS;
        }

        $results = Jobs::each($service->enabledChecks(), fn (string $analysis) => $this->runCheck($analysis));
        foreach (array_filter($results) as $analysis => $why) {
            $this->error("Background $analysis checks failed ($why), see above.");
        }

        return array_filter($results) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Run one background check in a process of its own. A process stopped at its timeout or killed
     * by a signal cannot release its lock, which would keep the check from running until the lock
     * expires: it is released for it.
     */
    protected function runCheck(string $analysis): bool
    {
        $process = $this->checkProcess($analysis);
        try {
            $process->run(fn ($type, $output) => $this->output->write($output));
        } catch (ProcessTimedOutException|ProcessSignaledException $e) {
            self::releaseLock("checks-$analysis");

            throw $e;
        }

        return $process->isSuccessful();
    }

    /**
     * The process of one background check: `topology:checks <analysis>`, stopped after Jobs::CHECK_TIMEOUT.
     */
    protected function checkProcess(string $analysis): Process
    {
        return new Process([PHP_BINARY, base_path('artisan'), 'topology:checks', $analysis], base_path(), null, null, Jobs::CHECK_TIMEOUT);
    }
}
