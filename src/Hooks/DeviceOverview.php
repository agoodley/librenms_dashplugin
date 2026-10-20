<?php

namespace Agoodley\LibrenmsTopology\Hooks;

use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Topology\TopologyService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;

/**
 * Topology / spanning tree panel on the device overview page.
 */
class DeviceOverview implements DeviceOverviewHook
{
    public function authorize(): bool
    {
        return true;
    }

    public function handle(string $pluginName, array $settings, $device): View
    {
        // LibreNMS disables a plugin whose hook throws, so never let an error escape
        try {
            $service = new TopologyService(new TopologyRepository(auth()->user()), new PluginSettings($settings));
            $summary = $service->deviceSummary((int) $device->device_id);
        } catch (\Throwable $e) {
            Log::error('topology plugin: device overview failed: ' . $e->getMessage());
            $summary = null;
        }

        return view("$pluginName::device-overview", [
            'device' => $device,
            'summary' => $summary,
        ]);
    }
}
