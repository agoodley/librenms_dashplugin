<?php

namespace Agoodley\LibrenmsTopology\Data;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Data of the loop evidence on the spanning tree page: broadcast / multicast counters of
 * inter-switch ports and the loop related syslog messages. The callers pass device and port ids
 * already scoped to the user (ScopedRepository); syslog is only read when LibreNMS lets the user
 * view it.
 */
class LoopEvidenceRepository extends ScopedRepository
{
    /** Matching syslog messages read at most (newest first). */
    public const SYSLOG_LIMIT = 5000;

    /** Messages read at most when the database cannot filter them with the pattern. */
    private const SYSLOG_SCAN_LIMIT = 20000;

    /**
     * Port rows of the given ports with their unicast, broadcast and multicast packet rates of the
     * last poll (ports and ports_statistics); none when this LibreNMS version lacks the columns.
     *
     * @param  int[]  $portIds
     * @return array<int, array> port_id => row
     */
    public function portRates(array $portIds): array
    {
        $result = [];
        try {
            foreach (array_chunk(array_values(array_unique(array_filter($portIds))), 5000) as $chunk) {
                foreach (DB::table('ports as p')->leftJoin('ports_statistics as s', 's.port_id', '=', 'p.port_id')
                    ->whereIntegerInRaw('p.port_id', $chunk)
                    ->get([
                        'p.port_id', 'p.device_id', 'p.ifIndex', 'p.ifName', 'p.ifDescr', 'p.ifSpeed', 'p.ifOperStatus',
                        'p.ifInUcastPkts_rate', 'p.ifOutUcastPkts_rate', 'p.poll_time', 'p.poll_period',
                        's.ifInBroadcastPkts_rate', 's.ifOutBroadcastPkts_rate', 's.ifInMulticastPkts_rate', 's.ifOutMulticastPkts_rate',
                    ]) as $row) {
                    $result[(int) $row->port_id] = (array) $row;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read port packet rates: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Syslog messages of these devices since $since, newest first. With a pattern the database only
     * returns matching messages (at most SYSLOG_LIMIT); a database whose REGEXP does not understand
     * the pattern returns the newest messages unfiltered instead and the caller matches them.
     *
     * @param  int[]  $deviceIds
     * @return array{rows: array<int, array>, truncated: bool}
     */
    public function syslog(array $deviceIds, int $since, ?string $pattern): array
    {
        if (! $deviceIds) {
            return ['rows' => [], 'truncated' => false];
        }

        $query = fn (int $limit) => DB::table('syslog')->whereIntegerInRaw('device_id', $deviceIds)
            ->where('timestamp', '>=', date('Y-m-d H:i:s', $since))
            ->orderByDesc('timestamp')->orderByDesc('seq')->limit($limit + 1);
        $columns = ['seq', 'device_id', 'timestamp', 'program', 'msg'];

        $rows = null;
        $limit = self::SYSLOG_LIMIT;
        if ($pattern !== null) {
            try {
                $rows = $query($limit)->whereRaw("CONCAT_WS(' ', program, msg) REGEXP ?", [$pattern])->get($columns);
            } catch (\Throwable $e) {
                Log::debug('topology plugin: the database cannot filter syslog with the loop patterns: ' . $e->getMessage());
            }
        }
        if ($rows === null) {
            $limit = self::SYSLOG_SCAN_LIMIT;
            try {
                $rows = $query($limit)->get($columns);
            } catch (\Throwable $e) {
                Log::debug('topology plugin: cannot read syslog: ' . $e->getMessage());

                return ['rows' => [], 'truncated' => false];
            }
        }

        $rows = $rows->map(fn ($row) => (array) $row)->all();

        return ['rows' => array_slice($rows, 0, $limit), 'truncated' => count($rows) > $limit];
    }

    /**
     * How many of these devices sent any syslog message since $since (to tell "no problem" from
     * "no syslog").
     *
     * @param  int[]  $deviceIds
     */
    public function syslogDevices(array $deviceIds, int $since): int
    {
        if (! $deviceIds) {
            return 0;
        }

        try {
            return (int) DB::table('syslog')->whereIntegerInRaw('device_id', $deviceIds)
                ->where('timestamp', '>=', date('Y-m-d H:i:s', $since))
                ->distinct()->count('device_id');
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot count syslog senders: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Port names of these devices, to recognize the ports named in their messages.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array> port_id => row (device_id, ifIndex, ifName, ifDescr)
     */
    public function portNames(array $deviceIds): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique(array_filter($deviceIds))), 500) as $chunk) {
            foreach (DB::table('ports')->whereIntegerInRaw('device_id', $chunk)->get(['port_id', 'device_id', 'ifIndex', 'ifName', 'ifDescr']) as $row) {
                $result[(int) $row->port_id] = (array) $row;
            }
        }

        return $result;
    }

    /**
     * May the user read syslog? LibreNMS versions with a syslog policy decide (custom roles can lack
     * it); older versions show a device's syslog to anyone who can see the device.
     */
    public function canViewSyslog(): bool
    {
        try {
            if (! class_exists(\App\Models\Syslog::class) || Gate::getPolicyFor(\App\Models\Syslog::class) === null) {
                return true;
            }

            return Gate::forUser($this->user)->allows('viewAny', \App\Models\Syslog::class);
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot check the syslog permission: ' . $e->getMessage());

            return false;
        }
    }
}
