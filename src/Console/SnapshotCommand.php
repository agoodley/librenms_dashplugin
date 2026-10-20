<?php

namespace Agoodley\LibrenmsTopology\Console;

use Agoodley\LibrenmsTopology\TopologyServiceProvider;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * `lnms topology:snapshot`: record the topology history, by hand also the background checks. The
 * plugin schedules it with --no-checks every *History interval* minutes, and topology:checks apart.
 */
class SnapshotCommand extends HistoryCommand
{
    protected $signature = 'topology:snapshot
        {--no-checks : only record the topology, skip the background checks}
        {--baseline : then save the recorded topology as the baseline of every device}';

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
            $this->info(sprintf('Topology snapshot of %d devices: %d facts, %d new, %d gone, %d updated, %d old rows pruned (%.2f s).',
                $stats['devices'], $stats['facts'], $stats['added'], $stats['removed'], $stats['updated'], $stats['pruned'], $stats['seconds']));

            if ($this->option('baseline')) {
                $saved = $service->saveBaseline(['device' => null, 'group' => null, 'location' => null], 'lnms topology:snapshot');
                $message = sprintf('Baseline saved: %d facts (replacing %d).', $saved['saved'], $saved['removed']);
                Log::info("topology plugin: lnms topology:snapshot --baseline: $message");
                $this->info($message);
            }
        });
        if ($done && ! $this->option('no-checks')) {
            $this->checks($service);
        }

        return self::SUCCESS;
    }
}
