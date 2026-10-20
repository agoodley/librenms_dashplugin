<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Illuminate\Support\Facades\DB;

/**
 * Database access of the site / WAN map: the location of every device and the neighbor links
 * between devices at different locations.
 */
class SiteRepository extends ScopedRepository
{
    /** Device ids per query (large IN lists are split). */
    private const CHUNK = 5000;

    /**
     * Devices with their location (the site they belong to). The role comes from the device type
     * only: the site map counts devices, it does not draw them.
     *
     * @param  int[]  $ids  devices the user may see
     * @return array<int, array> device_id => row: display, hostname, status, role, url, bgpLocalAs, location_id,
     *                           location, lat, lng
     */
    public function devices(array $ids): array
    {
        $devices = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
            $rows = DB::table('devices')
                ->leftJoin('locations', 'devices.location_id', '=', 'locations.id')
                ->whereIntegerInRaw('devices.device_id', $chunk)
                ->get([
                    'devices.device_id', 'devices.hostname', 'devices.sysName', 'devices.display', 'devices.hardware',
                    'devices.os', 'devices.type', 'devices.status', 'devices.disabled', 'devices.ignore', 'devices.bgpLocalAs',
                    'devices.location_id', 'locations.location as location', 'locations.lat', 'locations.lng',
                ]);
            foreach ($rows as $row) {
                $row = (array) $row;
                $id = (int) $row['device_id'];
                // a location row can be gone while devices still point at it
                $row['location_id'] = $row['location'] !== null && $row['location_id'] ? (int) $row['location_id'] : null;
                $row['role'] = DeviceRole::classify($row);
                $row['url'] = url('device/' . $id);
                $devices[$id] = $row;
            }
        }

        return $devices;
    }

    /**
     * Active neighbor links (LLDP, CDP...) between two of the given devices that are at different
     * locations (devices without a location count as one location).
     *
     * @param  int[]  $ids  devices the user may see
     * @return array<int, array> links rows
     */
    public function siteLinks(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $links = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
            // compare the location rows, not the ids: a device pointing at a deleted location has none
            foreach (DB::table('links as l')
                ->join('devices as a', 'a.device_id', '=', 'l.local_device_id')
                ->join('devices as b', 'b.device_id', '=', 'l.remote_device_id')
                ->leftJoin('locations as la', 'la.id', '=', 'a.location_id')
                ->leftJoin('locations as lb', 'lb.id', '=', 'b.location_id')
                ->where('l.active', 1)
                ->whereIntegerInRaw('l.local_device_id', $chunk)
                ->whereIntegerInRaw('l.remote_device_id', $ids)
                ->whereRaw('NOT (la.id <=> lb.id)')
                ->get(['l.id', 'l.local_device_id', 'l.local_port_id', 'l.remote_device_id', 'l.remote_port_id', 'l.remote_hostname',
                    'l.remote_port', 'l.remote_platform', 'l.remote_version', 'l.protocol']) as $link) {
                $links[] = (array) $link;
            }
        }

        return $links;
    }
}
