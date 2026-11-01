<?php

namespace Agoodley\LibrenmsInventory\Http\Controllers;

use Agoodley\LibrenmsInventory\Data\LibrenmsNetwork;
use Agoodley\LibrenmsInventory\Data\SettingsRepository;
use Agoodley\LibrenmsInventory\Services\Eventlog;
use Agoodley\LibrenmsInventory\Services\SoftwareCheck;
use Agoodley\LibrenmsInventory\Support\PluginSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The plugin's settings, which it stores itself (LibreNMS' plugin settings form is open to plugin.admin, a permission
 * non-admin roles can have). LibreNMS admins only; a change is written to the eventlog, and the devices are checked with
 * it right away.
 */
class SettingsController extends Controller
{
    /** The settings page of the plugin in LibreNMS. */
    private const PAGE = 'plugin/settings/inventory';

    /**
     * POST plugin/inventory/settings: save the settings and check the devices with them; nothing is saved when one is
     * wrong.
     */
    public function save(Request $request): RedirectResponse
    {
        $input = $request->only(array_keys(PluginSettings::DEFAULTS));
        $back = url(self::PAGE) . '#inv-settings-form';
        try {
            $result = PluginSettings::validate($input);
            $errors = $result['errors'];
            $group = trim((string) ($input['device_group'] ?? ''));
            if ($group !== '' && ! isset($errors['device_group']) && ! in_array($group, (new LibrenmsNetwork())->groups(), true)) {
                $errors['device_group'] = 'LibreNMS has no device group of this name';
            }
            if ($errors || $result['settings'] === null) {
                return redirect($back)->with('inventory_errors', $errors)->with('inventory_old', $input);
            }
            (new SettingsRepository())->save($result['settings']);
            (new Eventlog())->write('Inventory: ' . ($request->user()->username ?? 'an admin') . ' changed the settings of the inventory plugin', null, 'info');
            $summary = SoftwareCheck::forLibrenms($result['settings'])->run();
        } catch (\Throwable $e) {
            return redirect($back)->with('inventory_errors', ['' => $this->failure('save of the settings', $e)])->with('inventory_old', $input);
        }

        return redirect($back)->with('inventory_saved', $summary['error'] ?? SoftwareCheck::describe($summary))->with('inventory_saved_ok', $summary['error'] === null);
    }
}
