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
            FactCollector::STP_ROOT => $this->device($data['device'], $data['device_name']) . ': root bridge ' . $this->root($data) . ' in ' . StpIndex::vlanLabel((int) $data['vlan']),
            FactCollector::STP_BLOCKED => $this->device($data['device'], $data['device_name']) . ' ' . $data['port_name'] . ' blocking in ' . StpIndex::vlanLabel((int) $data['vlan']),
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
     * 12 hex characters as aa:bb:cc:dd:ee:ff.
     */
    public static function mac(string $hex): string
    {
        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : $hex;
    }
}
