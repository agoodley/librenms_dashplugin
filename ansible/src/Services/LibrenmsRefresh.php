<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Changes\DataRefresh;
use Illuminate\Support\Facades\DB;

/**
 * LibreNMS asked for a discovery of devices, as its own scheduling would run it: when its discovery is scheduled by
 * Laravel (schedule_type.discovery "scheduler"), a DiscoverDevice job per device on the device's poller group's
 * discovery queue, with every module, as its dispatcher queues them (the job is unique per device: asking twice queues
 * it once); otherwise (the discovery cron, or the dispatcher service) each device marked for rediscovery, as the device
 * page's Rediscover button does (last_discovered emptied: picked up within minutes). A full discovery, never a few
 * modules: one limited to some modules would still set last_discovered and push the device's next full discovery back by
 * the discovery interval. A device that is down is not discovered by LibreNMS (it waits for it to be up).
 */
final class LibrenmsRefresh implements DataRefresh
{
    public function request(array $deviceIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $deviceIds), fn (int $id) => $id > 0)));
        if (! $ids) {
            return ['how' => 'rediscover', 'devices' => []];
        }
        try {
            $scheduler = class_exists(\App\Facades\LibrenmsConfig::class) && \App\Facades\LibrenmsConfig::get('schedule_type.discovery') === 'scheduler'
                && class_exists(\App\Jobs\DiscoverDevice::class) && class_exists(\LibreNMS\Util\ModuleList::class);
            $devices = DB::table('devices')->whereIn('device_id', $ids)->where('disabled', 0)->get(['device_id', 'poller_group']);
            if ($scheduler) {
                $default = (string) config('queue.default');
                $connection = $default === 'sync' ? 'database' : $default;
                $modules = \LibreNMS\Util\ModuleList::fromUserOverrides([]);
                foreach ($devices as $device) {
                    \App\Jobs\DiscoverDevice::dispatch((int) $device->device_id, $modules)->onConnection($connection)
                        ->onQueue($device->poller_group ? 'discovery-' . $device->poller_group : 'discovery');
                }

                return ['how' => 'dispatch', 'devices' => array_map(fn ($device) => (int) $device->device_id, $devices->all())];
            }
            DB::table('devices')->whereIn('device_id', $ids)->where('disabled', 0)->update(['last_discovered' => null]);

            return ['how' => 'rediscover', 'devices' => array_map(fn ($device) => (int) $device->device_id, $devices->all())];
        } catch (\Throwable $e) {
            throw new \RuntimeException(\Agoodley\LibrenmsAnsible\Inventory\UntrustedText::line($e->getMessage(), 200), 0, $e);
        }
    }
}
