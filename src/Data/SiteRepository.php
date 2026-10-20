<?php

namespace Agoodley\LibrenmsTopology\Data;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Database access of the site / WAN map: the location of every device, the neighbor links and
 * point-to-point addresses that can join locations, the interface stack under those addresses and
 * the devices owning the addresses of routing neighbors.
 */
class SiteRepository extends ScopedRepository
{
    /** Device ids or addresses per query (large IN lists are split). */
    private const CHUNK = 5000;

    /** From this many devices on, subnetAddresses() scans the address tables instead of listing the devices. */
    private const SCAN_FROM = 1000;

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

    /**
     * Interface addresses that can make a point-to-point subnet (IPv4 with a prefix of at least
     * $prefix4, IPv6 of at least $prefix6, no host routes), in the format of
     * TopologyRepository::addresses(). The rest of an address plan (LANs, loopbacks) never joins
     * two sites, so it is not loaded.
     *
     * @param  int[]  $ids  devices the user may see
     * @return array<int, array> rows: device_id, port_id, ifName, ifDescr, ifAlias, ifOperStatus, ifType, address, prefixlen, vrf
     */
    public function subnetAddresses(array $ids, int $prefix4, int $prefix6): array
    {
        $ids = array_values(array_unique($ids));
        if (! $ids) {
            return [];
        }

        // a long device list makes the database look up the addresses of every port of those devices: from
        // SCAN_FROM devices on, scanning the address tables for the few point-to-point prefixes is much faster
        $wanted = array_flip($ids);
        $rows = [];
        foreach (count($ids) >= self::SCAN_FROM ? [null] : array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ([['ipv4_addresses', 'ipv4_address', 'ipv4_prefixlen', $prefix4, 32], ['ipv6_addresses', 'ipv6_compressed', 'ipv6_prefixlen', $prefix6, 128]]
                as [$table, $address, $prefixlen, $min, $host]) {
                $query = DB::table($table . ' as a')->join('ports as p', 'p.port_id', '=', 'a.port_id')->where('p.deleted', 0)
                    ->where('a.' . $prefixlen, '>=', $min)->where('a.' . $prefixlen, '<', $host);
                if ($chunk !== null) {
                    $query->whereIntegerInRaw('p.device_id', $chunk);
                }
                foreach ($query->get(['p.device_id', 'p.port_id', 'p.ifName', 'p.ifDescr', 'p.ifAlias', 'p.ifOperStatus', 'p.ifType', 'p.ifVrf',
                    'a.' . $address . ' as address', 'a.' . $prefixlen . ' as prefixlen', 'a.context_name']) as $row) {
                    if (isset($wanted[(int) $row->device_id])) {
                        $rows[] = (array) $row;
                    }
                }
            }
        }

        $vrfIds = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'ifVrf')))));
        $vrfs = $vrfIds ? DB::table('vrfs')->whereIntegerInRaw('vrf_id', $vrfIds)->pluck('vrf_name', 'vrf_id')->all() : [];
        foreach ($rows as &$row) {
            $row['vrf'] = (string) (($row['ifVrf'] ? ($vrfs[$row['ifVrf']] ?? '') : '') ?: ($row['context_name'] ?? ''));
        }
        unset($row);

        return $rows;
    }

    /**
     * Devices the user may see that have one of the given interface addresses, to find the routers
     * behind OSPF neighbors and BGP peers. Every visible device counts, not only those on the map:
     * a session to a device outside the map's scope is not a session to an unknown network.
     *
     * @param  string[]  $addresses  IPv4 / IPv6 addresses
     * @return array<string, array<int, array{device_id: int, prefixlen: int, vrf: string}>> address (canonical form) =>
     *     owners (a device once per VRF), by device id
     */
    public function addressOwners(array $addresses): array
    {
        $wanted = [4 => [], 6 => []];
        foreach ($addresses as $address) {
            $packed = @inet_pton(trim((string) $address));
            if ($packed !== false) {
                $family = strlen($packed) === 4 ? 4 : 6;
                $wanted[$family][inet_ntop($packed)] = true;
                $wanted[$family][strtoupper(inet_ntop($packed))] = true;
            }
        }

        $rows = [];
        foreach ([4 => ['ipv4_addresses', 'ipv4_address', 'ipv4_prefixlen'], 6 => ['ipv6_addresses', 'ipv6_compressed', 'ipv6_prefixlen']]
            as $family => [$table, $address, $prefixlen]) {
            foreach (array_chunk(array_keys($wanted[$family]), self::CHUNK) as $chunk) {
                // the address columns are not indexed: comparing bytes rather than collated text makes the scan several times faster
                foreach (DB::table($table . ' as a')->join('ports as p', 'p.port_id', '=', 'a.port_id')
                    ->whereIn(DB::raw('CAST(a.' . $address . ' AS BINARY)'), $chunk)->where('p.deleted', 0)
                    ->get(['p.device_id', 'p.ifVrf', 'a.' . $address . ' as address', 'a.' . $prefixlen . ' as prefixlen', 'a.context_name']) as $row) {
                    if ($this->canSee((int) $row->device_id)) {
                        $rows[] = (array) $row;
                    }
                }
            }
        }

        $vrfIds = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'ifVrf')))));
        $vrfs = $vrfIds ? DB::table('vrfs')->whereIntegerInRaw('vrf_id', $vrfIds)->pluck('vrf_name', 'vrf_id')->all() : [];
        $owners = [];
        foreach ($rows as $row) {
            $packed = @inet_pton((string) $row['address']);
            if ($packed === false) {
                continue;
            }
            $vrf = (string) (($row['ifVrf'] ? ($vrfs[$row['ifVrf']] ?? '') : '') ?: ($row['context_name'] ?? ''));
            $owners[inet_ntop($packed)][$row['device_id'] . '|' . $vrf] ??= ['device_id' => (int) $row['device_id'], 'prefixlen' => (int) $row['prefixlen'], 'vrf' => $vrf];
        }
        foreach ($owners as &$list) {
            usort($list, fn (array $a, array $b) => [$a['device_id'], $a['vrf']] <=> [$b['device_id'], $b['vrf']]);
        }
        unset($list);

        return $owners;
    }

    /**
     * Interface stack pairs of the devices (IF-MIB ifStackTable) as port id pairs, in no particular
     * order: since LibreNMS 24.8 ports_stack keeps the higher layer (a sub-interface, a port-channel)
     * in low_port_id, before it kept it as the ifIndex in port_id_high. SiteConnections::stack() tells
     * the upper port from the lower one by the ports themselves.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array{0: int, 1: int}>
     */
    public function stackPairs(array $deviceIds): array
    {
        if (! $deviceIds) {
            return [];
        }

        $pairs = [];
        $byIndex = [];
        try {
            foreach (DB::table('ports_stack')->whereIntegerInRaw('device_id', $deviceIds)
                ->get(['device_id', 'high_ifIndex', 'low_ifIndex', 'high_port_id', 'low_port_id']) as $row) {
                if ($row->high_port_id && $row->low_port_id) {
                    $pairs[] = [(int) $row->high_port_id, (int) $row->low_port_id];
                } else {
                    $byIndex[] = [(int) $row->device_id, (int) $row->high_ifIndex, (int) $row->low_ifIndex];
                }
            }
        } catch (\Throwable $e) {
            try {
                foreach (DB::table('ports_stack')->whereIntegerInRaw('device_id', $deviceIds)->get(['device_id', 'port_id_high', 'port_id_low']) as $row) {
                    $byIndex[] = [(int) $row->device_id, (int) $row->port_id_high, (int) $row->port_id_low];
                }
            } catch (\Throwable $e) {
                Log::debug('topology plugin: cannot read ports_stack: ' . $e->getMessage());
            }
        }

        // rows without port ids (and the schema before 24.8) name the ports by ifIndex
        if ($byIndex) {
            $ports = [];
            foreach (DB::table('ports')->whereIntegerInRaw('device_id', array_values(array_unique(array_column($byIndex, 0))))
                ->get(['port_id', 'device_id', 'ifIndex']) as $port) {
                $ports[$port->device_id . ':' . $port->ifIndex] = (int) $port->port_id;
            }
            foreach ($byIndex as [$deviceId, $high, $low]) {
                if (isset($ports["$deviceId:$high"], $ports["$deviceId:$low"])) {
                    $pairs[] = [$ports["$deviceId:$high"], $ports["$deviceId:$low"]];
                }
            }
        }

        return array_values(array_filter($pairs, fn (array $pair) => $pair[0] > 0 && $pair[1] > 0 && $pair[0] !== $pair[1]));
    }

    /**
     * Ports by interface name (ifName or ifDescr), such as the parent of a sub-interface that the
     * interface stack does not list.
     *
     * @param  array<int, string[]>  $names  device_id => names
     * @return array<int, array<string, int>> device_id => name => port_id
     */
    public function portsByName(array $names): array
    {
        $names = array_filter($names);
        if (! $names) {
            return [];
        }

        $all = array_values(array_unique(array_merge(...array_values($names))));
        $found = [];
        foreach (DB::table('ports')->whereIntegerInRaw('device_id', array_keys($names))->where('deleted', 0)
            ->where(fn ($query) => $query->whereIn('ifName', $all)->orWhereIn('ifDescr', $all))
            ->orderBy('port_id')
            ->get(['port_id', 'device_id', 'ifName', 'ifDescr']) as $row) {
            foreach ([$row->ifName, $row->ifDescr] as $name) {
                if ($name !== null && in_array($name, $names[(int) $row->device_id] ?? [], true)) {
                    $found[(int) $row->device_id][$name] ??= (int) $row->port_id;
                }
            }
        }

        return $found;
    }
}
