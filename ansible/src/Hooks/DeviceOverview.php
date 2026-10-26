<?php

namespace Agoodley\LibrenmsAnsible\Hooks;

use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\DeviceRepository;
use Agoodley\LibrenmsAnsible\Data\OwnLoginRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Inventory\InventoryBuilder;
use Agoodley\LibrenmsAnsible\Runs\TargetSelection;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Support\Access;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;

/**
 * Ansible panel on the device overview page: the device's latest changes (template, status, its dry run and result
 * on the device) and runs (template, when, who, result), a link to a new change on it, and whether it logs in with a
 * login of its own (install/rocky/device-logins.sh; never the login). Only for LibreNMS admins.
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
        $data = ['device' => $device, 'runs' => [], 'changes' => [], 'canRun' => false, 'refusal' => null, 'login' => null, 'error' => null];
        try {
            $runs = new RunRepository();
            $changes = new ChangeRepository();
            $data['canRun'] = Access::canRun(auth()->user());
            $data['login'] = (new OwnLoginRepository())->ownLogins()->of((int) $device->device_id, (string) $device->hostname,
                fn (string $field) => OwnLoginRepository::field($device, $field));
            $data['runs'] = $runs->available() ? $runs->deviceRuns((int) $device->device_id) : [];
            $data['changes'] = $changes->available() ? $changes->deviceChanges((int) $device->device_id) : [];
            if ($data['canRun']) {
                // offer a run only to a device that can be a target, else say why not (also when another run has it, or a
                // stopped run still holds it)
                $settings = Plugin::settings();
                $data['refusal'] = (new InventoryBuilder($settings->platformMap(), $settings->allowedGroup()))->refusal(
                    ['hostname' => (string) $device->hostname, 'overwrite_ip' => $device->overwrite_ip, 'os' => (string) $device->os, 'disabled' => (int) $device->disabled],
                    $device->groups()->pluck('device_groups.id')->map(fn ($id) => (int) $id)->all(), (new DeviceRepository())->deviceGroups());
                $busy = $data['refusal'] === null && $runs->available() ? ($runs->busy()[(int) $device->device_id] ?? null) : null;
                if ($busy !== null) {
                    $data['refusal'] = TargetSelection::busyRefusal($busy);
                }
                $reserved = $data['refusal'] === null && $changes->available() ? ($changes->reserved()[(int) $device->device_id] ?? null) : null;
                if ($reserved !== null) {
                    $data['refusal'] = TargetSelection::reservedRefusal($reserved);
                }
            }
            $view = view("$pluginName::device-overview", $data);
            $view->render();

            return $view;
        } catch (\Throwable $e) {
            Log::error('ansible plugin: device overview failed: ' . \Agoodley\LibrenmsAnsible\Support\ErrorText::forLog($e));

            return view("$pluginName::device-overview", ['device' => $device, 'runs' => [], 'changes' => [], 'canRun' => false, 'refusal' => null, 'login' => null,
                'error' => 'The Ansible changes and runs cannot be shown: see logs/librenms.log.']);
        }
    }
}
