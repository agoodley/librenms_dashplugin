<?php

namespace Agoodley\LibrenmsTopology\Redundancy;

/**
 * Batches for a firmware upgrade, a reboot or a configuration change of many devices, planned on
 * the network as it is now (the current graph of the redundancy analysis):
 *  - farthest from the core first and the core last: a device's path to the core only runs through
 *    devices closer to the core, so a change that breaks a device never cuts off devices that still
 *    wait for it;
 *  - devices that back each other up never share a batch: a batch going down as a whole cuts off no
 *    more than its devices going down one at a time;
 *  - for each batch, what loses its path to the core while it is down: its maintenance window.
 * What one device cuts off comes from a single depth first search (cut vertices). A batch is only
 * searched again when its devices could together cut off something more: the device closest to the
 * core among those cut off has all its links toward the core on the batch or on what it cuts off
 * anyway (see conflict()).
 * Whether devices back each other up is decided strictly: with every core device of a part down, the
 * whole part has lost the core, so the core devices of a part never share a batch. What a core batch
 * takes down is reported like the map's failure simulation (the largest remaining piece carries on).
 * Devices without a path to the core now, or without any LLDP/CDP neighbor, are left out: nothing
 * tells what depends on them, they are planned by hand.
 */
class RolloutPlanner
{
    /** Tier of the core devices: planned last. */
    public const CORE = 'core';

    /** Reasons a device is left out of the plan. */
    public const UNKNOWN = 'unknown';

    public const DOWN = 'down';

    public const ISOLATED = 'isolated';

    public const UNCONNECTED = 'unconnected';

    /** @var array<string, array> node id => node, see RedundancyAnalyzer::nodes() */
    private array $nodes;

    /** @var array<string, string[]> device node => its graph nodes (copies of an end device, else itself) */
    private array $graphNodes = [];

    /** @var array<string, string> graph node => device node */
    private array $deviceOf = [];

    /** @var array<string, array<int, string>> graph node => neighbours in the current graph */
    private array $adjacency = [];

    /** @var array<string, int> graph node => hops from the core node now (reachable nodes only) */
    private array $distance = [];

    /** @var array<string, int> graph node => number of its neighbours one hop closer to the core */
    private array $parentCount = [];

    /** @var array<string, array<int, string>> graph node => its neighbours one hop farther from the core */
    private array $children = [];

    /** @var array{order: array, tin: array, tout: array, vertices: array} cut vertices toward the core now */
    private array $cuts;

    /** @var array<string, array<string, true>> device node => graph nodes that lose the core when it alone is down */
    private array $alone = [];

    /** @var array<string, array<string, true>> device node => devices that lose the core when it alone is down */
    private array $aloneDevices = [];

    /** @var array<string, int> graph node => number of its links in the design graph (also those down now) */
    private array $designDegree = [];

    public function __construct(private RedundancyAnalyzer $analyzer)
    {
        $this->nodes = $analyzer->nodes();
        foreach ($analyzer->copies() as $device => $copies) {
            $this->graphNodes[$device] = $copies;
            foreach ($copies as $copy) {
                $this->deviceOf[$copy] = $device;
            }
        }

        $graph = $analyzer->graph(current: true);
        foreach ($graph->nodes() as $node) {
            $node = (string) $node;
            $this->adjacency[$node] = array_map('strval', $graph->neighbours($node));
            $this->deviceOf[$node] ??= $node;
        }
        $this->distance = array_map('intval', $graph->distances(RedundancyAnalyzer::CORE));
        foreach ($this->distance as $node => $hops) {
            foreach ($this->adjacency[$node] as $next) {
                if (($this->distance[$next] ?? null) === $hops - 1) {
                    $this->parentCount[$node] = ($this->parentCount[$node] ?? 0) + 1;
                } elseif (($this->distance[$next] ?? null) === $hops + 1) {
                    $this->children[$node][] = $next;
                }
            }
        }
        $this->cuts = $graph->cuts(RedundancyAnalyzer::CORE);

        $design = $analyzer->graph();
        foreach ($design->nodes() as $node) {
            $this->designDegree[(string) $node] = $design->degree($node);
        }
    }

    /**
     * Batches for the given devices, in the order to run them.
     *
     * @param  int[]  $deviceIds  monitored devices to change
     * @param  int  $batchSize  most devices in one batch, 0 for no limit
     * @return array{batches: array<int, array{tier: int|string, devices: int[], lost: int[], network: int, unmanaged: int,
     *     refs: string[], reference: string}>, skipped: array<int, array{device_id: int, reason: string}>} tier: hops from the
     *     core devices (1 for a switch cabled to one) or CORE; reference: 'core', or 'largest part' when a core batch
     *     leaves a part without core device; skipped: UNKNOWN (not analyzed), DOWN, ISOLATED (no LLDP/CDP neighbor) or
     *     UNCONNECTED (no path to the core now)
     */
    public function plan(array $deviceIds, int $batchSize = 0): array
    {
        $tiers = [];
        $skipped = [];
        foreach (array_values(array_unique(array_map('intval', $deviceIds))) as $deviceId) {
            $device = 'd:' . $deviceId;
            if (! isset($this->nodes[$device]) || isset($this->nodes[$device]['of'])) {
                $skipped[] = ['device_id' => $deviceId, 'reason' => self::UNKNOWN];
            } elseif (! $this->nodes[$device]['up']) {
                $skipped[] = ['device_id' => $deviceId, 'reason' => self::DOWN];
            } elseif (($tier = $this->tier($device)) === null) {
                $links = array_sum(array_map(fn ($node) => $this->designDegree[$node] ?? 0, $this->graphNodesOf($device)));
                $skipped[] = ['device_id' => $deviceId, 'reason' => $links ? self::UNCONNECTED : self::ISOLATED];
            } else {
                $tiers[(string) $tier][] = $device;
            }
        }

        // the farthest from the core first, the core last
        $rank = fn (string $tier) => $tier === self::CORE ? -1 : (int) $tier;
        uksort($tiers, fn ($a, $b) => $rank((string) $b) <=> $rank((string) $a));

        $batches = [];
        foreach ($tiers as $tier => $devices) {
            $tier = (string) $tier;
            usort($devices, fn ($a, $b) => strnatcasecmp($this->nodes[$a]['name'], $this->nodes[$b]['name']) ?: strcmp($a, $b));
            foreach ($this->pack($tier, $devices, $batchSize) as $batch) {
                $batches[] = $this->describe($tier, $batch);
            }
        }

        return ['batches' => $batches, 'skipped' => $skipped];
    }

    /**
     * Hops from the core node now (its closest link; 1 for a core device), the core tier, or null
     * without a path to the core.
     */
    private function tier(string $device): int|string|null
    {
        $hops = null;
        foreach ($this->graphNodesOf($device) as $node) {
            if (isset($this->distance[$node])) {
                $hops = $hops === null ? $this->distance[$node] : min($hops, $this->distance[$node]);
            }
        }
        if ($hops !== null && isset($this->analyzer->coreNodes()[$device])) {
            return self::CORE;
        }

        return $hops;
    }

    /**
     * Devices of one tier in as few batches as allowed (first fit, in the given order).
     *
     * @param  array<int, string>  $devices
     * @return array<int, array{devices: array<int, string>, covered: array<string, true>, counts: array<string, int>,
     *     lost: array<string, true>}>
     */
    private function pack(string $tier, array $devices, int $batchSize): array
    {
        $batches = [];
        foreach ($devices as $device) {
            $placed = false;
            foreach ($batches as $index => $batch) {
                if ($batchSize > 0 && count($batch['devices']) >= $batchSize) {
                    continue;
                }
                $added = $this->add($tier, $batch, $device);
                if ($added !== null) {
                    $batches[$index] = $added;
                    $placed = true;
                    break;
                }
            }
            if (! $placed) {
                $batches[] = $this->add($tier, ['devices' => [], 'covered' => [], 'counts' => [], 'lost' => []], $device);
            }
        }

        return $batches;
    }

    /**
     * The batch with the device added, or null when they back each other up.
     *
     * @param  array{devices: array<int, string>, covered: array<string, true>, counts: array<string, int>, lost: array<string, true>}  $batch
     * @return array{devices: array<int, string>, covered: array<string, true>, counts: array<string, int>, lost: array<string, true>}|null
     */
    private function add(string $tier, array $batch, string $device): ?array
    {
        // what the device cuts off alone, and whether the batch could cut off more together
        $new = [];
        foreach ($this->graphNodesOf($device) as $node) {
            if (! isset($batch['covered'][$node])) {
                $new[$node] = true;
            }
        }
        foreach ($this->aloneNodes($device) as $node => $true) {
            if (! isset($batch['covered'][$node])) {
                $new[$node] = true;
            }
        }
        $counts = [];
        foreach (array_keys($new) as $node) {
            foreach ($this->children[$node] ?? [] as $child) {
                $counts[$child] = ($counts[$child] ?? 0) + 1;
            }
        }

        $devices = [...$batch['devices'], $device];
        if ($batch['devices'] && $this->conflict($batch, $new, $counts, $devices)) {
            return null;
        }

        $batch['devices'] = $devices;
        $batch['covered'] += $new;
        foreach ($counts as $child => $count) {
            $batch['counts'][$child] = ($batch['counts'][$child] ?? 0) + $count;
        }
        $batch['lost'] += $this->aloneDevicesOf($device);

        return $batch;
    }

    /**
     * Do the batch's devices cut off more together than one at a time? Only searched when a node
     * outside what is covered (the batch and what its devices cut off alone) has all its links toward
     * the core on covered nodes, or an end device has all its links on covered nodes: else nothing
     * more can be cut off.
     *
     * @param  array<string, true>  $new  nodes the device adds to the covered ones
     * @param  array<string, int>  $counts  links toward the core of their children that $new covers
     * @param  array<int, string>  $devices  the batch with the device
     */
    private function conflict(array $batch, array $new, array $counts, array $devices): bool
    {
        $covered = fn (string $node) => isset($batch['covered'][$node]) || isset($new[$node]);
        $suspect = false;
        foreach ($counts as $child => $count) {
            if (! $covered($child) && ($batch['counts'][$child] ?? 0) + $count >= ($this->parentCount[$child] ?? 0)) {
                $suspect = true;
                break;
            }
        }
        if (! $suspect) {
            foreach (array_keys($new) as $node) {
                $device = $this->deviceOf[$node] ?? $node;
                if ($device !== $node && ! in_array($device, $devices, true) && $this->allCovered($device, $covered)) {
                    $suspect = true; // an end device whose links all end on the batch or on what it cuts off
                    break;
                }
            }
        }
        if (! $suspect) {
            return false;
        }

        $lostAlone = $batch['lost'] + $this->aloneDevicesOf($devices[count($devices) - 1]);
        foreach ($this->lostTogether($devices) as $lost => $true) {
            if (! isset($lostAlone[$lost])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Devices that lose the core when the given devices are down together (breadth first search
     * from the core around them).
     *
     * @param  array<int, string>  $devices
     * @return array<string, true> devices (not among $devices)
     */
    private function lostTogether(array $devices): array
    {
        $down = [];
        foreach ($devices as $device) {
            foreach ($this->graphNodesOf($device) as $node) {
                $down[$node] = true;
            }
        }
        $reached = [RedundancyAnalyzer::CORE => true];
        $queue = [RedundancyAnalyzer::CORE];
        for ($i = 0; $i < count($queue); $i++) {
            foreach ($this->adjacency[$queue[$i]] ?? [] as $next) {
                if (! isset($reached[$next]) && ! isset($down[$next])) {
                    $reached[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        $lost = [];
        foreach (array_keys($this->distance) as $node) {
            $node = (string) $node;
            $device = $this->deviceOf[$node] ?? $node;
            if (! isset($reached[$node]) && ! isset($down[$node]) && $node !== RedundancyAnalyzer::CORE) {
                $lost[$device] = true;
            }
        }
        // an end device keeps the core while one of its links still reaches it
        foreach (array_keys($lost) as $device) {
            foreach ($this->graphNodesOf($device) as $node) {
                if (isset($reached[$node])) {
                    unset($lost[$device]);
                    break;
                }
            }
        }

        return $lost;
    }

    /**
     * Graph nodes that lose the core when the device alone is down (the subtrees of its cut children).
     *
     * @return array<string, true>
     */
    private function aloneNodes(string $device): array
    {
        if (! isset($this->alone[$device])) {
            $nodes = [];
            foreach ($this->graphNodesOf($device) as $node) {
                foreach ($this->cuts['vertices'][$node] ?? [] as $child) {
                    for ($i = $this->cuts['tin'][$child]; $i <= $this->cuts['tout'][$child]; $i++) {
                        $nodes[(string) $this->cuts['order'][$i]] = true;
                    }
                }
            }
            $this->alone[$device] = $nodes;
        }

        return $this->alone[$device];
    }

    /**
     * Devices that lose the core when the device alone is down: those whose links that reach the
     * core now all are among aloneNodes().
     *
     * @return array<string, true>
     */
    private function aloneDevicesOf(string $device): array
    {
        if (! isset($this->aloneDevices[$device])) {
            $nodes = $this->aloneNodes($device);
            $devices = [];
            foreach (array_keys($nodes) as $node) {
                $of = $this->deviceOf[$node] ?? $node;
                if (! isset($devices[$of]) && $of !== $device && $this->allCovered($of, fn ($n) => isset($nodes[$n]))) {
                    $devices[$of] = true;
                }
            }
            $this->aloneDevices[$device] = $devices;
        }

        return $this->aloneDevices[$device];
    }

    /**
     * Are all graph nodes of a device that reach the core now accepted by $covered?
     *
     * @param  callable(string): bool  $covered
     */
    private function allCovered(string $device, callable $covered): bool
    {
        foreach ($this->graphNodesOf($device) as $node) {
            if (isset($this->distance[$node]) && ! $covered($node)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function graphNodesOf(string $device): array
    {
        return $this->graphNodes[$device] ?? [$device];
    }

    /**
     * Does the batch take down every core device that is up in a part of the network?
     *
     * @param  array<int, string>  $devices
     */
    private function takesWholeCore(array $devices): bool
    {
        $parts = $this->analyzer->parts();
        $batch = array_flip($devices);
        $left = [];
        foreach (array_keys($this->analyzer->coreNodes()) as $core) {
            if ($this->nodes[$core]['up'] && isset($parts[$core])) {
                $left[$parts[$core]] = ($left[$parts[$core]] ?? 0) + (isset($batch[$core]) ? 0 : 1);
            }
        }

        return in_array(0, $left, true);
    }

    /**
     * Result row of a batch: its devices, what loses its path to the core while it is down and the
     * references of the map's failure simulation. A core batch is described by the simulation
     * itself, which lets the largest remaining piece carry on when no core device is left.
     *
     * @param  array{devices: array<int, string>, lost: array<string, true>}  $batch
     * @return array{tier: int|string, devices: int[], lost: int[], network: int, unmanaged: int, refs: string[], reference: string}
     */
    private function describe(string $tier, array $batch): array
    {
        $reference = 'core';
        if ($tier === self::CORE) {
            $simulated = $this->analyzer->simulate($batch['devices']);
            $lostNodes = array_column($simulated['lost'], 'node');
            $unmanaged = $simulated['unmanaged'];
            $reference = $this->takesWholeCore($batch['devices']) ? 'largest part' : 'core';
        } else {
            $leaves = $this->analyzer->leaves();
            $lostNodes = array_values(array_diff(array_map('strval', array_keys($batch['lost'])), $batch['devices']));
            $unmanaged = 0;
            foreach ($batch['devices'] as $device) {
                $unmanaged += count($leaves[$device] ?? []);
            }
            foreach ($lostNodes as $device) {
                $unmanaged += count($leaves[$device] ?? []) + ($this->nodes[$device]['managed'] ? 0 : 1);
            }
        }

        $lost = [];
        $network = 0;
        foreach ($lostNodes as $device) {
            $node = $this->nodes[$device];
            if ($node['managed']) {
                $lost[] = (int) $node['device_id'];
                $network += $node['network'] ? 1 : 0;
            }
        }
        // switches, routers and firewalls first, as in the failure simulation
        $key = fn (int $id) => [! $this->nodes['d:' . $id]['network'], strtolower($this->nodes['d:' . $id]['name'])];
        usort($lost, fn ($a, $b) => $key($a) <=> $key($b));

        return [
            'tier' => $tier === self::CORE ? self::CORE : (int) $tier - 1,
            'devices' => array_map(fn ($device) => (int) $this->nodes[$device]['device_id'], $batch['devices']),
            'lost' => $lost,
            'network' => $network,
            'unmanaged' => $unmanaged,
            'refs' => $batch['devices'],
            'reference' => $reference,
        ];
    }
}
