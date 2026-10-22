<?php

namespace Agoodley\LibrenmsAnsible\Hooks;

use Agoodley\LibrenmsAnsible\Support\Access;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

/**
 * "Ansible" in the LibreNMS Plugins menu: the changes, the plugin's main page. Only for LibreNMS admins.
 */
class MenuEntry implements MenuEntryHook
{
    /**
     * Admins only: nobody else may open the plugin's pages.
     */
    public function authorize(): bool
    {
        return Access::canView(auth()->user());
    }

    /**
     * @return array{0: string, 1: array} view and its data, rendered inside the menu
     */
    public function handle(string $pluginName): array
    {
        return ["$pluginName::menu", []];
    }
}
