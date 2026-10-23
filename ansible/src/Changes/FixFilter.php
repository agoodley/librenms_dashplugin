<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * The Fixes page's filter, as the topology plugin's pages filter (pure): a device, a device group, a location, each one
 * given narrowing the devices whose fixes are listed (those the admin sees); the topology plugin computes the fixes of
 * those devices only, at most MAX_DEVICES at once (more: of every device, then kept for them as it keeps them).
 */
final class FixFilter
{
    /** The filters, as the page's query names them. */
    public const NAMES = ['device', 'group', 'location'];

    /** Most devices whose fixes the topology plugin computes in one call (its verification API's MAX_DEVICES). */
    public const MAX_DEVICES = 5000;

    /**
     * The filter in a page's query: name => number, each one given; null when one is not a number.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, int>|null
     */
    public static function parse(array $query): ?array
    {
        $filter = [];
        foreach (self::NAMES as $name) {
            $value = $query[$name] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_string($value) || ! preg_match('/^[1-9]\d{0,9}$/D', $value)) {
                return null;
            }
            $filter[$name] = (int) $value;
        }

        return $filter;
    }

    /**
     * The devices the filter keeps among those the user sees (enabled ones: a disabled device has no fixes): the device, the
     * members of the group, those at the location.
     *
     * @param  array<string, int>  $filter
     * @param  array<int, array>  $devices  device_id => row (location_id, disabled)
     * @param  array<int, int[]>  $memberships  device_id => device group ids
     * @param  array<int, true>  $visible  the devices the user sees
     * @return int[]
     */
    public static function keep(array $filter, array $devices, array $memberships, array $visible): array
    {
        $kept = [];
        foreach ($devices as $deviceId => $device) {
            if (! isset($visible[(int) $deviceId]) || ! empty($device['disabled']) || (isset($filter['device']) && (int) $deviceId !== $filter['device'])
                || (isset($filter['group']) && ! in_array($filter['group'], $memberships[(int) $deviceId] ?? [], true))
                || (isset($filter['location']) && (int) ($device['location_id'] ?? 0) !== $filter['location'])) {
                continue;
            }
            $kept[] = (int) $deviceId;
        }

        return $kept;
    }

    /**
     * The fixes of these devices, as the topology plugin keeps them for some devices: a fix of one of them, or of a finding
     * about one.
     *
     * @param  list<array>  $fixes
     * @param  int[]  $ids
     * @return list<array>
     */
    public static function ofDevices(array $fixes, array $ids): array
    {
        $wanted = array_fill_keys($ids, true);
        $of = fn (array $devices) => (bool) array_intersect_key(array_flip(array_map('intval', array_filter($devices, 'is_scalar'))), $wanted);

        return array_values(array_filter($fixes, fn ($fix) => is_array($fix) && ($of((array) ($fix['devices'] ?? []))
            || array_filter((array) ($fix['findings'] ?? []), fn ($finding) => is_array($finding) && $of((array) ($finding['devices'] ?? []))))));
    }
}
