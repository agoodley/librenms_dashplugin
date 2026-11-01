<?php

namespace Agoodley\LibrenmsCompliance\Hooks;

use Agoodley\LibrenmsCompliance\Data\ResultRepository;
use Agoodley\LibrenmsCompliance\Data\SettingsRepository;
use Agoodley\LibrenmsCompliance\Services\Backup;
use Agoodley\LibrenmsCompliance\Support\Access;
use Agoodley\LibrenmsCompliance\Support\ErrorText;
use Agoodley\LibrenmsCompliance\Support\Log;
use Illuminate\Contracts\View\View;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;

/**
 * Compliance panel on the device overview page: the findings of the device's last check (the worst first), when it
 * was checked and of which backup, a link to its compliance page. Nothing for a device that has no backup and was
 * never checked (a server, a printer). For the users who may read the device's configuration backups.
 */
class DeviceOverview implements DeviceOverviewHook
{
    /** Most findings the panel names. */
    private const SHOWN = 6;

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
        $data = ['device' => $device, 'row' => null, 'findings' => [], 'more' => 0, 'rules' => [], 'error' => null];
        try {
            $results = new ResultRepository();
            if (Access::canSeeDevice(auth()->user(), (int) $device->device_id) && $results->available()) {
                $row = $results->device((int) $device->device_id);
                if ($row !== null && ($row['status'] !== Backup::NO_BACKUP || $row['outcomes'])) {
                    $findings = $results->findings((int) $device->device_id);
                    uasort($findings, fn (array $a, array $b) => \Agoodley\LibrenmsCompliance\Rules\Rule::rank((string) $a['severity'])
                        <=> \Agoodley\LibrenmsCompliance\Rules\Rule::rank((string) $b['severity']));
                    $data['row'] = $row;
                    $data['findings'] = array_slice($findings, 0, self::SHOWN, true);
                    $data['more'] = max(0, count($findings) - self::SHOWN);
                    $data['rules'] = (new SettingsRepository())->get()->rules();
                }
            }
            $view = view("$pluginName::device-overview", $data);
            $view->render();

            return $view;
        } catch (\Throwable $e) {
            Log::error('device overview failed: ' . ErrorText::forLog($e));

            return view("$pluginName::device-overview", ['device' => $device, 'row' => null, 'findings' => [], 'more' => 0, 'rules' => [],
                'error' => 'The compliance results cannot be shown: see logs/librenms.log.']);
        }
    }
}
