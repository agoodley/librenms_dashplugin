<?php

namespace Agoodley\LibrenmsInventory\Data;

use Illuminate\Support\Facades\DB;

/**
 * Data\Network from LibreNMS' tables: devices, device_groups and the chassis of the hardware inventory (entPhysical).
 * The scheduled check reads every device; a page reads the devices its user may see (Data\InventoryRepository), never
 * through this class.
 */
final class LibrenmsNetwork implements Network
{
    /** Device ids per query. */
    private const CHUNK = 2000;

    public function devices(string $group = ''): array
    {
        $query = DB::table('devices')->where('disabled', 0);
        if ($group !== '') {
            $groupId = DB::table('device_groups')->where('name', $group)->value('id');
            if ($groupId === null) {
                throw new \InvalidArgumentException("LibreNMS has no device group $group (the Devices setting of the inventory plugin)");
            }
            $query->whereIn('device_id', DB::table('device_group_device')->where('device_group_id', $groupId)->select('device_id'));
        }
        $devices = [];
        foreach ($query->orderBy('device_id')->get(['device_id', 'hostname', 'display', 'os', 'hardware', 'version']) as $row) {
            $hardware = trim((string) $row->hardware);
            $devices[(int) $row->device_id] = ['name' => (string) ($row->display ?: $row->hostname), 'os' => (string) $row->os, 'hardware' => $hardware,
                'version' => trim((string) $row->version), 'models' => $hardware !== '' ? [$hardware] : []];
        }
        foreach (array_chunk(array_keys($devices), self::CHUNK) as $chunk) {
            foreach (DB::table('entPhysical')->whereIntegerInRaw('device_id', $chunk)->where('entPhysicalClass', 'chassis')->where('entPhysicalModelName', '<>', '')
                ->get(['device_id', 'entPhysicalModelName']) as $row) {
                $model = trim((string) $row->entPhysicalModelName);
                if ($model !== '' && ! in_array($model, $devices[(int) $row->device_id]['models'], true)) {
                    $devices[(int) $row->device_id]['models'][] = $model;
                }
            }
        }

        return $devices;
    }

    public function deviceIds(): array
    {
        return array_map('intval', DB::table('devices')->pluck('device_id')->all());
    }

    public function groups(): array
    {
        return array_map('strval', DB::table('device_groups')->orderBy('name')->pluck('name')->all());
    }
}
