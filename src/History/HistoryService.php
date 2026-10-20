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
        $run = $this->history->startRun('snapshot', $started);

        $ids = $this->topology->deviceIds();
        $current = $this->collect($ids);
        $diff = FactDiff::diff($this->history->openFacts($this->history->disabledDeviceIds()), $current);
        $knownKinds = $this->history->factKinds();

        $stats = [
            'devices' => count($ids),
            'facts' => count($current),
            'added' => $this->history->insertFacts($diff['insert'], $started, $knownKinds),
            'removed' => $this->history->closeFacts($diff['remove'], $started),
            'updated' => $this->history->updateFacts($diff['update']),
            'pruned' => $this->history->prune($started->copy()->subDays($this->settings->int('history_retention_days', 1, 3650))),
        ];
        $stats['seconds'] = round(Carbon::now()->diffInMilliseconds($started, true) / 1000, 2);
        $this->history->finishRun($run, $stats, Carbon::now());

        return $stats;
    }

    /**
     * Run the background checks and record new and resolved findings; those at or above the
     * eventlog severity setting are written to the eventlog of their devices.
     *
     * @param  callable(string, int|null, string, string): void|null  $eventlog  writer (message, device_id, severity,
     *                                                                           reference); default: LibreNMS eventlog
     * @return array<string, array<string, int>> analysis => counts
     */
    public function checks(?callable $eventlog = null): array
    {
        $analyses = array_values(array_intersect(self::ANALYSES, array_map('strtolower', $this->settings->list('history_checks'))));
        if (! $analyses) {
            return [];
        }

        $started = Carbon::now();
        $run = $this->history->startRun('checks', $started);
        $service = new TopologyService($this->topology, $this->settings, $this->history);
        $params = MapParams::parse([], $this->settings);
        $minimum = strtolower($this->settings->all()['history_eventlog_severity'] ?? 'warning');
        $eventlog ??= self::eventlog(...);

        $stats = ['analyses' => []];
        foreach ($analyses as $analysis) {
            try {
                $findings = $service->$analysis($params)['findings'] ?? [];
            } catch (\Throwable $e) {
                Log::error("topology plugin: background $analysis check failed: " . $e->getMessage());
                $stats['analyses'][$analysis] = ['error' => 1];
                continue;
            }

            $initial = ! $this->history->hasFindings($analysis);
            $diff = FindingHistory::diff($analysis, $this->history->openFindings($analysis), $findings);
            $this->history->insertFindings($analysis, $diff['new'], $started, $initial);
            $this->history->resolveFindings(array_column($diff['resolved'], 'id'), $started);
            $this->history->updateFindings($diff['update']);

            // the first run records what is there already: only later changes are news
            $entries = $initial ? [] : FindingHistory::eventlog($analysis, $diff['new'], $diff['resolved'], $minimum);
            foreach ($entries as $entry) {
                $eventlog($entry['message'], $entry['device_id'], $entry['severity'], $entry['reference']);
            }

            $stats['analyses'][$analysis] = ['findings' => count($findings), 'new' => count($diff['new']), 'resolved' => count($diff['resolved']),
                'eventlog' => count($entries), 'initial' => (int) $initial];
        }
        $stats['seconds'] = round(Carbon::now()->diffInMilliseconds($started, true) / 1000, 2);
        $this->history->finishRun($run, $stats, Carbon::now());

        return $stats['analyses'];
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

        $rows = $this->history->windowFacts($from, $to, $ids);
        $candidates = [];
        foreach ($rows as $row) {
            if ($row['kind'] === FactCollector::LINK && isset($row['data']['b']['node']) && ! $row['initial'] && $row['first_seen'] >= $from->getTimestamp()) {
                $candidates[] = $row['data']['b']['node'];
            }
        }
        $report = ChangeReport::build($rows, $from->getTimestamp(), $to->getTimestamp(), $text,
            $this->history->devicesAddedSince($ids, $from), $this->history->priorNodes($candidates, $from));

        $findings = array_map(function (array $finding) use ($text) {
            $finding['device_names'] = array_combine($finding['devices'], array_map(fn ($id) => $text->device($id, null), $finding['devices']));

            return $finding;
        }, $this->history->windowFindings($from, $to, $ids));

        return [
            'available' => true,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'events' => $report['events'],
            'counts' => $report['counts'],
            'baseline' => $this->baselineComparison($ids, $text),
            'findings' => $findings,
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
        $facts = array_filter($this->history->currentFacts($ids), fn ($fact) => in_array($fact['kind'], Baseline::KINDS, true));

        return [
            'devices' => count($ids),
            'kinds' => array_count_values(array_column($facts, 'kind')),
            'facts' => count($facts),
            'replaces' => $this->history->baselineInfo($ids)['facts'] ?? 0,
            'snapshot' => $this->history->latestRuns()['snapshot']['started_at'] ?? null,
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
        $ids = $this->scopeIds($p);

        return $this->history->replaceBaseline($ids, $this->history->currentFacts($ids, Baseline::KINDS), Carbon::now(), $user);
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
     * Facts of the devices as they are now, from the LibreNMS tables.
     *
     * @param  int[]  $ids
     * @return array<string, array> hash => fact
     */
    public function collect(array $ids): array
    {
        $links = $this->topology->links($ids);
        $lag = $this->topology->lagParents($ids);
        $stp = $this->history->stpRoots($ids);
        $blocked = $this->history->blockedPorts($ids);
        $ospf = $this->history->ospfNeighbors($ids);
        $bgp = $this->history->bgpPeers($ids);
        $addresses = $this->history->addressOwners(array_merge(array_column($ospf, 'ospfNbrIpAddr'), array_map('strtolower', array_column($bgp, 'bgpPeerIdentifier'))), $ids);

        $portIds = array_merge(
            array_column($links, 'local_port_id'),
            array_column($links, 'remote_port_id'),
            array_keys($lag),
            array_values($lag),
            array_column($blocked, 'port_id'),
            array_column($ospf, 'port_id'),
        );
        $collector = new FactCollector($this->history->names($ids), $this->topology->ports($portIds, self::PORT_COLUMNS));

        return $collector->links($links)
            + $collector->lagMembers($lag)
            + $collector->stpRoots($stp)
            + $collector->blockedPorts($blocked)
            + $collector->ospf($ospf, $this->history->routerIds($ids), $addresses)
            + $collector->bgp($bgp, $addresses);
    }

    /**
     * Baseline against the last snapshot: what is missing and what is new.
     *
     * @param  int[]  $ids
     */
    private function baselineComparison(array $ids, FactText $text): array
    {
        $info = $this->history->baselineInfo($ids);
        if ($info === null) {
            return ['saved' => null, 'missing' => [], 'new' => [], 'new_total' => 0];
        }

        $comparison = Baseline::compare($this->history->baselineFacts($ids), $this->history->currentFacts($ids, Baseline::KINDS));
        $since = $this->history->lastSeen(array_keys($comparison['missing']));
        $describe = fn (array $fact) => [
            'kind' => $fact['kind'],
            'text' => $text->describe($fact),
            'devices' => array_values(array_filter([$fact['device_a'], $fact['device_b']], fn ($id) => $text->visible($id))),
        ];
        $missing = array_map(fn ($fact) => $describe($fact) + ['since' => $since[$fact['hash']] ?? null], array_values($comparison['missing']));
        $new = array_map($describe, array_values($comparison['new']));
        usort($missing, fn ($a, $b) => [$a['kind'], $a['text']] <=> [$b['kind'], $b['text']]);
        usort($new, fn ($a, $b) => [$a['kind'], $a['text']] <=> [$b['kind'], $b['text']]);

        return ['saved' => $info, 'missing' => $missing, 'new' => array_slice($new, 0, 200), 'new_total' => count($new)];
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
