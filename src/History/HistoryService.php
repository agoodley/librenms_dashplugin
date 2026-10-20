<?php

namespace Agoodley\LibrenmsTopology\History;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Topology\TopologyService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Topology history: the scheduled snapshot of the topology facts and the background checks
 * (console, system scope), and what the Changes page and the baseline show (user scope).
 */
class HistoryService
{
    /** Analyses the background checks can run (TopologyService methods returning findings). */
    public const ANALYSES = ['stp', 'links', 'redundancy'];

    /** Port columns the facts need (interface names). */
    private const PORT_COLUMNS = ['port_id', 'device_id', 'ifName', 'ifDescr'];

    /** Missing and new baseline facts listed on the Changes page (the counts are complete). */
    private const LISTED = 200;

    public function __construct(
        private readonly HistoryRepository $history,
        private readonly TopologyRepository $topology,
        private readonly PluginSettings $settings,
    ) {
    }

    /**
     * Record the topology as it is now: insert new facts, close the facts that are gone, update
     * changed details; nothing is written for facts that did not change. Then forget what ended
     * before the retention period.
     *
     * @return array<string, int|float> counts for the command output and the run record
     */
    public function snapshot(): array
    {
        $started = Carbon::now();
        $previous = $this->history->latestRun('snapshot');
        $run = $this->history->startRun('snapshot', $started);

        // disabled devices too: LibreNMS keeps their data as it was, and so does the history
        $topology = $this->topology->withDisabled();
        $ids = $topology->deviceIds();
        $stats = ['devices' => count($ids), 'facts' => 0, 'added' => 0, 'removed' => 0, 'updated' => 0];
        $recorded = [];
        foreach ($this->sources($topology, $ids) as $kind => $source) {
            try {
                $current = $source();
            } catch (\Throwable $e) {
                // a read error is no empty kind: its facts stay as they were until the next snapshot
                Log::error("topology plugin: snapshot of $kind skipped: " . $e->getMessage());
                $stats['skipped'][] = $kind;
                continue;
            }
            // spanning tree facts keep their VLANs in the data: a change of them is a new period the Changes page can compare
            $diff = FactDiff::diff($this->history->openFacts($kind), $current, in_array($kind, [FactCollector::STP_ROOT, FactCollector::STP_BLOCKED], true));
            $stats['facts'] += count($current);
            // what the first snapshot of a kind finds existed before the history: no change
            $stats['added'] += $this->history->insertFacts($diff['insert'], $started, Runs::initial($previous['stats'] ?? null, $kind));
            $stats['removed'] += $this->history->closeFacts($diff['remove'], $started);
            $stats['updated'] += $this->history->updateFacts($diff['update']);
            $recorded[] = $kind;
        }
        $stats['pruned'] = $this->history->prune($started->copy()->subDays($this->settings->int('history_retention_days', 1, 3650)));
        $stats['seconds'] = round(Carbon::now()->diffInMilliseconds($started, true) / 1000, 2);
        $stats['recorded'] = Runs::record($previous['stats'] ?? null, $recorded);
        $this->history->finishRun($run, $stats, Carbon::now());

        return $stats;
    }

    /**
     * The background checks the settings enable (of $only, when given).
     *
     * @param  string[]|null  $only
     * @return string[]
     */
    public function enabledChecks(?array $only = null): array
    {
        $enabled = array_values(array_intersect(self::ANALYSES, array_map('strtolower', $this->settings->list('history_checks'))));

        return $only === null ? $enabled : array_values(array_intersect($enabled, array_map('strtolower', $only)));
    }

    /**
     * Run one background check and record its findings history; a device gets an eventlog entry
     * when it gets its first confirmed finding of the check or loses its last one (see
     * FindingHistory), from the eventlog severity setting on and except the skipped checks. Each
     * analysis keeps its own runs (type = analysis).
     *
     * @param  callable(string, int|null, string, string): void|null  $eventlog  writer (message, device_id, severity,
     *                                                                           reference); default: LibreNMS eventlog
     * @return array<string, int> counts, or ['error' => 1]
     */
    public function check(string $analysis, ?callable $eventlog = null): array
    {
        $started = Carbon::now();
        $previous = $this->history->latestRun($analysis);
        $run = $this->history->startRun($analysis, $started);
        $params = MapParams::parse([], $this->settings);
        try {
            $service = new TopologyService($this->topology, $this->settings, $this->history);
            // the spanning tree check goes one VLAN at a time (a PVST network has an instance per VLAN)
            $findings = ($analysis === 'stp' ? $service->stpFindings($params) : $service->$analysis($params))['findings'] ?? [];
        } catch (\Throwable $e) {
            Log::error("topology plugin: background $analysis check failed: " . $e->getMessage());
            $this->history->finishRun($run, ['error' => 1, 'recorded' => Runs::record($previous['stats'] ?? null, [])], Carbon::now());

            return ['error' => 1];
        }

        // the first successful run records what is there already: only later changes are news
        $initial = Runs::initial($previous['stats'] ?? null, $analysis);
        $changes = FindingHistory::run($analysis, $this->history->openFindings($analysis), $findings, $started->toDateTimeString(), $initial);
        $this->history->applyFindings($analysis, $changes, $started, $initial);
        $entries = $initial ? [] : FindingHistory::eventlog($analysis, $changes['before'], $changes['after'],
            strtolower($this->settings->all()['history_eventlog_severity'] ?? 'warning'), array_map('strtolower', $this->settings->list('history_eventlog_skip')));
        $eventlog ??= self::eventlog(...);
        foreach ($entries as $entry) {
            $eventlog($entry['message'], $entry['device_id'], $entry['severity'], $entry['reference']);
        }

        $stats = ['findings' => count($findings), 'new' => count($changes['insert']), 'confirmed' => count($changes['confirm']),
            'resolved' => count($changes['resolve']), 'eventlog' => count($entries), 'initial' => (int) $initial];
        $this->history->finishRun($run, $stats + ['seconds' => round(Carbon::now()->diffInMilliseconds($started, true) / 1000, 2),
            'recorded' => Runs::record($previous['stats'] ?? null, [$analysis])], Carbon::now());

        return $stats;
    }

    /**
     * Data of the Changes page: the changes of the window, the baseline comparison and the
     * findings that came or went, for the devices of the page's scope.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     */
    public function changes(array $p, CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $this->history->available()) {
            return ['available' => false];
        }

        [$scope, $device] = $this->history->scope($p);
        $ids = $device ? [$device] : $scope;
        $visible = array_fill_keys($this->history->deviceIds(), true);
        $text = new FactText($this->history->names(array_keys($visible)), $visible);

        $window = $this->history->windowFacts($from, $to, $ids);
        $rows = $window['rows'];
        if ($window['since'] !== null) {
            // too many changes: only those since the boundary of the rows read, with both halves of each
            $from = Carbon::createFromTimestamp(max($from->getTimestamp(), $window['since']), $from->getTimezone());
        }
        $nodes = [];
        $firstFacts = []; // device => when its first new fact of the window was seen
        foreach ($rows as $row) {
            if ($row['initial'] || $row['first_seen'] < $from->getTimestamp()) {
                continue;
            }
            if ($row['kind'] === FactCollector::LINK && isset($row['data']['b']['node'])) {
                $nodes[] = $row['data']['b']['node'];
            }
            foreach (array_filter([$row['device_a'], $row['device_b']]) as $deviceId) {
                $firstFacts[$deviceId] = min($firstFacts[$deviceId] ?? PHP_INT_MAX, $row['first_seen']);
            }
        }
        // devices LibreNMS started to monitor: added recently, and no facts of them before these
        $added = $this->history->devicesAddedSince(array_keys($firstFacts), $from->copy()->subDay());
        $report = ChangeReport::build($rows, $from->getTimestamp(), $to->getTimestamp(), $text,
            $this->history->newToHistory(array_intersect_key($firstFacts, $added)), $this->history->priorNodes($nodes, $from));

        $windowFindings = $this->history->windowFindings($from, $to, $ids);
        $findings = array_map(function (array $finding) use ($text) {
            $finding['device_names'] = array_combine($finding['devices'], array_map(fn ($id) => $text->device($id, null), $finding['devices']));

            return $finding;
        }, FindingHistory::window($windowFindings['rows'], $from->getTimestamp(), $to->getTimestamp()));

        return [
            'available' => true,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'events' => $report['events'],
            'counts' => $report['counts'],
            'truncated' => $window['truncated'],
            'since' => $window['since'] !== null ? $from->toDateTimeString() : null,
            'baseline' => $this->baselineComparison($ids, $text),
            'findings' => $findings,
            'findings_truncated' => $windowFindings['truncated'],
            'runs' => $this->history->latestRuns(),
            'device_names' => $this->history->names(array_unique(array_merge(...array_column($report['events'], 'devices')))),
            'filter' => $p,
        ];
    }

    /**
     * What saving the baseline for the page's scope would do (facts per kind, replaced facts).
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     */
    public function baselinePreview(array $p): array
    {
        $ids = $this->scopeIds($p);
        $kinds = $this->history->currentKinds($ids, Baseline::KINDS);

        return [
            'devices' => count($ids),
            'kinds' => array_intersect_key(array_merge(array_fill_keys(Baseline::KINDS, 0), $kinds), $kinds),
            'facts' => array_sum($kinds),
            'replaces' => $this->history->baselineInfo($ids)['facts'] ?? 0,
            'snapshot' => $this->history->latestRun('snapshot')['started_at'] ?? null,
        ];
    }

    /**
     * Save the topology of the last snapshot as the baseline of the page's scope.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     * @return array{removed: int, saved: int}
     */
    public function saveBaseline(array $p, ?string $user): array
    {
        return $this->history->replaceBaseline($this->scopeIds($p), Carbon::now(), $user);
    }

    /**
     * Forget the baseline of the page's scope.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     */
    public function clearBaseline(array $p): int
    {
        return $this->history->clearBaseline($this->scopeIds($p));
    }

    /**
     * Take one missing fact out of the baseline of the page's scope (gone on purpose); for a root
     * bridge only the VLANs given.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     * @param  int[]|null  $vlans
     */
    public function acceptMissing(array $p, string $hash, ?array $vlans = null): int
    {
        return $this->history->forgetBaselineFact($hash, $this->scopeIds($p), $vlans);
    }

    /**
     * The facts of the devices as they are now, from the LibreNMS tables: one reader per kind, so a
     * large network is read a kind at a time and a kind that cannot be read is skipped alone.
     *
     * @param  int[]  $ids
     * @return array<string, callable(): array<string, array>> kind => reader (hash => fact)
     */
    private function sources(TopologyRepository $topology, array $ids): array
    {
        $names = $this->history->names($ids);
        $hostNames = $this->history->hostNames($ids);
        $collector = fn (array $portIds) => new FactCollector($names, $topology->ports($portIds, self::PORT_COLUMNS), $hostNames);

        return [
            FactCollector::LINK => function () use ($topology, $ids, $collector) {
                $links = $topology->links($ids);

                return $collector(array_merge(array_column($links, 'local_port_id'), array_column($links, 'remote_port_id')))->links($links);
            },
            FactCollector::LAG => function () use ($topology, $ids, $collector) {
                $lag = $topology->lagParents($ids);

                return $collector(array_merge(array_keys($lag), array_values($lag)))->lagMembers($lag);
            },
            FactCollector::STP_ROOT => fn () => $collector([])->stpRoots($this->history->stpRoots($ids)),
            FactCollector::STP_BLOCKED => function () use ($ids, $names, $collector) {
                $blocked = FactCollector::blockingPorts($this->history->blockedPorts($ids), $names);

                return $collector(array_merge([], ...array_map('array_keys', array_values($blocked))))->blockedPortFacts($blocked);
            },
            FactCollector::OSPF => function () use ($ids, $collector) {
                $ospf = $this->history->ospfNeighbors($ids);

                return $collector(array_column($ospf, 'port_id'))->ospf($ospf, $this->history->routerIds($ids),
                    $this->history->addressOwners(array_column($ospf, 'ospfNbrIpAddr'), $ids));
            },
            FactCollector::BGP => function () use ($ids, $collector) {
                $bgp = $this->history->bgpPeers($ids);

                return $collector([])->bgp($bgp, $this->history->addressOwners(array_map('strtolower', array_column($bgp, 'bgpPeerIdentifier')), $ids));
            },
        ];
    }

    /**
     * Baseline against the last snapshot: what is missing and what is new. Compared by identity;
     * only the facts listed are read in full.
     *
     * @param  int[]  $ids
     */
    private function baselineComparison(array $ids, FactText $text): array
    {
        $info = $this->history->baselineInfo($ids);
        if ($info === null) {
            return ['saved' => null, 'missing' => [], 'missing_total' => 0, 'new' => [], 'new_total' => 0];
        }

        // root bridges per switch and VLAN (one fact holds the VLANs of a root bridge), the rest by identity
        $kinds = array_values(array_diff(Baseline::KINDS, [FactCollector::STP_ROOT]));
        $baseline = array_filter($this->history->baselineHashes($ids), fn ($kind) => $kind !== FactCollector::STP_ROOT);
        $current = $this->history->currentHashes($ids, $kinds);
        $missingHashes = array_keys(array_diff_key($baseline, $current));
        $newHashes = array_keys(array_diff_key($current, $baseline));
        $roots = Baseline::compareRoots($this->history->baselineFactsOfKind($ids, FactCollector::STP_ROOT),
            $this->history->currentFactsOfKind($ids, FactCollector::STP_ROOT));

        $describe = fn (array $fact) => [
            'kind' => $fact['kind'],
            'hash' => $fact['hash'],
            'vlans' => $fact['kind'] === FactCollector::STP_ROOT ? FactText::vlans($fact['data']) : null,
            'text' => $text->describe($fact),
            'devices' => array_values(array_filter([$fact['device_a'], $fact['device_b']], fn ($id) => $text->visible($id))),
        ];
        $missingFacts = array_merge(array_slice($roots['missing'], 0, self::LISTED), $this->history->baselineFactsByHash($missingHashes, self::LISTED));
        $since = $this->history->goneSince(array_column($missingFacts, 'hash'));
        $missing = array_map(fn ($fact) => $describe($fact) + ['since' => $since[$fact['hash']] ?? null], $missingFacts);
        $new = array_map($describe, array_merge(array_slice($roots['new'], 0, self::LISTED), $this->history->currentFactsByHash($newHashes, self::LISTED)));
        usort($missing, fn ($a, $b) => [$a['kind'], $a['text']] <=> [$b['kind'], $b['text']]);
        usort($new, fn ($a, $b) => [$a['kind'], $a['text']] <=> [$b['kind'], $b['text']]);

        return ['saved' => $info, 'missing' => array_slice($missing, 0, self::LISTED), 'missing_total' => count($missingHashes) + count($roots['missing']),
            'new' => array_slice($new, 0, self::LISTED), 'new_total' => count($newHashes) + count($roots['new'])];
    }

    /**
     * Devices of the page's scope (the centered device alone when one is chosen).
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     * @return int[]
     */
    private function scopeIds(array $p): array
    {
        [$scope, $device] = $this->history->scope($p);

        return $device ? [$device] : $scope;
    }

    /**
     * Write one entry to the LibreNMS eventlog (type "topology").
     */
    private static function eventlog(string $message, ?int $deviceId, string $severity, string $reference): void
    {
        $level = match ($severity) {
            'critical' => \LibreNMS\Enum\Severity::Error,
            'warning' => \LibreNMS\Enum\Severity::Warning,
            'ok' => \LibreNMS\Enum\Severity::Ok,
            default => \LibreNMS\Enum\Severity::Info,
        };
        \App\Models\Eventlog::log($message, $deviceId, 'topology', $level, $reference);
    }
}
