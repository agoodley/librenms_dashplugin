<?php

namespace Agoodley\LibrenmsAnsible\Hooks;

use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Support\Access;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;

/**
 * Ansible panel on the device overview page: the device's latest runs (template, when, who, result) and a link to
 * run a template on it. Only for LibreNMS admins.
 */
class DeviceOverview implements DeviceOverviewHook
{
    /**
     * Admins only.
     */
    public function authorize(): bool
    {
        return Access::canView(auth()->user());
    }

    /**
     * Called by LibreNMS with dependency injection.
     *
     * @param  string  $pluginName  injected by LibreNMS (view namespace)
     * @param  \App\Models\Device  $device  device of the overview page
     */
    public function handle(string $pluginName, $device): View
    {
        // LibreNMS disables a plugin whose hook throws, and the view is rendered later inside the device page:
        // render it here so no error can escape
        $data = ['device' => $device, 'runs' => [], 'canRun' => false, 'error' => null];
        try {
            $runs = new RunRepository();
            $data['canRun'] = Access::canRun(auth()->user());
            $data['runs'] = $runs->available() ? $runs->deviceRuns((int) $device->device_id) : [];
            $view = view("$pluginName::device-overview", $data);
            $view->render();

            return $view;
        } catch (\Throwable $e) {
            Log::error('ansible plugin: device overview failed: ' . \Agoodley\LibrenmsAnsible\Support\ErrorText::forLog($e));

            return view("$pluginName::device-overview", ['device' => $device, 'runs' => [], 'canRun' => false, 'error' => 'The Ansible runs cannot be shown: see logs/librenms.log.']);
        }
    }
}
