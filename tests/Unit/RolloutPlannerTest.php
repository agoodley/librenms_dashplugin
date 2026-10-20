<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Redundancy\DependencyPlanner;
use Agoodley\LibrenmsTopology\Redundancy\RedundancyAnalyzer;
use Agoodley\LibrenmsTopology\Redundancy\RolloutPlanner;
use Agoodley\LibrenmsTopology\Topology\PlanningService;
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
     * Plan and check every batch against the failure simulation of the same devices.
     */
    private function plan(RedundancyAnalyzer $analyzer, array $devices, int $size = 0): array
    {
        $plan = (new RolloutPlanner($analyzer))->plan($devices, $size);
        $this->assertLikeTheSimulation($analyzer, $plan);

        return $plan;
    }

    private function assertLikeTheSimulation(RedundancyAnalyzer $analyzer, array $plan, string $context = ''): void
    {
        // the simulation counts from the largest piece as soon as any part has no core device up
        $everyCoreUp = $analyzer->simulate([])['reference'] === 'core';
        foreach ($plan['batches'] as $batch) {
            $simulated = $analyzer->simulate($batch['refs']);
            $lost = array_values(array_filter(array_column($simulated['lost'], 'device_id')));
            sort($lost);
            $planned = $batch['lost'];
            sort($planned);
            $what = $context . 'batch ' . implode(',', $batch['devices']);
            $this->assertSame($lost, $planned, "$what cuts off what the simulation says");
            $this->assertSame($simulated['unmanaged'], $batch['unmanaged'], "$what: unmanaged neighbors");
            if ($everyCoreUp) {
                $this->assertSame($simulated['reference'], $batch['reference'], "$what: reference");
            }
        }
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
        $this->assertSame([2, 1, 1, RolloutPlanner::CORE, RolloutPlanner::CORE], array_column($plan['batches'], 'tier'), 'hops from the core devices');
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
        $this->assertSame([3, 2, 1, 1], array_column($plan['batches'], 'tier'));
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

    public function testDevicesWithoutAPathToTheCoreAreLeftOut(): void
    {
        $net = RedundancyFixture::campus();
        $net->devices[self::A2]['status'] = 0;
        $net->device(11, 'lab-sw'); // no links at all
        // cut-sw's only uplink is down now, and edge-sw hangs off it: nothing tells what depends on them
        $net->device(12, 'cut-sw')->link(12, 'Gi1/0/49', self::D1, 'Gi1/0/5')->setPort(12, 'Gi1/0/49', ['ifOperStatus' => 'down']);
        $net->device(13, 'edge-sw')->link(13, 'Gi1/0/49', 12, 'Gi1/0/1');
        $plan = $this->plan($net->analyzer(), [self::A1, self::A2, 11, 12, 13, 999]);

        $this->assertSame([[self::A1]], self::batches($plan), 'never batched together without topology');
        $this->assertSame([2], array_column($plan['batches'], 'tier'));
        $this->assertSame([
            ['device_id' => self::A2, 'reason' => RolloutPlanner::DOWN],
            ['device_id' => 11, 'reason' => RolloutPlanner::ISOLATED],
            ['device_id' => 12, 'reason' => RolloutPlanner::UNCONNECTED],
            ['device_id' => 13, 'reason' => RolloutPlanner::UNCONNECTED],
            ['device_id' => 999, 'reason' => RolloutPlanner::UNKNOWN],
        ], $plan['skipped']);
    }

    public function testPickedDevicesArePlannedAsGiven(): void
    {
        $nodes = RedundancyFixture::campus()->analyzer()->nodes();
        $filter = fn () => [self::A1, self::AP, self::D1];

        // picked devices are planned as they are, unknown ones too (listed as left out), never the filter instead
        $this->assertSame([999, self::A2], PlanningService::rolloutDevices([999, self::A2], $filter, $nodes, true));
        $this->assertSame([self::A1, self::D1], PlanningService::rolloutDevices([], $filter, $nodes, true), 'switches only');
        $this->assertSame([self::A1, self::AP, self::D1], PlanningService::rolloutDevices([], $filter, $nodes, false));

        $plan = $this->plan(RedundancyFixture::campus()->analyzer(), PlanningService::rolloutDevices([999], $filter, $nodes, true));
        $this->assertSame([], $plan['batches']);
        $this->assertSame([['device_id' => 999, 'reason' => RolloutPlanner::UNKNOWN]], $plan['skipped']);
    }

    public function testTheOnlyCoreDeviceIsReportedLikeTheFailureSimulation(): void
    {
        // core1 is the only core device: core2 is an ordinary switch one hop away
        $plan = $this->plan(RedundancyFixture::campus()->analyzer(['redundancy_core' => 'core1']), [self::C1, self::C2]);

        $this->assertSame([[self::C2], [self::C1]], self::batches($plan));
        $this->assertSame([1, RolloutPlanner::CORE], array_column($plan['batches'], 'tier'));
        $this->assertSame([], $plan['batches'][0]['lost']);
        $this->assertSame('core', $plan['batches'][0]['reference']);
        // without its only core device the part carries on as one piece, as in the failure simulation
        $this->assertSame([], $plan['batches'][1]['lost']);
        $this->assertSame('largest part', $plan['batches'][1]['reference']);
    }

    public function testCoreBatchLeavingPiecesCountsFromTheLargest(): void
    {
        // dist3 hangs off core1 alone: with core1 down it is cut off from the rest of the part
        $net = RedundancyFixture::campus();
        $net->device(8, 'dist3')->link(8, 'Te1/1/1', self::C1, 'Te1/0/8');
        $plan = $this->plan($net->analyzer(['redundancy_core' => 'core1']), [self::C1]);

        $this->assertSame([8], $plan['batches'][0]['lost']);
        $this->assertSame('largest part', $plan['batches'][0]['reference']);
    }

    public function testCoreDevicesThatBackEachOtherUpAreKeptApart(): void
    {
        $plan = $this->plan(RedundancyFixture::campus()->analyzer(), [self::C1, self::C2]);

        $this->assertSame([[self::C1], [self::C2]], self::batches($plan));
        $this->assertSame([], $plan['batches'][0]['lost']);
        $this->assertSame('core', $plan['batches'][0]['reference']);
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
        // the dist "a" batch cuts off the single-homed access switches, as the simulation says
        $this->assertSame(40 * 5, count($plan['batches'][1]['lost']));
        $this->assertLikeTheSimulation($analyzer, $plan);
    }

    public function testRandomNetworksMatchBruteForce(): void
    {
        for ($seed = 1; $seed <= 40; $seed++) {
            foreach ([true, false] as $configured) {
                [$net, $settings] = self::randomNetwork($seed, $configured);
                $analyzer = $net->analyzer($settings);
                $ids = array_keys($net->devices);
                $context = "seed $seed" . ($configured ? '' : ' (automatic core)') . ': ';
                $plan = (new RolloutPlanner($analyzer))->plan($ids, $seed % 4 === 0 ? $seed % 3 + 1 : 0);

                $this->assertLikeTheSimulation($analyzer, $plan, $context);
                $planned = array_merge(array_column($plan['skipped'], 'device_id'), ...self::batches($plan));
                sort($planned);
                $this->assertSame($ids, $planned, $context . 'every device planned or left out once');

                $before = self::reached($analyzer, []);
                $done = [];
                foreach ($plan['batches'] as $index => $batch) {
                    // devices that back each other up never share a batch
                    $alone = [];
                    foreach ($batch['refs'] as $ref) {
                        $alone += self::lost($analyzer, $before, [$ref]);
                    }
                    ksort($alone);
                    $together = self::lost($analyzer, $before, $batch['refs']);
                    $this->assertSame(array_keys($alone), array_keys($together), $context . 'batch ' . implode(',', $batch['devices']) . ' cuts off more together');

                    // the farthest first: what still waits keeps its path while every batch so far is down
                    $done = array_merge($done, $batch['refs']);
                    $after = self::reached($analyzer, $done);
                    foreach (array_slice($plan['batches'], $index + 1) as $later) {
                        foreach ($later['refs'] as $ref) {
                            $this->assertArrayHasKey($ref, $after, $context . "$ref waits but is cut off after batch " . ($index + 1));
                        }
                    }
                }

                // derived dependencies never make a loop, and parents are never farther from the core
                $derived = (new DependencyPlanner($analyzer))->derive();
                $this->assertSame([], DependencyPlanner::loops(array_filter(array_map(fn ($entry) => $entry['parents'], $derived))), $context . 'loops');
                $nodes = $analyzer->nodes();
                foreach ($derived as $deviceId => $entry) {
                    foreach ($entry['parents'] as $parent) {
                        if ($nodes['d:' . $deviceId]['network']) {
                            $this->assertLessThanOrEqual($entry['hops'], $derived[$parent]['hops'], $context . "parent $parent of $deviceId");
                        }
                    }
                }
            }
        }
    }

    /**
     * Device nodes that reach the core now with the given devices down (an end device while one of
     * its links does).
     *
     * @param  string[]  $down  device nodes
     * @return array<string, true>
     */
    private static function reached(RedundancyAnalyzer $analyzer, array $down): array
    {
        $graph = $analyzer->graph(current: true);
        $copies = $analyzer->copies();
        $skip = [];
        foreach ($down as $device) {
            foreach ($copies[$device] ?? [$device] as $node) {
                $skip[$node] = true;
            }
        }
        $reached = [RedundancyAnalyzer::CORE => true];
        $queue = [RedundancyAnalyzer::CORE];
        for ($i = 0; $i < count($queue); $i++) {
            foreach ($graph->neighbours($queue[$i]) as $next) {
                $next = (string) $next;
                if (! isset($reached[$next]) && ! isset($skip[$next])) {
                    $reached[$next] = true;
                    $queue[] = $next;
                }
            }
        }
        unset($reached[RedundancyAnalyzer::CORE]);
        $nodes = $analyzer->nodes();
        $devices = [];
        foreach (array_keys($reached) as $node) {
            $devices[$nodes[$node]['of'] ?? (string) $node] = true;
        }

        return $devices;
    }

    /**
     * Device nodes that lose the core when the given ones are down together (strictly, no piece
     * carries on).
     *
     * @param  array<string, true>  $before  see reached()
     * @param  string[]  $down
     * @return array<string, true>
     */
    private static function lost(RedundancyAnalyzer $analyzer, array $before, array $down): array
    {
        $lost = array_diff_key($before, self::reached($analyzer, $down), array_flip($down));
        ksort($lost);

        return $lost;
    }

    /**
     * A random network: 1-3 parts with 1-3 core switches each and layers of switches with 1-3
     * uplinks (some two layers up, through an unmanaged switch or doubled), peer links, end devices
     * with several links, phones, a lone device, and some links and devices down.
     *
     * @return array{0: RedundancyFixture, 1: array} the network and its settings
     */
    private static function randomNetwork(int $seed, bool $configured): array
    {
        mt_srand($seed);
        $pick = fn (array $list) => $list[mt_rand(0, count($list) - 1)];
        $net = new RedundancyFixture;
        $ports = [];
        $port = function (int $device) use (&$ports): string {
            $ports[$device] = ($ports[$device] ?? 0) + 1;

            return 'Gi1/0/' . $ports[$device];
        };
        $id = 0;
        $unmanaged = 0;
        $cores = [];
        $cables = [];
        for ($part = mt_rand(1, 3); $part > 0; $part--) {
            $layer = [];
            for ($i = mt_rand(1, 3); $i > 0; $i--) {
                $net->device(++$id, "p{$part}core$i");
                $cores[] = "p{$part}core$i";
                if ($layer) {
                    $local = $port($id);
                    $net->link($id, $local, end($layer), $port(end($layer)));
                    $cables[] = [$id, $local];
                }
                $layer[] = $id;
            }
            $layers = [$layer];
            for ($depth = mt_rand(1, 4), $l = 1; $l <= $depth; $l++) {
                $layer = [];
                for ($i = mt_rand(1, 6); $i > 0; $i--) {
                    $net->device(++$id, "p{$part}l{$l}s$i");
                    $targets = [];
                    for ($k = mt_rand(1, 3); $k > 0; $k--) {
                        $targets[] = $pick($l >= 2 && mt_rand(0, 4) === 0 ? $layers[$l - 2] : $layers[$l - 1]);
                    }
                    foreach (array_unique($targets) as $target) {
                        $kind = mt_rand(0, 9);
                        if ($kind === 0) {
                            $name = 'unm' . ++$unmanaged;
                            $net->neighbor($id, $port($id), $name)->neighbor($target, $port($target), $name);
                            continue;
                        }
                        $local = $port($id);
                        $net->link($id, $local, $target, $port($target));
                        $cables[] = [$id, $local];
                        if ($kind === 1) {
                            $net->link($id, $port($id), $target, $port($target));
                        }
                    }
                    $layer[] = $id;
                }
                for ($i = mt_rand(0, 2); $i > 0 && count($layer) >= 2; $i--) {
                    [$a, $b] = [$pick($layer), $pick($layer)];
                    if ($a !== $b) {
                        $local = $port($a);
                        $net->link($a, $local, $b, $port($b));
                        $cables[] = [$a, $local];
                    }
                }
                $layers[] = $layer;
            }
            $switches = array_merge(...$layers);
            for ($i = mt_rand(0, 3); $i > 0; $i--) {
                $net->device(++$id, "p{$part}end$i", mt_rand(0, 1) ? 'server' : 'wireless');
                foreach (array_unique([$pick($switches), $pick($switches)]) as $index => $target) {
                    $net->link($id, "eth$index", $target, $port($target));
                }
            }
            for ($i = mt_rand(0, 2); $i > 0; $i--) {
                $target = $pick($switches);
                $net->neighbor($target, $port($target), 'SEP' . sprintf('%012X', mt_rand()), 'Cisco IP Phone 8841');
            }
        }
        if (mt_rand(0, 1)) {
            $net->device(++$id, 'lone');
        }
        foreach ($cables as [$device, $name]) {
            if (mt_rand(0, 14) === 0) {
                $net->setPort($device, $name, ['ifOperStatus' => 'down']);
            }
        }
        foreach (array_keys($net->devices) as $device) {
            if (mt_rand(0, 24) === 0) {
                $net->devices[$device]['status'] = 0;
            }
        }

        return [$net, ['redundancy_core' => $configured ? implode(' ', $cores) : '']];
    }
}
