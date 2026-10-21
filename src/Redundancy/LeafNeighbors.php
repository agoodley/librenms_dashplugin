<?php

namespace Agoodley\LibrenmsTopology\Redundancy;

/**
 * Unmanaged neighbours seen on one port only (phones, PCs, access points) can only be cut off with the
 * device they hang off, so RedundancyAnalyzer counts them on that device (its leaves). This notes where
 * each unmanaged neighbour is seen while the links are read, so that a caller keeps the other rows only,
 * not those of a network's thousands of phones: keep() leaves the row of an unmanaged neighbour out, the
 * caller reads the rows that several() accepts again and passes leaves() to the analyzer.
 */
class LeafNeighbors
{
    /** @var array<string, true> neighbours whose rows are all kept */
    private array $kept = [];

    /** @var array<string, string> neighbour => "device:port" it was first seen on */
    private array $at = [];

    /** @var array<string, true> neighbours seen on several ports */
    private array $several = [];

    /**
     * @param  array[]  $links  rows the analyzer gets anyway (baseline links that are gone): their unmanaged
     *                          neighbours keep all their rows, as the analyzer looks at them together
     */
    public function __construct(array $links = [])
    {
        foreach ($links as $link) {
            if (empty($link['remote_device_id'])) {
                $this->kept[RedundancyAnalyzer::neighborKey($link)] = true;
            }
        }
    }

    /**
     * Is this links row needed as it is? Not the row of an unmanaged neighbour, until several() says so.
     */
    public function keep(array $link): bool
    {
        if (! empty($link['remote_device_id'])) {
            return true;
        }
        $key = RedundancyAnalyzer::neighborKey($link);
        if (isset($this->kept[$key])) {
            return true;
        }
        $place = (int) $link['local_device_id'] . ':' . (int) ($link['local_port_id'] ?? 0);
        if (($this->at[$key] ?? $place) !== $place) {
            $this->several[$key] = true;
        }
        $this->at[$key] ??= $place;

        return false;
    }

    /**
     * Is this a row keep() left out of a neighbour seen on several ports, which the analyzer needs after all?
     */
    public function several(array $link): bool
    {
        return empty($link['remote_device_id']) && isset($this->several[RedundancyAnalyzer::neighborKey($link)]);
    }

    public function hasSeveral(): bool
    {
        return $this->several !== [];
    }

    /**
     * @return array<int, int[]> device_id => ports of the unmanaged neighbours seen on that one port only
     */
    public function leaves(): array
    {
        $leaves = [];
        foreach (array_diff_key($this->at, $this->several) as $place) {
            [$deviceId, $portId] = array_map('intval', explode(':', $place));
            $leaves[$deviceId][] = $portId;
        }

        return $leaves;
    }
}
