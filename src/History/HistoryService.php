<?php

namespace Agoodley\LibrenmsTopology\History;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Data\LoopEvidenceRepository;
use Agoodley\LibrenmsTopology\Data\RoutingRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Links\LinkAnalyzer;
use Agoodley\LibrenmsTopology\Redundancy\RedundancyAnalyzer;
use Agoodley\LibrenmsTopology\Routing\RoutingAnalyzer;
use Agoodley\LibrenmsTopology\Routing\RoutingService;
use Agoodley\LibrenmsTopology\Stp\LoopEvidence;
use Agoodley\LibrenmsTopology\Stp\StpAnalyzer;
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
    /** Analyses the background checks can run, in the order they run (findings()). */
    public const ANALYSES = ['stp', 'links', 'redundancy', 'routing'];

    /** Port columns the facts need (interface names). */
    private const PORT_COLUMNS = ['port_id', 'device_id', 'ifName', 'ifDescr'];

    /** Missing and new baseline facts listed on the Changes page (the counts are complete). */
    private const LISTED = 200;

    /**
     * The LibreNMS table each kind of fact comes from: a user whose role may not read it does not see those
     * facts on the Changes page either (port-channel members are ports).
     */
    private const FACT_TABLES = [FactCollector::LINK => 'links', FactCollector::LAG => 'ports', FactCollector::STP_ROOT => 'stp',
        FactCollector::STP_BLOCKED => 'ports_stp', FactCollector::OSPF => 'ospf_nbrs', FactCollector::BGP => 'bgpPeers'];

    /**
     * @param  RoutingRepository|null  $routing  data of the routing check (default: TopologyRepository's scope)
     */
    public function __construct(
        private readonly HistoryRepository $history,
        private readonly TopologyRepository $topology,
        private readonly PluginSettings $settings,
        private readonly ?RoutingRepository $routing = null,
    ) {
    }

    /**
     * Record the topology as it is now: insert new facts, close the facts that are gone, update
     * changed details; nothing is written for facts that did not change. A link recorded before its
     * neighbor was known as a phone is dropped from the history quietly, as if it had never been
     * recorded (its neighbor's role is all that changed). Then forget what ended before the
     * retention period.
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
        $leftOut = [];
        $skipped = function (string $kind, \Throwable $e) use (&$stats): void {
            Log::error("topology plugin: snapshot of $kind skipped: " . $e->getMessage());
            $stats['skipped'][] = $kind;
        };
        foreach (Jobs::read($this->sources($topology, $ids, $leftOut), $skipped) as $kind => $current) {
            // spanning tree facts keep their VLANs in the data: a change of them is a new period the Changes page can compare
            $diff = FactDiff::diff($this->history->openFacts($kind), $current, in_array($kind, [FactCollector::STP_ROOT, FactCollector::STP_BLOCKED], true),
                $leftOut[$kind] ?? []);
            unset($leftOut[$kind]);
            $stats['facts'] += count($current);
            // what the first snapshot of a kind finds existed before the history: no change
            $stats['added'] += $this->history->insertFacts($diff['insert'], $started, Runs::initial($previous['stats'] ?? null, $kind));
            $stats['removed'] += $this->history->closeFacts($diff['remove'], $started);
            $stats['updated'] += $this->history->updateFacts($diff['update']);
            if ($diff['drop']) {
                $stats['dropped'] = ($stats['dropped'] ?? 0) + $this->history->dropFacts($diff['drop']);
            }
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
     * analysis keeps its own runs (type = analysis). A run that cannot read the network or store
     * its findings history (one transaction) leaves the history and the eventlog as they were, and
     * one whose eventlog entries cannot be written stops there: both are recorded as errors.
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
            $findings = $this->findings($analysis, $params);

            // the first successful run records what is there already: only later changes are news
            $initial = Runs::initial($previous['stats'] ?? null, $analysis);
            $open = $this->history->openFindings($analysis);
            $devices = [];
            foreach ([$open, $findings] as $list) {
                foreach ($list as $finding) {
                    foreach ($finding['devices'] ?? [] as $device) {
                        $devices[(int) $device] = true;
                    }
                }
            }
            // the names their titles use tell which devices a finding is about
            $changes = FindingHistory::run($analysis, $open, $findings, $started->toDateTimeString(), $initial, $this->history->names(array_keys($devices)));
            $this->history->transaction(fn () => $this->history->applyFindings($analysis, $changes, $started, $initial));
            $entries = $initial ? [] : FindingHistory::eventlog($analysis, $changes['before'], $changes['after'],
                strtolower($this->settings->all()['history_eventlog_severity'] ?? 'warning'), FindingHistory::skipList($this->settings->all()['history_eventlog_skip'] ?? ''));
            $eventlog ??= self::eventlog(...);
            foreach ($entries as $entry) {
                $eventlog($entry['message'], $entry['device_id'], $entry['severity'], $entry['reference']);
            }
        } catch (\Throwable $e) {
            Log::error("topology plugin: background $analysis check failed: " . $e->getMessage());
            $this->history->finishRun($run, ['error' => 1, 'recorded' => Runs::record($previous['stats'] ?? null, [])], Carbon::now());

            return ['error' => 1];
        }

        $stats = ['findings' => count($findings), 'new' => count($changes['insert']), 'confirmed' => count($changes['confirm']),
            'resolved' => count($changes['resolve']), 'eventlog' => count($entries), 'initial' => (int) $initial];
        $this->history->finishRun($run, $stats + ['seconds' => round(Carbon::now()->diffInMilliseconds($started, true) / 1000, 2),
            'recorded' => Runs::record($previous['stats'] ?? null, [$analysis])], Carbon::now());

        return $stats;
    }

    /**
     * Check codes of the background checks (those the eventlog setting can name).
     *
     * @return string[]
     */
    public static function checkCodes(): array
    {
        return array_keys(StpAnalyzer::CHECKS + LoopEvidence::CHECKS + LinkAnalyzer::CHECKS + RedundancyAnalyzer::CHECKS + RoutingAnalyzer::CHECKS);
    }

    /**
     * Findings of one analysis over all devices (MapParams defaults: no device, group or location):
     * the spanning tree one VLAN at a time (a PVST network has an instance per VLAN), routing & IP
     * from the routing service, the others from the topology service.
     *
     * @return array<int, array>
     */
    private function findings(string $analysis, array $params): array
    {
        if ($analysis === 'routing') {
            // strict: a table that cannot be read fails the run instead of becoming findings
            $routing = ($this->routing ?? $this->topology->sibling(RoutingRepository::class))->strict();

            return (new RoutingService($routing, $this->settings))->analyze($params)['findings'] ?? [];
        }
        $service = new TopologyService($this->topology, $this->settings, $this->history);

        return ($analysis === 'stp' ? $service->stpFindings($params) : $service->$analysis($params))['findings'] ?? [];
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

        $window = $this->window($ids, $from, $to, $text);
        $from = $window['from'];
        $since = $window['since'];
        $report = $window['report'];

        // findings of the syslog checks only for users who may read syslog, as on the spanning tree page
        $hidden = $this->history->sibling(LoopEvidenceRepository::class)->canViewSyslog()
            ? [] : ['stp' => array_keys(array_filter(LoopEvidence::CHECKS, fn ($category) => $category === 'syslog'))];
        $windowFindings = $this->history->windowFindings($from, $to, $ids, $hidden);
        $windowFindings['rows'] = array_values(array_filter($windowFindings['rows'], fn ($row) => $this->findingAllowed($row['analysis'], $row['code'])));
        $findings = array_map(function (array $finding) use ($text) {
            $finding['device_names'] = array_combine($finding['devices'], array_map(fn ($id) => $text->device($id, null), $finding['devices']));

            return $finding;
        }, FindingHistory::window($windowFindings['rows'], $from->getTimestamp(), $to->getTimestamp()));

        return [
            'available' => true,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            // the ids of the rows behind an event are for the maps (ChangeMarks)
            'events' => array_map(fn (array $event) => array_diff_key($event, ['facts' => true]), $report['events']),
            'counts' => $report['counts'],
            'truncated' => $window['truncated'],
            'since' => $since?->toDateTimeString(),
            'dropped' => $window['dropped'],
            'baseline' => $this->baselineComparison($ids, $text),
            'findings' => $findings,
            'findings_truncated' => $windowFindings['truncated'],
            'runs' => $this->history->latestRuns(),
            'device_names' => $this->history->names(array_unique(array_merge(...array_column($report['events'], 'devices')))),
            'filter' => $p,
        ];
    }

    /**
     * The changes of the devices in [$from, $to] as the Changes page tells them (ChangeReport), of the kinds of
     * facts the user's role may read: the facts that started or ended in it, the newest of each up to a common
     * boundary when there are too many (HistoryRepository::windowFacts(); from then starts after it), and the
     * report. A map's changes since a time (MapHistory) read the same.
     *
     * @param  int[]  $ids
     * @return array{rows: array<int, array>, report: array{events: array<int, array>, counts: array<string, int>},
     *     from: CarbonInterface, since: ?CarbonInterface, truncated: bool, dropped: ?array}
     */
    public function window(array $ids, CarbonInterface $from, CarbonInterface $to, FactText $text): array
    {
        $window = $this->history->windowFacts($from, $to, $ids);
        $rows = array_values(array_filter($window['rows'], fn ($row) => $this->factAllowed($row['kind'])));
        $since = null;
        if ($window['since'] !== null) {
            // too many changes: those after the boundary of the rows read are listed, with both halves of each; the
            // snapshots up to it are counted (dropped)
            $since = Carbon::createFromTimestamp($window['since'], $from->getTimezone());
            $from = $since->copy()->addSecond();
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

        return ['rows' => $rows, 'report' => $report, 'from' => $from, 'since' => $since, 'truncated' => $window['truncated'], 'dropped' => $window['dropped']];
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
        return $this->history->replaceBaseline($this->scopeIds($p, true), Carbon::now(), $user);
    }

    /**
     * Forget the baseline of the page's scope.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     */
    public function clearBaseline(array $p): int
    {
        return $this->history->clearBaseline($this->scopeIds($p, true));
    }

    /**
     * Accept a change of root bridges (Baseline::rootChanges()) in the baseline of the page's scope,
     * on all the switches that see it, each with its own VLANs.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     * @param  string  $from  root bridge MAC in the baseline, '' for a new instance
     * @param  string  $to  root bridge MAC now, '' for an instance that is gone
     * @param  int[]  $vlans  VLANs of the change as the page showed it (all its switches)
     * @return array|null the change accepted with its text, null when the page's change is no longer there
     */
    public function acceptRootChange(array $p, string $from, string $to, array $vlans, ?string $user): ?array
    {
        $ids = $this->scopeIds($p, true);
        sort($vlans);
        // the baseline's root bridge facts are read under a lock: an admin accepting an overlapping change at the same
        // time waits, then sees this change applied
        $accepted = $this->history->transaction(function () use ($ids, $from, $to, $vlans, $user) {
            $baseline = $this->history->baselineFactsOfKind($ids, FactCollector::STP_ROOT, true);
            $current = $this->history->currentFactsOfKind($ids, FactCollector::STP_ROOT);
            foreach (Baseline::rootChanges($baseline, $current) as $change) {
                if (($change['from']['root'] ?? '') === $from && ($change['to']['root'] ?? '') === $to && $change['vlans'] === $vlans) {
                    $this->history->applyBaselineChange(Baseline::acceptRootChange($baseline, $current, $change), Carbon::now(), $user);

                    return $change;
                }
            }

            return null;
        });

        return $accepted !== null ? $accepted + ['text' => (new FactText($this->history->names($accepted['devices'])))->rootChange($accepted)] : null;
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
        return $this->history->forgetBaselineFact($hash, $this->scopeIds($p, true), $vlans);
    }

    /**
     * The facts of the devices as they are now, from the LibreNMS tables: one reader per kind, so a
     * large network is read a kind at a time and a kind that cannot be read is skipped alone.
     *
     * @param  int[]  $ids
     * @param  array<string, string[]>  $leftOut  set by a reader to the hashes of what it no longer records (FactDiff)
     * @return array<string, callable(): array<string, array>> kind => reader (hash => fact)
     */
    private function sources(TopologyRepository $topology, array $ids, array &$leftOut): array
    {
        $names = $this->history->names($ids);
        $hostNames = $this->history->hostNames($ids);
        $collector = fn (array $portIds) => new FactCollector($names, $topology->ports($portIds, self::PORT_COLUMNS), $hostNames);

        return [
            FactCollector::LINK => function () use ($topology, $ids, $collector, &$leftOut) {
                $links = $topology->links($ids);
                $facts = $collector(array_merge(array_column($links, 'local_port_id'), array_column($links, 'remote_port_id')))->links($links, $phones);
                $leftOut[FactCollector::LINK] = $phones;

                return $facts;
            },
            FactCollector::LAG => function () use ($topology, $ids, $collector) {
                $lag = $topology->lagParents($ids, true);

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
    /**
     * May the user's role read the LibreNMS data this kind of fact comes from?
     */
    public function factAllowed(string $kind): bool
    {
        return $this->history->allowsTable(self::FACT_TABLES[$kind] ?? '');
    }

    /**
     * May the user's role read the data this finding is about? OSPF and BGP findings need those, the other
     * routing findings (addresses) nothing more; the link and redundancy checks need the LLDP/CDP links.
     */
    private function findingAllowed(string $analysis, string $code): bool
    {
        return match (true) {
            $analysis === 'stp' => $this->history->allowsTable('stp'),
            $analysis === 'links', $analysis === 'redundancy' => $this->history->allowsTable('links'),
            $analysis === 'routing' && str_starts_with($code, 'ospf_') => $this->history->allowsTable('ospf_nbrs'),
            $analysis === 'routing' && str_starts_with($code, 'bgp_') => $this->history->allowsTable('bgpPeers'),
            default => true,
        };
    }

    private function baselineComparison(array $ids, FactText $text): array
    {
        $info = $this->history->baselineInfo($ids);
        if ($info === null) {
            return ['saved' => null, 'missing' => [], 'missing_total' => 0, 'new' => [], 'new_total' => 0];
        }

        // root bridges per switch and VLAN (one fact holds the VLANs of a root bridge), the rest by identity; only the
        // kinds the user's role may read
        $kinds = array_values(array_filter(array_diff(Baseline::KINDS, [FactCollector::STP_ROOT]), fn ($kind) => $this->factAllowed($kind)));
        $baseline = array_filter($this->history->baselineHashes($ids), fn ($kind) => $kind !== FactCollector::STP_ROOT && $this->factAllowed($kind));
        $current = $kinds ? $this->history->currentHashes($ids, $kinds) : [];
        $missingHashes = array_keys(array_diff_key($baseline, $current));
        $newHashes = array_keys(array_diff_key($current, $baseline));
        // a root bridge change is one item for all the switches that see it (an admin accepts it at once)
        $roots = $this->factAllowed(FactCollector::STP_ROOT) ? Baseline::rootChanges($this->history->baselineFactsOfKind($ids, FactCollector::STP_ROOT),
            $this->history->currentFactsOfKind($ids, FactCollector::STP_ROOT)) : [];
        $rootItem = fn (array $change) => [
            'kind' => FactCollector::STP_ROOT,
            'hash' => FactCollector::hash(FactCollector::STP_ROOT, 'd' . $change['devices'][0] . '|' . ($change['from']['root'] ?? '')),
            // the fields of its Accept form (the window's from / to are the page's own)
            'change' => ['root_from' => $change['from']['root'] ?? '', 'root_to' => $change['to']['root'] ?? '', 'vlans' => implode(',', $change['vlans'])],
            'switches' => count($change['devices']),
            'text' => $text->rootChange($change),
            'devices' => array_values(array_filter(array_slice($change['devices'], 0, 6), fn ($id) => $text->visible($id))),
        ];
        $describe = fn (array $fact) => [
            'kind' => $fact['kind'],
            'hash' => $fact['hash'],
            'change' => null,
            'switches' => null,
            'text' => $text->describe($fact),
            'devices' => array_values(array_filter([$fact['device_a'], $fact['device_b']], fn ($id) => $text->visible($id))),
        ];
        $missingRoots = array_values(array_filter($roots, fn ($change) => $change['from'] !== null));
        $newRoots = array_values(array_filter($roots, fn ($change) => $change['from'] === null));
        $missing = array_merge(array_map($rootItem, array_slice($missingRoots, 0, self::LISTED)),
            array_map($describe, $this->history->baselineFactsByHash($missingHashes, self::LISTED)));
        $since = $this->history->goneSince(array_column($missing, 'hash'));
        $missing = array_map(fn ($item) => $item + ['since' => $since[$item['hash']] ?? null], $missing);
        $new = array_merge(array_map($rootItem, array_slice($newRoots, 0, self::LISTED)),
            array_map($describe, $this->history->currentFactsByHash($newHashes, self::LISTED)));
        usort($missing, fn ($a, $b) => [$a['kind'], $a['text']] <=> [$b['kind'], $b['text']]);
        usort($new, fn ($a, $b) => [$a['kind'], $a['text']] <=> [$b['kind'], $b['text']]);

        return ['saved' => $info, 'missing' => array_slice($missing, 0, self::LISTED), 'missing_total' => count($missingHashes) + count($missingRoots),
            'new' => array_slice($new, 0, self::LISTED), 'new_total' => count($newHashes) + count($newRoots)];
    }

    /**
     * Devices of the page's scope (the centered device alone when one is chosen).
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     * @return int[]
     */
    /**
     * Devices of the page's scope: the centered device, else the group / location filter. For a write
     * ($strict), a device, group or location that was given but does not resolve (disabled, deleted, not
     * permitted) is refused rather than widened to every device.
     *
     * @throws \InvalidArgumentException
     */
    private function scopeIds(array $p, bool $strict = false): array
    {
        [$scope, $device] = $strict ? $this->history->strictScope($p) : $this->history->scope($p);

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
