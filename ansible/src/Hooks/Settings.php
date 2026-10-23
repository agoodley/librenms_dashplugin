<?php

namespace Agoodley\LibrenmsAnsible\Hooks;

use Agoodley\LibrenmsAnsible\Data\ConnectionChecks;
use Agoodley\LibrenmsAnsible\Data\DeviceRepository;
use Agoodley\LibrenmsAnsible\Data\FetchRepository;
use Agoodley\LibrenmsAnsible\Data\PermissionRepository;
use Agoodley\LibrenmsAnsible\Data\UserRepository;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Support\Access;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

/**
 * Settings page in Plugin Admin. LibreNMS opens it to holders of its plugin.admin permission, which non-admin roles
 * can have, and stores what a form posts to it: so the plugin keeps none of its settings there. Its forms (the runner
 * connection, the inventory user, the targets and runs, the run permission) post to the plugin's own routes, for
 * LibreNMS admins only; anyone else sees a short note.
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
     * @return array{content_view: string, settings: array, ansible: array}
     */
    public function handle(string $pluginName, array $settings): array
    {
        // LibreNMS disables a plugin whose hook throws: nothing may escape
        $data = ['admin' => false, 'connection' => null, 'connectionError' => null, 'settings' => PluginSettings::DEFAULTS, 'settingsError' => null,
            'mapErrors' => [], 'holders' => [], 'candidates' => [], 'approveHolders' => [], 'approveCandidates' => [], 'groups' => [], 'users' => [], 'fetches' => [],
            'lastCheck' => null, 'mapRows' => [], 'oxidized' => false, 'topology' => 'unknown', 'topologyApi' => null, 'verification' => 'unknown'];
        try {
            $data['admin'] = Access::canView(auth()->user());
            if ($data['admin']) {
                $plugin = new Plugin();
                $current = Plugin::settings();
                $data['settings'] = $current->all();
                $data['settingsError'] = $current->error;
                $data['mapErrors'] = $current->platformMap()->errors();
                $data['mapRows'] = $current->platformMap()->rows();
                $data['connection'] = $plugin->connection()->forPage();
                $data['connectionError'] = $plugin->connectionError();
                $data['lastCheck'] = (new ConnectionChecks())->last();
                $permissions = new PermissionRepository();
                $data['holders'] = $permissions->holders();
                $data['candidates'] = $permissions->candidates();
                $data['approveHolders'] = $permissions->holders(Access::APPROVE);
                $data['approveCandidates'] = $permissions->candidates(Access::APPROVE);
                $data['oxidized'] = (new \Agoodley\LibrenmsAnsible\Services\OxidizedBackups())->enabled();
                // can the topology plugin plan the changes (installed, enabled, a planning API the plugin speaks)?
                $data['topology'] = (new \Agoodley\LibrenmsAnsible\Services\TopologyPlanning())->unavailable();
                $data['topologyApi'] = class_exists(\Agoodley\LibrenmsAnsible\Services\TopologyPlanning::API)
                    ? (string) constant(\Agoodley\LibrenmsAnsible\Services\TopologyPlanning::API . '::VERSION') : null;
                // can the topology plugin verify the batches (its verification API)?
                $data['verification'] = (new \Agoodley\LibrenmsAnsible\Services\TopologyVerification())->unavailable();
                $data['groups'] = (new DeviceRepository())->deviceGroups();
                $data['users'] = (new UserRepository())->all();
                $data['fetches'] = (new FetchRepository())->all();
            }
        } catch (\Throwable $e) {
            Log::error('ansible plugin: settings page failed: ' . \Agoodley\LibrenmsAnsible\Support\ErrorText::forLog($e));
            $data['connectionError'] = 'The plugin could not read its data: see logs/librenms.log (did ./lnms migrate run?).';
        }

        return ['content_view' => "$pluginName::settings", 'settings' => [], 'ansible' => $data];
    }
}
