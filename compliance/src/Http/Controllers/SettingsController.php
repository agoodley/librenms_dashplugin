<?php

namespace Agoodley\LibrenmsCompliance\Http\Controllers;

use Agoodley\LibrenmsCompliance\Data\LibrenmsNetwork;
use Agoodley\LibrenmsCompliance\Data\SettingsRepository;
use Agoodley\LibrenmsCompliance\Rules\BuiltinRules;
use Agoodley\LibrenmsCompliance\Services\CheckService;
use Agoodley\LibrenmsCompliance\Services\Eventlog;
use Agoodley\LibrenmsCompliance\Support\PluginSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The plugin's settings, which it stores itself (LibreNMS' plugin settings form is open to plugin.admin, a permission
 * non-admin roles can have). LibreNMS admins only; a change is written to the eventlog, and the next scheduled check
 * checks every device with it.
 */
class SettingsController extends Controller
{
    /** The settings page of the plugin in LibreNMS. */
    private const PAGE = 'plugin/settings/compliance';

    /**
     * POST plugin/compliance/settings: save the settings; nothing is saved when one is wrong.
     */
    public function save(Request $request): RedirectResponse
    {
        $input = $request->only(array_keys(PluginSettings::DEFAULTS));
        $enabled = $request->input('enabled_rules', []);
        $enabled = is_array($enabled) ? array_map('strval', $enabled) : [];
        $input['disabled_rules'] = array_values(array_diff(array_keys(BuiltinRules::RULES), $enabled));
        try {
            $result = PluginSettings::validate($input);
            $errors = $result['errors'];
            $group = trim((string) ($input['device_group'] ?? ''));
            if ($group !== '' && ! isset($errors['device_group']) && ! in_array($group, (new LibrenmsNetwork())->groups(), true)) {
                $errors['device_group'] = 'LibreNMS has no device group of this name';
            }
            if ($errors || $result['settings'] === null) {
                return redirect(url(self::PAGE) . '#cmp-settings-form')->with('compliance_errors', $errors)->with('compliance_old', $input);
            }
            (new SettingsRepository())->save($result['settings']);
            CheckService::forLibrenms($result['settings'])->request();
            (new Eventlog())->write('Compliance: ' . ($request->user()->username ?? 'an admin') . ' changed the settings of the compliance plugin', null, 'info');
        } catch (\Throwable $e) {
            return redirect(url(self::PAGE) . '#cmp-settings-form')->with('compliance_errors', ['' => $this->failure('save of the settings', $e)])->with('compliance_old', $input);
        }

        return redirect(url(self::PAGE) . '#cmp-settings-form')->with('compliance_saved', true);
    }
}
