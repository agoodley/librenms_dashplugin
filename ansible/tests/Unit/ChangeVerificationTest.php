<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\ApprovalRules;
use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
use Agoodley\LibrenmsAnsible\Changes\BlastRadius;
use Agoodley\LibrenmsAnsible\Changes\ChangeLifecycle;
use Agoodley\LibrenmsAnsible\Changes\ChangeSettings;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Changes\RolloutPlan;
use Agoodley\LibrenmsAnsible\Changes\TopologyError;
use Agoodley\LibrenmsAnsible\Changes\VerificationOutcome;
use Agoodley\LibrenmsAnsible\Changes\VerificationText;
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

    /** The topology plugin's planning API: what each device cuts off now (a link down makes lab-sw2 cut off lab-sw5 too). */
    private FakeTopology $topology;

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
        $this->topology = new FakeTopology();
        $this->t0 = strtotime('2026-10-22 10:00:00 UTC');
        $this->backups->now = $this->t0;
        // LibreNMS' data as it was before the change: discovered and polled an hour ago
        $this->verifier->discoveredAt = $this->verifier->polledAt = $this->t0 - 3600;
    }

    private function lifecycle(array $settings = []): ChangeLifecycle
    {
        return new ChangeLifecycle($this->changes, $this->runs, $this->runner, $this->backups, new FakeWindows(), $this->events,
            new ChangeSettings(...$settings + ['approval' => ApprovalRules::SECOND, 'settleSeconds' => 600, 'expectedSeconds' => 3600, 'maintenance' => false,
                'verify' => true, 'verifyWaitSeconds' => 1800]), $this->topology, $this->verifier, $this->refresh);
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
        $this->assertStringContainsString('Before batch 1: the plugin takes the state of the network around it (its devices, what it cuts off, their LLDP/CDP neighbors and '
            . 'spanning tree domains) as the last step before its launch, from data LibreNMS discovers again for it (it waits for that data at most 30 minutes, before the '
            . 'batch\'s blast radius is checked).', $this->messages($id));

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
        $this->assertStringContainsString('State before batch 1 taken right before its launch (6 devices, 1 fact, 0 findings), from fresh data.', $this->messages($id));

        // batch 1 ends: its verification waits for the settle time, then asks for fresh data (with batch 2's devices, for its state before)
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->assertSame(['settling', $this->t0 + 900], [$this->verify($id, 1)['after']['status'], $this->verify($id, 1)['after']['due']]);
        $this->sync($id, $this->t0 + 600);
        $this->assertSame(1, count($this->refresh->requests), 'nothing asked during the settle time');
        $this->sync($id, $this->t0 + 960);
        $this->assertSame([510, 502, 503, 501, 512], $this->refresh->requests[1], 'what the state before batch 1 covered, and batch 2 with its neighbors');
        $this->sync($id, $this->t0 + 1020);
        $this->assertSame([[510]], $this->launched(), 'data older than the batch\'s end and its settle time is never compared');
        $this->fresh($this->t0 + 700);
        $this->sync($id, $this->t0 + 1040);
        $this->assertSame([[510]], $this->launched(), 'nor data newer than its end, within its settle time');
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

    public function testThePluginSendsTheTopologyPluginTheDevicesOfAScopeOnlyNeverItsDomain(): void
    {
        $id = $this->applied();
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->fresh($this->t0 + 1000);
        $this->sync($id, $this->t0 + 1080);
        $this->assertSame('verified', $this->verify($id, 1)['result']);

        // the freshness of the scope before and after batch 1: lab-sw4 and its neighbors, the topology plugin adding their spanning tree domain (of
        // any size: a call takes at most 5000 devices, a domain may have more)
        $asked = array_values(array_map(fn (array $call) => $call[1], array_filter($this->verifier->calls, fn (array $call) => $call[0] === 'freshness')));
        $this->assertSame([[510, 502, 503], [510, 502, 503], [502, 501, 503, 510, 512]], $asked, 'before batch 1, after it, before batch 2: lab-sw5, of the domain only, never');
        $this->assertSame([true, true, true], $this->verifier->domains);
        $this->assertSame(['include' => [510, 502, 503]], $this->verifier->covers[1], 'the state after it: what the state before it covered');
        // what each waits for still names the domain's switches (a poll)
        $this->assertSame([501, 511, 512], $this->changes->scopeOf($id, 1, 'before')['domain']);
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
        // the eventlog of each target quotes the comment as written, as an approval's is
        foreach ([510, 502] as $deviceId) {
            $this->assertContains('Ansible change #1 (CHG-1): bob accepted the 2 unexpected changes of batch 1: "uplink being replaced"',
                array_column($this->events->of($deviceId), 'message'), "device $deviceId");
        }
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
        $this->assertSame([ChangeStatus::PAUSED, 'verify_unknown'], [$change['status'], $change['waiting']]);
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

    public function testAComparisonThatCouldNotReadEverythingIsNoVerification(): void
    {
        $id = $this->applied();
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->fresh($this->t0 + 1000);
        // the topology plugin could not read the links in one of the states, and its redundancy check failed: nothing else changed
        $this->verifier->comparison = ['skipped' => ['facts' => ['link'], 'checks' => ['redundancy'], 'errors' => [
            'facts' => ['link' => ['after' => "database error 42S02/1146: Table 'librenms.links' doesn't exist"]],
            'checks' => ['redundancy' => ['before' => 'TypeError: an argument is null', 'after' => 'TypeError: an argument is null']]]]];
        $this->sync($id, $this->t0 + 1080);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'verify_unknown'], [$change['status'], $change['waiting']], 'not verified: what was not compared may have changed');
        $this->assertSame(['unknown', 'incomplete'], [$this->verify($id, 1)['result'], $this->verify($id, 1)['after']['status']]);
        $why = "the topology plugin could not read the links in the state after it (database error 42S02/1146: Table 'librenms.links' doesn't exist); the topology "
            . "plugin's redundancy check failed in the state before it (TypeError: an argument is null) and in the state after it (TypeError: an argument is null); "
            . 'nothing else that was compared changed. To go on: fix it (the details are in logs/librenms.log), switch the redundancy check off in the topology '
            . "plugin's settings (Background checks) or set When a batch cannot be verified to only record it";
        $this->assertStringContainsString("Batch 1 cannot be verified: $why. The change waits for", $this->messages($id),
            'the timeline names what failed, its error, and the ways out');
        $this->assertSame($why, $this->verify($id, 1)['error'], 'so does the change page');
        $events = implode("\n", array_column($this->events->of(510), 'message'));
        $this->assertStringContainsString('the topology plugin could not read the links; its redundancy check failed in the state before or after it', $events);
        $this->assertStringNotContainsString('TypeError', $events, 'the eventlog without the errors');
        $this->assertSame([[510]], $this->launched());

        // a new state after it every 5 minutes: once the topology plugin reads everything, the batch is verified and the change goes on
        $this->sync($id, $this->t0 + 1200);
        $this->assertSame(2, $this->verifier->count('state'), 'not before 5 minutes');
        $this->verifier->comparison = [];
        $this->sync($id, $this->t0 + 1400);
        $this->assertSame([$this->t0 + 120, $this->t0 + 1080, $this->t0 + 1400, $this->t0 + 1400], $this->verifier->stateTimes(),
            'before batch 1, after it (incomplete), after it again, before batch 2');
        $this->assertSame('verified', $this->verify($id, 1)['result']);
        $this->assertSame(ChangeStatus::APPLYING, $this->changes->find($id)['status']);
    }

    // ------------------------------------------------------------------ never verified on less than its scope

    public function testADeviceOfItsScopeLibreNmsNoLongerHasMakesTheBatchOneThatCannotBeVerified(): void
    {
        $id = $this->applied();
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        // lab-sw3, a neighbor of lab-sw4 the state before covered, disabled in LibreNMS after the batch
        $this->verifier->gone = [503];
        $this->fresh($this->t0 + 1000);
        $this->sync($id, $this->t0 + 1080);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'verify_unknown', 'unknown', 'missing'], [$change['status'], $change['waiting'], $this->verify($id, 1)['result'],
            $this->verify($id, 1)['after']['status']], 'never "verified" on less than its scope');
        $this->assertSame(1, $this->verifier->count('state'), 'no state after it is taken without the device');
        $this->assertStringContainsString('Batch 1 cannot be verified: LibreNMS no longer has lab-sw3 of its scope as alice, who started the batch, sees the network (disabled or '
            . 'deleted, or no longer visible to them)', $this->messages($id));
        $events = implode("\n", array_column($this->events->of(510), 'message'));
        $this->assertStringContainsString('LibreNMS no longer has 1 device of its scope as the admin who started the batch sees the network', $events);
        $this->assertStringNotContainsString('lab-sw3', $events, 'the eventlog names no other device');

        // enabled again: checked again a minute later, verified, the change goes on
        $this->verifier->gone = [];
        $this->sync($id, $this->t0 + 1150);
        $this->assertSame('verified', $this->verify($id, 1)['result']);
        $this->assertSame(ChangeStatus::APPLYING, $this->changes->find($id)['status']);
    }

    public function testTheStateAfterABatchCoversAtLeastWhatTheStateBeforeItCovered(): void
    {
        $id = $this->applied();
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        // after the batch lab-sw4 no longer reports lab-sw3 as its neighbor: the state after it covers lab-sw3 still, as the state before it did
        $this->verifier->neighbors[510] = [502];
        $this->fresh($this->t0 + 1000);
        $this->sync($id, $this->t0 + 1080);
        $this->assertSame('verified', $this->verify($id, 1)['result']);
        $this->assertSame([503], $this->changes->stateOf($id, 1, 'after')['scope']['included'], 'lab-sw3, which the state before it covered as a neighbor');
        $this->assertSame(['include' => [510, 502, 503]], $this->verifier->covers[1], 'the state after it, taken with the cover of the state before it');
    }

    public function testAStateAfterThatNoLongerCoversADeviceCannotBeVerifiedWhateverItFound(): void
    {
        $id = $this->applied();
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->fresh($this->t0 + 1000);
        // the topology plugin's state after it lacks lab-sw3 (no longer visible to the admin when the state was taken), and what both cover changed
        $this->verifier->comparison = ['skipped' => ['facts' => [], 'checks' => [], 'errors' => [], 'devices' => [503]]] + self::uplinkLost();
        $this->sync($id, $this->t0 + 1080);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'verify_unknown', 'unknown'], [$change['status'], $change['waiting'], $this->verify($id, 1)['result']],
            'cannot be verified, not "unexpected": the comparison is not over its whole scope');
        $this->assertMatchesRegularExpression('/Batch 1 cannot be verified: the state after it no longer covers lab-sw3: LibreNMS no longer has it as the admin who started the batch '
            . 'sees the network \(disabled or deleted, or no longer visible to that admin\); \d+ unexpected changes? in what was compared \(listed with the batch\)\. To go on: enable '
            . 'the device in LibreNMS again \(or let that admin see it\) or set When a batch cannot be verified to only record it\./', $this->messages($id));
        $this->assertSame([[510]], $this->launched());

        // a state after it that covers it all again (checked every 5 minutes): what changed is then what the batch made, and the change, which
        // waits for an admin to accept it, never goes on meanwhile
        $this->verifier->comparison = self::uplinkLost();
        $statuses = [];
        $record = function () use (&$record, &$statuses, $id) {
            $statuses[] = $this->changes->find($id)['status'];
            $this->changes->afterUpdate = $record;
        };
        $this->changes->afterUpdate = $record;
        $this->sync($id, $this->t0 + 1400);
        $this->changes->afterUpdate = null;
        $this->assertSame([ChangeStatus::PAUSED], $statuses, 'paused again at once, for an acceptance');
        $this->assertSame([ChangeStatus::PAUSED, 'verification', 'unexpected'], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting'],
            $this->verify($id, 1)['result']]);
    }

    public function testNoStateIsTakenAsAnAdminWhoMayNoLongerStartTheBatch(): void
    {
        $id = $this->applied();
        $this->endBatch($id, 1, $this->t0 + 300);
        // alice, who started batch 1, demoted after it: the topology plugin would show her nothing, and nothing would read as unchanged
        $this->runner->userProblems[self::ALICE['user_id']] = 'is no longer a LibreNMS admin';
        $asked = [$this->verifier->count('state'), $this->verifier->count('freshness'), $this->verifier->count('scope'), count($this->refresh->requests)];
        $this->sync($id, $this->t0 + 960);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'verify_unknown', 'unknown'], [$change['status'], $change['waiting'], $this->verify($id, 1)['result']]);
        $this->assertStringContainsString('Batch 1 cannot be verified: the state after it could not be taken (it is taken as alice, who started the batch, and alice is no longer '
            . 'a LibreNMS admin)', $this->messages($id));
        $this->assertSame($asked, [$this->verifier->count('state'), $this->verifier->count('freshness'), $this->verifier->count('scope'), count($this->refresh->requests)],
            'nothing asked as her after the batch: no freshness, scope, state or refresh');
        // checked again every minute: once she may start it again, the batch is verified as any
        unset($this->runner->userProblems[self::ALICE['user_id']]);
        $this->sync($id, $this->t0 + 1030);
        $this->fresh($this->t0 + 1040);
        $this->sync($id, $this->t0 + 1100);
        $this->assertSame('verified', $this->verify($id, 1)['result']);
    }

    public function testNoStateBeforeIsTakenAsAnAdminWhoMayNoLongerStartTheBatch(): void
    {
        $id = $this->approved();
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->runner->userProblems[self::ALICE['user_id']] = 'no longer holds the run permission (ansible.run)';
        $this->sync($id, $this->t0 + 60);
        $this->assertSame([0, 0], [$this->verifier->count('scope'), $this->verifier->count('state')], 'nothing taken as her');
        $this->assertSame('unknown', $this->verify($id, 1)['before']['status']);
        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::FAILED, $change['status'], 'nor is the batch launched by her');
        $this->assertStringContainsString('alice, who launches it, no longer holds the run permission', (string) $change['error']);
    }

    public function testTheStateBeforeMustCoverTheBatchsDevices(): void
    {
        $id = $this->approved();
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        // lab-sw4, the batch's device, is not one the admin who starts the batch sees in LibreNMS
        $this->verifier->gone = [510];
        $this->sync($id, $this->t0 + 60);
        $this->fresh($this->t0 + 90);
        $this->sync($id, $this->t0 + 120);
        $this->assertSame([], $this->launched(), 'tried again until the wait is over (it may be enabled again)');
        $this->assertStringContainsString('Batch 1: the state before it could not be taken (it does not cover lab-sw4 of the batch: alice, who starts the batch, does not see it '
            . 'in LibreNMS (disabled, or not visible to them)); the plugin tries again every minute until 2026-10-22 10:32 UTC, then the batch goes on without it.',
            $this->messages($id));
        $this->sync($id, $this->t0 + 1000);
        $this->assertSame([], $this->launched());
        $this->sync($id, $this->t0 + 1921);
        $this->assertSame([[510]], $this->launched(), 'the wait over: the batch goes on without it');
        $this->assertSame('unknown', $this->verify($id, 1)['before']['status']);
        $this->assertStringContainsString('Batch 1 will not be verified: the state before it could not be taken (it does not cover lab-sw4 of the batch: alice, who starts the '
            . 'batch, does not see it in LibreNMS (disabled, or not visible to them), tried since 2026-10-22 10:02 UTC)', $this->messages($id));
    }

    // ------------------------------------------------------------------ who starts a batch

    public function testABatchAnotherAdminContinuedToIsStartedAndVerifiedAsThem(): void
    {
        // a wait for an admin after the first batch: bob (an approver who holds the run permission too) continues the change
        $id = $this->approved([], BatchPlan::make(array_keys(self::TARGETS), [], 1, 5, 1, true, 0));
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->sync($id, $this->t0 + 60);
        $this->fresh($this->t0 + 90);
        $this->sync($id, $this->t0 + 120);
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->fresh($this->t0 + 1000);
        $this->sync($id, $this->t0 + 1080);
        $this->assertSame([ChangeStatus::PAUSED, 'canary'], [$this->changes->find($id)['status'], $this->changes->find($id)['waiting']]);

        $this->assertNull($this->web($this->t0 + 1100)->resume($id, self::BOB, [], $this->t0 + 1100));
        $this->assertSame([[510]], $this->launched(), 'the Continue (a page) takes no state: batch 2 waits for the state before it');
        $batch = $this->changes->batches[$id][2];
        $this->assertSame([2, 'bob'], [(int) $batch['user_id'], $batch['username']], 'who continued it is recorded on the batch at once');

        // alice, who applied the change, loses the run permission meanwhile: batch 2 is bob's, the sync command starts it as him
        $this->runner->userProblems[self::ALICE['user_id']] = 'no longer holds the run permission (ansible.run)';
        $this->fresh($this->t0 + 1130);
        $this->sync($id, $this->t0 + 1160);
        $this->assertSame([[510], [502]], $this->launched());
        $this->assertSame('bob', end($this->runner->launches)['user'], 'launched by bob');
        $this->assertStringContainsString('Batch 2 of 2 launched by bob', $this->messages($id));
        $states = array_values(array_filter($this->verifier->calls, fn (array $call) => $call[0] === 'state'));
        $this->assertSame(2, end($states)[2], 'its state before it taken as bob sees the network');
        $this->assertSame('taken', $this->verify($id, 2)['before']['status']);
    }

    // ------------------------------------------------------------------ decided at Apply

    /** @return array<string, array{string}> why the topology plugin's verification API is not there at the Apply */
    public static function unavailableAtApply(): array
    {
        return [
            'not installed' => ['the topology plugin is not installed'],
            'too old' => ['the topology plugin is too old for the verification API this plugin needs: update both plugins together (this plugin\'s README, Upgrade from phase 3)'],
            'disabled' => ['the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unavailableAtApply')]
    public function testWithoutTheVerificationApiAtTheApplyTheChangeRunsWithoutAVerification(string $why): void
    {
        $this->verifier->unavailable = $why;
        $id = $this->approved();
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->assertSame([[510]], $this->launched(), 'the Apply launches batch 1 itself, as in phase 3: no state to take first');
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::APPLYING, 'not_verified', $why], [$change['status'], $change['verification'], $change['verification_note']],
            'its outcome from its Apply on');
        $this->assertStringContainsString("The change is not verified: $why. Its batches run without a verification: none waits or pauses for one.", $this->messages($id));

        // the topology plugin back meanwhile changes nothing: decided at the Apply
        $this->verifier->unavailable = null;
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->assertSame([[510], [502]], $this->launched(), 'batch 2 starts right after batch 1: nothing waits or pauses for a verification');
        $this->sync($id, $this->t0 + 400);
        $this->endBatch($id, 2, $this->t0 + 500);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::APPLIED, 'not_verified', $why], [$change['status'], $change['verification'], $change['verification_note']]);
        $this->assertSame([], $this->verifier->calls, 'nothing asked of the topology plugin: no freshness, scope or state');
        $this->assertSame([], $this->refresh->requests, 'nor of LibreNMS');
        $this->assertSame([[], []], [$this->verify($id, 1), $this->verify($id, 2)]);
        $this->assertStringContainsString("Verification: not verified ($why)", $this->messages($id));
        $this->assertContains('warning', array_column(array_filter($this->events->of(510), fn ($e) => str_contains($e['message'], "not verified ($why)")), 'severity'),
            'the eventlog of each target says it');
    }

    public function testAnApiThatFailsAfterTheApplyLeavesBatchesThatCannotBeVerified(): void
    {
        $id = $this->applied();
        $this->assertSame([null, null], [$this->changes->find($id)['verification'], $this->changes->find($id)['verification_note']], 'verified: no outcome before its end');
        // the topology plugin disabled while batch 1 runs
        $this->verifier->unavailable = 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)';
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'verify_unknown'], [$change['status'], $change['waiting']], 'batch 1 cannot be verified: the setting pauses the change');
        $this->assertStringContainsString('Batch 1 cannot be verified: the topology plugin is disabled in LibreNMS', $this->messages($id));
        $this->assertSame([[510]], $this->launched());

        $this->assertNull($this->web($this->t0 + 1000)->acceptVerification($id, self::BOB, true, "topology plugin back\ntomorrow", $this->t0 + 1000, $this->seen($id)));
        $this->assertStringContainsString('bob accepted going on without the verification of batch 1: "topology plugin back tomorrow"', $this->messages($id));
        $this->assertContains('Ansible change #1 (CHG-1): bob accepted going on without the verification of batch 1: "topology plugin back tomorrow"',
            array_column($this->events->of(510), 'message'), 'its eventlog entry quotes the comment, on one line');
        $this->assertStringContainsString('The state before batch 2 cannot be taken: the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin). The batch runs, '
            . 'and cannot be verified after it.', $this->messages($id));
        $this->assertSame([[510], [502]], $this->launched());
        $this->endBatch($id, 2, $this->t0 + 1100);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::APPLIED, 'not_verified'], [$change['status'], $change['verification']], 'the last batch does not pause');
        $this->assertSame('batch 1 could not be verified; batch 2 could not be verified', $change['verification_note']);
    }

    public function testRecordOnlyGoesOnWhenTheApiFailsAfterTheApply(): void
    {
        $this->settings = ['verifyUnknown' => 'record'];
        $id = $this->applied();
        $this->verifier->unavailable = 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)';
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->assertSame([[510], [502]], $this->launched(), 'recorded: the change goes on');
        $this->assertStringContainsString('Recorded; the change goes on (setting When a batch cannot be verified: only record)', $this->messages($id));
    }

    public function testWithVerifyChangesOffTheChangeRunsWithoutAVerification(): void
    {
        $this->settings = ['verify' => false];
        $id = $this->approved();
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->assertSame([[510]], $this->launched(), 'the Apply launches batch 1 itself, as in phase 3');
        $change = $this->changes->find($id);
        $this->assertSame(['not_verified', VerificationOutcome::SWITCHED_OFF], [$change['verification'], $change['verification_note']]);

        // switched on meanwhile: decided at the Apply
        $this->settings = [];
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->assertSame([[510], [502]], $this->launched());
        $this->endBatch($id, 2, $this->t0 + 400);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::APPLIED, 'not_verified', 'switched off'], [$change['status'], $change['verification'], $change['verification_note']],
            'not verified (switched off)');
        $this->assertSame([], $this->verifier->calls);
        $this->assertStringNotContainsString('not verified', $this->messages($id), 'as in phase 3: no word of it in the timeline');
        $this->assertSame([], array_filter($this->events->of(510), fn ($e) => str_contains($e['message'], 'not verified')), 'nor in the eventlog');
        $this->assertSame('not verified (switched off)', VerificationText::listed($change['verification'], $change['verification_note']), 'the Changes list');
    }

    public function testVerifyChangesSwitchedOffWhileAChangeIsVerifiedGoesOnVerifyingIt(): void
    {
        $id = $this->applied();
        $this->settings = ['verify' => false];
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->assertSame([[510]], $this->launched(), 'batch 1 is verified first');
        $this->fresh($this->t0 + 1000);
        $this->sync($id, $this->t0 + 1080);
        $this->assertSame('verified', $this->verify($id, 1)['result']);
        $this->assertSame([[510], [502]], $this->launched(), 'batch 2 started from the state before it, taken with batch 1\'s verification');
        $this->assertSame('taken', $this->verify($id, 2)['before']['status']);
    }

    public function testAChangeAppliedBeforePhase4GoesOnUnverified(): void
    {
        $this->settings = ['verify' => false];
        $id = $this->approved();
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        // as phase 3 applied it: no outcome (the columns phase 4 adds are empty), its first batch without a verification
        $this->changes->changes[$id] = array_merge($this->changes->changes[$id], ['verification' => null, 'verification_note' => null]);
        $this->settings = [];
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->assertSame([[510], [502]], $this->launched(), 'its next batch starts at once');
        $this->endBatch($id, 2, $this->t0 + 400);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::APPLIED, null], [$change['status'], $change['verification']]);
        $this->assertSame([], $this->verifier->calls);
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

        // verified on it: the batch's result and the change's outcome say so, not only the timeline of the state
        $this->endBatch($id, 1, $this->t0 + 2000);
        $this->sync($id, $this->t0 + 2700);
        $this->fresh($this->t0 + 2750);
        $this->sync($id, $this->t0 + 2800);
        $this->assertSame('verified', $this->verify($id, 1)['result']);
        $this->assertSame('the Apply', VerificationText::older($this->verify($id, 1)));
        $this->assertStringContainsString('Batch 1 verified, on data older than the Apply before it: nothing unexpected changed (1 fact and 0 findings compared on fresh data after it)',
            $this->messages($id));
        $this->assertNull(VerificationText::older($this->verify($id, 2)), 'batch 2\'s state before it was fresh');
        $this->endBatch($id, 2, $this->t0 + 3000);
        $this->sync($id, $this->t0 + 3700);
        $this->fresh($this->t0 + 3750);
        $this->sync($id, $this->t0 + 3800);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::APPLIED, 'verified', 'batch 1 on data older than the Apply before it'], [$change['status'], $change['verification'],
            $change['verification_note']]);
        $this->assertStringContainsString('Verification: verified (batch 1 on data older than the Apply before it)', $this->messages($id));
    }

    public function testDevicesNoDiscoveryOfWhichStartedAreAskedForAgainTwiceAtMost(): void
    {
        $id = $this->approved();
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->sync($id, $this->t0 + 60);
        $this->assertSame([[510, 502, 503]], $this->refresh->requests);
        // every device discovered and polled since, but lab-sw4: a discovery of it was running when LibreNMS was asked, and took the request up
        $this->fresh($this->t0 + 90);
        $this->verifier->devices[510] = ['discovered_at' => $this->t0 + 30];
        $this->sync($id, $this->t0 + 120);
        $this->assertCount(1, $this->refresh->requests, 'not before ' . ChangeLifecycle::REASK_AFTER . ' seconds');
        $this->sync($id, $this->t0 + 250);
        $this->assertSame([510], $this->refresh->requests[1], 'asked again: lab-sw4 alone');
        $this->assertStringContainsString('Batch 1: no discovery of lab-sw4 started since LibreNMS was asked at 2026-10-22 10:01 UTC: it was asked again for the state before the batch.',
            $this->messages($id));
        $this->sync($id, $this->t0 + 300);
        $this->sync($id, $this->t0 + 440);
        $this->assertCount(3, $this->refresh->requests, 'once more, ' . ChangeLifecycle::REASK_AFTER . ' seconds after');
        $this->sync($id, $this->t0 + 700);
        $this->sync($id, $this->t0 + 1000);
        $this->assertCount(3, $this->refresh->requests, 'twice at most: then the wait runs');
        $this->assertSame([], $this->launched());
        // its discovery after the request: the state is taken, from fresh data (newer than the first request)
        $this->verifier->devices[510] = ['discovered_at' => $this->t0 + 1010];
        $this->sync($id, $this->t0 + 1020);
        $this->assertSame([[510]], $this->launched());
        $this->assertFalse($this->verify($id, 1)['before']['stale']);

        // after a batch too, and never with Fresh data: wait (nothing is asked then)
        $this->endBatch($id, 1, $this->t0 + 1100);
        $this->sync($id, $this->t0 + 1800);
        $asked = count($this->refresh->requests);
        $this->fresh($this->t0 + 1850);
        $this->verifier->devices = [503 => ['discovered_at' => $this->t0 + 1650]];
        $this->sync($id, $this->t0 + 2000);
        $this->assertSame([503], $this->refresh->requests[$asked], 'lab-sw3, no discovery of which started since the request for the verification');
        $this->assertStringContainsString('Batch 1: no discovery of lab-sw3 started since LibreNMS was asked at 2026-10-22 10:30 UTC: it was asked again for its verification.',
            $this->messages($id));
    }

    public function testWithFreshDataWaitTheStateBeforeIsTakenFromTheDataAsItIs(): void
    {
        $this->settings = ['verifyFresh' => 'wait'];
        $id = $this->approved();
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->sync($id, $this->t0 + 60);
        $this->assertSame([[510]], $this->launched());
        $this->assertSame([], $this->refresh->requests, 'LibreNMS is not asked');
        $this->assertStringContainsString("State before batch 1 taken right before its launch (6 devices, 1 fact, 0 findings), from LibreNMS' data as it is (setting Fresh data: "
            . 'wait).', $this->messages($id));

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
        $this->assertSame([], $this->launched(), 'tried again every minute until the wait is over');
        $this->assertSame('the topology plugin could not compute the scope (see logs/librenms.log)', $this->verify($id, 1)['before']['failed']);
        $this->sync($id, $this->t0 + 1859);
        $this->assertSame([], $this->launched());
        $this->sync($id, $this->t0 + 1861);
        $this->assertSame([[510]], $this->launched());
        $this->assertSame('unknown', $this->verify($id, 1)['before']['status']);
        $this->assertStringContainsString('Batch 1 will not be verified: the state before it could not be taken (the topology plugin could not compute the scope', $this->messages($id));
    }

    public function testAStateBeforeThatFailedOnceIsTakenWhenItWorksAgain(): void
    {
        $id = $this->approved();
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->sync($id, $this->t0 + 60);
        $this->fresh($this->t0 + 90);
        // the state's process killed right before the launch
        $this->verifier->failures['state'] = new TopologyError('the topology plugin could not take the state of the batch: its process ended without an answer (code 9, see logs/librenms.log)');
        $this->sync($id, $this->t0 + 120);
        $this->assertSame([], $this->launched(), 'not launched without its state: tried again first');
        $this->assertSame('preparing', $this->changes->batches[$id][1]['status'], 'prepared, its window open');
        $this->assertStringContainsString('Batch 1: the state before it could not be taken (the topology plugin could not take the state of the batch: its process ended without an '
            . 'answer (code 9, see logs/librenms.log)); the plugin tries again every minute until 2026-10-22 10:32 UTC, then the batch goes on without it.', $this->messages($id));
        unset($this->verifier->failures['state']);
        $this->sync($id, $this->t0 + 180);
        $this->assertSame([[510]], $this->launched());
        $before = $this->verify($id, 1)['before'];
        $this->assertSame(['taken', null, null], [$before['status'], $before['failed'], $before['failed_since']], 'taken, the failure over');
    }

    public function testAStateAfterThatFailedIsTriedAgainUntilTheWaitForEveryBatchAndSetting(): void
    {
        // the last batch (nothing left to hold), and the stop setting: one failure is no verdict
        $this->settings = ['verifyUnexpected' => 'stop', 'verifyUnknown' => 'stop'];
        $id = $this->approved([], BatchPlan::make([510], [], 1, 5, 1, false, 0));
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->sync($id, $this->t0 + 60);
        $this->fresh($this->t0 + 90);
        $this->sync($id, $this->t0 + 120);
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->fresh($this->t0 + 1000);
        $this->verifier->failures['state'] = new TopologyError('the topology plugin could not take the state of the batch: its process ended without an answer (code 9, see logs/librenms.log)');
        $this->sync($id, $this->t0 + 1080);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::APPLYING, null], [$change['status'], $this->verify($id, 1)['result'] ?? null], 'not ended: tried again');
        $this->assertStringContainsString('Batch 1: the state after it could not be taken (the topology plugin could not take the state of the batch: its process ended without an '
            . 'answer (code 9, see logs/librenms.log)); the plugin tries again every minute until 2026-10-22 10:45 UTC.', $this->messages($id));
        $this->assertArrayHasKey(510, $this->changes->reserved(), 'its devices still reserved');
        unset($this->verifier->failures['state']);
        $this->sync($id, $this->t0 + 1140);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::APPLIED, 'verified'], [$change['status'], $change['verification']]);
        $this->assertNull($this->verify($id, 1)['after']['failed']);

        // a failure that lasts past the wait: the batch cannot be verified, then the settings decide (stop; the last batch: the change ends)
        $this->setUp();
        $this->settings = ['verifyUnknown' => 'stop'];
        $id = $this->approved();
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->sync($id, $this->t0 + 60);
        $this->fresh($this->t0 + 90);
        $this->sync($id, $this->t0 + 120);
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->fresh($this->t0 + 1000);
        $this->verifier->failures['state'] = new TopologyError('the topology plugin could not take the state of the batch (see logs/librenms.log)');
        foreach ([1080, 1500, 2600] as $offset) {
            $this->sync($id, $this->t0 + $offset);
            $this->assertSame(ChangeStatus::APPLYING, $this->changes->find($id)['status'], "still tried at +$offset");
        }
        $this->sync($id, $this->t0 + 2701);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::FAILED, 'unknown'], [$change['status'], $this->verify($id, 1)['result']]);
        $this->assertStringContainsString('batch 1 cannot be verified: the state after it could not be taken (the topology plugin could not take the state of the batch', $change['error']);
        $this->assertSame([[510]], $this->launched());
    }

    // ------------------------------------------------------------------ the state before a batch: the last step before its launch

    /** A change of lab-sw4 then lab-sw2, planned by the topology plugin (lab-sw2 cuts off lab-sw6), dry-run by alice and approved by bob. */
    private function planned(array $fields = []): int
    {
        $plan = RolloutPlan::make([510, 502], [], 1, 5, 1, false, 0, RolloutPlanTest::planner([510, 502], 5), RolloutPlan::LEAVE,
            fn (array $batch) => RolloutPlanTest::blast($batch))['plan'];
        $this->assertSame([[510], [502]], $plan->batches);

        return $this->approved($fields, $plan);
    }

    /**
     * The planned change applied, batch 1 verified at t0 + 1080; lab-sw5's link to lab-sw3 went down after it, so lab-sw2 (batch
     * 2) cuts off lab-sw5 too: batch 2 waits for an approver to accept its new blast radius.
     */
    private function grown(): int
    {
        $id = $this->planned();
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, [], $this->t0 + 50));
        $this->sync($id, $this->t0 + 60);
        $this->fresh($this->t0 + 90);
        $this->sync($id, $this->t0 + 120);
        $this->assertSame([[510]], $this->launched());
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->topology->cuts[502] = [512, 511];
        $this->fresh($this->t0 + 1000);
        $this->sync($id, $this->t0 + 1080);
        $this->assertSame('verified', $this->verify($id, 1)['result']);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'blast'], [$change['status'], $change['waiting']]);

        return $id;
    }

    public function testTheStateBeforeABatchIsTakenRightBeforeItsLaunchAfterAnAcceptance(): void
    {
        $id = $this->grown();
        $this->assertSame(2, $this->verifier->count('state'), 'no state before batch 2 yet: it is the last step before the launch, after the blast check');
        $this->assertSame('retake', $this->verify($id, 2)['before']['status'], 'its data was ready; the batch waits: its state is taken when it starts again');

        // the link comes back and LibreNMS discovers it; bob accepts the new blast radius
        $this->topology->cuts = RolloutPlanTest::CUTS;
        $this->fresh($this->t0 + 1150);
        $blast = $this->changes->batches[$id][2]['blast'];
        $this->assertNull($this->web($this->t0 + 1200)->acceptBlast($id, self::BOB, true, 'ok', $this->t0 + 1200, BlastRadius::checkId($blast, $id, 2)));
        $this->assertSame([[510]], $this->launched(), 'the acceptance (a page) takes no state: the sync command starts the batch');
        $requests = count($this->refresh->requests);
        $this->sync($id, $this->t0 + 1260);
        $this->assertSame([[510], [502]], $this->launched());
        $this->assertSame([$this->t0 + 120, $this->t0 + 1080, $this->t0 + 1260], $this->verifier->stateTimes(),
            'the state before batch 2 taken right before its launch, after the acceptance: the network as it is then');
        $this->assertSame($requests, count($this->refresh->requests), 'LibreNMS discovered its scope within the wait: not asked again');
        $this->assertStringContainsString('State before batch 2 taken right before its launch (6 devices, 1 fact, 0 findings), from data LibreNMS discovered and polled within '
            . 'the last 30 minutes.', $this->messages($id));
        $this->assertSame(['taken', $this->t0 + 1260], [$this->verify($id, 2)['before']['status'], $this->verify($id, 2)['before']['taken_at']]);
    }

    public function testALaunchHeldLongerThanTheWaitAsksForFreshDataAgainFirst(): void
    {
        $id = $this->grown();
        // the link comes back; the acceptance comes two hours later, and LibreNMS did not discover the scope meanwhile
        $this->topology->cuts = RolloutPlanTest::CUTS;
        $blast = $this->changes->batches[$id][2]['blast'];
        $this->assertNull($this->web($this->t0 + 8000)->acceptBlast($id, self::BOB, true, 'ok', $this->t0 + 8000, BlastRadius::checkId($blast, $id, 2)));
        $requests = count($this->refresh->requests);
        $this->sync($id, $this->t0 + 8060);
        $this->assertSame([[510]], $this->launched(), 'its data is older than the wait: LibreNMS is asked to discover its scope again first');
        $this->assertSame([$requests + 1, [502, 501, 503, 510, 512]], [count($this->refresh->requests), end($this->refresh->requests)]);
        $this->assertStringContainsString('again for the state before the batch, taken again (a discovery job each); the plugin waits for fresh data until', $this->messages($id));
        $this->sync($id, $this->t0 + 8120);
        $this->assertSame([[510]], $this->launched(), 'within the wait, only on data newer than the request');
        $this->fresh($this->t0 + 8150);
        $this->sync($id, $this->t0 + 8180);
        $this->assertSame([[510], [502]], $this->launched());
        $this->assertSame($this->t0 + 8180, $this->verify($id, 2)['before']['taken_at']);
        $this->assertStringContainsString('State before batch 2 taken right before its launch (6 devices, 1 fact, 0 findings), from fresh data.', $this->messages($id));
    }

    public function testALaunchHeldLongerThanTheWaitWithoutFreshDataIsTakenAtItsEndSaidOlder(): void
    {
        $id = $this->grown();
        $this->topology->cuts = RolloutPlanTest::CUTS;
        $blast = $this->changes->batches[$id][2]['blast'];
        $this->assertNull($this->web($this->t0 + 8000)->acceptBlast($id, self::BOB, true, 'ok', $this->t0 + 8000, BlastRadius::checkId($blast, $id, 2)));
        $this->sync($id, $this->t0 + 8060);
        $this->sync($id, $this->t0 + 9861);
        $this->assertSame([[510], [502]], $this->launched(), 'past the wait, taken anyway');
        $this->assertTrue($this->verify($id, 2)['before']['stale']);
        $this->assertMatchesRegularExpression('/State before batch 2 taken right before its launch \(.*\), its data is older than the request for it at 2026-10-22 12:1\d UTC: '
            . 'LibreNMS did not discover or poll lab-sw2 \(a discovery and a poll\).* again within 30 minutes\./', $this->messages($id));
    }

    public function testABatchThatWaitedForABusyDeviceTakesItsStateFromDataWithinTheWait(): void
    {
        $id = $this->applied();
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->fresh($this->t0 + 1000);
        // lab-sw2 (batch 2) is busy in another run when batch 2 comes to start: it waits for it
        $this->runner->busy = [502 => 'busy in run #77, which has not finished'];
        $this->sync($id, $this->t0 + 1080);
        $this->assertSame('verified', $this->verify($id, 1)['result']);
        $this->assertSame([[510]], $this->launched());
        $this->assertStringContainsString('Batch 2 waits: lab-sw2 is busy in run #77, which has not finished.', $this->messages($id));
        $this->assertSame('retake', $this->verify($id, 2)['before']['status'], 'its data (asked for with batch 1\'s verification) is checked again when it goes on');

        // free 40 minutes later: LibreNMS' data is older than the wait, it is asked for again first
        $this->runner->busy = [];
        $requests = count($this->refresh->requests);
        $this->sync($id, $this->t0 + 3480);
        $this->assertSame([[510]], $this->launched());
        $this->assertSame($requests + 1, count($this->refresh->requests));
        $this->fresh($this->t0 + 3500);
        $this->sync($id, $this->t0 + 3540);
        $this->assertSame([[510], [502]], $this->launched());
        $this->assertSame($this->t0 + 3540, $this->verify($id, 2)['before']['taken_at'], 'taken right before its launch');
    }

    public function testALaunchWithSecretAnswersTakesTheStateAgainWhenTheyCameLate(): void
    {
        $secret = ['name' => 'key', 'label' => 'Key', 'type' => 'secret', 'value' => '********', 'secret' => true];
        $id = $this->approved(['has_secrets' => true, 'variables' => [$secret]], BatchPlan::make([510], [], 1, 5, 1, false, 0));
        $this->assertNull($this->web($this->t0 + 50)->apply($id, self::ALICE, ['key' => 's3cret'], $this->t0 + 50));
        $this->sync($id, $this->t0 + 60);
        $this->fresh($this->t0 + 90);
        $this->sync($id, $this->t0 + 120);
        // the sync command took the state right before the launch; the launch waits for the secret answers (the page that holds them launches it)
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'secrets', 'prepared'], [$change['status'], $change['waiting'], $this->changes->batches[$id][1]['status']]);
        $this->assertSame([$this->t0 + 120], $this->verifier->stateTimes());
        $this->assertSame([], $this->launched());
        $this->assertFalse(ChangeLifecycle::beforeRetaking($change, $this->changes->batches[$id][1]));

        // they come later than the state counts: it is taken again first, and no launch is offered meanwhile
        $late = $this->t0 + 120 + ChangeLifecycle::STATE_VALID + 1;
        $this->assertNull($this->web($late)->resume($id, self::ALICE, ['key' => 's3cret'], $late));
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'secrets'], [$change['status'], $change['waiting']]);
        $this->assertSame([], $this->launched(), 'not launched on a state older than it counts');
        $this->assertTrue(ChangeLifecycle::beforeRetaking($change, $this->changes->batches[$id][1]));
        $this->assertStringContainsString('Batch 1: the secret answers came more than 2 minutes after the state before it (taken at 2026-10-22 10:02 UTC): it is taken again '
            . 'first, then the change page that holds the answers launches the batch with them.', $this->messages($id));
        $this->assertGreaterThanOrEqual($late + 1800, Clock::unix($change['resume_at']), 'its wait for them starts again, with the wait for fresh data');
        $this->web($late + 5)->advance($id, $late + 5);
        $this->assertSame(1, $this->verifier->count('state'), 'a page takes no state');
        $this->sync($id, $late + 30);
        $this->assertSame([$this->t0 + 120, $late + 30], $this->verifier->stateTimes(), 'taken again by the sync command, from data LibreNMS has within the wait');
        $this->assertFalse(ChangeLifecycle::beforeRetaking($this->changes->find($id), $this->changes->batches[$id][1]));

        // the page that holds the answers launches the batch within seconds
        $this->assertNull($this->web($late + 32)->resume($id, self::ALICE, ['key' => 's3cret'], $late + 32));
        $this->assertSame([[510]], $this->launched());
        $this->assertSame(['key' => 's3cret'], end($this->runner->launches)['secrets']);
        $this->assertSame(2, $this->verifier->count('state'));
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

    public function testAFixWhoseCheckDidNotRunIsNotVerified(): void
    {
        $fix = ['kind' => 'port_descriptions', 'ids' => ['f1'], 'variable' => 'port_descriptions', 'title' => 'Port descriptions of lab-sw4', 'lines' => [],
            'findings' => ['d510' => ['code' => 'description_wrong', 'severity' => 'warning', 'title' => 'Port descriptions name the wrong neighbor: lab-sw4', 'devices' => [510]]],
            'devices' => ['510' => ['d510']]];
        // the topology plugin's link check is not one of its background checks: no state has its findings, so none is "still there"
        $this->verifier->checks = ['stp', 'redundancy', 'routing'];
        $id = $this->applied(['fix' => $fix]);
        $this->endBatch($id, 1, $this->t0 + 300);
        $this->sync($id, $this->t0 + 960);
        $this->fresh($this->t0 + 1000);
        $this->sync($id, $this->t0 + 1080);
        $change = $this->changes->find($id);
        $this->assertSame([ChangeStatus::PAUSED, 'verify_unknown', 'unknown'], [$change['status'], $change['waiting'], $this->verify($id, 1)['result']]);
        $this->assertStringContainsString("Batch 1 cannot be verified: the topology plugin's links check, which found the fix's findings, is switched off in its settings "
            . '(Background checks); nothing else that was compared changed. To go on: set When a batch cannot be verified to only record it.', $this->messages($id));
    }

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
