<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Hosts\HostLocator;
use Agoodley\LibrenmsTopology\Hosts\HostQuery;
use Agoodley\LibrenmsTopology\Hosts\Mac;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Topology\L2Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Database access of the host locator and the port tab: forwarding tables (ports_fdb), ARP
 * (ipv4_mac), IPv6 neighbors (ipv6_nd), 802.1X / MAB sessions (ports_nac) and the OUI table, only
 * from devices the user may see. MACs are LibreNMS' 12 lowercase hex digits.
 *
 * Indexes LibreNMS has: ports_fdb on port, MAC and device; ipv4_mac on MAC, port and device (not on
 * the address); ipv6_nd on port and device only; ports_nac on port + MAC and device; ports on device
 * (not on ifPhysAddress). A search by IPv4 address therefore reads the whole ARP table once, and any
 * IPv6 lookup the whole IPv6 neighbor table once; everything else is looked up by index.
 */
class HostRepository extends ScopedRepository
{
    /** Devices put into a query's permission filter at most; with more, rows are checked after reading. */
    private const SCOPE_IN_QUERY = 1000;

    /** Assumed duration of a discovery whose duration LibreNMS did not record (seconds). */
    private const UNKNOWN_DURATION = 3600;

    /** Slack between the recorded start of a discovery and the forwarding table entries it stamps (seconds). */
    private const READ_MARGIN = 60;

    /**
     * Forwarding table entries of the MACs.
     *
     * @param  string[]  $macs
     * @return array<int, array{mac: string, device_id: int, port_id: int, vlan: ?int, vlan_name: ?string, first_seen: int, last_seen: int}>
     */
    public function fdbByMacs(array $macs): array
    {
        if (! $macs) {
            return [];
        }

        return $this->fdbRows(DB::table('ports_fdb as f')->whereIn('f.mac_address', array_values($macs)));
    }

    /**
     * Forwarding table entries of a port, current ones first.
     *
     * @return array<int, array> see fdbByMacs()
     */
    public function fdbByPort(int $portId, int $limit): array
    {
        return $this->fdbRows(DB::table('ports_fdb as f')->where('f.port_id', $portId)->orderByDesc('f.updated_at')->orderBy('f.mac_address')->limit($limit));
    }

    /**
     * Start of each switch's last forwarding table read: entries updated before it are from an earlier
     * read (LibreNMS stamps every entry it still sees with the time of the read). It is the start of the
     * device's last discovery when an entry confirms that discovery read the table (one indexed probe per
     * device); otherwise (forwarding table module off or failed, never discovered) the newest entry tells,
     * which reads all entries of that device. Devices without entries have no forwarding table data.
     *
     * @param  int[]  $deviceIds
     * @param  array<int, array>  $rows  entries already read (fdbRows()): each proves a read at its time
     * @return array<int, int> device_id => unix time
     */
    public function fdbSince(array $deviceIds, array $rows = []): array
    {
        $deviceIds = $this->visible($deviceIds);
        if (! $deviceIds) {
            return [];
        }

        $since = [];
        try {
            $starts = [];
            foreach (DB::table('devices')->whereIntegerInRaw('device_id', $deviceIds)->get(['device_id', 'last_discovered', 'last_discovered_timetaken']) as $row) {
                $at = self::time($row->last_discovered);
                if ($at > 0) {
                    $took = $row->last_discovered_timetaken === null ? self::UNKNOWN_DURATION : (int) ceil((float) $row->last_discovered_timetaken);
                    $starts[(int) $row->device_id] = $at - $took - self::READ_MARGIN;
                }
            }
            foreach (array_chunk($starts, 100, true) as $chunk) {
                $query = null;
                foreach ($chunk as $deviceId => $start) {
                    $probe = DB::table('ports_fdb')->select('device_id')->where('device_id', $deviceId)->where('updated_at', '>=', self::date($start))->limit(1);
                    $query = $query === null ? $probe : $query->unionAll($probe);
                }
                foreach ($query->get() as $row) {
                    $since[(int) $row->device_id] = $chunk[(int) $row->device_id];
                }
            }

            $rest = array_values(array_diff($deviceIds, array_keys($since)));
            if ($rest) {
                foreach (DB::table('ports_fdb')->whereIntegerInRaw('device_id', $rest)->groupBy('device_id')
                    ->selectRaw('device_id, MAX(updated_at) as newest')->get() as $row) {
                    $since[(int) $row->device_id] = self::time($row->newest) - HostLocator::STALE_AFTER;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read forwarding table times: ' . $e->getMessage());
        }

        foreach ($rows as $row) {
            if (isset($since[$row['device_id']])) {
                $since[$row['device_id']] = max($since[$row['device_id']], $row['last_seen'] - HostLocator::STALE_AFTER);
            }
        }

        return $since;
    }

    /**
     * MACs learned on each port at its switch's last forwarding table read.
     *
     * @param  int[]  $portIds
     * @param  array<int, int>  $since  see fdbSince(); all entries count for a switch missing here
     * @return array<int, int> port_id => distinct MACs
     */
    public function macCounts(array $portIds, array $since): array
    {
        $portIds = self::ids($portIds);
        if (! $portIds) {
            return [];
        }

        $byDevice = [];
        foreach (DB::table('ports')->whereIntegerInRaw('port_id', $portIds)->get(['port_id', 'device_id']) as $row) {
            $byDevice[(int) $row->device_id][] = (int) $row->port_id;
        }

        $counts = [];
        foreach ($byDevice as $deviceId => $ids) {
            if (! $this->canSee($deviceId)) {
                continue;
            }
            $query = DB::table('ports_fdb')->whereIntegerInRaw('port_id', $ids);
            if (isset($since[$deviceId])) {
                $query->where('updated_at', '>=', self::date($since[$deviceId]));
            }
            foreach ($query->groupBy('port_id')->selectRaw('port_id, COUNT(DISTINCT mac_address) as macs')->get() as $row) {
                $counts[(int) $row->port_id] = (int) $row->macs;
            }
        }

        return $counts;
    }

    /**
     * Monitored devices whose own (interface) MAC address each port learned at its switch's last read:
     * a port without LLDP/CDP neighbor that learned a switch's MAC leads toward that switch.
     *
     * @param  int[]  $portIds
     * @param  int[]  $deviceIds  the devices to look for
     * @param  array<int, int>  $since  see fdbSince()
     * @return array<int, int[]> port_id => device ids
     */
    public function switchMacsOn(array $portIds, array $deviceIds, array $since): array
    {
        $portIds = self::ids($portIds);
        $deviceIds = $this->visible($deviceIds);
        if (! $portIds || ! $deviceIds) {
            return [];
        }

        $found = [];
        try {
            $learned = []; // mac => port_id => device that learned it
            foreach (DB::table('ports_fdb')->whereIntegerInRaw('port_id', $portIds)->limit(20000)->get(['port_id', 'device_id', 'mac_address', 'updated_at']) as $row) {
                $device = (int) $row->device_id;
                if (! isset($since[$device]) || self::time($row->updated_at) >= $since[$device]) {
                    $learned[strtolower((string) $row->mac_address)][(int) $row->port_id] = $device;
                }
            }
            foreach (array_chunk(array_keys($learned), 1000) as $chunk) {
                foreach (DB::table('ports')->whereIntegerInRaw('device_id', $deviceIds)->whereIn('ifPhysAddress', $chunk)->get(['device_id', 'ifPhysAddress']) as $row) {
                    $owner = (int) $row->device_id;
                    foreach ($learned[strtolower((string) $row->ifPhysAddress)] ?? [] as $portId => $device) {
                        if ($owner !== $device) {
                            $found[$portId][$owner] = true;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot match switch MACs: ' . $e->getMessage());
        }

        return array_map(fn ($owners) => array_keys($owners), $found);
    }

    /**
     * ARP and IPv6 neighbor entries of the addresses (IPv6 in any notation).
     *
     * @param  string[]  $ips
     * @return array<int, array{mac: string, ip: string, family: int, device_id: int, port_id: int, context: ?string}>
     */
    public function addressesByIps(array $ips): array
    {
        $v4 = [];
        $v6 = [];
        foreach ($ips as $ip) {
            if (str_contains($ip, ':')) {
                $v6[] = HostQuery::ipv6Full($ip);
            } else {
                $v4[] = $ip;
            }
        }

        return array_merge(
            $v4 ? $this->addressRows('ipv4_mac', 'ipv4_address', fn ($query) => $query->whereIn('ipv4_address', array_values(array_unique($v4))), 4) : [],
            $v6 ? $this->addressRows('ipv6_nd', 'ipv6_address', fn ($query) => $query->whereIn('ipv6_address', array_values(array_unique($v6))), 6) : [],
        );
    }

    /**
     * IPv4 and IPv6 addresses of the MACs from the ARP and IPv6 neighbor tables.
     *
     * @param  string[]  $macs
     * @param  bool  $ipv6  also read the IPv6 neighbor table (no index on the MAC)
     * @return array<int, array> see addressesByIps()
     */
    public function addressesByMacs(array $macs, bool $ipv6 = true): array
    {
        if (! $macs) {
            return [];
        }

        return array_merge(
            $this->addressRows('ipv4_mac', 'ipv4_address', fn ($query) => $query->whereIn('mac_address', array_values($macs)), 4),
            $ipv6 ? $this->addressRows('ipv6_nd', 'ipv6_address', fn ($query) => $query->whereIn('mac_address', array_values($macs)), 6) : [],
        );
    }

    /**
     * 802.1X / MAB sessions of the MACs (the nac poller module), current ones first: on the given ports (the index is
     * on port and MAC), or without ports by MAC alone, which reads the whole table (one row per session, small).
     *
     * @param  string[]  $macs
     * @param  int[]|null  $portIds  ports where the MACs are learned
     */
    public function nacByMacs(array $macs, ?array $portIds = null): array
    {
        if (! $macs) {
            return [];
        }
        $query = DB::table('ports_nac');
        if ($portIds !== null) {
            $portIds = self::ids($portIds);
            if (! $portIds) {
                return [];
            }
            $query->whereIntegerInRaw('port_id', $portIds);
        }

        $wanted = array_flip($macs);
        $variants = array_values(array_unique(array_merge(...array_map([Mac::class, 'variants'], array_values($macs)))));

        return array_values(array_filter($this->nacRows($query->whereIn('mac_address', $variants)), fn ($row) => isset($wanted[$row['mac']])));
    }

    /**
     * 802.1X / MAB sessions on a port.
     */
    public function nacByPort(int $portId): array
    {
        return $this->nacRows(DB::table('ports_nac')->where('port_id', $portId));
    }

    /**
     * Vendors of the MACs' prefixes from the OUI table (filled by LibreNMS when mac_oui.enabled is on).
     *
     * @param  string[]  $macs
     * @return array<string, string> oui => vendor (all prefixes of the MACs' /24 blocks)
     */
    public function vendors(array $macs): array
    {
        $prefixes = array_values(array_unique(array_map(fn ($mac) => substr($mac, 0, 6), array_filter($macs, fn ($mac) => strlen($mac) === 12))));
        if (! $prefixes) {
            return [];
        }

        $ouis = [];
        try {
            foreach (array_chunk($prefixes, 100) as $chunk) {
                $query = DB::table('vendor_ouis')->where(function ($query) use ($chunk): void {
                    foreach ($chunk as $prefix) {
                        $query->orWhere('oui', 'like', $prefix . '%');
                    }
                });
                foreach ($query->get(['oui', 'vendor']) as $row) {
                    $ouis[strtolower((string) $row->oui)] = (string) $row->vendor;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read vendor_ouis: ' . $e->getMessage());
        }

        return $ouis;
    }

    /**
     * Interfaces of the given (visible) devices that have one of the MAC addresses.
     *
     * @param  string[]  $macs
     * @param  int[]  $deviceIds
     * @return array<string, array<int, array{device_id: int, port_id: int, port: string}>> mac => interfaces
     */
    public function owners(array $macs, array $deviceIds): array
    {
        $macs = array_values(array_unique(array_filter($macs, fn ($mac) => strlen($mac) === 12)));
        $deviceIds = $this->visible($deviceIds);
        if (! $macs || ! $deviceIds) {
            return [];
        }

        $owners = [];
        foreach (DB::table('ports')->whereIntegerInRaw('device_id', $deviceIds)->whereIn('ifPhysAddress', $macs)
            ->orderBy('device_id')->orderBy('ifIndex')->get(['device_id', 'port_id', 'ifName', 'ifDescr', 'ifPhysAddress']) as $row) {
            $owners[strtolower((string) $row->ifPhysAddress)][] = ['device_id' => (int) $row->device_id, 'port_id' => (int) $row->port_id,
                'port' => InterfaceName::short((string) ($row->ifName ?: $row->ifDescr))];
        }

        return $owners;
    }

    /**
     * MAC addresses of the interfaces of the given (visible) devices, physical ports first.
     *
     * @param  int[]  $deviceIds
     * @return string[]
     */
    public function deviceMacs(array $deviceIds, int $limit = 200): array
    {
        $deviceIds = $this->visible($deviceIds);
        if (! $deviceIds) {
            return [];
        }

        $macs = [];
        foreach (DB::table('ports')->whereIntegerInRaw('device_id', $deviceIds)->whereNotNull('ifPhysAddress')
            ->orderByRaw("ifType = 'ethernetCsmacd' DESC")->orderBy('ifIndex')->limit($limit * 2)->pluck('ifPhysAddress') as $mac) {
            $mac = strtolower((string) $mac);
            if (preg_match('/^[0-9a-f]{12}$/', $mac) && $mac !== '000000000000') {
                $macs[$mac] = true;
            }
        }

        return array_slice(array_keys($macs), 0, $limit);
    }

    /**
     * LLDP/CDP neighbors on the ports, in both directions: what each port reports, and the
     * monitored devices that report one of these ports as their neighbor (switches that do not run
     * LLDP/CDP themselves are only seen this way).
     *
     * @param  int[]  $portIds
     * @return array<int, array<int, array{name: string, port: string, device_id: ?int, role: string, platform: string, description: string}>>
     */
    public function neighbors(array $portIds): array
    {
        $portIds = self::ids($portIds);
        if (! $portIds) {
            return [];
        }

        $rows = DB::table('links')->where('active', 1)
            ->where(fn ($query) => $query->whereIntegerInRaw('local_port_id', $portIds)->orWhereIntegerInRaw('remote_port_id', $portIds))
            ->get(['local_device_id', 'local_port_id', 'remote_device_id', 'remote_port_id', 'remote_hostname', 'remote_port', 'remote_platform', 'remote_version'])
            ->map(fn ($row) => (array) $row)->all();

        $wanted = array_flip($portIds);
        $deviceIds = [];
        $named = [];
        foreach ($rows as $row) {
            foreach (['local_device_id', 'remote_device_id'] as $column) {
                if ((int) $row[$column] > 0 && $this->canSee((int) $row[$column])) {
                    $deviceIds[(int) $row[$column]] = true;
                }
            }
            // the neighbor's port by its name in LibreNMS, so both directions of a link look the same
            if (isset($wanted[(int) $row['local_port_id']]) && (int) $row['remote_port_id'] > 0) {
                $named[(int) $row['remote_port_id']] = true;
            }
            if (isset($wanted[(int) $row['remote_port_id']])) {
                $named[(int) $row['local_port_id']] = true;
            }
        }
        $devices = $this->deviceRows(array_keys($deviceIds));
        $portNames = $this->portNames(array_keys($named));

        $neighbors = [];
        foreach ($rows as $row) {
            $local = (int) $row['local_port_id'];
            $remote = (int) $row['remote_port_id'];
            if (isset($wanted[$local]) && $this->canSee((int) $row['local_device_id'])) {
                $deviceId = (int) $row['remote_device_id'];
                // a device the user may not see looks like an unmanaged neighbor, as on the maps
                $neighbors[$local][] = $deviceId > 0 && isset($devices[$deviceId])
                    ? self::monitoredNeighbor($deviceId, $devices[$deviceId], $portNames[$remote] ?? (string) $row['remote_port'])
                    : self::unmanagedNeighbor($row);
            }
            $deviceId = (int) $row['local_device_id'];
            if ($remote && isset($wanted[$remote]) && isset($devices[$deviceId]) && $deviceId !== (int) $row['remote_device_id']) {
                $neighbors[$remote][] = self::monitoredNeighbor($deviceId, $devices[$deviceId], $portNames[$local] ?? '');
            }
        }

        return array_map([self::class, 'uniqueNeighbors'], $neighbors);
    }

    /**
     * The neighbors of a port without the second copy of a link reported by both ends, which can name the
     * far port differently (CDP TenGigabitEthernet1/1/1, ifName Te1/1/1).
     *
     * @param  array<int, array>  $neighbors
     * @return array<int, array>
     */
    public static function uniqueNeighbors(array $neighbors): array
    {
        $unique = [];
        foreach ($neighbors as $neighbor) {
            $unique[($neighbor['device_id'] ?? 'u:' . strtolower($neighbor['name'])) . '|' . InterfaceName::normalize($neighbor['port'])] ??= $neighbor;
        }

        return array_values($unique);
    }

    /**
     * A neighbor LibreNMS does not monitor (or the user may not see) from the LLDP/CDP data of the port:
     * its role from the CDP platform or, for LLDP (which has no platform), the system description
     * LibreNMS keeps in remote_version.
     *
     * @param  array  $link  links row: remote_hostname, remote_port, remote_platform, remote_version
     * @return array{name: string, port: string, device_id: null, role: string, platform: string, description: string}
     */
    public static function unmanagedNeighbor(array $link): array
    {
        $hostname = trim((string) ($link['remote_hostname'] ?? ''));
        $platform = trim((string) ($link['remote_platform'] ?? ''));
        $description = trim((string) ($link['remote_version'] ?? ''));

        return [
            'name' => L2Builder::shortHost($hostname) ?: 'unknown neighbor',
            'port' => (string) ($link['remote_port'] ?? ''),
            'device_id' => null,
            'role' => DeviceRole::fromNeighbor($platform, $description, $hostname),
            'platform' => $platform,
            'description' => mb_substr(trim((string) preg_replace('/\R.*/s', '', $description)), 0, 120),
        ];
    }

    /**
     * Monitored devices linked to the given ones by LLDP/CDP (either end reporting the link), visible ones only.
     * Two indexed queries: the links the devices report (local_device_id), and the links others report to their
     * ports (remote_port_id); remote_device_id has no index of its own, so an OR on it reads the whole table.
     *
     * @param  int[]  $deviceIds
     * @return array<int, int[]> device_id => linked device ids
     */
    public function linkedDevices(array $deviceIds): array
    {
        $deviceIds = $this->visible($deviceIds);
        if (! $deviceIds) {
            return [];
        }

        $rows = array_merge(
            DB::table('links')->where('active', 1)->whereIntegerInRaw('local_device_id', $deviceIds)->get(['local_device_id', 'remote_device_id'])->all(),
            DB::table('links as l')->join('ports as p', 'p.port_id', '=', 'l.remote_port_id')->where('l.active', 1)->whereIntegerInRaw('p.device_id', $deviceIds)
                ->get(['l.local_device_id', 'p.device_id as remote_device_id'])->all(),
        );
        $linked = [];
        foreach ($rows as $row) {
            $a = (int) $row->local_device_id;
            $b = (int) $row->remote_device_id;
            if ($a > 0 && $b > 0 && $a !== $b && $this->canSee($a) && $this->canSee($b)) {
                $linked[$a][$b] = true;
                $linked[$b][$a] = true;
            }
        }

        return array_map(fn ($ids) => array_keys($ids), array_intersect_key($linked, array_flip($deviceIds)));
    }

    /**
     * IPv4 and IPv6 addresses of the given (visible) devices with their prefix length and interface.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array<int, array{port_id: int, port: string, ip: string, prefix: int, family: int}>> device_id => addresses
     */
    public function layer3Addresses(array $deviceIds): array
    {
        $deviceIds = $this->visible($deviceIds);
        if (! $deviceIds) {
            return [];
        }

        $addresses = [];
        foreach ([4 => ['ipv4_addresses', 'ipv4_address', 'ipv4_prefixlen'], 6 => ['ipv6_addresses', 'ipv6_address', 'ipv6_prefixlen']] as $family => [$table, $column, $prefix]) {
            try {
                foreach (DB::table($table . ' as a')->join('ports as p', 'p.port_id', '=', 'a.port_id')->whereIntegerInRaw('p.device_id', $deviceIds)
                    ->limit(5000)->get(['p.device_id', 'p.port_id', 'p.ifName', 'p.ifDescr', 'a.' . $column . ' as ip', 'a.' . $prefix . ' as prefix']) as $row) {
                    $addresses[(int) $row->device_id][] = ['port_id' => (int) $row->port_id, 'port' => (string) ($row->ifName ?: $row->ifDescr), 'ip' => (string) $row->ip,
                        'prefix' => (int) $row->prefix, 'family' => $family];
                }
            } catch (\Throwable $e) {
                Log::debug("topology plugin: cannot read $table: " . $e->getMessage());
            }
        }

        return $addresses;
    }

    /**
     * Visible devices with OSPF neighbors or BGP peers (as TopologyRepository's role facts tell a router).
     *
     * @param  int[]  $deviceIds
     * @return array<int, true>
     */
    public function routingDevices(array $deviceIds): array
    {
        $deviceIds = $this->visible($deviceIds);
        $routing = [];
        foreach ($deviceIds ? ['ospf_nbrs', 'bgpPeers'] : [] as $table) {
            try {
                foreach (DB::table($table)->whereIntegerInRaw('device_id', $deviceIds)->distinct()->pluck('device_id') as $deviceId) {
                    $routing[(int) $deviceId] = true;
                }
            } catch (\Throwable $e) {
                Log::debug("topology plugin: cannot read $table: " . $e->getMessage());
            }
        }

        return $routing;
    }

    /**
     * Monitored devices named like the query (hostname, sysName or display name, also without
     * the domain), with their role and the addresses LibreNMS polls them on.
     *
     * @return array<int, array{device_id: int, name: string, hostname: string, role: string, ips: string[]}>
     */
    public function devicesByName(string $name): array
    {
        $rows = DB::table('devices')->where('disabled', 0)
            ->where(fn ($query) => $query->where('hostname', $name)->orWhere('hostname', 'like', addcslashes($name, '%_\\') . '.%')
                ->orWhere('sysName', $name)->orWhere('display', $name))
            ->limit(20)
            ->get(['device_id', 'hostname', 'sysName', 'display', 'ip', 'overwrite_ip', 'type', 'os', 'hardware']);

        $devices = [];
        foreach ($rows as $row) {
            $deviceId = (int) $row->device_id;
            if (! $this->canSee($deviceId)) {
                continue;
            }
            $ips = [];
            foreach ([(string) $row->overwrite_ip, (string) $row->hostname] as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    $ips[] = $candidate;
                }
            }
            $binary = $row->ip === null ? false : @inet_ntop((string) $row->ip);
            if ($binary) {
                $ips[] = $binary;
            }
            $devices[] = ['device_id' => $deviceId, 'name' => (string) ($row->display ?: $row->hostname), 'hostname' => (string) $row->hostname,
                'role' => DeviceRole::classify((array) $row), 'ips' => array_values(array_unique($ips))];
        }

        return $devices;
    }

    /**
     * VLANs of a port: untagged / tagged membership (ports_vlans) with the switch's VLAN names.
     *
     * @return array<int, array{vlan: int, name: ?string, untagged: bool, state: string}>
     */
    public function portVlans(int $deviceId, int $portId): array
    {
        $vlans = [];
        try {
            foreach (DB::table('ports_vlans')->where('device_id', $deviceId)->where('port_id', $portId)
                ->orderBy('vlan')->get(['vlan', 'untagged', 'state']) as $row) {
                $vlans[(int) $row->vlan] = ['vlan' => (int) $row->vlan, 'name' => null, 'untagged' => (bool) $row->untagged, 'state' => (string) $row->state];
            }
            if ($vlans) {
                foreach (DB::table('vlans')->where('device_id', $deviceId)->whereIntegerInRaw('vlan_vlan', array_keys($vlans))
                    ->get(['vlan_vlan', 'vlan_name']) as $row) {
                    $vlans[(int) $row->vlan_vlan]['name'] = $row->vlan_name !== null ? (string) $row->vlan_name : null;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read port VLANs: ' . $e->getMessage());
        }

        return array_values($vlans);
    }

    /**
     * Spanning tree rows of one device: its instances (stp) and the rows of the given ports (ports_stp).
     *
     * @param  int[]  $portIds
     * @return array{0: array<int, array>, 1: array<int, array>}
     */
    public function stpRows(int $deviceId, array $portIds): array
    {
        try {
            return [
                DB::table('stp')->where('device_id', $deviceId)->get()->map(fn ($row) => (array) $row)->all(),
                $portIds ? DB::table('ports_stp')->where('device_id', $deviceId)->whereIntegerInRaw('port_id', $portIds)->get()->map(fn ($row) => (array) $row)->all() : [],
            ];
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read spanning tree rows: ' . $e->getMessage());

            return [[], []];
        }
    }

    /**
     * Ports of visible devices with their names and links.
     *
     * @param  int[]  $portIds
     * @return array<int, array> port_id => row
     */
    public function ports(array $portIds): array
    {
        $portIds = self::ids($portIds);
        if (! $portIds) {
            return [];
        }

        $ports = [];
        foreach (array_chunk($portIds, 2000) as $chunk) {
            foreach (DB::table('ports')->whereIntegerInRaw('port_id', $chunk)
                ->get(['port_id', 'device_id', 'ifName', 'ifDescr', 'ifAlias', 'ifType', 'ifSpeed', 'ifOperStatus', 'ifAdminStatus', 'ifVlan', 'ifTrunk', 'ifPhysAddress']) as $row) {
                $row = (array) $row;
                if (! $this->canSee((int) $row['device_id'])) {
                    continue;
                }
                $row['url'] = url('device/device=' . $row['device_id'] . '/tab=port/port=' . $row['port_id'] . '/');
                $ports[(int) $row['port_id']] = $row;
            }
        }

        return $ports;
    }

    /**
     * Visible devices with display name, role (from the device type), OS, URL and time of the last poll.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array{name: string, role: string, os: string, url: string, status: bool, last_polled: ?int}>
     */
    public function deviceRows(array $deviceIds): array
    {
        $deviceIds = $this->visible($deviceIds);
        if (! $deviceIds) {
            return [];
        }

        $devices = [];
        foreach (DB::table('devices')->whereIntegerInRaw('device_id', $deviceIds)
            ->get(['device_id', 'hostname', 'sysName', 'display', 'type', 'os', 'hardware', 'status', 'last_polled']) as $row) {
            $row = (array) $row;
            $deviceId = (int) $row['device_id'];
            $devices[$deviceId] = [
                'name' => (string) ($row['display'] ?: $row['hostname']),
                'role' => DeviceRole::classify($row),
                'os' => (string) $row['os'],
                'url' => url('device/' . $deviceId),
                'status' => (bool) $row['status'],
                'last_polled' => self::time($row['last_polled']) ?: null,
            ];
        }

        return $devices;
    }

    /**
     * Do the devices poll their 802.1X / MAB sessions: is LibreNMS' nac poller module (off by default) on for them?
     * Decided like LibreNMS decides (Util\ModuleList), as CoverageRepository::protocolsModuleOff() does for
     * discovery-protocols: the device's own setting, else the setting for its OS, else the global one.
     *
     * @param  array<int, array>  $devices  device_id => row with os
     * @return array<int, bool> device_id => module on
     */
    public function nacPolled(array $devices): array
    {
        if (! $devices) {
            return [];
        }

        $attributes = [];
        try {
            $attributes = DB::table('devices_attribs')->whereIntegerInRaw('device_id', array_keys($devices))
                ->where('attrib_type', 'poll_nac')->pluck('attrib_value', 'device_id')->all();
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read device module settings: ' . $e->getMessage());
        }

        $global = self::config('poller_modules.nac');
        $byOs = [];
        $polled = [];
        foreach ($devices as $deviceId => $device) {
            $os = (string) ($device['os'] ?? '');
            $osSetting = $byOs[$os] ??= [self::config("os.$os.poller_modules.nac")];
            $polled[(int) $deviceId] = self::moduleOn($global, $osSetting[0], $attributes[$deviceId] ?? null);
        }

        return $polled;
    }

    /**
     * Is a LibreNMS module on, from its global, OS and device setting (null: not set)? The device's setting wins over
     * the OS setting, which wins over the global one (Polling\ModuleStatus); a module without a global setting does not
     * exist in this LibreNMS.
     */
    public static function moduleOn(mixed $global, mixed $os, mixed $device): bool
    {
        return match (true) {
            $global === null => false,
            $device !== null => (bool) $device,
            $os !== null => (bool) $os,
            default => (bool) $global,
        };
    }

    /**
     * Seconds from one poll of a device to the next: the dispatcher's poller frequency, else the RRD step (300 by
     * default), as LibreNMS schedules polls.
     */
    public function pollInterval(): int
    {
        $interval = (int) (self::config('service_poller_frequency') ?? self::config('rrd.step', 300));

        return $interval > 0 ? $interval : 300;
    }

    /**
     * @param  int[]  $portIds
     * @return array<int, string> port_id => ifName (ifDescr when empty)
     */
    private function portNames(array $portIds): array
    {
        if (! $portIds) {
            return [];
        }

        $names = [];
        foreach (DB::table('ports')->whereIntegerInRaw('port_id', $portIds)->get(['port_id', 'ifName', 'ifDescr']) as $row) {
            $names[(int) $row->port_id] = (string) ($row->ifName ?: $row->ifDescr);
        }

        return $names;
    }

    /**
     * @param  int  $deviceId
     * @param  array{name: string, role: string}  $device  deviceRows() row
     */
    private static function monitoredNeighbor(int $deviceId, array $device, string $port): array
    {
        return ['name' => $device['name'], 'port' => $port, 'device_id' => $deviceId, 'role' => $device['role'], 'platform' => '', 'description' => ''];
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query  ports_fdb as f
     */
    private function fdbRows($query): array
    {
        $rows = [];
        try {
            foreach ($query->leftJoin('vlans as v', 'v.vlan_id', '=', 'f.vlan_id')
                ->get(['f.mac_address', 'f.device_id', 'f.port_id', 'v.vlan_vlan', 'v.vlan_name', 'f.created_at', 'f.updated_at']) as $row) {
                $deviceId = (int) $row->device_id;
                if (! $this->canSee($deviceId)) {
                    continue;
                }
                $lastSeen = self::time($row->updated_at);
                $rows[] = [
                    'mac' => strtolower((string) $row->mac_address),
                    'device_id' => $deviceId,
                    'port_id' => (int) $row->port_id,
                    'vlan' => $row->vlan_vlan !== null ? (int) $row->vlan_vlan : null,
                    'vlan_name' => $row->vlan_name !== null ? (string) $row->vlan_name : null,
                    'first_seen' => self::time($row->created_at) ?: $lastSeen,
                    'last_seen' => $lastSeen,
                ];
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read ports_fdb: ' . $e->getMessage());
        }

        return $rows;
    }

    /**
     * ARP / IPv6 neighbor rows from visible devices (at most 500); a missing table (LibreNMS before ipv6_nd) gives none.
     */
    private function addressRows(string $table, string $column, callable $where, int $family): array
    {
        $rows = [];
        try {
            $query = $this->scoped(DB::table($table));
            $where($query);
            foreach ($query->limit(500)->get(['mac_address', $column . ' as ip', 'device_id', 'port_id', 'context_name']) as $row) {
                $deviceId = (int) $row->device_id;
                if (! $this->canSee($deviceId)) {
                    continue;
                }
                $rows[] = [
                    'mac' => strtolower((string) $row->mac_address),
                    'ip' => (string) $row->ip,
                    'family' => $family,
                    'device_id' => $deviceId,
                    'port_id' => (int) $row->port_id,
                    'context' => $row->context_name !== null && $row->context_name !== '' ? (string) $row->context_name : null,
                ];
            }
        } catch (\Throwable $e) {
            Log::debug("topology plugin: cannot read $table: " . $e->getMessage());
        }

        return $rows;
    }

    /**
     * 802.1X / MAB rows from visible devices (at most 200), with the MAC in LibreNMS' notation (some
     * switches store it as 0:4:f2:bc:95:2).
     *
     * @param  \Illuminate\Database\Query\Builder  $query  ports_nac
     */
    private function nacRows($query): array
    {
        $rows = [];
        try {
            foreach ($this->scoped($query)->limit(200)->get() as $row) {
                $row = (array) $row;
                $deviceId = (int) $row['device_id'];
                if (! $this->canSee($deviceId)) {
                    continue;
                }
                $rows[] = [
                    'id' => (int) ($row['ports_nac_id'] ?? 0),
                    'mac' => Mac::normalize((string) $row['mac_address']) ?? strtolower((string) $row['mac_address']),
                    'device_id' => $deviceId,
                    'port_id' => (int) $row['port_id'],
                    'username' => (string) ($row['username'] ?? ''),
                    'domain' => (string) ($row['domain'] ?? ''),
                    'ip' => (string) ($row['ip_address'] ?? ''),
                    'status' => (string) ($row['authz_status'] ?? ''),
                    'auth_status' => (string) ($row['authc_status'] ?? ''),
                    'method' => (string) ($row['method'] ?? ''),
                    'host_mode' => (string) ($row['host_mode'] ?? ''),
                    'vlan' => isset($row['vlan']) && $row['vlan'] !== null ? (int) $row['vlan'] : null,
                    'historical' => (bool) ($row['historical'] ?? false),
                    'first_seen' => self::time($row['created_at'] ?? null),
                    'started' => self::sessionStart($row),
                    'last_seen' => self::time($row['updated_at'] ?? null),
                ];
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read ports_nac: ' . $e->getMessage());
        }
        usort($rows, fn ($a, $b) => [$a['historical'], -$a['last_seen']] <=> [$b['historical'], -$b['last_seen']]);

        return $rows;
    }

    /**
     * When a session started: its update time minus the session time the switch reports (time_elapsed: Huawei, ProCurve,
     * PowerConnect), else when LibreNMS created the row. Cisco reports only the time to the next reauthentication and
     * refreshes the update time with it at every poll, so neither tells the start; the row is created at the first
     * poll of a session (but kept when the MAC comes back on the same switch with something in common).
     */
    private static function sessionStart(array $row): int
    {
        $elapsed = $row['time_elapsed'] ?? null;
        $updated = self::time($row['updated_at'] ?? null);
        if (is_numeric($elapsed) && (float) $elapsed >= 0 && $updated > 0) {
            return $updated - (int) $elapsed;
        }

        return self::time($row['created_at'] ?? null) ?: $updated;
    }

    /**
     * Limit a query on a per-device table to the devices the user may see, so that a row limit counts
     * visible rows only (for a user who may see very many devices the rows are checked after reading).
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Query\Builder
     */
    private function scoped($query)
    {
        $ids = array_keys($this->accessible());
        if (count($ids) <= self::SCOPE_IN_QUERY) {
            $query->whereIntegerInRaw('device_id', $ids);
        }

        return $query;
    }

    /**
     * @param  int[]  $deviceIds
     * @return int[] the ones the user may see
     */
    private function visible(array $deviceIds): array
    {
        return array_values(array_filter(array_unique(array_map('intval', $deviceIds)), fn ($id) => $this->canSee($id)));
    }

    /**
     * @return int[] distinct positive ids
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
    }

    private static function time(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return $value instanceof \DateTimeInterface ? $value->getTimestamp() : (int) strtotime((string) $value);
    }

    private static function date(int $time): string
    {
        return date('Y-m-d H:i:s', $time);
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
