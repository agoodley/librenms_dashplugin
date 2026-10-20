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
    /**
     * Shown on every device; the panel hides itself when the device has no neighbours or STP data.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Called by LibreNMS with dependency injection.
     *
     * @param  string  $pluginName  injected by LibreNMS (view namespace)
     * @param  array  $settings  plugin settings injected by LibreNMS
     * @param  \App\Models\Device  $device  device of the overview page
     */
    public function handle(string $pluginName, array $settings, $device): View
    {
        // LibreNMS disables a plugin whose hook throws, and the view is rendered later inside the
        // device page: render it here so no error can escape
        try {
            $service = new TopologyService(new TopologyRepository(auth()->user()), new PluginSettings($settings));
            $view = view("$pluginName::device-overview", [
                'device' => $device,
                'summary' => $service->deviceSummary((int) $device->device_id),
            ]);
            $view->render();

            return $view;
        } catch (\Throwable $e) {
            Log::error('topology plugin: device overview failed: ' . $e->getMessage());

            return view("$pluginName::device-overview", ['device' => $device, 'summary' => null]);
        }
    }
}
