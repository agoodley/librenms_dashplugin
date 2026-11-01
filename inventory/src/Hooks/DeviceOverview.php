<?php

namespace Agoodley\LibrenmsInventory\Hooks;

use Agoodley\LibrenmsInventory\Data\InventoryRepository;
use Agoodley\LibrenmsInventory\Data\SoftwareRepository;
use Agoodley\LibrenmsInventory\Reports\Summaries;
use Agoodley\LibrenmsInventory\Support\Access;
use Agoodley\LibrenmsInventory\Support\ErrorText;
use Agoodley\LibrenmsInventory\Support\Log;
use Illuminate\Contracts\View\View;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;

/**
 * The Inventory panel on a device's overview: its chassis with their models and serial numbers (each member of a stack),
 * its software against the policies (as the last software check saw it) and its last version change, how many serial
 * numbers its hardware inventory has and its optics, with links to the plugin's lists.
 */
class DeviceOverview implements DeviceOverviewHook
{
    public function authorize(): bool
    {
        return Access::canView(auth()->user());
    }

    /**
     * @param  \App\Models\Device  $device
     */
    public function handle(string $pluginName, $device): View
    {
        // LibreNMS disables a plugin whose hook throws, and the view is rendered later inside the device page: render it
        // here so no error can escape
        $id = (int) $device->device_id;
        $data = ['device' => $device, 'chassis' => [], 'counts' => ['serials' => 0, 'optics' => 0], 'origins' => null, 'software' => null, 'upgrade' => null,
            'error' => null];
        try {
            $repo = new InventoryRepository(auth()->user());
            $devices = $repo->devices(['device' => $id]);
            if (isset($devices[$id])) {
                $data['chassis'] = $repo->chassis([$id])[$id] ?? [];
                $data['counts'] = $repo->counts([$id])[$id] ?? $data['counts'];
                $data['origins'] = $data['counts']['optics'] > 0 ? Summaries::origins($repo->optics([$id], $devices)) : null;
                $software = new SoftwareRepository();
                if ($software->available()) {
                    $data['software'] = $software->rows([$id])[$id] ?? null;
                    $data['upgrade'] = $software->upgrades([$id], 1)['rows'][0] ?? null;
                }
            }
            $view = view("$pluginName::device-overview", $data);
            $view->render();

            return $view;
        } catch (\Throwable $e) {
            Log::error('device overview failed: ' . ErrorText::forLog($e));

            return view("$pluginName::device-overview", ['device' => $device, 'chassis' => [], 'counts' => ['serials' => 0, 'optics' => 0],
                'origins' => null, 'software' => null, 'upgrade' => null, 'error' => 'The inventory cannot be shown: see logs/librenms.log.']);
        }
    }
}
