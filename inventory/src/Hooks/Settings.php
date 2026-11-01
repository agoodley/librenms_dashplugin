<?php

namespace Agoodley\LibrenmsInventory\Hooks;

use Agoodley\LibrenmsInventory\Data\LibrenmsNetwork;
use Agoodley\LibrenmsInventory\Data\SettingsRepository;
use Agoodley\LibrenmsInventory\Data\SoftwareRepository;
use Agoodley\LibrenmsInventory\Services\SoftwareCheck;
use Agoodley\LibrenmsInventory\Support\Access;
use Agoodley\LibrenmsInventory\Support\ErrorText;
use Agoodley\LibrenmsInventory\Support\Log;
use Agoodley\LibrenmsInventory\Support\PluginSettings;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

/**
 * Settings page in Plugin Admin. LibreNMS opens it to holders of its plugin.admin permission, which non-admin roles
 * can have, and stores what a form posts to it: so the plugin keeps none of its settings there. Its form posts to the
 * plugin's own route, for LibreNMS admins only; anyone else sees a short note.
 */
class Settings implements SettingsHook
{
    /**
     * The settings route itself is limited to plugin admins by LibreNMS.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @param  array  $settings  what LibreNMS stores for the plugin: not used
     * @return array{content_view: string, settings: array, inventory: array}
     */
    public function handle(string $pluginName, array $settings): array
    {
        // LibreNMS disables a plugin whose hook throws: nothing may escape
        $data = ['admin' => false, 'settings' => PluginSettings::DEFAULTS, 'error' => null, 'groups' => [], 'lastRun' => null, 'policyErrors' => [], 'tables' => true];
        try {
            $data['admin'] = Access::isAdmin(auth()->user());
            if ($data['admin']) {
                $current = (new SettingsRepository())->get();
                $data['settings'] = $current->all();
                $data['error'] = $current->error;
                $data['policyErrors'] = $current->policies()['errors'];
                $data['groups'] = (new LibrenmsNetwork())->groups();
                $data['lastRun'] = SoftwareCheck::forLibrenms($current)->lastRun();
                $data['tables'] = (new SoftwareRepository())->available();
            }
        } catch (\Throwable $e) {
            Log::error('settings page failed: ' . ErrorText::forLog($e));
            $data['error'] = 'The plugin could not read its data: see logs/librenms.log (did ./lnms migrate run?).';
        }

        return ['content_view' => "$pluginName::settings", 'settings' => [], 'inventory' => $data];
    }
}
