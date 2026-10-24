<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Changes\InventoryGuard;
use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\SchemaCheck;
use Agoodley\LibrenmsAnsible\Services\RunFollower;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\SqliteSql;
use PHPUnit\Framework\TestCase;

class RunFollowerTest extends TestCase
{
    public function testTheLockOfARefreshOutlastsItsSlowestRunnerCalls(): void
    {
        // a refresh may make RunFollower::CALLS runner calls (a search reads up to five pages), each up to the connection's timeout
        $this->assertSame(7, RunFollower::CALLS);
        $this->assertSame(7 * 15 + 60, RunFollower::lockSecondsFor(15));
        $this->assertSame(7 * 120 + 60, RunFollower::lockSecondsFor(120));
        $this->assertSame(67, RunFollower::lockSecondsFor(1));
    }

    public function testTheGuardsRecordOfADryRunIsItsChangesForItsCurrentDryRunOnly(): void
    {
        // what the run lifecycle asks before it takes a log that says nothing ran for a dry run that ran nowhere
        SchemaCheck::useCatalogue(fn (string $what, string $table) => $what === 'table' ? true
            : ['change_id', 'kind', 'batch', 'context', 'commit_hash', 'proof', 'backup_check', 'served_hash', 'served_at', 'served_note', 'blast', 'blast_accepted',
                'blast_override', 'cut_off', 'verify', 'fix', 'verification', 'verification_note']);
        try {
            $changes = new ChangeRepository(new SqliteSql());
            $plan = BatchPlan::make([501, 502], [], 1, 5, 1, true, 0);
            $id = $changes->create(['user_id' => 1, 'username' => 'alice', 'template_id' => '77', 'template_name' => 'Port description', 'playbook' => 'desc.yml',
                'reference' => 'CHG-1', 'status' => ChangeStatus::DRY_RUN, 'variables' => [], 'has_secrets' => false, 'plan' => $plan->toArray(), 'target_count' => 2,
                'batch_count' => $plan->count(), 'request_token' => bin2hex(random_bytes(16))],
                [501 => ['inventory_name' => 'lab-sw1.netops.lab', 'hostname' => 'lab-sw1.netops.lab', 'display' => 'lab-sw1', 'batch' => 1],
                    502 => ['inventory_name' => 'lab-sw2.netops.lab', 'hostname' => 'lab-sw2.netops.lab', 'display' => 'lab-sw2', 'batch' => 2]], $plan->count());
            $this->assertTrue($changes->updateAt($id, 0, ['dry_run_id' => 90]));
            $run = ['id' => 90, 'kind' => 'dry_run', 'change_id' => $id];
            $this->assertFalse(RunFollower::allLeftOut($run, $changes), 'nothing recorded');
            $changes->updateTargets($id, [501 => ['served_hash' => InventoryGuard::LEFT_OUT]]);
            $this->assertFalse(RunFollower::allLeftOut($run, $changes), 'lab-sw2 was not left out');
            $changes->updateTargets($id, [502 => ['served_hash' => InventoryGuard::LEFT_OUT]]);
            $this->assertTrue(RunFollower::allLeftOut($run, $changes), 'every target left out');
            $this->assertFalse(RunFollower::allLeftOut(['id' => 89] + $run, $changes), 'not the change\'s current dry run');
            $this->assertFalse(RunFollower::allLeftOut(['kind' => 'batch'] + $run, $changes), 'a batch');
            $this->assertFalse(RunFollower::allLeftOut(['change_id' => null] + $run, $changes), 'a run of no change');
        } finally {
            SchemaCheck::useCatalogue(null);
        }
    }

    public function testTheLockOfAChangeOutlastsALaunchsRunnerCallsBetweenTwoHeartbeats(): void
    {
        // a launch makes 10 runner calls between two heartbeats (the token's checks, the template, inventory, variable groups,
        // repository, then the POST): more than a refresh of a run
        $this->assertSame(12, \Agoodley\LibrenmsAnsible\Services\ChangeFollower::LAUNCH_CALLS);
        $this->assertSame(12 * 15 + 60, \Agoodley\LibrenmsAnsible\Services\ChangeFollower::lockSecondsFor(15));
        $this->assertGreaterThan(RunFollower::lockSecondsFor(15), \Agoodley\LibrenmsAnsible\Services\ChangeFollower::lockSecondsFor(15));
    }
}
