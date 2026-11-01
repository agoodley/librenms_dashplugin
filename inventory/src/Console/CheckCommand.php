<?php

namespace Agoodley\LibrenmsInventory\Console;

use Agoodley\LibrenmsInventory\InventoryServiceProvider;
use Agoodley\LibrenmsInventory\Services\SoftwareCheck;
use Agoodley\LibrenmsInventory\Software\Status;
use Illuminate\Console\Command;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * inventory:check, every hour through the LibreNMS scheduler: the software version of every device the settings cover
 * against the policies, and the version changes since the last run.
 */
class CheckCommand extends Command
{
    protected $signature = 'inventory:check';

    protected $description = 'Check the software versions of the devices against the inventory plugin\'s policies, and record their upgrades';

    public function handle(PluginManagerInterface $plugins): int
    {
        if (! $plugins->pluginEnabled(InventoryServiceProvider::PLUGIN_NAME)) {
            $this->warn('The inventory plugin is disabled in LibreNMS (Plugin Admin): nothing checked.');

            return self::SUCCESS;
        }
        $verbose = $this->getOutput()->isVerbose();
        $summary = SoftwareCheck::forLibrenms()->run($verbose ? function (int $id, string $name, array $result): void {
            $this->line("  $name (#$id): " . Status::label($result['status']) . ($result['policy'] !== null ? ' (' . $result['policy'] . ')' : ''));
        } : null);
        if ($summary['error'] !== null) {
            $this->error('inventory:check: ' . $summary['error']);

            return self::FAILURE;
        }
        $this->line(date('Y-m-d H:i:s T') . ' inventory:check: ' . SoftwareCheck::describe($summary));

        return self::SUCCESS;
    }
}
