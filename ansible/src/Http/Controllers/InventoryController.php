<?php

namespace Agoodley\LibrenmsAnsible\Http\Controllers;

use Agoodley\LibrenmsAnsible\Data\FetchRepository;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The inventory: what the runner gets (the endpoint its inventory script fetches with a LibreNMS API token),
 * and the page that shows it with the devices left out, the last fetches and the setup steps.
 */
class InventoryController extends Controller
{
    /** JSON flags of the inventory: readable names, valid UTF-8 always. */
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * plugin/ansible/inventory: the inventory as the runner gets it (as the user it last fetched it as; as a user
     * who sees every device until then), the devices left out and why, the fetches and the setup steps.
     */
    public function show(): View
    {
        $data = ['inventory' => null, 'leftOut' => [], 'names' => [], 'fetches' => [], 'runnerUser' => null, 'error' => null,
            'connection' => null, 'endpoint' => route('ansible.api.inventory'), 'assetVersion' => self::assetVersion(),
            'mapErrors' => $this->settings()->platformMap()->errors()];
        try {
            $service = $this->inventoryService();
            $runnerUser = $service->runnerUser();
            $data['runnerUser'] = $runnerUser === null ? null : ['username' => $runnerUser['user']->username] + $runnerUser['fetch'];
            $inventory = $service->build($runnerUser['visible'] ?? null);
            $leftOut = $inventory->leftOut();
            if ($runnerUser !== null) {
                // devices the runner's user may not see are not in its inventory at all
                foreach (array_diff_key($service->devices()->exists(), $runnerUser['visible']) as $deviceId => $true) {
                    $leftOut[$deviceId] = ['reason' => 'not_visible', 'text' => 'the inventory\'s user ' . $runnerUser['user']->username . ' may not see it'];
                }
                ksort($leftOut);
            }
            $data['inventory'] = $inventory;
            $data['leftOut'] = $leftOut;
            $data['names'] = $service->devices()->names(array_keys($leftOut + array_flip(array_keys($inventory->names()))));
            $data['fetches'] = (new FetchRepository())->all();
            $data['connection'] = (new Plugin())->connection()->forPage();
        } catch (\Throwable $e) {
            $data['error'] = $this->failure('inventory', $e);
        }

        return view('ansible::inventory', $data);
    }

    /**
     * plugin/ansible/inventory/download: the inventory of the page as a JSON file.
     */
    public function download(): JsonResponse
    {
        try {
            $service = $this->inventoryService();
            $inventory = $service->build($service->runnerUser()['visible'] ?? null);
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure('inventory', $e)], 500);
        }

        return response()->json($inventory->toAnsible(), 200, ['Content-Disposition' => 'attachment; filename="librenms-inventory.json"'], self::JSON | JSON_PRETTY_PRINT);
    }

    /**
     * plugin/ansible/api/inventory[?host=NAME]: the dynamic inventory JSON for the token's user (only the devices
     * that user may see), or one host's variables. A --list fetch is recorded (when, as whom, how many hosts).
     */
    public function runner(Request $request): JsonResponse
    {
        $user = $request->user();
        try {
            $inventory = $this->inventoryService()->forUser($user);
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure('inventory', $e)], 500);
        }
        $host = $request->query('host');
        if (is_string($host) && $host !== '') {
            return response()->json($inventory->hostVars($host), 200, ['Cache-Control' => 'no-store'], self::JSON);
        }
        (new FetchRepository())->record((int) $user->user_id, (string) $user->username, count($inventory->hosts()), $request->ip());

        return response()->json($inventory->toAnsible(), 200, ['Cache-Control' => 'no-store'], self::JSON);
    }
}
