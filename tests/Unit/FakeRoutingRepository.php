<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Data\RoutingRepository;

/**
 * RoutingRepository over the rows of a RoutingFixture instead of the database, with the same
 * filters as its queries, for testing RoutingService without LibreNMS. Counts the address rows it
 * hands out.
 */
class FakeRoutingRepository extends RoutingRepository
{
    public int $addressRows = 0;

    public function __construct(private readonly RoutingFixture $net)
    {
        parent::__construct(null);
    }

    public function deviceIds(?int $groupId = null, ?int $locationId = null): array
    {
        return array_keys($this->net->devices);
    }

    public function scope(array $p): array
    {
        return [$this->deviceIds(), ($p['device'] ?? null) ?: null];
    }

    public function devices(array $ids): array
    {
        return array_intersect_key($this->net->devices, array_flip($ids));
    }

    public function ospf(array $ids): array
    {
        return array_map(fn ($rows) => $this->of($rows, $ids), $this->net->ospf);
    }

    public function bgp(array $ids): array
    {
        return array_map(fn ($rows) => $this->of($rows, $ids), $this->net->bgp);
    }

    public function addresses(array $ids): \Generator
    {
        foreach ($this->of($this->net->addresses, $ids) as $row) {
            $this->addressRows++;
            yield $row;
        }
    }

    public function devicesWithAddresses(array $addresses, array $ids): array
    {
        $wanted = array_flip($addresses);
        $devices = [];
        foreach ($this->of($this->net->addresses, $ids) as $row) {
            if (isset($wanted[$row['address']])) {
                $devices[$row['device_id']] = true;
            }
        }

        return array_keys($devices);
    }

    public function vrfs(array $ids): array
    {
        return array_intersect_key($this->net->vrfs, array_flip($ids));
    }

    public function links(array $ids): \Generator
    {
        $ids = array_flip($ids);
        foreach ($this->net->links as $link) {
            if (isset($ids[$link['local_device_id']], $ids[$link['remote_device_id']])) {
                yield $link;
            }
        }
    }

    /**
     * @param  int[]  $ids
     */
    private function of(array $rows, array $ids): \Generator
    {
        $ids = array_flip($ids);
        foreach ($rows as $row) {
            if (isset($ids[$row['device_id']])) {
                yield $row;
            }
        }
    }
}
