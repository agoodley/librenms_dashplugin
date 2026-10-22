<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\SqliteSql;
use PHPUnit\Framework\TestCase;

/**
 * ChangeRepository's statements as written for LibreNMS' database, run on SQLite: the compare-and-set, the targets
 * and batches, diffs, windows and approvals, the devices a change reserves, the changes the sync command follows, and
 * the runs that changed devices since a dry run.
 */
class ChangeRepositoryTest extends TestCase
{
    private SqliteSql $sql;

    private ChangeRepository $changes;

    protected function setUp(): void
    {
        $this->sql = new SqliteSql();
        $this->changes = new ChangeRepository($this->sql);
    }

    private function create(array $change = [], array $targets = [501 => 'lab-sw1', 502 => 'lab-sw2', 503 => 'lab-sw3']): int
    {
        $plan = BatchPlan::make(array_keys($targets), [], 1, 5, 1, true, 0);
        $rows = [];
        foreach ($targets as $deviceId => $name) {
            $rows[$deviceId] = ['inventory_name' => "$name.netops.lab", 'hostname' => "$name.netops.lab", 'display' => $name, 'batch' => (int) $plan->batchOf($deviceId)];
        }

        return $this->changes->create($change + ['user_id' => 1, 'username' => 'alice', 'template_id' => '77', 'template_name' => 'Port description',
            'playbook' => 'desc.yml', 'reference' => 'CHG-1', 'status' => ChangeStatus::DRAFT, 'variables' => [['name' => 'interface', 'value' => 'Fa0/1', 'secret' => false]],
            'has_secrets' => false, 'plan' => $plan->toArray(), 'target_count' => count($targets), 'batch_count' => $plan->count(),
            'request_token' => bin2hex(random_bytes(16))], $rows, $plan->count());
    }

    public function testAChangeIsRecordedWithItsTargetsAndBatches(): void
    {
        $id = $this->create(['request_token' => str_repeat('b', 32)]);

        $change = $this->changes->find($id);
        $this->assertSame('draft', $change['status']);
        $this->assertSame([['name' => 'interface', 'value' => 'Fa0/1', 'secret' => false]], $change['variables'], 'JSON columns come back decoded');
        $this->assertSame([[501], [502, 503]], $change['plan']['batches']);
        $this->assertFalse($change['has_secrets']);
        $this->assertSame([501, 502, 503], array_keys($this->changes->targets($id)), 'in their order');
        $this->assertSame([1, 2, 2], array_map(fn ($t) => (int) $t['batch'], array_values($this->changes->targets($id))));
        $this->assertSame(['pending', 'pending'], array_column($this->changes->batches($id), 'status'));
        $this->assertSame($id, $this->changes->byRequestToken(str_repeat('b', 32)));
        $this->expectException(\PDOException::class);
        $this->create(['request_token' => str_repeat('b', 32)]); // the form token is unique: a double submit makes one change
    }

    public function testUpdatesAreACompareAndSet(): void
    {
        $id = $this->create();

        $this->assertTrue($this->changes->updateAt($id, 0, ['status' => ChangeStatus::DRY_RUN, 'dry_run_findings' => ['a', 'b']]));
        $this->assertFalse($this->changes->updateAt($id, 0, ['status' => ChangeStatus::CANCELLED]), 'a write from an older read is refused');
        $change = $this->changes->find($id);
        $this->assertSame(ChangeStatus::DRY_RUN, $change['status']);
        $this->assertSame(['a', 'b'], $change['dry_run_findings']);
        $this->assertSame(1, (int) $change['version']);
    }

    public function testTargetsBatchesWindowsDiffsAndApprovals(): void
    {
        $id = $this->create();
        $this->changes->updateTargets($id, [502 => ['dry_result' => 'changed', 'dry_counts' => ['ok' => 1, 'changed' => 1]], 503 => ['before_status' => 'requested']]);
        $this->assertSame(['ok' => 1, 'changed' => 1], $this->changes->targets($id)[502]['dry_counts']);
        $this->assertSame('requested', $this->changes->targets($id)[503]['before_status']);

        $this->changes->updateBatch($id, 2, ['status' => 'running', 'run_id' => 9]);
        $this->assertSame('running', $this->changes->batches($id)[2]['status']);

        $window = $this->changes->addWindow(['change_id' => $id, 'batch' => 1, 'schedule_id' => 41, 'status' => 'open', 'opened_at' => 1000, 'ends_at' => 4600]);
        $this->changes->updateWindow($window, ['closing' => true, 'ends_at' => Clock::store(5000)]);
        $this->assertTrue($this->changes->windows($id)[$window]['closing']);
        $this->assertSame(Clock::store(5000), $this->changes->windows($id)[$window]['ends_at']);

        $this->changes->replaceDiffs($id, 9, [['device_id' => 502, 'task' => 'Set', 'item' => null, 'kind' => 'diff', 'lines' => ['--- before', '+ x'], 'cut' => false,
            'host' => null, 'reason' => null]]);
        $this->changes->replaceDiffs($id, 10, [['device_id' => null, 'task' => 'Set', 'item' => 'a', 'kind' => 'text', 'lines' => ['raw'], 'cut' => true, 'host' => 'h',
            'reason' => 'not a target']]);
        $diffs = $this->changes->diffs($id);
        $this->assertCount(1, $diffs, 'a new dry run replaces the diffs');
        $this->assertSame(['raw'], $diffs[0]['lines']);
        $this->assertTrue($diffs[0]['cut']);

        $this->changes->addApproval(['change_id' => $id, 'user_id' => 2, 'username' => 'bob', 'decision' => 'approved', 'comment' => 'ok', 'fingerprint' => 'f',
            'dry_run_id' => 9, 'created_at' => 2000]);
        $this->changes->voidApprovals($id, 'expired', 3000);
        $approval = $this->changes->approvals($id)[0];
        $this->assertSame([Clock::store(3000), 'expired'], [$approval['voided_at'], $approval['void_reason']]);

        $this->changes->addEvent($id, 'dry_run', 'Dry run launched.', 'alice', 1000);
        $this->assertSame([['at' => Clock::store(1000), 'username' => 'alice', 'kind' => 'dry_run', 'message' => 'Dry run launched.']], $this->changes->events($id));
    }

    public function testDevicesAreReservedWhileAChangeIsApplied(): void
    {
        $applying = $this->create();
        $ready = $this->create([], [503 => 'lab-sw3', 510 => 'lab-sw4']);
        $this->changes->updateAt($applying, 0, ['status' => ChangeStatus::APPLYING]);

        $this->assertSame([501 => $applying, 502 => $applying, 503 => $applying], $this->changes->reserved());
        $this->assertSame([], $this->changes->reserved($applying), 'a change does not refuse its own devices');
        $version = 1;
        foreach ([ChangeStatus::PAUSED, ChangeStatus::STOPPING] as $status) {
            $this->changes->updateAt($applying, $version++, ['status' => $status]);
            $this->assertSame([501 => $applying, 502 => $applying, 503 => $applying], $this->changes->reserved(), "a $status change reserves its devices too");
        }
        $this->changes->updateAt($applying, $version, ['status' => ChangeStatus::APPLIED]);
        $this->assertSame([], $this->changes->reserved());
        $this->assertSame([$ready], $this->changes->pendingIds());
    }

    public function testTheSyncCommandFollowsUnfinishedChangesAndOpenWindowsAndBackups(): void
    {
        $done = $this->create(['status' => ChangeStatus::APPLIED]);
        $windowed = $this->create(['status' => ChangeStatus::FAILED]);
        $backedUp = $this->create(['status' => ChangeStatus::STOPPED]);
        $open = $this->create();
        $this->changes->addWindow(['change_id' => $windowed, 'batch' => 1, 'schedule_id' => 1, 'status' => 'open', 'opened_at' => 1, 'ends_at' => 2]);
        $this->changes->updateTargets($backedUp, [501 => ['after_status' => 'requested']]);

        $this->assertSame([$windowed, $backedUp, $open], $this->changes->pendingIds());
        $this->assertNotContains($done, $this->changes->pendingIds());
    }

    public function testRunsThatChangedDevicesSinceADryRun(): void
    {
        $runs = new RunRepository($this->sql);
        $record = fn (array $run, array $targets) => $runs->create($run + ['user_id' => 1, 'username' => 'alice', 'runner' => 'semaphore', 'runner_project' => 8,
            'template_id' => '77', 'template_name' => 'T', 'limit' => 'x', 'request_token' => bin2hex(random_bytes(16)), 'status' => 'success'],
            array_map(fn ($name) => ['inventory_name' => $name, 'hostname' => $name, 'display' => $name], $targets));
        $since = time() - 60;
        $proven = $record(['kind' => 'dry_run', 'change_id' => 5], [501 => 'a']);
        $runs->setProof($proven, 'verified');
        $record(['kind' => 'dry_run', 'change_id' => 5, 'status' => 'running'], [501 => 'a']);
        $record(['kind' => 'batch', 'change_id' => 7], [501 => 'a']);
        $other = $record(['kind' => 'batch', 'change_id' => 6], [502 => 'b']);
        $phaseOne = $record([], [503 => 'c']);
        $record(['status' => 'not_launched'], [510 => 'd']);

        $this->assertSame([502 => $other, 503 => $phaseOne], $runs->changedSince([501, 502, 503, 510], $since, 7),
            'other changes\' batches and phase 1 runs count; proven and running dry runs, the change\'s own runs and refused launches do not');
        $this->assertSame([], $runs->changedSince([501, 502, 503], time() + 60, 7), 'only since the dry run');

        $unproven = $record(['kind' => 'dry_run', 'change_id' => 5], [511 => 'e']);
        $runs->setProof($unproven, 'unverified');
        $lost = $record(['kind' => 'dry_run', 'change_id' => 6, 'status' => 'lost'], [512 => 'f']);
        $this->assertSame([511 => $unproven, 512 => $lost], $runs->changedSince([511, 512], $since, 7),
            'a dry run not proven to be one may have changed its devices');
        $queued = $record(['status' => 'stopped'], [514 => 'h']);
        $this->assertSame([], $runs->changedSince([514], $since, 7), 'a run stopped in the runner\'s queue never started: it changed nothing');
        $this->sql->change('UPDATE `ansible_runs` SET `started_at` = ? WHERE `id` = ?', [Clock::store(time()), $queued]);
        $this->assertSame([514 => $queued], $runs->changedSince([514], $since, 7), 'a stopped run that had started counts');
        $stray = $record([], [513 => 'g']);
        $this->sql->change('UPDATE `ansible_run_targets` SET `unexpected` = 1 WHERE `run_id` = ?', [$stray]);
        $this->assertSame([513 => $stray], $runs->changedSince([513], $since, 7), 'a run on a device that was not its target counts too');
    }
}
