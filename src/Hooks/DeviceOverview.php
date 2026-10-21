<?php

namespace Agoodley\LibrenmsTopology\Hooks;

use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Topology\TopologyService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;

/**
 * Topology panel on the device overview page: neighbors, spanning tree state, routing, and links to the plugin's pages.
 */
class DeviceOverview implements DeviceOverviewHook
{
    /**
     * Shown on every device; the panel hides itself when the device has no neighbours, STP or routing data.
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
            $settings = new PluginSettings($settings);
            $service = new TopologyService(new TopologyRepository(auth()->user()), $settings);
            $view = view("$pluginName::device-overview", [
                'device' => $device,
                'summary' => $service->deviceSummary((int) $device->device_id),
                'history' => $settings->bool('history_enabled'),
            ]);
            $view->render();

            return $view;
        } catch (\Throwable $e) {
            Log::error('topology plugin: device overview failed: ' . $e->getMessage());

            return view("$pluginName::device-overview", ['device' => $device, 'summary' => null, 'history' => false]);
        }
    }
}
