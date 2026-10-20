<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Redundancy\CoreAdvice;
use Agoodley\LibrenmsTopology\Redundancy\DependencyPlanner;
use Agoodley\LibrenmsTopology\Topology\PlanningService;
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

    private static function parents(RedundancyFixture $net, array $settings = [], array $stpPriority = []): array
    {
        return array_map(fn ($entry) => $entry['parents'], (new DependencyPlanner($net->analyzer($settings, null, [], [], $stpPriority)))->derive());
    }

    /**
     * core1 - core2, dist1 with an uplink to core1 and dist2 with one to core2, and a cable between
     * the two distribution switches.
     */
    private static function square(): RedundancyFixture
    {
        $net = new RedundancyFixture;
        $net->device(self::C1, 'core1')->device(self::C2, 'core2')->device(self::D1, 'dist1')->device(self::D2, 'dist2');
        $net->link(self::C1, 'Te1/1/1', self::C2, 'Te1/1/1')->link(self::D1, 'Te1/1/1', self::C1, 'Te1/0/1')
            ->link(self::D2, 'Te1/1/1', self::C2, 'Te1/0/1')->link(self::D1, 'Te1/1/2', self::D2, 'Te1/1/2');

        return $net;
    }

    public function testCampusDependsOnTheUplinks(): void
    {
        $derived = (new DependencyPlanner(RedundancyFixture::campus()->analyzer()))->derive();

        $this->assertSame(['status' => DependencyPlanner::CORE, 'parents' => [], 'hops' => 0], $derived[self::C1]);
        $this->assertSame([self::C1, self::C2], $derived[self::D1]['parents']);
        $this->assertSame(1, $derived[self::D1]['hops'], 'hops from the core devices');
        $this->assertSame([self::D1, self::D2], $derived[self::A2]['parents'], 'dual-homed: both uplinks');
        $this->assertSame(2, $derived[self::A2]['hops']);
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

    public function testSquareGetsTheSwitchNextToItAtTheSameDistance(): void
    {
        // dist1 ranks first (same priority, as central, lower device id): dist2 gets it as its second parent
        $parents = self::parents(self::square());
        $this->assertSame([self::C1], $parents[self::D1]);
        $this->assertSame([self::C2, self::D1], $parents[self::D2]);
        $this->assertSame([], DependencyPlanner::loops($parents));

        // a lower bridge priority ranks first (the VLAN bits of PVST priorities do not count)
        $parents = self::parents(self::square(), [], [self::D1 => 32769, self::D2 => 28673]);
        $this->assertSame([self::C1, self::D2], $parents[self::D1]);
        $this->assertSame([self::C2], $parents[self::D2]);
        $this->assertSame([self::C2, self::D1], self::parents(self::square(), [], [self::D1 => 32769, self::D2 => 32770])[self::D2]);

        // then the more central switch (fewer hops to the other switches): acc1 hangs off dist2
        $net = self::square();
        $net->device(self::A1, 'acc1')->link(self::A1, 'Gi1/0/49', self::D2, 'Gi1/0/1');
        $parents = self::parents($net);
        $this->assertSame([self::C1, self::D2], $parents[self::D1]);
        $this->assertSame([self::C2], $parents[self::D2]);
    }

    public function testOddRingDependsOnBothSides(): void
    {
        // three access switches from dist1 to dist2: the middle one is the farthest, with a parent on each side
        $net = RedundancyFixture::campus();
        $net->device(8, 'ring1')->device(9, 'ring2')->device(10, 'ring3');
        $net->link(8, 'Gi1/0/49', self::D1, 'Gi1/0/8')->link(8, 'Gi1/0/50', 9, 'Gi1/0/49')
            ->link(9, 'Gi1/0/50', 10, 'Gi1/0/49')->link(10, 'Gi1/0/50', self::D2, 'Gi1/0/10');
        $parents = self::parents($net);

        $this->assertSame([self::D1], $parents[8]);
        $this->assertSame([8, 10], $parents[9]);
        $this->assertSame([self::D2], $parents[10]);
        $this->assertSame([], DependencyPlanner::loops($parents), 'parents rank before their children: no loop');
    }

    public function testEvenRingPairDependsOnOneAnother(): void
    {
        // four access switches from dist1 to dist2: ring2 and ring3 are the farthest, at the same distance
        $net = RedundancyFixture::campus();
        $net->device(8, 'ring1')->device(9, 'ring2')->device(10, 'ring3')->device(11, 'ring4');
        $net->link(8, 'Gi1/0/49', self::D1, 'Gi1/0/8')->link(8, 'Gi1/0/50', 9, 'Gi1/0/49')->link(9, 'Gi1/0/50', 10, 'Gi1/0/49')
            ->link(10, 'Gi1/0/50', 11, 'Gi1/0/49')->link(11, 'Gi1/0/50', self::D2, 'Gi1/0/11');
        $derived = (new DependencyPlanner($net->analyzer()))->derive();

        $this->assertSame([8], $derived[9]['parents'], 'ranks first (as central, lower device id)');
        $this->assertSame([9, 11], $derived[10]['parents'], 'its own uplink and the switch next to it');
        $this->assertSame([3, 3], [$derived[9]['hops'], $derived[10]['hops']]);
        $this->assertSame([], DependencyPlanner::loops(array_map(fn ($entry) => $entry['parents'], $derived)));
    }

    public function testSecondCoreSwitchThatIsNotSetAsCore(): void
    {
        // only core1 is set as core; core2, cabled to both distribution switches, has the lower bridge priority
        $net = RedundancyFixture::campus();
        $analyzer = $net->analyzer(['redundancy_core' => 'core1'], null, [], [], [self::C1 => 4096, self::C2 => 8192, self::D1 => 32768]);
        $derived = (new DependencyPlanner($analyzer))->derive();

        $this->assertSame([self::C1], $derived[self::C2]['parents']);
        $this->assertSame(1, $derived[self::C2]['hops']);
        $this->assertSame([self::C1, self::C2], $derived[self::D1]['parents'], 'both uplinks: core2 ranks first');
        $this->assertSame([self::C1, self::C2], $derived[self::D2]['parents']);
        $this->assertSame([self::D1, self::D2], $derived[self::A1]['parents']);
        $this->assertSame([['core' => self::C1, 'lookalike' => self::C2, 'shared' => 2]], CoreAdvice::singleCores($analyzer));

        // without spanning tree data the distribution switches are more central than core2: the page says to set it as core
        $analyzer = $net->analyzer(['redundancy_core' => 'core1']);
        $this->assertSame([['core' => self::C1, 'lookalike' => self::C2, 'shared' => 2]], CoreAdvice::singleCores($analyzer));
        $this->assertSame([], DependencyPlanner::loops(array_map(fn ($entry) => $entry['parents'], (new DependencyPlanner($analyzer))->derive())));
    }

    public function testDualHomedServerAndLoneDevices(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(11, 'esx1', 'server')->link(11, 'vmnic0', self::A1, 'Gi1/0/2')->link(11, 'vmnic1', self::A2, 'Gi1/0/2');
        $net->device(12, 'lab-sw');
        $derived = (new DependencyPlanner($net->analyzer()))->derive();

        $this->assertSame([self::A1, self::A2], $derived[11]['parents']);
        $this->assertSame(3, $derived[11]['hops']);
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
        $this->assertSame([self::D2 => [self::C1, self::C2], self::A1 => [self::D1, self::D2]], DependencyPlanner::changes($rows));
    }

    public function testSelectSkipsWhatChangedSinceThePreview(): void
    {
        $derived = (new DependencyPlanner(RedundancyFixture::campus()->analyzer()))->derive();
        $current = [self::D2 => [self::C1], self::A1 => [self::D1], self::AP => [self::A1]];
        $rows = DependencyPlanner::compare($derived, $current, [self::D2, self::A1, self::A2, self::AP, 12]);

        $selection = DependencyPlanner::select($rows, [
            self::D2 => [self::C2, self::C1], // as previewed, in another order
            self::A1 => [self::D1], // the preview showed other parents
            self::AP => [self::A2], // no change any more: the preview showed one
            12 => [self::A1], // not in the topology any more
        ], $current);

        $this->assertSame([self::D2 => [self::C1, self::C2], self::A2 => [self::D1, self::D2]], $selection['apply'], 'acc2: no preview to check against');
        $this->assertSame([self::A1, self::AP, 12], $selection['stale']);
        $this->assertSame([], $selection['loops']);
        $this->assertSame([self::D2, self::A1, self::A2], array_keys(DependencyPlanner::select($rows, [], $current)['apply']));
    }

    public function testSelectRefusesDevicesThatWouldBeOnALoop(): void
    {
        // a hand-made dependency of core1 points the other way: dist1 would depend on core1 and core1 on dist1
        $derived = (new DependencyPlanner(RedundancyFixture::campus()->analyzer()))->derive();
        $current = [self::C1 => [self::D1]];
        $rows = DependencyPlanner::compare($derived, $current, [self::D1, self::D2, self::A1, self::A2, self::AP]);
        $selection = DependencyPlanner::select($rows, [], $current);

        $this->assertSame([self::D1], $selection['refused']);
        $this->assertSame([[self::C1, self::D1]], $selection['loops']);
        $this->assertSame([self::D2, self::A1, self::A2, self::AP], array_keys($selection['apply']), 'the other changes are applied');
        $this->assertSame([], DependencyPlanner::loops(DependencyPlanner::after($current, $selection['apply'])));
    }

    public function testSelectChecksAgainAfterRefusing(): void
    {
        // core2 depends on acc2 by hand, and dist2 and acc1 on one another
        $derived = (new DependencyPlanner(RedundancyFixture::campus()->analyzer()))->derive();
        $current = [self::C1 => [self::D1], self::C2 => [self::A2], self::D2 => [self::A1], self::A1 => [self::D2]];
        $rows = DependencyPlanner::compare($derived, $current, [self::D1, self::D2, self::A1, self::A2, self::AP]);
        $selection = DependencyPlanner::select($rows, [], $current);

        // dist1, dist2 and acc2 would be on loops through the core devices; with dist2 refused, acc1 stays on its loop with dist2
        $this->assertSame([self::D1, self::D2, self::A1, self::A2], $selection['refused']);
        $this->assertSame([[self::C1, self::D1], [self::C2, self::A2, self::D2], [self::C2, self::A2, self::D1], [self::D2, self::A1]], $selection['loops']);
        $this->assertSame([self::AP => [self::A1]], $selection['apply']);
        $after = DependencyPlanner::after($current, $selection['apply']);
        $this->assertSame([[self::D2, self::A1]], DependencyPlanner::loops($after), 'only the loop that was there');
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
            self::C1 => [[100, 50000], [19000, 19100]], // the first outage still runs after the second one ends
            self::C2 => [[19500, null]],
        ];
        $sets = [
            'now' => [self::A1 => [self::D1, self::D2], self::A2 => [self::C1, self::C2]],
            'after' => [self::A1 => [self::D1], self::A2 => [self::D1, self::D2]],
        ];
        $result = DependencyPlanner::explained($sets, $outages, 100);

        // acc1 at 1000: both uplinks down; at 10000 only dist1; the outage at 50 is before the window
        $this->assertSame(['outages' => 2, 'explained' => ['now' => 1, 'after' => 2]], $result[self::A1]);
        // acc2 at 20000: core1 since 100 and core2 since 19500 (still down)
        $this->assertSame(['outages' => 1, 'explained' => ['now' => 1, 'after' => 0]], $result[self::A2]);
        $this->assertSame(['outages' => 2, 'explained' => ['now' => 0, 'after' => 0]], $result[self::D1], 'no parents, nothing explained');
    }

    public function testFingerprintOfTheChanges(): void
    {
        $changes = [self::A1 => [self::D1, self::D2], self::D2 => [self::C1]];

        $this->assertSame(PlanningService::fingerprint($changes), PlanningService::fingerprint(array_reverse($changes, true)));
        $this->assertNotSame(PlanningService::fingerprint($changes), PlanningService::fingerprint([self::A1 => [self::D1]] + $changes));
        $this->assertNotSame(PlanningService::fingerprint($changes), PlanningService::fingerprint([]));
    }
}
