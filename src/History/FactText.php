<?php

namespace Agoodley\LibrenmsTopology\History;

use Agoodley\LibrenmsTopology\Stp\StpIndex;

/**
 * Wording of facts for the Changes page. The history is recorded for every device, so names of
 * devices the user may not see are replaced (by the name their neighbor's LLDP/CDP reports, as on
 * the L2 map, else "another device").
 */
class FactText
{
    /** Groups of VLANs (by their number of switches) a change of root bridges names one by one. */
    private const VLAN_GROUPS = 6;

    /**
     * @param  array<int, string>  $names  device_id => current name of the devices the user may see
     * @param  array<int, true>|null  $visible  devices the user may see, null for all
     */
    public function __construct(private readonly array $names, private readonly ?array $visible = null)
    {
    }

    public function visible(?int $deviceId): bool
    {
        return $deviceId !== null && ($this->visible === null || isset($this->visible[$deviceId]));
    }

    /**
     * Name of a device: its current name when the user may see it, else $masked.
     */
    public function device(?int $deviceId, ?string $stored, string $masked = 'another device'): string
    {
        if ($deviceId === null) {
            return $stored ?: $masked;
        }

        return $this->visible($deviceId) ? ($this->names[$deviceId] ?? $stored ?? "device #$deviceId") : $masked;
    }

    /**
     * "core1, core2 and 7 more" for the devices the user may see (the others are not counted).
     *
     * @param  int[]  $devices
     */
    public function deviceList(array $devices, int $max = 5): string
    {
        $names = [];
        foreach ($devices as $device) {
            if ($this->visible($device)) {
                $names[] = $this->device($device, null);
            }
        }
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $more = count($names) - $max;

        return implode(', ', array_slice($names, 0, $max)) . ($more > 0 ? ' and ' . number_format($more) . ' more' : '');
    }

    /**
     * One line for a change of root bridges against the baseline (Baseline::rootChanges()).
     */
    public function rootChange(array $change): string
    {
        $vlans = self::changeVlans($change);
        $on = $this->deviceList($change['devices']);

        return match (true) {
            $change['from'] !== null && $change['to'] !== null => "$vlans: root bridge " . $this->root($change['from']) . ' → ' . $this->root($change['to']) . " on $on",
            $change['from'] !== null => "$vlans: root bridge " . $this->root($change['from']) . " no longer reported on $on",
            default => "$vlans: root bridge " . $this->root($change['to']) . " on $on",
        };
    }

    /**
     * VLANs of a change of root bridges: "VLAN 31" when each of its switches has them all, else with
     * the number of switches of each ("VLAN 31 on 1,242 switches, VLAN 33 on 1 switch", VLANs with
     * the same number together): one Accept takes them all.
     */
    public static function changeVlans(array $change): string
    {
        $switches = [];
        foreach ($change['device_vlans'] ?? [] as $vlans) {
            foreach ($vlans as $vlan) {
                $switches[$vlan] = ($switches[$vlan] ?? 0) + 1;
            }
        }
        if (! $switches || min($switches) === count($change['devices'])) {
            return self::vlanList($change['vlans']);
        }

        ksort($switches);
        $groups = [];
        foreach ($switches as $vlan => $count) {
            $groups[$count][] = $vlan;
        }
        $parts = [];
        foreach (array_slice($groups, 0, self::VLAN_GROUPS, true) as $count => $vlans) {
            $parts[] = self::vlanList($vlans) . ' on ' . number_format($count) . ($count === 1 ? ' switch' : ' switches') . (count($vlans) > 1 ? ' each' : '');
        }
        $more = count(array_merge([], ...array_values(array_slice($groups, self::VLAN_GROUPS, null, true))));

        return implode(', ', $parts) . ($more > 0 ? ' and ' . $more . ($more === 1 ? ' more VLAN' : ' more VLANs') : '');
    }

    /**
     * "acc-sw1 Gi1/0/49" for one end of a link fact.
     */
    public function linkEnd(array $end): string
    {
        $device = isset($end['device'])
            ? $this->device((int) $end['device'], $end['name'] ?? null, $end['host'] ?? 'another device')
            : ($end['name'] ?? 'unknown neighbor');

        return trim($device . ' ' . ($end['port_name'] ?? ''));
    }

    /**
     * One line describing a fact, as in the baseline tables.
     */
    public function describe(array $fact): string
    {
        $data = $fact['data'];

        return match ($fact['kind']) {
            FactCollector::LINK => $this->linkEnd($data['a']) . ' ↔ ' . $this->linkEnd($data['b']),
            FactCollector::LAG => $this->device($data['device'], $data['device_name']) . ' ' . $data['aggregate_name'] . ': member ' . $data['member_name'],
            FactCollector::STP_ROOT => $this->device($data['device'], $data['device_name']) . ': root bridge ' . $this->root($data) . ' in ' . self::vlanList(self::vlans($data)),
            FactCollector::STP_BLOCKED => $this->device($data['device'], $data['device_name']) . ' ' . $data['port_name'] . ' blocking in ' . self::vlanList(self::vlans($data)),
            FactCollector::OSPF => 'OSPF ' . $this->ospf($data),
            FactCollector::BGP => 'BGP ' . $this->bgp($data),
            default => $fact['key'] ?? $fact['fact_key'] ?? '',
        };
    }

    /**
     * Root bridge of a stp_root fact: "core1 (00:ab:01:00:00:01)", or the MAC alone.
     */
    public function root(array $data): string
    {
        $mac = self::mac((string) $data['root']);
        if (($data['root_device'] ?? null) !== null && $this->visible((int) $data['root_device'])) {
            return $this->device((int) $data['root_device'], $data['root_name'] ?? null) . " ($mac)";
        }

        return $mac;
    }

    /**
     * "core1 Gi1/0/1 ↔ core2 (10.0.0.2)" for an adjacency fact.
     */
    public function ospf(array $data): string
    {
        $local = $this->visible((int) $data['device'])
            ? trim($this->device($data['device'], $data['device_name']) . ' ' . ($data['port_name'] ?? ''))
            : 'another device';
        $neighbor = ($data['neighbor'] ?? null) !== null && $this->visible((int) $data['neighbor'])
            ? $this->device((int) $data['neighbor'], $data['neighbor_name'] ?? null) . ' (' . $data['ip'] . ')'
            : 'router ' . $data['router_id'] . ($data['ip'] !== $data['router_id'] ? ' (' . $data['ip'] . ')' : '');

        return $local . ' ↔ ' . $neighbor . ($data['context'] !== '' ? ' [' . $data['context'] . ']' : '');
    }

    /**
     * "core1 → 192.0.2.1 (AS 65001, ISP-A)" for a session fact.
     */
    public function bgp(array $data): string
    {
        $peer = ($data['neighbor'] ?? null) !== null && $this->visible((int) $data['neighbor'])
            ? $this->device((int) $data['neighbor'], $data['neighbor_name'] ?? null) . ' ' . $data['peer']
            : $data['peer'];
        $details = array_filter(['AS ' . $data['remote_as'], $data['description'] ?? '']);

        return $this->device($data['device'], $data['device_name']) . ' → ' . $peer . ' (' . implode(', ', $details) . ')'
            . ($data['context'] !== '' ? ' [' . $data['context'] . ']' : '');
    }

    /**
     * Spanning tree instances (VLAN keys) of a spanning tree fact.
     *
     * @return int[]
     */
    public static function vlans(array $data): array
    {
        return array_map('intval', $data['vlans'] ?? [$data['vlan'] ?? 0]);
    }

    /**
     * "VLAN 1 / CST", "VLAN 10, VLAN 20" or, for more than three, "VLANs 1-29, 31-50".
     *
     * @param  int[]  $vlans  VLAN keys (StpIndex::vlanKey(), 0 for VLAN 1 / CST)
     */
    public static function vlanList(array $vlans): string
    {
        $vlans = array_values(array_unique($vlans));
        sort($vlans);
        if (count($vlans) <= 3) {
            return implode(', ', array_map(fn ($vlan) => StpIndex::vlanLabel($vlan), $vlans));
        }

        $ranges = [];
        foreach (array_map(fn ($vlan) => max(1, $vlan), $vlans) as $vlan) {
            $last = count($ranges) - 1;
            if ($last >= 0 && $ranges[$last][1] === $vlan - 1) {
                $ranges[$last][1] = $vlan;
            } else {
                $ranges[] = [$vlan, $vlan];
            }
        }

        return 'VLANs ' . implode(', ', array_map(fn ($range) => $range[0] === $range[1] ? (string) $range[0] : $range[0] . '-' . $range[1], $ranges));
    }

    /**
     * 12 hex characters as aa:bb:cc:dd:ee:ff.
     */
    public static function mac(string $hex): string
    {
        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : $hex;
    }
}
