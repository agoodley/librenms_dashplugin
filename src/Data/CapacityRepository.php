<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Capacity\ErrDisable;
use Agoodley\LibrenmsTopology\Interfaces\InterfaceAnalyzer;
use Agoodley\LibrenmsTopology\Interfaces\RrdTotals;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The LibreNMS data of the port capacity analysis (Capacity\CapacityService): the switches of the devices
 * the user may see with their location, uptime and last poll, their physical Ethernet ports, the LLDP/CDP
 * neighbor of each port, port-channels, the eventlog's port changes and err-disable traps, err-disable
 * syslog messages, and the plugin's record of each port (topology_port_usage, Capacity\PortUsage). Times
 * are returned as Unix timestamps.
 */
class CapacityRepository extends ScopedRepository
{
    /** The plugin's record of the ports. */
    public const RECORD_TABLE = 'topology_port_usage';

    /** Port columns the analysis reads. */
    private const PORT_COLUMNS = ['port_id', 'device_id', 'ifIndex', 'ifName', 'ifDescr', 'ifAlias', 'ifType', 'ifSpeed', 'ifOperStatus',
        'ifAdminStatus', 'ifLastChange', 'ifVlan', 'ifTrunk', 'poll_time'];

    /** Most err-disable messages read at once (newest first): they are rare, this only bounds a flood. */
    public const MESSAGE_LIMIT = 50000;

    /** Rows written to the record at once. */
    private const WRITE_CHUNK = 500;

    /** @var bool|null whether the record table exists (migrations run) */
    private static ?bool $recordAvailable = null;

    /**
     * The switches (and L3 switches) among these devices, by their role (Support\DeviceRole), with name, location,
     * uptime, last poll and the time LibreNMS added them.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array> device_id => name, hostname, location_id, location, uptime, last_polled, inserted, role, url
     */
    public function switches(array $deviceIds): array
    {
        $deviceIds = array_values(array_unique(array_map('intval', $deviceIds)));
        $switches = [];
        foreach (array_chunk($deviceIds, 2000) as $chunk) {
            $devices = $this->sibling(TopologyRepository::class)->devices($chunk);
            $extra = [];
            foreach (DB::table('devices')->whereIntegerInRaw('device_id', $chunk)->get(['device_id', 'location_id', 'last_polled', 'inserted']) as $row) {
                $extra[(int) $row->device_id] = $row;
            }
            foreach ($devices as $id => $device) {
                if (! in_array($device['role'] ?? null, [DeviceRole::SWITCH, DeviceRole::L3SWITCH], true)) {
                    continue;
                }
                $row = $extra[$id] ?? null;
                $switches[(int) $id] = [
                    'device_id' => (int) $id,
                    'name' => (string) (($device['display'] ?? '') ?: $device['hostname']),
                    'hostname' => (string) $device['hostname'],
                    'location_id' => $row && $row->location_id ? (int) $row->location_id : null,
                    'location' => (string) ($device['location'] ?? ''),
                    'uptime' => isset($device['uptime']) ? (int) $device['uptime'] : null,
                    'last_polled' => self::time($row->last_polled ?? null),
                    'inserted' => self::time($row->inserted ?? null),
                    'status' => (int) ($device['status'] ?? 0),
                    'hardware' => (string) ($device['hardware'] ?? ''),
                    'role' => $device['role'],
                    'url' => (string) ($device['url'] ?? url('device/' . $id)),
                ];
            }
        }

        return $switches;
    }

    /**
     * The physical Ethernet ports of these devices that LibreNMS polls and still has (ignored ones too: LibreNMS
     * only stops alerting on them).
     *
     * @param  int[]  $deviceIds
     * @return array<int, array> port_id => row with url
     */
    public function ports(array $deviceIds): array
    {
        if (! $deviceIds || ! $this->allowsTable('ports')) {
            return [];
        }
        $ports = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $deviceIds))), 1000) as $chunk) {
            foreach (DB::table('ports')->whereIntegerInRaw('device_id', $chunk)->whereIn('ifType', InterfaceAnalyzer::ETHERNET_TYPES)
                ->where('deleted', 0)->where('disabled', 0)->get(self::PORT_COLUMNS) as $row) {
                $row = (array) $row;
                $row['url'] = url('device/device=' . $row['device_id'] . '/tab=port/port=' . $row['port_id'] . '/');
                $ports[(int) $row['port_id']] = $row;
            }
        }

        return $ports;
    }

    /**
     * The LLDP/CDP neighbor of each port of these devices: an uplink when it is a monitored device the user may see
     * whose role is a switch, router or firewall, or an unmonitored neighbor LLDP/CDP names as one (DeviceRole); also
     * when only the monitored neighbor reports the cable. An access point, phone or server is no uplink.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array{uplink: bool, name: string}> port_id => neighbor
     */
    public function neighbors(array $deviceIds): array
    {
        $topology = $this->sibling(TopologyRepository::class);
        $links = $topology->links($deviceIds);
        $toward = $topology->linksToward($deviceIds);
        $remote = [];
        foreach ([...$links, ...$toward] as $link) {
            foreach (['local_device_id', 'remote_device_id'] as $key) {
                if (! empty($link[$key])) {
                    $remote[(int) $link[$key]] = true;
                }
            }
        }
        $devices = [];
        foreach (array_chunk(array_keys($remote), 2000) as $chunk) {
            $devices += $topology->devices($chunk);
        }
        $network = fn (?int $deviceId) => $deviceId !== null && in_array($devices[$deviceId]['role'] ?? null, DeviceRole::NETWORK, true);
        $name = fn (?int $deviceId) => $deviceId !== null && isset($devices[$deviceId])
            ? (string) (($devices[$deviceId]['display'] ?? '') ?: $devices[$deviceId]['hostname']) : '';

        $neighbors = [];
        $set = function (int $portId, bool $uplink, string $label) use (&$neighbors): void {
            if ($portId && (! isset($neighbors[$portId]) || ($uplink && ! $neighbors[$portId]['uplink']))) {
                $neighbors[$portId] = ['uplink' => $uplink, 'name' => $label];
            }
        };
        foreach ($links as $link) {
            $remoteId = ! empty($link['remote_device_id']) ? (int) $link['remote_device_id'] : null;
            $uplink = $remoteId !== null ? $network($remoteId)
                : in_array(DeviceRole::fromNeighbor($link['remote_platform'] ?? '', $link['remote_version'] ?? '', $link['remote_hostname'] ?? ''), DeviceRole::NETWORK, true);
            $label = $remoteId !== null ? $name($remoteId) : trim((string) ($link['remote_hostname'] ?? ''));
            $port = InterfaceName::short((string) ($link['remote_port'] ?? ''));
            $set((int) $link['local_port_id'], $uplink, $label . ($label !== '' && $port !== '' ? ' ' . $port : ''));
        }
        // the cables only the monitored neighbor reports
        foreach ($toward as $link) {
            $localId = (int) $link['local_device_id'];
            $set((int) ($link['remote_port_id'] ?? 0), $network($localId), $name($localId));
        }

        return $neighbors;
    }

    /**
     * Port-channel membership of these devices' ports: member port_id => aggregate port_id.
     *
     * @param  int[]  $deviceIds
     * @return array<int, int>
     */
    public function lags(array $deviceIds): array
    {
        return $deviceIds ? $this->sibling(TopologyRepository::class)->lagParents($deviceIds) : [];
    }

    /**
     * The latest time an ifOperStatus change of LibreNMS' eventlog since $since shows each port of these devices up
     * ("up -> down", "down -> up").
     *
     * @param  int[]  $deviceIds
     * @return array<int, int> port_id => time
     */
    public function eventUps(array $deviceIds, int $since): array
    {
        if (! $deviceIds || ! $this->allowsTable('eventlog') || ! $this->allowsTable('ports')) {
            return [];
        }
        $ups = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $deviceIds))), 1000) as $chunk) {
            try {
                $rows = DB::table('eventlog')->where('type', 'interface')->whereIntegerInRaw('device_id', $chunk)
                    ->where('datetime', '>=', date('Y-m-d H:i:s', $since))
                    ->where(fn ($q) => $q->where('message', 'like', 'ifOperStatus: up ->%')->orWhere('message', 'like', 'ifOperStatus: % -> up'))
                    ->groupBy('reference')->selectRaw('reference, MAX(datetime) as latest')->get();
            } catch (\Throwable $e) {
                Log::debug('topology plugin: cannot read the port changes of the eventlog: ' . $e->getMessage());

                return $ups;
            }
            foreach ($rows as $row) {
                $time = self::time($row->latest);
                if (ctype_digit((string) $row->reference) && $time !== null) {
                    $ups[(int) $row->reference] = max($ups[(int) $row->reference] ?? 0, $time);
                }
            }
        }

        return $ups;
    }

    /**
     * Since when the eventlog holds the port changes of these devices: its oldest entry, or when LibreNMS added the
     * device if that was later. None when the eventlog cannot be read or is empty.
     *
     * @param  array<int, array>  $devices  device_id => inserted (timestamp)
     * @return array<int, int> device_id => time
     */
    public function eventSince(array $devices): array
    {
        if (! $devices || ! $this->allowsTable('eventlog')) {
            return [];
        }
        try {
            $oldest = self::time(DB::table('eventlog')->min('datetime'));
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read the eventlog: ' . $e->getMessage());

            return [];
        }
        if ($oldest === null) {
            return [];
        }
        $since = [];
        foreach ($devices as $deviceId => $device) {
            $since[(int) $deviceId] = max($oldest, (int) ($device['inserted'] ?? 0));
        }

        return $since;
    }

    /**
     * The messages since $since that may report err-disabled ports of these devices, newest first: syslog (program
     * and message) and the eventlog entries of SNMP traps that name err-disable, filtered with ErrDisable::LIKE
     * (ErrDisable::matches() decides). At most MESSAGE_LIMIT of each.
     *
     * @param  int[]  $deviceIds
     * @return list<array{device_id: int, time: int, text: string}>
     */
    public function errDisableMessages(array $deviceIds, int $since): array
    {
        $deviceIds = array_values(array_unique(array_map('intval', $deviceIds)));
        if (! $deviceIds) {
            return [];
        }
        $from = date('Y-m-d H:i:s', $since);
        $messages = [];
        if ($this->allowsTable('syslog')) {
            $count = 0;
            foreach (array_chunk($deviceIds, 500) as $chunk) {
                try {
                    $rows = DB::table('syslog')->whereIntegerInRaw('device_id', $chunk)->where('timestamp', '>=', $from)
                        ->where(fn ($q) => $q->where('msg', 'like', ErrDisable::LIKE)->orWhere('program', 'like', ErrDisable::LIKE))
                        ->orderByDesc('timestamp')->limit(self::MESSAGE_LIMIT - $count)->get(['device_id', 'timestamp', 'program', 'msg']);
                } catch (\Throwable $e) {
                    Log::debug('topology plugin: cannot read syslog: ' . $e->getMessage());
                    break;
                }
                foreach ($rows as $row) {
                    $time = self::time($row->timestamp);
                    if ($time !== null) {
                        $messages[] = ['device_id' => (int) $row->device_id, 'time' => $time, 'text' => trim($row->program . ' ' . $row->msg)];
                    }
                }
                $count += count($rows);
                if ($count >= self::MESSAGE_LIMIT) {
                    break;
                }
            }
        }
        if ($this->allowsTable('eventlog')) {
            foreach (array_chunk($deviceIds, 1000) as $chunk) {
                try {
                    $rows = DB::table('eventlog')->where('type', 'trap')->whereIntegerInRaw('device_id', $chunk)->where('datetime', '>=', $from)
                        ->where('message', 'like', ErrDisable::LIKE)->orderByDesc('datetime')->limit(self::MESSAGE_LIMIT)->get(['device_id', 'datetime', 'message']);
                } catch (\Throwable $e) {
                    Log::debug('topology plugin: cannot read the eventlog: ' . $e->getMessage());
                    break;
                }
                foreach ($rows as $row) {
                    $time = self::time($row->datetime);
                    if ($time !== null) {
                        $messages[] = ['device_id' => (int) $row->device_id, 'time' => $time, 'text' => (string) $row->message];
                    }
                }
            }
        }

        return $messages;
    }

    /**
     * Does LibreNMS keep syslog of these devices (any message since $since)? null when the user's role may not read it.
     *
     * @param  int[]  $deviceIds
     * @return int[]|null the devices that sent syslog
     */
    public function syslogSenders(array $deviceIds, int $since): ?array
    {
        if (! $this->allowsTable('syslog')) {
            return null;
        }
        $senders = [];
        $from = date('Y-m-d H:i:s', $since);
        try {
            foreach (array_values(array_unique(array_map('intval', $deviceIds))) as $deviceId) {
                if (DB::table('syslog')->where('device_id', $deviceId)->where('timestamp', '>=', $from)->exists()) {
                    $senders[] = $deviceId;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read syslog: ' . $e->getMessage());

            return null;
        }

        return $senders;
    }

    /**
     * Has the plugin's record been created (./lnms migrate)?
     */
    public function recordAvailable(): bool
    {
        if (self::$recordAvailable === null) {
            try {
                self::$recordAvailable = Schema::hasTable(self::RECORD_TABLE);
            } catch (\Throwable $e) {
                Log::debug('topology plugin: cannot check the port record: ' . $e->getMessage());
                self::$recordAvailable = false;
            }
        }

        return self::$recordAvailable;
    }

    /**
     * The plugin's record of the ports of these devices (all ports when $deviceIds is null: the scheduled job).
     *
     * @param  int[]|null  $deviceIds
     * @return array<int, array> port_id => device_id, first_seen, last_up, err_disabled_at, err_disabled_message
     */
    public function records(?array $deviceIds): array
    {
        if (! $this->recordAvailable() || ($deviceIds !== null && (! $deviceIds || ! $this->allowsTable('ports')))) {
            return [];
        }
        if ($deviceIds === null && ! $this->isSystem()) {
            throw new \LogicException('only the system scope reads the record of every port');
        }
        $records = [];
        $read = function ($query) use (&$records): void {
            foreach ($query->get(['port_id', 'device_id', 'first_seen', 'last_up', 'err_disabled_at', 'err_disabled_message']) as $row) {
                $records[(int) $row->port_id] = [
                    'device_id' => (int) $row->device_id,
                    'first_seen' => self::time($row->first_seen),
                    'last_up' => self::time($row->last_up),
                    'err_disabled_at' => self::time($row->err_disabled_at),
                    'err_disabled_message' => $row->err_disabled_message,
                ];
            }
        };
        if ($deviceIds === null) {
            $read(DB::table(self::RECORD_TABLE));
        } else {
            foreach (array_chunk(array_values(array_unique(array_map('intval', $deviceIds))), 1000) as $chunk) {
                $read(DB::table(self::RECORD_TABLE)->whereIntegerInRaw('device_id', $chunk));
            }
        }

        return $records;
    }

    /**
     * Write the record (Capacity\PortUsage::update()): insert or update these rows, forget these ports. System scope only.
     *
     * @param  array<int, array>  $rows  port_id => device_id, first_seen, last_up, err_disabled_at, err_disabled_message
     * @param  int[]  $remove
     */
    public function saveRecords(array $rows, array $remove, int $now): void
    {
        if (! $this->isSystem()) {
            throw new \LogicException('only the scheduled job writes the port record');
        }
        $date = fn (?int $time) => $time === null ? null : date('Y-m-d H:i:s', $time);
        foreach (array_chunk($rows, self::WRITE_CHUNK, true) as $chunk) {
            $values = [];
            foreach ($chunk as $portId => $row) {
                $values[] = [
                    'port_id' => (int) $portId,
                    'device_id' => (int) $row['device_id'],
                    'first_seen' => $date($row['first_seen']),
                    'last_up' => $date($row['last_up']),
                    'err_disabled_at' => $date($row['err_disabled_at']),
                    'err_disabled_message' => $row['err_disabled_message'],
                    'updated_at' => $date($now),
                ];
            }
            DB::table(self::RECORD_TABLE)->upsert($values, ['port_id'], ['device_id', 'last_up', 'err_disabled_at', 'err_disabled_message', 'updated_at']);
        }
        foreach (array_chunk(array_values(array_unique(array_map('intval', $remove))), self::WRITE_CHUNK) as $chunk) {
            DB::table(self::RECORD_TABLE)->whereIntegerInRaw('port_id', $chunk)->delete();
        }
    }

    /**
     * Since when the plugin keeps the record of these devices' ports: the first port it recorded; null without any.
     *
     * @param  int[]  $deviceIds
     */
    public function recordSince(array $deviceIds): ?int
    {
        if (! $this->recordAvailable() || ! $deviceIds) {
            return null;
        }
        $first = null;
        try {
            foreach (array_chunk(array_values(array_unique(array_map('intval', $deviceIds))), 1000) as $chunk) {
                $time = self::time(DB::table(self::RECORD_TABLE)->whereIntegerInRaw('device_id', $chunk)->min('first_seen'));
                $first = $time !== null && ($first === null || $time < $first) ? $time : $first;
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read the port record: ' . $e->getMessage());

            return null;
        }

        return $first;
    }

    /**
     * Which ports are PoE ports, for the switches LibreNMS has PoE data of: it writes port-id<id>-poe.rrd for each port
     * whose PoE it polls (enable_ports_poe, for the OSes it supports). The ports of a switch without any such file are
     * left out (no PoE, or no data of it); null when LibreNMS' RRD files are not on this server (rrdcached elsewhere).
     *
     * @param  array<int, array>  $ports  port_id => device_id
     * @param  array<int, array>  $devices  device_id => hostname
     * @return array<int, bool>|null port_id => PoE
     */
    public function poePorts(array $ports, array $devices): ?array
    {
        if (self::config('rrd.enable', true) === false) {
            return null;
        }
        $directory = rtrim((string) self::config('rrd_dir', ''), '/');
        if ($directory === '' || ! is_dir($directory)) {
            return null;
        }
        $byDevice = [];
        foreach ($ports as $portId => $port) {
            $byDevice[(int) $port['device_id']][] = (int) $portId;
        }
        $poe = [];
        foreach ($byDevice as $deviceId => $portIds) {
            $hostname = trim((string) ($devices[$deviceId]['hostname'] ?? ''), '[]');
            if ($hostname === '') {
                continue;
            }
            // one directory read per switch rather than a look-up per port
            $files = glob($directory . '/' . RrdTotals::safeName($hostname) . '/port-id*-poe.rrd', GLOB_NOSORT) ?: [];
            $found = [];
            foreach ($files as $file) {
                if (preg_match('~/port-id(\d+)-poe\.rrd$~', $file, $match)) {
                    $found[(int) $match[1]] = true;
                }
            }
            if ($found) {
                foreach ($portIds as $portId) {
                    $poe[$portId] = isset($found[$portId]);
                }
            }
        }

        return $poe;
    }

    /**
     * A LibreNMS setting, through the LibrenmsConfig facade (LibreNMS\Config on older versions); $default without either.
     */
    public static function config(string $key, mixed $default = null): mixed
    {
        try {
            return match (true) {
                class_exists(\App\Facades\LibrenmsConfig::class) => \App\Facades\LibrenmsConfig::get($key, $default),
                class_exists(\LibreNMS\Config::class) => \LibreNMS\Config::get($key, $default),
                default => $default,
            };
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * A date and time of the database as a timestamp (null for none or the zero date).
     */
    public static function time(mixed $value): ?int
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        $time = strtotime((string) $value);

        return $time === false ? null : $time;
    }
}
