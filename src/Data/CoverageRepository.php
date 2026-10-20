<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Coverage\CoverageAnalyzer;
use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

/**
 * Database access of the coverage report: the LLDP/CDP rows around the analyzed devices, the names of
 * the devices the user may see, MAC address counts of access ports, and the LibreNMS settings that
 * decide whether LLDP/CDP is read and neighbours are added. Of the devices the user may not see,
 * nothing leaves this class but that LibreNMS linked a neighbour to one of them (its name is the
 * neighbour's, which LibreNMS shows on the device's neighbours tab too).
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
     * name them, streamed from the database in chunks every time they are read (a network can have tens
     * of thousands, mostly phones). A remote device the user may not see loses its ids and the row gets
     * 'hidden' (monitored, out of the user's sight); one that no longer exists only loses its ids (not
     * monitored); a disabled device the user may see keeps its id.
     *
     * @param  int[]  $ids
     * @return LazyCollection<int, array>
     */
    public function links(array $ids): LazyCollection
    {
        $accessible = $this->accessible();
        $disabled = $this->disabledDevices();
        $existing = array_fill_keys(array_map('intval', DB::table('devices')->pluck('device_id')->all()), true);
        // when the scope is every device the user may see, its own rows already hold every reporter
        $everything = ! array_diff_key($accessible, array_flip($ids));
        $visible = function (object $row) use ($accessible, $disabled, $existing): array {
            $row = (array) $row;
            $rd = (int) $row['remote_device_id'];
            $row['hidden'] = false;
            if ($rd && ! isset($accessible[$rd]) && ! isset($disabled[$rd])) {
                $row['hidden'] = isset($existing[$rd]);
                $row['remote_device_id'] = 0;
                $row['remote_port_id'] = null;
            }

            return $row;
        };

        $allowed = $this->allowsTable('links');

        return LazyCollection::make(function () use ($ids, $accessible, $everything, $visible, $allowed) {
            if (! $allowed) {
                return;
            }
            foreach (array_chunk($ids, 10000) as $chunk) {
                foreach (DB::table('links')->where('active', 1)->whereIntegerInRaw('local_device_id', $chunk)
                    ->select(self::LINK_COLUMNS)->lazyById(2000, 'id') as $row) {
                    yield $visible($row);
                }
            }
            if ($everything) {
                return;
            }
            $scope = array_flip($ids);
            foreach (array_chunk($ids, 10000) as $chunk) {
                foreach (DB::table('links')->where('active', 1)->whereIntegerInRaw('remote_device_id', $chunk)
                    ->select(self::LINK_COLUMNS)->lazyById(2000, 'id') as $row) {
                    $ld = (int) $row->local_device_id;
                    if (isset($accessible[$ld]) && ! isset($scope[$ld])) {
                        yield $visible($row);
                    }
                }
            }
        });
    }

    /**
     * Names of LLDP/CDP neighbours of the given devices that are IP addresses: a neighbour without a
     * system name is known by its address, which may belong to a monitored device.
     *
     * @param  int[]  $ids
     * @return string[]
     */
    public function addressNames(array $ids): array
    {
        $names = [];
        foreach ($this->allowsTable('links') ? array_chunk($ids, 10000) : [] as $chunk) {
            foreach (DB::table('links')->where('active', 1)->whereIntegerInRaw('local_device_id', $chunk)
                ->where(fn ($query) => $query->where('remote_hostname', 'like', '%.%.%.%')->orWhere('remote_hostname', 'like', '%:%'))
                ->distinct()->pluck('remote_hostname') as $name) {
                $name = trim((string) $name);
                if (filter_var($name, FILTER_VALIDATE_IP)) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * The devices the user may see, also disabled ones, with what identifies them as a neighbour: the
     * lower case hostname, sysName and display name, and the given addresses when they belong to one of
     * their interfaces. Neighbours are only matched against these, like LibreNMS links them.
     *
     * @param  string[]  $addresses  neighbour names that are IP addresses
     * @return array<int, array{name: string, hostname: string, sysname: string, names: string[], disabled: bool}>
     */
    public function knownDevices(array $addresses = []): array
    {
        $known = [];
        foreach (Device::hasAccess($this->user)->toBase()
            ->get(['devices.device_id', 'devices.hostname', 'devices.sysName', 'devices.display', 'devices.disabled']) as $row) {
            $hostname = strtolower(trim((string) $row->hostname));
            $sysName = strtolower(trim((string) $row->sysName));
            $known[(int) $row->device_id] = [
                'name' => (string) ($row->display ?: $row->hostname),
                'hostname' => $hostname,
                'sysname' => $sysName,
                'names' => array_values(array_unique(array_filter([$hostname, $sysName, strtolower(trim((string) $row->display))]))),
                'disabled' => (bool) $row->disabled,
            ];
        }

        foreach ($this->addressOwners($addresses) as $address => $deviceIds) {
            foreach (array_unique($deviceIds) as $deviceId) {
                if (isset($known[$deviceId])) {
                    $known[$deviceId]['names'][] = $address;
                }
            }
        }

        return $known;
    }

    /**
     * Ports of the given devices that can have an unmanaged switch behind them: Ethernet ports (or of
     * no reported type) that exist and are no Cisco trunk.
     *
     * @param  int[]  $ids
     * @return array<int, int> port_id => device_id
     */
    public function accessPorts(array $ids): array
    {
        $types = array_values(array_filter(CoverageAnalyzer::ETHERNET_TYPES));
        $ports = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('ports')->whereIntegerInRaw('device_id', $chunk)->where('deleted', 0)
                ->where(fn ($query) => $query->whereIn('ifType', $types)->orWhereNull('ifType')->orWhere('ifType', ''))
                ->where(fn ($query) => $query->whereNull('ifTrunk')->orWhereIn('ifTrunk', CoverageAnalyzer::NOT_TRUNK))
                ->get(['port_id', 'device_id']) as $row) {
                $ports[(int) $row->port_id] = (int) $row->device_id;
            }
        }

        return $ports;
    }

    /**
     * MAC address counts of the given ports in the last forwarding table discovery of their device, for
     * the ports with at least $min addresses: how many, in how many and which VLANs, and when. Only these
     * ports are read, not the uplinks and trunks that hold most of the forwarding table.
     *
     * @param  array<int, int>  $ports  port_id => device_id, see accessPorts()
     * @return array<int, array{device_id: int, macs: int, vlans: int[], vlan_count: int, last_seen: int}> port_id => counts
     */
    public function busyPorts(array $ports, int $min): array
    {
        if (! $ports || $min < 1 || ! $this->allowsTable('ports_fdb')) {
            return [];
        }

        try {
            $discovered = [];
            foreach (array_chunk(array_values(array_unique($ports)), 5000) as $chunk) {
                foreach (DB::table('devices')->whereIntegerInRaw('device_id', $chunk)
                    ->get(['device_id', 'last_discovered', 'last_discovered_timetaken']) as $row) {
                    $discovered[(int) $row->device_id] = [
                        $row->last_discovered ? (strtotime((string) $row->last_discovered) ?: null) : null,
                        (float) $row->last_discovered_timetaken,
                    ];
                }
            }

            // rows per port and discovery that found them last, aggregated while they are read: a row is one address
            // in one VLAN, so at least as many as the addresses; the ports that reach $min are counted exactly below
            $stamps = [];
            $groups = function () use ($ports, &$stamps) {
                foreach (array_chunk(array_keys($ports), 2000) as $chunk) {
                    foreach (DB::table('ports_fdb')->whereIntegerInRaw('port_id', $chunk)->whereNotNull('updated_at')
                        ->groupBy('port_id', 'updated_at')->selectRaw('port_id, updated_at, COUNT(*) as macs')->get() as $row) {
                        yield [
                            'port_id' => (int) $row->port_id,
                            'device_id' => $ports[(int) $row->port_id] ?? 0,
                            'seen' => $stamps[$row->updated_at] ??= (int) strtotime((string) $row->updated_at),
                            'macs' => (int) $row->macs,
                        ];
                    }
                }
            };
            $current = CoverageAnalyzer::currentMacCounts($groups(), $discovered, $min);

            // the addresses of the last discovery and their VLANs: ports_fdb.vlan_id is the id of the device's vlans
            // row (0 without one; only the timos and edgeswitch discovery store the VLAN number itself)
            $macs = [];
            $vlanIds = [];
            $vlans = [];
            foreach (array_chunk(array_keys($current), 2000) as $chunk) {
                foreach (DB::table('ports_fdb as f')
                    ->leftJoin('vlans as v', function ($join): void {
                        $join->on('v.vlan_id', '=', 'f.vlan_id')->on('v.device_id', '=', 'f.device_id');
                    })
                    ->whereIntegerInRaw('f.port_id', $chunk)
                    ->get(['f.port_id', 'f.mac_address', 'f.vlan_id', 'f.updated_at', 'v.vlan_vlan']) as $row) {
                    $portId = (int) $row->port_id;
                    if (($stamps[$row->updated_at] ??= (int) strtotime((string) $row->updated_at)) !== $current[$portId]['last_seen']) {
                        continue;
                    }
                    $macs[$portId][strtolower((string) $row->mac_address)] = true;
                    $vlanIds[$portId][(int) $row->vlan_id] = true;
                    $vlan = $row->vlan_vlan !== null ? (int) $row->vlan_vlan : (int) $row->vlan_id;
                    if ($vlan > 0 && $vlan < 4095) {
                        $vlans[$portId][$vlan] = true;
                    }
                }
            }

            $busy = [];
            foreach ($current as $portId => $port) {
                $count = count($macs[$portId] ?? []);
                if ($count >= $min) {
                    $busy[$portId] = [
                        'device_id' => $port['device_id'],
                        'macs' => $count,
                        'vlans' => array_keys($vlans[$portId] ?? []),
                        'vlan_count' => count($vlanIds[$portId] ?? []),
                        'last_seen' => $port['last_seen'],
                    ];
                }
            }

            return $busy;
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot count MAC addresses: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Devices whose discovery-protocols module is switched off in LibreNMS, so their LLDP/CDP tables
     * are never read. Decided like LibreNMS decides (Util\ModuleList): the device's own setting, else
     * the setting for its OS, else the global one.
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

        $global = self::config('discovery_modules.discovery-protocols');
        $byOs = [];
        $off = [];
        foreach ($devices as $deviceId => $device) {
            $os = (string) ($device['os'] ?? '');
            $osSetting = $byOs[$os] ??= [self::config("os.$os.discovery_modules.discovery-protocols")];
            $enabled = match (true) {
                $global === null => false, // not a discovery module of this LibreNMS
                isset($attributes[$deviceId]) => (bool) $attributes[$deviceId],
                $osSetting[0] !== null => (bool) $osSetting[0],
                default => (bool) $global,
            };
            if (! $enabled) {
                $off[(int) $deviceId] = true;
            }
        }

        return $off;
    }

    /**
     * Does LibreNMS add LLDP/CDP neighbours as new devices? 'on', 'off', or 'no_nets' when
     * autodiscovery.xdp is on but no discovery networks (nets) are set: then it adds none.
     */
    public function xdpAutodiscovery(): string
    {
        if (self::config('autodiscovery.xdp', true) !== true) {
            return 'off';
        }

        return self::config('nets') ? 'on' : 'no_nets';
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
     * A LibreNMS setting, through the LibrenmsConfig facade (LibreNMS\Config on older versions).
     */
    private static function config(string $key, mixed $default = null): mixed
    {
        try {
            return class_exists(\App\Facades\LibrenmsConfig::class)
                ? \App\Facades\LibrenmsConfig::get($key, $default)
                : \LibreNMS\Config::get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
