<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * Is LibreNMS' data of a batch's scope fresh since a time (the batch's end plus the settle time, or the request for a
 * refresh before the batch)? Pure: it reads how fresh each device's data is (the topology plugin's verification API:
 * when its last discovery and its last poll started, from LibreNMS' last_discovered and last_polled minus the time they
 * took, as LibreNMS records them at their end and only for a device that is up).
 *
 * The devices whose facts a state records (the batch's devices, what it cuts off, their LLDP/CDP neighbors) need a
 * discovery and a poll that started after that time: LLDP/CDP links, port-channels and spanning tree port states change
 * in LibreNMS at a discovery. The other spanning tree switches of the domain need a poll after it: their root bridge
 * changes at a poll. A device that is down needs neither: one down since before the batch is left out (nothing of it
 * can be fresh), one that went down after it is a change of its own (the comparison says it went down).
 */
final class Freshness
{
    /**
     * @param  array<int, array{up?: bool, down_since?: ?int, discovered_at?: ?int, polled_at?: ?int, discovery_pending?: bool}>  $states  device => how
     *                                                                                                                                    fresh its data is now
     * @param  int[]  $covered  the devices that need a discovery and a poll
     * @param  int[]  $domain  the devices that need a poll
     * @param  int  $since  the time the data must be newer than (it started after it)
     * @param  int  $downBefore  a device down since before this time (the batch's start) is left out
     * @return array{fresh: bool, waiting: array<int, string>, left_out: int[], down: int[], unknown: int[]} waiting: device => what it waits for
     *     ("a discovery", "a poll", "a discovery and a poll"); left_out: down since before the batch; down: went down since; unknown: devices
     *     the API no longer tells about (not visible, removed), left out too
     */
    public static function check(array $states, array $covered, array $domain, int $since, int $downBefore): array
    {
        $result = ['fresh' => true, 'waiting' => [], 'left_out' => [], 'down' => [], 'unknown' => []];
        $needs = [];
        foreach ($covered as $deviceId) {
            $needs[(int) $deviceId] = true;
        }
        foreach ($domain as $deviceId) {
            $needs[(int) $deviceId] ??= false;
        }
        foreach ($needs as $deviceId => $discovery) {
            $state = $states[$deviceId] ?? null;
            if ($state === null) {
                $result['unknown'][] = $deviceId;

                continue;
            }
            if (empty($state['up'])) {
                $downSince = isset($state['down_since']) ? (int) $state['down_since'] : null;
                if ($downSince !== null && $downSince < $downBefore) {
                    $result['left_out'][] = $deviceId;
                } else {
                    $result['down'][] = $deviceId;
                }

                continue;
            }
            $missing = [];
            if ($discovery && (! empty($state['discovery_pending']) || ($state['discovered_at'] ?? null) === null || (int) $state['discovered_at'] < $since)) {
                $missing[] = 'a discovery';
            }
            if (($state['polled_at'] ?? null) === null || (int) $state['polled_at'] < $since) {
                $missing[] = 'a poll';
            }
            if ($missing) {
                $result['waiting'][$deviceId] = implode(' and ', $missing);
            }
        }
        $result['fresh'] = ! $result['waiting'];
        sort($result['left_out']);
        sort($result['down']);
        sort($result['unknown']);

        return $result;
    }

    /**
     * What a scope still waits for, in words: "lab-sw4 (a discovery and a poll), lab-sw2 (a poll) and 3 more".
     *
     * @param  array<int, string>  $waiting  device => what it waits for
     * @param  array<int, string>  $names
     */
    public static function waitingText(array $waiting, array $names, int $max = 5): string
    {
        $parts = [];
        foreach (array_slice($waiting, 0, $max, true) as $deviceId => $what) {
            $parts[] = \Agoodley\LibrenmsAnsible\Inventory\UntrustedText::line((string) (($names[$deviceId] ?? '') ?: "device #$deviceId"), 80) . " ($what)";
        }

        return implode(', ', $parts) . (count($waiting) > $max ? ' and ' . (count($waiting) - $max) . ' more' : '');
    }
}
