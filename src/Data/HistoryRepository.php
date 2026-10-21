<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\History\Baseline;
use Agoodley\LibrenmsTopology\History\FactCollector;
use Agoodley\LibrenmsTopology\History\FindingHistory;
use Agoodley\LibrenmsTopology\History\HistoryService;
use Agoodley\LibrenmsTopology\History\Runs;
use Agoodley\LibrenmsTopology\History\Window;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Database access of the topology history: the topology_* tables (facts, baseline, runs and the
 * findings history) and the sources of facts the maps do not load (STP roots and blocked ports,
 * OSPF router ids, addresses of routing neighbors). Reads for a page are limited to facts that
 * involve devices the user may see; the scheduled job uses the system scope.
 */
class HistoryRepository extends ScopedRepository
{
    private const CHUNK = 500;

    /** Facts that started, and facts that ended, read for one window of the Changes page (newest first). */
    public const WINDOW_LIMIT = 5000;

    /** Snapshots a cut window of the Changes page counts one by one (newest first), the earlier ones together. */
    public const DROPPED_LINES = 10;

    /** Findings that came or went shown for one window of the Changes page (newest first). */
    public const WINDOW_FINDINGS = 1000;

    /** A baseline is compared with the last snapshot only while that is this recent (seconds). */
    private const SNAPSHOT_MAX_AGE = 86400;

    /** @var bool|null whether the history tables exist (migrations run) */
    private static ?bool $available = null;

    /** @var bool|null whether topology_findings has the vlan and subjects columns (the second migration ran) */
    private static ?bool $subjectColumns = null;

    /**
     * Have the plugin's migrations been run? Everything else returns nothing until they have.
     */
    public function available(): bool
    {
        if (self::$available === null) {
            try {
                self::$available = Schema::hasTable('topology_facts') && Schema::hasTable('topology_findings');
            } catch (\Throwable $e) {
                Log::debug('topology plugin: cannot check the history tables: ' . $e->getMessage());
                self::$available = false;
            }
        }

        return self::$available;
    }

    /**
     * Display names of devices (callers pass devices they already scoped).
     *
     * @param  int[]  $ids
     * @return array<int, string>
     */
    public function names(array $ids): array
    {
        $names = [];
        foreach (array_chunk(array_values(array_unique($ids)), 5000) as $chunk) {
            $names += $this->deviceNames($chunk);
        }

        return $names;
    }

    // ---------------------------------------------------------------- fact sources

    /**
     * Other names neighbors may report a device by (sysName, hostname), for FactCollector.
     *
     * @param  int[]  $ids
     * @return array<int, string[]>
     */
    public function hostNames(array $ids): array
    {
        $names = [];
        foreach ($this->rows('devices', $ids, ['device_id', 'sysName', 'hostname']) as $row) {
            $names[(int) $row['device_id']] = array_values(array_filter([(string) $row['sysName'], (string) $row['hostname']]));
        }

        return $names;
    }

    /**
     * stp rows (device_id, vlan, designatedRoot, bridgeAddress), one at a time: PVST has one per
     * switch and VLAN.
     *
     * @param  int[]  $ids
     * @return \Generator<int, array>
     */
    public function stpRoots(array $ids): \Generator
    {
        return $this->stream('stp', $ids, fn (Builder $q) => $q->select(['device_id', 'vlan', 'designatedRoot', 'bridgeAddress']));
    }

    /**
     * Ports that spanning tree blocks, one row at a time (ports_stp grows with ports x VLANs, so only
     * the blocking rows).
     *
     * @param  int[]  $ids
     * @return \Generator<int, array>
     */
    public function blockedPorts(array $ids): \Generator
    {
        return $this->stream('ports_stp', $ids, fn (Builder $q) => $q->whereIn('state', StpIndex::BLOCKING_STATES)->select(['device_id', 'port_id', 'vlan', 'state']));
    }

    /**
     * Rows of a per-device table, one at a time; none when the table does not exist on this
     * LibreNMS version, while a read error is thrown (the snapshot then skips that kind of fact
     * rather than take it for gone).
     *
     * @param  int[]  $ids
     * @param  callable(Builder): Builder  $query
     * @return \Generator<int, array>
     */
    private function stream(string $table, array $ids, callable $query): \Generator
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($query(DB::table($table)->whereIntegerInRaw('device_id', $chunk))->cursor() as $row) {
                yield (array) $row;
            }
        }
    }

    /**
     * All rows of a per-device table, none when the table does not exist; a read error is thrown
     * (see stream()).
     *
     * @param  int[]  $ids
     * @param  string[]  $columns
     * @return array<int, array>
     */
    private function strictRows(string $table, array $ids, array $columns): array
    {
        if (! $ids || ! Schema::hasTable($table)) {
            return [];
        }

        $rows = [];
        foreach (array_chunk($ids, 5000) as $chunk) {
            foreach (DB::table($table)->whereIntegerInRaw('device_id', $chunk)->get($columns) as $row) {
                $rows[] = (array) $row;
            }
        }

        return $rows;
    }

    /**
     * @param  int[]  $ids
     */
    public function ospfNeighbors(array $ids): array
    {
        return $this->strictRows('ospf_nbrs', $ids, ['device_id', 'port_id', 'ospfNbrIpAddr', 'ospfNbrRtrId', 'ospfNbrState', 'context_name']);
    }

    /**
     * Devices by OSPF router ID; a router ID of several devices (a duplicate) names none of them.
     *
     * @param  int[]  $ids
     * @return array<string, int> OSPF router id => device_id
     */
    public function routerIds(array $ids): array
    {
        $owners = [];
        foreach ($this->strictRows('ospf_instances', $ids, ['device_id', 'ospfRouterId']) as $row) {
            if (! empty($row['ospfRouterId'])) {
                $owners[(string) $row['ospfRouterId']][(int) $row['device_id']] = true;
            }
        }

        return self::onlyOwners($owners);
    }

    /**
     * @param  int[]  $ids
     */
    public function bgpPeers(array $ids): array
    {
        return $this->strictRows('bgpPeers', $ids, ['device_id', 'bgpPeerIdentifier', 'bgpPeerRemoteAs', 'bgpPeerState', 'bgpPeerDescr', 'context_name', 'vrf_id']);
    }

    /**
     * Devices that own the given IP addresses (interface addresses of the devices in $ids); an
     * address on several devices (shared gateway, reused in VRFs, a duplicate) names none of them.
     *
     * @param  string[]  $addresses
     * @param  int[]  $ids
     * @return array<string, int> address => device_id
     */
    public function addressOwners(array $addresses, array $ids): array
    {
        $v4 = [];
        $v6 = [];
        foreach (array_unique(array_filter($addresses)) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $v4[] = $address;
            } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $v6[] = $address;
            }
        }

        $owners = [];
        $wanted = array_flip($ids);
        if ($this->allowsTable('ipv4_addresses') && Schema::hasTable('ipv4_addresses')) {
            foreach (array_chunk($v4, 1000) as $chunk) {
                foreach (DB::table('ipv4_addresses')->join('ports', 'ports.port_id', '=', 'ipv4_addresses.port_id')
                    ->whereIn('ipv4_addresses.ipv4_address', $chunk)->get(['ipv4_addresses.ipv4_address as address', 'ports.device_id']) as $row) {
                    if (isset($wanted[(int) $row->device_id])) {
                        $owners[(string) $row->address][(int) $row->device_id] = true;
                    }
                }
            }
        }
        if ($this->allowsTable('ipv6_addresses') && Schema::hasTable('ipv6_addresses')) {
            foreach (array_chunk($v6, 1000) as $chunk) {
                foreach (DB::table('ipv6_addresses')->join('ports', 'ports.port_id', '=', 'ipv6_addresses.port_id')
                    ->where(fn (Builder $q) => $q->whereIn('ipv6_addresses.ipv6_compressed', $chunk)->orWhereIn('ipv6_addresses.ipv6_address', $chunk))
                    ->get(['ipv6_addresses.ipv6_compressed', 'ipv6_addresses.ipv6_address', 'ports.device_id']) as $row) {
                    if (isset($wanted[(int) $row->device_id])) {
                        $owners[strtolower((string) $row->ipv6_compressed)][(int) $row->device_id] = true;
                        $owners[strtolower((string) $row->ipv6_address)][(int) $row->device_id] = true;
                    }
                }
            }
        }

        return self::onlyOwners($owners);
    }

    /**
     * The single owner of each key, leaving out keys of several owners.
     *
     * @param  array<string, array<int, true>>  $owners
     * @return array<string, int>
     */
    private static function onlyOwners(array $owners): array
    {
        return array_map(fn (array $devices) => (int) array_key_first($devices), array_filter($owners, fn (array $devices) => count($devices) === 1));
    }

    // ---------------------------------------------------------------- facts

    /**
     * Facts of a kind present at the last snapshot, oldest first.
     *
     * @return array<int, array{id: int, hash: string, data_hash: string}>
     */
    public function openFacts(string $kind): array
    {
        return DB::table('topology_facts')->where('kind', $kind)->whereNull('removed_at')->orderBy('id')->get(['id', 'hash', 'data_hash'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'hash' => $r->hash, 'data_hash' => $r->data_hash])->all();
    }

    /**
     * @param  array<string, array>  $facts  hash => fact
     * @param  bool  $initial  facts of a kind recorded for the first time (they existed before the history)
     */
    public function insertFacts(array $facts, CarbonInterface $time, bool $initial): int
    {
        $rows = [];
        foreach ($facts as $fact) {
            $rows[] = [
                'kind' => $fact['kind'],
                'hash' => $fact['hash'],
                'fact_key' => $fact['key'],
                'node' => isset($fact['data']['b']['node']) ? mb_substr((string) $fact['data']['b']['node'], 0, 191) : null,
                'device_a' => $fact['device_a'],
                'device_b' => $fact['device_b'],
                'data' => FactCollector::json($fact['data']),
                'data_hash' => $fact['data_hash'],
                'initial' => $initial,
                'first_seen' => $time,
                'removed_at' => null,
            ];
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('topology_facts')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * @param  int[]  $ids
     */
    public function closeFacts(array $ids, CarbonInterface $time): int
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            DB::table('topology_facts')->whereIntegerInRaw('id', $chunk)->update(['removed_at' => $time]);
        }

        return count($ids);
    }

    /**
     * Forget facts the history no longer records (links to phones, FactCollector::links()): all
     * their periods and their baseline entries, as if they had never been recorded, so no change is
     * reported and the baseline does not miss them.
     *
     * @param  int[]  $ids  their open periods
     */
    public function dropFacts(array $ids): int
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $hashes = DB::table('topology_facts')->whereIntegerInRaw('id', $chunk)->pluck('hash')->all();
            $this->transaction(function () use ($hashes): void {
                DB::table('topology_facts')->whereIn('hash', $hashes)->delete();
                DB::table('topology_baseline')->whereIn('hash', $hashes)->delete();
            });
        }

        return count($ids);
    }

    /**
     * New details (names, platform) of facts that are still there.
     *
     * @param  array<int, array>  $facts  id => fact
     */
    public function updateFacts(array $facts): int
    {
        foreach ($facts as $id => $fact) {
            DB::table('topology_facts')->where('id', $id)->update([
                'data' => FactCollector::json($fact['data']),
                'data_hash' => $fact['data_hash'],
                'device_a' => $fact['device_a'],
                'device_b' => $fact['device_b'],
            ]);
        }

        return count($facts);
    }

    /**
     * Forget what ended before $cutoff (facts, findings, runs; the latest finished run of each type
     * stays, it carries what the history recorded so far).
     *
     * @return int rows deleted
     */
    public function prune(CarbonInterface $cutoff): int
    {
        $deleted = DB::table('topology_facts')->whereNotNull('removed_at')->where('removed_at', '<', $cutoff)->delete();
        $deleted += DB::table('topology_findings')->whereNotNull('resolved_at')->where('resolved_at', '<', $cutoff)->delete();
        foreach (DB::table('topology_runs')->whereNotNull('finished_at')->select('type', DB::raw('max(id) as id'))->groupBy('type')->get() as $latest) {
            $deleted += DB::table('topology_runs')->where('type', $latest->type)->where('id', '<', $latest->id)->where('started_at', '<', $cutoff)->delete();
        }

        return $deleted;
    }

    /**
     * Facts of the devices that started (not with the first snapshot of their kind) or ended in
     * [$from, $to], with their data decoded: the newest WINDOW_LIMIT of each, cut at a common
     * boundary when there were more (Window::merge()). The snapshots the cut leaves out are
     * counted per kind of fact (`dropped`, Window::dropped()). Rows are decoded as they are read,
     * not held twice.
     *
     * @param  int[]  $ids
     * @param  string[]|null  $kinds  only these kinds of facts (a map reads its own), null: all
     * @return array{rows: array<int, array>, truncated: bool, since: ?int, dropped: ?array}
     */
    public function windowFacts(CarbonInterface $from, CarbonInterface $to, array $ids, ?array $kinds = null): array
    {
        $facts = fn () => $kinds === null ? $this->touching(DB::table('topology_facts'), $ids) : $this->touching(DB::table('topology_facts'), $ids)->whereIn('kind', $kinds);
        $started = fn (CarbonInterface $until) => $facts()->whereBetween('first_seen', [$from, $until])->where('initial', 0);
        $ended = fn (CarbonInterface $until) => $facts()->whereBetween('removed_at', [$from, $until]);
        $read = fn (Builder $query, string $column) => $query->orderByDesc($column)->orderByDesc('id')->limit(self::WINDOW_LIMIT)->cursor()
            ->map(fn ($row) => self::factRow((array) $row))->all();
        $window = Window::merge($read($started($to), 'first_seen'), $read($ended($to), 'removed_at'), self::WINDOW_LIMIT);
        $window['dropped'] = null;
        if ($window['since'] !== null) {
            $until = Carbon::createFromTimestamp($window['since'], $from->getTimezone());
            // per snapshot and kind for the newest snapshots left out (a snapshot has a row per kind at most), per kind for all
            $perSnapshot = fn (Builder $query, string $column) => $query->groupBy($column, 'kind')->orderByDesc($column)
                ->limit((self::DROPPED_LINES + 1) * count(FactCollector::KINDS))->get([$column . ' as time', 'kind', DB::raw('count(*) as facts')])
                ->map(fn ($row) => ['time' => (int) strtotime((string) $row->time), 'kind' => (string) $row->kind, 'facts' => (int) $row->facts])->all();
            $perKind = fn (Builder $query) => array_map('intval', $query->groupBy('kind')->select('kind', DB::raw('count(*) as facts'))->pluck('facts', 'kind')->all());
            $totals = $perKind($started($until));
            foreach ($perKind($ended($until)) as $kind => $facts) {
                $totals[$kind] = ($totals[$kind] ?? 0) + $facts;
            }
            $window['dropped'] = Window::dropped(array_merge($perSnapshot($started($until), 'first_seen'), $perSnapshot($ended($until), 'removed_at')),
                $totals, self::DROPPED_LINES);
        }

        return $window;
    }

    /**
     * Facts present at the last snapshot that involve the devices, per kind.
     *
     * @param  int[]  $ids
     * @param  string[]  $kinds
     * @return array<string, int> kind => facts
     */
    public function currentKinds(array $ids, array $kinds): array
    {
        return array_map('intval', $this->touching(DB::table('topology_facts'), $ids)->whereNull('removed_at')->whereIn('kind', $kinds)
            ->groupBy('kind')->select('kind', DB::raw('count(*) as facts'))->pluck('facts', 'kind')->all());
    }

    /**
     * Identities of the facts present at the last snapshot that involve the devices.
     *
     * @param  int[]  $ids
     * @param  string[]  $kinds
     * @return array<string, string> hash => kind
     */
    public function currentHashes(array $ids, array $kinds): array
    {
        return $this->touching(DB::table('topology_facts'), $ids)->whereNull('removed_at')->whereIn('kind', $kinds)->pluck('kind', 'hash')->all();
    }

    /**
     * Facts present at the last snapshot, by identity, decoded (at most $limit).
     *
     * @param  string[]  $hashes
     * @return array<int, array>
     */
    public function currentFactsByHash(array $hashes, int $limit): array
    {
        $facts = [];
        foreach (array_chunk(array_slice(array_values($hashes), 0, $limit), self::CHUNK) as $chunk) {
            foreach (DB::table('topology_facts')->whereIn('hash', $chunk)->whereNull('removed_at')->get() as $row) {
                $facts[] = self::factRow((array) $row);
            }
        }

        return $facts;
    }

    /**
     * Unmanaged neighbors (node ids) that were seen before $before.
     *
     * @param  string[]  $nodes
     * @return array<string, true>
     */
    public function priorNodes(array $nodes, CarbonInterface $before): array
    {
        $seen = [];
        foreach (array_chunk(array_values(array_unique($nodes)), self::CHUNK) as $chunk) {
            foreach (DB::table('topology_facts')->whereIn('node', $chunk)->where('first_seen', '<', $before)->distinct()->pluck('node') as $node) {
                $seen[(string) $node] = true;
            }
        }

        return $seen;
    }

    /**
     * Devices of $ids added to LibreNMS since $from.
     *
     * @param  int[]  $ids
     * @return array<int, true>
     */
    public function devicesAddedSince(array $ids, CarbonInterface $from): array
    {
        $added = [];
        foreach (array_chunk($ids, 5000) as $chunk) {
            foreach (DB::table('devices')->whereIntegerInRaw('device_id', $chunk)->where('inserted', '>=', $from)->pluck('device_id') as $id) {
                $added[(int) $id] = true;
            }
        }

        return $added;
    }

    /**
     * Devices the history saw for the first time at the given time (no fact of them before).
     *
     * @param  array<int, int>  $firstSeen  device_id => timestamp of its first fact in a window
     * @return array<int, true>
     */
    public function newToHistory(array $firstSeen): array
    {
        $earliest = []; // device => its earliest fact
        foreach (array_chunk(array_keys($firstSeen), self::CHUNK) as $chunk) {
            foreach (['device_a', 'device_b'] as $column) {
                foreach (DB::table('topology_facts')->whereIntegerInRaw($column, $chunk)->groupBy($column)
                    ->select($column . ' as device', DB::raw('min(first_seen) as first'))->get() as $row) {
                    $earliest[(int) $row->device] = min($earliest[(int) $row->device] ?? PHP_INT_MAX, (int) strtotime((string) $row->first));
                }
            }
        }

        $new = [];
        foreach ($firstSeen as $deviceId => $time) {
            if (($earliest[$deviceId] ?? PHP_INT_MAX) >= $time) {
                $new[(int) $deviceId] = true;
            }
        }

        return $new;
    }

    /**
     * Since when facts are gone: the end of their latest period, the first snapshot that no longer
     * saw them.
     *
     * @param  string[]  $hashes
     * @return array<string, string> hash => date
     */
    public function goneSince(array $hashes): array
    {
        $result = [];
        foreach (array_chunk($hashes, self::CHUNK) as $chunk) {
            foreach (DB::table('topology_facts')->whereIn('hash', $chunk)->whereNotNull('removed_at')
                ->groupBy('hash')->select('hash', DB::raw('max(removed_at) as seen'))->get() as $row) {
                $result[$row->hash] = (string) $row->seen;
            }
        }

        return $result;
    }

    // ---------------------------------------------------------------- maps at a point in time

    /**
     * The last finished snapshot at or before $time, which a map as of $time shows (every fact starts and ends at a
     * snapshot), with the start of the next one; null before the first. started_at is the time as stored, for
     * factsAt(); recorded the kinds of facts recorded by then.
     *
     * @return array{time: int, started_at: string, next: ?int, recorded: string[], skipped: string[]}|null
     */
    public function snapshotAt(int $time): ?array
    {
        $runs = fn () => DB::table('topology_runs')->where('type', 'snapshot')->whereNotNull('finished_at');
        $row = $runs()->where('started_at', '<=', Carbon::createFromTimestamp($time, date_default_timezone_get()))
            ->orderByDesc('started_at')->orderByDesc('id')->first(['started_at', 'stats']);
        if ($row === null) {
            return null;
        }
        $next = $runs()->where('started_at', '>', $row->started_at)->min('started_at');
        $stats = json_decode((string) $row->stats, true) ?: [];

        return [
            'time' => (int) strtotime((string) $row->started_at),
            'started_at' => (string) $row->started_at,
            'next' => $next !== null ? (int) strtotime((string) $next) : null,
            'recorded' => Runs::recorded($stats),
            'skipped' => array_values(array_map('strval', is_array($stats['skipped'] ?? null) ? $stats['skipped'] : [])),
        ];
    }

    /**
     * The finished snapshots: the earliest still kept (the retention prunes older runs with their facts, so a map
     * of an earlier time would miss facts), the latest, how many, and the starts of the $recent latest, newest first.
     *
     * @return array{earliest: ?int, latest: ?int, count: int, recent: int[]}
     */
    public function snapshotTimes(int $recent): array
    {
        $runs = fn () => DB::table('topology_runs')->where('type', 'snapshot')->whereNotNull('finished_at');
        $bounds = $runs()->selectRaw('min(started_at) as earliest, max(started_at) as latest, count(*) as runs')->first();
        $times = $runs()->orderByDesc('started_at')->limit($recent)->pluck('started_at')->all();

        return [
            'earliest' => $bounds && $bounds->earliest !== null ? (int) strtotime((string) $bounds->earliest) : null,
            'latest' => $bounds && $bounds->latest !== null ? (int) strtotime((string) $bounds->latest) : null,
            'count' => (int) ($bounds->runs ?? 0),
            'recent' => array_map(fn ($time) => (int) strtotime((string) $time), $times),
        ];
    }

    /**
     * Facts of these kinds present at a snapshot ($at: its started_at as snapshotAt() returns it) that involve the
     * devices (device_a or device_b; with $reporting only device_a, the device whose data the fact comes from), with
     * their data decoded: a chunk at a time in id order, so the links of a whole network are streamed rather than
     * held as rows. A fact is present from its first snapshot (first_seen, included) until the snapshot that no
     * longer saw it (removed_at, excluded).
     *
     * @param  int[]  $ids
     * @param  string[]  $kinds
     * @return \Generator<int, array>
     */
    public function factsAt(string $at, array $ids, array $kinds, bool $reporting = false): \Generator
    {
        if (! $ids || ! $kinds) {
            return;
        }
        $last = 0;
        do {
            $query = DB::table('topology_facts')->whereIn('kind', $kinds)->where('first_seen', '<=', $at)
                ->where(fn (Builder $q) => $q->whereNull('removed_at')->orWhere('removed_at', '>', $at))
                ->where('id', '>', $last)->orderBy('id')->limit(self::CHUNK * 4);
            $rows = ($reporting ? $query->whereIntegerInRaw('device_a', $ids) : $this->touching($query, $ids))->get();
            foreach ($rows as $row) {
                $last = (int) $row->id;
                yield self::factRow((array) $row);
            }
        } while (count($rows) === self::CHUNK * 4);
    }

    /**
     * What the devices were at $time, for a map of the past: down when an outage covered that time (device_outages,
     * when the user's role may read it), new when LibreNMS added them later, else up; unknown without the outages.
     *
     * @param  int[]  $ids
     * @return array<int, string> device_id => up, down, new or unknown
     */
    public function deviceStatesAt(array $ids, int $time): array
    {
        $states = [];
        $outages = $this->allowsTable('device_outages') && Schema::hasTable('device_outages');
        $at = Carbon::createFromTimestamp($time, date_default_timezone_get());
        foreach (array_chunk(array_values(array_unique($ids)), 5000) as $chunk) {
            foreach (DB::table('devices')->whereIntegerInRaw('device_id', $chunk)->get(['device_id', 'inserted']) as $row) {
                $states[(int) $row->device_id] = $row->inserted !== null && $row->inserted > $at->toDateTimeString() ? 'new' : ($outages ? 'up' : 'unknown');
            }
            if ($outages) {
                foreach (DB::table('device_outages')->whereIntegerInRaw('device_id', $chunk)->where('going_down', '<=', $time)
                    ->where(fn (Builder $q) => $q->whereNull('up_again')->orWhere('up_again', '>', $time))->distinct()->pluck('device_id') as $id) {
                    if (($states[(int) $id] ?? null) === 'up') {
                        $states[(int) $id] = 'down';
                    }
                }
            }
        }

        return $states;
    }

    /**
     * Every device LibreNMS has: a fact's device that is not one of them was deleted since.
     *
     * @return array<int, true>
     */
    public function existingDevices(): array
    {
        return array_fill_keys(array_map('intval', DB::table('devices')->pluck('device_id')->all()), true);
    }

    // ---------------------------------------------------------------- baseline

    /**
     * Identities of the baseline facts that involve the devices.
     *
     * @param  int[]  $ids
     * @return array<string, string> hash => kind
     */
    public function baselineHashes(array $ids): array
    {
        return $this->touching(DB::table('topology_baseline'), $ids)->pluck('kind', 'hash')->all();
    }

    /**
     * Baseline facts of one kind that involve the devices, decoded; with $lock read for update
     * (inside transaction()), so a concurrent change of them waits for this one.
     *
     * @param  int[]  $ids
     * @return array<string, array> hash => fact
     */
    public function baselineFactsOfKind(array $ids, string $kind, bool $lock = false): array
    {
        $query = $this->touching(DB::table('topology_baseline'), $ids)->where('kind', $kind);
        if ($lock) {
            $query->lockForUpdate();
        }
        $facts = [];
        foreach ($query->get() as $row) {
            $fact = self::baselineRow((array) $row);
            $facts[$fact['hash']] = $fact;
        }

        return $facts;
    }

    /**
     * Facts of one kind present at the last snapshot that involve the devices, decoded.
     *
     * @param  int[]  $ids
     * @return array<string, array> hash => fact
     */
    public function currentFactsOfKind(array $ids, string $kind): array
    {
        $facts = [];
        foreach ($this->touching(DB::table('topology_facts'), $ids)->where('kind', $kind)->whereNull('removed_at')->get() as $row) {
            $fact = self::factRow((array) $row);
            $facts[$fact['hash']] = $fact;
        }

        return $facts;
    }

    /**
     * Baseline facts by identity, decoded (at most $limit).
     *
     * @param  string[]  $hashes
     * @return array<int, array>
     */
    public function baselineFactsByHash(array $hashes, int $limit): array
    {
        $facts = [];
        foreach (array_chunk(array_slice(array_values($hashes), 0, $limit), self::CHUNK) as $chunk) {
            foreach (DB::table('topology_baseline')->whereIn('hash', $chunk)->get() as $row) {
                $facts[] = self::baselineRow((array) $row);
            }
        }

        return $facts;
    }

    /**
     * When and by whom the baseline of the devices was saved (latest save), null without one.
     *
     * @param  int[]  $ids
     * @return array{saved_at: string, saved_by: ?string, facts: int}|null
     */
    public function baselineInfo(array $ids): ?array
    {
        $row = $this->touching(DB::table('topology_baseline'), $ids)
            ->selectRaw('count(*) as facts, max(saved_at) as saved_at')->first();
        if (! $row || ! $row->facts) {
            return null;
        }
        $by = DB::table('topology_baseline')->where('saved_at', $row->saved_at)->value('saved_by');

        return ['saved_at' => (string) $row->saved_at, 'saved_by' => $by, 'facts' => (int) $row->facts];
    }

    /**
     * Replace the baseline of the devices with the facts of the last snapshot that involve them
     * (copied as stored, a chunk at a time).
     *
     * @param  int[]  $ids
     * @return array{removed: int, saved: int}
     */
    public function replaceBaseline(array $ids, CarbonInterface $time, ?string $user): array
    {
        return DB::transaction(function () use ($ids, $time, $user) {
            $result = ['removed' => $this->clearBaseline($ids), 'saved' => 0];
            $this->touching(DB::table('topology_facts'), $ids)->whereNull('removed_at')->whereIn('kind', Baseline::KINDS)
                ->select(['id', 'kind', 'hash', 'fact_key', 'device_a', 'device_b', 'data'])
                ->chunkById(self::CHUNK, function ($facts) use (&$result, $time, $user): void {
                    $rows = [];
                    foreach ($facts as $fact) {
                        $rows[$fact->hash] = ['kind' => $fact->kind, 'hash' => $fact->hash, 'fact_key' => $fact->fact_key, 'device_a' => $fact->device_a,
                            'device_b' => $fact->device_b, 'data' => $fact->data, 'saved_at' => $time, 'saved_by' => $user !== null ? mb_substr($user, 0, 128) : null];
                    }
                    // a fact can involve devices on both sides of the scope: it replaces the saved copy
                    $result['removed'] += DB::table('topology_baseline')->whereIn('hash', array_keys($rows))->delete();
                    DB::table('topology_baseline')->insert(array_values($rows));
                    $result['saved'] += count($rows);
                });

            return $result;
        });
    }

    /**
     * Write an accepted change of root bridges (Baseline::acceptRootChange()) to the baseline.
     *
     * @param  array{update: array<string, array>, delete: string[], insert: array<string, array>}  $ops
     * @return int baseline facts written
     */
    public function applyBaselineChange(array $ops, CarbonInterface $time, ?string $user): int
    {
        $user = $user !== null ? mb_substr($user, 0, 128) : null;

        return DB::transaction(function () use ($ops, $time, $user) {
            $written = 0;
            foreach ($ops['update'] as $hash => $data) {
                $written += DB::table('topology_baseline')->where('hash', $hash)->update(['data' => FactCollector::json($data), 'saved_at' => $time, 'saved_by' => $user]);
            }
            foreach (array_chunk($ops['delete'], self::CHUNK) as $chunk) {
                $written += DB::table('topology_baseline')->whereIn('hash', $chunk)->delete();
            }
            $rows = [];
            foreach ($ops['insert'] as $hash => $fact) {
                $rows[] = ['kind' => $fact['kind'], 'hash' => $hash, 'fact_key' => $fact['key'] ?? $fact['fact_key'], 'device_a' => $fact['device_a'],
                    'device_b' => $fact['device_b'], 'data' => FactCollector::json($fact['data']), 'saved_at' => $time, 'saved_by' => $user];
            }
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                DB::table('topology_baseline')->insert($chunk);
            }

            return $written + count($rows);
        });
    }

    /**
     * Take one fact out of the baseline (an admin accepted that it is gone); of a root bridge fact
     * only the VLANs given, the fact goes with its last VLAN.
     *
     * @param  int[]  $ids  devices of the page's scope; the fact must involve one of them
     * @param  int[]|null  $vlans
     */
    public function forgetBaselineFact(string $hash, array $ids, ?array $vlans = null): int
    {
        return $this->transaction(function () use ($hash, $ids, $vlans) {
            $query = $this->touching(DB::table('topology_baseline'), $ids)->where('hash', $hash);
            // read for update: another admin taking other VLANs out of the same fact at once waits for this one
            $row = $vlans !== null ? (clone $query)->lockForUpdate()->first() : null;
            if ($row !== null) {
                $data = json_decode((string) $row->data, true) ?: [];
                $left = array_values(array_diff(array_map('intval', $data['vlans'] ?? []), array_map('intval', $vlans)));
                if ($left) {
                    return DB::table('topology_baseline')->where('id', $row->id)->update(['data' => FactCollector::json(['vlans' => $left] + $data)]);
                }
            }

            return $query->delete();
        });
    }

    /**
     * Run $callback in one transaction, again when it ran into a deadlock (at most three times).
     */
    public function transaction(callable $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    /**
     * Forget the baseline of the devices.
     *
     * @param  int[]  $ids
     */
    public function clearBaseline(array $ids): int
    {
        $deleted = 0;
        foreach (array_chunk($ids, 5000) as $chunk) {
            $deleted += DB::table('topology_baseline')->where(fn (Builder $q) => $q->whereIntegerInRaw('device_a', $chunk)->orWhereIntegerInRaw('device_b', $chunk))->delete();
        }

        return $deleted;
    }

    /**
     * Baseline links that the last snapshot no longer found, as links rows for the redundancy
     * analysis (see Baseline::linkRows()): one query, cheap enough for every simulation. Nothing
     * without a baseline, before the migrations ran, or when the last snapshot is older than a day
     * (the history is switched off or the scheduler does not run).
     *
     * @param  int[]  $ids  devices of the analysis
     * @return array<int, array>
     */
    public function missingBaselineLinks(array $ids): array
    {
        if (! $ids || ! $this->available()) {
            return [];
        }

        try {
            $snapshot = $this->latestRun('snapshot');
            if ($snapshot === null || time() - (int) strtotime($snapshot['started_at']) > self::SNAPSHOT_MAX_AGE) {
                return [];
            }
            $missing = [];
            foreach ($this->touching(DB::table('topology_baseline as b'), $ids, 'b.')->where('b.kind', FactCollector::LINK)
                ->whereNotExists(fn (Builder $q) => $q->from('topology_facts as f')->whereColumn('f.hash', 'b.hash')->whereNull('f.removed_at'))
                ->select('b.*')->get() as $row) {
                $fact = self::baselineRow((array) $row);
                $missing[$fact['hash']] = $fact;
            }
            if (! $missing) {
                return [];
            }

            // ends that have another cable now (Baseline::goneLinks())
            $devices = array_unique(array_filter(array_merge(array_column($missing, 'device_a'), array_column($missing, 'device_b'))));
            $current = $this->touching(DB::table('topology_facts'), $devices)->where('kind', FactCollector::LINK)->whereNull('removed_at')
                ->pluck('fact_key', 'hash')->map(fn ($key) => ['fact_key' => $key])->all();

            return Baseline::linkRows(Baseline::goneLinks($missing, $current), array_flip($ids), $this->goneSince(array_keys($missing)));
        } catch (\Throwable $e) {
            Log::error('topology plugin: cannot compare the links with the baseline: ' . $e->getMessage());

            return [];
        }
    }

    // ---------------------------------------------------------------- runs

    public function startRun(string $type, CarbonInterface $time): int
    {
        return (int) DB::table('topology_runs')->insertGetId(['type' => $type, 'started_at' => $time]);
    }

    public function finishRun(int $id, array $stats, CarbonInterface $time): void
    {
        DB::table('topology_runs')->where('id', $id)->update(['finished_at' => $time, 'stats' => json_encode($stats)]);
    }

    /**
     * The latest finished run of a type, null before the first one.
     *
     * @return array{started_at: string, finished_at: string, stats: array}|null
     */
    public function latestRun(string $type): ?array
    {
        $row = DB::table('topology_runs')->where('type', $type)->whereNotNull('finished_at')->orderByDesc('id')->first();

        return $row ? ['started_at' => (string) $row->started_at, 'finished_at' => (string) $row->finished_at, 'stats' => json_decode((string) $row->stats, true) ?: []] : null;
    }

    /**
     * The latest finished run of each type, and as 'checks' the latest one of the background checks.
     *
     * @return array<string, array{started_at: string, finished_at: string, stats: array}>
     */
    public function latestRuns(): array
    {
        $runs = [];
        foreach (array_merge(['snapshot'], HistoryService::ANALYSES) as $type) {
            if (($run = $this->latestRun($type)) !== null) {
                $runs[$type] = $run;
            }
        }
        foreach (HistoryService::ANALYSES as $analysis) {
            if (isset($runs[$analysis]) && $runs[$analysis]['started_at'] > ($runs['checks']['started_at'] ?? '')) {
                $runs['checks'] = $runs[$analysis];
            }
        }

        return $runs;
    }

    // ---------------------------------------------------------------- findings history

    /**
     * Open findings of an analysis (not resolved), oldest first; with their VLAN and the devices
     * their title named when recorded (null when unknown: recorded before that was kept).
     *
     * @return array<int, array{id: int, hash: string, code: string, severity: string, title: string, devices: int[],
     *     vlan: ?int, subjects: ?int[], confirmed: bool, missed_at: ?string, first_seen: string}>
     */
    public function openFindings(string $analysis): array
    {
        $columns = ['id', 'hash', 'code', 'severity', 'title', 'devices', 'confirmed', 'missed_at', 'first_seen'];

        return DB::table('topology_findings')->where('analysis', $analysis)->whereNull('resolved_at')->orderBy('id')
            ->get($this->subjectColumns() ? array_merge($columns, ['vlan', 'subjects']) : $columns)
            ->map(fn ($r) => ['id' => (int) $r->id, 'hash' => $r->hash, 'code' => $r->code, 'severity' => $r->severity, 'title' => $r->title,
                'devices' => array_map('intval', json_decode((string) $r->devices, true) ?: []),
                'vlan' => isset($r->vlan) ? (int) $r->vlan : null,
                'subjects' => isset($r->subjects) && is_array($subjects = json_decode((string) $r->subjects, true)) ? array_map('intval', $subjects) : null,
                'confirmed' => (bool) $r->confirmed, 'missed_at' => $r->missed_at !== null ? (string) $r->missed_at : null, 'first_seen' => (string) $r->first_seen])
            ->all();
    }

    /**
     * Has topology_findings the vlan and subjects columns? Until the plugin's second migration ran
     * they are left out (a finding's subjects then come from its title).
     */
    private function subjectColumns(): bool
    {
        if (self::$subjectColumns === null) {
            try {
                self::$subjectColumns = Schema::hasColumn('topology_findings', 'vlan') && Schema::hasColumn('topology_findings', 'subjects');
            } catch (\Throwable $e) {
                Log::debug('topology plugin: cannot check the findings columns: ' . $e->getMessage());
                self::$subjectColumns = false;
            }
        }

        return self::$subjectColumns;
    }

    /**
     * Write one run of an analysis (FindingHistory::run()).
     */
    public function applyFindings(string $analysis, array $run, CarbonInterface $time, bool $initial): void
    {
        $rows = [];
        $confirmed = array_fill_keys($run['confirmed'], true);
        $subjects = $this->subjectColumns();
        foreach ($run['insert'] as $hash => $finding) {
            $rows[] = [
                'analysis' => $analysis,
                'code' => mb_substr((string) $finding['code'], 0, 64),
                'hash' => $hash,
                'severity' => $finding['severity'],
                'title' => FindingHistory::title($finding),
                'devices' => json_encode(array_values(array_map('intval', $finding['devices'] ?? []))),
                'initial' => $initial,
                'confirmed' => isset($confirmed[$hash]),
                'first_seen' => $time,
                'missed_at' => null,
                'resolved_at' => null,
            ] + ($subjects ? ['vlan' => $finding['vlan'] ?? null, 'subjects' => json_encode($finding['subjects'] ?? [])] : []);
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('topology_findings')->insert($chunk);
        }

        $byTime = [];
        foreach ($run['resolve'] as $id => $resolvedAt) {
            $byTime[$resolvedAt][] = (int) $id;
        }
        foreach ($byTime as $resolvedAt => $ids) {
            $this->updateFindingRows($ids, ['resolved_at' => $resolvedAt]);
        }
        $this->updateFindingRows($run['miss'], ['missed_at' => $time]);
        $this->updateFindingRows($run['unmiss'], ['missed_at' => null]);
        $this->updateFindingRows($run['confirm'], ['confirmed' => true]);
        foreach ($run['update'] as $id => $finding) {
            DB::table('topology_findings')->where('id', $id)->update(['severity' => $finding['severity'], 'title' => FindingHistory::title($finding)]
                + ($subjects ? ['subjects' => json_encode($finding['subjects'] ?? [])] : []));
        }
    }

    /**
     * Open history rows of findings, by identity.
     *
     * @param  string[]  $hashes
     * @return array<string, array{first_seen: string, initial: bool}>
     */
    public function findingsByHash(array $hashes): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique($hashes)), self::CHUNK) as $chunk) {
            foreach (DB::table('topology_findings')->whereIn('hash', $chunk)->whereNull('resolved_at')->get(['hash', 'first_seen', 'initial']) as $row) {
                $result[$row->hash] = ['first_seen' => (string) $row->first_seen, 'initial' => (bool) $row->initial];
            }
        }

        return $result;
    }

    /**
     * Confirmed findings that started or were resolved in [$from, $to], involve the devices and only devices
     * the user may see (their titles name devices: the diagnostics pages show the user's own); the
     * newest WINDOW_FINDINGS of them, read page by page so a restricted view is not left empty.
     * Checks in $hidden (analysis => codes) are left out.
     *
     * @param  int[]  $ids
     * @param  array<string, string[]>  $hidden
     * @return array{rows: array<int, array>, truncated: bool}
     */
    public function windowFindings(CarbonInterface $from, CarbonInterface $to, array $ids, array $hidden = []): array
    {
        $hidden = array_map('array_flip', $hidden);
        $wanted = array_flip($ids);
        $visible = $this->accessible();
        $rows = [];
        $before = null;
        do {
            // confirmed findings only (reported by two runs in a row): one that comes and goes every run is no news
            $query = DB::table('topology_findings')->where('confirmed', 1)
                ->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereBetween('first_seen', [$from, $to])->where('initial', 0))
                    ->orWhereBetween('resolved_at', [$from, $to]))
                ->orderByDesc('id')->limit(2000);
            if ($before !== null) {
                $query->where('id', '<', $before);
            }
            $page = $query->get();
            foreach ($page as $row) {
                $before = (int) $row->id;
                $devices = array_map('intval', json_decode((string) $row->devices, true) ?: []);
                if (isset($hidden[$row->analysis][$row->code]) || ! array_filter($devices, fn ($id) => isset($wanted[$id]))
                    || array_filter($devices, fn ($id) => ! isset($visible[$id]))) {
                    continue;
                }
                if (count($rows) === self::WINDOW_FINDINGS) {
                    return ['rows' => $rows, 'truncated' => true];
                }
                $rows[] = ['analysis' => $row->analysis, 'code' => $row->code, 'severity' => $row->severity, 'title' => $row->title,
                    'devices' => array_values(array_filter($devices, fn ($id) => isset($wanted[$id]))), 'initial' => (bool) $row->initial,
                    'first_seen' => (string) $row->first_seen, 'resolved_at' => $row->resolved_at !== null ? (string) $row->resolved_at : null];
            }
        } while (count($page) === 2000);

        return ['rows' => $rows, 'truncated' => false];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Rows of a fact table that involve at least one of the devices.
     *
     * @param  int[]  $ids
     */
    private function touching(Builder $query, array $ids, string $prefix = ''): Builder
    {
        if (! $ids) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q->whereIntegerInRaw($prefix . 'device_a', $ids)->orWhereIntegerInRaw($prefix . 'device_b', $ids));
    }

    /**
     * @param  int[]  $ids
     * @param  array<string, mixed>  $values
     */
    private function updateFindingRows(array $ids, array $values): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            DB::table('topology_findings')->whereIntegerInRaw('id', $chunk)->update($values);
        }
    }

    /**
     * A stored baseline fact with typed columns and decoded data.
     */
    private static function baselineRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'kind' => $row['kind'],
            'hash' => $row['hash'],
            'key' => $row['fact_key'],
            'fact_key' => $row['fact_key'],
            'device_a' => $row['device_a'] !== null ? (int) $row['device_a'] : null,
            'device_b' => $row['device_b'] !== null ? (int) $row['device_b'] : null,
            'data' => json_decode((string) $row['data'], true) ?: [],
            'saved_at' => (string) $row['saved_at'],
            'saved_by' => $row['saved_by'],
        ];
    }

    /**
     * A stored fact with typed columns and decoded data.
     */
    private static function factRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'kind' => $row['kind'],
            'hash' => $row['hash'],
            'key' => $row['fact_key'],
            'fact_key' => $row['fact_key'],
            'device_a' => $row['device_a'] !== null ? (int) $row['device_a'] : null,
            'device_b' => $row['device_b'] !== null ? (int) $row['device_b'] : null,
            'data' => json_decode((string) $row['data'], true) ?: [],
            'data_hash' => $row['data_hash'],
            'initial' => (bool) $row['initial'],
            'first_seen' => strtotime((string) $row['first_seen']),
            'removed_at' => $row['removed_at'] !== null ? strtotime((string) $row['removed_at']) : null,
        ];
    }
}
