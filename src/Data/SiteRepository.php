<?php

namespace Agoodley\LibrenmsTopology\Data;

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
     * Location (the site) of each device: id, name and coordinates. A device whose location row is
     * gone has none.
     *
     * @param  int[]  $ids  devices the user may see
     * @return array<int, array{location_id: int|null, location: string|null, lat: mixed, lng: mixed}> device_id => location
     */
    public function locations(array $ids): array
    {
        $locations = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
            foreach (DB::table('devices')->leftJoin('locations', 'devices.location_id', '=', 'locations.id')
                ->whereIntegerInRaw('devices.device_id', $chunk)
                ->get(['devices.device_id', 'locations.id as location_id', 'locations.location', 'locations.lat', 'locations.lng']) as $row) {
                $locations[(int) $row->device_id] = [
                    'location_id' => $row->location_id !== null ? (int) $row->location_id : null,
                    'location' => $row->location,
                    'lat' => $row->lat,
                    'lng' => $row->lng,
                ];
            }
        }

        return $locations;
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
