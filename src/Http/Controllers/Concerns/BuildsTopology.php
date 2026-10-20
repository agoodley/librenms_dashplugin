<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers\Concerns;

use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Topology\TopologyService;
use Agoodley\LibrenmsTopology\TopologyServiceProvider;
use Illuminate\Http\Request;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

trait BuildsTopology
{
    protected function settings(): PluginSettings
    {
        try {
            $stored = app(PluginManagerInterface::class)->getSettings(TopologyServiceProvider::PLUGIN_NAME);
        } catch (\Throwable) {
            $stored = [];
        }

        return new PluginSettings($stored);
    }

    protected function service(Request $request, ?PluginSettings $settings = null): TopologyService
    {
        return new TopologyService(new TopologyRepository($request->user()), $settings ?? $this->settings());
    }
}
