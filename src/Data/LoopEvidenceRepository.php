<?php

namespace Agoodley\LibrenmsTopology\Data;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Data of the loop evidence on the spanning tree page: packet counters of inter-switch ports and
 * the loop related syslog messages, of the devices the user may see; syslog only when LibreNMS lets
 * the user view it.
 */
class LoopEvidenceRepository extends ScopedRepository
{
    /** Syslog messages searched at most per page view, shared by the devices in turns. */
    public const SYSLOG_SCAN = 100000;

    /** Matching syslog messages kept at most. */
    public const SYSLOG_MATCHES = 5000;

    /** The index a device's messages are read on, newest first. */
    private const SYSLOG_INDEX = 'syslog_device_id_timestamp_index';

    /** Without the hint MariaDB reads all newer messages of the device again for every page. */
    private bool $indexHint = true;

    /**
     * Port rows of the given ports with their unicast, broadcast and multicast counters and rates of
     * the last poll (ports and ports_statistics); deleted and disabled ports and ports of devices the
     * user may not see are left out, and everything when this LibreNMS version lacks the columns.
     *
     * @param  int[]  $portIds
     * @return array<int, array> port_id => row
     */
    public function portRates(array $portIds): array
    {
        $result = [];
        try {
            foreach (array_chunk(array_values(array_unique(array_filter(array_map('intval', $portIds)))), 5000) as $chunk) {
                foreach (DB::table('ports as p')->leftJoin('ports_statistics as s', 's.port_id', '=', 'p.port_id')
                    ->whereIntegerInRaw('p.port_id', $chunk)->where('p.deleted', 0)->where('p.disabled', 0)
                    ->get([
                        'p.port_id', 'p.device_id', 'p.ifIndex', 'p.ifName', 'p.ifDescr', 'p.ifSpeed', 'p.ifOperStatus',
                        'p.ifInUcastPkts_rate', 'p.ifOutUcastPkts_rate', 'p.poll_time', 'p.poll_period',
                        's.ifInBroadcastPkts', 's.ifOutBroadcastPkts', 's.ifInMulticastPkts', 's.ifOutMulticastPkts',
                        's.ifInBroadcastPkts_rate', 's.ifOutBroadcastPkts_rate', 's.ifInMulticastPkts_rate', 's.ifOutMulticastPkts_rate',
                    ]) as $row) {
                    if ($this->canSee((int) $row->device_id)) {
                        $result[(int) $row->port_id] = (array) $row;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read port packet rates: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * The syslog messages of these devices since $since that $classify assigns to a check, with its
     * code. The devices that sent syslog in the window are read newest first and in turns, each turn
     * a page of messages or a share of the matches, so a busy or looping device cannot use up what the
     * others get; reading stops after SYSLOG_SCAN messages or SYSLOG_MATCHES matches. Matching here
     * rather than with the database's REGEXP is several times faster on large syslog tables and the
     * same on MySQL and MariaDB.
     *
     * @param  int[]  $deviceIds
     * @param  callable(string): ?string  $classify  check code of a message (program and text), null when it is no evidence
     * @return array{rows: array<int, array>, senders: int, scanned: int, truncated: bool, limited: int}
     *     senders: devices that sent syslog in the window; scanned: messages searched; truncated: matching messages
     *     were left out; limited: devices whose older messages in the window were not searched
     */
    public function syslog(array $deviceIds, int $since, callable $classify): array
    {
        $result = ['rows' => [], 'senders' => 0, 'scanned' => 0, 'truncated' => false, 'limited' => 0];
        $deviceIds = array_values(array_filter(array_unique(array_map('intval', $deviceIds)), fn ($id) => $this->canSee($id)));
        if (! $deviceIds || ! $this->canViewSyslog()) {
            return $result;
        }

        try {
            $from = date('Y-m-d H:i:s', $since);
            $turns = array_fill_keys($this->syslogSenders($deviceIds, $from), null); // device_id => where its next turn starts
            $result['senders'] = count($turns);
            $page = max(100, min(5000, intdiv(self::SYSLOG_SCAN, max(1, count($turns)))));
            $share = max(50, intdiv(self::SYSLOG_MATCHES, max(1, count($turns))));
            while ($turns && $result['scanned'] < self::SYSLOG_SCAN && count($result['rows']) < self::SYSLOG_MATCHES) {
                $deviceId = (int) array_key_first($turns);
                $rows = $this->syslogPage($deviceId, $from, $turns[$deviceId], $page + 1);
                unset($turns[$deviceId]);
                $more = count($rows) > $page;
                $rows = array_slice($rows, 0, $page);
                $kept = 0;
                foreach ($rows as $i => $row) {
                    $last = $row;
                    $result['scanned']++;
                    $code = $classify(trim($row->program . ' ' . $row->msg));
                    if ($code !== null) {
                        $result['rows'][] = ['code' => $code] + (array) $row;
                        if (++$kept >= $share) {
                            $more = $more || $i < count($rows) - 1;
                            break;
                        }
                    }
                }
                if ($more) {
                    $turns[$deviceId] = [(string) $last->timestamp, (int) $last->seq]; // more in the window: another turn
                }
            }
            $result['truncated'] = $turns && count($result['rows']) >= self::SYSLOG_MATCHES;
            $result['limited'] = count($turns);
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read syslog: ' . $e->getMessage());
            $result['rows'] = [];
        }

        return $result;
    }

    /**
     * Port names of these devices, to recognize the ports named in their messages (deleted ports left
     * out, so a renumbered port is found under its current id).
     *
     * @param  int[]  $deviceIds
     * @return array<int, array> port_id => row (device_id, ifIndex, ifName, ifDescr)
     */
    public function portNames(array $deviceIds): array
    {
        $result = [];
        $deviceIds = array_values(array_filter(array_unique(array_map('intval', $deviceIds)), fn ($id) => $this->canSee($id)));
        try {
            foreach (array_chunk($deviceIds, 500) as $chunk) {
                foreach (DB::table('ports')->whereIntegerInRaw('device_id', $chunk)->where('deleted', 0)
                    ->get(['port_id', 'device_id', 'ifIndex', 'ifName', 'ifDescr']) as $row) {
                    $result[(int) $row->port_id] = (array) $row;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read port names: ' . $e->getMessage());
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

    /**
     * Devices among $deviceIds that sent syslog since $from, from the newest message of each (one index
     * lookup per device rather than a scan of the window).
     *
     * @param  int[]  $deviceIds
     * @return int[]
     */
    private function syslogSenders(array $deviceIds, string $from): array
    {
        $senders = [];
        foreach (array_chunk($deviceIds, 1000) as $chunk) {
            foreach (DB::table('syslog')->whereIntegerInRaw('device_id', $chunk)->groupBy('device_id')
                ->havingRaw('MAX(timestamp) >= ?', [$from])->pluck('device_id') as $deviceId) {
                $senders[] = (int) $deviceId;
            }
        }
        sort($senders);

        return $senders;
    }

    /**
     * Up to $limit messages of a device since $from, newest first, older than the last message of the
     * previous page.
     *
     * @param  array{0: string, 1: int}|null  $after  timestamp and seq of the last message read
     * @return object[]
     */
    private function syslogPage(int $deviceId, string $from, ?array $after, int $limit): array
    {
        $query = DB::table('syslog')->where('device_id', $deviceId)->where('timestamp', '>=', $from);
        if ($after) {
            $query->where(fn ($q) => $q->where('timestamp', '<', $after[0])
                ->orWhere(fn ($q) => $q->where('timestamp', $after[0])->where('seq', '<', $after[1])));
        }
        $query->orderByDesc('timestamp')->orderByDesc('seq')->limit($limit)->select(['seq', 'device_id', 'timestamp', 'program', 'msg']);

        if ($this->indexHint) {
            try {
                return (clone $query)->forceIndex(self::SYSLOG_INDEX)->get()->all();
            } catch (\Throwable $e) {
                Log::debug('topology plugin: reading syslog without the index hint: ' . $e->getMessage());
                $this->indexHint = false;
            }
        }

        return $query->get()->all();
    }
}
