<?php

namespace Agoodley\LibrenmsCompliance\Data;

use Illuminate\Support\Facades\DB;

/**
 * Data\Network from LibreNMS' tables: devices, ports, links (LLDP/CDP), stp and ports_stp (the root port of each
 * instance: the ports_stp row of its VLAN whose port_index is the instance's rootPort, NULL, 0 and 1 being the default
 * instance, as the topology plugin reads them) and ports_stack (the members of port-channels). The scheduled check
 * reads every device; a page reads the devices its user may see (Support\Access), never through this class.
 */
final class LibrenmsNetwork implements Network
{
    public function devices(string $group = ''): array
    {
        $query = DB::table('devices')->where('disabled', 0);
        if ($group !== '') {
            $groupId = DB::table('device_groups')->where('name', $group)->value('id');
            if ($groupId === null) {
                throw new \InvalidArgumentException("LibreNMS has no device group $group (the Device group setting)");
            }
            $query->whereIn('device_id', DB::table('device_group_device')->where('device_group_id', $groupId)->select('device_id'));
        }
        $devices = [];
        foreach ($query->orderBy('device_id')->get(['device_id', 'hostname', 'display', 'os']) as $row) {
            $devices[(int) $row->device_id] = ['name' => (string) ($row->display ?: $row->hostname), 'os' => (string) $row->os];
        }

        return $devices;
    }

    public function device(int $deviceId): ?array
    {
        $row = DB::table('devices')->where('device_id', $deviceId)->first(['hostname', 'display', 'os', 'disabled']);

        return $row === null ? null : ['name' => (string) ($row->display ?: $row->hostname), 'os' => (string) $row->os, 'disabled' => (bool) $row->disabled];
    }

    /**
     * The names of these devices (as LibreNMS shows them) and their hostnames, by id; a device LibreNMS no longer has is
     * left out.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array{name: string, hostname: string, os: string, disabled: bool}>
     */
    public function names(array $deviceIds): array
    {
        $names = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $deviceIds))), 1000) as $chunk) {
            foreach (DB::table('devices')->whereIn('device_id', $chunk)->get(['device_id', 'hostname', 'display', 'os', 'disabled']) as $row) {
                $names[(int) $row->device_id] = ['name' => (string) ($row->display ?: $row->hostname), 'hostname' => (string) $row->hostname, 'os' => (string) $row->os,
                    'disabled' => (bool) $row->disabled];
            }
        }

        return $names;
    }

    /**
     * LibreNMS' device groups, by name.
     *
     * @return string[]
     */
    public function groups(): array
    {
        return array_map('strval', DB::table('device_groups')->orderBy('name')->pluck('name')->all());
    }

    public function ports(int $deviceId): array
    {
        $ports = [];
        foreach (DB::table('ports')->where('device_id', $deviceId)->where('deleted', 0)->get(['port_id', 'ifName', 'ifDescr']) as $row) {
            $ports[(int) $row->port_id] = ['ifName' => $row->ifName, 'ifDescr' => $row->ifDescr];
        }

        return $ports;
    }

    public function neighborhood(int $deviceId): array
    {
        $links = array_map(fn ($row) => (array) $row, DB::table('links')->where('local_device_id', $deviceId)->where('active', 1)
            ->get(['local_port_id', 'remote_device_id', 'remote_port_id', 'remote_hostname', 'remote_port'])->all());
        $devices = array_values(array_unique(array_merge([$deviceId], array_filter(array_map(fn (array $link) => (int) ($link['remote_device_id'] ?? 0), $links)))));
        $bridges = [];
        $instances = [];
        foreach (DB::table('stp')->whereIn('device_id', $devices)->get(['device_id', 'vlan', 'rootPort', 'rootBridge', 'bridgeAddress', 'designatedRoot']) as $row) {
            $bridges[(int) $row->device_id] = true;
            $root = (bool) $row->rootBridge || ($row->bridgeAddress !== null && $row->bridgeAddress !== '' && self::mac($row->bridgeAddress) === self::mac($row->designatedRoot));
            if (! $root && (int) $row->rootPort > 0) {
                $instances[(int) $row->device_id][self::vlan($row->vlan)][] = (int) $row->rootPort;
            }
        }
        $rootPorts = [];
        if ($instances) {
            foreach (DB::table('ports_stp')->whereIn('device_id', array_keys($instances))->get(['device_id', 'vlan', 'port_id', 'port_index']) as $row) {
                if (in_array((int) $row->port_index, $instances[(int) $row->device_id][self::vlan($row->vlan)] ?? [], true)) {
                    $rootPorts[(int) $row->device_id][] = (int) $row->port_id;
                }
            }
        }
        $pairs = [];
        foreach (DB::table('ports_stack')->whereIn('device_id', $devices)->get() as $row) {
            // the columns are high_port_id/low_port_id since LibreNMS 24.8, port_id_high/port_id_low before
            $row = (array) $row;
            $high = (int) ($row['high_port_id'] ?? $row['port_id_high'] ?? 0);
            $low = (int) ($row['low_port_id'] ?? $row['port_id_low'] ?? 0);
            if ($high > 0 && $low > 0 && $high !== $low) {
                $pairs[] = [$high, $low];
            }
        }
        $names = [];
        foreach (DB::table('devices')->whereIn('device_id', $devices)->get(['device_id', 'hostname', 'display']) as $row) {
            $names[(int) $row->device_id] = (string) ($row->display ?: $row->hostname);
        }

        return ['links' => $links, 'bridges' => $bridges, 'rootPorts' => array_map(fn (array $ids) => array_values(array_unique($ids)), $rootPorts),
            'lagParent' => self::lags($pairs), 'names' => $names];
    }

    /**
     * The port-channel of each member port, from ports_stack's pairs: it also stacks other interfaces (a VLAN over its
     * port, a subinterface), and which side of a pair is the upper layer changed in LibreNMS 24.8, so the port-channel
     * is told by the ports themselves (its LAG ifType or name), as the topology plugin does.
     *
     * @param  array<int, array{0: int, 1: int}>  $pairs
     * @return array<int, int> member port id => its port-channel's port id
     */
    private static function lags(array $pairs): array
    {
        if (! $pairs) {
            return [];
        }
        $ports = [];
        foreach (array_chunk(array_values(array_unique(array_merge(...$pairs))), 1000) as $chunk) {
            foreach (DB::table('ports')->whereIn('port_id', $chunk)->get(['port_id', 'ifType', 'ifName', 'ifDescr']) as $row) {
                $ports[(int) $row->port_id] = ['type' => (string) $row->ifType, 'name' => trim((string) ($row->ifName ?: $row->ifDescr))];
            }
        }
        $lags = [];
        foreach ($pairs as [$a, $b]) {
            if (! isset($ports[$a], $ports[$b])) {
                continue;
            }
            if (self::aggregate($ports[$a]) && self::member($ports[$b])) {
                $lags[$b] ??= $a;
            } elseif (self::aggregate($ports[$b]) && self::member($ports[$a])) {
                $lags[$a] ??= $b;
            }
        }

        return $lags;
    }

    /** A port-channel itself (its LAG ifType or name), not one of its subinterfaces. */
    private static function aggregate(array $port): bool
    {
        return ! str_contains($port['name'], '.') && (strcasecmp($port['type'], 'ieee8023adLag') === 0
            || preg_match('~^(port-?channel|po|ae|bond|bundle-ether|be|lag|trk)\s*\d~i', $port['name']) === 1);
    }

    /** A port that can be bundled: no port-channel, subinterface, VLAN or virtual interface. */
    private static function member(array $port): bool
    {
        return ! self::aggregate($port) && ! str_contains($port['name'], '.')
            && ! in_array($port['type'], ['l2vlan', 'l3ipvlan', 'propVirtual', 'softwareLoopback', 'tunnel', 'mplsTunnel'], true);
    }

    /** A VLAN of stp and ports_stp: NULL, 0 and 1 are the default instance. */
    private static function vlan(mixed $vlan): int
    {
        return (int) $vlan <= 1 ? 0 : (int) $vlan;
    }

    private static function mac(?string $value): string
    {
        return strtolower((string) preg_replace('~[^0-9a-f]~i', '', (string) $value));
    }
}
