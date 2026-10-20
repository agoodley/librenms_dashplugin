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
    /** Matching syslog messages kept at most (the newest). */
    public const SYSLOG_MATCHES = 5000;

    /** Messages searched at most per page view (the newest of the window), which bounds the time on busy logs. */
    public const SYSLOG_SCAN = 100000;

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
     * Syslog messages of these devices since $since that match $regex, newest first. The messages are
     * matched here rather than with the database's REGEXP, which is several times slower on large
     * syslog tables and differs between MySQL and MariaDB.
     *
     * @param  int[]  $deviceIds
     * @param  string  $regex  PCRE of every enabled check (LoopEvidence::scanPattern())
     * @return array{rows: array<int, array>, truncated: bool, scan_limited: bool}
     *     truncated: more matches than kept; scan_limited: the window has more messages than were searched
     */
    public function syslog(array $deviceIds, int $since, string $regex): array
    {
        $result = ['rows' => [], 'truncated' => false, 'scan_limited' => false];
        if (! $deviceIds) {
            return $result;
        }

        try {
            $scanned = 0;
            foreach (DB::table('syslog')->whereIntegerInRaw('device_id', $deviceIds)
                ->where('timestamp', '>=', date('Y-m-d H:i:s', $since))
                ->orderByDesc('timestamp')->orderByDesc('seq')->limit(self::SYSLOG_SCAN + 1)
                ->select(['seq', 'device_id', 'timestamp', 'program', 'msg'])->cursor() as $row) {
                if (++$scanned > self::SYSLOG_SCAN) {
                    $result['scan_limited'] = true;
                    break;
                }
                if (preg_match($regex, trim($row->program . ' ' . $row->msg)) !== 1) {
                    continue;
                }
                if (count($result['rows']) >= self::SYSLOG_MATCHES) {
                    $result['truncated'] = true;
                    continue;
                }
                $result['rows'][] = (array) $row;
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read syslog: ' . $e->getMessage());
        }

        return $result;
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
