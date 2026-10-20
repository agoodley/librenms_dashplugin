<?php

namespace Agoodley\LibrenmsTopology\Redundancy;

use Agoodley\LibrenmsTopology\Support\Graph;

/**
 * The core of each part of the network: the reference that devices must keep a path to.
 *  1. the configured core devices of the part;
 *  2. else the switches given the lowest spanning tree bridge priority, when it was lowered
 *     below the default 32768 (the root bridge placed on purpose);
 *  3. else the most central network devices: fewest hops to all other switches, routers and
 *     firewalls. Devices within 2% of the best count too, which keeps both members of a
 *     symmetric core pair and every spine of a leaf-spine fabric.
 */
class CoreFinder
{
    /** Central device candidates per part: the best connected network devices. */
    private const CANDIDATES = 30;

    /** Candidates whose hop total is within this share of the best are core devices too. */
    private const TOLERANCE = 0.02;

    /** Default bridge priority (IEEE 802.1D); lower values are set on purpose. */
    private const DEFAULT_PRIORITY = 32768;

    /**
     * @param  Graph  $graph  device graph (monitored and unmanaged nodes)
     * @param  array<int|string, true>  $network  monitored switches, routers and firewalls
     * @param  array<int|string, true>  $configured  core devices from the settings
     * @param  array<int|string, int>  $stpPriority  bridge priority of the default spanning tree instance
     * @return array<int|string, string> core node => 'configured', 'stp' or 'automatic'
     */
    public static function find(Graph $graph, array $network, array $configured, array $stpPriority = []): array
    {
        $core = [];
        foreach ($graph->components() as $component) {
            $members = array_values(array_filter($component, fn ($node) => isset($network[$node])));
            $chosen = array_values(array_filter($component, fn ($node) => isset($configured[$node])));
            $mode = 'configured';
            if (! $chosen) {
                [$chosen, $mode] = [self::rootBridges($members, $stpPriority), 'stp'];
            }
            if (! $chosen) {
                [$chosen, $mode] = [self::central($graph, $members, $network), 'automatic'];
            }
            foreach ($chosen as $node) {
                $core[$node] = $mode;
            }
        }

        return $core;
    }

    /**
     * Network devices with the lowest bridge priority of the part, if it is below the default.
     * The extended system ID (VLAN number) in the low bits is ignored.
     *
     * @param  array<int, int|string>  $members
     * @param  array<int|string, int>  $stpPriority
     * @return array<int, int|string>
     */
    private static function rootBridges(array $members, array $stpPriority): array
    {
        $priorities = [];
        foreach ($members as $node) {
            if (isset($stpPriority[$node])) {
                $priorities[$node] = $stpPriority[$node] & 0xF000;
            }
        }
        if (! $priorities || min($priorities) >= self::DEFAULT_PRIORITY) {
            return [];
        }

        return array_keys($priorities, min($priorities), true);
    }

    /**
     * Network devices of one part with the smallest total of hops to the other network devices.
     *
     * @param  array<int, int|string>  $members  network devices of the part
     * @param  array<int|string, true>  $network
     * @return array<int, int|string>
     */
    private static function central(Graph $graph, array $members, array $network): array
    {
        if (count($members) <= 1) {
            return $members;
        }

        // candidates: most network neighbours first (an access switch has many phones, few switches)
        $degree = [];
        foreach ($members as $node) {
            $degree[$node] = count(array_filter($graph->neighbours($node), fn ($next) => isset($network[$next])));
        }
        uksort($degree, fn ($a, $b) => [$degree[$b], (string) $a] <=> [$degree[$a], (string) $b]);

        $totals = [];
        foreach (array_slice(array_keys($degree), 0, self::CANDIDATES) as $node) {
            $total = 0;
            foreach ($graph->distances($node) as $other => $hops) {
                if (isset($network[$other])) {
                    $total += $hops;
                }
            }
            $totals[$node] = $total;
        }

        $best = min($totals);

        return array_keys(array_filter($totals, fn ($total) => $total <= $best * (1 + self::TOLERANCE)));
    }
}
