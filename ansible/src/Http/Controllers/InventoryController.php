<?php

namespace Agoodley\LibrenmsAnsible\Http\Controllers;

use Agoodley\LibrenmsAnsible\Changes\InventoryGuard;
use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\FetchRepository;
use Agoodley\LibrenmsAnsible\Inventory\Inventory;
use Agoodley\LibrenmsAnsible\Services\Eventlog;
use Agoodley\LibrenmsAnsible\Support\InventoryScript;
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
     * plugin/ansible/inventory: the inventory as the runner gets it with the inventory user's token (as a user who
     * sees every device while none is chosen), the devices left out and why, the fetches and the setup steps.
     */
    public function show(): View
    {
        $data = ['inventory' => null, 'leftOut' => [], 'names' => [], 'fetches' => [], 'inventoryUser' => null, 'inventoryUserProblem' => null,
            'error' => $this->settings()->error, 'connection' => null, 'endpoint' => route('ansible.api.inventory'), 'assetVersion' => self::assetVersion(),
            'mapErrors' => $this->settings()->platformMap()->errors()];
        try {
            $service = $this->inventoryService();
            $inventoryUser = $service->inventoryUser();
            $data['inventoryUserProblem'] = $service->inventoryUserProblem();
            $data['inventoryUser'] = $inventoryUser === null ? null : ['username' => (string) $inventoryUser['user']->username, 'fetch' => $inventoryUser['fetch']];
            $inventory = self::guarded($service->build($inventoryUser['visible'] ?? null));
            $leftOut = $inventory->leftOut();
            if ($inventoryUser !== null) {
                // devices the inventory user may not see are not in its inventory at all
                foreach (array_diff_key($service->devices()->exists(), $inventoryUser['visible']) as $deviceId => $true) {
                    $leftOut[$deviceId] = ['reason' => 'not_visible', 'text' => 'the inventory user ' . $inventoryUser['user']->username . ' may not see it'];
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
            $inventory = self::guarded($service->build($service->inventoryUser()['visible'] ?? null));
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure('inventory', $e)], 500);
        }

        return response()->json($inventory->toAnsible(), 200, ['Content-Disposition' => 'attachment; filename="librenms-inventory.json"'], self::JSON | JSON_PRETTY_PRINT);
    }

    /**
     * plugin/ansible/api/inventory[?host=NAME]: the dynamic inventory JSON for the token's user (only the devices
     * that user may see), or one host's variables. Once an admin chose the inventory user, only that user's tokens
     * get an answer. A --list fetch is recorded (when, as whom, how many hosts, the inventory script's version). The
     * hosts of a change's dry run or batch in progress are served only as that change's dry run recorded how the runner
     * reaches them, and what was served them is recorded (Changes\InventoryGuard); when that cannot be checked, nothing
     * is served.
     */
    public function runner(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($this->settings()->error !== null) {
            return response()->json(['error' => $this->settings()->error], 500, ['Cache-Control' => 'no-store']);
        }
        $chosen = $this->settings()->inventoryUser();
        if ($chosen !== null && (int) $user->user_id !== $chosen) {
            return response()->json(['error' => 'This API token\'s user is not the inventory user chosen in the Ansible plugin settings: '
                . 'the runner must fetch the inventory with that user\'s token (an admin can change the inventory user there).'], 403, ['Cache-Control' => 'no-store']);
        }
        $host = $request->query('host');
        $host = is_string($host) && $host !== '' ? $host : null;
        try {
            $inventory = $this->inventoryService()->forUser($user);
            $changes = new ChangeRepository();
            if ($changes->available()) {
                $inventory = (new InventoryGuard($changes, new Eventlog()))->serve($inventory, time(), $host);
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure('inventory', $e)], 500, ['Cache-Control' => 'no-store']);
        }
        if ($host !== null) {
            return response()->json($inventory->hostVars($host), 200, ['Cache-Control' => 'no-store'], self::JSON);
        }
        (new FetchRepository())->record((int) $user->user_id, (string) $user->username, count($inventory->hosts()), $request->ip(),
            InventoryScript::versionFrom($request->userAgent()));

        return response()->json($inventory->toAnsible(), 200, ['Cache-Control' => 'no-store'], self::JSON);
    }

    /**
     * The inventory as the runner would get it now: without the hosts a change's dry run or batch in progress no longer
     * reaches as its dry run recorded (Changes\InventoryGuard; nothing is recorded).
     */
    private static function guarded(Inventory $inventory): Inventory
    {
        $changes = new ChangeRepository();

        return $changes->available() ? (new InventoryGuard($changes))->filter($inventory) : $inventory;
    }

    /**
     * POST, PUT, PATCH or DELETE plugin/ansible/api/inventory: the endpoint is read only.
     */
    public function readOnly(): JsonResponse
    {
        return response()->json(['error' => 'The inventory endpoint is read only: fetch it with GET.'], 405, ['Allow' => 'GET, HEAD']);
    }
}
