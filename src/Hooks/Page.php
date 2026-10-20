<?php

namespace Agoodley\LibrenmsTopology\Hooks;

use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;

/**
 * Landing page at /plugin/topology linking to the maps and diagnostics.
 */
class Page implements SinglePageHook
{
    public function authorize(): bool
    {
        return true;
    }

    public function handle(string $pluginName): array
    {
        return [
            'title' => 'Network Topology',
            'content_view' => "$pluginName::landing",
        ];
    }
}
