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
            foreach (DB::table('devices')->whereIntegerInRaw('device_id', $chunk)
                ->get(['device_id', 'status', 'last_discovered', 'last_discovered_timetaken', 'last_polled', 'last_polled_timetaken']) as $row) {
                $states[(int) $row->device_id] = [
                    'up' => (bool) $row->status,
                    'down_since' => null,
                    'discovered_at' => self::started($row->last_discovered, $row->last_discovered_timetaken),
                    'polled_at' => self::started($row->last_polled, $row->last_polled_timetaken),
                    'discovery_pending' => $row->last_discovered === null,
                ];
            }
        }
        $down = array_keys(array_filter($states, fn (array $state) => ! $state['up']));
        if ($down && $this->allowsTable('device_outages') && Schema::hasTable('device_outages')) {
            foreach (DB::table('device_outages')->whereIntegerInRaw('device_id', $down)->whereNull('up_again')->groupBy('device_id')
                ->selectRaw('device_id, MAX(going_down) AS since')->get() as $row) {
                $states[(int) $row->device_id]['down_since'] = (int) $row->since;
            }
        }

        return $states;
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
            foreach (DB::table('links')->where('active', 1)->whereIntegerInRaw('local_device_id', $chunk)->where('remote_device_id', '>', 0)
                ->distinct()->pluck('remote_device_id') as $deviceId) {
                $found[(int) $deviceId] = true;
            }
            foreach (DB::table('links')->where('active', 1)->whereIntegerInRaw('remote_device_id', $chunk)->distinct()->pluck('local_device_id') as $deviceId) {
                $found[(int) $deviceId] = true;
            }
        }

        return array_values(array_diff($this->visibleOf(array_keys($found)), $ids));
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
