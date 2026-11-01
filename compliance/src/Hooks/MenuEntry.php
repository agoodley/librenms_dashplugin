<?php

namespace Agoodley\LibrenmsCompliance\Hooks;

use Agoodley\LibrenmsCompliance\Support\Access;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

/**
 * "Compliance" in the LibreNMS Plugins menu: the overview, the plugin's main page. For the users who may read
 * configuration backups (Support\Access).
 */
class MenuEntry implements MenuEntryHook
{
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
