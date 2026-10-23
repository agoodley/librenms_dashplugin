<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\ApprovalRules;
use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
use Agoodley\LibrenmsAnsible\Changes\BlastRadius;
use Agoodley\LibrenmsAnsible\Changes\ChangeLifecycle;
use Agoodley\LibrenmsAnsible\Changes\ChangeSettings;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Changes\RolloutPlan;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeBackups;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeChangeRunner;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeTopology;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeWindows;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\MemoryChangeStore;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\MemoryRunStore;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\RecordingEvents;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3: a change planned by the topology plugin on the lab (RolloutPlanTest), its batches checked against their
 * approved blast radius before they start and right before their launch, and their maintenance windows covering what
 * they cut off. The safety rules: no batch starts with a blast radius larger than approved unless an approver accepts
 * it, nor when it cannot be computed unless an admin continues explicitly (recorded); the batches launched are the
 * approved ones, in order; a batch's window covers its devices and the devices the recomputed blast radius cuts off,
 * nothing else, and every window closes.
 */
class ChangeBlastRadiusTest extends TestCase
{
    private const TARGETS = [501 => 'lab-sw1.netops.lab', 502 => 'lab-sw2.netops.lab', 503 => 'lab-sw3.netops.lab', 510 => 'lab-sw4.netops.lab',
        511 => 'lab-sw5.netops.lab', 512 => 'lab-sw6.netops.lab', 513 => 'lab-sw7.netops.lab', 514 => 'lab-sw8.netops.lab', 509 => 'lab-other.netops.lab'];

    /** The batches of the lab's plan: the canary lab-sw4, the rest of the access switches, lab-sw2, lab-sw3, the core. */
    private const BATCHES = [[510], [511, 512, 513, 514], [502], [503], [501]];

    private const ALICE = ['user_id' => 1, 'username' => 'alice'];

    private const BOB = ['user_id' => 2, 'username' => 'bob'];

    private MemoryChangeStore $changes;

    private MemoryRunStore $runs;

    private FakeChangeRunner $runner;

    private FakeBackups $backups;

    private FakeWindows $windows;

    private RecordingEvents $events;

    private FakeTopology $topology;

    private int $t0;

    protected function setUp(): void
    {
        $this->changes = new MemoryChangeStore();
        $this->runs = new MemoryRunStore();
        $this->runner = new FakeChangeRunner($this->runs, self::TARGETS);
        $this->backups = new FakeBackups();
        $this->backups->enabled = false; // batches launch at once, unless a test asks for backups
        $this->windows = new FakeWindows();
        $this->events = new RecordingEvents();
        $this->topology = new FakeTopology();
        $this->t0 = strtotime('2026-10-22 10:00:00 UTC');
        $this->backups->now = $this->t0;
    }

    private function lifecycle(array $settings = []): ChangeLifecycle
    {
        return new ChangeLifecycle($this->changes, $this->runs, $this->runner, $this->backups, $this->windows, $this->events,
            new ChangeSettings(...$settings + ['approval' => ApprovalRules::SECOND, 'settleSeconds' => 600, 'expectedSeconds' => 3600]), $this->topology);
    }

    /**
     * The lab's plan from the topology plugin (no wait after the first batch unless asked).
     *
     * @param  int[]  $devices
     */
    private static function plan(array $devices = [501, 502, 503, 510, 511, 512, 513, 514], bool $wait = false, string $unplanned = RolloutPlan::LEAVE): BatchPlan
    {
        $made = RolloutPlan::make($devices, [], 1, 5, 1, $wait, 0, RolloutPlanTest::planner($devices, 5), $unplanned, fn (array $batch) => RolloutPlanTest::blast($batch));

        return $made['plan'];
    }

    /** A change of the plan, dry-run by alice and approved by bob. */
    private function approved(?BatchPlan $plan = null, array $fields = []): int
    {
        $plan ??= self::plan();
        $targets = array_intersect_key(self::TARGETS, array_flip(array_merge(...$plan->batches)));
        $id = $this->changes->add($targets, $plan, $fields);
        $lifecycle = $this->lifecycle();
        $this->assertNull($lifecycle->startDryRun($id, self::ALICE, [], $this->t0));
        $runId = (int) $this->changes->find($id)['dry_run_id'];
        $lines = ['DRY RUN ****', 'PLAY [Description] [CHECK MODE] ****', 'TASK [Set it] [CHECK MODE] ****'];
        foreach ($targets as $name) {
            $lines[] = "changed: [$name]";
        }
        $lines[] = 'PLAY RECAP ****';
        foreach ($targets as $name) {
            $lines[] = "$name : ok=1 changed=1 unreachable=0 failed=0 skipped=0 rescued=0 ignored=0";
        }
        $this->endRun($runId, array_fill_keys(array_keys($targets), 'changed'), $lines, $this->t0 + 30);
        $lifecycle->advance($id, $this->t0 + 35);
        $this->assertSame(ChangeStatus::AWAITING, $this->changes->find($id)['status']);
        $this->assertNull($lifecycle->approve($id, self::BOB, true, 'ok', $this->t0 + 40));

        return $id;
    }

    private function endRun(int $runId, array $results, array $log = [], int $at = 0): void
    {
        $this->runs->runs[$runId] = array_merge($this->runs->runs[$runId], ['status' => 'success', 'finished_at' => Clock::store($at),
            'output' => implode("\n", $log), 'version' => $this->runs->runs[$runId]['version'] + 1]);
        foreach ($results as $deviceId => $result) {
            $this->runs->targets[$runId][$deviceId]['result'] = $result;
        }
    }

    /** End batch $number's run (every device changed) and move the change on. */
    private function endBatch(int $id, int $number, int $at): void
    {
        $runId = (int) $this->changes->batches[$id][$number]['run_id'];
        $this->assertGreaterThan(0, $runId, "batch $number has a run");
        $this->endRun($runId, array_fill_keys(array_keys($this->runs->targets[$runId]), 'changed'), [], $at);
        $this->lifecycle()->advance($id, $at + 1);
    }

    /** The devices of the batch launches, in order. */
    private function launched(): array
    {
        return array_column(array_values(array_filter($this->runner->launches, fn ($l) => $l['kind'] === 'batch')), 'devices');
    }

    /** The windows: [devices, cut off recorded] per window, in order. */
    private function windowDevices(): array
    {
        return array_values(array_map(fn ($w) => $w['devices'], $this->windows->windows));
    }

    private function messages(int $id): string
    {
        return implode("\n", $this->changes->messages($id));
    }

    /** The check of the blast radius the change waits for, as its page shows it: what an acceptance or a Continue names. */
    private function seen(int $id): string
    {
        $number = (int) $this->changes->find($id)['current_batch'];

        return BlastRadius::checkId($this->changes->batches[$id][$number]['blast'] ?? [], $id, $number);
    }

    // ------------------------------------------------------------------ the plan of the topology plugin, applied

    public function testThePlannedBatchesAreLaunchedInOrderWithWindowsOnWhatTheyCutOff(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();

        $this->assertNull($lifecycle->apply($id, self::ALICE, [], $this->t0 + 50));
        for ($number = 1; $number <= 5; $number++) {
            $this->endBatch($id, $number, $this->t0 + 100 * $number);
        }

        $this->assertSame(ChangeStatus::APPLIED, $this->changes->find($id)['status']);
        $this->assertSame(self::BATCHES, $this->launched(), 'the approved batches, in their order: the canary, the access switches, lab-sw2, lab-sw3, the core last');
        $this->assertSame([[510], [511, 512, 513, 514], [502, 512], [503, 513, 514], [501]], $this->windowDevices(),
            'each window covers its batch and the monitored devices it cuts off, nothing else');
        $this->assertSame([null, null, [512], [513, 514], null], array_values(array_column($this->changes->windows, 'cut_off')));
        $this->assertStringContainsString('lab-sw2. Also the devices the batch cuts off (no path to the core while it runs): lab-sw6. The plugin closes it after the batch.',
            $this->windows->windows[102]['notes']);
        $sw6 = array_column($this->events->of(512), 'message');
        $this->assertNotEmpty(preg_grep('/^Ansible change #1 \(CHG-1\), batch 3 cuts this device off from the core while it runs: maintenance window #102 covers it until \d{4}-/', $sw6),
            'the eventlog of a device cut off says why it is under maintenance, naming the batch by its number only');
        $this->assertStringContainsString('Maintenance window #102 opened for batch 3 until', $this->messages($id));
        $this->assertMatchesRegularExpression('/^Batch 3 of 5 launched by alice \(run #\d+\): while it runs it cuts off lab-sw6 and 2 unmanaged neighbors\.$/m', $this->messages($id),
            'the timeline says what each batch cuts off while it runs');
        $this->assertMatchesRegularExpression('/^Batch 1 of 5 launched by alice \(run #\d+\): while it runs it cuts off no other monitored device, and 1 unmanaged neighbor\.$/m',
            $this->messages($id));
        $this->assertStringContainsString(', also on the devices it cuts off: lab-sw6.', $this->messages($id));

        // the blast radius was computed before each batch and right before its launch, as the admin who started it sees the network
        $this->assertSame(array_merge(...array_map(fn ($b) => [$b, $b], self::BATCHES)), array_column($this->topology->calls, 'devices'));
        $this->assertSame([1], array_values(array_unique(array_column($this->topology->calls, 'user'))));
        $this->assertSame(['same', 'same', 'same', 'same', 'same'], array_values(array_map(fn ($b) => $b['blast']['state'], $this->changes->batches[$id])));

        // every window closes, also on the devices it covered besides the batch's own
        $lifecycle->advance($id, $this->t0 + 2000);
        $this->assertSame(['closed'], array_values(array_unique(array_column($this->changes->windows, 'status'))));
        $this->assertNotEmpty(preg_grep('/batch 3: maintenance window #102 closed at/', array_column($this->events->of(512), 'message')));
        $this->assertFalse($lifecycle->pending($id));
    }

    public function testWhatEachBatchCutsOffIsPartOfWhatIsDryRunAndApproved(): void
    {
        $id = $this->approved();
        // the stored blast radius of batch 3 altered after the approval (nothing but a change of its row does that)
        $plan = $this->changes->changes[$id]['plan'];
        $this->assertSame([512], $plan['topology']['batches'][2]['lost']);
        $plan['topology']['batches'][2]['lost'] = [];
        $this->changes->changes[$id]['plan'] = $plan;

        $error = $this->lifecycle()->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->assertSame('The dry run no longer counts (the change is no longer what was dry-run (the batch plan)): launch it again.', $error);
        $this->assertSame(ChangeStatus::DRAFT, $this->changes->find($id)['status']);
        $this->assertSame('void', $this->changes->find($id)['dry_run_state']);
        $this->assertSame([], $this->launched(), 'nothing launched');
        $this->assertSame([], $this->topology->calls);
    }

    public function testNoEventlogEntryNamesADeviceOtherThanItsOwn(): void
    {
        // LibreNMS shows a device's eventlog to whoever may see that device: what phase 3 writes there counts the other devices
        // the batch is about, it never names them. Every step: grew and accepted, shrank, cut off and covered by windows,
        // cannot be computed and continued, refused.
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->cuts[502] = [512, 511];
        $this->endBatch($id, 2, $this->t0 + 200);
        $this->assertNull($lifecycle->acceptBlast($id, self::BOB, true, 'ok', $this->t0 + 300, $this->seen($id)));
        $this->topology->cuts[503] = [514];
        $this->endBatch($id, 3, $this->t0 + 400);
        $this->assertSame('shrank', $this->changes->batches[$id][4]['blast']['state']);
        $this->topology->error = 'an error';
        $this->endBatch($id, 4, $this->t0 + 500);
        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 600, $this->seen($id)));
        $this->endBatch($id, 5, $this->t0 + 700);
        $lifecycle->advance($id, $this->t0 + 5000);
        $this->assertSame(ChangeStatus::APPLIED, $this->changes->find($id)['status']);

        $this->topology->error = null;
        $this->topology->cuts = RolloutPlanTest::CUTS;
        $refused = $this->approved(self::plan([502]));
        $this->topology->cuts[502] = [512, 511];
        $this->lifecycle(['refuseGrownBlast' => true])->apply($refused, self::ALICE, [], $this->t0 + 6000);
        $this->assertSame(ChangeStatus::FAILED, $this->changes->find($refused)['status']);
        $this->assertStringContainsString('lab-sw5', (string) $this->changes->find($refused)['error'], 'the change page names it');

        // a device of a batch the topology can no longer tell about (down in LibreNMS), and an admin who continues without a blast radius
        $this->topology->cuts = RolloutPlanTest::CUTS;
        $skipped = $this->approved(self::plan([502, 503]));
        $this->topology->skips[503] = 'down';
        $lifecycle->apply($skipped, self::ALICE, [], $this->t0 + 7000);
        $this->endBatch($skipped, 1, $this->t0 + 7100);
        $this->assertSame('blast_unknown', $this->changes->find($skipped)['waiting']);
        $this->assertStringContainsString('lab-sw3 (down now)', (string) $this->changes->batches[$skipped][2]['note'], 'the change page names it');
        $this->assertNull($lifecycle->resume($skipped, self::BOB, [], $this->t0 + 7200, $this->seen($skipped)));
        $this->assertSame('override', $this->changes->batches[$skipped][2]['blast']['state']);

        $names = RolloutPlanTest::NAMES;
        $this->assertGreaterThan(80, count($this->events->entries));
        foreach ($this->events->entries as $entry) {
            foreach ($names as $deviceId => $name) {
                if ($deviceId !== $entry['device']) {
                    $this->assertStringNotContainsString($name, $entry['message'], "the eventlog of device {$entry['device']} names $name");
                }
            }
        }
        // and still says what happened, in counts
        $sw5 = implode("\n", array_column($this->events->of(511), 'message'));
        $this->assertStringContainsString('batch 3 cuts this device off from the core while it runs: maintenance window #102 covers it until', $sw5);
        $sw2 = implode("\n", array_column($this->events->of(502), 'message'));
        $this->assertStringContainsString('it would now cut off 1 more device than approved (2 devices and 3 unmanaged neighbors in all)', $sw2);
        $this->assertStringContainsString("failed, template 'Port description': batch 1 was not started: its blast radius grew since the approval: it would now cut off "
            . '1 more device than approved (setting When the blast radius grew: refuse the batch)', $sw2);
        $sw3 = implode("\n", array_column($this->events->of(503), 'message'));
        $this->assertStringContainsString('batch 4: its blast radius shrank since the approval: it cuts off 1 device fewer; it goes on', $sw3);
        $sw1 = implode("\n", array_column($this->events->of(501), 'message'));
        $this->assertStringContainsString('batch 5: continued by bob without a blast radius (it cannot be computed: an error); its maintenance window covers its devices and no other device', $sw1);
        $this->assertStringContainsString('batch 2: its blast radius cannot be computed now (the topology plugin can no longer tell what depends on 1 device of the batch '
            . '(down now))', $sw2);
        $this->assertStringContainsString('batch 2: continued by bob without a blast radius (it cannot be computed: the topology plugin can no longer tell what depends '
            . 'on 1 device of the batch (down now)); its maintenance window covers its devices and the 2 devices approved as cut off', $sw2);
    }

    public function testAChangePlannedByThePluginsSettingsHasNoBlastCheck(): void
    {
        $id = $this->approved(BatchPlan::make([501, 502, 503], [], 1, 5, 1, false, 0));
        $this->assertNull($this->lifecycle()->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->endBatch($id, 1, $this->t0 + 100);

        $this->assertSame([], $this->topology->calls, 'its blast radius is unknown: nothing to compare');
        $this->assertSame([[501], [502, 503]], $this->windowDevices(), 'its windows cover its devices, as in phase 2');
        $this->assertNull($this->changes->batches[$id][1]['blast']);
    }

    // ------------------------------------------------------------------ grew: an approver accepts, or the batch is refused

    public function testABatchWhoseBlastRadiusGrewWaitsForAnApproverToAcceptIt(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        // lab-sw5's uplink to lab-sw3 goes down: lab-sw2 now cuts off lab-sw5 as well as lab-sw6
        $this->topology->cuts[502] = [512, 511];
        $this->endBatch($id, 2, $this->t0 + 200);

        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'blast', 3], [$change['status'], $change['waiting'], (int) $change['current_batch']]);
        $this->assertSame([[510], [511, 512, 513, 514]], $this->launched(), 'batch 3 is not launched');
        $this->assertCount(2, $this->windows->windows, 'nor its window opened');
        $batch = $this->changes->batches[$id][3];
        $this->assertSame(['pending', 'grew', [511], [512]], [$batch['status'], $batch['blast']['state'], $batch['blast']['added'], $batch['blast']['held']]);
        $this->assertSame('Its blast radius grew since the approval: it would now also cut off lab-sw5', $batch['note']);
        $this->assertStringContainsString('Batch 3: its blast radius grew since the approval: it would now also cut off lab-sw5 (approved: lab-sw6). The batch does not start '
            . 'until an admin with the approve permission (not the requester) accepts the new blast radius.', $this->messages($id));
        $this->assertSame('warning', end($this->events->entries)['severity']);
        $this->assertSame('Ansible change #1 (CHG-1), batch 3: its blast radius grew since the approval: it would now cut off 1 more device than approved (2 devices and 3 '
            . 'unmanaged neighbors in all). The batch does not start until an admin with the approve permission (not the requester) accepts the new blast radius.',
            end($this->events->entries)['message'], 'in the eventlog of the targets, in counts');

        // nothing moves it on but an approver's acceptance
        $lifecycle->advance($id, $this->t0 + 300);
        $lifecycle->advance($id, $this->t0 + 3000);
        $this->assertSame([ChangeStatus::PAUSED, 2], [$this->changes->find($id)['status'], count($this->launched())]);
        $this->assertStringContainsString('accept its new blast radius', (string) $lifecycle->resume($id, self::ALICE, [], $this->t0 + 3001));
        $this->assertStringContainsString('approve permission', (string) $lifecycle->acceptBlast($id, ['user_id' => 3, 'username' => 'carol'], false, '', $this->t0 + 3002));
        $this->assertStringContainsString('you requested this change', (string) $lifecycle->acceptBlast($id, self::ALICE, true, '', $this->t0 + 3003),
            'not the requester, with a second admin');
        $this->assertSame([ChangeStatus::PAUSED, 2], [$this->changes->find($id)['status'], count($this->launched())]);

        $this->assertNull($lifecycle->acceptBlast($id, self::BOB, true, 'the uplink is being fixed', $this->t0 + 3010, $this->seen($id)));
        $this->assertSame([[510], [511, 512, 513, 514], [502]], $this->launched(), 'accepted: batch 3 starts');
        $this->assertSame([502, 512, 511], $this->windows->windows[102]['devices'], 'its window covers the device it now cuts off too');
        $this->assertSame([512, 511], $this->changes->batches[$id][3]['blast_accepted']['lost']);
        $this->assertSame('bob', $this->changes->batches[$id][3]['blast_accepted']['by']);
        $this->assertStringContainsString('Batch 3: its new blast radius accepted by bob: it cuts off lab-sw6, lab-sw5 and 3 unmanaged neighbors: "the uplink is being fixed"',
            $this->messages($id));
        $this->assertNotEmpty(preg_grep('/batch 3 cuts this device off from the core while it runs: maintenance window #102 covers it until/', array_column($this->events->of(511), 'message')),
            'lab-sw5\'s eventlog says why it is under maintenance');
        $this->assertNotEmpty(preg_grep('/^Ansible change #1 \(CHG-1\), batch 3: its new blast radius accepted by bob: it cuts off 2 devices and 3 unmanaged neighbors: "the uplink is being fixed"$/',
            array_column($this->events->of(502), 'message')));

        foreach ([3, 4, 5] as $number) {
            $this->endBatch($id, $number, $this->t0 + 4000 + 100 * $number);
        }
        $this->assertSame(ChangeStatus::APPLIED, $this->changes->find($id)['status']);
        $this->assertSame(self::BATCHES, $this->launched(), 'still the approved batches, in order');
    }

    public function testWithoutAnApprovalAnAdminWhoMayContinueAcceptsANewBlastRadius(): void
    {
        // the settings ask for no approval: the change is applied as it was dry-run, held to the blast radius planned with it
        $plan = self::plan();
        $id = $this->changes->add(array_intersect_key(self::TARGETS, array_flip(array_merge(...$plan->batches))), $plan);
        $lifecycle = $this->lifecycle(['approval' => ApprovalRules::OFF]);
        $this->assertNull($lifecycle->startDryRun($id, self::ALICE, [], $this->t0));
        $runId = (int) $this->changes->find($id)['dry_run_id'];
        $names = array_intersect_key(self::TARGETS, array_flip(array_merge(...$plan->batches)));
        $lines = ['DRY RUN ****', 'PLAY [Description] [CHECK MODE] ****', 'TASK [Set it] [CHECK MODE] ****'];
        foreach ($names as $name) {
            $lines[] = "changed: [$name]";
        }
        $lines[] = 'PLAY RECAP ****';
        foreach ($names as $name) {
            $lines[] = "$name : ok=1 changed=1 unreachable=0 failed=0 skipped=0 rescued=0 ignored=0";
        }
        $this->endRun($runId, array_fill_keys(array_keys($names), 'changed'), $lines, $this->t0 + 30);
        $lifecycle->advance($id, $this->t0 + 35);
        $this->assertSame([ChangeStatus::READY, null], [$this->changes->find($id)['status'], $this->changes->find($id)['approved_at']]);
        $this->assertNull($lifecycle->apply($id, self::ALICE, [], $this->t0 + 50));
        $end = function (int $number, int $at) use ($id, $lifecycle): void {
            $batchRun = (int) $this->changes->batches[$id][$number]['run_id'];
            $this->assertGreaterThan(0, $batchRun, "batch $number has a run");
            $this->endRun($batchRun, array_fill_keys(array_keys($this->runs->targets[$batchRun]), 'changed'), [], $at);
            $lifecycle->advance($id, $at + 1);
        };
        $end(1, $this->t0 + 100);
        $this->topology->cuts[502] = [512, 511];
        $end(2, $this->t0 + 200);

        $this->assertSame([ChangeStatus::PAUSED, 'blast'], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting']]);
        $this->assertSame('Its blast radius grew since the change was made: it would now also cut off lab-sw5', $this->changes->batches[$id][3]['note']);
        $this->assertStringContainsString('Batch 3: its blast radius grew since the change was made: it would now also cut off lab-sw5 (planned: lab-sw6). The batch does not '
            . 'start until an admin with the run permission accepts the new blast radius.', $this->messages($id));
        $this->assertStringContainsString('run permission (ansible.run)', (string) $lifecycle->acceptBlast($id, self::BOB, false, '', $this->t0 + 300));
        // the requester, who may continue the change, accepts it: nobody approves changes here
        $this->assertNull($lifecycle->acceptBlast($id, self::ALICE, true, 'lab-sw5 is being recabled', $this->t0 + 310, $this->seen($id)));
        $this->assertSame([[510], [511, 512, 513, 514], [502]], $this->launched());
        $this->assertSame([502, 512, 511], $this->windows->windows[102]['devices']);
        $this->assertStringContainsString('Batch 3: its new blast radius accepted by alice: it cuts off lab-sw6, lab-sw5 and 3 unmanaged neighbors: "lab-sw5 is being recabled"',
            $this->messages($id));
        $this->assertSame('alice', $this->changes->batches[$id][3]['blast_accepted']['by']);
    }

    public function testABlastRadiusThatGrewAgainSinceItWasAcceptedWaitsAgain(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->cuts[502] = [512, 511];
        $this->endBatch($id, 2, $this->t0 + 200);
        // before bob accepts, lab-sw4 loses its uplink to lab-sw3 too: what he accepts is checked again when the batch starts
        $this->topology->onBlast = function (): void {
            $this->topology->cuts[502] = [512, 511, 510];
        };
        $this->assertNull($lifecycle->acceptBlast($id, self::BOB, true, '', $this->t0 + 300, $this->seen($id)));
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'blast'], [$change['status'], $change['waiting']]);
        $this->assertSame([510], $this->changes->batches[$id][3]['blast']['added']);
        $this->assertStringContainsString('its blast radius grew since it was accepted: it would now also cut off lab-sw4 (accepted: lab-sw6, lab-sw5)', $this->messages($id));
        $this->assertCount(2, $this->launched());
    }

    public function testABlastRadiusThatGrewBetweenTheStartAndTheLaunchStopsTheLaunch(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = true;
        $this->backups->succeed = false; // the backups before batch 1 take a while
        $this->assertNull($lifecycle->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->assertSame([[510]], $this->windowDevices(), 'batch 1 started: its window is open, its backups asked for');
        $this->assertSame([], $this->launched());

        // meanwhile lab-sw4 became the only path of lab-sw8 (a cable moved): it would cut it off
        $this->topology->cuts[510] = [514];
        $this->backups->nodes[510] = ['name' => 'dev510', 'full_name' => 'lab/dev510', 'last' => ['start' => gmdate('Y-m-d H:i:s', $this->t0 + 52) . ' UTC',
            'end' => gmdate('Y-m-d H:i:s', $this->t0 + 55) . ' UTC', 'status' => 'success']];
        $lifecycle->advance($id, $this->t0 + 60);

        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'blast'], [$change['status'], $change['waiting']]);
        $this->assertSame([], $this->launched(), 'not launched: checked right before the launch');
        $this->assertSame(['pending', null], [$this->changes->batches[$id][1]['status'], $this->changes->batches[$id][1]['started_at']]);
        $this->assertSame($this->t0 + 60, $this->windows->windows[100]['end'], 'the window of that start closes now');
        $this->assertSame('closed', $this->changes->windows[1]['status']);

        $this->backups->succeed = true;
        $this->backups->now = $this->t0 + 100;
        $this->assertNull($lifecycle->acceptBlast($id, self::BOB, true, '', $this->t0 + 100, $this->seen($id)));
        $this->assertSame([[510], [510, 514]], $this->windowDevices(), 'a new window when the batch starts again, on what it cuts off now');
        $this->assertCount(2, array_filter($this->backups->requests, fn ($r) => str_contains($r['message'], 'before batch 1')), 'its backups asked for again');
        $this->assertSame([[510]], $this->launched(), 'fresh backups: it launches');
        $this->assertSame([514], $this->changes->windows[2]['cut_off']);
    }

    public function testARefusedGrownBlastRadiusEndsTheChange(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle(['refuseGrownBlast' => true]);
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->cuts[502] = [512, 511];
        $runId = (int) $this->changes->batches[$id][2]['run_id'];
        $this->endRun($runId, array_fill_keys(self::BATCHES[1], 'changed'), [], $this->t0 + 200);
        $lifecycle->advance($id, $this->t0 + 201);

        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::FAILED, $change['status']);
        $this->assertSame('batch 3 was not started: its blast radius grew since the approval: it would now also cut off lab-sw5 (setting When the blast radius grew: '
            . 'refuse the batch)', $change['error']);
        $this->assertSame(['failed', 'cancelled', 'cancelled'], array_column(array_slice($this->changes->batches[$id], 2), 'status'));
        $this->assertCount(2, $this->launched());
        $lifecycle->advance($id, $this->t0 + 2000);
        $this->assertSame(['closed'], array_values(array_unique(array_column($this->changes->windows, 'status'))));
    }

    // ------------------------------------------------------------------ cannot be computed: an admin continues explicitly, or the batch is refused

    public function testABlastRadiusThatCannotBeComputedWaitsForAnAdminToContinueWithoutIt(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->error = 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)';
        $this->endBatch($id, 2, $this->t0 + 200);

        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'blast_unknown'], [$change['status'], $change['waiting']]);
        $this->assertCount(2, $this->launched());
        $this->assertCount(2, $this->windows->windows);
        $this->assertStringContainsString('Batch 3: its blast radius cannot be computed now (the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)). '
            . 'The batch does not start until its blast radius can be computed again (checked every minute), or an admin continues it, without one if it still '
            . 'cannot be computed.', $this->messages($id));
        $this->assertStringContainsString('Only a change whose batch waits because its blast radius grew', (string) $lifecycle->acceptBlast($id, self::BOB, true, '', $this->t0 + 300));
        $lifecycle->advance($id, $this->t0 + 400);
        $this->assertSame(ChangeStatus::PAUSED, $this->changes->find($id)['status'], 'it waits for an admin');

        $this->runner->userProblems[3] = 'no longer holds the approve permission (ansible.approve)';
        $this->assertStringContainsString('continuing a batch whose blast radius cannot be computed needs the Ansible plugin\'s approve permission',
            (string) $lifecycle->resume($id, ['user_id' => 3, 'username' => 'carol'], [], $this->t0 + 450, $this->seen($id)));
        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 500, $this->seen($id)));
        $this->assertSame([[510], [511, 512, 513, 514], [502]], $this->launched(), 'continued explicitly: batch 3 goes');
        $this->assertSame([502, 512], $this->windows->windows[102]['devices'], 'its window covers the devices approved as cut off');
        $this->assertSame(['bob', 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)'],
            [$this->changes->batches[$id][3]['blast_override']['by'], $this->changes->batches[$id][3]['blast_override']['why']]);
        $this->assertSame('override', $this->changes->batches[$id][3]['blast']['state']);
        $this->assertStringContainsString('Batch 3: continued by bob without a blast radius (it cannot be computed: the topology plugin is disabled', $this->messages($id));
        $this->assertCount(1, preg_grep('/without a blast radius \(it cannot be computed|goes on without a blast radius/', $this->changes->messages($id)), 'said once');
        $this->assertNotEmpty(array_filter($this->events->entries, fn ($e) => $e['severity'] === 'warning' && str_contains($e['message'], 'continued by bob without a blast radius')),
            'recorded in the eventlog');

        // the next batch is checked again, as any: the topology plugin is back
        $this->topology->error = null;
        $this->endBatch($id, 3, $this->t0 + 600);
        $this->assertSame('same', $this->changes->batches[$id][4]['blast']['state']);
        $this->assertNull($this->changes->batches[$id][4]['blast_override'], 'the decision was for batch 3 only');
    }

    public function testAContinueOnceTheBlastRadiusCanBeComputedAgainRecordsNoDecision(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->error = 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)';
        $this->endBatch($id, 2, $this->t0 + 200);
        $this->assertSame('blast_unknown', $this->changes->find($id)['waiting']);
        $warnings = count(array_filter($this->events->entries, fn ($e) => $e['severity'] === 'warning'));

        // the admin enables the topology plugin again, then continues before the plugin checked again on its own
        $this->topology->error = null;
        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 230, $this->seen($id)));
        $this->assertSame([[510], [511, 512, 513, 514], [502]], $this->launched(), 'batch 3 starts');
        $batch = $this->changes->batches[$id][3];
        $this->assertNull($batch['blast_override'], 'no decision to go without a blast radius is recorded');
        $this->assertSame('same', $batch['blast']['state'], 'it was checked against its blast radius, as any batch');
        $this->assertStringContainsString('Batch 3: its blast radius can be computed again; continued by bob.', $this->messages($id));
        $this->assertStringNotContainsString('without a blast radius (it cannot be computed', $this->messages($id));
        $this->assertSame($warnings, count(array_filter($this->events->entries, fn ($e) => $e['severity'] === 'warning')), 'no warning in the eventlog');
        $this->assertSame([502, 512], $this->windows->windows[102]['devices']);
    }

    public function testABatchWhoseBlastRadiusCanBeComputedAgainStartsOnItsOwn(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->error = 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)';
        $this->endBatch($id, 2, $this->t0 + 200);
        $calls = count($this->topology->calls);

        // checked again at most every minute (the page polls every 2 seconds), as the admin who started the batch
        $lifecycle->advance($id, $this->t0 + 230);
        $this->assertCount($calls, $this->topology->calls, 'not checked again within the minute');
        $lifecycle->advance($id, $this->t0 + 262);
        $this->assertCount($calls + 1, $this->topology->calls, 'checked again after a minute');
        $this->assertSame(1, end($this->topology->calls)['user']);
        $this->assertSame([ChangeStatus::PAUSED, 'blast_unknown'], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting']], 'still not: it waits');
        $this->assertSame(Clock::store($this->t0 + 262), $this->changes->batches[$id][3]['blast']['at'], 'when it was checked last');
        $this->assertCount(2, $this->launched());
        $messages = $this->changes->messages($id);

        // the topology plugin is enabled again: the next check starts the batch as any
        $this->topology->error = null;
        $lifecycle->advance($id, $this->t0 + 300);
        $this->assertSame($messages, $this->changes->messages($id), 'nothing said while it waited for the minute');
        $lifecycle->advance($id, $this->t0 + 323);
        $this->assertSame([[510], [511, 512, 513, 514], [502]], $this->launched());
        $this->assertSame([ChangeStatus::APPLYING, null], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting']]);
        $this->assertNull($this->changes->batches[$id][3]['blast_override']);
        $this->assertSame('same', $this->changes->batches[$id][3]['blast']['state']);
        $this->assertStringContainsString('Batch 3: its blast radius can be computed again: the batch starts.', $this->messages($id));
    }

    public function testABlastRadiusComputedAgainThatGrewWaitsForItsAcceptance(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->error = 'an error';
        $this->endBatch($id, 2, $this->t0 + 200);
        // back, and lab-sw2 now cuts off lab-sw5 too: the batch waits for an admin to accept that, as any
        $this->topology->error = null;
        $this->topology->cuts[502] = [512, 511];
        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 230, $this->seen($id)));
        $this->assertSame([ChangeStatus::PAUSED, 'blast'], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting']]);
        $this->assertSame([511], $this->changes->batches[$id][3]['blast']['added']);
        $this->assertNull($this->changes->batches[$id][3]['blast_override']);
        $this->assertCount(2, $this->launched());
    }

    public function testARefusedUnknownBlastRadiusEndsTheChange(): void
    {
        $id = $this->approved();
        $this->topology->error = 'the topology plugin could not compute the blast radius (see logs/librenms.log)';
        $lifecycle = $this->lifecycle(['refuseUnknownBlast' => true]);

        $this->assertNull($lifecycle->apply($id, self::ALICE, [], $this->t0 + 50));
        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::FAILED, $change['status']);
        $this->assertStringStartsWith('batch 1 was not started: its blast radius cannot be computed now (the topology plugin could not compute', $change['error']);
        $this->assertSame([], $this->launched());
        $this->assertSame([], $this->windows->windows);
    }

    // ------------------------------------------------------------------ a device of the batch the topology can no longer tell about

    public function testADeviceOfTheBatchTheTopologyCanNoLongerTellAboutLeavesItsBlastRadiusUnknown(): void
    {
        // lab-sw2 (batch 3, approved as cutting off lab-sw6) is down in LibreNMS when batch 3 starts, though the runner still reaches
        // it: the topology plugin skips it ("down now") and leaves out what depends on it, so it cuts off nothing as far as it can
        // tell. That is not "shrank": the batch's blast radius cannot be computed
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->skips[502] = 'down';
        $this->endBatch($id, 2, $this->t0 + 200);

        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'blast_unknown', 3], [$change['status'], $change['waiting'], (int) $change['current_batch']]);
        $this->assertCount(2, $this->launched(), 'batch 3 is not launched');
        $this->assertCount(2, $this->windows->windows, 'nor its window opened');
        $record = $this->changes->batches[$id][3]['blast'];
        $this->assertSame(['unknown', [['device_id' => 502, 'reason' => 'down']], [], [512], [512]],
            [$record['state'], $record['unplanned'], $record['lost'], $record['held'], $record['window']], 'the device and the reason in the record');
        $this->assertSame('Its blast radius cannot be computed now (the topology plugin can no longer tell what depends on lab-sw2 (down now))',
            $this->changes->batches[$id][3]['note']);
        $this->assertStringContainsString('Batch 3: its blast radius cannot be computed now (the topology plugin can no longer tell what depends on lab-sw2 (down now)). '
            . 'The batch does not start until its blast radius can be computed again (checked every minute), or an admin continues it', $this->messages($id));
        $this->assertStringNotContainsString('shrank', $this->messages($id));
        $this->assertSame('Ansible change #1 (CHG-1), batch 3: its blast radius cannot be computed now (the topology plugin can no longer tell what depends on 1 device '
            . 'of the batch (down now)). The batch does not start until its blast radius can be computed again (checked every minute), or an admin continues it, '
            . 'without one if it still cannot be computed.', end($this->events->entries)['message'], 'the eventlog of the targets counts it');

        // checked again every minute: still down, it waits
        $lifecycle->advance($id, $this->t0 + 262);
        $this->assertSame('blast_unknown', $this->changes->find($id)['waiting']);
        $this->assertSame([Clock::store($this->t0 + 262), [['device_id' => 502, 'reason' => 'down']]],
            [$this->changes->batches[$id][3]['blast']['at'], $this->changes->batches[$id][3]['blast']['unplanned']]);
        $this->assertCount(2, $this->launched());

        // an admin continues it without a blast radius: its window covers lab-sw2 and lab-sw6, approved as cut off (what lab-sw2 cuts
        // off while it is down in LibreNMS is not known), and the decision is recorded
        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 300, $this->seen($id)));
        $this->assertSame([[510], [511, 512, 513, 514], [502]], $this->launched());
        $this->assertSame([502, 512], $this->windows->windows[102]['devices']);
        $this->assertSame([512], $this->changes->windows[3]['cut_off']);
        $batch = $this->changes->batches[$id][3];
        $this->assertSame(['override', [512], 'the topology plugin can no longer tell what depends on lab-sw2 (down now)'],
            [$batch['blast']['state'], $batch['blast']['window'], $batch['blast_override']['why']]);
        $this->assertStringContainsString('Batch 3: continued by bob without a blast radius (it cannot be computed: the topology plugin can no longer tell what depends '
            . 'on lab-sw2 (down now)); its maintenance window covers its devices and the devices approved as cut off: lab-sw6', $this->messages($id));
        $this->assertMatchesRegularExpression('/^Batch 3 of 5 launched by bob \(run #\d+\): its blast radius unknown \(continued without one\), its window covers lab-sw6\.$/m',
            $this->messages($id));
        $this->assertNotEmpty(preg_grep('/^Ansible change #1 \(CHG-1\), batch 3: continued by bob without a blast radius \(it cannot be computed: the topology plugin can '
            . 'no longer tell what depends on 1 device of the batch \(down now\)\); its maintenance window covers its devices and the 1 device approved as cut off$/',
            array_column($this->events->of(501), 'message')), 'in the eventlog, in counts');
    }

    public function testEveryReasonTheTopologyCannotTellAboutADeviceLeavesTheBlastRadiusUnknown(): void
    {
        // lab-sw3's LLDP links are gone (a discovery run, or a change that touched LLDP), its uplink is down, or the admin who starts
        // the batch may no longer see it: what depends on it is unknown
        foreach (['isolated' => 'no LLDP/CDP neighbors: no topology data', 'unconnected' => 'no path to the core now', 'unknown' => 'unknown, disabled or not visible'] as $reason => $text) {
            $this->setUp();
            $id = $this->approved(self::plan([502, 503]));
            $this->topology->skips[503] = $reason;
            $this->lifecycle()->apply($id, self::ALICE, [], $this->t0 + 50);
            $this->endBatch($id, 1, $this->t0 + 100);
            $change = $this->changes->find($id);
            $this->assertSame([ChangeStatus::PAUSED, 'blast_unknown', 2], [$change['status'], $change['waiting'], (int) $change['current_batch']], $reason);
            $this->assertSame("Its blast radius cannot be computed now (the topology plugin can no longer tell what depends on lab-sw3 ($text))",
                $this->changes->batches[$id][2]['note'], $reason);
            $this->assertSame([[502]], $this->launched(), $reason);
        }
    }

    public function testAnAcceptanceWhileADeviceIsSkippedKeepsWhatTheBatchWasHeldTo(): void
    {
        // batch 2 (lab-sw6, lab-sw7, lab-sw8) was approved to cut off lab-sw3, behind lab-sw7. Then lab-sw7's LLDP links vanish (what depends
        // on it is unknown: lab-sw3 is no longer in what the check computes) and lab-sw6 now cuts off lab-sw2: it grew. The acceptance of the
        // growth keeps lab-sw3, and the window of the admin's Continue without a blast radius covers what was approved and what was accepted
        $cuts = [513 => [503]] + RolloutPlanTest::CUTS;
        $plan = RolloutPlan::make([511, 512, 513, 514], [], 1, 5, 1, false, 0, RolloutPlanTest::planner([511, 512, 513, 514], 5), RolloutPlan::LEAVE,
            fn (array $batch) => RolloutPlanTest::blast($batch, $cuts))['plan'];
        $this->assertSame([[511], [512, 513, 514]], $plan->batches);
        $this->assertSame([503], $plan->topology->batch(2)['lost']);
        $this->topology->cuts = $cuts;
        $id = $this->approved($plan);
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->topology->skips[513] = 'isolated';
        $this->topology->cuts[512] = [502];
        $this->endBatch($id, 1, $this->t0 + 100);

        $record = $this->changes->batches[$id][2]['blast'];
        $this->assertSame(['grew', [502], [503], [['device_id' => 513, 'reason' => 'isolated']]], [$record['state'], $record['lost'], $record['held'], $record['unplanned']]);
        $this->assertSame([503, 502], $record['window'], 'what it was held to, and what it cuts off now');
        $this->assertNull($lifecycle->acceptBlast($id, self::BOB, true, 'lab-sw2 may go', $this->t0 + 200, $this->seen($id)));
        $this->assertSame([[503, 502], [503, 502]], [$this->changes->batches[$id][2]['blast_accepted']['lost'], $this->changes->batches[$id][2]['blast_accepted']['all']],
            'the acceptance keeps lab-sw3: what depends on lab-sw7 is unknown');
        $this->assertSame([ChangeStatus::PAUSED, 'blast_unknown'], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting']]);

        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 300, $this->seen($id)));
        $this->assertSame([[511], [512, 513, 514]], $this->launched());
        $this->assertSame([512, 513, 514, 503, 502], $this->windows->windows[101]['devices'], 'its window covers lab-sw3 (approved) and lab-sw2 (accepted)');
    }

    public function testTheWindowOfAContinueWithoutABlastRadiusCoversWhatWasApprovedAndEverythingAccepted(): void
    {
        // batch 3 (lab-sw2), approved to cut off lab-sw6, grew to lab-sw5 and lab-sw6 and was accepted; it grew again, to lab-sw5 alone (lab-sw6
        // got another uplink) and lab-sw4, which was accepted too. Then the topology plugin cannot answer: the window of the admin's Continue
        // covers lab-sw6, lab-sw5 and lab-sw4, everything approved or accepted
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->cuts[502] = [512, 511];
        $this->endBatch($id, 2, $this->t0 + 200);
        $this->topology->cuts[502] = [511, 510];
        $this->assertNull($lifecycle->acceptBlast($id, self::BOB, true, '', $this->t0 + 300, $this->seen($id)));
        $this->assertSame([ChangeStatus::PAUSED, 'blast', [510]], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting'], $this->changes->batches[$id][3]['blast']['added']]);
        $this->topology->error = 'an error';
        $this->assertNull($lifecycle->acceptBlast($id, self::BOB, true, '', $this->t0 + 400, $this->seen($id)));
        $this->assertSame([[511, 510], [512, 511, 510]], [$this->changes->batches[$id][3]['blast_accepted']['lost'], $this->changes->batches[$id][3]['blast_accepted']['all']]);
        $this->assertSame('blast_unknown', $this->changes->find($id)['waiting']);
        $this->assertSame([511, 510, 512], $this->changes->batches[$id][3]['blast']['window']);
        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 500, $this->seen($id)));
        $this->assertSame([502, 511, 510, 512], $this->windows->windows[102]['devices']);
    }

    public function testADeviceTheTopologyCanNoLongerTellAboutRightBeforeTheLaunchStopsTheLaunch(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = true;
        $this->backups->succeed = false; // the backups before batch 1 take a while
        $this->assertNull($lifecycle->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->assertSame([[510]], $this->windowDevices(), 'batch 1 started: its window is open, its backups asked for');

        // meanwhile lab-sw4's LLDP links are gone (a discovery run): checked right before the launch
        $this->topology->skips[510] = 'isolated';
        $this->backups->nodes[510] = ['name' => 'dev510', 'full_name' => 'lab/dev510', 'last' => ['start' => gmdate('Y-m-d H:i:s', $this->t0 + 52) . ' UTC',
            'end' => gmdate('Y-m-d H:i:s', $this->t0 + 55) . ' UTC', 'status' => 'success']];
        $lifecycle->advance($id, $this->t0 + 60);

        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'blast_unknown'], [$change['status'], $change['waiting']]);
        $this->assertSame([], $this->launched(), 'not launched');
        $this->assertSame('closed', $this->changes->windows[1]['status'], 'the window of that start closes');
        $this->assertSame([['device_id' => 510, 'reason' => 'isolated']], $this->changes->batches[$id][1]['blast']['unplanned']);
    }

    public function testABlastRadiusThatGrewWhileADeviceIsSkippedIsAcceptedFirst(): void
    {
        // lab-sw7's LLDP links are gone and lab-sw5 now cuts off lab-sw4 (recabled behind it): what grew waits for an approver, then
        // the batch waits for an admin to go on without a blast radius, and its window covers what was accepted
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->topology->skips[513] = 'isolated';
        $this->topology->cuts[511] = [510];
        $this->endBatch($id, 1, $this->t0 + 100);

        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'blast', 2], [$change['status'], $change['waiting'], (int) $change['current_batch']]);
        $record = $this->changes->batches[$id][2]['blast'];
        $this->assertSame(['grew', [510], [['device_id' => 513, 'reason' => 'isolated']]], [$record['state'], $record['added'], $record['unplanned']]);
        $this->assertStringContainsString('lab-sw7', $record['error'], 'the page says it too');
        $this->assertStringContainsString('accept its new blast radius', (string) $lifecycle->resume($id, self::ALICE, [], $this->t0 + 150), 'Continue does not start it');

        $this->assertNull($lifecycle->acceptBlast($id, self::BOB, true, '', $this->t0 + 200, $this->seen($id)));
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'blast_unknown', 2], [$change['status'], $change['waiting'], (int) $change['current_batch']]);
        $this->assertSame([[510]], $this->launched());
        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 300, $this->seen($id)));
        $this->assertSame([[510], [511, 512, 513, 514]], $this->launched());
        $this->assertSame([511, 512, 513, 514, 510], $this->windows->windows[101]['devices'], 'its window covers what was accepted');
    }

    public function testAnAdminsDecisionToGoWithoutABlastRadiusHoldsOnlyWhileItCannotBeComputed(): void
    {
        // the topology plugin cannot answer when batch 3 starts; an admin continues it without a blast radius; its backups take a while
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->error = 'an error';
        $this->endBatch($id, 2, $this->t0 + 200);
        $this->backups->enabled = true;
        $this->backups->succeed = false;
        $this->backups->now = $this->t0 + 300;
        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 300, $this->seen($id)));
        $this->assertSame(['override', 'preparing'], [$this->changes->batches[$id][3]['blast']['state'], $this->changes->batches[$id][3]['status']]);
        $this->assertCount(2, $this->launched(), 'it waits for its backups');

        // right before its launch the topology plugin answers again, and lab-sw2 now cuts off lab-sw5 too: the decision was to go
        // without a blast radius, not with one that grew
        $this->topology->error = null;
        $this->topology->cuts[502] = [512, 511];
        $this->backups->nodes[502] = ['name' => 'dev502', 'full_name' => 'lab/dev502', 'last' => ['start' => gmdate('Y-m-d H:i:s', $this->t0 + 302) . ' UTC',
            'end' => gmdate('Y-m-d H:i:s', $this->t0 + 305) . ' UTC', 'status' => 'success']];
        $lifecycle->advance($id, $this->t0 + 310);
        $this->assertSame([ChangeStatus::PAUSED, 'blast'], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting']]);
        $this->assertSame([511], $this->changes->batches[$id][3]['blast']['added']);
        $this->assertCount(2, $this->launched(), 'not launched');
    }

    public function testADeviceTheBatchNoLongerCutsOffRightBeforeItsLaunchLeavesItsWindowAndItsEventlogSaysSo(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->endBatch($id, 2, $this->t0 + 200);
        // batch 4 (lab-sw3) starts cutting off lab-sw7 and lab-sw8; before its launch (after its backups) lab-sw8 got a second uplink
        $this->backups->enabled = true;
        $this->backups->succeed = false;
        $this->endBatch($id, 3, $this->t0 + 300);
        $this->assertSame([503, 513, 514], $this->windows->windows[103]['devices'] ?? null);
        $this->topology->cuts[503] = [513];
        foreach ([502, 503] as $deviceId) {
            $this->backups->nodes[$deviceId] = ['name' => "dev$deviceId", 'full_name' => "lab/dev$deviceId", 'last' => ['start' => gmdate('Y-m-d H:i:s', $this->t0 + 305) . ' UTC',
                'end' => gmdate('Y-m-d H:i:s', $this->t0 + 306) . ' UTC', 'status' => 'success']];
        }
        $lifecycle->advance($id, $this->t0 + 310);

        $this->assertSame([[510], [511, 512, 513, 514], [502], [503]], $this->launched());
        $this->assertSame([503, 513], $this->windows->windows[103]['devices'], 'lab-sw8 is out of the window');
        $this->assertSame([513], $this->changes->windows[4]['cut_off']);
        $this->assertStringContainsString('Maintenance window #103 of batch 4 now covers what the batch cuts off right before its launch: no longer lab-sw8.', $this->messages($id));
        $this->assertSame(['Ansible change #1 (CHG-1), batch 4: maintenance window #103 no longer covers this device: the batch no longer cuts it off'],
            array_values(preg_grep('/no longer covers this device/', array_column($this->events->of(514), 'message'))), 'lab-sw8\'s eventlog says so');
        $this->assertSame([], preg_grep('/no longer covers this device/', array_column($this->events->of(513), 'message')), 'not lab-sw7\'s: still covered');
    }

    // ------------------------------------------------------------------ an acceptance, a Continue: for the blast radius the page showed

    public function testAnAcceptanceIsForTheBlastRadiusThePageShowed(): void
    {
        // two approvers (or one in two tabs) look at batch 3, which would now also cut off lab-sw5; meanwhile lab-sw4 loses its uplink
        // to lab-sw3 too. The first acceptance starts the batch, which grew again and waits; the second, given for what the pages
        // showed, does not accept the second growth nobody saw
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->cuts[502] = [512, 511];
        $this->endBatch($id, 2, $this->t0 + 200);
        $shown = $this->seen($id);
        $this->topology->cuts[502] = [512, 511, 510];
        $dave = ['user_id' => 4, 'username' => 'dave'];

        $this->assertNull($lifecycle->acceptBlast($id, self::BOB, true, 'tab 1: lab-sw5 ok', $this->t0 + 300, $shown));
        $this->assertSame([ChangeStatus::PAUSED, 'blast', [510]], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting'],
            $this->changes->batches[$id][3]['blast']['added']]);
        $this->assertSame(ChangeLifecycle::SEEN_CHANGED, $lifecycle->acceptBlast($id, $dave, true, 'tab 2: lab-sw5 ok', $this->t0 + 301, $shown));
        $this->assertSame(['bob', [512, 511]], [$this->changes->batches[$id][3]['blast_accepted']['by'], $this->changes->batches[$id][3]['blast_accepted']['lost']],
            'still held to what was accepted, as shown');
        $this->assertSame(ChangeLifecycle::SEEN_CHANGED, $lifecycle->acceptBlast($id, $dave, true, '', $this->t0 + 302), 'nor without naming the check');
        $this->assertCount(2, $this->launched());
        $this->assertSame(1, substr_count($this->messages($id), 'its new blast radius accepted by'), 'one acceptance recorded');

        // looked again: accepted
        $this->assertNull($lifecycle->acceptBlast($id, $dave, true, 'lab-sw4 too', $this->t0 + 310, $this->seen($id)));
        $this->assertSame([[510], [511, 512, 513, 514], [502]], $this->launched());
        $this->assertSame([512, 511, 510], $this->changes->batches[$id][3]['blast_accepted']['lost']);
    }

    public function testOnlyABlastRadiusThatGrewIsAccepted(): void
    {
        // the change waits for the acceptance of batch 3's new blast radius, but the batch's check says otherwise (a row changed by
        // hand, a request of an older version): nothing is accepted, the batch does not start
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->cuts[502] = [512, 511];
        $this->endBatch($id, 2, $this->t0 + 200);
        $this->changes->batches[$id][3]['blast'] = ['state' => 'same'] + $this->changes->batches[$id][3]['blast'];

        $this->assertSame('Batch 3 has no new blast radius to accept: reload the page.', $lifecycle->acceptBlast($id, self::BOB, true, '', $this->t0 + 300, $this->seen($id)));
        $this->assertNull($this->changes->batches[$id][3]['blast_accepted']);
        $this->assertSame([ChangeStatus::PAUSED, 2], [$this->changes->find($id)['status'], count($this->launched())]);
    }

    public function testWhoMayContinueABatchWithoutABlastRadiusIsWhoMayAcceptOne(): void
    {
        // a second admin approves: the requester may not go past what nobody accepted by making the blast radius unknown (the topology plugin
        // disabled), then pressing Continue; an approver who is not the requester may
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->error = 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)';
        $this->endBatch($id, 2, $this->t0 + 200);
        $this->assertSame('The settings need a second admin: you requested this change, so another admin with the approve permission continues it without a blast radius.',
            $lifecycle->resume($id, self::ALICE, [], $this->t0 + 300, $this->seen($id)));
        $carol = ['user_id' => 3, 'username' => 'carol'];
        $this->runner->permissionProblems['3:approve'] = 'does not hold the approve permission (ansible.approve)';
        $this->assertStringContainsString('needs the Ansible plugin\'s approve permission (ansible.approve)', (string) $lifecycle->resume($id, $carol, [], $this->t0 + 310, $this->seen($id)),
            'an admin with the run permission only');
        $this->assertSame([ChangeStatus::PAUSED, 'blast_unknown', 2], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting'], count($this->launched())]);
        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 320, $this->seen($id)));
        $this->assertSame('bob', $this->changes->batches[$id][3]['blast_override']['by']);

        // without an approval: an admin with the run permission (the requester too)
        $this->setUp();
        $plan = self::plan();
        $id = $this->changes->add(array_intersect_key(self::TARGETS, array_flip(array_merge(...$plan->batches))), $plan);
        $lifecycle = $this->lifecycle(['approval' => ApprovalRules::OFF]);
        $this->assertNull($lifecycle->startDryRun($id, self::ALICE, [], $this->t0));
        $runId = (int) $this->changes->find($id)['dry_run_id'];
        $names = array_intersect_key(self::TARGETS, array_flip(array_merge(...$plan->batches)));
        $lines = ['DRY RUN ****', 'PLAY [Description] [CHECK MODE] ****', 'TASK [Set it] [CHECK MODE] ****', ...array_map(fn ($n) => "changed: [$n]", $names), 'PLAY RECAP ****',
            ...array_map(fn ($n) => "$n : ok=1 changed=1 unreachable=0 failed=0 skipped=0 rescued=0 ignored=0", $names)];
        $this->endRun($runId, array_fill_keys(array_keys($names), 'changed'), $lines, $this->t0 + 30);
        $lifecycle->advance($id, $this->t0 + 35);
        $this->assertNull($lifecycle->apply($id, self::ALICE, [], $this->t0 + 50));
        $batchRun = (int) $this->changes->batches[$id][1]['run_id'];
        $this->endRun($batchRun, array_fill_keys(array_keys($this->runs->targets[$batchRun]), 'changed'), [], $this->t0 + 100);
        $this->topology->error = 'an error';
        $lifecycle->advance($id, $this->t0 + 101);
        $this->assertSame('blast_unknown', $this->changes->find($id)['waiting']);
        $this->assertNull($lifecycle->resume($id, self::ALICE, [], $this->t0 + 200, $this->seen($id)), 'the requester, with the run permission');
        $this->assertSame('alice', $this->changes->batches[$id][2]['blast_override']['by']);
    }

    public function testAnApproverWithoutTheRunPermissionContinuesItAsTheAdminWhoStartedIt(): void
    {
        // bob may accept a new blast radius but launches nothing: the batch he continues without a blast radius starts as alice, who applied
        // the change, as after an acceptance (the secret answers he sent are not used)
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->error = 'an error';
        $this->endBatch($id, 2, $this->t0 + 200);
        $this->runner->permissionProblems['2:run'] = 'does not hold the run permission (ansible.run)';
        $this->assertNull($lifecycle->resume($id, self::BOB, ['key' => 'ignored'], $this->t0 + 300, $this->seen($id)));
        $launch = end($this->runner->launches);
        $this->assertSame([3, [502], 'alice', []], [$launch['number'], $launch['devices'], $launch['user'], $launch['secrets']]);
        $this->assertSame(['bob', 'alice'], [$this->changes->batches[$id][3]['blast_override']['by'], $this->changes->batches[$id][3]['username']]);
        $this->assertStringContainsString('Batch 3: continued by bob without a blast radius (it cannot be computed: an error)', $this->messages($id));
        $this->assertSame(ChangeStatus::APPLYING, $this->changes->find($id)['status']);

        // a template with secret answers: the batch then waits for an admin with the run permission to give them
        $this->setUp();
        $secret = ['name' => 'key', 'label' => 'Key', 'type' => 'secret', 'value' => '********', 'secret' => true];
        $id = $this->approved(null, ['has_secrets' => true, 'variables' => [$secret]]);
        $lifecycle = $this->lifecycle();
        $this->assertNull($lifecycle->apply($id, self::ALICE, ['key' => 's3cret'], $this->t0 + 50));
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->assertSame('secrets', $this->changes->find($id)['waiting']);
        $this->assertNull($lifecycle->resume($id, self::ALICE, ['key' => 's3cret'], $this->t0 + 110));
        $this->topology->error = 'an error';
        $this->endBatch($id, 2, $this->t0 + 200);
        $this->assertSame('blast_unknown', $this->changes->find($id)['waiting']);
        $this->runner->permissionProblems['2:run'] = 'does not hold the run permission (ansible.run)';
        $this->assertNull($lifecycle->resume($id, self::BOB, ['key' => 'not used'], $this->t0 + 300, $this->seen($id)));
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'secrets', 'prepared', 2], [$change['status'], $change['waiting'], $this->changes->batches[$id][3]['status'],
            count($this->launched())], 'batch 3 waits for the secret answers');
        $this->assertSame('bob', $this->changes->batches[$id][3]['blast_override']['by']);
        $this->assertSame('You may not continue the change: does not hold the run permission (ansible.run).',
            $lifecycle->resume($id, self::BOB, ['key' => 's3cret'], $this->t0 + 310), 'bob does not give them');
        $this->assertNull($lifecycle->resume($id, self::ALICE, ['key' => 's3cret'], $this->t0 + 320));
        $launch = end($this->runner->launches);
        $this->assertSame([3, 'alice', ['key' => 's3cret']], [$launch['number'], $launch['user'], $launch['secrets']]);
    }

    public function testAContinueGoesWithoutABlastRadiusOnlyForTheReasonThePageShowed(): void
    {
        // the page shows batch 3 waiting as the topology plugin is disabled; before the minute's check, it is enabled again and lab-sw2 is
        // down in LibreNMS. The Continue computes first and finds another reason than the page showed: it is refused, the page shows it
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->error = 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)';
        $this->endBatch($id, 2, $this->t0 + 200);
        $shown = $this->seen($id);
        $this->topology->error = null;
        $this->topology->skips[502] = 'down';

        $this->assertSame(ChangeLifecycle::SEEN_CHANGED, $lifecycle->resume($id, self::BOB, [], $this->t0 + 220, $shown));
        $this->assertSame([ChangeStatus::PAUSED, 'blast_unknown'], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting']]);
        $this->assertSame('the topology plugin can no longer tell what depends on lab-sw2 (down now)', $this->changes->batches[$id][3]['blast']['error'],
            'the page shows what the Continue found');
        $this->assertNull($this->changes->batches[$id][3]['blast_override']);
        $this->assertCount(2, $this->launched());

        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 230, $this->seen($id)));
        $this->assertSame('the topology plugin can no longer tell what depends on lab-sw2 (down now)', $this->changes->batches[$id][3]['blast_override']['why']);
        $this->assertCount(3, $this->launched());
    }

    public function testAContinueGivenForOneBatchDoesNotContinueAnotherWhoseCheckReadsTheSame(): void
    {
        // batches of one leaf switch each, approved to cut off nothing; the topology plugin cannot answer after batch 1. Batch 2 waits, and
        // so will batch 3, with a check that reads the same: admin B's page loads batch 2's wait, admin A continues batch 2 with it, and B's
        // Continue, given for batch 2, does not continue batch 3 without a blast radius
        $plan = RolloutPlan::make([511, 513, 514], [], 1, 1, 1, false, 0, RolloutPlanTest::planner([511, 513, 514], 1), RolloutPlan::LEAVE,
            fn (array $batch) => RolloutPlanTest::blast($batch))['plan'];
        $this->assertSame([[511], [513], [514]], $plan->batches);
        $id = $this->approved($plan);
        $lifecycle = $this->lifecycle();
        $dave = ['user_id' => 4, 'username' => 'dave'];
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->topology->error = 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)';
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->assertSame([2, 'blast_unknown'], [(int) $this->changes->find($id)['current_batch'], $this->changes->find($id)['waiting']]);
        $pageOfDave = $this->seen($id);

        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 150, $pageOfDave));
        $this->endBatch($id, 2, $this->t0 + 200);
        $this->assertSame([3, 'blast_unknown'], [(int) $this->changes->find($id)['current_batch'], $this->changes->find($id)['waiting']]);
        $this->assertSame(array_diff_key($this->changes->batches[$id][2]['blast'], ['state' => 1, 'at' => 1]),
            array_diff_key($this->changes->batches[$id][3]['blast'], ['state' => 1, 'at' => 1]), 'batch 3\'s check reads as batch 2\'s did');
        $this->assertSame(ChangeLifecycle::SEEN_CHANGED, $lifecycle->resume($id, $dave, [], $this->t0 + 250, $pageOfDave), 'given for batch 2: refused for batch 3');
        $this->assertSame([[511], [513]], $this->launched());
        $this->assertNull($this->changes->batches[$id][3]['blast_override']);
        $this->assertNull($lifecycle->resume($id, $dave, [], $this->t0 + 260, $this->seen($id)), 'given for batch 3: continued');
        $this->assertSame([[511], [513], [514]], $this->launched());
    }

    public function testAContinueWithoutABlastRadiusIsForWhatThePageShowed(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->topology->error = 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)';
        $this->endBatch($id, 2, $this->t0 + 200);
        $shown = $this->seen($id);
        $lifecycle->advance($id, $this->t0 + 262);
        $this->assertSame($shown, $this->seen($id), 'checked again a minute later, the same: the same check for the page');

        // the topology plugin is enabled again, and lab-sw2 is down in LibreNMS: why it cannot be computed is no longer what the page showed
        $this->topology->error = null;
        $this->topology->skips[502] = 'down';
        $lifecycle->advance($id, $this->t0 + 330);
        $this->assertNotSame($shown, $this->seen($id));
        $this->assertSame(ChangeLifecycle::SEEN_CHANGED, $lifecycle->resume($id, self::BOB, [], $this->t0 + 340, $shown));
        $this->assertSame(ChangeLifecycle::SEEN_CHANGED, $lifecycle->resume($id, self::BOB, [], $this->t0 + 341), 'nor without naming the check');
        $this->assertNull($this->changes->batches[$id][3]['blast_override']);
        $this->assertCount(2, $this->launched());

        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 350, $this->seen($id)));
        $this->assertSame('the topology plugin can no longer tell what depends on lab-sw2 (down now)', $this->changes->batches[$id][3]['blast_override']['why']);
        $this->assertSame([[510], [511, 512, 513, 514], [502]], $this->launched());
    }

    // ------------------------------------------------------------------ the last core device of its part

    public function testABatchThatNowTakesDownEveryCoreDeviceOfItsPartGrew(): void
    {
        // lab-sw1 shares the core of its part with a core device outside the change: its batch was approved as cutting off nothing,
        // measured from the core left standing. That one went down since: lab-sw1 is the last core device of its part, and what its
        // batch cuts off is measured from the largest piece left (nothing, here). Its blast radius grew all the same
        $id = $this->approved(self::plan([501]));
        $this->assertSame(['core', []], [$this->changes->changes[$id]['plan']['topology']['batches'][0]['reference'], $this->changes->changes[$id]['plan']['topology']['batches'][0]['lost']]);
        $this->topology->lastCore = [501];
        $lifecycle = $this->lifecycle();
        $this->assertNull($lifecycle->apply($id, self::ALICE, [], $this->t0 + 50));

        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'blast', 1], [$change['status'], $change['waiting'], (int) $change['current_batch']]);
        $this->assertSame([], $this->launched(), 'lab-sw1 is not rebooted');
        $record = $this->changes->batches[$id][1]['blast'];
        $this->assertSame(['grew', true, [], 'largest part', 'core'], [$record['state'], $record['core_lost'], $record['added'], $record['reference'], $record['held_reference']],
            'the record keeps the reference of the check and the one it is held to');
        $this->assertSame('Its blast radius grew since the approval: it now takes down every core device of its part, so what it cuts off is measured from the largest piece left',
            $this->changes->batches[$id][1]['note']);
        $this->assertStringContainsString('Batch 1: its blast radius grew since the approval: it now takes down every core device of its part, so what it cuts off is measured '
            . 'from the largest piece left (approved: no other monitored device). The batch does not start until an admin with the approve permission (not the requester) '
            . 'accepts the new blast radius.', $this->messages($id));
        $this->assertSame('Ansible change #1 (CHG-1), batch 1: its blast radius grew since the approval: it now takes down every core device of its part, so what it cuts off '
            . 'is measured from the largest piece left (no other monitored device in all). The batch does not start until an admin with the approve permission (not the '
            . 'requester) accepts the new blast radius.', end($this->events->entries)['message']);

        // accepted: held to it from then on, the batch starts, its window as the topology plugin models it (the batch, and what it cuts
        // off from the largest piece left)
        $this->assertNull($lifecycle->acceptBlast($id, self::BOB, true, 'the other core is being replaced', $this->t0 + 100, $this->seen($id)));
        $this->assertSame([[501]], $this->launched());
        $this->assertSame(['largest part', 'same'], [$this->changes->batches[$id][1]['blast_accepted']['reference'], $this->changes->batches[$id][1]['blast']['state']]);
        $this->assertSame([[501]], $this->windowDevices());
        $this->assertStringContainsString('Batch 1: its new blast radius accepted by bob: it cuts off no other monitored device, measured from the largest piece left (it takes '
            . 'down every core device of its part): "the other core is being replaced"', $this->messages($id));
    }

    public function testTheCoresOwnBatchMeasuredFromTheLargestPieceLeftIsHeldToThat(): void
    {
        // the only core device of its part: approved as taking every core device down, checked so: the same (and not more when a second
        // core device appears meanwhile)
        $plan = RolloutPlan::make([502, 501], [], 1, 5, 1, false, 0, RolloutPlanTest::planner([502, 501], 5), RolloutPlan::LEAVE,
            fn (array $batch) => ['reference' => in_array(501, $batch, true) ? 'largest part' : 'core'] + RolloutPlanTest::blast($batch))['plan'];
        $id = $this->approved($plan);
        $this->topology->lastCore = [501];
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->assertSame([[502], [501]], $this->launched());
        $this->assertSame(['same', 'largest part'], [$this->changes->batches[$id][2]['blast']['state'], $this->changes->batches[$id][2]['blast']['reference']]);
        $this->endBatch($id, 2, $this->t0 + 200);
        $this->assertSame(ChangeStatus::APPLIED, $this->changes->find($id)['status']);

        $id = $this->approved($plan);
        $this->topology->lastCore = [];
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 300);
        $this->endBatch($id, 1, $this->t0 + 400);
        $this->assertSame(['same', 'core'], [$this->changes->batches[$id][2]['blast']['state'], $this->changes->batches[$id][2]['blast']['reference']]);
    }

    public function testARefusedBatchThatNowTakesDownEveryCoreDeviceOfItsPartEndsTheChange(): void
    {
        $id = $this->approved(self::plan([501]));
        $this->topology->lastCore = [501];
        $this->topology->cuts[501] = [503];
        $this->assertNull($this->lifecycle(['refuseGrownBlast' => true])->apply($id, self::ALICE, [], $this->t0 + 50));
        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::FAILED, $change['status']);
        $this->assertSame('batch 1 was not started: its blast radius grew since the approval: it now takes down every core device of its part, so what it cuts off is '
            . 'measured from the largest piece left, and it would also cut off lab-sw3 (setting When the blast radius grew: refuse the batch)', $change['error']);
        $this->assertNotEmpty(preg_grep('/batch 1 was not started: its blast radius grew since the approval: it now takes down every core device of its part, so what it cuts '
            . 'off is measured from the largest piece left, and it would cut off 1 more device than approved \(setting/', array_column($this->events->of(501), 'message')));
        $this->assertSame([], $this->launched());
    }

    // ------------------------------------------------------------------ shrank: it goes on, and the window covers what it cuts off now

    public function testABlastRadiusThatShrankGoesOnAndSaysSo(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        // lab-sw8 got a second uplink: lab-sw3 now cuts off lab-sw7 only
        $this->topology->cuts[503] = [513];
        foreach ([1, 2, 3] as $number) {
            $this->endBatch($id, $number, $this->t0 + 100 * $number);
        }

        $this->assertSame([[510], [511, 512, 513, 514], [502], [503]], $this->launched());
        $this->assertSame([503, 513], $this->windows->windows[103]['devices'], 'only the batch and what it cuts off now');
        $this->assertSame(['shrank', [514]], [$this->changes->batches[$id][4]['blast']['state'], $this->changes->batches[$id][4]['blast']['removed']]);
        $this->assertSame(1, substr_count($this->messages($id), 'Batch 4: its blast radius shrank since the approval: it no longer cuts off lab-sw8; it goes on.'),
            'said once, not again right before the launch');
    }

    public function testTheWindowFollowsTheBlastRadiusComputedRightBeforeTheLaunch(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->endBatch($id, 2, $this->t0 + 200);
        // batch 4 starts (when batch 3 ends) while lab-sw8 has a second uplink (it cuts off lab-sw7 only), which is gone again right
        // before its launch (after its backups)
        $this->backups->enabled = true;
        $this->backups->succeed = false;
        $this->topology->cuts[503] = [513];
        $this->endBatch($id, 3, $this->t0 + 300);
        $this->assertSame([503, 513], $this->windows->windows[103]['devices'] ?? null, 'opened on what it cut off at its start');
        $this->assertSame([[510], [511, 512, 513, 514], [502]], $this->launched(), 'batch 4 waits for its backups');
        $this->topology->cuts[503] = [513, 514];
        foreach ([502, 503] as $deviceId) {
            $this->backups->nodes[$deviceId] = ['name' => "dev$deviceId", 'full_name' => "lab/dev$deviceId", 'last' => ['start' => gmdate('Y-m-d H:i:s', $this->t0 + 305) . ' UTC',
                'end' => gmdate('Y-m-d H:i:s', $this->t0 + 306) . ' UTC', 'status' => 'success']];
        }
        $lifecycle->advance($id, $this->t0 + 310);

        $this->assertSame([[510], [511, 512, 513, 514], [502], [503]], $this->launched(), 'not more than approved: it launches');
        $this->assertSame([503, 513, 514], $this->windows->windows[103]['devices'], 'the window covers lab-sw8 again');
        $this->assertSame([513, 514], $this->changes->windows[4]['cut_off']);
        $this->assertStringContainsString('Maintenance window #103 of batch 4 now covers what the batch cuts off right before its launch: also lab-sw8.', $this->messages($id));
        $this->assertNotEmpty(preg_grep('/batch 4 cuts this device off from the core while it runs: maintenance window #103 covers it until/', array_column($this->events->of(514), 'message')));
    }

    // ------------------------------------------------------------------ the rest of the lifecycle around the waits

    public function testAStopWhileABatchWaitsForAnApproverEndsTheChange(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->topology->cuts[510] = [514];
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->assertSame('blast', $this->changes->find($id)['waiting']);

        $this->assertNull($lifecycle->stop($id, 'alice', $this->t0 + 60));
        $lifecycle->advance($id, $this->t0 + 61);
        $this->assertSame(ChangeStatus::STOPPED, $this->changes->find($id)['status']);
        $this->assertSame('stopped by alice before batch 1 was launched', $this->changes->find($id)['error']);
        $this->assertSame([], $this->launched());
    }

    public function testAWaitForAnApproverEndsWhenTheApprovalNoLongerCounts(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->topology->cuts[510] = [514];
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $lifecycle->advance($id, $this->t0 + 86400 + 100);

        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::FAILED, $change['status']);
        $this->assertSame('while it waited before batch 1, its dry run expired (Dry run valid setting)', $change['error']);
    }

    public function testTheBlastRadiusIsComputedAsTheAdminWhoStartsTheBatch(): void
    {
        $id = $this->approved(self::plan(wait: true));
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endBatch($id, 1, $this->t0 + 100);
        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 150, $this->seen($id)));

        $this->assertSame([1, 1, 2, 2], array_column($this->topology->calls, 'user'), 'batch 1 as alice, who applied it; batch 2 as bob, who continued it');
    }

    public function testABatchPlannedByHandThatTheTopologyNowKnowsIsHeldToWhatWasApproved(): void
    {
        // lab-other had no LLDP neighbor when the change was made: a final batch planned by hand, whose blast radius was unknown
        $plan = self::plan([501, 502, 503, 510, 511, 512, 513, 514, 509], false, RolloutPlan::HAND);
        $this->assertSame([509], $plan->batches[5]);
        $this->assertSame([[], [['device_id' => 509, 'reason' => 'isolated']]], [$plan->topology->batch(6)['lost'], $plan->topology->batch(6)['skipped']]);
        $id = $this->approved($plan);
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->topology->skips[509] = 'down';
        for ($number = 1; $number <= 5; $number++) {
            $this->endBatch($id, $number, $this->t0 + 100 * $number);
        }
        // still unknown to the topology (now down, too): it goes as approved, held to "cuts off nothing"
        $this->assertSame(ChangeStatus::APPLYING, $this->changes->find($id)['status']);
        $this->assertSame([509], $this->runner->launches[count($this->runner->launches) - 1]['devices'], 'still unknown: it goes as approved');
        $this->assertSame(['same', []], [$this->changes->batches[$id][6]['blast']['state'], $this->changes->batches[$id][6]['blast']['unplanned']]);
        $this->endBatch($id, 6, $this->t0 + 700);
        $this->assertSame(ChangeStatus::APPLIED, $this->changes->find($id)['status']);

        // its neighbors are known now: it cuts off lab-sw7, which nobody approved
        $id = $this->approved($plan);
        $this->topology->skips = [];
        $this->topology->cuts[509] = [513];
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        for ($number = 1; $number <= 5; $number++) {
            $this->endBatch($id, $number, $this->t0 + 100 * $number);
        }
        $this->assertSame([ChangeStatus::PAUSED, 'blast', 6], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting'], (int) $this->changes->find($id)['current_batch']]);
        $this->assertSame([513], $this->changes->batches[$id][6]['blast']['added']);
    }
}
