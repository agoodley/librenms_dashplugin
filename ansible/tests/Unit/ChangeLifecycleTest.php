<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\ApprovalRules;
use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
use Agoodley\LibrenmsAnsible\Changes\ChangeLifecycle;
use Agoodley\LibrenmsAnsible\Changes\ChangeSettings;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeBackups;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeChangeRunner;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeWindows;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\MemoryChangeStore;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\MemoryRunStore;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\RecordingEvents;
use PHPUnit\Framework\TestCase;

/**
 * The change lifecycle with fake runs, runner, Oxidized and maintenance windows: the dry run and its proof, approvals,
 * batches, backups, windows, stops, and the safety rules (nothing applied that was not dry-run and approved, no batch
 * after a stop or a failure, every window closed and every backup recorded).
 */
class ChangeLifecycleTest extends TestCase
{
    private const TARGETS = [501 => 'lab-sw1.netops.lab', 502 => 'lab-sw2.netops.lab', 503 => 'lab-sw3.netops.lab'];

    private const ALICE = ['user_id' => 1, 'username' => 'alice'];

    private const BOB = ['user_id' => 2, 'username' => 'bob'];

    private MemoryChangeStore $changes;

    private MemoryRunStore $runs;

    private FakeChangeRunner $runner;

    private FakeBackups $backups;

    private FakeWindows $windows;

    private RecordingEvents $events;

    private int $t0;

    protected function setUp(): void
    {
        $this->changes = new MemoryChangeStore();
        $this->runs = new MemoryRunStore();
        $this->runner = new FakeChangeRunner($this->runs, self::TARGETS);
        $this->backups = new FakeBackups();
        $this->windows = new FakeWindows();
        $this->events = new RecordingEvents();
        $this->t0 = strtotime('2026-10-22 10:00:00 UTC');
        $this->backups->now = $this->t0;
    }

    private function lifecycle(array $settings = []): ChangeLifecycle
    {
        return new ChangeLifecycle($this->changes, $this->runs, $this->runner, $this->backups, $this->windows, $this->events,
            new ChangeSettings(...$settings + ['approval' => ApprovalRules::SECOND, 'settleSeconds' => 600, 'expectedSeconds' => 3600]));
    }

    /** A change of the three switches: the canary 501, then 502 and 503. */
    private function change(array $change = [], ?BatchPlan $plan = null): int
    {
        return $this->changes->add(self::TARGETS, $plan ?? BatchPlan::make(array_keys(self::TARGETS), [], 1, 5, 1, true, 0), $change);
    }

    /**
     * A dry run's log: verified (DRY RUN banner, markers), a unified diff and "changed" for each host, a recap.
     *
     * @param  array<string, string>  $results  host => changed, ok or failed
     */
    private static function dryRunLog(array $results, bool $marked = true): array
    {
        $marker = $marked ? ' [CHECK MODE]' : '';
        $lines = $marked ? ['DRY RUN ****'] : [];
        $lines = array_merge($lines, ["PLAY [Interface description]$marker ****", "TASK [Set the description]$marker ****"]);
        foreach ($results as $host => $result) {
            if ($result === 'changed') {
                $lines = array_merge($lines, ['--- before', '+++ after', '@@ -1,2 +1,3 @@', ' interface Fa0/1', "+ description for $host", ' interface Fa0/2']);
            }
            $lines[] = $result === 'failed' ? "fatal: [$host]: FAILED! => {\"msg\": \"x\"}" : "$result: [$host]";
        }
        $lines[] = 'PLAY RECAP ****';
        foreach ($results as $host => $result) {
            $lines[] = "$host : ok=" . ($result === 'failed' ? 0 : 1) . ' changed=' . ($result === 'changed' ? 1 : 0) . ' unreachable=0 failed=' . ($result === 'failed' ? 1 : 0)
                . ' skipped=0 rescued=0 ignored=0';
        }

        return $lines;
    }

    /**
     * The runner ends a run: its status, each target's result, its log.
     *
     * @param  array<int, string>  $results  device_id => result
     */
    private function endRun(int $runId, string $status, array $results, array $log = [], int $at = 0, array $more = []): void
    {
        $this->runs->runs[$runId] = array_merge($this->runs->runs[$runId], ['status' => $status, 'finished_at' => Clock::store($at ?: $this->t0 + 60),
            'output' => implode("\n", $log), 'version' => $this->runs->runs[$runId]['version'] + 1], $more);
        foreach ($results as $deviceId => $result) {
            $this->runs->targets[$runId][$deviceId]['result'] = $result;
        }
    }

    /** A change dry-run (verified, every target would change), awaiting approval. */
    private function dryRun(int $id, ?array $results = null, int $at = 0): int
    {
        $lifecycle = $this->lifecycle();
        $this->assertNull($lifecycle->startDryRun($id, self::ALICE, [], $at ?: $this->t0));
        $runId = (int) $this->changes->find($id)['dry_run_id'];
        $results ??= array_fill_keys(array_keys(self::TARGETS), 'changed');
        $this->endRun($runId, in_array('failed', $results, true) ? 'failed' : 'success', $results,
            self::dryRunLog(array_combine(array_map(fn ($d) => self::TARGETS[$d], array_keys($results)), array_values($results))), ($at ?: $this->t0) + 30);
        $lifecycle->advance($id, ($at ?: $this->t0) + 35);

        return $runId;
    }

    /** A change dry-run and approved by bob. */
    private function approved(array $change = [], ?BatchPlan $plan = null): int
    {
        $id = $this->change($change, $plan);
        $this->dryRun($id);
        $this->assertNull($this->lifecycle()->approve($id, self::BOB, true, 'looks good', $this->t0 + 40));

        return $id;
    }

    /** The run of a batch. */
    private function batchRun(int $id, int $number): int
    {
        return (int) $this->changes->batches[$id][$number]['run_id'];
    }

    // ------------------------------------------------------------------ dry run

    public function testAVerifiedDryRunAwaitsApprovalWithItsDiffsPerDevice(): void
    {
        $id = $this->change();
        $runId = $this->dryRun($id);

        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::AWAITING, $change['status']);
        $this->assertSame('verified', $change['dry_run_state']);
        $this->assertSame([$runId => true], $this->runner->proofs, 'recorded on the run');
        $this->assertSame('abc1234', $change['dry_run_commit'], 'the commit the runner reported for the dry run');
        $this->assertSame(64, strlen((string) $change['plan_fingerprint']));
        $this->assertSame(['dry_run', 1, [501, 502, 503]], [$this->runner->launches[0]['kind'], $this->runner->launches[0]['number'], $this->runner->launches[0]['devices']],
            'the first dry run of the change, on every target');
        $this->assertSame('changed', $this->changes->targets[$id][502]['dry_result']);
        $diffs = $this->changes->diffs[$id];
        $this->assertSame([501, 502, 503], array_column($diffs, 'device_id'));
        $this->assertContains('+ description for lab-sw2.netops.lab', $diffs[1]['lines'], 'each diff under its own device');
        $this->assertSame($runId, $diffs[0]['run_id']);
        $this->assertStringContainsString('Ansible change #1 (CHG-1), dry run #' . $runId . ': would change', $this->events->of(501)[1]['message']);
        $this->assertStringContainsString('approval requested', end($this->events->entries)['message']);
    }

    public function testADryRunNotProvenToBeOneCanBeNeitherApprovedNorApplied(): void
    {
        $id = $this->change();
        $lifecycle = $this->lifecycle(['approval' => ApprovalRules::OFF]);
        $lifecycle->startDryRun($id, self::ALICE, [], $this->t0);
        $runId = (int) $this->changes->find($id)['dry_run_id'];
        $this->endRun($runId, 'success', array_fill_keys(array_keys(self::TARGETS), 'changed'),
            self::dryRunLog(array_fill_keys(self::TARGETS, 'changed'), false), $this->t0 + 30);
        $lifecycle->advance($id, $this->t0 + 35);

        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::DRAFT, $change['status']);
        $this->assertSame('unverified', $change['dry_run_state']);
        $this->assertSame([$runId => false], $this->runner->proofs, 'recorded on the run: it counts as one that may have changed its devices');
        $this->assertStringContainsString('DRY RUN banner', $change['dry_run_findings'][0]);
        foreach (array_keys(self::TARGETS) as $deviceId) {
            $entry = end($this->events->entries) && ($e = array_values(array_filter($this->events->of($deviceId), fn ($e) => $e['severity'] === 'error'))) ? $e[0] : null;
            $this->assertNotNull($entry, "an error entry on device $deviceId");
            $this->assertStringContainsString('NOT PROVEN to be a dry run, so it may have changed this device', $entry['message']);
        }
        $this->assertStringContainsString('verified without failures', (string) $lifecycle->approve($id, self::BOB, true, '', $this->t0 + 40));
        $this->assertStringContainsString('needs a verified dry run', (string) $lifecycle->apply($id, self::ALICE, [], $this->t0 + 40));
        $this->assertCount(1, $this->runner->launches, 'nothing else launched');
    }

    public function testAFailedDryRunGoesBackToDraft(): void
    {
        $id = $this->change();
        $this->dryRun($id, [501 => 'changed', 502 => 'failed', 503 => 'changed']);

        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::DRAFT, $change['status']);
        $this->assertSame('failed', $change['dry_run_state']);
        $this->assertSame('failed', $this->changes->targets[$id][502]['dry_result']);
        $this->assertStringContainsString('every target must do what was asked', end($change['dry_run_findings']));
    }

    public function testAnUnreadableLogIsAskedAgainThenLeavesTheDryRunUnverified(): void
    {
        $id = $this->change();
        $lifecycle = $this->lifecycle();
        $lifecycle->startDryRun($id, self::ALICE, [], $this->t0);
        $runId = (int) $this->changes->find($id)['dry_run_id'];
        $this->endRun($runId, 'success', array_fill_keys(array_keys(self::TARGETS), 'changed'), [], $this->t0 + 30);
        $this->runner->logs[$runId] = null;

        $lifecycle->advance($id, $this->t0 + 40);
        $this->assertSame(ChangeStatus::DRY_RUN, $this->changes->find($id)['status'], 'asked again later');
        $lifecycle->advance($id, $this->t0 + 30 + ChangeLifecycle::LOG_GRACE + 1);
        $this->assertSame('unverified', $this->changes->find($id)['dry_run_state']);
    }

    public function testADryRunWhoseLaunchWasRefusedLeavesADraft(): void
    {
        $id = $this->change();
        $this->runner->launchErrors = ['The template cannot be launched safely: it has no ANSIBLE_CHECK_MODE_MARKERS.'];

        $error = $this->lifecycle()->startDryRun($id, self::ALICE, [], $this->t0);
        $this->assertStringContainsString('ANSIBLE_CHECK_MODE_MARKERS', (string) $error);
        $this->assertSame(ChangeStatus::DRAFT, $this->changes->find($id)['status']);
        $this->assertSame('not_launched', $this->changes->find($id)['dry_run_state']);
    }

    public function testADryRunThatNeverRanNeedsNoProofAndWarnsOfNothing(): void
    {
        foreach ([['not_launched', ['error' => 'the runner refused a task of template 77 (HTTP 400): Task queue is full']], ['stopped', ['started_at' => null]]] as [$status, $more]) {
            $id = $this->change();
            $lifecycle = $this->lifecycle();
            $lifecycle->startDryRun($id, self::ALICE, [], $this->t0);
            $runId = (int) $this->changes->find($id)['dry_run_id'];
            $this->endRun($runId, $status, array_fill_keys(array_keys(self::TARGETS), 'not_run'), [], $this->t0 + 5, $more);
            $before = count($this->events->entries);
            $lifecycle->advance($id, $this->t0 + 10);

            $change = $this->changes->find($id);
            $this->assertSame([ChangeStatus::DRAFT, 'not_launched'], [$change['status'], $change['dry_run_state']], $status);
            $this->assertStringContainsString($status === 'stopped' ? 'stopped before the runner started it: nothing ran' : 'Task queue is full', (string) $change['error']);
            $this->assertSame('not_run', $this->changes->targets[$id][501]['dry_result']);
            $this->assertCount($before, $this->events->entries, 'no "not proven" error in the eventlog of its devices: nothing ran');
            $this->assertArrayNotHasKey($runId, $this->runner->proofs);
        }
    }

    // ------------------------------------------------------------------ approvals

    public function testApprovalsFollowTheRules(): void
    {
        $id = $this->change();
        $this->dryRun($id);
        $lifecycle = $this->lifecycle();

        $this->assertStringContainsString('second admin', (string) $lifecycle->approve($id, self::ALICE, true, '', $this->t0 + 40), 'not the requester');
        $this->assertStringContainsString('approve permission', (string) $lifecycle->approve($id, self::BOB, false, '', $this->t0 + 40));
        $this->assertStringContainsString('needs an approval from a second admin first', (string) $lifecycle->apply($id, self::ALICE, [], $this->t0 + 40));
        $this->assertNull($lifecycle->approve($id, self::BOB, true, "ok\nfor CHG-1", $this->t0 + 40));

        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::READY, $change['status']);
        $this->assertSame('bob', $change['approved_by']);
        $this->assertSame('ok for CHG-1', $this->changes->approvals[0]['comment'], 'the comment on one line');
        $this->assertSame($change['approval_fingerprint'], $this->changes->approvals[0]['fingerprint']);
    }

    public function testATemplateChangedSinceTheDryRunVoidsIt(): void
    {
        $id = $this->change();
        $this->dryRun($id);
        $this->runner->snapshot = FakeChangeRunner::snapshotOf(['playbook' => 'other.yml']);

        $error = $this->lifecycle()->approve($id, self::BOB, true, '', $this->t0 + 40);
        $this->assertStringContainsString("the template changed in the runner since the dry run (the template's playbook)", (string) $error);
        $this->assertSame(ChangeStatus::DRAFT, $this->changes->find($id)['status']);
        $this->assertSame('void', $this->changes->find($id)['dry_run_state']);
    }

    public function testDryRunsAndApprovalsExpire(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle(['approvalSeconds' => 3600]);

        $lifecycle->advance($id, $this->t0 + 40 + 3601);
        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::AWAITING, $change['status'], 'the approval expired, the dry run still counts');
        $this->assertNull($change['approved_at']);
        $this->assertNotNull($this->changes->approvals[0]['voided_at']);
        $this->assertStringContainsString('approval no longer counts: its approval expired', end($this->events->entries)['message']);

        $lifecycle->advance($id, $this->t0 + 86401);
        $this->assertSame(ChangeStatus::DRAFT, $this->changes->find($id)['status'], 'the dry run expired');
        $this->assertStringContainsString('its dry run expired', (string) $this->changes->find($id)['error']);
    }

    public function testAnotherRunOnItsDevicesVoidsTheDryRun(): void
    {
        $id = $this->approved();
        $this->runner->changed = [502 => 77];

        $error = $this->lifecycle()->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->assertStringContainsString('another run changed 1 of its devices since its dry run (run #77)', (string) $error);
        $this->assertSame(ChangeStatus::DRAFT, $this->changes->find($id)['status']);
        $this->assertCount(1, $this->runner->launches, 'nothing applied');
    }

    public function testAnApproverWhoLostThePermissionVoidsTheApproval(): void
    {
        $id = $this->approved();
        $this->runner->userProblems[2] = 'no longer holds the approve permission';

        $error = $this->lifecycle()->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->assertStringContainsString('its approver bob no longer holds the approve permission', (string) $error);
        $this->assertSame(ChangeStatus::AWAITING, $this->changes->find($id)['status']);
    }

    // ------------------------------------------------------------------ batches

    public function testAnApplyGoesInBatchesWithWindowsAndBackups(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->backups->succeed = false; // Oxidized takes the backup a few seconds after the request

        $this->assertNull($lifecycle->apply($id, self::ALICE, [], $this->t0 + 50));
        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::APPLYING, $change['status']);
        $this->assertSame('preparing', $this->changes->batches[$id][1]['status'], 'waiting for the backups');
        $this->assertSame([[501]], array_column($this->windows->windows, 'devices'), 'a window for the canary');
        $this->assertSame('Ansible change #1, batch 1: Port description', $this->windows->windows[100]['title']);
        $this->assertSame([['node' => 'dev501', 'message' => 'LibreNMS Ansible change #1 (CHG-1), before batch 1', 'user' => 'alice']], $this->backups->requests);
        $this->assertCount(1, $this->runner->launches, 'the batch waits for its backup');

        $this->backups->nodes[501] = ['name' => 'dev501', 'full_name' => 'lab/dev501', 'last' => null]; // backing up
        $lifecycle->advance($id, $this->t0 + 55);
        $this->assertCount(1, $this->runner->launches);
        $this->backups->nodes[501]['last'] = ['start' => gmdate('Y-m-d H:i:s', $this->t0 + 52) . ' UTC', 'end' => gmdate('Y-m-d H:i:s', $this->t0 + 57) . ' UTC', 'status' => 'success'];
        $this->backups->succeed = true;
        $lifecycle->advance($id, $this->t0 + 60);
        $this->assertSame('fresh', $this->changes->targets[$id][501]['before_status']);
        $this->assertSame(Clock::store($this->t0 + 57), $this->changes->targets[$id][501]['before_at']);
        $this->assertNotNull($this->changes->targets[$id][501]['before_version']);
        $this->assertSame(['batch', 1, [501], 'alice', 'abc1234'], array_values(array_intersect_key($this->runner->launches[1], array_flip(['kind', 'number', 'devices', 'user', 'commit']))),
            'the canary, pinned to the dry run\'s commit');

        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 120);
        $this->backups->now = $this->t0 + 125;
        $lifecycle->advance($id, $this->t0 + 125);
        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::PAUSED, $change['status'], 'waits for an admin after the canary');
        $this->assertSame('canary', $change['waiting']);
        $this->assertSame('changed', $this->changes->targets[$id][501]['result']);
        $this->assertSame($this->t0 + 120 + 600, $this->windows->windows[100]['end'], 'the window ends a settle time after the batch');
        $this->assertSame('fresh', $this->changes->targets[$id][501]['after_status'], 'the backup after the batch');
        $this->assertCount(2, $this->runner->launches);

        $this->backups->now = $this->t0 + 200;
        $this->assertNull($lifecycle->resume($id, self::BOB, [], $this->t0 + 200));
        $lifecycle->advance($id, $this->t0 + 205);
        $this->assertSame(['batch', 2, [502, 503], 'bob'], array_values(array_intersect_key($this->runner->launches[2], array_flip(['kind', 'number', 'devices', 'user']))));
        $this->endRun($this->batchRun($id, 2), 'success', [502 => 'changed', 503 => 'ok'], [], $this->t0 + 300);
        $this->backups->now = $this->t0 + 305;
        $lifecycle->advance($id, $this->t0 + 305);
        $this->assertSame(ChangeStatus::APPLIED, $this->changes->find($id)['status']);
        $this->assertSame(['fresh', 'fresh'], [$this->changes->targets[$id][502]['after_status'], $this->changes->targets[$id][503]['after_status']]);

        $lifecycle->advance($id, $this->t0 + 300 + 601);
        $this->assertSame(['closed', 'closed'], array_values(array_column($this->changes->windows, 'status')), 'the plugin closed its windows');
        $this->assertFalse($lifecycle->pending($id), 'nothing left to follow');
        $this->assertStringContainsString('Ansible change #1 (CHG-1) applied', $this->events->of(503)[count($this->events->of(503)) - 2]['message']);
    }

    public function testABatchWithFailuresAtTheThresholdStopsTheChange(): void
    {
        $plan = BatchPlan::make(array_keys(self::TARGETS), [], 1, 1, 1, false, 0);
        $id = $this->approved([], $plan);
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = false;

        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);
        $lifecycle->advance($id, $this->t0 + 101);
        $this->assertSame([501], $this->runner->launches[1]['devices']);
        $this->assertSame([502], $this->runner->launches[2]['devices'], 'no wait after the canary in this plan');
        $this->endRun($this->batchRun($id, 2), 'failed', [502 => 'unreachable'], [], $this->t0 + 150);
        $lifecycle->advance($id, $this->t0 + 151);

        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::FAILED, $change['status']);
        $this->assertSame('1 device of batch 2 failed (the plan stops at 1)', $change['error']);
        $this->assertCount(3, $this->runner->launches, 'batch 3 is never launched');
        $this->assertSame('cancelled', $this->changes->batches[$id][3]['status']);
        $lifecycle->advance($id, $this->t0 + 1000);
        $this->assertSame(['closed', 'closed'], array_values(array_column($this->changes->windows, 'status')));
        $this->assertSame('error', $this->events->of(503)[count($this->events->of(503)) - 1]['severity'], 'the failure on the devices not run too');
    }

    public function testATemplateChangedBeforeTheNextBatchStopsTheChange(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = false;
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);
        $lifecycle->advance($id, $this->t0 + 101);
        $this->runner->snapshot = FakeChangeRunner::snapshotOf(['arguments' => ['-v'], 'argumentsRaw' => '["-v"]']);

        $this->assertNull($lifecycle->resume($id, self::ALICE, [], $this->t0 + 200));
        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::FAILED, $change['status']);
        $this->assertStringContainsString("batch 2 was not launched: The launch was refused: the template changed in the runner since the dry run (the template's CLI arguments)",
            $change['error']);
        $this->assertCount(2, $this->runner->launches);
        $this->assertSame('failed', $this->changes->batches[$id][2]['status']);
    }

    public function testABatchThatRanAnotherCommitStopsTheChange(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = false;
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100, ['commit_hash' => 'def5678']);
        $lifecycle->advance($id, $this->t0 + 101);

        $this->assertSame(ChangeStatus::FAILED, $this->changes->find($id)['status']);
        $this->assertSame('batch 1 ran commit def5678, not commit abc1234 of the dry run', $this->changes->find($id)['error']);
    }

    public function testNoBatchIsLaunchedTwice(): void
    {
        $id = $this->approved([], BatchPlan::make(array_keys(self::TARGETS), [], 1, 5, 1, false, 0));
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = false;
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);

        // the sync command and a page both observe the end
        $lifecycle->advance($id, $this->t0 + 101);
        $lifecycle->advance($id, $this->t0 + 102);
        $this->changes->batches[$id][2]['status'] = 'launching'; // as if a request died after it marked the launch
        $lifecycle->advance($id, $this->t0 + 103);

        $this->assertCount(3, $this->runner->launches, 'the dry run and two batches, each once');
        $this->assertSame('running', $this->changes->batches[$id][2]['status'], 'the run of the one-time token is found again');
    }

    public function testStopStopsTheRunningBatchAndCancelsTheRest(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = false;
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $runId = $this->batchRun($id, 1);

        $this->assertNull($lifecycle->stop($id, 'carol', $this->t0 + 60));
        $this->assertSame(ChangeStatus::STOPPING, $this->changes->find($id)['status']);
        $this->assertSame(["$runId by carol"], $this->runner->stops, 'phase 1\'s stop of the batch\'s run');
        $lifecycle->advance($id, $this->t0 + 70);
        $this->assertSame(ChangeStatus::STOPPING, $this->changes->find($id)['status'], 'until the runner ends the run');

        $held = $this->t0 + 80 + 1800;
        $this->endRun($runId, 'stopped', [501 => 'unknown'], [], $this->t0 + 80, ['held_until' => Clock::store($held)]);
        $lifecycle->advance($id, $this->t0 + 90);
        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::STOPPED, $change['status']);
        $this->assertSame('stopped by carol during batch 1', $change['error']);
        $this->assertSame('cancelled', $this->changes->batches[$id][2]['status']);
        $this->assertSame($held + 600, $this->windows->windows[100]['end'], 'the window lasts the hold of the stopped batch and the settle time');
        $this->assertCount(2, $this->runner->launches, 'no batch after a stop');
        $this->assertStringContainsString('only a change being applied', strtolower((string) $lifecycle->stop($id, 'carol', $this->t0 + 95)));
    }

    public function testAStopWhileWaitingEndsTheChangeAtOnce(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = false;
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);
        $lifecycle->advance($id, $this->t0 + 101);

        $this->assertNull($lifecycle->stop($id, 'alice', $this->t0 + 150));
        $this->assertSame(ChangeStatus::STOPPED, $this->changes->find($id)['status']);
        $this->assertSame('stopped by alice after batch 1', $this->changes->find($id)['error']);
        $this->assertStringContainsString('Only a paused change', (string) $lifecycle->resume($id, self::ALICE, [], $this->t0 + 160));
        $this->assertCount(2, $this->runner->launches);
    }

    public function testAStopRacingTheSyncCommandLaunchesNothing(): void
    {
        $id = $this->approved([], BatchPlan::make(array_keys(self::TARGETS), [], 1, 5, 1, false, 0));
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = false;
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);

        // a Stop lands between the sync command's read of the change and its write
        $this->changes->beforeUpdate = function () use ($id): void {
            $this->changes->changes[$id] = array_merge($this->changes->changes[$id], ['status' => ChangeStatus::STOPPED, 'version' => $this->changes->changes[$id]['version'] + 1]);
        };
        $lifecycle->advance($id, $this->t0 + 101);

        $this->assertSame(ChangeStatus::STOPPED, $this->changes->find($id)['status']);
        $this->assertCount(2, $this->runner->launches, 'no batch of a stopped change');
    }

    public function testATimedPauseContinuesOnItsOwn(): void
    {
        $id = $this->approved([], BatchPlan::make(array_keys(self::TARGETS), [], 1, 5, 1, false, 15));
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = false;
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'ok'], [], $this->t0 + 100);
        $lifecycle->advance($id, $this->t0 + 101);

        $this->assertSame('timer', $this->changes->find($id)['waiting']);
        $lifecycle->advance($id, $this->t0 + 101 + 899);
        $this->assertCount(2, $this->runner->launches, 'not before the pause ends');
        $lifecycle->advance($id, $this->t0 + 101 + 900);
        $this->assertCount(3, $this->runner->launches);
        $this->assertSame('alice', $this->runner->launches[2]['user'], 'launched for the admin who applied it');
    }

    public function testAWaitPastTheApprovalEndsTheChange(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle(['approvalSeconds' => 3600]);
        $this->backups->enabled = false;
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);
        $lifecycle->advance($id, $this->t0 + 101);

        $lifecycle->advance($id, $this->t0 + 40 + 3601);
        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::FAILED, $change['status']);
        $this->assertStringContainsString('stopped while it waited before batch 2: its approval expired', $change['error']);
    }

    public function testSecretAnswersAreAskedForEachBatchAndNeverKept(): void
    {
        $id = $this->approved(['has_secrets' => true, 'variables' => [['name' => 'key', 'label' => 'Key', 'type' => 'secret', 'value' => '********', 'secret' => true]]],
            BatchPlan::make(array_keys(self::TARGETS), [], 1, 1, 1, false, 0));
        $lifecycle = $this->lifecycle();
        $this->backups->now = $this->t0 + 50;

        $lifecycle->apply($id, self::ALICE, ['key' => 's3cret'], $this->t0 + 50);
        $lifecycle->advance($id, $this->t0 + 60); // backups fresh: the sync command cannot launch without the secrets
        $this->assertSame(ChangeStatus::PAUSED, $this->changes->find($id)['status']);
        $this->assertSame('secrets', $this->changes->find($id)['waiting']);
        $this->assertSame('prepared', $this->changes->batches[$id][1]['status']);

        $this->assertNull($lifecycle->resume($id, self::ALICE, ['key' => 's3cret'], $this->t0 + 70));
        $this->assertSame(['key' => 's3cret'], $this->runner->launches[1]['secrets'], 'the secrets go to the runner');
        $this->assertSame(1, $this->runner->launches[1]['number']);
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);
        $this->backups->now = $this->t0 + 101;
        $lifecycle->advance($id, $this->t0 + 101);
        $this->assertSame('secrets', $this->changes->find($id)['waiting'], 'every batch needs them again');
        $this->assertSame(2, (int) $this->changes->find($id)['current_batch']);

        $stored = json_encode([$this->changes->changes, $this->changes->targets, $this->changes->batches, $this->changes->events, $this->runs->runs]);
        $this->assertStringNotContainsString('s3cret', (string) $stored);

        $this->assertNull($lifecycle->stop($id, 'alice', $this->t0 + 110));
        $this->assertSame([ChangeStatus::STOPPED, 'stopped by alice before batch 2 was launched'], [$this->changes->find($id)['status'], $this->changes->find($id)['error']],
            'a batch waiting for the secret answers was not launched');
    }

    public function testWithoutAFreshBackupTheBatchIsRefusedOrGoesWithAWarning(): void
    {
        $id = $this->approved();
        $this->backups->succeed = false;
        $lifecycle = $this->lifecycle(['backupTimeoutSeconds' => 600]);
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $lifecycle->advance($id, $this->t0 + 50 + 601);

        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::FAILED, $change['status']);
        $this->assertStringContainsString('batch 1 was refused: no fresh backup of lab-sw1', $change['error']);
        $this->assertCount(1, $this->runner->launches, 'the canary is not launched');
        $this->assertStringContainsString('no fresh backup before the batch (failed: Oxidized took no backup within 10 minutes)',
            implode("\n", array_column($this->events->of(501), 'message')));
        $lifecycle->advance($id, $this->t0 + 50 + 700);
        $this->assertSame('closed', array_values($this->changes->windows)[0]['status'], 'the window of a batch that never ran is closed');

        $warned = $this->approved();
        $this->backups->nodes[501] = null; // not in Oxidized
        $lifecycle = $this->lifecycle(['refuseWithoutBackup' => false]);
        $lifecycle->apply($warned, self::ALICE, [], $this->t0 + 50);
        $this->assertSame('no_node', $this->changes->targets[$warned][501]['before_status']);
        $this->assertSame(ChangeStatus::APPLYING, $this->changes->find($warned)['status']);
        $this->assertSame('running', $this->changes->batches[$warned][1]['status'], 'launched with a warning');
        $this->assertStringContainsString('goes without a fresh backup', implode(' ', $this->changes->messages($warned)));
        $this->assertContains('Batch 1 of 2 launched by alice (run #' . $this->batchRun($warned, 1) . ')', $this->changes->messages($warned),
            'the timeline does not repeat the change\'s name');
    }

    public function testAnOxidizedThatCannotBeReachedIsAskedOncePerBatch(): void
    {
        $id = $this->approved([], BatchPlan::make(array_keys(self::TARGETS), [], 3, 5, 1, true, 0));
        $this->backups->unreachable = 'Oxidized cannot be reached (unreachable)';
        $this->lifecycle()->apply($id, self::ALICE, [], $this->t0 + 50);

        $this->assertSame(1, $this->backups->nodeCalls, 'one call: each one could wait its timeout');
        $this->assertSame(['unavailable', 'unavailable', 'unavailable'], array_column($this->changes->targets[$id], 'before_status'));
        $this->assertSame(ChangeStatus::FAILED, $this->changes->find($id)['status']);
        $this->assertStringContainsString('Oxidized cannot be reached', $this->changes->find($id)['error']);
    }

    public function testAWindowThatCannotBeOpenedStopsTheBatch(): void
    {
        $id = $this->approved();
        $this->windows->openError = 'database is down';

        $this->lifecycle()->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->assertSame(ChangeStatus::FAILED, $this->changes->find($id)['status']);
        $this->assertStringContainsString('the maintenance window of batch 1 could not be opened: database is down', $this->changes->find($id)['error']);
        $this->assertCount(1, $this->runner->launches);
    }

    public function testRejectAndCancel(): void
    {
        $id = $this->change();
        $this->dryRun($id);
        $lifecycle = $this->lifecycle();
        $this->assertNull($lifecycle->reject($id, self::ALICE, true, 'wrong interface', $this->t0 + 40), 'the requester may reject their own change');
        $this->assertSame(ChangeStatus::REJECTED, $this->changes->find($id)['status']);
        $this->assertStringContainsString('rejected by alice: "wrong interface"', end($this->events->entries)['message']);

        $other = $this->change();
        $this->assertNull($lifecycle->cancel($other, 'alice', $this->t0));
        $this->assertSame(ChangeStatus::CANCELLED, $this->changes->find($other)['status']);
        $this->assertStringContainsString('dry run is only for a change that has not been applied', (string) $lifecycle->startDryRun($other, self::ALICE, [], $this->t0));
    }

    public function testAnApplierWhoLostThePermissionStopsTheChange(): void
    {
        $id = $this->approved([], BatchPlan::make(array_keys(self::TARGETS), [], 1, 5, 1, false, 0));
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = false;
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->runner->userProblems[1] = 'no longer holds the run permission';
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);
        $lifecycle->advance($id, $this->t0 + 101);

        $this->assertSame(ChangeStatus::FAILED, $this->changes->find($id)['status']);
        $this->assertStringContainsString('stopped before batch 2: alice, who launches it, no longer holds the run permission', $this->changes->find($id)['error']);
    }
}
