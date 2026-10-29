<?php

namespace Agoodley\LibrenmsTopology\Verification;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Data\RoutingRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Data\VerificationRepository;
use Agoodley\LibrenmsTopology\History\FindingHistory;
use Agoodley\LibrenmsTopology\History\HistoryService;
use Agoodley\LibrenmsTopology\Redundancy\RedundancyAnalyzer;
use Agoodley\LibrenmsTopology\Routing\RoutingService;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\ErrorText;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Support\RoleData;
use Agoodley\LibrenmsTopology\Topology\TopologyService;
use Illuminate\Support\Facades\Log;

/**
 * The verification API's sources in LibreNMS: the plugin's scoped repositories (a user's view, or the system scope of
 * the console) and its own checks and history code: the facts as the snapshot records them (HistoryService), the
 * findings as the background checks find them (TopologyService, RoutingService), the blast radius as the planning
 * API's (the redundancy analysis' failure simulation). The redundancy analysis is built once per instance.
 */
final class LibrenmsSources implements Sources
{
    private ?HistoryRepository $history = null;

    private ?VerificationRepository $verification = null;

    private ?TopologyService $service = null;

    private ?RedundancyAnalyzer $redundancy = null;

    /** @var array{keys: array<int, int[]>, bridges: array<string, int>}|null the spanning tree keys of every device the scope sees */
    private ?array $stpKeys = null;

    public function __construct(private readonly TopologyRepository $repo, private readonly PluginSettings $settings)
    {
    }

    public function visible(array $ids): array
    {
        return $this->verification()->visibleOf($ids);
    }

    public function neighbors(array $ids): array
    {
        return $this->verification()->neighborsOf($ids);
    }

    public function dependents(array $ids): array
    {
        $ids = $this->visible($ids);
        if (! $ids) {
            return [];
        }
        $lost = [];
        foreach ($this->redundancy()->simulate(array_map(fn (int $id) => 'd:' . $id, $ids))['lost'] as $node) {
            if (($node['device_id'] ?? null) !== null) {
                $lost[] = (int) $node['device_id'];
            }
        }

        return array_values(array_diff($this->visible($lost), $ids));
    }

    public function stpDomain(array $ids): array
    {
        $keys = $this->stpKeys()['keys'];
        $start = array_values(array_filter($this->visible($ids), fn (int $id) => isset($keys[$id])));
        if (! $start) {
            return [];
        }
        // the LLDP/CDP links between switches with spanning tree data, as the scope sees them
        $adjacent = [];
        foreach ($this->repo->linksWhere(array_keys($keys), fn (array $link) => isset($keys[(int) $link['remote_device_id']])) as $link) {
            $adjacent[(int) $link['local_device_id']][(int) $link['remote_device_id']] = true;
            $adjacent[(int) $link['remote_device_id']][(int) $link['local_device_id']] = true;
        }
        $seen = array_fill_keys($start, true);
        $queue = $start;
        for ($i = 0; $i < count($queue); $i++) {
            foreach (array_keys($adjacent[$queue[$i]] ?? []) as $far) {
                if (! isset($seen[$far])) {
                    $seen[$far] = true;
                    $queue[] = $far;
                }
            }
        }

        return $queue;
    }

    public function facts(array $ids, array $rootIds, ?array $nameIds): array
    {
        $facts = (new HistoryService($this->history(), $this->repo, $this->settings))->currentFacts($this->repo, $this->visible($ids), $this->visible($rootIds),
            $nameIds === null ? $this->repo->deviceIds() : $this->visible($nameIds), $failed, $errors);

        return ['facts' => $facts, 'failed' => $failed, 'errors' => $errors];
    }

    public function findings(array $ids, array $stpIds): array
    {
        // the checks of the network's state: a state is no time window (HistoryService::WINDOW_ANALYSES)
        $enabled = array_values(array_diff((new HistoryService($this->history(), $this->repo, $this->settings))->enabledChecks(),
            HistoryService::WINDOW_ANALYSES));
        $result = ['findings' => [], 'failed' => [], 'enabled' => $enabled];
        // the redundancy check first, on the analysis the scope's dependents were found with, which is then let go: on a
        // large network it and the spanning tree check's links and ports are the most memory a state takes
        $order = array_values(array_unique([...array_intersect(['redundancy'], $enabled), ...$enabled]));
        foreach ($order as $analysis) {
            try {
                $result['findings'][$analysis] = match ($analysis) {
                    'stp' => $this->service()->stpFindingsFor($this->visible($stpIds))['findings'],
                    'links' => $this->linkFindings($ids),
                    'redundancy' => $this->redundancy()->analyze()['findings'],
                    // strict, as the background check: a table that cannot be read fails the check rather than become findings
                    'routing' => (new RoutingService($this->repo->sibling(RoutingRepository::class)->strict(), $this->settings))
                        ->analyze(MapParams::parse([], $this->settings))['findings'] ?? [],
                    default => [],
                };
            } catch (\Throwable $e) {
                Log::error("topology plugin: the verification's $analysis check failed: " . $e->getMessage());
                $result['failed'][$analysis] = ErrorText::line($e);
            }
            if ($analysis === 'redundancy') {
                $this->redundancy = null;
            }
        }

        return $result;
    }

    public function linkFindings(?array $ids): array
    {
        return $this->service()->links(MapParams::parse([], $this->settings), $ids === null ? null : $this->visible($ids))['findings'];
    }

    public function stpAnalysis(?array $ids): array
    {
        return $this->service()->stpFindingsFor($ids === null ? array_keys($this->stpKeys()['keys']) : $this->visible($ids));
    }

    public function bridges(array $keys): array
    {
        $all = $this->stpKeys()['keys'];
        $result = [];
        $wanted = [];
        foreach ($keys as $deviceId => $deviceKeys) {
            $deviceId = (int) $deviceId;
            if (! isset($all[$deviceId]) || ! $this->repo->canSee($deviceId)) {
                continue;
            }
            $result[$deviceId] = ['keys' => $all[$deviceId], 'instances' => []];
            // a single-instance bridge (802.1D, RSTP, MST's CIST) controls every VLAN with its one instance
            $wanted[$deviceId] = $all[$deviceId] === [0] ? [0] : array_values(array_intersect(array_map('intval', $deviceKeys), $all[$deviceId]));
        }
        foreach ($this->repo->stpInstancesFor($wanted) as $row) {
            $instance = StpIndex::normalizeInstance($row);
            $deviceId = $instance['device_id'];
            $info = ['mac' => $instance['bridgeAddress'], 'priority' => $instance['priority'], 'protocol' => $instance['protocolSpecification']];
            if ($all[$deviceId] === [0]) {
                // the one instance stands for each VLAN asked
                foreach (array_map('intval', $keys[$deviceId] ?? [0]) as $key) {
                    $result[$deviceId]['instances'][$key] = $info;
                }
            } else {
                $result[$deviceId]['instances'][$instance['vlan']] = $info;
            }
        }

        return $result;
    }

    public function explicitPriorities(array $keys): array
    {
        $all = $this->stpKeys()['keys'];
        $keys = array_values(array_unique(array_map('intval', $keys)));
        $result = [];
        foreach ($this->repo->lowPriorityInstances(array_keys($all)) as $row) {
            $instance = StpIndex::normalizeInstance($row);
            $deviceId = $instance['device_id'];
            if (! isset($all[$deviceId]) || ! $this->repo->canSee($deviceId)) {
                continue;
            }
            $priority = intdiv(max(0, $instance['priority']), 4096) * 4096;
            // a single-instance bridge controls every VLAN with its one instance
            foreach ($all[$deviceId] === [0] ? $keys : array_intersect([$instance['vlan']], $keys) as $key) {
                $result[$key][$deviceId] = min($priority, $result[$key][$deviceId] ?? PHP_INT_MAX);
            }
        }
        ksort($result);

        return $result;
    }

    public function deviceStates(array $ids): array
    {
        return $this->verification()->deviceStates($ids);
    }

    public function portNames(array $portIds): array
    {
        return $this->verification()->portNames($portIds);
    }

    public function names(array $ids): array
    {
        return $this->history()->names($this->visible($ids));
    }

    public function devicesOfSetting(string $key): array
    {
        $wanted = $this->settings->list($key);
        if (! $wanted) {
            return ['devices' => [], 'ambiguous' => [], 'unmatched' => []];
        }

        return FixBuilder::devicesNamed($wanted, array_map(fn (array $row) => (string) ($row['hostname'] ?? ''), $this->repo->nameRows()));
    }

    public function quietChecks(): array
    {
        return FindingHistory::skipList((string) ($this->settings->all()['history_eventlog_skip'] ?? ''));
    }

    public function roleHidden(): array
    {
        return $this->repo->hiddenKinds(RoleData::PAGES['verification']);
    }

    /**
     * The scope's sibling repositories and the topology service, made on first use (a repository reads the devices the
     * scope may see when it is made).
     */
    private function history(): HistoryRepository
    {
        return $this->history ??= $this->repo->sibling(HistoryRepository::class);
    }

    private function verification(): VerificationRepository
    {
        return $this->verification ??= $this->repo->sibling(VerificationRepository::class);
    }

    private function service(): TopologyService
    {
        return $this->service ??= new TopologyService($this->repo, $this->settings, $this->history());
    }

    /**
     * The redundancy analysis of the whole network the scope sees, built once (with stack members and outage starts, as
     * the redundancy page).
     */
    private function redundancy(): RedundancyAnalyzer
    {
        return $this->redundancy ??= $this->service()->redundancyAnalyzer($this->repo->deviceIds(), true);
    }

    /**
     * The spanning tree keys of every device the scope sees, read once.
     *
     * @return array{keys: array<int, int[]>, bridges: array<string, int>}
     */
    private function stpKeys(): array
    {
        return $this->stpKeys ??= $this->repo->stpKeys($this->repo->deviceIds());
    }
}
