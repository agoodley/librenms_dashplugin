<?php

namespace Agoodley\LibrenmsTopology\Hooks;

use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

/**
 * Entries in the LibreNMS "Plugins" menu.
 */
class MenuEntry implements MenuEntryHook
{
    public function authorize(): bool
    {
        // data on every page is limited to the devices the user can see
        return true;
    }

    public function handle(string $pluginName): array
    {
        return ["$pluginName::menu", []];
    }
}
