<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\ApprovalRules;
use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
use Agoodley\LibrenmsAnsible\Changes\ChangeLifecycle;
use Agoodley\LibrenmsAnsible\Changes\ChangeSettings;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Changes\Connections;
use Agoodley\LibrenmsAnsible\Changes\NextChange;
use Agoodley\LibrenmsAnsible\Runner\SurveyVar;
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
    private function dryRun(int $id, ?array $results = null, int $at = 0, array $secrets = []): int
    {
        $lifecycle = $this->lifecycle();
        $this->assertNull($lifecycle->startDryRun($id, self::ALICE, $secrets, $at ?: $this->t0));
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
        $this->assertSame('The dry run ended failed on lab-sw2 (failed): every target must do what was asked without failing.', end($change['dry_run_findings']),
            'it names the devices');
        $this->assertSame([501, 503], NextChange::devices($this->changes->targets($id), NextChange::PASSED), 'a new change without them takes the others');
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

    public function testAnApprovalSwitchedOnIsRequestedNotVoided(): void
    {
        $id = $this->change();
        $off = $this->lifecycle(['approval' => ApprovalRules::OFF]);
        $this->assertNull($off->startDryRun($id, self::ALICE, [], $this->t0));
        $runId = (int) $this->changes->find($id)['dry_run_id'];
        $this->endRun($runId, 'success', array_fill_keys(array_keys(self::TARGETS), 'changed'), self::dryRunLog(array_fill_keys(array_values(self::TARGETS), 'changed')),
            $this->t0 + 30);
        $off->advance($id, $this->t0 + 35);
        $this->assertSame(ChangeStatus::READY, $this->changes->find($id)['status'], 'no approval needed');

        // an admin switches approval on: the change awaits one, said as a request
        $on = $this->lifecycle(['approval' => ApprovalRules::REQUIRED]);
        $on->advance($id, $this->t0 + 40);
        $this->assertSame(ChangeStatus::AWAITING, $this->changes->find($id)['status']);
        $this->assertSame('The settings ask for an approval now: approval requested.', end($this->changes->events[$id])['message']);
        $this->assertSame([], array_values(array_filter($this->changes->messages($id), fn ($m) => str_contains($m, 'no longer counts'))), 'nothing voided');
        $this->assertSame('info', end($this->events->entries)['severity']);

        // the same at an Apply that comes first
        $id = $this->change();
        $this->assertNull($off->startDryRun($id, self::ALICE, [], $this->t0 + 50));
        $this->endRun((int) $this->changes->find($id)['dry_run_id'], 'success', array_fill_keys(array_keys(self::TARGETS), 'changed'),
            self::dryRunLog(array_fill_keys(array_values(self::TARGETS), 'changed')), $this->t0 + 60);
        $off->advance($id, $this->t0 + 61);
        $this->assertSame('The settings ask for an approval now: the change awaits one.', $on->apply($id, self::ALICE, [], $this->t0 + 62));
        $this->assertSame(ChangeStatus::AWAITING, $this->changes->find($id)['status']);
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

    public function testARunnerThatCannotGiveTheTemplateRefusesTheActionAndVoidsNothing(): void
    {
        // a server error (or a timeout) of the runner at Approve, then at Apply: nothing says the template changed
        $id = $this->change();
        $this->dryRun($id);
        $lifecycle = $this->lifecycle();
        $this->runner->snapshotError = 'Semaphore UI answered HTTP 500';
        $this->assertSame('The template cannot be read from the runner now (Semaphore UI answered HTTP 500): try again in a moment. The dry run still counts; '
            . 'once the template can be read, it is compared with the one dry-run.', $lifecycle->approve($id, self::BOB, true, '', $this->t0 + 40));
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::AWAITING, 'verified', null], [$change['status'], $change['dry_run_state'], $change['approved_by']]);

        $this->runner->snapshotError = null;
        $this->assertNull($lifecycle->approve($id, self::BOB, true, '', $this->t0 + 41));
        $this->runner->snapshotError = 'the runner did not answer within 15 s';
        $this->assertStringStartsWith('The template cannot be read from the runner now (the runner did not answer within 15 s): try again in a moment. '
            . 'The dry run and its approval still count', (string) $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50));
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::READY, 'verified', 'bob'], [$change['status'], $change['dry_run_state'], $change['approved_by']]);
        $this->assertSame([], array_filter(array_column($this->changes->approvals, 'voided_at')), 'the approval was not voided');
        $this->assertSame(['dry_run'], array_column($this->runner->launches, 'kind'));
        $this->assertSame([], array_filter($this->changes->messages($id), fn ($m) => str_contains($m, 'no longer counts')), 'nothing voided in the timeline');

        $this->runner->snapshotError = null;
        $this->backups->enabled = false;
        $this->assertNull($lifecycle->apply($id, self::ALICE, [], $this->t0 + 60), 'once the runner answers again');
        $this->assertSame(ChangeStatus::APPLYING, $this->changes->find($id)['status']);
    }

    // ------------------------------------------------------------------ the eventlog

    public function testEachEntryCarriesTheAdminWhoDidTheStep(): void
    {
        $carol = ['user_id' => 3, 'username' => 'carol'];
        $id = $this->approved(); // alice asked for it and dry-ran it, bob approved it
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = false;
        $this->assertNull($lifecycle->apply($id, $carol, [], $this->t0 + 50));
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);
        $lifecycle->advance($id, $this->t0 + 101);
        $this->assertNull($lifecycle->stop($id, 'dave', $this->t0 + 110));
        $lifecycle->advance($id, $this->t0 + 111);

        $users = [];
        foreach ($this->events->of(501) as $entry) {
            $users[preg_replace('/^Ansible change #1 \(CHG-1\)[,:]? ?/', '', $entry['message'])] = $entry['user'];
        }
        $user = function (string $start) use ($users): ?string {
            foreach ($users as $message => $who) {
                if (str_starts_with($message, $start)) {
                    return $who;
                }
            }

            return null;
        };
        $this->assertSame('alice', $user('dry run (run #'), 'the dry run, launched by alice');
        $this->assertSame('alice', $user('dry run #'), 'what it found');
        $this->assertSame('bob', $user('approved by bob'), 'the approval: bob, not the requester');
        $this->assertSame('carol', $user('apply started by carol'));
        $this->assertSame('carol', $user('batch 1: maintenance window'), 'the window of the batch carol started');
        $this->assertSame('carol', $user('batch 2 waits for an admin'));
        $this->assertSame('dave', $user('stop requested by dave'));
        $this->assertSame('dave', $user('was stopped'), 'and how it ended');
    }

    // ------------------------------------------------------------------ how the runner reaches the devices

    /** lab-sw1 (501) pointed at the address of lab-sw2 (502), as an admin who may edit devices could do. */
    private const ELSEWHERE = ['ansible_connection' => 'ansible.netcommon.network_cli', 'ansible_host' => '10.0.0.246', 'ansible_network_os' => 'cisco.ios.ios'];

    public function testTheDryRunRecordsHowTheRunnerReachesEachTarget(): void
    {
        $id = $this->change();
        $this->dryRun($id);

        $recorded = (array) $this->changes->find($id)['fingerprint_parts']['connections'];
        $this->assertSame(['10.0.0.245', '10.0.0.246', '10.0.0.247'], [Connections::address($recorded, 501), Connections::address($recorded, 502), Connections::address($recorded, 503)]);
        $this->assertSame('cisco.ios.ios', ((array) $recorded[501])['ansible_network_os']);
    }

    public function testADeviceReachedElsewhereAfterTheApprovalIsNotApplied(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->runner->connections[501] = self::ELSEWHERE;

        $answer = $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->assertSame('The dry run no longer counts (how the runner reaches a device changed since the dry run (lab-sw1: ansible_host 10.0.0.245 → 10.0.0.246)): '
            . 'launch it again.', $answer);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::DRAFT, 'void', null, null], [$change['status'], $change['dry_run_state'], $change['approved_by'], $change['approval_fingerprint']]);
        $this->assertNotNull($this->changes->approvals[0]['voided_at'], 'the approval is void');
        $this->assertSame(['dry_run'], array_column($this->runner->launches, 'kind'), 'no batch was launched');
        $this->assertStringContainsString('lab-sw1: ansible_host 10.0.0.245 → 10.0.0.246', end($this->events->entries)['message'], 'said in the eventlog');

        // a new dry run records the new address, and a new approval approves it
        $this->dryRun($id, null, $this->t0 + 60);
        $this->assertSame('10.0.0.246', Connections::address((array) $this->changes->find($id)['fingerprint_parts']['connections'], 501));
        $this->assertNull($lifecycle->approve($id, self::BOB, true, 'the new address is right', $this->t0 + 100));
        $this->backups->enabled = false;
        $this->assertNull($lifecycle->apply($id, self::ALICE, [], $this->t0 + 110));
        $this->assertSame([501], end($this->runner->launches)['devices'], 'batch 1 launched');
    }

    public function testADeviceReachedElsewhereIsSeenBeforeTheApproval(): void
    {
        $id = $this->change();
        $this->dryRun($id);
        $this->runner->connections[502] = [Connections::NOT_A_HOST => 'disabled in LibreNMS'];

        $error = $this->lifecycle()->approve($id, self::BOB, true, '', $this->t0 + 40);
        $this->assertSame('The dry run no longer counts (how the runner reaches a device changed since the dry run (lab-sw2 is no longer a host of the inventory '
            . '(disabled in LibreNMS))): launch it again.', $error);
        $this->assertSame([], $this->changes->approvals, 'nothing approved');

        // the page's polls see it too, before anyone approves
        $id = $this->change();
        $this->dryRun($id);
        $this->runner->connections[503] = ['ansible_host' => '10.0.0.247', 'ansible_network_os' => 'junipernetworks.junos.junos', 'ansible_connection' => 'ansible.netcommon.network_cli'];
        $this->lifecycle()->advance($id, $this->t0 + 50);
        $this->assertSame(ChangeStatus::DRAFT, $this->changes->find($id)['status']);
        $this->assertStringContainsString('lab-sw3: ansible_network_os cisco.ios.ios → junipernetworks.junos.junos', (string) $this->changes->find($id)['error']);
    }

    public function testADeviceReachedElsewhereRightBeforeItsBatchLaunchesIsNotRun(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = false;
        // after the checks of the batch's start (the window, the backups), right before the launch
        $this->runner->beforeVerify = function (string $kind) {
            if ($kind === 'batch') {
                $this->runner->connections[501] = self::ELSEWHERE;
            }
        };

        $this->assertNull($lifecycle->apply($id, self::ALICE, [], $this->t0 + 50));
        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::FAILED, $change['status']);
        $this->assertSame('batch 1 was not launched: The launch was refused: how the runner reaches a device changed since the dry run (lab-sw1: ansible_host '
            . '10.0.0.245 → 10.0.0.246): its approval no longer counts.', $change['error']);
        $this->assertSame(['dry_run'], array_column($this->runner->launches, 'kind'), 'no batch was launched');
        $this->assertSame('failed', $this->changes->batches[$id][1]['status']);
    }

    public function testADeviceReachedElsewhereBetweenBatchesStopsTheChange(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = false;
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);
        $lifecycle->advance($id, $this->t0 + 101);
        $this->assertSame([ChangeStatus::PAUSED, 'canary'], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting']]);
        $this->runner->connections[502] = ['ansible_host' => '10.0.0.99'] + self::ELSEWHERE;

        $lifecycle->advance($id, $this->t0 + 150);
        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::FAILED, $change['status']);
        $this->assertSame('while it waited before batch 2, how the runner reaches a device changed since the dry run (lab-sw2: ansible_host 10.0.0.246 → 10.0.0.99)',
            $change['error']);
        $this->assertSame(['dry_run', 'batch'], array_column($this->runner->launches, 'kind'), 'batch 2 was never launched');
        $this->assertSame('cancelled', $this->changes->batches[$id][2]['status']);
    }

    public function testADeviceReachedElsewhereWhileItsBatchRanStopsTheChange(): void
    {
        // the address changed after the launch's check: the batch may have run on another device; the change stops and says so
        $id = $this->approved(plan: BatchPlan::make(array_keys(self::TARGETS), [], 1, 5, 1, false, 0));
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = false;
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->runner->connections[501] = self::ELSEWHERE;
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);

        $lifecycle->advance($id, $this->t0 + 101);
        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::FAILED, $change['status']);
        $this->assertSame('how the runner reaches a device changed by the end of batch 1 (lab-sw1: ansible_host 10.0.0.245 → 10.0.0.246): the batch may have run on other '
            . 'devices than the ones dry-run', $change['error']);
        $this->assertSame(['dry_run', 'batch'], array_column($this->runner->launches, 'kind'), 'no further batch');
        $this->assertSame('failed', $this->changes->batches[$id][1]['status']);
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
        $messages503 = array_column($this->events->of(503), 'message');
        $this->assertNotEmpty(preg_grep('/^Ansible change #1 \(CHG-1\) applied/', $messages503));
        // the backups are recorded: in each device's eventlog with the version Oxidized keeps, and in the timeline
        $this->assertCount(1, preg_grep('/^Ansible change #1 \(CHG-1\), batch 2: Oxidized backed this device up before the batch \(version [0-9a-f]{40}\)$/', $messages503));
        $this->assertCount(1, preg_grep('/^Ansible change #1 \(CHG-1\), batch 2: Oxidized backed this device up after the batch \(version [0-9a-f]{40}\)$/', $messages503));
        $this->assertCount(1, preg_grep('/^Oxidized backed up lab-sw1 \(version [0-9a-f]{10}\) before batch 1\.$/', $this->changes->messages($id)));
        $this->assertCount(1, preg_grep('/^Oxidized backed up lab-sw2 \(version [0-9a-f]{10}\), lab-sw3 \(version [0-9a-f]{10}\) after batch 2\.$/', $this->changes->messages($id)));
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

        $windows = count($this->windows->windows);
        $this->assertNull($lifecycle->resume($id, self::ALICE, [], $this->t0 + 200));
        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::FAILED, $change['status']);
        $this->assertStringContainsString("batch 2 was not started: The launch was refused: the template changed in the runner since the dry run (the template's CLI arguments)",
            $change['error']);
        $this->assertCount(2, $this->runner->launches);
        $this->assertSame('failed', $this->changes->batches[$id][2]['status']);
        $this->assertSame($windows, count($this->windows->windows), 'refused before its window opened');
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
        $this->assertContains('Stopped by carol during batch 1.', $this->changes->messages($id), 'the timeline says it once, not "Stopped: stopped by"');
        $this->assertStringStartsWith("Ansible change #$id (CHG-1) was stopped by carol during batch 1, template 'Port description'", end($this->events->entries)['message']);
        $this->assertSame('cancelled', $this->changes->batches[$id][2]['status']);
        $this->assertSame($held + 600, $this->windows->windows[100]['end'], 'the window lasts the hold of the stopped batch and the settle time');
        $this->assertCount(2, $this->runner->launches, 'no batch after a stop');
        $this->assertStringContainsString('only a change being applied', strtolower((string) $lifecycle->stop($id, 'carol', $this->t0 + 95)));
    }

    public function testAStopWhileWaitingEndsTheChangeAtItsNextStep(): void
    {
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = false;
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);
        $lifecycle->advance($id, $this->t0 + 101);

        $this->assertNull($lifecycle->stop($id, 'alice', $this->t0 + 150));
        $this->assertSame(ChangeStatus::STOPPING, $this->changes->find($id)['status'], 'recorded at once');
        $this->assertStringContainsString('Only a paused change', (string) $lifecycle->resume($id, self::ALICE, [], $this->t0 + 151), 'nothing continues it');
        $lifecycle->advance($id, $this->t0 + 152);
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

    public function testAStopAfterTheRunEndedRecordsTheBatchAndEndsAfterIt(): void
    {
        // the batch's run ended, the change did not process it yet, then Stop
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->backups->now = $this->t0 + 50;
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50); // backups fresh: batch 1 launched
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);
        $this->backups->now = $this->t0 + 101;

        $this->assertNull($lifecycle->stop($id, 'alice', $this->t0 + 101));
        $lifecycle->advance($id, $this->t0 + 102);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::STOPPED, 'stopped by alice after batch 1'], [$change['status'], $change['error']]);
        $this->assertSame('done', $this->changes->batches[$id][1]['status'], 'the batch is recorded, not left running');
        $this->assertSame('changed', $this->changes->targets[$id][501]['result'], 'with its results');
        $this->assertSame('requested', $this->changes->targets[$id][501]['after_status'], 'its backups after it are asked for');
        $window = current($this->changes->windows($id));
        $this->assertSame($this->t0 + 100 + 600, Clock::unix($window['ends_at']), 'its window ends the settle time after it, not at once');
        $this->assertSame([502, 503], NextChange::devices($this->changes->targets[$id], NextChange::REMAINING), 'the remaining devices are those not changed');
    }

    public function testAStopReachesABatchWhoseLaunchHasNotRecordedItsRun(): void
    {
        // a request launched batch 1 and died before recording the run on the batch: the stop finds it by its token
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->backups->enabled = false;
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $runId = $this->batchRun($id, 1);
        $this->changes->batches[$id][1] = array_merge($this->changes->batches[$id][1], ['status' => 'launching', 'run_id' => null]);

        $this->assertNull($lifecycle->stop($id, 'bob', $this->t0 + 60));
        $this->assertSame(["$runId by bob"], $this->runner->stops, 'the runner is asked to stop it at once');
        $this->assertSame($runId, (int) $this->changes->batches[$id][1]['run_id']);
    }

    public function testNoBatchLaunchesOnceAStopIsAsked(): void
    {
        // the stop lands while Oxidized takes the backups of batch 1
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->backups->succeed = false;
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->assertSame('preparing', $this->changes->batches[$id][1]['status']);
        $this->assertNull($lifecycle->stop($id, 'bob', $this->t0 + 55));
        $lifecycle->advance($id, $this->t0 + 60);
        $this->assertSame(['dry_run'], array_column($this->runner->launches, 'kind'), 'batch 1 never launched');
        $this->assertSame([ChangeStatus::STOPPED, 'stopped by bob before batch 1 was launched'], [$this->changes->find($id)['status'], $this->changes->find($id)['error']]);

        // the stop lands while another request is between the backups and the launch (it read the change before)
        $this->setUp();
        $id = $this->approved();
        $lifecycle = $this->lifecycle();
        $this->backups->now = $this->t0 + 50;
        $this->backups->onRequest = function () use ($id): void {
            $this->changes->changes[$id] = array_merge($this->changes->changes[$id], ['status' => ChangeStatus::STOPPING, 'stop_requested_by' => 'bob',
                'stop_requested_at' => Clock::store($this->t0 + 50), 'version' => $this->changes->changes[$id]['version'] + 1]);
        };
        $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
        $this->assertSame(['dry_run'], array_column($this->runner->launches, 'kind'), 'its launch checks for the stop right before it');
    }

    public function testAnApprovalOrAPermissionLostDuringTheBackupsStopsTheBatchBeforeItsLaunch(): void
    {
        foreach (['the approver' => fn () => $this->runner->userProblems[2] = 'no longer holds the approve permission',
            'the applier' => fn () => $this->runner->userProblems[1] = 'no longer holds the run permission',
            'the approval' => fn (int $id) => $this->changes->changes[$id]['approved_at'] = Clock::store($this->t0 - 90000)] as $what => $lose) {
            $this->setUp();
            $id = $this->approved();
            $lifecycle = $this->lifecycle();
            $this->backups->succeed = false; // Oxidized takes its time
            $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50);
            $this->assertSame('preparing', $this->changes->batches[$id][1]['status'], $what);
            $lose($id);
            $this->backups->succeed = true;
            $this->backups->nodes = [501 => ['name' => 'dev501', 'full_name' => 'lab/dev501', 'last' => ['start' => gmdate('Y-m-d H:i:s', $this->t0 + 70) . ' UTC',
                'end' => gmdate('Y-m-d H:i:s', $this->t0 + 74) . ' UTC', 'status' => 'success']]];
            $lifecycle->advance($id, $this->t0 + 80);

            $change = $this->changes->find($id);
            $this->assertSame(['dry_run'], array_column($this->runner->launches, 'kind'), "$what: batch 1 never launched");
            $this->assertSame(ChangeStatus::FAILED, $change['status'], $what);
            $this->assertStringStartsWith('batch 1 was not launched: ', $change['error'], $what);
        }
    }

    public function testAnApplyIsRefusedWhileAnotherChangeReservesItsDevices(): void
    {
        // A: lab-sw1, then lab-sw2 (it waits after its canary); B: lab-sw3, then lab-sw2
        $a = $this->changes->add([501 => self::TARGETS[501], 502 => self::TARGETS[502]], BatchPlan::make([501, 502], [], 1, 5, 1, true, 0));
        $b = $this->changes->add([503 => self::TARGETS[503], 502 => self::TARGETS[502]], BatchPlan::make([503, 502], [], 1, 5, 1, true, 0));
        $this->dryRun($a, [501 => 'changed', 502 => 'changed']);
        $this->dryRun($b, [503 => 'changed', 502 => 'changed']);
        $lifecycle = $this->lifecycle();
        $this->assertNull($lifecycle->approve($a, self::BOB, true, '', $this->t0 + 40));
        $this->assertNull($lifecycle->approve($b, self::BOB, true, '', $this->t0 + 40));
        $this->backups->enabled = false;
        $this->assertNull($lifecycle->apply($a, self::ALICE, [], $this->t0 + 50));
        $this->endRun($this->batchRun($a, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);
        $lifecycle->advance($a, $this->t0 + 101);
        $this->assertSame([ChangeStatus::PAUSED, 'canary'], [$this->changes->find($a)['status'], $this->changes->find($a)['waiting']]);

        $refused = 'Another change being applied reserves a device of this one until it ends: lab-sw2 (change #' . $a . '). Apply this change once that change has ended.';
        $this->assertSame($refused, $lifecycle->apply($b, self::ALICE, [], $this->t0 + 110), 'A, paused after its canary, reserves lab-sw2');
        $this->assertSame(ChangeStatus::READY, $this->changes->find($b)['status'], 'B stays ready: its dry run and approval still count');
        $this->assertNull($this->changes->find($b)['applied_by']);
        $this->assertNull($lifecycle->resume($a, self::ALICE, [], $this->t0 + 120));
        $this->assertSame($refused, $lifecycle->apply($b, self::ALICE, [], $this->t0 + 130), 'and while it applies its later batch');
        $this->assertNull($lifecycle->stop($a, 'alice', $this->t0 + 140));
        $this->assertSame(ChangeStatus::STOPPING, $this->changes->find($a)['status']);
        $this->assertSame($refused, $lifecycle->apply($b, self::ALICE, [], $this->t0 + 150), 'and while it stops');
        $this->endRun($this->batchRun($a, 2), 'stopped', [502 => 'unknown'], [], $this->t0 + 160);
        $lifecycle->advance($a, $this->t0 + 161);
        $this->assertSame(ChangeStatus::STOPPED, $this->changes->find($a)['status']);
        $this->assertSame(['dry_run', 'dry_run', 'batch', 'batch'], array_column($this->runner->launches, 'kind'), 'nothing of B launched meanwhile');

        // the check and the switch to applying are made under the lock of the launches
        $this->runner->lockRefusal = 'Another launch is being recorded: try again in a moment.';
        $this->assertSame('Another launch is being recorded: try again in a moment.', $lifecycle->apply($b, self::ALICE, [], $this->t0 + 170));
        $this->assertSame(ChangeStatus::READY, $this->changes->find($b)['status']);
        $this->runner->lockRefusal = null;
        $this->assertNull($lifecycle->apply($b, self::ALICE, [], $this->t0 + 180), 'once A ended');
        $this->assertSame(ChangeStatus::APPLYING, $this->changes->find($b)['status']);
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
        $this->assertStringContainsString('while it waited before batch 2, its approval expired', $change['error']);
    }

    public function testSecretAnswersAreAskedForEachBatchAndNeverKept(): void
    {
        $id = $this->approved(['has_secrets' => true, 'variables' => [['name' => 'key', 'label' => 'Key', 'type' => 'secret', 'value' => '********', 'secret' => true]]],
            BatchPlan::make(array_keys(self::TARGETS), [], 1, 1, 1, false, 0));
        $lifecycle = $this->lifecycle();
        $this->backups->now = $this->t0 + 50;

        // Oxidized backs batch 1 up at once: it launches within the apply, with the secret answers given there
        $this->assertNull($lifecycle->apply($id, self::ALICE, ['key' => 's3cret'], $this->t0 + 50));
        $this->assertSame([1, ['key' => 's3cret']], [$this->runner->launches[1]['number'], $this->runner->launches[1]['secrets']], 'the secrets go to the runner');
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);
        $this->backups->now = $this->t0 + 101;
        $lifecycle->advance($id, $this->t0 + 101); // the sync command starts batch 2: it cannot launch it without them
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'secrets', 2], [$change['status'], $change['waiting'], (int) $change['current_batch']], 'every batch needs them again');
        $this->assertSame('prepared', $this->changes->batches[$id][2]['status']);
        $this->assertNull($lifecycle->resume($id, self::ALICE, ['key' => 's3cret'], $this->t0 + 103), 'the page that holds them launches it');
        $this->assertSame([2, ['key' => 's3cret']], [$this->runner->launches[2]['number'], $this->runner->launches[2]['secrets']]);
        $this->assertSame([], array_values(array_filter($this->changes->messages($id), fn ($m) => str_contains($m, 'enter the secret answers'))),
            'it did not really wait: the timeline does not say it did');

        $stored = json_encode([$this->changes->changes, $this->changes->targets, $this->changes->batches, $this->changes->events, $this->runs->runs]);
        $this->assertStringNotContainsString('s3cret', (string) $stored);

        $this->endRun($this->batchRun($id, 2), 'success', [502 => 'changed'], [], $this->t0 + 150);
        $this->backups->now = $this->t0 + 151;
        $lifecycle->advance($id, $this->t0 + 151);
        $this->assertSame('secrets', $this->changes->find($id)['waiting']);
        $this->assertNull($lifecycle->stop($id, 'alice', $this->t0 + 160));
        $lifecycle->advance($id, $this->t0 + 161);
        $this->assertSame([ChangeStatus::STOPPED, 'stopped by alice before batch 3 was launched'], [$this->changes->find($id)['status'], $this->changes->find($id)['error']],
            'a batch waiting for the secret answers was not launched');
    }

    public function testAnActionWithoutARequiredSecretAnswerIsRefusedAndChangesNothing(): void
    {
        $this->runner->snapshot = FakeChangeRunner::snapshotOf(['surveyVars' => [new SurveyVar('interface', 'Interface', '', 'string', '', true),
            new SurveyVar('key', 'Key', '', 'secret', 'secret', true)]]);
        $id = $this->change(['has_secrets' => true, 'variables' => [['name' => 'key', 'label' => 'Key', 'type' => 'secret', 'value' => '********', 'secret' => true,
            'required' => true]]], BatchPlan::make(array_keys(self::TARGETS), [], 1, 5, 1, true, 0));
        $lifecycle = $this->lifecycle();
        $why = ': the plugin never keeps secret answers, so each launch needs them.';
        $this->assertSame("Enter the secret answer Key for the dry run$why", $lifecycle->startDryRun($id, self::ALICE, ['key' => ''], $this->t0));
        $this->assertSame([ChangeStatus::DRAFT, 0, []], [$this->changes->find($id)['status'], (int) $this->changes->find($id)['dry_runs'], $this->runner->launches]);
        $this->dryRun($id, null, 0, ['key' => 's3cret']);
        $this->assertNull($lifecycle->approve($id, self::BOB, true, '', $this->t0 + 40));

        $this->backups->now = $this->t0 + 50;
        $this->assertSame("Enter the secret answer Key to apply the change$why", $lifecycle->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->assertSame([ChangeStatus::READY, [], []], [$this->changes->find($id)['status'], $this->windows->windows, $this->backups->requests],
            'no window, no backup: nothing was started');
        $this->assertNull($lifecycle->apply($id, self::ALICE, ['key' => 's3cret'], $this->t0 + 50));
        $this->endRun($this->batchRun($id, 1), 'success', [501 => 'changed'], [], $this->t0 + 100);
        $lifecycle->advance($id, $this->t0 + 101);
        $this->assertSame([ChangeStatus::PAUSED, 'canary'], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting']]);

        // Continue after the canary without it: refused, the change still waits after its first batch
        $this->assertSame("Enter the secret answer Key to continue with batch 2$why", $lifecycle->resume($id, self::ALICE, ['key' => ''], $this->t0 + 110));
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'canary', 1], [$change['status'], $change['waiting'], (int) $change['current_batch']]);
        $this->assertCount(1, $this->windows->windows, 'no window for batch 2');

        // with it, batch 2 starts; its backups come in a later request, which has no secret answers: it waits for them
        $this->backups->succeed = false;
        $this->assertNull($lifecycle->resume($id, self::ALICE, ['key' => 's3cret'], $this->t0 + 120));
        foreach ([502, 503] as $deviceId) {
            $this->backups->nodes[$deviceId] = ['name' => "dev$deviceId", 'full_name' => "lab/dev$deviceId", 'last' => ['start' => gmdate('Y-m-d H:i:s', $this->t0 + 125) . ' UTC',
                'end' => gmdate('Y-m-d H:i:s', $this->t0 + 129) . ' UTC', 'status' => 'success']];
        }
        $lifecycle->advance($id, $this->t0 + 130);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'secrets', 2], [$change['status'], $change['waiting'], (int) $change['current_batch']]);
        $deadline = Clock::unix($change['resume_at']);
        $this->assertSame("Enter the secret answer Key to launch batch 2$why The batch keeps waiting for them until " . Clock::show($deadline, 'Y-m-d H:i T') . '.',
            $lifecycle->resume($id, self::ALICE, [], $this->t0 + 135));
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'secrets', 'prepared'], [$change['status'], $change['waiting'], $this->changes->batches[$id][2]['status']], 'it keeps waiting');
        $this->assertSame(['dry_run', 'batch'], array_column($this->runner->launches, 'kind'));
        $this->assertNull($lifecycle->resume($id, self::ALICE, ['key' => 's3cret'], $this->t0 + 140));
        $this->assertSame([2, ['key' => 's3cret']], [$this->runner->launches[2]['number'], $this->runner->launches[2]['secrets']]);
    }

    public function testAWaitForTheSecretAnswersEndsTheChangeAfterTheExpectedTimeOfABatch(): void
    {
        $id = $this->approved(['has_secrets' => true, 'variables' => [['name' => 'key', 'label' => 'Key', 'type' => 'secret', 'value' => '********', 'secret' => true]]]);
        $lifecycle = $this->lifecycle(['expectedSeconds' => 1800]);
        $this->backups->succeed = false; // Oxidized takes its time: the apply's request ends before the backups
        $lifecycle->apply($id, self::ALICE, ['key' => 's3cret'], $this->t0 + 50);
        $this->backups->nodes[501] = ['name' => 'dev501', 'full_name' => 'lab/dev501', 'last' => ['start' => gmdate('Y-m-d H:i:s', $this->t0 + 52) . ' UTC',
            'end' => gmdate('Y-m-d H:i:s', $this->t0 + 56) . ' UTC', 'status' => 'success']];
        $lifecycle->advance($id, $this->t0 + 60); // the backups are fresh; the page that held the secrets was closed
        $change = $this->changes->find($id);
        $this->assertSame(['paused', 'secrets'], [$change['status'], $change['waiting']]);
        $deadline = Clock::unix($change['resume_at']);
        $this->assertSame($this->t0 + 60 + 1800, $deadline, 'it waits the expected time of a batch');
        $this->assertSame('open', current($this->changes->windows($id))['status'], 'its window is open meanwhile');
        $waits = fn () => array_values(array_filter($this->changes->messages($id), fn ($m) => str_contains($m, 'enter the secret answers')));
        $lifecycle->advance($id, $this->t0 + 60 + ChangeLifecycle::SECRETS_ANNOUNCE - 1);
        $this->assertSame([], $waits(), 'not said while the page that holds them may still launch it');
        $lifecycle->advance($id, $this->t0 + 60 + ChangeLifecycle::SECRETS_ANNOUNCE);
        $lifecycle->advance($id, $this->t0 + 60 + ChangeLifecycle::SECRETS_ANNOUNCE + 5);
        $this->assertSame(['Batch 1 waits for an admin to enter the secret answers again (the plugin never keeps them) until ' . Clock::show($deadline, 'Y-m-d H:i T')
            . ', then the change ends.'], $waits(), 'said once it is real, once');
        $this->assertSame('until ' . Clock::show($deadline, 'Y-m-d H:i T') . ', then the change ends', $this->changes->batches[$id][1]['note']);

        $lifecycle->advance($id, $deadline - 1);
        $this->assertSame('paused', $this->changes->find($id)['status']);
        $lifecycle->advance($id, $deadline);
        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::FAILED, $change['status']);
        $this->assertStringStartsWith('while it waited before batch 1, nobody entered the secret answers by ', $change['error']);
        $this->assertSame('cancelled', $this->changes->batches[$id][1]['status']);
        $this->assertSame(['dry_run'], array_column($this->runner->launches, 'kind'), 'no batch was launched');
        $window = current($this->changes->windows($id));
        $this->assertTrue((bool) $window['closing'], 'its window ends now');
        $this->assertSame($deadline, Clock::unix($window['ends_at']));
        $this->assertSame('Only a paused change can be continued.', $lifecycle->resume($id, self::ALICE, ['key' => 's3cret'], $deadline + 1));
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
        $this->backups->nodeCalls = 0;
        $this->lifecycle()->apply($id, self::ALICE, [], $this->t0 + 50);

        $this->assertSame(1, $this->backups->nodeCalls, 'one call: each one could wait its timeout');
        $this->assertSame(['unavailable', 'unavailable', 'unavailable'], array_column($this->changes->targets[$id], 'before_status'));
        $this->assertSame(ChangeStatus::FAILED, $this->changes->find($id)['status']);
        $this->assertStringContainsString('Oxidized cannot be reached', $this->changes->find($id)['error']);
    }

    public function testTheDryRunSaysWhichTargetsOxidizedCannotBackUp(): void
    {
        $this->backups->nodes = [502 => null, 503 => ['excluded' => true]];
        $id = $this->change();
        $this->dryRun($id);

        $this->assertSame(['node', 'no_node', 'excluded'], array_column($this->changes->targets[$id], 'backup_check'));
        $this->assertStringContainsString('Oxidized cannot back up lab-sw2 (Oxidized has no node for it), lab-sw3 (LibreNMS leaves it out of Oxidized): their batches '
            . 'will be refused (setting Without a fresh backup: refuse)', implode("\n", array_column($this->changes->events[$id], 'message')));
        $this->assertStringContainsString('Oxidized has no node for this device, so its batch will be refused', implode("\n", array_column($this->events->of(502), 'message')));
        $this->assertSame([], array_filter(array_column($this->events->of(501), 'message'), fn ($m) => str_contains($m, 'Oxidized')), 'nothing for a device it backs up');

        // Oxidized down: said once, not asked for each device
        $this->backups->unreachable = 'Oxidized cannot be reached (unreachable)';
        $this->backups->nodeCalls = 0;
        $id = $this->change();
        $this->dryRun($id);
        $this->assertSame(1, $this->backups->nodeCalls);
        $this->assertSame(['unknown', 'unknown', 'unknown'], array_column($this->changes->targets[$id], 'backup_check'));
        $this->assertStringContainsString("Oxidized could not be asked whether it backs the targets up (Oxidized cannot be reached (unreachable)): check LibreNMS' oxidized.url setting",
            implode("\n", array_column($this->changes->events[$id], 'message')));
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
        $this->assertStringContainsString('batch 2 was not started: alice, who launches it, no longer holds the run permission', $this->changes->find($id)['error']);
    }
}
