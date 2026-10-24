<?php

namespace Agoodley\LibrenmsAnsible\Data;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The LibreNMS data the inventory and the target checks are built from, as plain arrays: every device with the
 * columns the inventory needs, device group memberships, the names of device groups and locations, and which
 * devices a user may see (LibreNMS' own device permissions).
 */
final class DeviceRepository
{
    /** Device columns the inventory and the pages read. */
    private const COLUMNS = ['device_id', 'hostname', 'sysName', 'display', 'os', 'type', 'hardware', 'version', 'location_id',
        'overwrite_ip', 'disabled', 'status', 'ignore'];

    /** @var array<int, array>|null */
    private ?array $devices = null;

    /**
     * Every device, enabled or not: the inventory names are computed over all of them.
     *
     * @return array<int, array> device_id => row
     */
    public function devices(): array
    {
        if ($this->devices === null) {
            $this->devices = [];
            foreach (DB::table('devices')->orderBy('device_id')->get(self::COLUMNS) as $row) {
                $this->devices[(int) $row->device_id] = (array) $row;
            }
        }

        return $this->devices;
    }

    /**
     * The names of these devices' ports as LibreNMS knows them (ifName; deleted ports left out): what a fix of port
     * descriptions may name.
     *
     * @param  int[]  $deviceIds
     * @return array<int, string[]> device_id => ifNames
     */
    public function interfaces(array $deviceIds): array
    {
        $interfaces = array_fill_keys(array_map('intval', $deviceIds), []);
        foreach (array_chunk(array_keys($interfaces), 500) as $chunk) {
            foreach (DB::table('ports')->whereIn('device_id', $chunk)->where('deleted', 0)->whereNotNull('ifName')->get(['device_id', 'ifName']) as $row) {
                $interfaces[(int) $row->device_id][] = (string) $row->ifName;
            }
        }

        return $interfaces;
    }

    /**
     * Device ids that exist.
     *
     * @return array<int, true>
     */
    public function exists(): array
    {
        return array_fill_keys(array_keys($this->devices()), true);
    }

    /**
     * Device group memberships.
     *
     * @return array<int, int[]> device_id => device group ids
     */
    public function memberships(): array
    {
        $memberships = [];
        try {
            foreach (DB::table('device_group_device')->get(['device_id', 'device_group_id']) as $row) {
                $memberships[(int) $row->device_id][] = (int) $row->device_group_id;
            }
        } catch (\Throwable $e) {
            Log::debug('ansible plugin: cannot read device groups: ' . \Agoodley\LibrenmsAnsible\Support\ErrorText::forLog($e));
        }

        return $memberships;
    }

    /**
     * Every device group.
     *
     * @return array<int, string> id => name
     */
    public function deviceGroups(): array
    {
        try {
            return DB::table('device_groups')->orderBy('name')->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
        } catch (\Throwable $e) {
            Log::debug('ansible plugin: cannot read device groups: ' . \Agoodley\LibrenmsAnsible\Support\ErrorText::forLog($e));

            return [];
        }
    }

    /**
     * Every location.
     *
     * @return array<int, string> id => name
     */
    public function locations(): array
    {
        try {
            return DB::table('locations')->orderBy('location')->pluck('location', 'id')->map(fn ($n) => (string) $n)->all();
        } catch (\Throwable $e) {
            Log::debug('ansible plugin: cannot read locations: ' . \Agoodley\LibrenmsAnsible\Support\ErrorText::forLog($e));

            return [];
        }
    }

    /**
     * The devices a LibreNMS user may see, disabled ones included (the inventory says why it leaves them out).
     *
     * @param  \App\Models\User  $user
     * @return array<int, true>
     */
    public function visibleTo($user): array
    {
        return array_fill_keys(array_map('intval', Device::hasAccess($user)->pluck('devices.device_id')->all()), true);
    }

    /**
     * Display names of devices.
     *
     * @param  int[]  $ids
     * @return array<int, string> device_id => display name (or hostname)
     */
    public function names(array $ids): array
    {
        $devices = $this->devices();
        $names = [];
        foreach ($ids as $id) {
            if (isset($devices[$id])) {
                $names[$id] = (string) (($devices[$id]['display'] ?? '') ?: $devices[$id]['hostname']);
            }
        }

        return $names;
    }
}
