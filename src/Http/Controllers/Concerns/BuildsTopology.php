<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers\Concerns;

use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Topology\TopologyService;
use Agoodley\LibrenmsTopology\TopologyServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * Shared by the controllers: plugin settings, the topology service for the current user and
 * error handling.
 */
trait BuildsTopology
{
    /**
     * Plugin settings saved in LibreNMS, merged with the defaults.
     */
    protected function settings(): PluginSettings
    {
        try {
            $stored = app(PluginManagerInterface::class)->getSettings(TopologyServiceProvider::PLUGIN_NAME);
        } catch (\Throwable) {
            $stored = [];
        }

        return new PluginSettings($stored);
    }

    /**
     * Topology service bound to the logged in user's permissions.
     */
    protected function service(Request $request, ?PluginSettings $settings = null): TopologyService
    {
        return new TopologyService(new TopologyRepository($request->user()), $settings ?? $this->settings());
    }

    /**
     * Log a failure and return the message for the user. Exception text can contain SQL and
     * schema details, so it is only shown when APP_DEBUG is enabled.
     */
    protected function failure(string $what, \Throwable $e): string
    {
        Log::error("topology plugin: $what failed: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString());

        $message = "Unable to build the $what, see logs/librenms.log for details.";

        return config('app.debug') ? $message . ' (' . $e->getMessage() . ')' : $message;
    }
}
