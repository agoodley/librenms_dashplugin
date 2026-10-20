<?php

namespace Agoodley\LibrenmsTopology\Console;

use Agoodley\LibrenmsTopology\History\Jobs;
use Agoodley\LibrenmsTopology\TopologyServiceProvider;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * `lnms topology:checks`: the background checks of the topology history (spanning tree, link and
 * redundancy analyses over all devices; findings history and eventlog). topology:snapshot runs it
 * for each analysis in a process of its own; it fails when an analysis fails.
 */
class ChecksCommand extends HistoryCommand
{
    protected $signature = Jobs::CHECKS;

    protected $description = 'Run the background checks of the topology plugin';

    public function handle(PluginManagerInterface $plugins): int
    {
        $service = $this->service($plugins);
        if ($service === null) {
            return $plugins->pluginEnabled(TopologyServiceProvider::PLUGIN_NAME) ? self::FAILURE : self::SUCCESS;
        }

        return $this->checks($service, $this->argument('analysis') ?: null) ? self::SUCCESS : self::FAILURE;
    }
}
