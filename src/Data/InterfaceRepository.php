<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Interfaces\InterfaceAnalyzer;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Illuminate\Support\Facades\DB;

/**
 * The LibreNMS data of the interface health checks (Interfaces\InterfaceService): the physical
 * Ethernet ports of the devices the user may see, the changes LibreNMS' poller logged for them in
 * the eventlog, the devices' reboots and the LLDP/CDP neighbor of each port.
 */
class InterfaceRepository extends ScopedRepository
{
    /** Port columns the checks read. */
    private const PORT_COLUMNS = ['port_id', 'device_id', 'ifIndex', 'ifName', 'ifDescr', 'ifAlias', 'ifType', 'ifSpeed', 'ifDuplex',
        'ifOperStatus', 'ifAdminStatus', 'ifInErrors', 'ifOutErrors', 'ifInErrors_delta', 'ifOutErrors_delta',
        'ifInUcastPkts_delta', 'ifOutUcastPkts_delta', 'poll_period'];

    /** Most eventlog changes read at once (newest first, a group of devices at a time). */
    public const EVENT_LIMIT = 200000;

    /** Messages of the port changes the checks read (InterfaceAnalyzer::parseEvent()). */
    private const EVENT_PREFIXES = ['ifOperStatus: ', 'ifAdminStatus: ', 'ifSpeed: ', 'ifDuplex: '];

    private bool $eventsCapped = false;

    /**
     * The physical Ethernet ports of these devices that LibreNMS polls, not ignored or deleted, worth a look: the ports
     * with changes ($withChanges), the ones up at half duplex now and the ones whose error counters are not zero.
     *
     * @param  int[]  $deviceIds
     * @param  int[]  $withChanges
     * @return array<int, array> port_id => row
     */
    public function ports(array $deviceIds, array $withChanges): array
    {
        if (! $deviceIds || ! $this->allowsTable('ports')) {
            return [];
        }
        $ports = [];
        $add = function (iterable $rows) use (&$ports): void {
            foreach ($rows as $row) {
                $row = (array) $row;
                $row['url'] = url('device/device=' . $row['device_id'] . '/tab=port/port=' . $row['port_id'] . '/');
                $ports[(int) $row['port_id']] = $row;
            }
        };
        foreach (array_chunk(array_values(array_unique(array_map('intval', $deviceIds))), 5000) as $chunk) {
            $add($this->checked($chunk)->where(function ($query): void {
                $query->where(fn ($q) => $q->where('ifOperStatus', 'up')->where('ifDuplex', 'halfDuplex'))
                    ->orWhere('ifInErrors', '>', 0)->orWhere('ifOutErrors', '>', 0)
                    ->orWhere('ifInErrors_delta', '>', 0)->orWhere('ifOutErrors_delta', '>', 0);
            })->get(self::PORT_COLUMNS));
        }
        $missing = array_values(array_diff(array_unique(array_map('intval', $withChanges)), array_keys($ports)));
        foreach (array_chunk($missing, 5000) as $chunk) {
            $add($this->checked($deviceIds)->whereIntegerInRaw('port_id', $chunk)->get(self::PORT_COLUMNS));
        }

        return $ports;
    }

    /**
     * How many ports of these devices the checks look at.
     *
     * @param  int[]  $deviceIds
     */
    public function portCount(array $deviceIds): int
    {
        if (! $deviceIds || ! $this->allowsTable('ports')) {
            return 0;
        }
        $count = 0;
        foreach (array_chunk(array_values(array_unique(array_map('intval', $deviceIds))), 5000) as $chunk) {
            $count += $this->checked($chunk)->count();
        }

        return $count;
    }

    /**
     * The changes of status, speed and duplex LibreNMS' poller logged for the ports of these devices since $since,
     * oldest first: InterfaceAnalyzer::parseEvent() rows with port_id and time. Beyond EVENT_LIMIT the rest
     * is left out (eventsCapped()).
     *
     * @param  int[]  $deviceIds
     * @return list<array>
     */
    public function events(array $deviceIds, int $since): array
    {
        $this->eventsCapped = false;
        if (! $deviceIds || ! $this->allowsTable('eventlog') || ! $this->allowsTable('ports')) {
            return [];
        }
        $events = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $deviceIds))), 2000) as $chunk) {
            $query = DB::table('eventlog')->where('type', 'interface')->whereIntegerInRaw('device_id', $chunk)
                ->where('datetime', '>=', date('Y-m-d H:i:s', $since))
                ->where(function ($q): void {
                    foreach (self::EVENT_PREFIXES as $prefix) {
                        $q->orWhere('message', 'like', $prefix . '%');
                    }
                })
                ->orderByDesc('datetime')->orderByDesc('event_id')->limit(self::EVENT_LIMIT - count($events) + 1);
            foreach ($query->cursor() as $row) {
                if (count($events) >= self::EVENT_LIMIT) {
                    $this->eventsCapped = true;
                    break;
                }
                $event = InterfaceAnalyzer::parseEvent((string) $row->message);
                $time = strtotime((string) $row->datetime);
                if ($event === null || ! ctype_digit((string) $row->reference) || $time === false) {
                    continue;
                }
                $events[] = ['port_id' => (int) $row->reference, 'time' => $time] + $event;
            }
            if ($this->eventsCapped) {
                break;
            }
        }
        usort($events, fn ($a, $b) => $a['time'] <=> $b['time']);

        return $events;
    }

    /**
     * Did the last events() leave older changes out (more than EVENT_LIMIT)?
     */
    public function eventsCapped(): bool
    {
        return $this->eventsCapped;
    }

    /**
     * When these devices rebooted since $since, from the eventlog.
     *
     * @param  int[]  $deviceIds
     * @return array<int, int[]> device_id => times
     */
    public function reboots(array $deviceIds, int $since): array
    {
        if (! $deviceIds || ! $this->allowsTable('eventlog')) {
            return [];
        }
        $reboots = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $deviceIds))), 5000) as $chunk) {
            foreach (DB::table('eventlog')->where('type', 'reboot')->whereIntegerInRaw('device_id', $chunk)
                ->where('datetime', '>=', date('Y-m-d H:i:s', $since))->get(['device_id', 'datetime']) as $row) {
                $time = strtotime((string) $row->datetime);
                if ($time !== false) {
                    $reboots[(int) $row->device_id][] = $time;
                }
            }
        }

        return $reboots;
    }

    /**
     * The LLDP/CDP neighbor of each port of these devices, with its name and port: INTER_SWITCH for a link to the
     * port of another monitored device the user may see (also when only that device reports it); NEIGHBOR for another
     * monitored device, or a switch, router, access point, phone or server LLDP/CDP names (DeviceRole); ACCESS for any
     * other neighbor (a PC or printer that speaks LLDP), as for a port without one. Of several, the first class counts.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array{class: string, name: string, port: string}> port_id => neighbor
     */
    public function neighbors(array $deviceIds): array
    {
        $rank = [InterfaceAnalyzer::INTER_SWITCH => 0, InterfaceAnalyzer::NEIGHBOR => 1, InterfaceAnalyzer::ACCESS => 2];
        $topology = $this->sibling(TopologyRepository::class);
        $links = $topology->links($deviceIds);
        $toward = $topology->linksToward($deviceIds);
        $names = [];
        foreach ([...$links, ...$toward] as $link) {
            $names[(int) $link['local_device_id']] = true;
            if ($link['remote_device_id']) {
                $names[(int) $link['remote_device_id']] = true;
            }
        }
        $names = $this->names(array_keys($names));
        $portNames = [];
        foreach ($toward as $link) {
            $portNames[(int) $link['local_port_id']] = true;
        }
        foreach ($links as $link) {
            if ($link['remote_port_id']) {
                $portNames[(int) $link['remote_port_id']] = true;
            }
        }
        $portNames = $this->portNames(array_keys($portNames));

        $neighbors = [];
        foreach ($links as $link) {
            $portId = (int) $link['local_port_id'];
            $monitored = $link['remote_device_id'] && $link['remote_port_id'];
            $class = match (true) {
                (bool) $monitored => InterfaceAnalyzer::INTER_SWITCH,
                (bool) $link['remote_device_id'] => InterfaceAnalyzer::NEIGHBOR,
                in_array(DeviceRole::fromNeighbor($link['remote_platform'] ?? '', $link['remote_version'] ?? '', $link['remote_hostname'] ?? ''),
                    [DeviceRole::OTHER, DeviceRole::UNKNOWN], true) => InterfaceAnalyzer::ACCESS,
                default => InterfaceAnalyzer::NEIGHBOR,
            };
            if (! $portId || (isset($neighbors[$portId]) && $rank[$neighbors[$portId]['class']] <= $rank[$class])) {
                continue;
            }
            $neighbors[$portId] = [
                'class' => $class,
                'name' => $link['remote_device_id'] ? ($names[(int) $link['remote_device_id']] ?? '') : trim((string) $link['remote_hostname']),
                'port' => $monitored ? ($portNames[(int) $link['remote_port_id']] ?? '') : InterfaceName::short((string) $link['remote_port']),
            ];
        }
        // the cables only the monitored neighbor reports
        foreach ($toward as $link) {
            $portId = (int) $link['remote_port_id'];
            if ($portId && ($neighbors[$portId]['class'] ?? null) !== InterfaceAnalyzer::INTER_SWITCH) {
                $neighbors[$portId] = ['class' => InterfaceAnalyzer::INTER_SWITCH, 'name' => $names[(int) $link['local_device_id']] ?? '',
                    'port' => $portNames[(int) $link['local_port_id']] ?? ''];
            }
        }

        return $neighbors;
    }

    /**
     * Name, display name and sysName of these devices.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array>
     */
    public function devices(array $deviceIds): array
    {
        return $this->sibling(TopologyRepository::class)->devices($deviceIds, roles: false);
    }

    /**
     * The polled, not ignored or deleted physical Ethernet ports of these devices.
     *
     * @param  int[]  $deviceIds
     */
    private function checked(array $deviceIds): \Illuminate\Database\Query\Builder
    {
        return DB::table('ports')->whereIntegerInRaw('device_id', $deviceIds)->whereIn('ifType', InterfaceAnalyzer::ETHERNET_TYPES)
            ->where('deleted', 0)->where('disabled', 0)->where('ignore', 0);
    }

    /**
     * @param  int[]  $deviceIds
     * @return array<int, string> device_id => display name
     */
    private function names(array $deviceIds): array
    {
        $names = [];
        foreach (array_chunk($deviceIds, 5000) as $chunk) {
            foreach (DB::table('devices')->whereIntegerInRaw('device_id', $chunk)->get(['device_id', 'display', 'hostname']) as $row) {
                $names[(int) $row->device_id] = (string) ($row->display ?: $row->hostname);
            }
        }

        return $names;
    }

    /**
     * @param  int[]  $portIds
     * @return array<int, string> port_id => short name (Gi1/0/1)
     */
    private function portNames(array $portIds): array
    {
        if (! $this->allowsTable('ports')) {
            return [];
        }
        $names = [];
        foreach (array_chunk($portIds, 5000) as $chunk) {
            foreach (DB::table('ports')->whereIntegerInRaw('port_id', $chunk)->get(['port_id', 'ifName', 'ifDescr']) as $row) {
                $names[(int) $row->port_id] = InterfaceName::short(InterfaceName::label((array) $row));
            }
        }

        return $names;
    }
}
