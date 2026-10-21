<?php

namespace Agoodley\LibrenmsAnsible\Hooks;

use Agoodley\LibrenmsAnsible\Data\DeviceRepository;
use Agoodley\LibrenmsAnsible\Data\FetchRepository;
use Agoodley\LibrenmsAnsible\Data\PermissionRepository;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Support\Access;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

/**
 * Settings page in Plugin Admin (LibreNMS limits it to holders of plugin.admin). The settings LibreNMS stores
 * (what may be targeted, how much output is kept) are a form posted to LibreNMS; the runner connection, the
 * run permission and the connection test are forms of the plugin's own, for LibreNMS admins only.
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
     * @param  array  $settings  saved settings injected by LibreNMS; the form posts them back as settings[...]
     * @return array{content_view: string, settings: array<string, string>, ansible: array}
     */
    public function handle(string $pluginName, array $settings): array
    {
        // LibreNMS disables a plugin whose hook throws: nothing may escape
        $merged = PluginSettings::merge($settings);
        $data = ['admin' => false, 'connection' => null, 'connectionError' => null, 'holders' => [], 'candidates' => [], 'groups' => [],
            'mapErrors' => [], 'fetch' => null];
        try {
            $plugin = new Plugin();
            $data['admin'] = Access::canView(auth()->user());
            $data['groups'] = (new DeviceRepository())->deviceGroups();
            $data['mapErrors'] = (new PluginSettings($settings))->platformMap()->errors();
            if ($data['admin']) {
                $data['connection'] = $plugin->connection()->forPage();
                $data['connectionError'] = $plugin->connectionError();
                $permissions = new PermissionRepository();
                $data['holders'] = $permissions->holders();
                $data['candidates'] = $permissions->candidates();
                $data['fetch'] = (new FetchRepository())->latest();
            }
        } catch (\Throwable $e) {
            Log::error('ansible plugin: settings page failed: ' . $e->getMessage());
            $data['connectionError'] = 'The plugin could not read its data: see logs/librenms.log (did ./lnms migrate run?).';
        }

        return ['content_view' => "$pluginName::settings", 'settings' => $merged, 'ansible' => $data];
    }
}
