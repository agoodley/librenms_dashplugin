<?php

namespace Agoodley\LibrenmsTopology\Data;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Database access of the coverage report: the LLDP/CDP rows around the analyzed devices (keeping
 * which neighbours are monitored, disabled or out of the user's sight), the names of every
 * monitored device, MAC address counts per port, and the LibreNMS settings that decide whether
 * LLDP/CDP is read and neighbours are added.
 */
class CoverageRepository extends ScopedRepository
{
    private const LINK_COLUMNS = [
        'id', 'local_device_id', 'local_port_id', 'remote_device_id', 'remote_port_id', 'remote_hostname', 'remote_port',
        'remote_platform', 'remote_version', 'protocol',
    ];

    /** @var array<int, true>|null */
    private ?array $disabled = null;

    /**
     * LLDP/CDP rows reported by the given devices, and the rows of other devices the user may see that
     * name them. A neighbour monitored on a device the user may not see loses its ids and is flagged
     * 'hidden' (it is neither unmonitored nor shown); a disabled device the user may see keeps its id;
     * an id of a device that no longer exists is dropped (the neighbour is unmonitored).
     *
     * @param  int[]  $ids
     * @return array<int, array>
     */
    public function links(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $accessible = $this->accessible();
        // when the scope is every device the user may see, its own rows already hold every reporter
        $everything = ! array_diff_key($accessible, array_flip($ids));
        $rows = [];
        foreach (array_chunk($ids, 5000) as $chunk) {
            foreach (DB::table('links')->where('active', 1)->whereIntegerInRaw('local_device_id', $chunk)->get(self::LINK_COLUMNS) as $row) {
                $rows[(int) $row->id] = (array) $row;
            }
            if ($everything) {
                continue;
            }
            foreach (DB::table('links')->where('active', 1)->whereIntegerInRaw('remote_device_id', $chunk)->get(self::LINK_COLUMNS) as $row) {
                if (isset($accessible[(int) $row->local_device_id])) {
                    $rows[(int) $row->id] = (array) $row;
                }
            }
        }

        $others = [];
        foreach ($rows as $row) {
            $rd = (int) $row['remote_device_id'];
            if ($rd && ! isset($accessible[$rd])) {
                $others[$rd] = true;
            }
        }
        $existing = [];
        foreach (array_chunk(array_keys($others), 5000) as $chunk) {
            $existing += array_fill_keys(array_map('intval', DB::table('devices')->whereIntegerInRaw('device_id', $chunk)->pluck('device_id')->all()), true);
        }
        $disabled = $this->disabledDevices();

        foreach ($rows as &$row) {
            $rd = (int) $row['remote_device_id'];
            $row['hidden'] = false;
            if (! $rd || isset($accessible[$rd]) || isset($disabled[$rd])) {
                continue;
            }
            $row['hidden'] = isset($existing[$rd]);
            $row['remote_device_id'] = 0;
            $row['remote_port_id'] = null;
        }
        unset($row);

        return array_values($rows);
    }

    /**
     * Every monitored device with what identifies it as a neighbour: its lower case hostname, sysName and
     * display name, and the given addresses when they belong to one of its interfaces. Devices the user may
     * not see get no display name: they only keep their neighbours out of the report.
     *
     * @param  string[]  $addresses  neighbour names that are IP addresses
     * @return array<int, array{name: string, names: string[], visible: bool, disabled: bool}>
     */
    public function knownDevices(array $addresses = []): array
    {
        $accessible = $this->accessible();
        $disabled = $this->disabledDevices();
        $known = [];
        foreach (DB::table('devices')->get(['device_id', 'hostname', 'sysName', 'display', 'disabled']) as $row) {
            $id = (int) $row->device_id;
            $visible = isset($accessible[$id]) || isset($disabled[$id]);
            $known[$id] = [
                'name' => $visible ? (string) ($row->display ?: $row->hostname) : '',
                'names' => array_values(array_unique(array_filter(array_map(fn ($name) => strtolower(trim((string) $name)), [$row->hostname, $row->sysName, $row->display])))),
                'visible' => $visible,
                'disabled' => (bool) $row->disabled,
            ];
        }

        foreach ($this->addressOwners($addresses) as $address => $deviceIds) {
            foreach ($deviceIds as $deviceId) {
                if (isset($known[$deviceId])) {
                    $known[$deviceId]['names'][] = $address;
                }
            }
        }

        return $known;
    }

    /**
     * Ports of the given devices with at least $min MAC addresses in the forwarding table, with the
     * VLANs of those addresses and when one was last seen. Counted in the database, so only the busy
     * ports come back.
     *
     * @param  int[]  $ids
     * @return array<int, array{device_id: int, macs: int, vlans: int[], last_seen: ?int}> port_id => counts
     */
    public function busyPorts(array $ids, int $min): array
    {
        if (! $ids || $min < 1) {
            return [];
        }

        try {
            $seen = Schema::hasColumn('ports_fdb', 'updated_at') ? 'MAX(updated_at)' : 'NULL';
            $ports = [];
            foreach (array_chunk($ids, 5000) as $chunk) {
                foreach (DB::table('ports_fdb')->whereIntegerInRaw('device_id', $chunk)->where('port_id', '>', 0)
                    ->groupBy('port_id', 'device_id')
                    ->havingRaw('COUNT(DISTINCT mac_address) >= ?', [$min])
                    ->selectRaw("port_id, device_id, COUNT(DISTINCT mac_address) as macs, $seen as last_seen")
                    ->get() as $row) {
                    $ports[(int) $row->port_id] = [
                        'device_id' => (int) $row->device_id,
                        'macs' => (int) $row->macs,
                        'vlans' => [],
                        'last_seen' => $row->last_seen ? (strtotime((string) $row->last_seen) ?: null) : null,
                    ];
                }
            }

            // ports_fdb.vlan_id is the id of the device's vlans row, or the VLAN number when there is none
            foreach (array_chunk(array_keys($ports), 5000) as $chunk) {
                foreach (DB::table('ports_fdb as f')
                    ->leftJoin('vlans as v', function ($join): void {
                        $join->on('v.vlan_id', '=', 'f.vlan_id')->on('v.device_id', '=', 'f.device_id');
                    })
                    ->whereIntegerInRaw('f.port_id', $chunk)->distinct()
                    ->get(['f.port_id', 'f.vlan_id', 'v.vlan_vlan']) as $row) {
                    $vlan = $row->vlan_vlan !== null ? (int) $row->vlan_vlan : (int) $row->vlan_id;
                    if ($vlan > 0 && $vlan < 4095) {
                        $ports[(int) $row->port_id]['vlans'][] = $vlan;
                    }
                }
            }

            return $ports;
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot count MAC addresses: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Devices whose discovery-protocols module is switched off in LibreNMS (in the device's settings,
     * else for its OS or globally), so their LLDP/CDP tables are never read.
     *
     * @param  array<int, array>  $devices  device_id => row with os
     * @return array<int, true>
     */
    public function protocolsModuleOff(array $devices): array
    {
        if (! $devices) {
            return [];
        }

        $attributes = [];
        try {
            foreach (array_chunk(array_keys($devices), 5000) as $chunk) {
                $attributes += DB::table('devices_attribs')->whereIntegerInRaw('device_id', $chunk)
                    ->where('attrib_type', 'discover_discovery-protocols')->pluck('attrib_value', 'device_id')->all();
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read device module settings: ' . $e->getMessage());
        }

        $byOs = [];
        $off = [];
        foreach ($devices as $deviceId => $device) {
            if (isset($attributes[$deviceId])) {
                $enabled = (bool) $attributes[$deviceId];
            } else {
                $os = (string) ($device['os'] ?? '');
                $enabled = $byOs[$os] ??= $this->moduleEnabledForOs($os);
            }
            if (! $enabled) {
                $off[(int) $deviceId] = true;
            }
        }

        return $off;
    }

    /**
     * Does LibreNMS add LLDP/CDP neighbours as new devices (autodiscovery.xdp, on by default)?
     */
    public function xdpAutodiscovery(): bool
    {
        try {
            return (bool) \LibreNMS\Config::get('autodiscovery.xdp', true);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Disabled devices the user may see (they are not in deviceIds()).
     *
     * @return array<int, true>
     */
    private function disabledDevices(): array
    {
        if ($this->disabled === null) {
            $this->disabled = array_fill_keys(array_map('intval', Device::hasAccess($this->user)->where('devices.disabled', 1)
                ->pluck('devices.device_id')->all()), true);
        }

        return $this->disabled;
    }

    /**
     * Devices owning the given addresses on one of their interfaces.
     *
     * @param  string[]  $addresses
     * @return array<string, int[]> address => device ids
     */
    private function addressOwners(array $addresses): array
    {
        $v4 = [];
        $v6 = [];
        foreach (array_unique($addresses) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $v4[] = $address;
            } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $v6[strtolower((string) inet_ntop((string) inet_pton($address)))] = $address;
            }
        }

        $owners = [];
        try {
            foreach (array_chunk($v4, 1000) as $chunk) {
                foreach (DB::table('ipv4_addresses as a')->join('ports as p', 'p.port_id', '=', 'a.port_id')
                    ->whereIn('a.ipv4_address', $chunk)->get(['a.ipv4_address', 'p.device_id']) as $row) {
                    $owners[(string) $row->ipv4_address][] = (int) $row->device_id;
                }
            }
            foreach (array_chunk(array_keys($v6), 1000) as $chunk) {
                foreach (DB::table('ipv6_addresses as a')->join('ports as p', 'p.port_id', '=', 'a.port_id')
                    ->whereIn('a.ipv6_compressed', $chunk)->get(['a.ipv6_compressed', 'p.device_id']) as $row) {
                    $owners[strtolower($v6[strtolower((string) $row->ipv6_compressed)] ?? (string) $row->ipv6_compressed)][] = (int) $row->device_id;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read interface addresses: ' . $e->getMessage());
        }

        return $owners;
    }

    /**
     * Is the discovery-protocols module on for this OS (or globally)?
     */
    private function moduleEnabledForOs(string $os): bool
    {
        try {
            return (bool) (\LibreNMS\Config::getCombined($os, 'discovery_modules')['discovery-protocols'] ?? true);
        } catch (\Throwable) {
            return true;
        }
    }
}
