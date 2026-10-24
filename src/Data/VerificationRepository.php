<?php

namespace Agoodley\LibrenmsTopology\Data;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the verification API reads besides the topology and the history (Api\Verification): how fresh LibreNMS' data of
 * each device is (when its last discovery and its last poll started, whether a discovery is pending), its status and
 * current outage, the monitored LLDP/CDP neighbors of devices in both directions, and the names and descriptions of
 * ports. Every answer is limited to the devices the user may see.
 */
class VerificationRepository extends ScopedRepository
{
    /**
     * Status and freshness of the devices the user may see among $ids: up, since when it is down (its current outage),
     * when its last discovery and its last poll started (LibreNMS records their end and the time they took; a down
     * device is neither discovered nor counted as polled), and whether a discovery was asked for and has not run
     * (last_discovered empty, as LibreNMS' Rediscover leaves it).
     *
     * @param  int[]  $ids
     * @return array<int, array{up: bool, down_since: ?int, discovered_at: ?int, polled_at: ?int, discovery_pending: bool}>
     */
    public function deviceStates(array $ids): array
    {
        $ids = $this->visibleOf($ids);
        if (! $ids) {
            return [];
        }
        $states = [];
        foreach (array_chunk($ids, 5000) as $chunk) {
            foreach ($this->deviceRows($chunk) as $row) {
                $states[(int) $row->device_id] = self::stateOf($row);
            }
        }
        $down = array_keys(array_filter($states, fn (array $state) => ! $state['up']));
        if ($down) {
            foreach ($this->outageStarts($down) as $deviceId => $since) {
                $states[(int) $deviceId]['down_since'] = (int) $since;
            }
        }

        return $states;
    }

    /**
     * A device's status and freshness from its devices row: up; when its last discovery and its last poll started (LibreNMS
     * records when one ended and how long it took); a discovery pending when it was asked for and has not run
     * (last_discovered empty, as LibreNMS' Rediscover leaves it). Its outage (down_since) is read apart.
     *
     * @return array{up: bool, down_since: ?int, discovered_at: ?int, polled_at: ?int, discovery_pending: bool}
     */
    public static function stateOf(object $row): array
    {
        return [
            'up' => (bool) $row->status,
            'down_since' => null,
            'discovered_at' => self::started($row->last_discovered, $row->last_discovered_timetaken),
            'polled_at' => self::started($row->last_polled, $row->last_polled_timetaken),
            'discovery_pending' => $row->last_discovered === null,
        ];
    }

    /**
     * The devices rows of these devices: their status and the end and length of their last discovery and poll.
     *
     * @param  int[]  $ids
     * @return iterable<object>
     */
    protected function deviceRows(array $ids): iterable
    {
        return DB::table('devices')->whereIntegerInRaw('device_id', $ids)
            ->get(['device_id', 'status', 'last_discovered', 'last_discovered_timetaken', 'last_polled', 'last_polled_timetaken']);
    }

    /**
     * When the current outage of each of these devices began (the device_outages table, when the user's role may read it).
     *
     * @param  int[]  $ids
     * @return array<int, int> device_id => Unix time
     */
    protected function outageStarts(array $ids): array
    {
        if (! $this->allowsTable('device_outages') || ! Schema::hasTable('device_outages')) {
            return [];
        }
        $since = [];
        foreach (DB::table('device_outages')->whereIntegerInRaw('device_id', $ids)->whereNull('up_again')->groupBy('device_id')
            ->selectRaw('device_id, MAX(going_down) AS since')->get() as $row) {
            $since[(int) $row->device_id] = (int) $row->since;
        }

        return $since;
    }

    /**
     * The monitored LLDP/CDP neighbors of the devices that the user may see: the far ends the devices report, and the
     * devices that report one of them as their neighbor (a link seen from one side only counts too).
     *
     * @param  int[]  $ids
     * @return int[]
     */
    public function neighborsOf(array $ids): array
    {
        $ids = $this->visibleOf($ids);
        if (! $ids || ! $this->allowsTable('links')) {
            return [];
        }
        $found = [];
        foreach (array_chunk($ids, 5000) as $chunk) {
            foreach ($this->reportedBy($chunk) as $deviceId) {
                $found[(int) $deviceId] = true;
            }
            foreach ($this->reporting($chunk) as $deviceId) {
                $found[(int) $deviceId] = true;
            }
        }

        return array_values(array_diff($this->visibleOf(array_keys($found)), $ids));
    }

    /**
     * The monitored far ends the devices report by LLDP/CDP (their active links).
     *
     * @param  int[]  $ids
     * @return iterable<int>
     */
    protected function reportedBy(array $ids): iterable
    {
        return DB::table('links')->where('active', 1)->whereIntegerInRaw('local_device_id', $ids)->where('remote_device_id', '>', 0)->distinct()->pluck('remote_device_id');
    }

    /**
     * The devices that report one of these devices as their far end (a link LLDP/CDP sees from that side only too).
     *
     * @param  int[]  $ids
     * @return iterable<int>
     */
    protected function reporting(array $ids): iterable
    {
        return DB::table('links')->where('active', 1)->whereIntegerInRaw('remote_device_id', $ids)->distinct()->pluck('local_device_id');
    }

    /**
     * Name, description and device of ports of the devices the user may see (for fixes: what a port is called in its
     * device's configuration, what its description is now); ports the user's role may not view are left out.
     *
     * @param  int[]  $portIds
     * @return array<int, array{device_id: int, ifName: string, ifDescr: string, ifAlias: string}>
     */
    public function portNames(array $portIds): array
    {
        if (! $portIds || ! $this->allowsTable('ports')) {
            return [];
        }
        $visible = array_flip($this->deviceIds());
        $ports = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $portIds))), 5000) as $chunk) {
            foreach (DB::table('ports')->whereIntegerInRaw('port_id', $chunk)->where('deleted', 0)->get(['port_id', 'device_id', 'ifName', 'ifDescr', 'ifAlias']) as $row) {
                if (isset($visible[(int) $row->device_id])) {
                    $ports[(int) $row->port_id] = ['device_id' => (int) $row->device_id, 'ifName' => (string) $row->ifName, 'ifDescr' => (string) $row->ifDescr,
                        'ifAlias' => (string) $row->ifAlias];
                }
            }
        }

        return $ports;
    }

    /**
     * The devices among $ids the user may see, in their order, each once.
     *
     * @param  int[]  $ids
     * @return int[]
     */
    public function visibleOf(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $this->canSee($id))));
    }

    /**
     * When a run that ended at $end and took $took seconds started (Unix time), null when it never ran.
     */
    private static function started(mixed $end, mixed $took): ?int
    {
        if ($end === null || $end === '') {
            return null;
        }
        $at = strtotime((string) $end);

        return $at === false ? null : $at - (int) ceil((float) $took);
    }
}
