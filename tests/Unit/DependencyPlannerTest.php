<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Redundancy\DependencyPlanner;
use PHPUnit\Framework\TestCase;

class DependencyPlannerTest extends TestCase
{
    private const C1 = RedundancyFixture::CORE1;
    private const C2 = RedundancyFixture::CORE2;
    private const D1 = RedundancyFixture::DIST1;
    private const D2 = RedundancyFixture::DIST2;
    private const A1 = RedundancyFixture::ACC1;
    private const A2 = RedundancyFixture::ACC2;
    private const AP = RedundancyFixture::AP1;

    private static function parents(RedundancyFixture $net, array $settings = []): array
    {
        return array_map(fn ($entry) => $entry['parents'], (new DependencyPlanner($net->analyzer($settings)))->derive());
    }

    public function testCampusDependsOnTheUplinks(): void
    {
        $derived = (new DependencyPlanner(RedundancyFixture::campus()->analyzer()))->derive();

        $this->assertSame(['status' => DependencyPlanner::CORE, 'parents' => [], 'hops' => 1], $derived[self::C1]);
        $this->assertSame([self::C1, self::C2], $derived[self::D1]['parents']);
        $this->assertSame([self::D1, self::D2], $derived[self::A2]['parents'], 'dual-homed: both uplinks');
        $this->assertSame(3, $derived[self::A2]['hops']);
        $this->assertSame([self::A1], $derived[self::AP]['parents']);
        $this->assertSame(DependencyPlanner::DERIVED, $derived[self::AP]['status']);
    }

    public function testDownLinksAndDevicesStillCount(): void
    {
        // dependencies describe the design: a link that is down now is still an uplink
        $net = RedundancyFixture::campus();
        $net->setPort(self::A1, 'Gi1/0/50', ['ifOperStatus' => 'down']);
        $net->devices[self::D1]['status'] = 0;

        $this->assertSame([self::D1, self::D2], self::parents($net)[self::A1]);
    }

    public function testUnmanagedSwitchesAreSkipped(): void
    {
        // acc3 hangs off an unmonitored switch on dist1, acc4 behind acc3
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->neighbor(self::D1, 'Gi1/0/3', 'old-sw')->neighbor(8, 'Gi1/0/49', 'old-sw');
        $net->device(9, 'acc4')->link(9, 'Gi1/0/49', 8, 'Gi1/0/48');
        $parents = self::parents($net);

        $this->assertSame([self::D1], $parents[8]);
        $this->assertSame([8], $parents[9]);
    }

    public function testOnlyCloserNeighborsAreParents(): void
    {
        // a ring of access switches: only the ends next to the distribution switches are closer
        $net = RedundancyFixture::campus();
        $net->device(8, 'ring1')->device(9, 'ring2')->device(10, 'ring3');
        $net->link(8, 'Gi1/0/49', self::D1, 'Gi1/0/8')->link(8, 'Gi1/0/50', 9, 'Gi1/0/49')
            ->link(9, 'Gi1/0/50', 10, 'Gi1/0/49')->link(10, 'Gi1/0/50', self::D2, 'Gi1/0/10');
        $parents = self::parents($net);

        $this->assertSame([self::D1], $parents[8]);
        $this->assertSame([8, 10], $parents[9]);
        $this->assertSame([self::D2], $parents[10]);
        $this->assertSame([], DependencyPlanner::loops($parents), 'parents are closer to the core: no loop');
    }

    public function testDualHomedServerAndLoneDevices(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(11, 'esx1', 'server')->link(11, 'vmnic0', self::A1, 'Gi1/0/2')->link(11, 'vmnic1', self::A2, 'Gi1/0/2');
        $net->device(12, 'lab-sw');
        $derived = (new DependencyPlanner($net->analyzer()))->derive();

        $this->assertSame([self::A1, self::A2], $derived[11]['parents']);
        $this->assertSame(['status' => DependencyPlanner::NONE, 'parents' => [], 'hops' => null], $derived[12]);
    }

    public function testCompareWithTheCurrentDependencies(): void
    {
        $derived = (new DependencyPlanner(RedundancyFixture::campus()->analyzer()))->derive();
        $current = [self::C1 => [9], self::D1 => [self::C2, self::C1], self::D2 => [self::C1], self::A1 => []];
        $rows = DependencyPlanner::compare($derived, $current, [self::C1, self::D1, self::D2, self::A1, 99]);

        $this->assertSame(['kept', 'same', 'changed', 'new', 'kept'], array_column($rows, 'change'));
        $this->assertSame([9], $rows[0]['current'], 'a core device keeps its parents');
        $this->assertSame([self::C1, self::C2], $rows[2]['derived']);
        $this->assertSame(DependencyPlanner::NONE, $rows[4]['status'], 'unknown device');
    }

    public function testLoops(): void
    {
        $this->assertSame([[1, 2, 3]], DependencyPlanner::loops([3 => [1], 1 => [2], 2 => [3], 4 => [1]]));
        $this->assertSame([[5]], DependencyPlanner::loops([5 => [5]]));
        $this->assertSame([], DependencyPlanner::loops([2 => [1], 3 => [1, 2]]));

        // applying derived parents next to a hand-made dependency that points the other way
        $after = DependencyPlanner::after([self::C1 => [self::D1]], [self::D1 => [self::C1, self::C2]]);
        $this->assertSame([[self::C1, self::D1]], DependencyPlanner::loops($after));
    }

    public function testExplainedOutages(): void
    {
        $outages = [
            self::A1 => [[1000, 2000], [10000, 10500], [50, 60]],
            self::D1 => [[900, 3000], [9000, 10200]],
            self::D2 => [[1250, 1500]], // went down within a poll of acc1
            self::A2 => [[20000, null]],
        ];
        $result = DependencyPlanner::explained([self::A1 => [self::D1, self::D2], self::A2 => [self::D1, self::D2]], $outages, 100);

        // acc1 at 1000: both uplinks down; at 10000 only dist1; the outage at 50 is before the window
        $this->assertSame(['outages' => 2, 'explained' => 1, 'last' => 1000], $result[self::A1]);
        $this->assertSame(['outages' => 1, 'explained' => 0, 'last' => null], $result[self::A2]);
        $this->assertSame(['outages' => 2, 'explained' => 0, 'last' => null], $result[self::D1], 'no parents, nothing explained');
    }
}
