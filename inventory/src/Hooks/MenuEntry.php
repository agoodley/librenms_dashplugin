<?php

namespace Agoodley\LibrenmsInventory\Hooks;

use Agoodley\LibrenmsInventory\Support\Access;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

/**
 * The plugin's entry in LibreNMS' Plugins menu.
 */
class MenuEntry implements MenuEntryHook
{
    public function authorize(): bool
    {
        return Access::canView(auth()->user());
    }

    /**
     * @return array{0: string, 1: array}
     */
    public function handle(string $pluginName): array
    {
        return ["$pluginName::menu", []];
    }
}
