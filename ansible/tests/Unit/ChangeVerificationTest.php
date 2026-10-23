<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\ApprovalRules;
use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
use Agoodley\LibrenmsAnsible\Changes\ChangeLifecycle;
use Agoodley\LibrenmsAnsible\Changes\ChangeSettings;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Changes\TopologyError;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeBackups;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeChangeRunner;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeRefresh;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeTopology;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeVerifier;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeWindows;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\MemoryChangeStore;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\MemoryRunStore;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\RecordingEvents;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4: each batch verified with the topology plugin's verification API. The state of the network around a batch is
 * taken before it (from data LibreNMS discovered again for it, at most the wait), and again after it once the settle time
 * is over and LibreNMS' data is newer than that; the comparison decides. The safety rules: no batch starts after one
 * whose verification found unexpected changes or could not be made unless an admin accepted it (pause), never with the
 * stop setting; no comparison on data older than the batch's end; only the sync command takes states; the last batch
 * does not pause, the change ends with its outcome; the eventlog counts, the timeline names.
 *
 * The change: lab-sw4 (510, a canary with lab-sw2 and lab-sw3 as neighbors), then lab-sw2 (502).
 */
class ChangeVerificationTest extends TestCase
{
    private const TARGETS = [510 => 'lab-sw4.netops.lab', 502 => 'lab-sw2.netops.lab'];

    private const ALICE = ['user_id' => 1, 'username' => 'alice'];

    private const BOB = ['user_id' => 2, 'username' => 'bob'];

    private MemoryChangeStore $changes;

    private MemoryRunStore $runs;

    private FakeChangeRunner $runner;

    private FakeBackups $backups;

    private RecordingEvents $events;

    private FakeVerifier $verifier;

    private FakeRefresh $refresh;

    private int $t0;

    protected function setUp(): void
    {
        $this->changes = new MemoryChangeStore();
        $this->runs = new MemoryRunStore();
        $this->runner = new FakeChangeRunner($this->runs, self::TARGETS);
        $this->backups = new FakeBackups();
        $this->backups->enabled = false;
        $this->events = new RecordingEvents();
        $this->verifier = new FakeVerifier();
        $this->verifier->neighbors = [510 => [502, 503], 502 => [501, 503, 510, 512]];
        $this->verifier->domain = [501, 511, 512];
        $this->refresh = new FakeRefresh();
        $this->t0 = strtotime('2026-10-22 10:00:00 UTC');
        $this->backups->now = $this->t0;
        // LibreNMS' data as it was before the change: discovered and polled an hour ago
        $this->verifier->discoveredAt = $this->verifier->polledAt = $this->t0 - 3600;
    }

    private function lifecycle(array $settings = []): ChangeLifecycle
    {
        return new ChangeLifecycle($this->changes, $this->runs, $this->runner, $this->backups, new FakeWindows(), $this->events,
            new ChangeSettings(...$settings + ['approval' => ApprovalRules::SECOND, 'settleSeconds' => 600, 'expectedSeconds' => 3600, 'maintenance' => false,
                'verify' => true, 'verifyWaitSeconds' => 1800]), new FakeTopology(), $this->verifier, $this->refresh);
    }

    /** A change of lab-sw4 then lab-sw2, dry-run by alice and approved by bob. */
    private function approved(array $fields = [], ?BatchPlan $plan = null): int
    {
        $plan ??= BatchPlan::make(array_keys(self::TARGETS), [], 1, 5, 1, false, 0);
        $id = $this->changes->add(array_intersect_key(self::TARGETS, array_flip(array_merge(...$plan->batches))), $plan, $fields);
        $lifecycle = $this->lifecycle();
        $this->assertNull($lifecycle->startDryRun($id, self::ALICE, [], $this->t0));
        $runId = (int) $this->changes->find($id)['dry_run_id'];
        $lines = ['DRY RUN ****', 'PLAY [Description] [CHECK MODE] ****', 'TASK [Set it] [CHECK MODE] ****'];
        foreach ($plan->batches as $batch) {
            foreach ($batch as $deviceId) {
                $lines[] = 'changed: [' . self::TARGETS[$deviceId] . ']';
            }
        }
        $lines[] = 'PLAY RECAP ****';
        foreach (array_merge(...$plan->batches) as $deviceId) {
            $lines[] = self::TARGETS[$deviceId] . ' : ok=1 changed=1 unreachable=0 failed=0 skipped=0 rescued=0 ignored=0';
        }
        $this->endRun($runId, array_fill_keys(array_merge(...$plan->batches), 'changed'), $lines, $this->t0 + 30);
        $lifecycle->advance($id, $this->t0 + 35);
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

    /** A user's action, or a page's refresh: a web request, which takes no state. */
    private function web(int $at): ChangeLifecycle
    {
        $this->verifier->takes = false;
        $this->verifier->now = $at;

        return $this->lifecycle($this->settings);
    }

    /** @var array<string, mixed> the settings of the test's lifecycle (ChangeSettings) */
    private array $settings = [];

    /** One run of the sync command at $at. */
    private function sync(int $id, int $at): array
    {
        $this->verifier->takes = true;
        $this->verifier->now = $at;

        return $this->lifecycle($this->settings)->advance($id, $at);
    }

    /** End batch $number's run (every device changed) at $at, taken in by a page's refresh. */
    private function endBatch(int $id, int $number, int $at): void
    {
        $runId = (int) $this->changes->batches[$id][$number]['run_id'];
        $this->assertGreaterThan(0, $runId, "batch $number has a run");
        $this->endRun($runId, array_fill_keys(array_keys($this->runs->targets[$runId]), 'changed'), [], $at);
        $this->web($at + 1)->advance($id, $at + 1);
    }

    /** LibreNMS discovered and polled every device at $at. */
    private function fresh(int $at): void
    {
        $this->verifier->discoveredAt = $this->verifier->polledAt = $at;
    }

    /** @return int[][] the devices of the batch launches, in order */
    private function launched(): array
    {
        return array_column(array_values(array_filter($this->runner->launches, fn ($l) => $l['kind'] === 'batch')), 'devices');
    }

    private function messages(int $id): string
    {
        return implode("\n", $this->changes->messages($id));
    }

    private function verify(int $id, int $number): array
    {
        return (array) ($this->changes->batches[$id][$number]['verify'] ?? []);
    }

    /** What the change page shows of the verification it waits for: what an acceptance names. */
    private function seen(int $id): string
    {
        $number = (int) $this->changes->find($id)['current_batch'];

        return ChangeLifecycle::verifyCheckId($this->verify($id, $number), $id, $number);
    }

    /** A comparison in which lab-sw4's uplink to lab-sw2 is gone and lab-sw3 became a single point of failure for it. */
    private static function uplinkLost(): array
    {
        return ['events' => [['type' => 'link_removed', 'category' => 'links', 'level' => 'warning', 'text' => 'Link gone: lab-sw2 Fa0/1 ↔ lab-sw4 Fa0/11', 'devices' => [502, 510]],
            ['type' => 'blocking_stopped', 'category' => 'stp', 'level' => 'info', 'text' => 'lab-sw2 Fa0/1 stopped blocking in VLAN 1 / CST', 'devices' => [502], 'vlans' => [0]]],
            'findings' => ['new' => [['identity' => 'n1', 'analysis' => 'redundancy', 'code' => 'spof_link', 'severity' => 'info', 'title' => 'Single link: lab-sw4 Fa0/12 ↔ lab-sw3 Fa0/1',
                'devices' => [503, 510], 'subjects' => [503, 510], 'vlans' => [], 'quiet' => false]],
                'worse' => [['identity' => 'w1', 'analysis' => 'redundancy', 'code' => 'spof_device', 'severity' => 'warning', 'title' => 'Single point of failure: lab-sw3',
                    'devices' => [503, 510, 513, 514], 'subjects' => [503], 'vlans' => [], 'quiet' => false, 'was' => ['identity' => 'w0', 'severity' => 'warning',
                        'devices' => [503, 513, 514]]]],
                'changed' => [], 'milder' => [], 'resolved' => [], 'same' => 8],
            'devices' => ['down' => [], 'up' => []], 'names' => [502 => 'lab-sw2', 503 => 'lab-sw3', 510 => 'lab-sw4']];
    }

    /**
     * Apply the change and take the state before batch 1 from fresh data (at t0 + 120), batch 1 launched; returns the change.
     */
    private function applied(array $fields = []): int
    {
        $id = $this->approved($fields);
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->sync($id, $this->t0 + 60);
        $this->fresh($this->t0 + 90);
        $this->sync($id, $this->t0 + 120);
        $this->assertSame([[510]], $this->launched(), 'batch 1 launched once the state before it was taken');

        return $id;
    }

    // ------------------------------------------------------------------ verified

    public function testEachBatchIsVerifiedOnFreshDataBeforeTheNextStarts(): void
    {
        $id = $this->approved();

        // the Apply (a page's request) takes no state: batch 1 waits for the state before it
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->assertSame([], $this->launched());
        $this->assertSame(0, $this->verifier->count('scope') + $this->verifier->count('state'), 'a page never takes a scope or a state');
        $this->assertSame(ChangeStatus::APPLYING, $this->changes->find($id)['status']);
        $this->assertStringContainsString('Before batch 1: the plugin takes the state of the network around it first', $this->messages($id));

        // the sync command: the scope of batch 1, LibreNMS asked to discover it again, then fresh data, then the state and the launch
        $this->sync($id, $this->t0 + 60);
        $this->assertSame([[510, 502, 503]], $this->refresh->requests, 'lab-sw4 and its neighbors discovered again');
        $this->sync($id, $this->t0 + 120);
        $this->assertSame([], $this->launched(), 'its data is older than the refresh: the batch waits');
        $this->assertSame('lab-sw4 (a discovery and a poll), lab-sw2 (a discovery and a poll), lab-sw3 (a discovery and a poll), lab-sw1 (a poll), lab-sw5 (a poll) and 1 more',
            $this->verify($id, 1)['before']['waiting'], 'its devices and their neighbors need a discovery and a poll, the rest of the domain a poll');
        $this->fresh($this->t0 + 150);
        $this->sync($id, $this->t0 + 180);
        $this->assertSame([[510]], $this->launched());
        $this->assertSame(['taken', false], [$this->verify($id, 1)['before']['status'], $this->verify($id, 1)['before']['stale']]);
        $this->assertStringContainsString('State before batch 1 taken (6 devices, 1 fact, 0 findings), from fresh data.', $this->messages($id));

        // batch 1 ends: its verification waits for the settle time, then asks for fresh data (with batch 2's devices, for its state before)
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->assertSame(['settling', $this->t0 + 900], [$this->verify($id, 1)['after']['status'], $this->verify($id, 1)['after']['due']]);
        $this->sync($id, $this->t0 + 600);
        $this->assertSame(1, count($this->refresh->requests), 'nothing asked during the settle time');
        $this->sync($id, $this->t0 + 960);
        $this->assertSame([510, 502, 503, 501, 512], $this->refresh->requests[1], 'what the state before batch 1 covered, and batch 2 with its neighbors');
        $this->sync($id, $this->t0 + 1020);
        $this->assertSame([[510]], $this->launched(), 'data older than the batch\'s end and its settle time is never compared');
        $this->assertSame(1, $this->verifier->count('state'));

        // fresh data: the state after it, compared, verified; batch 2 starts from its own state (its refresh was asked with it)
        $this->fresh($this->t0 + 1000);
        $this->sync($id, $this->t0 + 1080);
        $this->assertSame('verified', $this->verify($id, 1)['result']);
        $this->assertSame([[510], [502]], $this->launched());
        $this->assertSame([$this->t0 + 180, $this->t0 + 1080, $this->t0 + 1080], $this->verifier->stateTimes(), 'before batch 1, after it, before batch 2');
        $this->assertStringContainsString('Batch 1 verified: nothing unexpected changed (1 fact and 0 findings compared on fresh data).', $this->messages($id));

        $this->endBatch($id, 2, $this->t0 + 1200);
        $this->sync($id, $this->t0 + 1900);
        $this->fresh($this->t0 + 1950);
        $this->sync($id, $this->t0 + 2000);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::APPLIED, 'verified', null], [$change['status'], $change['verification'], $change['verification_note']]);
        $this->assertStringContainsString('Verification: verified', $this->messages($id));
        $this->assertSame(1, $this->verifier->count('compare') - 1, 'one comparison per batch');
    }

    public function testOnlyTheSyncCommandTakesStates(): void
    {
        $id = $this->applied();
        $this->endBatch($id, 1, $this->t0 + 300);
        $calls = count($this->verifier->calls);
        $this->fresh($this->t0 + 5000);
        foreach ([1000, 2000, 3000] as $offset) {
            $this->web($this->t0 + $offset)->advance($id, $this->t0 + $offset);
        }
        $this->assertSame($calls, count($this->verifier->calls), 'page refreshes ask nothing of the verification API');
        $this->assertSame('settling', $this->verify($id, 1)['after']['status']);
        $this->assertSame([[510]], $this->launched());
    }

    // ------------------------------------------------------------------ unexpected changes

    public function testUnexpectedChangesPauseTheChangeUntilAnApproverAcceptsThem(): void
    {
        $id = $this->applied();
        $this->verifier->comparison = self::uplinkLost();
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->fresh($this->t0 + 1000);
        $this->sync($id, $this->t0 + 1080);

        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'verification'], [$change['status'], $change['waiting']]);
        $this->assertSame([[510]], $this->launched(), 'batch 2 does not start');
        $this->assertStringContainsString('Batch 1 made 2 unexpected changes: Link gone: lab-sw2 Fa0/1 ↔ lab-sw4 Fa0/11; Worse finding (warning): Single point of failure: lab-sw3 '
            . '(now about 4 devices, was 3). The change waits for an admin with the approve permission (not the requester) to accept them: batch 2 does not start before', $this->messages($id));
        // the new single link is info: below the "Count findings from" setting, listed only
        $result = $this->changes->resultOf($id, 1)['evaluation'];
        $this->assertSame(['Single link: lab-sw4 Fa0/12 ↔ lab-sw3 Fa0/1'], array_map(fn ($e) => substr($e['text'], strlen('New finding (info): ')),
            array_values(array_filter($result['listed'], fn ($e) => $e['type'] === 'new'))));
        // the eventlog of each target counts: it names no other device
        $event = array_values(preg_grep('/batch 1 made/', array_column($this->events->of(510), 'message')))[0];
        $this->assertSame('Ansible change #1 (CHG-1): batch 1 made 2 unexpected changes (1 topology change, 1 finding): the change waits for an admin to accept them', $event);

        // nobody goes on without the acceptance: no Continue, no acceptance of another check, nor by the requester with a second admin
        $this->assertStringContainsString('to accept the unexpected changes of batch 1 (Accept): batch 2 does not start before', $this->web($this->t0 + 1100)->resume($id, self::BOB, [], $this->t0 + 1100));
        $this->assertSame(ChangeLifecycle::VERIFY_SEEN_CHANGED, $this->web($this->t0 + 1100)->acceptVerification($id, self::BOB, true, '', $this->t0 + 1100, str_repeat('0', 32)));
        $this->assertStringContainsString('another admin with the approve permission accepts', $this->web($this->t0 + 1100)->acceptVerification($id, self::ALICE, true, '', $this->t0 + 1100,
            $this->seen($id)));
        $this->assertStringContainsString('approve permission', $this->web($this->t0 + 1100)->acceptVerification($id, self::BOB, false, '', $this->t0 + 1100, $this->seen($id)));
        foreach ([1200, 2400, 7200] as $offset) {
            $this->sync($id, $this->t0 + $offset);
        }
        $this->assertSame([[510]], $this->launched(), 'a pause on unexpected changes never ends by itself');

        $this->assertNull($this->web($this->t0 + 7300)->acceptVerification($id, self::BOB, true, 'uplink being replaced', $this->t0 + 7300, $this->seen($id)));
        $this->assertStringContainsString('bob accepted the 2 unexpected changes of batch 1: "uplink being replaced"; the change goes on', $this->messages($id));
        // the refresh asked for batch 2's state with batch 1's verification is older than the wait now: asked again
        $this->sync($id, $this->t0 + 7320);
        $this->assertSame([[510]], $this->launched());
        $this->assertSame([502, 501, 503, 510, 512], end($this->refresh->requests));
        $this->fresh($this->t0 + 7330);
        $this->sync($id, $this->t0 + 7380);
        $this->assertSame([[510], [502]], $this->launched(), 'batch 2 starts once accepted');

        $this->verifier->comparison = [];
        $this->endBatch($id, 2, $this->t0 + 7400);
        $this->sync($id, $this->t0 + 8100);
        $this->fresh($this->t0 + 8150);
        $this->sync($id, $this->t0 + 8200);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::APPLIED, 'accepted', 'what batch 1 changed was accepted'], [$change['status'], $change['verification'], $change['verification_note']]);
    }

    public function testTheStopSettingEndsTheChangeAndNoBatchFollows(): void
    {
        $this->settings = ['verifyUnexpected' => 'stop'];
        $id = $this->applied();
        $this->verifier->comparison = self::uplinkLost();
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->fresh($this->t0 + 1000);
        $this->sync($id, $this->t0 + 1080);

        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::FAILED, 'not_verified'], [$change['status'], $change['verification']]);
        $this->assertStringContainsString('batch 1 made unexpected changes (setting When a batch makes unexpected changes: stop the change)', $change['error']);
        $this->assertSame([[510]], $this->launched());
        $this->assertSame('cancelled', $this->changes->batches[$id][2]['status']);
        $this->assertStringContainsString('Ansible change #1 (CHG-1) failed', implode("\n", array_column($this->events->of(510), 'message')));
        $this->assertNotNull($this->web($this->t0 + 1100)->acceptVerification($id, self::BOB, true, '', $this->t0 + 1100, $this->seen($id)), 'nothing to accept: it ended');
    }

    public function testTheRecordSettingGoesOnAndTheOutcomeSaysSo(): void
    {
        $this->settings = ['verifyUnexpected' => 'record'];
        $id = $this->applied();
        $this->verifier->comparison = self::uplinkLost();
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->fresh($this->t0 + 1000);
        $this->sync($id, $this->t0 + 1080);
        $this->assertSame([[510], [502]], $this->launched());
        $this->assertStringContainsString('Recorded; the change goes on (setting When a batch makes unexpected changes: only record)', $this->messages($id));

        $this->verifier->comparison = [];
        $this->endBatch($id, 2, $this->t0 + 1200);
        $this->sync($id, $this->t0 + 1900);
        $this->fresh($this->t0 + 1950);
        $this->sync($id, $this->t0 + 2000);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::APPLIED, 'not_verified', 'batch 1 made unexpected changes'], [$change['status'], $change['verification'], $change['verification_note']]);
    }

    public function testFindingsCountFromTheSettingsSeverity(): void
    {
        $this->settings = ['verifyMinimum' => 'info'];
        $id = $this->applied();
        $comparison = self::uplinkLost();
        $comparison['events'] = [];
        $comparison['findings']['worse'] = [];
        $this->verifier->comparison = $comparison;
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->fresh($this->t0 + 1000);
        $this->sync($id, $this->t0 + 1080);
        $this->assertSame('unexpected', $this->verify($id, 1)['result'], 'from info on, the new single link counts');

        $this->setUp();
        $this->settings = ['verifyMinimum' => 'warning'];
        $id = $this->applied();
        $this->verifier->comparison = $comparison;
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->fresh($this->t0 + 1000);
        $this->sync($id, $this->t0 + 1080);
        $this->assertSame('verified', $this->verify($id, 1)['result'], 'from warning on, it is listed only');
        $this->assertSame([[510], [502]], $this->launched());
    }

    // ------------------------------------------------------------------ cannot be verified

    public function testABatchWithoutFreshDataCannotBeVerifiedAndIsCheckedAgainEveryMinute(): void
    {
        $id = $this->applied();
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        // LibreNMS never discovers lab-sw4 again: past the wait (30 min after the settle time) the batch cannot be verified
        $this->verifier->devices[510] = ['discovered_at' => $this->t0 + 200];
        $this->fresh($this->t0 + 1000);
        $this->verifier->devices[510] += ['polled_at' => $this->t0 + 1000];
        $this->sync($id, $this->t0 + 1500);
        $this->assertSame('waiting', $this->verify($id, 1)['after']['status']);
        $this->sync($id, $this->t0 + 2701);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'verification_unknown'], [$change['status'], $change['waiting']]);
        $this->assertSame(1, $this->verifier->count('state'), 'no state taken on data older than the batch\'s end: no comparison');
        $this->assertSame(0, $this->verifier->count('compare'));
        $this->assertStringContainsString("Batch 1 cannot be verified: LibreNMS' data of lab-sw4 (a discovery) is not newer than the end of the batch and its settle time", $this->messages($id));
        $this->assertSame([[510]], $this->launched());
        $this->assertContains('Ansible change #1 (CHG-1): batch 1 cannot be verified (LibreNMS\' data of 1 device is not newer than the end of the batch after 30 minutes)',
            array_column($this->events->of(502), 'message'), 'the eventlog counts');

        // checked again every minute: once lab-sw4 is discovered again, the batch is verified and the change goes on by itself
        $this->sync($id, $this->t0 + 2730);
        $this->assertSame(ChangeStatus::PAUSED, $this->changes->find($id)['status']);
        $this->verifier->devices[510]['discovered_at'] = $this->t0 + 2750;
        $this->sync($id, $this->t0 + 2800);
        $this->assertSame('verified', $this->verify($id, 1)['result']);
        $this->assertSame(ChangeStatus::APPLYING, $this->changes->find($id)['status'], 'it goes on by itself');
        $this->fresh($this->t0 + 2810);
        $this->verifier->devices = [];
        $this->sync($id, $this->t0 + 2830);
        $this->assertSame([[510], [502]], $this->launched());
    }

    public function testWithoutTheTopologyPluginTheBatchesAreNotVerified(): void
    {
        $this->verifier->unavailable = 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)';
        $id = $this->approved();
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->assertSame([[510]], $this->launched(), 'the batch goes on: there is nothing to wait for');
        $this->assertStringContainsString('Batch 1 will not be verified: the topology plugin is disabled in LibreNMS', $this->messages($id));
        $this->endBatch($id, 1, $this->t0 + 300);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'verification_unknown'], [$change['status'], $change['waiting']], 'after it, it cannot be verified: the change pauses');
        $this->assertSame(0, count($this->verifier->calls));

        $this->assertNull($this->web($this->t0 + 400)->acceptVerification($id, self::BOB, true, '', $this->t0 + 400, $this->seen($id)));
        $this->assertStringContainsString('bob accepted going on without the verification of batch 1', $this->messages($id));
        $this->assertSame([[510], [502]], $this->launched());
        $this->endBatch($id, 2, $this->t0 + 500);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::APPLIED, 'not_verified'], [$change['status'], $change['verification']], 'the last batch does not pause');
        $this->assertStringContainsString('batch 1 could not be verified; batch 2 could not be verified', $change['verification_note']);
    }

    public function testTheLastBatchDoesNotPauseAndKeepsItsDevicesUntilItsVerificationEnds(): void
    {
        $plan = BatchPlan::make([510], [], 1, 5, 1, false, 0);
        $id = $this->approved([], $plan);
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->sync($id, $this->t0 + 60);
        $this->fresh($this->t0 + 90);
        $this->sync($id, $this->t0 + 120);
        $this->verifier->comparison = self::uplinkLost();
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->assertSame(ChangeStatus::APPLYING, $this->changes->find($id)['status']);
        $this->assertArrayHasKey(510, $this->changes->reserved(), 'its devices stay reserved while its verification waits');

        $this->fresh($this->t0 + 1000);
        $this->sync($id, $this->t0 + 1080);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::APPLIED, 'not_verified'], [$change['status'], $change['verification']]);
        $this->assertStringContainsString('It is the last batch: the change ends, not verified (nothing is left to hold)', $this->messages($id));
        $this->assertSame([], $this->changes->reserved(), 'released at its end');
        $this->assertContains('warning', array_column(array_filter($this->events->of(510), fn ($e) => str_contains($e['message'], 'batch 1 made')), 'severity'));
    }

    public function testTheLastBatchWithoutFreshDataEndsAtTheWait(): void
    {
        $plan = BatchPlan::make([510], [], 1, 5, 1, false, 0);
        $id = $this->approved([], $plan);
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->sync($id, $this->t0 + 60);
        $this->fresh($this->t0 + 90);
        $this->sync($id, $this->t0 + 120);
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->sync($id, $this->t0 + 2600);
        $this->assertSame(ChangeStatus::APPLYING, $this->changes->find($id)['status'], 'reserved until the wait is over');
        $this->sync($id, $this->t0 + 2701);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::APPLIED, 'not_verified'], [$change['status'], $change['verification']]);
    }

    // ------------------------------------------------------------------ the state before a batch

    public function testTheStateBeforeTheFirstBatchWaitsForFreshDataAtMostTheWait(): void
    {
        $id = $this->approved();
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->sync($id, $this->t0 + 60);
        $this->sync($id, $this->t0 + 1000);
        $this->assertSame([], $this->launched());
        $this->sync($id, $this->t0 + 1861);
        $this->assertSame([[510]], $this->launched(), 'past the wait the state is taken anyway');
        $this->assertTrue($this->verify($id, 1)['before']['stale']);
        $this->assertStringContainsString('its data is older than the Apply: LibreNMS did not discover or poll lab-sw4 (a discovery and a poll)', $this->messages($id));
    }

    public function testWithFreshDataWaitTheStateBeforeIsTakenFromTheDataAsItIs(): void
    {
        $this->settings = ['verifyFresh' => 'wait'];
        $id = $this->approved();
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->sync($id, $this->t0 + 60);
        $this->assertSame([[510]], $this->launched());
        $this->assertSame([], $this->refresh->requests, 'LibreNMS is not asked');
        $this->assertStringContainsString("State before batch 1 taken (6 devices, 1 fact, 0 findings), from LibreNMS' data as it is (setting Fresh data: wait).", $this->messages($id));

        // after it, the plugin waits for LibreNMS' own discovery and polling
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->assertSame([], $this->refresh->requests);
        $this->assertStringContainsString('the plugin waits for LibreNMS\' own discovery and polling of lab-sw4, lab-sw2, lab-sw3 for its verification', $this->messages($id));
    }

    public function testAStateThatCannotBeTakenLeavesTheBatchToGoOnNotVerified(): void
    {
        $id = $this->approved();
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->verifier->failures['scope'] = new TopologyError('the topology plugin could not compute the scope (see logs/librenms.log)');
        $this->sync($id, $this->t0 + 60);
        $this->assertSame([[510]], $this->launched());
        $this->assertSame('unknown', $this->verify($id, 1)['before']['status']);
        $this->assertStringContainsString('Batch 1 will not be verified: the state before it could not be taken (the topology plugin could not compute the scope', $this->messages($id));
    }

    // ------------------------------------------------------------------ stop

    public function testAStopWhileTheVerificationWaitsEndsTheChange(): void
    {
        $id = $this->applied();
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->assertNull($this->web($this->t0 + 1000)->stop($id, 'alice', $this->t0 + 1000));
        $this->sync($id, $this->t0 + 1010);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::STOPPED, 'not_verified'], [$change['status'], $change['verification']]);
        $this->assertSame('the change ended before the verification of batch 1', $change['verification_note']);
        $this->assertSame([[510]], $this->launched());
    }

    // ------------------------------------------------------------------ a fix

    public function testAFixIsVerifiedAgainstWhatItWasMadeFor(): void
    {
        $fix = ['kind' => 'port_descriptions', 'ids' => ['f1'], 'variable' => 'port_descriptions', 'title' => 'Port descriptions of lab-sw4', 'lines' => [],
            'findings' => ['d510' => ['code' => 'description_wrong', 'severity' => 'warning', 'title' => 'Port descriptions name the wrong neighbor: lab-sw4', 'devices' => [510]],
                'd502' => ['code' => 'description_missing', 'severity' => 'info', 'title' => 'Inter-switch ports without description: lab-sw2', 'devices' => [502]]],
            'devices' => ['510' => ['d510'], '502' => ['d502']]];
        $id = $this->applied(['fix' => $fix]);
        // after batch 1 lab-sw4's finding is resolved; lab-sw2's (batch 2) is no matter yet
        $this->verifier->comparison = ['findings' => ['new' => [], 'worse' => [], 'changed' => [], 'milder' => [], 'same' => 1, 'resolved' => [['identity' => 'd510',
            'analysis' => 'links', 'code' => 'description_wrong', 'severity' => 'warning', 'title' => 'Port descriptions name the wrong neighbor: lab-sw4', 'devices' => [510]]]]];
        $this->verifier->findings = ['d502' => ['analysis' => 'links', 'code' => 'description_missing', 'severity' => 'info', 'title' => 'Inter-switch ports without description: lab-sw2',
            'devices' => [502], 'subjects' => [502], 'vlans' => [], 'quiet' => false]];
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->fresh($this->t0 + 1000);
        $this->sync($id, $this->t0 + 1080);
        $this->assertSame('verified', $this->verify($id, 1)['result']);
        $this->assertSame(['Resolved (warning): Port descriptions name the wrong neighbor: lab-sw4'], array_column($this->changes->resultOf($id, 1)['evaluation']['expected'], 'text'));

        // batch 2 leaves lab-sw2's finding there: the fix is not done
        $this->verifier->comparison = ['findings' => ['new' => [], 'worse' => [], 'changed' => [], 'milder' => [], 'resolved' => [], 'same' => 1]];
        $this->endBatch($id, 2, $this->t0 + 1200);
        $this->sync($id, $this->t0 + 1900);
        $this->fresh($this->t0 + 1950);
        $this->sync($id, $this->t0 + 2000);
        $this->assertSame('unexpected', $this->verify($id, 2)['result']);
        $this->assertSame(["The fix's finding is still there: Inter-switch ports without description: lab-sw2"], $this->verify($id, 2)['top']);
        $this->assertSame('not_verified', $this->changes->find($id)['verification']);
    }
}
