<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Topology\L2Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Database access of the host locator and the port tab: forwarding tables (ports_fdb), ARP
 * (ipv4_mac), IPv6 neighbors (ipv6_nd), 802.1X / MAB sessions (ports_nac) and the OUI table, only
 * from devices the user may see. Every lookup is by MAC, address, port or device so the indexes
 * are used; MACs are LibreNMS' 12 lowercase hex digits.
 */
class HostRepository extends ScopedRepository
{
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
     * Time of each switch's newest forwarding table entry: entries older than that are from an
     * earlier discovery (LibreNMS refreshes updated_at of every entry it still sees).
     *
     * @param  int[]  $deviceIds
     * @return array<int, int> device_id => unix time
     */
    public function fdbFreshness(array $deviceIds): array
    {
        $deviceIds = $this->visible($deviceIds);
        if (! $deviceIds) {
            return [];
        }

        $fresh = [];
        foreach (DB::table('ports_fdb')->whereIntegerInRaw('device_id', $deviceIds)->groupBy('device_id')
            ->selectRaw('device_id, MAX(updated_at) as newest')->get() as $row) {
            $fresh[(int) $row->device_id] = self::time($row->newest);
        }

        return $fresh;
    }

    /**
     * MACs learned on each port at its switch's last forwarding table discovery (all entries for a
     * switch missing from $fresh).
     *
     * @param  int[]  $portIds
     * @param  array<int, int>  $fresh  device_id => newest entry (fdbFreshness())
     * @param  int  $window  entries this much older than the newest still count (seconds)
     * @return array<int, int> port_id => distinct MACs
     */
    public function macCounts(array $portIds, array $fresh, int $window): array
    {
        $portIds = array_values(array_unique(array_filter(array_map('intval', $portIds))));
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
            if (isset($fresh[$deviceId])) {
                $query->where('updated_at', '>=', date('Y-m-d H:i:s', $fresh[$deviceId] - $window));
            }
            foreach ($query->groupBy('port_id')->selectRaw('port_id, COUNT(DISTINCT mac_address) as macs')->get() as $row) {
                $counts[(int) $row->port_id] = (int) $row->macs;
            }
        }

        return $counts;
    }

    /**
     * ARP entries of an IPv4 address (ipv4_address has no index in LibreNMS: one exact match scan).
     *
     * @return array<int, array{mac: string, ip: string, family: int, device_id: int, port_id: int, context: ?string}>
     */
    public function arpByIp(string $ip): array
    {
        return $this->addressRows('ipv4_mac', 'ipv4_address', fn ($query) => $query->where('ipv4_address', $ip), 4);
    }

    /**
     * IPv6 neighbor entries of an address, in the uncompressed form LibreNMS stores.
     *
     * @return array<int, array> see arpByIp()
     */
    public function ndByIp(string $ip): array
    {
        return $this->addressRows('ipv6_nd', 'ipv6_address', fn ($query) => $query->where('ipv6_address', $ip), 6);
    }

    /**
     * IPv4 and IPv6 addresses of the MACs from the ARP and IPv6 neighbor tables.
     *
     * @param  string[]  $macs
     * @return array<int, array> see arpByIp()
     */
    public function addressesByMacs(array $macs): array
    {
        if (! $macs) {
            return [];
        }

        return array_merge(
            $this->addressRows('ipv4_mac', 'ipv4_address', fn ($query) => $query->whereIn('mac_address', array_values($macs)), 4),
            $this->addressRows('ipv6_nd', 'ipv6_address', fn ($query) => $query->whereIn('mac_address', array_values($macs)), 6),
        );
    }

    /**
     * 802.1X / MAB sessions of the MACs (the nac poller module), newest first.
     *
     * @param  string[]  $macs
     */
    public function nacByMacs(array $macs): array
    {
        return $macs ? $this->nacRows(DB::table('ports_nac')->whereIn('mac_address', array_values($macs))) : [];
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
     * Interfaces of the given (visible) devices that have this MAC address.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array{device_id: int, port_id: int, port: string}>
     */
    public function owners(string $mac, array $deviceIds): array
    {
        $deviceIds = $this->visible($deviceIds);
        if (! $deviceIds) {
            return [];
        }

        $owners = [];
        foreach (DB::table('ports')->whereIntegerInRaw('device_id', $deviceIds)->where('ifPhysAddress', $mac)
            ->get(['device_id', 'port_id', 'ifName', 'ifDescr']) as $row) {
            $owners[] = ['device_id' => (int) $row->device_id, 'port_id' => (int) $row->port_id, 'port' => (string) ($row->ifName ?: $row->ifDescr)];
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
     * @return array<int, array<int, array{name: string, port: string, device_id: ?int, role: string, platform: string}>>
     */
    public function neighbors(array $portIds): array
    {
        $portIds = array_values(array_unique(array_filter(array_map('intval', $portIds))));
        if (! $portIds) {
            return [];
        }

        $rows = DB::table('links')->where('active', 1)
            ->where(fn ($query) => $query->whereIntegerInRaw('local_port_id', $portIds)->orWhereIntegerInRaw('remote_port_id', $portIds))
            ->get(['local_device_id', 'local_port_id', 'remote_device_id', 'remote_port_id', 'remote_hostname', 'remote_port', 'remote_platform'])
            ->map(fn ($row) => (array) $row)->all();

        $wanted = array_flip($portIds);
        $deviceIds = [];
        $localPorts = [];
        foreach ($rows as $row) {
            foreach (['local_device_id', 'remote_device_id'] as $column) {
                if ((int) $row[$column] > 0 && $this->canSee((int) $row[$column])) {
                    $deviceIds[(int) $row[$column]] = true;
                }
            }
            if (isset($wanted[(int) $row['remote_port_id']])) {
                $localPorts[(int) $row['local_port_id']] = true;
            }
        }
        $devices = $this->deviceRows(array_keys($deviceIds));
        $portNames = $this->portNames(array_keys($localPorts));

        $neighbors = [];
        foreach ($rows as $row) {
            $local = (int) $row['local_port_id'];
            $remote = (int) $row['remote_port_id'];
            if (isset($wanted[$local]) && $this->canSee((int) $row['local_device_id'])) {
                $deviceId = (int) $row['remote_device_id'];
                $visible = $deviceId > 0 && isset($devices[$deviceId]);
                $neighbors[$local][] = [
                    'name' => $visible ? $devices[$deviceId]['name'] : (L2Builder::shortHost(trim((string) $row['remote_hostname'])) ?: 'unknown neighbor'),
                    'port' => (string) $row['remote_port'],
                    'device_id' => $visible ? $deviceId : null,
                    // a device the user may not see looks like an unmanaged neighbor, as on the maps
                    'role' => $visible ? $devices[$deviceId]['role'] : DeviceRole::fromPlatform($row['remote_platform'], $row['remote_hostname']),
                    'platform' => (string) $row['remote_platform'],
                ];
            }
            $deviceId = (int) $row['local_device_id'];
            if ($remote && isset($wanted[$remote]) && isset($devices[$deviceId]) && $deviceId !== (int) $row['remote_device_id']) {
                $neighbors[$remote][] = [
                    'name' => $devices[$deviceId]['name'],
                    'port' => $portNames[$local] ?? '',
                    'device_id' => $deviceId,
                    'role' => $devices[$deviceId]['role'],
                    'platform' => '',
                ];
            }
        }

        // a link reported by both ends appears twice
        foreach ($neighbors as &$list) {
            $unique = [];
            foreach ($list as $neighbor) {
                $unique[($neighbor['device_id'] ?? $neighbor['name']) . '|' . $neighbor['port']] ??= $neighbor;
            }
            $list = array_values($unique);
        }
        unset($list);

        return $neighbors;
    }

    /**
     * Monitored devices named like the query (hostname, sysName or display name, also without
     * the domain), with the addresses LibreNMS polls them on.
     *
     * @return array<int, array{device_id: int, name: string, hostname: string, ips: string[]}>
     */
    public function devicesByName(string $name): array
    {
        $rows = DB::table('devices')->where('disabled', 0)
            ->where(fn ($query) => $query->where('hostname', $name)->orWhere('hostname', 'like', addcslashes($name, '%_\\') . '.%')
                ->orWhere('sysName', $name)->orWhere('display', $name))
            ->limit(20)
            ->get(['device_id', 'hostname', 'sysName', 'display', 'ip', 'overwrite_ip']);

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
                'ips' => array_values(array_unique($ips))];
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
        $portIds = array_values(array_unique(array_filter(array_map('intval', $portIds))));
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
     * Visible devices with display name, role (from the device type) and URL.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array{name: string, role: string, url: string, status: bool}>
     */
    public function deviceRows(array $deviceIds): array
    {
        $deviceIds = $this->visible($deviceIds);
        if (! $deviceIds) {
            return [];
        }

        $devices = [];
        foreach (DB::table('devices')->whereIntegerInRaw('device_id', $deviceIds)
            ->get(['device_id', 'hostname', 'sysName', 'display', 'type', 'os', 'hardware', 'status']) as $row) {
            $row = (array) $row;
            $deviceId = (int) $row['device_id'];
            $devices[$deviceId] = [
                'name' => (string) ($row['display'] ?: $row['hostname']),
                'role' => DeviceRole::classify($row),
                'url' => url('device/' . $deviceId),
                'status' => (bool) $row['status'],
            ];
        }

        return $devices;
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
     * ARP / IPv6 neighbor rows from visible devices; a missing table (LibreNMS before ipv6_nd) gives none.
     */
    private function addressRows(string $table, string $column, callable $where, int $family): array
    {
        $rows = [];
        try {
            $query = DB::table($table);
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
     * @param  \Illuminate\Database\Query\Builder  $query  ports_nac
     */
    private function nacRows($query): array
    {
        $rows = [];
        try {
            foreach ($query->limit(200)->get() as $row) {
                $row = (array) $row;
                $deviceId = (int) $row['device_id'];
                if (! $this->canSee($deviceId)) {
                    continue;
                }
                $rows[] = [
                    'mac' => strtolower((string) $row['mac_address']),
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
     * @param  int[]  $deviceIds
     * @return int[] the ones the user may see
     */
    private function visible(array $deviceIds): array
    {
        return array_values(array_filter(array_unique(array_map('intval', $deviceIds)), fn ($id) => $this->canSee($id)));
    }

    private static function time(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return $value instanceof \DateTimeInterface ? $value->getTimestamp() : (int) strtotime((string) $value);
    }
}
