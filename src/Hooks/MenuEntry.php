<?php

namespace Agoodley\LibrenmsTopology\Hooks;

use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

/**
 * Entries in the LibreNMS "Plugins" menu.
 */
class MenuEntry implements MenuEntryHook
{
    /**
     * Every logged in user gets the entries: the pages only show devices the user may see.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array{0: string, 1: array} view and its data, rendered inside the menu
     */
    public function handle(string $pluginName): array
    {
        return ["$pluginName::menu", []];
    }
}
