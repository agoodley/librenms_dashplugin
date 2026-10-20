<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Stp\SyslogTurns;
use Illuminate\Database\Query\Builder;
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
     * code, read in turns (SyslogTurns: the most recently active devices first, a budget of messages
     * and matches shared by the devices). Matching here rather than with the database's REGEXP is
     * several times faster on large syslog tables and the same on MySQL and MariaDB.
     *
     * @param  int[]  $deviceIds
     * @param  callable(string): ?string  $classify  check code of a message (program and text), null when it is no evidence
     * @return array{rows: array<int, array>, senders: int, scanned: int, reads: int, truncated: bool, limited: int, unsearched: int}
     *     senders: devices that sent syslog in the window; the rest as SyslogTurns::read()
     */
    public function syslog(array $deviceIds, int $since, callable $classify): array
    {
        $none = ['rows' => [], 'senders' => 0, 'scanned' => 0, 'reads' => 0, 'truncated' => false, 'limited' => 0, 'unsearched' => 0];
        $deviceIds = array_values(array_filter(array_unique(array_map('intval', $deviceIds)), fn ($id) => $this->canSee($id)));
        if (! $deviceIds || ! $this->canViewSyslog()) {
            return $none;
        }

        try {
            $from = date('Y-m-d H:i:s', $since);
            $senders = $this->syslogSenders($deviceIds, $from);

            return ['senders' => count($senders)] + (new SyslogTurns)->read($senders, fn (array $turns) => $this->syslogPages($turns, $from), $classify);
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read syslog: ' . $e->getMessage());

            return $none;
        }
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
     * Devices among $deviceIds that sent syslog since $from, the most recently active first, from the
     * newest message of each (one index lookup per device rather than a scan of the window).
     *
     * @param  int[]  $deviceIds
     * @return int[]
     */
    private function syslogSenders(array $deviceIds, string $from): array
    {
        $newest = [];
        foreach (array_chunk($deviceIds, 1000) as $chunk) {
            foreach (DB::table('syslog')->whereIntegerInRaw('device_id', $chunk)->groupBy('device_id')->havingRaw('MAX(timestamp) >= ?', [$from])
                ->select(['device_id', DB::raw('MAX(timestamp) AS newest')])->get() as $row) {
                $newest[(int) $row->device_id] = (string) $row->newest;
            }
        }
        uksort($newest, fn ($a, $b) => [$newest[$b], $a] <=> [$newest[$a], $b]);

        return array_keys($newest);
    }

    /**
     * The next messages of several devices since $from, newest first: one query, a part per device.
     *
     * @param  array<int, array{after: array{0: string, 1: int}|null, limit: int}>  $turns  device_id => the last message
     *     read before (timestamp and seq) and how many to read
     * @return array<int, object[]> device_id => messages
     */
    private function syslogPages(array $turns, string $from): array
    {
        if ($this->indexHint) {
            try {
                return $this->pages($this->pagesQuery($turns, $from, true)->get());
            } catch (\Throwable $e) {
                Log::debug('topology plugin: reading syslog without the index hint: ' . $e->getMessage());
                $this->indexHint = false;
            }
        }

        return $this->pages($this->pagesQuery($turns, $from, false)->get());
    }

    /**
     * The union of one part per device: its messages since $from older than the last one read, newest first.
     */
    private function pagesQuery(array $turns, string $from, bool $hint): Builder
    {
        $query = null;
        foreach ($turns as $deviceId => $turn) {
            $part = DB::table('syslog')->where('device_id', $deviceId)->where('timestamp', '>=', $from);
            if ($turn['after']) {
                [$time, $seq] = $turn['after'];
                $part->where(fn ($q) => $q->where('timestamp', '<', $time)->orWhere(fn ($q) => $q->where('timestamp', $time)->where('seq', '<', $seq)));
            }
            $part->orderByDesc('timestamp')->orderByDesc('seq')->limit($turn['limit'])->select(['seq', 'device_id', 'timestamp', 'program', 'msg']);
            if ($hint) {
                $part->forceIndex(self::SYSLOG_INDEX);
            }
            $query = $query ? $query->unionAll($part) : $part;
        }

        return $query;
    }

    /**
     * Rows of a union by device, newest first (the parts of a union keep their order in practice, not by definition).
     *
     * @return array<int, object[]>
     */
    private function pages(iterable $rows): array
    {
        $pages = [];
        foreach ($rows as $row) {
            $pages[(int) $row->device_id][] = $row;
        }
        foreach ($pages as $deviceId => $messages) {
            usort($messages, fn ($a, $b) => [(string) $b->timestamp, (int) $b->seq] <=> [(string) $a->timestamp, (int) $a->seq]);
            $pages[$deviceId] = $messages;
        }

        return $pages;
    }
}
