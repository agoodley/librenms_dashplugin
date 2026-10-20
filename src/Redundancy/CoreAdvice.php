<?php

namespace Agoodley\LibrenmsTopology\Redundancy;

/**
 * Parts of the network whose core is a single device, and the switch that is cabled like a second
 * core switch there (most of the core device's switches also have a link to it). Without it in the
 * core, that switch is planned like an ordinary one: the rollout planner can batch it with access
 * switches and devices cabled to both depend on the core device alone. Setting both as Core devices
 * fixes that; the redundancy analysis itself (CoreFinder) is left as it is.
 */
class CoreAdvice
{
    /** Bridge priority of devices without spanning tree data (the IEEE default). */
    private const DEFAULT_PRIORITY = 32768;

    /**
     * One entry per part of the network with a single core device and other switches, routers or
     * firewalls: the core device, the switch cabled like a second core switch (null if none) and how
     * many switches, routers and firewalls have a link to both.
     *
     * @return array<int, array{core: int, lookalike: ?int, shared: int}>
     */
    public static function singleCores(RedundancyAnalyzer $analyzer): array
    {
        $nodes = $analyzer->nodes();
        $parts = $analyzer->parts();
        $graph = $analyzer->graph();
        $network = fn (string $node) => isset($nodes[$node]) && $nodes[$node]['managed'] && $nodes[$node]['network'] && ! isset($nodes[$node]['of']);
        $neighbours = function (string $node) use ($graph, $network): array {
            return array_values(array_filter(array_map('strval', $graph->neighbours($node)), $network));
        };

        $byPart = [];
        foreach (array_keys($analyzer->coreNodes()) as $core) {
            $byPart[$parts[$core] ?? -1][] = (string) $core;
        }
        $networkInPart = [];
        foreach ($parts as $node => $part) {
            if ($network((string) $node)) {
                $networkInPart[$part] = ($networkInPart[$part] ?? 0) + 1;
            }
        }

        $priority = [];
        foreach ($analyzer->bridgePriorities() as $deviceId => $value) {
            $priority['d:' . $deviceId] = (int) $value & 0xF000;
        }

        $result = [];
        foreach ($byPart as $part => $cores) {
            if (count($cores) !== 1 || ($networkInPart[$part] ?? 0) < 2) {
                continue;
            }
            $core = $cores[0];
            $around = $neighbours($core);
            $best = null;
            $bestShared = 0;
            foreach ($around as $candidate) {
                $shared = count(array_intersect($around, $neighbours($candidate)));
                $key = [-$shared, $priority[$candidate] ?? self::DEFAULT_PRIORITY, (int) $nodes[$candidate]['device_id']];
                if ($best === null || $key < $best[0]) {
                    $best = [$key, $candidate];
                    $bestShared = $shared;
                }
            }
            // cabled like a core switch: at least two, and half of the core device's other switches, have a link to it
            $lookalike = $best !== null && $bestShared >= 2 && $bestShared * 2 >= count($around) - 1 ? $best[1] : null;
            $result[] = [
                'core' => (int) $nodes[$core]['device_id'],
                'lookalike' => $lookalike !== null ? (int) $nodes[$lookalike]['device_id'] : null,
                'shared' => $lookalike !== null ? $bestShared : 0,
            ];
        }

        return $result;
    }
}
