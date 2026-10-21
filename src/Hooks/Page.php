<?php

namespace Agoodley\LibrenmsTopology\Hooks;

use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;

/**
 * Landing page at /plugin/topology linking to the maps and diagnostics.
 */
class Page implements SinglePageHook
{
    /**
     * Every logged in user may open the landing page.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array{title: string, content_view: string} rendered by LibreNMS inside its plugin page layout
     */
    public function handle(string $pluginName): array
    {
        return [
            'title' => 'Topology overview',
            'content_view' => "$pluginName::landing",
        ];
    }
}
