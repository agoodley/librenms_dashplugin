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
     * Root bridges per switch and VLAN: a root bridge fact holds the VLANs it is the root of, so a
     * VLAN whose root moved is missing from its old fact and new in another one, while the other
     * VLANs of both facts are as expected.
     *
     * @param  array<string, array>  $baseline  hash => saved root bridge fact
     * @param  array<string, array>  $current  hash => root bridge fact now
     * @return array{missing: array<int, array>, new: array<int, array>} facts limited to the VLANs that differ
     */
    public static function compareRoots(array $baseline, array $current): array
    {
        return [
            'missing' => self::differingVlans($baseline, self::rootsByVlan($current)),
            'new' => self::differingVlans($current, self::rootsByVlan($baseline)),
        ];
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
     * @return array<int, array<int, string>> device => VLAN key => root bridge MAC
     */
    private static function rootsByVlan(array $facts): array
    {
        $roots = [];
        foreach ($facts as $fact) {
            foreach (FactText::vlans($fact['data']) as $vlan) {
                $roots[(int) $fact['data']['device']][$vlan] = $fact['data']['root'];
            }
        }

        return $roots;
    }

    /**
     * The facts limited to their VLANs whose root bridge is another one in $other.
     *
     * @param  array<string, array>  $facts  root bridge facts
     * @param  array<int, array<int, string>>  $other  device => VLAN key => root bridge MAC
     * @return array<int, array>
     */
    private static function differingVlans(array $facts, array $other): array
    {
        $result = [];
        foreach ($facts as $fact) {
            $device = (int) $fact['data']['device'];
            $vlans = array_values(array_filter(FactText::vlans($fact['data']), fn ($vlan) => ($other[$device][$vlan] ?? null) !== $fact['data']['root']));
            if ($vlans) {
                $fact['data']['vlans'] = $vlans;
                $result[] = $fact;
            }
        }

        return $result;
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
