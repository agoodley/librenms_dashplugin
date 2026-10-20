<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\History\Baseline;
use Agoodley\LibrenmsTopology\History\FactCollector;
use Agoodley\LibrenmsTopology\History\FindingHistory;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
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

    /** @var bool|null whether the history tables exist (migrations run) */
    private static ?bool $available = null;

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
     * @param  int[]  $ids
     * @return array<int, array> stp rows (device_id, vlan, designatedRoot, bridgeAddress)
     */
    public function stpRoots(array $ids): array
    {
        return $this->rows('stp', $ids, ['device_id', 'vlan', 'designatedRoot', 'bridgeAddress']);
    }

    /**
     * Ports that spanning tree blocks (ports_stp grows with ports x VLANs, so only the blocking rows).
     *
     * @param  int[]  $ids
     * @return array<int, array>
     */
    public function blockedPorts(array $ids): array
    {
        $rows = [];
        try {
            foreach (array_chunk($ids, 5000) as $chunk) {
                foreach (DB::table('ports_stp')->whereIntegerInRaw('device_id', $chunk)->whereIn('state', StpIndex::BLOCKING_STATES)
                    ->get(['device_id', 'port_id', 'vlan', 'state']) as $row) {
                    $rows[] = (array) $row;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read ports_stp: ' . $e->getMessage());
        }

        return $rows;
    }

    /**
     * @param  int[]  $ids
     */
    public function ospfNeighbors(array $ids): array
    {
        return $this->rows('ospf_nbrs', $ids, ['device_id', 'port_id', 'ospfNbrIpAddr', 'ospfNbrRtrId', 'ospfNbrState', 'context_name']);
    }

    /**
     * @param  int[]  $ids
     * @return array<string, int> OSPF router id => device_id
     */
    public function routerIds(array $ids): array
    {
        $result = [];
        foreach ($this->rows('ospf_instances', $ids, ['device_id', 'ospfRouterId']) as $row) {
            if (! empty($row['ospfRouterId'])) {
                $result[(string) $row['ospfRouterId']] = (int) $row['device_id'];
            }
        }

        return $result;
    }

    /**
     * @param  int[]  $ids
     */
    public function bgpPeers(array $ids): array
    {
        return $this->rows('bgpPeers', $ids, ['device_id', 'bgpPeerIdentifier', 'bgpPeerRemoteAs', 'bgpPeerState', 'bgpPeerDescr', 'context_name']);
    }

    /**
     * Devices that own the given IP addresses (interface addresses of the devices in $ids).
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
        try {
            foreach (array_chunk($v4, 1000) as $chunk) {
                foreach (DB::table('ipv4_addresses')->join('ports', 'ports.port_id', '=', 'ipv4_addresses.port_id')
                    ->whereIn('ipv4_addresses.ipv4_address', $chunk)->get(['ipv4_addresses.ipv4_address as address', 'ports.device_id']) as $row) {
                    if (isset($wanted[(int) $row->device_id])) {
                        $owners[(string) $row->address] = (int) $row->device_id;
                    }
                }
            }
            foreach (array_chunk($v6, 1000) as $chunk) {
                foreach (DB::table('ipv6_addresses')->join('ports', 'ports.port_id', '=', 'ipv6_addresses.port_id')
                    ->where(fn (Builder $q) => $q->whereIn('ipv6_addresses.ipv6_compressed', $chunk)->orWhereIn('ipv6_addresses.ipv6_address', $chunk))
                    ->get(['ipv6_addresses.ipv6_compressed', 'ipv6_addresses.ipv6_address', 'ports.device_id']) as $row) {
                    if (isset($wanted[(int) $row->device_id])) {
                        $owners[strtolower((string) $row->ipv6_compressed)] = (int) $row->device_id;
                        $owners[strtolower((string) $row->ipv6_address)] = (int) $row->device_id;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read interface addresses: ' . $e->getMessage());
        }

        return $owners;
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
     * Kinds of facts the history already holds (a kind seen for the first time starts as "initial").
     *
     * @return array<string, true>
     */
    public function factKinds(): array
    {
        return array_fill_keys(DB::table('topology_facts')->distinct()->pluck('kind')->all(), true);
    }

    /**
     * @param  array<string, array>  $facts  hash => fact
     * @param  array<string, true>  $knownKinds  kinds already in the history; facts of other kinds are initial
     */
    public function insertFacts(array $facts, CarbonInterface $time, array $knownKinds): int
    {
        $rows = [];
        foreach ($facts as $fact) {
            $rows[] = [
                'kind' => $fact['kind'],
                'hash' => $fact['hash'],
                'fact_key' => $fact['key'],
                'device_a' => $fact['device_a'],
                'device_b' => $fact['device_b'],
                'data' => FactCollector::json($fact['data']),
                'data_hash' => $fact['data_hash'],
                'initial' => ! isset($knownKinds[$fact['kind']]),
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
     * Forget what ended before $cutoff (facts, findings, runs; the latest run of each type stays).
     *
     * @return int rows deleted
     */
    public function prune(CarbonInterface $cutoff): int
    {
        $deleted = DB::table('topology_facts')->whereNotNull('removed_at')->where('removed_at', '<', $cutoff)->delete();
        $deleted += DB::table('topology_findings')->whereNotNull('resolved_at')->where('resolved_at', '<', $cutoff)->delete();
        foreach (DB::table('topology_runs')->select('type', DB::raw('max(id) as id'))->groupBy('type')->get() as $latest) {
            $deleted += DB::table('topology_runs')->where('type', $latest->type)->where('id', '<', $latest->id)->where('started_at', '<', $cutoff)->delete();
        }

        return $deleted;
    }

    /**
     * Facts of the devices that started or ended in [$from, $to], with their data decoded.
     *
     * @param  int[]  $ids
     * @return array<int, array>
     */
    public function windowFacts(CarbonInterface $from, CarbonInterface $to, array $ids): array
    {
        $rows = [];
        foreach ($this->touching(DB::table('topology_facts'), $ids)
            ->where(fn (Builder $q) => $q->whereBetween('first_seen', [$from, $to])->orWhereBetween('removed_at', [$from, $to]))
            ->orderBy('id')->limit(20000)->get() as $row) {
            $rows[] = self::factRow((array) $row);
        }

        return $rows;
    }

    /**
     * Facts present at the last snapshot that involve the devices.
     *
     * @param  int[]  $ids
     * @param  string[]|null  $kinds
     * @return array<string, array> hash => fact
     */
    public function currentFacts(array $ids, ?array $kinds = null): array
    {
        $query = $this->touching(DB::table('topology_facts'), $ids)->whereNull('removed_at');
        if ($kinds !== null) {
            $query->whereIn('kind', $kinds);
        }

        $facts = [];
        foreach ($query->get() as $row) {
            $row = self::factRow((array) $row);
            $facts[$row['hash']] = $row;
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
        $wanted = array_fill_keys($nodes, true);
        if (! $wanted) {
            return [];
        }

        // an unmanaged end is the second of a link key ("d5:gi1/0/1|u:ap-12:eth0"), its node id the part
        // before one of its colons (node ids can contain colons themselves)
        $seen = [];
        foreach (DB::table('topology_facts')->where('kind', FactCollector::LINK)->where('first_seen', '<', $before)
            ->where('fact_key', 'like', '%|u:%')->select('fact_key')->cursor() as $row) {
            $end = substr((string) $row->fact_key, strpos((string) $row->fact_key, '|') + 1);
            for ($colon = strpos($end, ':', 2); $colon !== false; $colon = strpos($end, ':', $colon + 1)) {
                if (isset($wanted[substr($end, 0, $colon)])) {
                    $seen[substr($end, 0, $colon)] = true;
                }
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
        $new = [];
        foreach (array_slice($firstSeen, 0, 500, true) as $deviceId => $time) {
            $before = DB::table('topology_facts')->where('first_seen', '<', date('Y-m-d H:i:s', $time))
                ->where(fn (Builder $q) => $q->where('device_a', $deviceId)->orWhere('device_b', $deviceId))->exists();
            if (! $before) {
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

    // ---------------------------------------------------------------- baseline

    /**
     * Baseline facts that involve the devices.
     *
     * @param  int[]  $ids
     * @param  string[]|null  $kinds
     * @return array<string, array> hash => fact
     */
    public function baselineFacts(array $ids, ?array $kinds = null): array
    {
        $query = $this->touching(DB::table('topology_baseline'), $ids);
        if ($kinds !== null) {
            $query->whereIn('kind', $kinds);
        }

        $facts = [];
        foreach ($query->get() as $row) {
            $row = (array) $row;
            $facts[$row['hash']] = [
                'id' => (int) $row['id'],
                'kind' => $row['kind'],
                'hash' => $row['hash'],
                'key' => $row['fact_key'],
                'fact_key' => $row['fact_key'],
                'device_a' => $row['device_a'] !== null ? (int) $row['device_a'] : null,
                'device_b' => $row['device_b'] !== null ? (int) $row['device_b'] : null,
                'data' => json_decode($row['data'], true) ?: [],
                'saved_at' => $row['saved_at'],
                'saved_by' => $row['saved_by'],
            ];
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
     * Replace the baseline of the devices with $facts (facts that involve them).
     *
     * @param  int[]  $ids
     * @param  array<string, array>  $facts  hash => fact
     * @return array{removed: int, saved: int}
     */
    public function replaceBaseline(array $ids, array $facts, CarbonInterface $time, ?string $user): array
    {
        return DB::transaction(function () use ($ids, $facts, $time, $user) {
            $removed = $this->clearBaseline($ids);
            $rows = [];
            foreach ($facts as $fact) {
                if (! in_array($fact['kind'], Baseline::KINDS, true)) {
                    continue;
                }
                $rows[] = [
                    'kind' => $fact['kind'],
                    'hash' => $fact['hash'],
                    'fact_key' => $fact['key'] ?? $fact['fact_key'],
                    'device_a' => $fact['device_a'],
                    'device_b' => $fact['device_b'],
                    'data' => FactCollector::json($fact['data']),
                    'saved_at' => $time,
                    'saved_by' => $user !== null ? mb_substr($user, 0, 128) : null,
                ];
            }
            // a fact can involve devices on both sides of the scope: it replaces the saved copy
            foreach (array_chunk(array_column($rows, 'hash'), self::CHUNK) as $chunk) {
                $removed += DB::table('topology_baseline')->whereIn('hash', $chunk)->delete();
            }
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                DB::table('topology_baseline')->insert($chunk);
            }

            return ['removed' => $removed, 'saved' => count($rows)];
        });
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
     * Baseline links that LLDP/CDP does not report now, as links rows for the redundancy analysis
     * (see Baseline::linkRows()). Nothing without a baseline or before the migrations ran.
     *
     * @param  array<int, array>  $links  links rows now (TopologyRepository::links())
     * @param  array<int, array>  $ports  port_id => row (ifName, ifDescr) of their ports
     * @param  int[]  $ids  devices of the analysis
     * @return array<int, array>
     */
    public function missingBaselineLinks(array $links, array $ports, array $ids): array
    {
        if (! $ids || ! $this->available()) {
            return [];
        }

        try {
            $baseline = $this->baselineFacts($ids, [FactCollector::LINK]);
            if (! $baseline) {
                return [];
            }
            $current = (new FactCollector(array_fill_keys($ids, ''), $ports))->links($links);
            $missing = Baseline::goneLinks($baseline, $current);

            return $missing ? Baseline::linkRows($missing, array_flip($ids), $this->goneSince(array_keys($missing))) : [];
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
     * The latest finished run of each type.
     *
     * @return array<string, array{started_at: string, finished_at: string, stats: array}>
     */
    public function latestRuns(): array
    {
        $runs = [];
        foreach (DB::table('topology_runs')->whereNotNull('finished_at')->orderByDesc('id')->limit(50)->get() as $row) {
            $runs[$row->type] ??= ['started_at' => (string) $row->started_at, 'finished_at' => (string) $row->finished_at, 'stats' => json_decode((string) $row->stats, true) ?: []];
        }

        return $runs;
    }

    // ---------------------------------------------------------------- findings history

    /**
     * Findings of an analysis reported by the previous run, oldest first.
     *
     * @return array<int, array{id: int, hash: string, code: string, severity: string, title: string, devices: int[]}>
     */
    public function openFindings(string $analysis): array
    {
        return DB::table('topology_findings')->where('analysis', $analysis)->whereNull('resolved_at')->orderBy('id')
            ->get(['id', 'hash', 'code', 'severity', 'title', 'devices'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'hash' => $r->hash, 'code' => $r->code, 'severity' => $r->severity, 'title' => $r->title,
                'devices' => array_map('intval', json_decode((string) $r->devices, true) ?: [])])
            ->all();
    }

    /**
     * Has the analysis run in the background before (its first run records findings as initial)?
     */
    public function hasFindings(string $analysis): bool
    {
        return DB::table('topology_findings')->where('analysis', $analysis)->exists()
            || DB::table('topology_runs')->where('type', 'checks')->whereNotNull('finished_at')->where('stats', 'like', '%"' . $analysis . '"%')->exists();
    }

    /**
     * @param  array<string, array>  $findings  hash => finding
     */
    public function insertFindings(string $analysis, array $findings, CarbonInterface $time, bool $initial): void
    {
        $rows = [];
        foreach ($findings as $hash => $finding) {
            $rows[] = [
                'analysis' => $analysis,
                'code' => mb_substr((string) $finding['code'], 0, 64),
                'hash' => $hash,
                'severity' => $finding['severity'],
                'title' => FindingHistory::title($finding),
                'devices' => json_encode(array_values(array_map('intval', $finding['devices'] ?? []))),
                'initial' => $initial,
                'first_seen' => $time,
                'resolved_at' => null,
            ];
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('topology_findings')->insert($chunk);
        }
    }

    /**
     * @param  int[]  $ids
     */
    public function resolveFindings(array $ids, CarbonInterface $time): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            DB::table('topology_findings')->whereIntegerInRaw('id', $chunk)->update(['resolved_at' => $time]);
        }
    }

    /**
     * @param  array<int, array>  $findings  id => finding with a new severity or wording
     */
    public function updateFindings(array $findings): void
    {
        foreach ($findings as $id => $finding) {
            DB::table('topology_findings')->where('id', $id)->update(['severity' => $finding['severity'], 'title' => FindingHistory::title($finding)]);
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
     * Findings that started or were resolved in [$from, $to], involve the devices and only devices
     * the user may see (their titles name devices: the diagnostics pages show the user's own).
     *
     * @param  int[]  $ids
     * @return array<int, array>
     */
    public function windowFindings(CarbonInterface $from, CarbonInterface $to, array $ids): array
    {
        $wanted = array_flip($ids);
        $visible = $this->accessible();
        $rows = [];
        foreach (DB::table('topology_findings')
            ->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereBetween('first_seen', [$from, $to])->where('initial', 0))
                ->orWhereBetween('resolved_at', [$from, $to]))
            ->orderByDesc('id')->limit(5000)->get() as $row) {
            $devices = array_map('intval', json_decode((string) $row->devices, true) ?: []);
            if (! array_filter($devices, fn ($id) => isset($wanted[$id])) || array_filter($devices, fn ($id) => ! isset($visible[$id]))) {
                continue;
            }
            $rows[] = ['analysis' => $row->analysis, 'code' => $row->code, 'severity' => $row->severity, 'title' => $row->title,
                'devices' => array_values(array_filter($devices, fn ($id) => isset($wanted[$id]))), 'initial' => (bool) $row->initial,
                'first_seen' => (string) $row->first_seen, 'resolved_at' => $row->resolved_at !== null ? (string) $row->resolved_at : null];
        }

        return $rows;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Rows of a fact table that involve at least one of the devices.
     *
     * @param  int[]  $ids
     */
    private function touching(Builder $query, array $ids): Builder
    {
        if (! $ids) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q->whereIntegerInRaw('device_a', $ids)->orWhereIntegerInRaw('device_b', $ids));
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
