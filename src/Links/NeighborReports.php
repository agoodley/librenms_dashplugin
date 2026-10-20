<?php

namespace Agoodley\LibrenmsTopology\Links;

/**
 * Who reports whom in the LLDP/CDP neighbour tables LibreNMS collected (rows of the links
 * table): the devices that report neighbours at all, the monitored neighbour each one reports on
 * each of its ports, and the monitored devices that see a device. Shared by the link diagnostics
 * (links reported by one end only) and the coverage report (devices that report no neighbours).
 * Pure PHP.
 */
class NeighborReports
{
    /** @var array<int, true> devices with at least one LLDP/CDP neighbour */
    private array $reporting = [];

    /** @var array<int, array<int, array<int, true>>> device => local port => monitored neighbour */
    private array $reports = [];

    /** @var array<int, array<string, array{device_id: int, port_id: ?int, remote_port_id: ?int}>> device => sightings by others */
    private array $seenBy = [];

    /**
     * @param  iterable<array>  $links  links rows (local_device_id, local_port_id, remote_device_id, remote_port_id)
     * @param  array<int, mixed>  $devices  monitored devices, keyed by device_id: rows of other devices are ignored
     *                                      and neighbours outside count as unmanaged
     */
    public function __construct(iterable $links, private array $devices)
    {
        foreach ($links as $link) {
            $this->add($link);
        }
    }

    /**
     * Take one more links row into account (for callers that stream the rows).
     */
    public function add(array $link): void
    {
        $ld = (int) $link['local_device_id'];
        $lp = (int) ($link['local_port_id'] ?? 0);
        $rd = (int) ($link['remote_device_id'] ?? 0);
        if (! isset($this->devices[$ld]) || $rd === $ld) {
            return;
        }

        $this->reporting[$ld] = true;
        if (! $rd || ! isset($this->devices[$rd])) {
            return;
        }
        if ($lp) {
            $this->reports[$ld][$lp][$rd] = true;
        }
        $rp = (int) ($link['remote_port_id'] ?? 0) ?: null;
        $this->seenBy[$rd][$ld . '#' . $lp . '#' . $rp] = ['device_id' => $ld, 'port_id' => $lp ?: null, 'remote_port_id' => $rp];
    }

    /**
     * Does the device report any LLDP/CDP neighbour (monitored or not)?
     */
    public function reportsNeighbors(int $deviceId): bool
    {
        return isset($this->reporting[$deviceId]);
    }

    /**
     * Does the device list this monitored neighbour on this port?
     */
    public function reports(int $deviceId, int $portId, int $neighborId): bool
    {
        return isset($this->reports[$deviceId][$portId][$neighborId]);
    }

    /**
     * Monitored devices that list this device as their neighbour, once per port pair: their device and
     * port, and the device's own port when LibreNMS could resolve it.
     *
     * @return array<int, array{device_id: int, port_id: ?int, remote_port_id: ?int}>
     */
    public function seenBy(int $deviceId): array
    {
        return array_values($this->seenBy[$deviceId] ?? []);
    }
}
