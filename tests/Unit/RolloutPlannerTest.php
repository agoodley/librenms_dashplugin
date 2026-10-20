<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Redundancy\RedundancyAnalyzer;
use Agoodley\LibrenmsTopology\Redundancy\RolloutPlanner;
use PHPUnit\Framework\TestCase;

class RolloutPlannerTest extends TestCase
{
    private const C1 = RedundancyFixture::CORE1;
    private const C2 = RedundancyFixture::CORE2;
    private const D1 = RedundancyFixture::DIST1;
    private const D2 = RedundancyFixture::DIST2;
    private const A1 = RedundancyFixture::ACC1;
    private const A2 = RedundancyFixture::ACC2;
    private const AP = RedundancyFixture::AP1;

    private const NETWORK = [self::C1, self::C2, self::D1, self::D2, self::A1, self::A2];

    /**
     * Plan and check every batch without core devices against the failure simulation of the same
     * devices (core batches count strictly, see RolloutPlanner).
     */
    private function plan(RedundancyAnalyzer $analyzer, array $devices, int $size = 0): array
    {
        $plan = (new RolloutPlanner($analyzer))->plan($devices, $size);
        foreach ($plan['batches'] as $batch) {
            if ($batch['tier'] === RolloutPlanner::CORE) {
                continue;
            }
            $simulated = $analyzer->simulate($batch['refs']);
            $lost = array_values(array_filter(array_column($simulated['lost'], 'device_id')));
            sort($lost);
            $planned = $batch['lost'];
            sort($planned);
            $this->assertSame($lost, $planned, 'batch ' . implode(',', $batch['devices']) . ' cuts off what the simulation says');
            $this->assertSame($simulated['unmanaged'], $batch['unmanaged']);
        }

        return $plan;
    }

    private static function batches(array $plan): array
    {
        return array_column($plan['batches'], 'devices');
    }

    public function testCampusRollsOutFromTheEdgeToTheCore(): void
    {
        $plan = $this->plan(RedundancyFixture::campus()->analyzer(), self::NETWORK);

        // the access switches together, then one switch of each redundant pair at a time, the core last
        $this->assertSame([[self::A1, self::A2], [self::D1], [self::D2], [self::C1], [self::C2]], self::batches($plan));
        $this->assertSame([3, 2, 2, RolloutPlanner::CORE, RolloutPlanner::CORE], array_column($plan['batches'], 'tier'));
        // the access point behind acc1 is down while acc1 restarts
        $this->assertSame([self::AP], $plan['batches'][0]['lost']);
        $this->assertSame(['d:' . self::A1, 'd:' . self::A2], $plan['batches'][0]['refs']);
        $this->assertSame([], $plan['skipped']);
    }

    public function testBatchSizeLimit(): void
    {
        $plan = $this->plan(RedundancyFixture::campus()->analyzer(), self::NETWORK, 1);

        $this->assertSame([[self::A1], [self::A2], [self::D1], [self::D2], [self::C1], [self::C2]], self::batches($plan));
    }

    public function testSwitchesGoBeforeTheSwitchTheyHangOff(): void
    {
        // acc3 hangs off dist1 alone, acc4 off acc3
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->link(8, 'Gi1/0/49', self::D1, 'Gi1/0/3');
        $net->device(9, 'acc4')->link(9, 'Gi1/0/49', 8, 'Gi1/0/48');
        $plan = $this->plan($net->analyzer(), [self::D1, self::D2, 8, 9]);

        $this->assertSame([[9], [8], [self::D1], [self::D2]], self::batches($plan));
        $this->assertSame([4, 3, 2, 2], array_column($plan['batches'], 'tier'));
        $this->assertSame([8, 9], $plan['batches'][2]['lost']);
        $this->assertSame(2, $plan['batches'][2]['network']);
    }

    public function testSwitchesSharingADualHomedDeviceAreKeptApart(): void
    {
        // the server and the unmanaged switch are only cut off when both access switches are down
        $net = RedundancyFixture::campus();
        $net->device(10, 'esx1', 'server')->link(10, 'vmnic0', self::A1, 'Gi1/0/2')->link(10, 'vmnic1', self::A2, 'Gi1/0/2');
        $plan = $this->plan($net->analyzer(), [self::A1, self::A2]);
        $this->assertSame([[self::A1], [self::A2]], self::batches($plan));

        $net = RedundancyFixture::campus();
        $net->neighbor(self::A1, 'Gi1/0/20', 'old-sw')->neighbor(self::A2, 'Gi1/0/20', 'old-sw');
        $plan = $this->plan($net->analyzer(), [self::A1, self::A2]);
        $this->assertSame([[self::A1], [self::A2]], self::batches($plan));
    }

    public function testDownUnknownAndUnconnectedDevices(): void
    {
        $net = RedundancyFixture::campus();
        $net->devices[self::A2]['status'] = 0;
        $net->device(11, 'lab-sw'); // no links at all
        $plan = $this->plan($net->analyzer(), [self::A1, self::A2, 11, 999]);

        $this->assertSame([[11], [self::A1]], self::batches($plan));
        $this->assertSame([RolloutPlanner::UNCONNECTED, 3], array_column($plan['batches'], 'tier'));
        $this->assertSame([['device_id' => self::A2, 'reason' => 'down'], ['device_id' => 999, 'reason' => 'unknown']], $plan['skipped']);
    }

    public function testTheOnlyCoreDeviceCutsOffItsPart(): void
    {
        // core1 is the only core device: core2 is an ordinary switch two hops away
        $plan = $this->plan(RedundancyFixture::campus()->analyzer(['redundancy_core' => 'core1']), [self::C1, self::C2]);

        $this->assertSame([[self::C2], [self::C1]], self::batches($plan));
        $this->assertSame([2, RolloutPlanner::CORE], array_column($plan['batches'], 'tier'));
        $this->assertSame([], $plan['batches'][0]['lost']);
        // while the core restarts nothing has a path to it, core2 included
        $this->assertSame([self::A1, self::A2, self::C2, self::D1, self::D2, self::AP], $plan['batches'][1]['lost'], 'switches first, by name');
        $this->assertSame(5, $plan['batches'][1]['network']);
    }

    public function testCoreDevicesThatBackEachOtherUpAreKeptApart(): void
    {
        $plan = $this->plan(RedundancyFixture::campus()->analyzer(), [self::C1, self::C2]);

        $this->assertSame([[self::C1], [self::C2]], self::batches($plan));
        $this->assertSame([], $plan['batches'][0]['lost']);
    }

    public function testLargeCampusIsPlannedQuickly(): void
    {
        // 40 distribution pairs with 25 dual-homed access switches each, every 6th single-homed
        $net = new RedundancyFixture;
        $net->device(1, 'core1')->device(2, 'core2')->link(1, 'Te1/1/1', 2, 'Te1/1/1');
        $id = 2;
        $all = [1, 2];
        for ($pod = 0; $pod < 40; $pod++) {
            $a = ++$id;
            $b = ++$id;
            $net->device($a, "dist{$pod}a")->device($b, "dist{$pod}b")->link($a, 'Te1/1/1', 1, "Te1/0/$a")->link($a, 'Te1/1/2', 2, "Te1/0/$a")
                ->link($b, 'Te1/1/1', 1, "Te1/0/$b")->link($b, 'Te1/1/2', 2, "Te1/0/$b")->link($a, 'Te1/1/3', $b, 'Te1/1/3');
            array_push($all, $a, $b);
            for ($i = 0; $i < 25; $i++) {
                $acc = ++$id;
                $net->device($acc, "acc{$pod}-{$i}")->link($acc, 'Gi1/0/49', $a, "Gi1/0/$i");
                if ($i % 6) {
                    $net->link($acc, 'Gi1/0/50', $b, "Gi1/0/$i");
                }
                $all[] = $acc;
            }
        }
        $analyzer = $net->analyzer(['redundancy_core' => 'core1 core2']);
        $start = microtime(true);
        $plan = (new RolloutPlanner($analyzer))->plan($all);
        $elapsed = microtime(true) - $start;

        $this->assertSame([1000, 40, 40, 1, 1], array_map('count', self::batches($plan)));
        $this->assertLessThan(5.0, $elapsed);
    }
}
