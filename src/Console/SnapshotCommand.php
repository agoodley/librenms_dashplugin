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
 * `lnms topology:snapshot`: record the topology history and run the background checks. The plugin
 * schedules it every *History interval* minutes; it can also be run by hand.
 */
class SnapshotCommand extends Command
{
    protected $signature = 'topology:snapshot
        {--no-checks : only record the topology, skip the background checks}
        {--baseline : then save the recorded topology as the baseline of every device}';

    protected $description = 'Record the topology history of the topology plugin and run its background checks';

    public function handle(PluginManagerInterface $plugins): int
    {
        $name = TopologyServiceProvider::PLUGIN_NAME;
        if (! $plugins->pluginEnabled($name)) {
            $this->warn('The topology plugin is disabled: nothing to do.');

            return self::SUCCESS;
        }

        $history = HistoryRepository::system();
        if (! $history->available()) {
            $this->error('The topology history tables do not exist yet: run ./lnms migrate');

            return self::FAILURE;
        }

        $settings = new PluginSettings($plugins->getSettings($name));
        $service = new HistoryService($history, TopologyRepository::system(), $settings);

        $stats = $service->snapshot();
        $this->info(sprintf('Topology snapshot of %d devices: %d facts, %d new, %d gone, %d updated, %d old rows pruned (%.2f s).',
            $stats['devices'], $stats['facts'], $stats['added'], $stats['removed'], $stats['updated'], $stats['pruned'], $stats['seconds']));

        if ($this->option('baseline')) {
            $saved = $service->saveBaseline(['device' => null, 'group' => null, 'location' => null], 'lnms topology:snapshot');
            $this->info(sprintf('Baseline saved: %d facts (replacing %d).', $saved['saved'], $saved['removed']));
        }

        if (! $this->option('no-checks')) {
            foreach ($service->checks() as $analysis => $counts) {
                $this->info(isset($counts['error'])
                    ? "Background $analysis checks failed, see the LibreNMS log."
                    : sprintf('Background %s checks: %d findings, %d new, %d resolved, %d eventlog entries%s.', $analysis, $counts['findings'],
                        $counts['new'], $counts['resolved'], $counts['eventlog'], $counts['initial'] ? ' (first run: recorded without eventlog entries)' : ''));
            }
        }

        return self::SUCCESS;
    }
}
