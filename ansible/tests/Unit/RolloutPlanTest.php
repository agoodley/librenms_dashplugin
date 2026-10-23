<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
use Agoodley\LibrenmsAnsible\Changes\BlastRadius;
use Agoodley\LibrenmsAnsible\Changes\Fingerprint;
use Agoodley\LibrenmsAnsible\Changes\RolloutPlan;
use Agoodley\LibrenmsAnsible\Changes\TopologyPlan;
use Agoodley\LibrenmsAnsible\Services\ChangeService;
use PHPUnit\Framework\TestCase;

/**
 * A change's batches from the topology plugin's rollout plan (RolloutPlan), the blast radius of a batch (BlastRadius)
 * and what the plan keeps of the topology (TopologyPlan), on the lab: lab-sw1 the core; lab-sw2 and lab-sw3 cabled to
 * it; lab-sw4 and lab-sw5 dual-homed to both; lab-sw6 on lab-sw2 alone; lab-sw7 and lab-sw8 on lab-sw3 alone.
 */
class RolloutPlanTest extends TestCase
{
    public const SW1 = 501;
    public const SW2 = 502;
    public const SW3 = 503;
    public const SW4 = 510;
    public const SW5 = 511;
    public const SW6 = 512;
    public const SW7 = 513;
    public const SW8 = 514;

    /** The targets in the order chosen (as the new change page lists them). */
    public const ALL = [self::SW1, self::SW2, self::SW3, self::SW4, self::SW5, self::SW6, self::SW7, self::SW8];

    public const NAMES = [501 => 'lab-sw1', 502 => 'lab-sw2', 503 => 'lab-sw3', 504 => 'lab-dead', 509 => 'lab-other', 510 => 'lab-sw4', 511 => 'lab-sw5',
        512 => 'lab-sw6', 513 => 'lab-sw7', 514 => 'lab-sw8'];

    /** What each switch going down alone cuts off, and the unmanaged neighbors hanging off it. */
    public const CUTS = [501 => [], 502 => [512], 503 => [513, 514], 510 => [], 511 => [], 512 => [], 513 => [], 514 => []];

    public const PHONES = [501 => 0, 502 => 0, 503 => 0, 510 => 1, 511 => 1, 512 => 2, 513 => 1, 514 => 2];

    /** Devices the planner cannot plan: lab-dead is down, lab-other has no LLDP neighbor. */
    public const UNPLANNABLE = [504 => 'down', 509 => 'isolated'];

    /**
     * The planning API's rollout() of the lab (as the topology plugin answers it), with $size devices a batch.
     *
     * @param  int[]  $devices
     */
    public static function planner(array $devices, int $size = 5): array
    {
        $tiers = [[2, [510, 511, 512, 513, 514]], [1, [502]], [1, [503]], ['core', [501]]];
        $batches = [];
        foreach ($tiers as [$tier, $members]) {
            foreach (array_chunk(array_values(array_intersect($members, $devices)), $size ?: 100) as $chunk) {
                $blast = self::blast($chunk);
                $batches[] = ['number' => count($batches) + 1, 'tier' => $tier, 'devices' => $chunk, 'lost' => $blast['lost'], 'network' => count($blast['lost']),
                    'unmanaged' => $blast['unmanaged'], 'reference' => $tier === 'core' ? 'largest part' : 'core', 'simulate' => $blast['simulate']];
            }
        }
        $skipped = [];
        foreach ($devices as $deviceId) {
            if (isset(self::UNPLANNABLE[$deviceId])) {
                $skipped[] = ['device_id' => $deviceId, 'reason' => self::UNPLANNABLE[$deviceId], 'text' => '...'];
            } elseif (! isset(self::CUTS[$deviceId])) {
                $skipped[] = ['device_id' => $deviceId, 'reason' => 'unknown', 'text' => '...'];
            }
        }

        return ['version' => '1.1', 'batches' => $batches, 'skipped' => $skipped, 'core' => [501], 'core_modes' => [501 => 'configured'],
            'names' => array_intersect_key(self::NAMES, array_flip([...$devices, 501])), 'role_hidden' => []];
    }

    /**
     * The planning API's blastRadius() of these devices in the lab ($cuts: what each device alone cuts off now; $skips:
     * the devices the topology can tell nothing about now, with why: down, without LLDP/CDP neighbors...).
     *
     * @param  int[]  $devices
     * @param  array<int, int[]>  $cuts
     * @param  array<int, string>  $skips
     */
    public static function blast(array $devices, array $cuts = self::CUTS, array $skips = []): array
    {
        $lost = [];
        $unmanaged = 0;
        $skipped = [];
        foreach ($devices as $deviceId) {
            if (! isset($cuts[$deviceId]) || isset($skips[$deviceId])) {
                $skipped[] = ['device_id' => $deviceId, 'reason' => $skips[$deviceId] ?? self::UNPLANNABLE[$deviceId] ?? 'unknown', 'text' => '...'];

                continue;
            }
            $unmanaged += self::PHONES[$deviceId] ?? 0;
            foreach ($cuts[$deviceId] as $cut) {
                if (! in_array($cut, $devices, true) && ! in_array($cut, $lost, true)) {
                    $lost[] = $cut;
                    $unmanaged += self::PHONES[$cut] ?? 0;
                }
            }
        }

        return ['version' => '1.0', 'devices' => $devices, 'lost' => $lost, 'network' => count($lost), 'unmanaged' => $unmanaged, 'reference' => 'core',
            'skipped' => $skipped, 'names' => array_intersect_key(self::NAMES, array_flip([...$devices, ...$lost])),
            'simulate' => 'plugin/topology/l2?' . http_build_query(['fail' => implode(',', array_map(fn ($d) => "d:$d", $devices))]), 'role_hidden' => []];
    }

    /**
     * RolloutPlan::make() on the lab with the settings' defaults (a canary of 1, batches of 5, stop at 1, wait after the first).
     */
    private static function make(array $devices = self::ALL, array $canary = [], int $canarySize = 1, int $size = 5, string $unplanned = RolloutPlan::LEAVE): array
    {
        return RolloutPlan::make($devices, $canary, $canarySize, $size, 1, true, 0, self::planner($devices, $size), $unplanned, fn (array $batch) => self::blast($batch));
    }

    // ------------------------------------------------------------------ the plan from the planner

    public function testThePlannersOrderWithTheCanaryTakenFromItsFirstBatch(): void
    {
        $made = self::make();
        $plan = $made['plan'];

        $this->assertSame([[510], [511, 512, 513, 514], [502], [503], [501]], $plan->batches,
            'the farthest first; the canary is the first device of the planner\'s first batch, the rest of it follows; the redundant pair split; the core last');
        $this->assertSame(BatchPlan::TOPOLOGY, $plan->source);
        $this->assertSame([2, 2, 1, 1, 'core'], array_column($plan->topology->batches, 'tier'));
        $this->assertSame([[], [], [512], [513, 514], []], array_column($plan->topology->batches, 'lost'), 'lab-sw2 cuts off lab-sw6, lab-sw3 lab-sw7 and lab-sw8');
        $this->assertSame([1, 6, 2, 3, 0], array_column($plan->topology->batches, 'unmanaged'));
        $this->assertSame(['lab-sw1', 'lab-sw6', 'lab-sw7', 'lab-sw8'], array_values($plan->topology->names), 'the devices it cuts off, and the core, are named');
        $this->assertSame([[501], [501 => 'configured']], [$plan->topology->core, $plan->topology->coreModes], 'the core the plan is measured from, and how it was chosen');
        $this->assertSame('lab-sw1 (the Core devices setting)', $plan->topology->coreText());
        $this->assertSame([], $made['left_out']);
        $this->assertSame([], $made['notes']);
        $this->assertSame([1, true, 0], [$plan->stopAt, $plan->waitAfterFirst, $plan->pauseMinutes], 'the settings of batches');
        $this->assertSame('5 batches: 1, then 4, then 1, then 1, then 1 device, planned by the topology plugin; stops at 1 failed device in a batch; '
            . 'waits for an admin to continue after the first batch', $plan->describe());
    }

    public function testTheBatchSizeLimitsThePlannersBatches(): void
    {
        $this->assertSame([[510], [511], [512], [513], [514], [502], [503], [501]], self::make(size: 1)['plan']->batches);
        $this->assertSame([[510], [511], [512, 513], [514], [502], [503], [501]], self::make(size: 2)['plan']->batches, 'the canary, then the rest of the planner\'s first batch of 2');
    }

    public function testTheCanaryIsTheTickedDevicesOfThePlannersFirstBatch(): void
    {
        $this->assertSame([[512, 513], [510, 511, 514]], array_slice(self::make(canary: [513, 512])['plan']->batches, 0, 2));
        $this->assertSame([[510, 511], [512, 513, 514]], array_slice(self::make(canarySize: 2)['plan']->batches, 0, 2), 'the First batch setting');
        $this->assertSame([[510, 511, 512, 513, 514], [502]], array_slice(self::make(canarySize: 9)['plan']->batches, 0, 2), 'no more than the planner\'s first batch');

        // a device ticked that the planner puts later is not moved before what still waits for it
        $made = self::make(canary: [502]);
        $this->assertSame([[510], [511, 512, 513, 514], [502], [503], [501]], $made['plan']->batches);
        $this->assertSame(['lab-sw2 is ticked for the first batch, but the planner puts it later (1 hop from the core): the first batch is taken from the planner\'s first batch.'],
            $made['notes']);
    }

    public function testDevicesThePlannerCannotPlanAreLeftOutOrPlannedByHand(): void
    {
        $devices = [...self::ALL, 504, 509];
        $left = self::make($devices);
        $this->assertSame([[510], [511, 512, 513, 514], [502], [503], [501]], $left['plan']->batches, 'left out: not targets of the change');
        $this->assertSame([['device_id' => 504, 'reason' => 'down'], ['device_id' => 509, 'reason' => 'isolated']], $left['left_out']);
        $this->assertSame($left['left_out'], $left['plan']->topology->leftOut, 'kept with the plan');
        $this->assertSame('lab-dead', $left['plan']->topology->names[504]);

        $hand = self::make($devices, unplanned: RolloutPlan::HAND);
        $this->assertSame([[510], [511, 512, 513, 514], [502], [503], [501], [504, 509]], $hand['plan']->batches, 'a final batch planned by hand');
        $last = $hand['plan']->topology->batch(6);
        $this->assertSame([null, true, []], [$last['tier'], $last['hand'], $last['lost']]);
        $this->assertSame([['device_id' => 504, 'reason' => 'down'], ['device_id' => 509, 'reason' => 'isolated']], $last['skipped'], 'nothing tells what depends on them');
        $this->assertSame([], $hand['left_out']);
        $this->assertSame('6 batches: 1, then 4, then 1, then 1, then 1, then 2 devices, planned by the topology plugin (the last batch by hand); stops at 1 failed device in a batch; '
            . 'waits for an admin to continue after the first batch', $hand['plan']->describe());
        $this->assertSame([[504], [509]], array_slice(self::make($devices, size: 1, unplanned: RolloutPlan::HAND)['plan']->batches, -2), 'within the batch size');
        $this->assertStringContainsString('planned by the topology plugin (the last 2 batches by hand)', self::make($devices, size: 1, unplanned: RolloutPlan::HAND)['plan']->describe());
    }

    public function testWithNothingThePlannerCanPlan(): void
    {
        $left = self::make([504, 509]);
        $this->assertNull($left['plan']);
        $this->assertSame('The topology plugin cannot plan any of these devices (lab-dead (down now), lab-other (no LLDP/CDP neighbors: no topology data)): '
            . 'put them in final batches planned by hand (the choice below the preview), or choose others.', $left['error']);

        // by hand: a plan of the settings, the canary first
        $hand = self::make([504, 509], unplanned: RolloutPlan::HAND);
        $this->assertSame([[504], [509]], $hand['plan']->batches);
        $this->assertSame([true, true], array_column($hand['plan']->topology->batches, 'hand'));
    }

    public function testAPlannersAnswerThatDoesNotPlanTheTargetsIsRefused(): void
    {
        $bad = [
            'another device' => fn (array $p) => ['batches' => [['tier' => 1, 'devices' => [999]]]] + $p,
            'a target twice' => fn (array $p) => ['batches' => [['tier' => 1, 'devices' => [501]], ['tier' => 'core', 'devices' => [501]]]] + $p,
            'a target left out' => fn (array $p) => ['batches' => array_slice($p['batches'], 1)] + $p,
            'a batch without a tier' => fn (array $p) => ['batches' => [['devices' => [501, 502, 503, 510, 511, 512, 513, 514]]]] + $p,
            'no version' => fn (array $p) => array_diff_key($p, ['version' => 1]),
        ];
        foreach ($bad as $what => $change) {
            try {
                RolloutPlan::make(self::ALL, [], 1, 5, 1, true, 0, $change(self::planner(self::ALL)), RolloutPlan::LEAVE, fn (array $batch) => self::blast($batch));
                $this->fail("refused: $what");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('rollout plan', $e->getMessage(), $what);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        RolloutPlan::make(self::ALL, [], 1, 5, 1, true, 0, self::planner(self::ALL), RolloutPlan::LEAVE, fn (array $batch) => ['lost' => ['512']] + self::blast($batch));
    }

    public function testThePlannersConstraintsHoldInTheChangesBatches(): void
    {
        // every batch of the change is part of one batch of the planner's, in the planner's order: two devices that back each
        // other up never share a batch, and nothing waits behind a device of an earlier batch
        foreach ([[1, 5, []], [2, 5, []], [1, 2, []], [3, 1, [512, 514]], [1, 3, [511]]] as [$canarySize, $size, $ticked]) {
            $plan = self::make(canary: $ticked, canarySize: $canarySize, size: $size)['plan'];
            $planner = array_column(self::planner(self::ALL, $size)['batches'], 'devices');
            $at = 0;
            foreach ($plan->batches as $batch) {
                while ($at < count($planner) && array_diff($batch, $planner[$at])) {
                    $at++;
                }
                $this->assertLessThan(count($planner), $at, 'batch ' . implode(',', $batch) . " within one batch of the planner's, in order (size $size)");
            }
        }
    }

    // ------------------------------------------------------------------ the plan as data

    public function testThePlanWithItsTopologyIsPlainDataAndPartOfTheFingerprint(): void
    {
        $plan = self::make([...self::ALL, 504], unplanned: RolloutPlan::HAND)['plan'];
        $data = json_decode(json_encode($plan->toArray()), true); // as the database gives it back

        $this->assertSame(2, $data['version']);
        $read = BatchPlan::fromArray($data, [...self::ALL, 504]);
        $this->assertEquals($plan, $read);
        $this->assertSame([512], BatchPlan::topologyOf($data, 3)['lost']);
        $this->assertTrue(BatchPlan::isTopology($data));

        // what the topology said is part of what is dry-run and approved
        $parts = fn (array $plan) => Fingerprint::parts(['template' => ['playbook' => 'x.yml']], [501 => 'lab-sw1'], [], [], $plan);
        $other = $data;
        $other['topology']['batches'][2]['lost'] = [];
        $this->assertNotSame(Fingerprint::of($parts($data)), Fingerprint::of($parts($other)));
        $this->assertSame(['the batch plan'], Fingerprint::differences($parts($data), $parts($other)));
    }

    public function testAPlanOfTheSettingsStaysAsPhaseTwoStoredIt(): void
    {
        $plan = BatchPlan::make(self::ALL, [], 1, 5, 1, true, 0);
        $this->assertSame(['version' => 1, 'source' => 'plugin', 'batches' => [[501], [502, 503, 510, 511, 512], [513, 514]], 'stop_at' => 1, 'wait_after_first' => true,
            'pause_minutes' => 0], $plan->toArray(), 'byte for byte, so the fingerprints of the changes made before stay');
        $this->assertNull(BatchPlan::topologyOf($plan->toArray(), 1));
        $this->assertFalse(BatchPlan::isTopology($plan->toArray()));
        $this->assertEquals($plan, BatchPlan::fromArray($plan->toArray(), self::ALL));
    }

    public function testAStoredTopologyPartOfAnotherShapeIsRefused(): void
    {
        $data = self::make()['plan']->toArray();
        $bad = [
            'no topology part' => fn (array $d) => array_diff_key($d, ['topology' => 1]),
            'a topology part in a plan of version 1' => fn (array $d) => ['version' => 1] + $d,
            'one entry too few' => fn (array $d) => array_replace_recursive($d, ['topology' => ['batches' => [4 => null]]]),
            'a lost device that is no id' => fn (array $d) => array_replace_recursive($d, ['topology' => ['batches' => [2 => ['lost' => ['lab-sw6']]]]]),
            'a negative count' => fn (array $d) => array_replace_recursive($d, ['topology' => ['batches' => [0 => ['unmanaged' => -1]]]]),
            'a batch by hand with a tier' => fn (array $d) => array_replace_recursive($d, ['topology' => ['batches' => [0 => ['hand' => true]]]]),
        ];
        foreach ($bad as $what => $change) {
            $broken = $change($data);
            if ($what === 'one entry too few') {
                $broken['topology']['batches'] = array_slice($data['topology']['batches'], 0, 4);
            }
            try {
                BatchPlan::fromArray($broken, self::ALL);
                $this->fail("refused: $what");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(BatchPlan::isTopology($broken) || $what === 'no topology part', $what);
                $this->assertNull(BatchPlan::topologyPart($broken), "$what: no topology part read from it");
            }
        }
    }

    // ------------------------------------------------------------------ the blast radius

    public function testABlastRadiusIsCheckedWhenItIsRead(): void
    {
        $read = BlastRadius::fromApi(self::blast([502]));
        $this->assertSame(['lost' => [512], 'network' => 1, 'unmanaged' => 2, 'reference' => 'core', 'skipped' => [], 'simulate' => 'plugin/topology/l2?fail=d%3A502'],
            $read['blast']);
        $this->assertSame([502 => 'lab-sw2', 512 => 'lab-sw6'], $read['names']);

        $this->assertNull(BlastRadius::checked(['simulate' => 'https://evil.example/?x'] + self::blast([502]))['simulate'], 'only the path of the L2 map');
        $this->assertNull(BlastRadius::checked(['simulate' => 'plugin/topology/l2?fail=d:502"><script>'] + self::blast([502]))['simulate']);
        $this->assertSame('core', BlastRadius::checked(['reference' => '<b>'] + self::blast([502]))['reference']);
        $this->assertSame("lab sw6 x", BlastRadius::names(['names' => [512 => "lab\nsw6\tx", 'x' => 'y', 0 => 'z']])[512] ?? null, 'names are one line');
        $this->assertSame([512], array_keys(BlastRadius::names(['names' => [512 => "lab\nsw6\tx", 'x' => 'y', 0 => 'z']])));
        foreach ([['lost' => 'lab-sw6'], ['lost' => [0]], ['network' => -1], ['unmanaged' => '2'], ['skipped' => [['device_id' => 'x', 'reason' => 'down']]]] as $bad) {
            try {
                BlastRadius::checked($bad + self::blast([502]));
                $this->fail('refused: ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testABlastRadiusGrewWhenItCutsOffDevicesThatWereNotHeld(): void
    {
        $this->assertSame(['added' => [], 'removed' => [], 'core_lost' => false], BlastRadius::compare([512], [512]));
        $this->assertSame(['added' => [511], 'removed' => [], 'core_lost' => false], BlastRadius::compare([512], [512, 511]), 'grew: lab-sw5 lost its other uplink');
        $this->assertSame(['added' => [], 'removed' => [512], 'core_lost' => false], BlastRadius::compare([512], []), 'shrank');
        $this->assertSame(['added' => [511], 'removed' => [512], 'core_lost' => false], BlastRadius::compare([512], [511]), 'both: it grew');
        $this->assertSame(['added' => [513], 'removed' => [], 'core_lost' => false], BlastRadius::compare([], [513]), 'a batch planned by hand that the topology now knows: it grew');
    }

    public function testABlastRadiusGrewWhenItNowTakesDownEveryCoreDeviceOfItsPart(): void
    {
        // held with a core device of its part left standing, measured now from the largest piece left: it grew, whatever it cuts off
        $this->assertSame(['added' => [], 'removed' => [512], 'core_lost' => true], BlastRadius::compare([512], [], 'core', 'largest part'));
        $this->assertTrue(BlastRadius::compare([], [], 'core', 'largest part')['core_lost']);
        $this->assertFalse(BlastRadius::compare([], [], 'largest part', 'largest part')['core_lost'], 'the core\'s own batch, approved so: the same');
        $this->assertFalse(BlastRadius::compare([], [], 'largest part', 'core')['core_lost'], 'a core device back: not more');
        $this->assertFalse(BlastRadius::compare([512], [512])['core_lost']);
    }

    public function testANewChangeIsMadeOnlyWithTheBatchesAndTheBlastRadiusItsPreviewShowed(): void
    {
        $plan = self::make()['plan'];
        $shown = ChangeService::blastSignature($plan->topology);
        $this->assertNull(ChangeService::previewDiffers($plan, $plan->batches, $shown), 'as the preview showed');
        $this->assertNull(ChangeService::previewDiffers($plan, null, null), 'nothing given: nothing compared');
        $this->assertNull(ChangeService::previewDiffers(null, $plan->batches, 'x'), 'no plan: the change is not made anyway');

        // the topology changed between the preview and Create: lab-sw2 now also cuts off lab-sw5, the batches are the same
        $now = RolloutPlan::make(self::ALL, [], 1, 5, 1, true, 0, self::planner(self::ALL, 5), RolloutPlan::LEAVE,
            fn (array $batch) => self::blast($batch, [502 => [512, 511]] + self::CUTS))['plan'];
        $this->assertSame($plan->batches, $now->batches);
        $this->assertSame('What the batches cut off is no longer what the preview showed (the topology changed): preview again.',
            ChangeService::previewDiffers($now, $plan->batches, $shown));
        $this->assertSame('The batches are no longer those of the preview (the settings or the devices changed): preview again.',
            ChangeService::previewDiffers($now, [[501], [502]], $shown), 'the batches first');
        // a preview planned by the settings ("": no blast radius) and a change the topology plugin plans now, and the other way round
        $this->assertSame('What the batches cut off is no longer what the preview showed (the topology changed): preview again.', ChangeService::previewDiffers($plan, null, ''));
        $settings = BatchPlan::make(self::ALL, [], 1, 5, 1, true, 0);
        $this->assertNull(ChangeService::previewDiffers($settings, null, ''));
        $this->assertSame('What the batches cut off is no longer what the preview showed (the topology changed): preview again.', ChangeService::previewDiffers($settings, null, $shown));
    }

    public function testOnlyABlastRadiusAPreviewGivesIsTakenForOne(): void
    {
        $signature = ChangeService::blastSignature(self::make()['plan']->topology);
        $this->assertTrue(ChangeService::isBlastSignature($signature));
        $this->assertTrue(ChangeService::isBlastSignature(''), 'a plan of the settings: none');
        foreach (['zz', strtoupper($signature), $signature . 'a', substr($signature, 1), $signature . "\n", ' ' . $signature, null, 0, 123, true, [$signature], ['x' => 1]] as $value) {
            $this->assertFalse(ChangeService::isBlastSignature($value), 'not one: ' . json_encode($value));
        }
    }

    public function testWhatANewChangeKeepsFromItsPreviewIsWhatEachBatchCutsOffAndWhatThatIsMeasuredFrom(): void
    {
        $plan = fn (\Closure $blast) => RolloutPlan::make([501, 502], [], 1, 5, 1, false, 0, self::planner([501, 502]), RolloutPlan::LEAVE, $blast)['plan']->topology;
        $signature = ChangeService::blastSignature($plan(fn (array $batch) => self::blast($batch)));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
        $this->assertSame($signature, ChangeService::blastSignature($plan(fn (array $batch) => self::blast($batch))), 'the same plan, the same signature');
        $this->assertNotSame($signature, ChangeService::blastSignature($plan(fn (array $batch) => self::blast($batch, [502 => [512, 511]] + self::CUTS))),
            'lab-sw2 cuts off more: another signature');
        $this->assertNotSame($signature, ChangeService::blastSignature($plan(fn (array $batch) => ['reference' => in_array(501, $batch, true) ? 'largest part' : 'core']
            + self::blast($batch))), 'lab-sw1 takes down every core device of its part: another signature');
        $this->assertNotSame($signature, ChangeService::blastSignature($plan(fn (array $batch) => self::blast($batch, self::CUTS, [502 => 'down']))),
            'lab-sw2 skipped: another signature');
        $this->assertSame($signature, ChangeService::blastSignature($plan(fn (array $batch) => ['unmanaged' => 7, 'names' => []] + self::blast($batch))),
            'unmanaged neighbors and names are not kept');
    }

    public function testACheckOfABlastRadiusIsKnownByWhatThePageShowsOfIt(): void
    {
        // what an acceptance or a Continue names: what the batch cuts off and what that is measured from, what grew, why it cannot
        // be computed and what the window would cover; not when it was checked, the names, the unmanaged neighbors or the link
        $record = ['state' => 'grew', 'lost' => [512, 511], 'reference' => 'core', 'core_lost' => false, 'added' => [511], 'window' => [512, 511], 'unplanned' => [],
            'at' => '2026-10-22 10:00:00', 'names' => [511 => 'lab-sw5'], 'unmanaged' => 3, 'simulate' => 'plugin/topology/l2?fail=d%3A502', 'held' => [512]];
        $id = BlastRadius::checkId($record);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        foreach (['at' => '2026-10-22 10:01:00', 'names' => [], 'unmanaged' => 4, 'simulate' => null, 'lost' => [511, 512], 'window' => [511, 512]] as $key => $value) {
            $this->assertSame($id, BlastRadius::checkId([$key => $value] + $record), "the same check whatever its $key");
        }
        foreach (['state' => 'unknown', 'lost' => [512, 511, 510], 'reference' => 'largest part', 'core_lost' => true, 'added' => [511, 510], 'window' => [512],
            'error' => 'an error', 'unplanned' => [['device_id' => 502, 'reason' => 'down']]] as $key => $value) {
            $this->assertNotSame($id, BlastRadius::checkId([$key => $value] + $record), "another check when its $key differs");
        }
    }

    public function testTheWindowCoversTheBatchAndWhatItCutsOff(): void
    {
        $this->assertSame([502, 512, 511], BlastRadius::windowDevices([502], [512, 511]));
        $this->assertSame([511, 512], BlastRadius::windowDevices([511, 512], [512]), 'each device once');
        $this->assertSame([512, 511], BlastRadius::cutOff([502], [512, 511, 502]), 'the devices it covers besides the batch\'s own');
        $this->assertSame('lab-sw6 and 2 unmanaged neighbors', BlastRadius::text(['lost' => [512], 'unmanaged' => 2], self::NAMES));
        $this->assertSame('no other monitored device, and 1 unmanaged neighbor', BlastRadius::text(['lost' => [], 'unmanaged' => 1], self::NAMES));
        $this->assertSame('lab-sw1, lab-sw2 and 1 more', BlastRadius::listText([501, 502, 777], self::NAMES, 2));
        $this->assertSame('device #777', BlastRadius::listText([777], self::NAMES));
    }

    public function testWhatEachBatchCutsOffInWordsForTheTimeline(): void
    {
        $plan = self::make([...self::ALL, 504], [], 1, 5, RolloutPlan::HAND)['plan'];

        $this->assertSame('batch 1 (lab-sw4): no other monitored device, and 1 unmanaged neighbor; batch 2 (lab-sw5, lab-sw6, lab-sw7, lab-sw8): no other monitored '
            . 'device, and 6 unmanaged neighbors; batch 3 (lab-sw2): lab-sw6 and 2 unmanaged neighbors; batch 4 (lab-sw3): lab-sw7, lab-sw8 and 3 unmanaged neighbors; '
            . 'batch 5 (lab-sw1): no other monitored device; batch 6 (lab-dead): planned by hand, unknown', $plan->topology->summary($plan->batches, self::NAMES));
        $this->assertStringEndsWith('batch 2 (lab-sw5, lab-sw6, lab-sw7, lab-sw8): no other monitored device, and 6 unmanaged neighbors; and 4 more batches (on the change page)',
            $plan->topology->summary($plan->batches, self::NAMES, 2));
    }

    public function testAPlanThatStartsWithTheCoreOrOnAGuessedCoreIsSaid(): void
    {
        // the topology plugin guessed every chosen switch as its core: the canary is a core switch
        $planner = ['version' => '1.1', 'batches' => [['number' => 1, 'tier' => 'core', 'devices' => [501, 502, 503], 'lost' => [], 'network' => 0, 'unmanaged' => 0,
            'reference' => 'largest part', 'simulate' => null]], 'skipped' => [], 'core' => [501, 502, 503],
            'core_modes' => [501 => 'automatic', 502 => 'automatic', 503 => 'automatic'], 'names' => array_intersect_key(self::NAMES, array_flip([501, 502, 503])), 'role_hidden' => []];
        $made = RolloutPlan::make([501, 502, 503], [], 1, 5, 1, true, 0, $planner, RolloutPlan::LEAVE, fn (array $batch) => self::blast($batch));

        $this->assertSame([[501], [502, 503]], $made['plan']->batches);
        $this->assertTrue($made['plan']->topology->startsWithCore());
        $this->assertTrue($made['plan']->topology->coreGuessed());
        $this->assertSame('lab-sw1, lab-sw2, lab-sw3 (a guess: the most central switches)', $made['plan']->topology->coreText());
        $this->assertSame(['The first batch is of the core the topology plugin measures from (lab-sw1, lab-sw2, lab-sw3 (a guess: the most central switches)): the '
            . 'batches do not go from the edge to the core. Check the core.',
            'The core is a guess of the topology plugin (the most central switches): set Core devices in the topology plugin\'s settings (Overview, Plugins, '
            . 'Plugin Admin, topology, Settings) for the plan to be measured from yours.'], $made['notes']);

        // set in the settings, and the plan starts beyond it: nothing to say
        $this->assertSame([], self::make()['plan']->topology->coreWarnings());
    }

    public function testTheCoreIsKeptWithThePlanAndAnEarlierPlanHasNone(): void
    {
        $plan = self::make()['plan'];
        $data = json_decode((string) json_encode($plan->toArray()), true);
        $this->assertSame([501], $data['topology']['core']);
        $this->assertSame(['501' => 'configured'], $data['topology']['core_modes']);
        $back = BatchPlan::topologyPart($data);
        $this->assertSame([[501], [501 => 'configured']], [$back->core, $back->coreModes]);

        // a plan stored before the core was kept
        unset($data['topology']['core'], $data['topology']['core_modes']);
        $earlier = BatchPlan::topologyPart($data);
        $this->assertSame([[], [], ''], [$earlier->core, $earlier->coreModes, $earlier->coreText()]);
        $this->assertSame([], $earlier->coreWarnings());

        // a mode it does not know is dropped; a core that is not a list of devices is refused
        $this->assertSame([[501], []], TopologyPlan::coreOf([501], ['501' => 'telepathy']));
        $this->expectException(\InvalidArgumentException::class);
        TopologyPlan::coreOf(['lab-sw1'], []);
    }

    public function testTiersAndReasonsInWords(): void
    {
        $this->assertSame(['2 hops from the core', '1 hop from the core', 'the core', 'planned by hand'],
            array_map(fn ($tier) => TopologyPlan::tierText($tier), [2, 1, 'core', null]));
        $this->assertSame('no path to the core now', TopologyPlan::reasonText('unconnected'));
        $this->assertSame('unknown', TopologyPlan::reason('<script>'), 'a reason is a word');
    }
}
