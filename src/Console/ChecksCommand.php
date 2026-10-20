<?php

namespace Agoodley\LibrenmsTopology\Console;

use Agoodley\LibrenmsTopology\TopologyServiceProvider;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * `lnms topology:checks`: the background checks of the topology history (spanning tree, link and
 * redundancy analyses over all devices; findings history and eventlog). The plugin schedules each
 * analysis in its own process, apart from the snapshot, so one running out of memory does not stop
 * the others.
 */
class ChecksCommand extends HistoryCommand
{
    protected $signature = 'topology:checks
        {analysis?* : stp, links or redundancy (default: those of the Background checks setting)}';

    protected $description = 'Run the background checks of the topology plugin';

    public function handle(PluginManagerInterface $plugins): int
    {
        $service = $this->service($plugins);
        if ($service === null) {
            return $plugins->pluginEnabled(TopologyServiceProvider::PLUGIN_NAME) ? self::FAILURE : self::SUCCESS;
        }

        $this->checks($service, $this->argument('analysis') ?: null);

        return self::SUCCESS;
    }
}
