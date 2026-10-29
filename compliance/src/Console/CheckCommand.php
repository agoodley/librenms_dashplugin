<?php

namespace Agoodley\LibrenmsCompliance\Console;

use Agoodley\LibrenmsCompliance\ComplianceServiceProvider;
use Agoodley\LibrenmsCompliance\Rules\Outcome;
use Agoodley\LibrenmsCompliance\Services\Backup;
use Agoodley\LibrenmsCompliance\Services\CheckService;
use Illuminate\Console\Command;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * compliance:check, every hour through the LibreNMS scheduler: checks every device the settings cover when the
 * settings' interval has passed since the last run (--force: now), or one device (--device).
 */
class CheckCommand extends Command
{
    protected $signature = 'compliance:check {--device= : check only this device (its id or hostname)} {--force : check every device now, whatever the interval}';

    protected $description = 'Check the configuration backups of the devices against the compliance rules';

    public function handle(PluginManagerInterface $plugins): int
    {
        if (! $plugins->pluginEnabled(ComplianceServiceProvider::PLUGIN_NAME)) {
            $this->warn('The compliance plugin is disabled in LibreNMS (Plugin Admin): nothing checked.');

            return self::SUCCESS;
        }
        $service = CheckService::forLibrenms();
        $device = $this->option('device');
        if ($device !== null && $device !== '') {
            return $this->one($service, (string) $device);
        }
        if (! $this->option('force') && ! $service->due()) {
            $this->line(date('Y-m-d H:i:s T') . ' compliance:check: not due yet (the interval of the settings), nothing checked');

            return self::SUCCESS;
        }
        $verbose = $this->getOutput()->isVerbose();
        $summary = $service->checkAll($verbose ? function (int $id, string $name, array $result): void {
            $this->line("  $name (#$id): " . self::result($result));
        } : null);
        if ($summary['error'] !== null) {
            $this->error('compliance:check: ' . $summary['error']);

            return self::FAILURE;
        }
        $this->line(date('Y-m-d H:i:s T') . ' compliance:check: ' . Outcome::count($summary['devices'], 'device') . ', ' . $summary['checked'] . ' checked, '
            . $summary['no_backup'] . ' without a backup, ' . $summary['errors'] . ' failed; ' . Outcome::count($summary['findings'], 'finding') . ' ('
            . $summary['new'] . ' new, ' . $summary['resolved'] . ' resolved)' . ($summary['forgotten'] ? ', ' . Outcome::count($summary['forgotten'], 'device') . ' forgotten' : ''));
        foreach ($summary['unreachable'] as $why) {
            $this->warn('  ' . $why);
        }

        return self::SUCCESS;
    }

    private function one(CheckService $service, string $device): int
    {
        $id = ctype_digit($device) ? (int) $device : (int) \App\Models\Device::where('hostname', $device)->value('device_id');
        try {
            $result = $service->checkDevice($id);
        } catch (\InvalidArgumentException $e) {
            $this->error("compliance:check: $device: " . $e->getMessage());

            return self::FAILURE;
        }
        $this->line("compliance:check: $device: " . self::result($result));

        return $result['status'] === Backup::ERROR ? self::FAILURE : self::SUCCESS;
    }

    private static function result(array $result): string
    {
        return match ($result['status']) {
            Backup::FOUND => 'checked (' . $result['provider'] . '), ' . Outcome::count($result['findings'], 'finding') . ', ' . $result['new'] . ' new, '
                . $result['resolved'] . ' resolved',
            Backup::NO_BACKUP => 'no backup: ' . $result['why'],
            default => 'failed: ' . $result['why'],
        };
    }
}
