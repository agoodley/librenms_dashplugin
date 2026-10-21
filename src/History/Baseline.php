<?php

namespace Agoodley\LibrenmsTopology\History;

/**
 * The saved baseline: the topology an admin declared as expected. Blocked ports are left out,
 * they follow from the links and the spanning tree settings.
 */
class Baseline
{
    public const KINDS = [FactCollector::LINK, FactCollector::LAG, FactCollector::STP_ROOT, FactCollector::OSPF, FactCollector::BGP];

    /**
     * What differs between the baseline and the topology now.
     *
     * @param  array<string, array>  $baseline  hash => saved fact
     * @param  array<string, array>  $current  hash => fact now
     * @return array{missing: array<string, array>, new: array<string, array>}
     */
    public static function compare(array $baseline, array $current): array
    {
        $kinds = array_flip(self::KINDS);
        $current = array_filter($current, fn ($fact) => isset($kinds[$fact['kind']]));

        return [
            'missing' => array_diff_key($baseline, $current),
            'new' => array_diff_key($current, $baseline),
        ];
    }

    /**
     * Root bridges against the baseline per switch and VLAN, grouped like the Changes page: one
     * change per old root bridge and new root bridge, with the switches that see it and the VLANs
     * of each (a move of the user VLANs from one core to the other is one change, whichever of them
     * each access switch carries). from / to are the root bridge (root MAC, root_device, root_name)
     * of the baseline and of now; null for an instance that is gone or new. vlans is the union of
     * the switches' VLANs.
     *
     * @param  array<string, array>  $baseline  hash => saved root bridge fact
     * @param  array<string, array>  $current  hash => root bridge fact now
     * @return array<int, array{from: ?array, to: ?array, vlans: int[], devices: int[], device_vlans: array<int, int[]>}>
     */
    public static function rootChanges(array $baseline, array $current): array
    {
        $before = self::rootsByVlan($baseline);
        $after = self::rootsByVlan($current);

        $changes = [];
        foreach (array_keys($before + $after) as $device) {
            $was = $before[$device] ?? [];
            $now = $after[$device] ?? [];
            foreach (array_keys($was + $now) as $vlan) {
                $old = $was[$vlan] ?? null;
                $new = $now[$vlan] ?? null;
                if ($old !== null && $new !== null && $old['root'] === $new['root']) {
                    continue;
                }
                $pair = ($old['root'] ?? '') . '>' . ($new['root'] ?? '');
                $changes[$pair] ??= ['from' => $old, 'to' => $new, 'vlans' => [], 'devices' => [], 'device_vlans' => []];
                $changes[$pair]['device_vlans'][(int) $device][] = (int) $vlan;
                $changes[$pair]['vlans'][$vlan] = (int) $vlan;
            }
        }
        foreach ($changes as $pair => $change) {
            ksort($change['device_vlans']);
            foreach ($change['device_vlans'] as $device => $vlans) {
                sort($vlans);
                $change['device_vlans'][$device] = $vlans;
            }
            sort($change['vlans']);
            $change['devices'] = array_keys($change['device_vlans']);
            $changes[$pair] = $change;
        }

        return array_values($changes);
    }

    /**
     * The baseline after accepting a change of root bridges (rootChanges()) on its switches: the
     * VLANs of each switch leave the old root bridge's fact (which goes with its last VLAN) and join
     * the new one's, taken from the fact now when the baseline has none.
     *
     * @param  array<string, array>  $baseline  hash => saved root bridge facts of the change's switches
     * @param  array<string, array>  $current  hash => root bridge facts now of the change's switches
     * @param  array{from: ?array, to: ?array, vlans: int[], devices: int[], device_vlans?: array<int, int[]>}  $change
     * @return array{update: array<string, array>, delete: string[], insert: array<string, array>} hash => fact data, or fact
     */
    public static function acceptRootChange(array $baseline, array $current, array $change): array
    {
        $result = ['update' => [], 'delete' => [], 'insert' => []];
        foreach ($change['devices'] as $device) {
            $moved = $change['device_vlans'][$device] ?? $change['vlans'];
            if ($change['from'] !== null) {
                $hash = FactCollector::hash(FactCollector::STP_ROOT, "d$device|{$change['from']['root']}");
                if (isset($baseline[$hash])) {
                    $left = array_values(array_diff(FactText::vlans($baseline[$hash]['data']), $moved));
                    if ($left) {
                        $result['update'][$hash] = ['vlans' => $left] + $baseline[$hash]['data'];
                    } else {
                        $result['delete'][] = $hash;
                    }
                }
            }
            if ($change['to'] !== null) {
                $hash = FactCollector::hash(FactCollector::STP_ROOT, "d$device|{$change['to']['root']}");
                if (isset($baseline[$hash])) {
                    $vlans = array_values(array_unique(array_merge(FactText::vlans($baseline[$hash]['data']), $moved)));
                    sort($vlans);
                    $result['update'][$hash] = ['vlans' => $vlans] + $baseline[$hash]['data'];
                } elseif (isset($current[$hash])) {
                    $result['insert'][$hash] = ['data' => ['vlans' => $moved] + $current[$hash]['data']] + $current[$hash];
                }
            }
        }

        return $result;
    }

    /**
     * VLAN keys as a form sends them ("30,31"), null when the value is anything else.
     *
     * @return int[]|null
     */
    public static function parseVlans(mixed $value): ?array
    {
        if (! is_string($value) || ! preg_match('/^\d{1,4}(,\d{1,4})*$/', $value)) {
            return null;
        }

        return array_map('intval', explode(',', $value));
    }

    /**
     * Baseline links that are gone, leaving out those whose port now has another cable: a port
     * holds one cable, so that is a moved cable (the Changes page reports it), not a second path.
     *
     * @param  array<string, array>  $baseline  hash => baseline link fact
     * @param  array<string, array>  $current  hash => link fact now
     * @return array<string, array>
     */
    public static function goneLinks(array $baseline, array $current): array
    {
        $cabled = [];
        foreach ($current as $fact) {
            foreach (self::monitoredEnds($fact['key'] ?? $fact['fact_key']) as $end) {
                $cabled[$end] = true;
            }
        }

        return array_filter(array_diff_key($baseline, $current), function (array $fact) use ($cabled) {
            foreach (self::monitoredEnds($fact['key'] ?? $fact['fact_key']) as $end) {
                if (isset($cabled[$end])) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Links rows (as TopologyRepository::links() returns them) for baseline links that LLDP/CDP
     * no longer reports, flagged `missing` so the redundancy analysis treats them as down links.
     * Links with a monitored end outside $devices are left out: the analysis only knows those
     * devices, and their links are already there as unmanaged neighbors.
     *
     * @param  array<string, array>  $missing  hash => baseline link fact
     * @param  array<int, mixed>  $devices  device_id => anything, the devices of the analysis
     * @param  array<string, string>  $since  hash => since when the link is gone (date), if known
     * @return array<int, array>
     */
    public static function linkRows(array $missing, array $devices, array $since = []): array
    {
        $rows = [];
        foreach ($missing as $hash => $fact) {
            if ($fact['kind'] !== FactCollector::LINK) {
                continue;
            }
            $a = $fact['data']['a'];
            $b = $fact['data']['b'];
            if (! isset($a['device']) || ! isset($devices[(int) $a['device']]) || (isset($b['device']) && ! isset($devices[(int) $b['device']]))) {
                continue;
            }
            $rows[] = [
                'id' => null,
                'local_device_id' => (int) $a['device'],
                'local_port_id' => isset($a['port']) ? (int) $a['port'] : 0,
                'remote_device_id' => isset($b['device']) ? (int) $b['device'] : 0,
                'remote_port_id' => isset($b['port']) ? (int) $b['port'] : null,
                'remote_hostname' => isset($b['device']) ? ($b['host'] ?? '') : ($b['name'] ?? ''),
                'remote_port' => $b['port_name'] ?? '',
                'remote_platform' => $b['platform'] ?? '',
                'remote_version' => '',
                'protocol' => 'baseline',
                'missing' => $since[$hash] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array>  $facts  root bridge facts
     * @return array<int, array<int, array>> device => VLAN key => root bridge (root, root_device, root_name)
     */
    private static function rootsByVlan(array $facts): array
    {
        $roots = [];
        foreach ($facts as $fact) {
            $root = ['root' => $fact['data']['root'], 'root_device' => $fact['data']['root_device'] ?? null, 'root_name' => $fact['data']['root_name'] ?? null];
            foreach (FactText::vlans($fact['data']) as $vlan) {
                $roots[(int) $fact['data']['device']][$vlan] = $root;
            }
        }

        return $roots;
    }

    /**
     * Ends of a link fact on monitored devices with a known port ("d5:gi1/0/49").
     *
     * @return string[]
     */
    private static function monitoredEnds(string $key): array
    {
        return array_filter(explode('|', $key), fn ($end) => str_starts_with($end, 'd') && ! str_ends_with($end, ':'));
    }
}
