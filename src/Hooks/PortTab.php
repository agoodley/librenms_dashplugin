<?php

namespace Agoodley\LibrenmsTopology\Hooks;

use Agoodley\LibrenmsTopology\Data\HostRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Hosts\HostService;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Plugins\Hooks\PortTabHook;

/**
 * Topology section of the "Plugins" tab of every port page: far end, port-channel, VLANs,
 * spanning tree, MACs learned and 802.1X sessions. It runs on every port page view, so it only
 * queries by port and device.
 */
class PortTab implements PortTabHook
{
    /**
     * Every user who may open the port page gets the section; it only shows what the user may see.
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
     * @param  \App\Models\Port  $port  port of the page
     */
    public function handle(string $pluginName, array $settings, $port): View
    {
        // LibreNMS disables a plugin whose hook throws, and the view is rendered later inside the
        // port page: render it here so no error can escape
        try {
            $user = auth()->user();
            $service = new HostService(new HostRepository($user), new TopologyRepository($user), new PluginSettings($settings));
            $view = view("$pluginName::port-tab", ['tab' => $service->portTab((int) $port->device_id, (int) $port->port_id)]);
            $view->render();

            return $view;
        } catch (\Throwable $e) {
            Log::error('topology plugin: port tab failed: ' . $e->getMessage());

            return view("$pluginName::port-tab", ['tab' => null]);
        }
    }
}
