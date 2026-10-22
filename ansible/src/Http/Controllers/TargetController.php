<?php

namespace Agoodley\LibrenmsAnsible\Http\Controllers;

use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Runs\TargetSelection;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Illuminate\Http\JsonResponse;

/**
 * The devices a new change can have as targets: every device with its inventory name, platform, LibreNMS status
 * and, when it cannot be a target, why (TargetSelection).
 */
class TargetController extends Controller
{
    /**
     * plugin/ansible/api/targets: every device with its inventory name, platform, LibreNMS status and refusal.
     */
    public function targets(): JsonResponse
    {
        $runs = new RunRepository();
        $changes = new ChangeRepository();
        if (! $runs->available() || ! $changes->available()) {
            return response()->json(['error' => 'The Ansible plugin\'s tables are missing or out of date: run ./lnms migrate.'], 503);
        }
        try {
            $service = $this->inventoryService();
            $inventory = $service->build();
            $devices = $service->devices();
            $inventoryUser = $service->inventoryUser();
            $selection = new TargetSelection($inventory, $devices->exists(), $runs->busy(), $inventoryUser['visible'] ?? null,
                (string) ($inventoryUser['user']->username ?? ''), $changes->reserved());
            $memberships = $devices->memberships();
            $locations = $devices->locations();
            $map = $this->settings()->platformMap();
            $list = [];
            foreach ($devices->devices() as $deviceId => $row) {
                $platform = $map->for((string) $row['os']);
                $list[] = [
                    'id' => $deviceId,
                    'display' => (string) (($row['display'] ?? '') ?: $row['hostname']),
                    'hostname' => (string) $row['hostname'],
                    'name' => $inventory->nameOf($deviceId),
                    'os' => (string) $row['os'],
                    'platform' => $platform['network_os'] ?? null,
                    'status' => $row['disabled'] ? 'disabled' : ($row['status'] ? 'up' : 'down'),
                    'location_id' => $row['location_id'] !== null ? (int) $row['location_id'] : null,
                    'location' => $locations[(int) $row['location_id']] ?? '',
                    'groups' => $memberships[$deviceId] ?? [],
                    'refusal' => $selection->refusal($deviceId),
                ];
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure('target list', $e)], 500);
        }

        return response()->json(['devices' => $list, 'max_targets' => $this->settings()->maxTargets(),
            'inventory_user' => $inventoryUser['user']->username ?? null, 'fetched_at' => Clock::local($inventoryUser['fetch']['fetched_at'] ?? null)]);
    }
}
