<?php

namespace Agoodley\LibrenmsInventory\Hooks;

use Agoodley\LibrenmsInventory\Support\Access;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;

/**
 * The plugin's page in Plugin Admin (plugin/inventory): what its pages show.
 */
class Page implements SinglePageHook
{
    public function authorize(): bool
    {
        return Access::canView(auth()->user());
    }

    /**
     * @return array{title: string, content_view: string}
     */
    public function handle(string $pluginName): array
    {
        return ['title' => 'Inventory', 'content_view' => "$pluginName::landing"];
    }
}
