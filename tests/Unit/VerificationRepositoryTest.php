<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Data\VerificationRepository;
use PHPUnit\Framework\TestCase;

/**
 * What the verification API reads of LibreNMS (Data\VerificationRepository), its rows in memory: how fresh a device's data
 * is (a discovery or a poll counts from when it started: LibreNMS records its end and how long it took; a rediscovery
 * asked for is pending), and the LLDP/CDP neighbors of devices in both directions (a link seen from one side counts).
 */
class VerificationRepositoryTest extends TestCase
{
    public function testADiscoveryAndAPollCountFromWhenTheyStarted(): void
    {
        $row = (object) ['device_id' => 3, 'status' => 1, 'last_discovered' => '2026-10-22 10:00:30', 'last_discovered_timetaken' => 30.4,
            'last_polled' => '2026-10-22 10:05:12', 'last_polled_timetaken' => '12'];

        $state = VerificationRepository::stateOf($row);

        $this->assertSame(['up' => true, 'down_since' => null, 'discovered_at' => strtotime('2026-10-22 10:00:30') - 31, 'polled_at' => strtotime('2026-10-22 10:05:00'),
            'discovery_pending' => false], $state, 'a discovery of 30.4 s that ended at 10:00:30 started at 09:59:59');
    }

    public function testARediscoveryAskedForIsPendingAndADeviceDownIsNotUp(): void
    {
        // LibreNMS' Rediscover empties last_discovered; a device down keeps its last poll
        $pending = VerificationRepository::stateOf((object) ['device_id' => 4, 'status' => 0, 'last_discovered' => null, 'last_discovered_timetaken' => 0,
            'last_polled' => '2026-10-22 10:05:12', 'last_polled_timetaken' => 2]);

        $this->assertSame([false, null, true], [$pending['up'], $pending['discovered_at'], $pending['discovery_pending']]);
        $this->assertNull(VerificationRepository::stateOf((object) ['device_id' => 5, 'status' => 1, 'last_discovered' => '', 'last_discovered_timetaken' => 0,
            'last_polled' => null, 'last_polled_timetaken' => null])['polled_at'], 'never polled');
    }

    public function testTheStatesOfDevicesHaveTheOutageOfThoseDown(): void
    {
        $repo = new MemoryVerificationRows([[3, 1, '2026-10-22 10:00:30', 30, '2026-10-22 10:05:12', 12], [4, 0, '2026-10-22 09:00:00', 20, '2026-10-22 09:30:00', 5]],
            [], [4 => 1_792_000_000]);

        $states = $repo->deviceStates([3, 4, 9]);

        $this->assertSame([3, 4], array_keys($states), 'a device LibreNMS does not have is not there');
        $this->assertSame([null, 1_792_000_000], [$states[3]['down_since'], $states[4]['down_since']]);
    }

    public function testTheNeighborsOfDevicesAreTheFarEndsTheyReportAndTheDevicesThatReportThem(): void
    {
        // 3 reports 2; 5 reports 3 (3 does not report it); 3 reports 7, which the user may not see
        $repo = new MemoryVerificationRows([], [[3, 2], [5, 3], [3, 7], [8, 9]], [], [2, 3, 5, 8, 9]);

        $this->assertSame([2, 5], $repo->neighborsOf([3]));
        $this->assertSame([3], $repo->neighborsOf([2]), 'from the far end too');
        $this->assertSame([], $repo->neighborsOf([7]), 'a device the user may not see has none');
    }
}

/**
 * The verification repository on rows in memory: devices rows (id, status, last_discovered, its time taken, last_polled,
 * its time taken), LLDP/CDP links (local device, remote device), current outages; every device the test lists is visible.
 */
final class MemoryVerificationRows extends VerificationRepository
{
    /**
     * @param  list<array>  $devices
     * @param  list<array{int, int}>  $links
     * @param  array<int, int>  $outages  device_id => when its current outage began
     * @param  int[]|null  $visible  the devices the user may see (null: those of $devices)
     */
    public function __construct(private readonly array $devices, private readonly array $links, private readonly array $outages, private readonly ?array $visible = null)
    {
        parent::__construct(null);
    }

    public function visibleOf(array $ids): array
    {
        $visible = array_flip($this->visible ?? array_column($this->devices, 0));

        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => isset($visible[$id]))));
    }

    public function allowsTable(string $table): bool
    {
        return true;
    }

    protected function deviceRows(array $ids): iterable
    {
        foreach ($this->devices as [$id, $status, $discovered, $discoveryTook, $polled, $pollTook]) {
            if (in_array($id, $ids, true)) {
                yield (object) ['device_id' => $id, 'status' => $status, 'last_discovered' => $discovered, 'last_discovered_timetaken' => $discoveryTook,
                    'last_polled' => $polled, 'last_polled_timetaken' => $pollTook];
            }
        }
    }

    protected function outageStarts(array $ids): array
    {
        return array_intersect_key($this->outages, array_flip($ids));
    }

    protected function reportedBy(array $ids): iterable
    {
        return array_values(array_unique(array_map(fn (array $link) => $link[1], array_filter($this->links, fn (array $link) => in_array($link[0], $ids, true)))));
    }

    protected function reporting(array $ids): iterable
    {
        return array_values(array_unique(array_map(fn (array $link) => $link[0], array_filter($this->links, fn (array $link) => in_array($link[1], $ids, true)))));
    }
}
