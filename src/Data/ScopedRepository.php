<?php

namespace Agoodley\LibrenmsTopology\Data;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Base of the plugin's repositories: the devices the logged in user may see, and helpers that keep
 * every query inside them. Each feature keeps its queries in its own repository in src/Data that
 * extends this class; everything is returned as plain arrays for the LibreNMS independent analyzers.
 */
abstract class ScopedRepository
{
    /** @var array<int, true>|null */
    private ?array $accessible = null;

    /**
     * @param  mixed  $user  the logged in LibreNMS user (App\Models\User); every query is limited to what it may see
     */
    public function __construct(protected readonly mixed $user)
    {
    }

    /**
     * Another repository of the plugin for the same user.
     *
     * @template T of ScopedRepository
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function sibling(string $class): self
    {
        return new $class($this->user);
    }

    /**
     * Devices the user may see, optionally limited to a device group and/or location.
     *
     * @return int[]
     */
    public function deviceIds(?int $groupId = null, ?int $locationId = null): array
    {
        $query = Device::hasAccess($this->user)->where('devices.disabled', 0);
        if ($groupId) {
            $query->inDeviceGroup($groupId);
        }
        if ($locationId) {
            $query->where('devices.location_id', $locationId);
        }

        return array_map('intval', $query->pluck('devices.device_id')->all());
    }

    /**
     * Device scope of a page: the group / location filter, widened to every device the user may see
     * when the centered device is outside it; a centered device the user may not see is dropped.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p  see MapParams::parse()
     * @return array{0: int[], 1: int|null} device ids, centered device
     */
    public function scope(array $p): array
    {
        $scope = $this->deviceIds($p['group'] ?? null, $p['location'] ?? null);
        $root = ($p['device'] ?? null) ?: null;
        if ($root && ! in_array($root, $scope, true)) {
            $all = $this->deviceIds();
            if (in_array($root, $all, true)) {
                $scope = $all;
            } else {
                $root = null; // not visible to this user
            }
        }

        return [$scope, $root];
    }

    /**
     * May the user see this device (enabled and permitted)?
     */
    public function canSee(int $deviceId): bool
    {
        return isset($this->accessible()[$deviceId]);
    }

    /**
     * Device groups and locations the user may filter by.
     */
    public function filterOptions(): array
    {
        $groups = [];
        $locations = [];
        try {
            $groups = \App\Models\DeviceGroup::hasAccess($this->user)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($g) => ['id' => (int) $g->id, 'name' => (string) $g->name])->all();
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot list device groups: ' . $e->getMessage());
        }
        try {
            $locations = \App\Models\Location::hasAccess($this->user)->orderBy('location')->get(['id', 'location'])
                ->map(fn ($l) => ['id' => (int) $l->id, 'name' => (string) $l->location])->all();
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot list locations: ' . $e->getMessage());
        }

        return ['groups' => $groups, 'locations' => $locations];
    }

    /**
     * Display name of a device, null when the user may not see it.
     */
    public function deviceName(int $deviceId): ?string
    {
        if (! $this->canSee($deviceId)) {
            return null;
        }
        $row = DB::table('devices')->where('device_id', $deviceId)->first(['display', 'hostname']);

        return $row ? (string) ($row->display ?: $row->hostname) : null;
    }

    /**
     * @return array<int, true> device ids the user may see
     */
    protected function accessible(): array
    {
        if ($this->accessible === null) {
            $this->accessible = array_flip($this->deviceIds());
        }

        return $this->accessible;
    }

    /**
     * Display names of devices the caller already scoped.
     *
     * @param  int[]  $deviceIds
     * @return array<int, string> device_id => display name
     */
    protected function deviceNames(array $deviceIds): array
    {
        $deviceIds = array_values(array_unique($deviceIds));
        if (! $deviceIds) {
            return [];
        }

        $names = [];
        foreach (DB::table('devices')->whereIntegerInRaw('device_id', $deviceIds)->get(['device_id', 'display', 'hostname']) as $row) {
            $names[(int) $row->device_id] = (string) ($row->display ?: $row->hostname);
        }

        return $names;
    }

    /**
     * All rows of a per-device table for the given devices, or none when the table does not exist on
     * this LibreNMS version.
     *
     * @param  int[]  $ids
     * @param  string[]  $columns
     * @return array<int, array>
     */
    protected function rows(string $table, array $ids, array $columns): array
    {
        if (! $ids) {
            return [];
        }

        try {
            return DB::table($table)->whereIntegerInRaw('device_id', $ids)->get($columns)->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable $e) {
            Log::debug("topology plugin: cannot read $table: " . $e->getMessage());

            return [];
        }
    }

    /**
     * Devices among $ids with at least one row in a per-device table (none when the table does not exist).
     *
     * @param  int[]  $ids
     * @return int[]
     */
    protected function distinctDevices(string $table, array $ids): array
    {
        try {
            return array_map('intval', DB::table($table)->whereIntegerInRaw('device_id', $ids)->distinct()->pluck('device_id')->all());
        } catch (\Throwable $e) {
            Log::debug("topology plugin: cannot read $table: " . $e->getMessage());

            return [];
        }
    }
}
