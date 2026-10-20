<?php

namespace Agoodley\LibrenmsTopology\Data;

use App\Models\Device;
use App\Models\Eventlog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LibreNMS\Enum\Severity;

/**
 * LibreNMS device dependencies (device_relationships) and the outage history used to check derived
 * ones, scoped to the devices the user may see.
 */
class DependencyRepository extends ScopedRepository
{
    /**
     * Current parents of the given devices; parents the user may not see are counted, not named.
     *
     * @param  int[]  $ids
     * @return array{0: array<int, int[]>, 1: array<int, int>} child device_id => parent device ids, child => hidden parents
     */
    public function parents(array $ids): array
    {
        $ids = array_values(array_filter($ids, fn ($id) => $this->canSee((int) $id)));
        $parents = [];
        $hidden = [];
        if (! $ids) {
            return [$parents, $hidden];
        }
        foreach (DB::table('device_relationships')->whereIntegerInRaw('child_device_id', $ids)->get(['child_device_id', 'parent_device_id']) as $row) {
            $child = (int) $row->child_device_id;
            $parent = (int) $row->parent_device_id;
            if ($this->canSee($parent)) {
                $parents[$child][] = $parent;
            } else {
                $hidden[$child] = ($hidden[$child] ?? 0) + 1;
            }
        }

        return [$parents, $hidden];
    }

    /**
     * Outages of the given devices that were still going on at $since or started later.
     *
     * @param  int[]  $ids
     * @return array<int, array<int, array{0: int, 1: ?int}>> device_id => [going down, up again or null], oldest first
     */
    public function outages(array $ids, int $since): array
    {
        $ids = array_values(array_filter($ids, fn ($id) => $this->canSee((int) $id)));
        if (! $ids) {
            return [];
        }

        $outages = [];
        try {
            $rows = DB::table('device_outages')->whereIntegerInRaw('device_id', $ids)
                ->where(fn ($query) => $query->whereNull('up_again')->orWhere('up_again', '>=', $since))
                ->orderBy('going_down')
                ->get(['device_id', 'going_down', 'up_again']);
            foreach ($rows as $row) {
                $outages[(int) $row->device_id][] = [(int) $row->going_down, $row->up_again === null ? null : (int) $row->up_again];
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read device_outages: ' . $e->getMessage());
        }

        return $outages;
    }

    /**
     * Replace the parents of devices and note each change in the device's eventlog. Only for users
     * who may change device dependencies (checked by the caller); devices the user may not see are
     * left alone.
     *
     * @param  array<int, int[]>  $parents  child device_id => new parent device ids
     * @param  array<int, string>  $names  device_id => display name, for the eventlog text
     * @return int[] the devices changed
     */
    public function setParents(array $parents, array $names): array
    {
        $parents = array_filter($parents, fn ($ids, $child) => $this->canSee((int) $child) && ! array_filter($ids, fn ($id) => ! $this->canSee((int) $id)),
            ARRAY_FILTER_USE_BOTH);
        if (! $parents) {
            return [];
        }

        $username = $this->user->username ?? 'unknown';
        DB::transaction(function () use ($parents, $names, $username): void {
            foreach (Device::whereIntegerInRaw('device_id', array_keys($parents))->get() as $device) {
                $ids = array_values(array_map('intval', $parents[(int) $device->device_id]));
                $device->parents()->sync($ids);
                $list = implode(', ', array_map(fn ($id) => $names[$id] ?? "#$id", $ids));
                Eventlog::log("Device dependencies set from the topology by $username: parents $list", $device, 'system', Severity::Info);
            }
        });

        return array_map('intval', array_keys($parents));
    }
}
