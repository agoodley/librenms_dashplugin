<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\ApprovalRules;
use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
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
    private function approved(?BatchPlan $plan = null): int
    {
        $plan ??= self::plan();
        $targets = array_intersect_key(self::TARGETS, array_flip(array_merge(...$plan->batches)));
        $id = $this->changes->add($targets, $plan);
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
        $this->assertNotEmpty(preg_grep('/^Ansible change #1 \(CHG-1\), batch 3: maintenance window #102 covers this device until .*: it loses its path to the core while batch 3 \(lab-sw2\) runs$/', $sw6),
            'the eventlog of a device cut off says why it is under maintenance');
        $this->assertStringContainsString('Maintenance window #102 opened for batch 3 until', $this->messages($id));
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
            . 'until an admin with the approve permission accepts the new blast radius.', $this->messages($id));
        $this->assertSame('warning', end($this->events->entries)['severity']);
        $this->assertStringContainsString('would now also cut off lab-sw5', end($this->events->entries)['message'], 'in the eventlog of the targets');

        // nothing moves it on but an approver's acceptance
        $lifecycle->advance($id, $this->t0 + 300);
        $lifecycle->advance($id, $this->t0 + 3000);
        $this->assertSame([ChangeStatus::PAUSED, 2], [$this->changes->find($id)['status'], count($this->launched())]);
        $this->assertStringContainsString('accept its new blast radius', (string) $lifecycle->resume($id, self::ALICE, [], $this->t0 + 3001));
        $this->assertStringContainsString('approve permission', (string) $lifecycle->acceptBlast($id, ['user_id' => 3, 'username' => 'carol'], false, '', $this->t0 + 3002));
        $this->assertStringContainsString('you requested this change', (string) $lifecycle->acceptBlast($id, self::ALICE, true, '', $this->t0 + 3003),
            'not the requester, with a second admin');
        $this->assertSame([ChangeStatus::PAUSED, 2], [$this->changes->find($id)['status'], count($this->launched())]);

        $this->assertNull($lifecycle->acceptBlast($id, self::BOB, true, 'the uplink is being fixed', $this->t0 + 3010));
        $this->assertSame([[510], [511, 512, 513, 514], [502]], $this->launched(), 'accepted: batch 3 starts');
        $this->assertSame([502, 512, 511], $this->windows->windows[102]['devices'], 'its window covers the device it now cuts off too');
        $this->assertSame([512, 511], $this->changes->batches[$id][3]['blast_accepted']['lost']);
        $this->assertSame('bob', $this->changes->batches[$id][3]['blast_accepted']['by']);
        $this->assertStringContainsString('Batch 3: its new blast radius accepted by bob: it cuts off lab-sw6, lab-sw5 and 3 unmanaged neighbors: "the uplink is being fixed"',
            $this->messages($id));
        $this->assertNotEmpty(preg_grep('/covers this device until .*: it loses its path to the core while batch 3 \(lab-sw2\) runs$/', array_column($this->events->of(511), 'message')),
            'lab-sw5\'s eventlog says why it is under maintenance');

        foreach ([3, 4, 5] as $number) {
            $this->endBatch($id, $number, $this->t0 + 4000 + 100 * $number);
        }
        $this->assertSame(ChangeStatus::APPLIED, $this->changes->find($id)['status']);
        $this->assertSame(self::BATCHES, $this->launched(), 'still the approved batches, in order');
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
        $this->assertNull($lifecycle->acceptBlast($id, self::BOB, true, '', $this->t0 + 300));
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
        $this->assertNull($lifecycle->acceptBlast($id, self::BOB, true, '', $this->t0 + 100));
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
            . 'The batch does not start until an admin continues it without a blast radius.', $this->messages($id));
        $this->assertStringContainsString('Only a change whose batch waits because its blast radius grew', (string) $lifecycle->acceptBlast($id, self::BOB, true, '', $this->t0 + 300));
        $lifecycle->advance($id, $this->t0 + 400);
        $this->assertSame(ChangeStatus::PAUSED, $this->changes->find($id)['status'], 'it waits for an admin');

        $this->runner->userProblems[3] = 'no longer holds the run permission (ansible.run)';
        $this->assertStringContainsString('You may not continue the change', (string) $lifecycle->resume($id, ['user_id' => 3, 'username' => 'carol'], [], $this->t0 + 450));
        $this->assertNull($lifecycle->resume($id, self::ALICE, [], $this->t0 + 500));
        $this->assertSame([[510], [511, 512, 513, 514], [502]], $this->launched(), 'continued explicitly: batch 3 goes');
        $this->assertSame([502, 512], $this->windows->windows[102]['devices'], 'its window covers the devices approved as cut off');
        $this->assertSame(['alice', 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)'],
            [$this->changes->batches[$id][3]['blast_override']['by'], $this->changes->batches[$id][3]['blast_override']['why']]);
        $this->assertSame('override', $this->changes->batches[$id][3]['blast']['state']);
        $this->assertStringContainsString('Batch 3: continued by alice without a blast radius (it cannot be computed: the topology plugin is disabled', $this->messages($id));
        $this->assertNotEmpty(array_filter($this->events->entries, fn ($e) => $e['severity'] === 'warning' && str_contains($e['message'], 'continued by alice without a blast radius')),
            'recorded in the eventlog');

        // the next batch is checked again, as any: the topology plugin is back
        $this->topology->error = null;
        $this->endBatch($id, 3, $this->t0 + 600);
        $this->assertSame('same', $this->changes->batches[$id][4]['blast']['state']);
        $this->assertNull($this->changes->batches[$id][4]['blast_override'], 'the decision was for batch 3 only');
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
        $this->assertNotEmpty(preg_grep('/batch 4: maintenance window #103 covers this device until/', array_column($this->events->of(514), 'message')));
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
        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 150));

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
        for ($number = 1; $number <= 5; $number++) {
            $this->endBatch($id, $number, $this->t0 + 100 * $number);
        }
        // its neighbors are known now: it cuts off lab-sw7, which nobody approved
        $this->assertSame(ChangeStatus::APPLYING, $this->changes->find($id)['status']);
        $this->assertSame([509], $this->runner->launches[count($this->runner->launches) - 1]['devices'], 'still unknown: it goes as approved');
        $this->endBatch($id, 6, $this->t0 + 700);
        $this->assertSame(ChangeStatus::APPLIED, $this->changes->find($id)['status']);

        $id = $this->approved($plan);
        $this->topology->cuts[509] = [513];
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        for ($number = 1; $number <= 5; $number++) {
            $this->endBatch($id, $number, $this->t0 + 100 * $number);
        }
        $this->assertSame([ChangeStatus::PAUSED, 'blast', 6], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting'], (int) $this->changes->find($id)['current_batch']]);
        $this->assertSame([513], $this->changes->batches[$id][6]['blast']['added']);
    }
}
