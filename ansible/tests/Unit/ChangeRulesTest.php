<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\ApprovalRules;
use Agoodley\LibrenmsAnsible\Changes\BackupState;
use Agoodley\LibrenmsAnsible\Changes\BatchOutcome;
use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Changes\Connections;
use Agoodley\LibrenmsAnsible\Changes\DryRunCheck;
use Agoodley\LibrenmsAnsible\Changes\Fingerprint;
use Agoodley\LibrenmsAnsible\Changes\MaintenanceTimes;
use Agoodley\LibrenmsAnsible\Changes\NextChange;
use Agoodley\LibrenmsAnsible\Changes\TemplateSnapshot;
use Agoodley\LibrenmsAnsible\Runner\RunnerEnvironment;
use Agoodley\LibrenmsAnsible\Runner\RunnerInventory;
use Agoodley\LibrenmsAnsible\Runner\RunnerRepository;
use Agoodley\LibrenmsAnsible\Support\Clock;
use PHPUnit\Framework\TestCase;

/**
 * The rules of changes: the batch plan, the fingerprint of what is approved, who may approve, when a batch stops the
 * change, when a template can be dry-run, backups and maintenance windows.
 */
class ChangeRulesTest extends TestCase
{
    // ------------------------------------------------------------------ batch plan

    public function testThePlanPutsTheCanaryFirstThenBatchesOfTheBatchSize(): void
    {
        $plan = BatchPlan::make([501, 502, 503, 510, 511, 512, 513, 514], [], 1, 5, 1, true, 0);
        $this->assertSame([[501], [502, 503, 510, 511, 512], [513, 514]], $plan->batches);
        $this->assertSame('3 batches: 1, then 5, then 2 devices; stops at 1 failed device in a batch; waits for an admin to continue after the first batch', $plan->describe());

        $chosen = BatchPlan::make([501, 502, 503, 510], [510, 999], 1, 2, 2, false, 15);
        $this->assertSame([[510], [501, 502], [503]], $chosen->batches, 'the chosen canary, an unknown device ignored');
        $this->assertSame(2, $chosen->batchOf(501));
        $this->assertSame([503], $chosen->devices(3));
        $this->assertSame([], $chosen->devices(4));
        $this->assertStringContainsString('15 min between batches', $chosen->describe());
    }

    public function testAPlanFromDataMustCoverTheTargetsExactlyOnce(): void
    {
        $data = BatchPlan::make([1, 2, 3], [], 1, 5, 1, true, 0)->toArray();
        $this->assertSame($data, BatchPlan::fromArray($data, [3, 2, 1])->toArray(), 'a plan made elsewhere (phase 3) reads back the same');

        $bad = [
            'not a plan' => ['x'],
            'is empty or not a list of devices' => ['batches' => [[1, 2, 3], []]] + $data,
            'not a device id' => ['batches' => [[1, '2', 3]]] + $data,
            'more than one batch' => ['batches' => [[1, 2], [2, 3]]] + $data,
            'not targets of the change' => ['batches' => [[1, 2, 3, 4]]] + $data,
            'leaves out targets' => ['batches' => [[1, 2]]] + $data,
            'no failure threshold' => ['stop_at' => 0] + $data,
        ];
        foreach ($bad as $message => $plan) {
            try {
                BatchPlan::fromArray($plan, [1, 2, 3]);
                $this->fail("accepted: $message");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ fingerprint

    private static function snapshot(array $template = [], array $env = ['ANSIBLE_CHECK_MODE_MARKERS' => 'true', 'LIBRENMS_URL' => 'https://l'], ?RunnerRepository $repository = null): array
    {
        $groups = [new RunnerEnvironment('4', 'LibreNMS', array_keys($env), ['LIBRENMS_TOKEN'], $env, '', [])];

        return TemplateSnapshot::parts('semaphore', 8, TemplateCheckTest::template($template + ['environmentIds' => ['4']]), $groups,
            new RunnerInventory('12', 'LibreNMS', 'file', 'inventory/librenms_inventory.py'), $repository ?? new RunnerRepository('9', 'r', 'file:///repos/r.git', 'main'));
    }

    public function testTheFingerprintChangesWithWhatAnApplyRuns(): void
    {
        $plan = BatchPlan::make([501, 502], [], 1, 5, 1, true, 0)->toArray();
        $reach = [501 => ['ansible_host' => '10.0.0.1', 'ansible_network_os' => 'cisco.ios.ios'], 502 => ['ansible_host' => '10.0.0.2', 'ansible_network_os' => 'cisco.ios.ios']];
        $parts = fn (array $snapshot = [], array $targets = [501 => 'a', 502 => 'b'], ?array $connections = null, array $answers = ['interface' => 'Fa0/1'], ?array $batches = null)
            => Fingerprint::parts($snapshot ?: self::snapshot(), $targets, $connections ?? $reach, $answers, $batches ?? $plan);
        $base = $parts([], [502 => 'b', 501 => 'a'], [502 => $reach[502], 501 => $reach[501]]);
        $same = $parts();
        $this->assertSame(Fingerprint::of($base), Fingerprint::of($same), 'the order of targets does not matter');
        $this->assertSame(64, strlen(Fingerprint::of($base)));

        $changed = [
            "the template's playbook" => $parts(self::snapshot(['playbook' => 'other.yml'])),
            "the template's survey variables" => $parts(self::snapshot(['surveyVars' => []])),
            'its variable groups' => $parts(self::snapshot([], ['ANSIBLE_CHECK_MODE_MARKERS' => 'true', 'LIBRENMS_URL' => 'https://other'])),
            'its repository' => $parts(self::snapshot([], ['ANSIBLE_CHECK_MODE_MARKERS' => 'true', 'LIBRENMS_URL' => 'https://l'], new RunnerRepository('9', 'r', 'file:///repos/r.git', 'dev'))),
            'the targets' => $parts([], [501 => 'a']),
            'how the runner reaches the targets' => $parts([], [501 => 'a', 502 => 'b'], [501 => ['ansible_host' => '10.0.0.9'] + $reach[501], 502 => $reach[502]]),
            'the answers' => $parts([], [501 => 'a', 502 => 'b'], null, ['interface' => 'Fa0/2']),
            'the batch plan' => $parts([], [501 => 'a', 502 => 'b'], null, ['interface' => 'Fa0/1'], BatchPlan::make([501, 502], [502], 1, 5, 1, true, 0)->toArray()),
        ];
        foreach ($changed as $what => $other) {
            $this->assertNotSame(Fingerprint::of($base), Fingerprint::of($other), $what);
            $this->assertContains($what, Fingerprint::differences($base, $other), $what);
        }
        $this->assertSame([], Fingerprint::differences($base, $same));
        $stored = json_decode((string) json_encode($base), true);
        $this->assertSame(Fingerprint::of($base), Fingerprint::of($parts(self::snapshot(), [501 => 'a', 502 => 'b'], $stored['connections'])),
            'the connections read back from the stored parts give the same fingerprint');
        $this->assertNotSame(Fingerprint::approval('a', 7, null), Fingerprint::approval('a', 7, 'abc1234'), 'the dry run\'s commit is part of the approval');
        $this->assertStringNotContainsString('https://l', (string) json_encode($base), 'the stored parts hold hashes of the variables, not their values');
    }

    // ------------------------------------------------------------------ a new change made from an earlier one

    public function testANewChangeFromAnEarlierOneTakesWhatItLeftToDo(): void
    {
        $plan = BatchPlan::make([501, 502, 503, 504], [502], 1, 2, 1, true, 0)->toArray(); // 502, then 501 503, then 504
        $change = ['id' => 31, 'reference' => 'CHG-7', 'plan' => $plan, 'variables' => [
            ['name' => 'interface', 'value' => 'Fa0/9', 'secret' => false], ['name' => 'description', 'value' => 'uplink', 'secret' => false],
            ['name' => 'key', 'value' => '********', 'secret' => true]]];
        $target = fn (int $batch, int $position, ?string $dry, ?string $result) => ['batch' => $batch, 'position' => $position, 'dry_result' => $dry,
            'result' => $result, 'display' => '', 'hostname' => '', 'inventory_name' => ''];
        $targets = [501 => ['display' => 'sw1'] + $target(2, 1, 'changed', 'changed'), 502 => ['display' => 'sw2'] + $target(1, 0, 'changed', 'changed'),
            503 => ['display' => 'sw3'] + $target(2, 2, 'changed', 'failed'), 504 => ['display' => 'sw4'] + $target(3, 3, 'changed', null)];

        $this->assertSame([503, 504], NextChange::devices($targets, NextChange::REMAINING), 'not applied: failed, and never run');
        $prefill = NextChange::prefill($change, $targets, NextChange::REMAINING, [504 => ['run' => 9, 'held_until' => '2026-10-22 11:40:00']]);
        $this->assertSame([503], $prefill['devices'], 'a held device is left out');
        $this->assertStringStartsWith('sw4: held by run #9 until ', $prefill['left_out'][0]);
        $this->assertSame(['interface' => 'Fa0/9', 'description' => 'uplink'], $prefill['vars'], 'the answers, never the secret ones');
        $this->assertSame(['CHG-7', 'the remaining devices of change #31', []], [$prefill['reference'], $prefill['text'], $prefill['canary']]);

        $targets[502]['dry_result'] = 'unreachable';
        $passed = NextChange::prefill($change, $targets, NextChange::PASSED, []);
        $this->assertSame([501, 503, 504], $passed['devices'], 'without the devices its dry run failed on, in its order');
        $this->assertSame('change #31 without the devices its dry run failed on (sw2)', $passed['text']);
        $targets[502]['dry_result'] = 'changed';
        $this->assertSame([502], NextChange::prefill($change, $targets, NextChange::PASSED, [])['canary'], 'its first batch, when it is among them');
    }

    // ------------------------------------------------------------------ how the runner reaches the devices

    public function testConnectionsAreTheAnsibleVariablesOfEachHost(): void
    {
        $inventory = self::labInventory();
        $connections = Connections::of($inventory, [502, 501, 503]);

        $this->assertSame([501, 502, 503], array_keys($connections));
        $this->assertSame(['ansible_become' => true, 'ansible_become_method' => 'enable', 'ansible_connection' => 'ansible.netcommon.network_cli',
            'ansible_host' => '127.0.1.11', 'ansible_network_os' => 'cisco.ios.ios'], $connections[501], 'the overwrite IP is the address');
        $this->assertSame('sec-sw2.example.net', $connections[502]['ansible_host'], 'else the hostname');
        $this->assertSame([Connections::NOT_A_HOST => 'disabled in LibreNMS'], $connections[503]);
        $this->assertSame('127.0.1.11', Connections::address($connections, 501));
        $this->assertNull(Connections::address($connections, 503));
    }

    public function testAnotherAddressOrPlatformIsAnotherConnectionNotOtherFacts(): void
    {
        $names = [501 => 'sec-sw1', 502 => 'sec-sw2', 503 => 'sec-sw3'];
        $recorded = json_decode((string) json_encode(Connections::of(self::labInventory(), [501, 502])), true); // as stored
        $this->assertSame([], Connections::differences($recorded, Connections::of(self::labInventory(), [501, 502]), $names));

        // the facts of a device (sysName, location, version, hardware, type) are not how the runner reaches it
        $facts = Connections::of(self::labInventory([501 => ['sysName' => 'renamed', 'version' => '17.9', 'hardware' => 'other', 'location_id' => 2, 'type' => 'server']]), [501, 502]);
        $this->assertSame([], Connections::differences($recorded, $facts, $names));

        $moved = Connections::differences($recorded, Connections::of(self::labInventory([501 => ['overwrite_ip' => '127.0.1.12']]), [501, 502]), $names);
        $this->assertSame(['sec-sw1: ansible_host 127.0.1.11 → 127.0.1.12'], $moved, 'pointed at another device');

        $renamed = Connections::differences($recorded, Connections::of(self::labInventory([502 => ['hostname' => 'sec-sw9.example.net']]), [501, 502]), $names);
        $this->assertSame(['sec-sw2: ansible_host sec-sw2.example.net → sec-sw9.example.net'], $renamed, 'a new hostname without an overwrite IP is a new address');

        $platform = Connections::differences($recorded, Connections::of(self::labInventory([501 => ['os' => 'junos']]), [501, 502]), $names);
        $this->assertSame(['sec-sw1: ansible_become true → (none), ansible_become_method enable → (none), ansible_network_os cisco.ios.ios → junipernetworks.junos.junos'],
            $platform, 'another platform (from its os, or from the Platform mapping setting)');

        $gone = Connections::differences($recorded, Connections::of(self::labInventory([502 => ['disabled' => 1]]), [501, 502]), $names);
        $this->assertSame(['sec-sw2 is no longer a host of the inventory (disabled in LibreNMS)'], $gone);
        $this->assertSame(['sec-sw3: the dry run recorded no address for it'], Connections::differences($recorded, Connections::of(self::labInventory([503 => ['disabled' => 0]]), [503]), $names));
    }

    /**
     * An inventory of three switches (501 with an overwrite IP, 502 without, 503 disabled), with some of their fields replaced.
     *
     * @param  array<int, array<string, mixed>>  $changes
     */
    private static function labInventory(array $changes = []): \Agoodley\LibrenmsAnsible\Inventory\Inventory
    {
        $devices = [
            501 => ['hostname' => 'sec-sw1.example.net', 'overwrite_ip' => '127.0.1.11', 'sysName' => 'sec-sw1', 'os' => 'ios', 'type' => 'network', 'hardware' => 'C9300',
                'version' => '17.6', 'location_id' => 1, 'disabled' => 0],
            502 => ['hostname' => 'sec-sw2.example.net', 'overwrite_ip' => null, 'sysName' => 'sec-sw2', 'os' => 'iosxe', 'type' => 'network', 'hardware' => 'C9300',
                'version' => '17.6', 'location_id' => 1, 'disabled' => 0],
            503 => ['hostname' => 'sec-sw3.example.net', 'overwrite_ip' => '127.0.1.13', 'sysName' => 'sec-sw3', 'os' => 'ios', 'type' => 'network', 'hardware' => 'C9300',
                'version' => '17.6', 'location_id' => 1, 'disabled' => 1],
        ];
        foreach ($changes as $deviceId => $values) {
            $devices[$deviceId] = $values + $devices[$deviceId];
        }

        return (new \Agoodley\LibrenmsAnsible\Inventory\InventoryBuilder(\Agoodley\LibrenmsAnsible\Inventory\PlatformMap::parse(null)))
            ->build($devices, [], [], [1 => 'HQ', 2 => 'Annex']);
    }

    // ------------------------------------------------------------------ can the template be dry-run?

    public function testATemplateCanBeDryRunOnlyWithTheMarkers(): void
    {
        $template = TemplateCheckTest::template(['environmentIds' => ['4', '5']]);
        $group = fn (string $id, array $env, array $secrets = []) => new RunnerEnvironment($id, "group $id", array_keys($env), $secrets, $env);

        $this->assertSame([], DryRunCheck::reasons($template, [$group('4', ['ANSIBLE_CHECK_MODE_MARKERS' => 'True']), $group('5', [])]));
        $this->assertStringContainsString('do not set the environment variable ANSIBLE_CHECK_MODE_MARKERS to true',
            DryRunCheck::reasons($template, [$group('4', []), $group('5', [])])[0]);
        $this->assertStringContainsString('to "false": set it to true', DryRunCheck::reasons($template,
            [$group('4', ['ANSIBLE_CHECK_MODE_MARKERS' => 'true']), $group('5', ['ANSIBLE_CHECK_MODE_MARKERS' => 'false'])])[0], 'a later group wins, as in Semaphore');
        $this->assertStringContainsString('as a secret, whose value the plugin cannot read', DryRunCheck::reasons($template,
            [$group('4', ['ANSIBLE_CHECK_MODE_MARKERS' => 'true']), $group('5', [], ['ANSIBLE_CHECK_MODE_MARKERS'])])[0], 'a secret overrides a variable');
        $this->assertStringContainsString('ANSIBLE_STDOUT_CALLBACK to "json"', DryRunCheck::reasons($template,
            [$group('4', ['ANSIBLE_CHECK_MODE_MARKERS' => 'true', 'ANSIBLE_STDOUT_CALLBACK' => 'json']), $group('5', [])])[0]);
        $this->assertStringContainsString('ANSIBLE_DISPLAY_OK_HOSTS to "no"', DryRunCheck::reasons($template,
            [$group('4', ['ANSIBLE_CHECK_MODE_MARKERS' => 'true', 'ANSIBLE_DISPLAY_OK_HOSTS' => 'no']), $group('5', [])])[0]);
        $this->assertStringContainsString('cannot be read: HTTP 500', DryRunCheck::reasons($template, [$group('4', ['ANSIBLE_CHECK_MODE_MARKERS' => 'true']), 'HTTP 500'])[0]);
        $this->assertStringContainsString('hold --check, so every run of it is a dry run', DryRunCheck::reasons(TemplateCheckTest::template(['arguments' => ['--check']]),
            [$group('4', ['ANSIBLE_CHECK_MODE_MARKERS' => 'true'])])[0]);
        $this->assertStringContainsString('no variable group', DryRunCheck::reasons($template, [])[0]);
    }

    // ------------------------------------------------------------------ approvals

    public function testWhoMayApprove(): void
    {
        $change = ['status' => ChangeStatus::AWAITING, 'user_id' => 1];

        $this->assertNull(ApprovalRules::refusal(ApprovalRules::REQUIRED, $change, 1, true), 'the requester may approve when no second admin is needed');
        $this->assertStringContainsString('second admin', (string) ApprovalRules::refusal(ApprovalRules::SECOND, $change, 1, true));
        $this->assertNull(ApprovalRules::refusal(ApprovalRules::SECOND, $change, 1, true, true), 'the requester may reject their own change');
        $this->assertNull(ApprovalRules::refusal(ApprovalRules::SECOND, $change, 2, true));
        $this->assertStringContainsString('ansible.approve', (string) ApprovalRules::refusal(ApprovalRules::SECOND, $change, 2, false));
        $this->assertStringContainsString('verified without failures', (string) ApprovalRules::refusal(ApprovalRules::REQUIRED, ['status' => ChangeStatus::DRAFT] + $change, 2, true));
        $this->assertStringContainsString('no longer be approved', (string) ApprovalRules::refusal(ApprovalRules::REQUIRED, ['status' => ChangeStatus::APPLYING] + $change, 2, true));
    }

    public function testApprovalsExpireAndNeedTheirApprover(): void
    {
        $now = strtotime('2026-10-22 12:00:00 UTC');
        $change = ['user_id' => 1, 'approved_user_id' => 2, 'approved_at' => Clock::store($now - 3600)];

        $this->assertNull(ApprovalRules::problem(ApprovalRules::OFF, ['approved_at' => null] + $change, 86400, $now, null));
        $this->assertNull(ApprovalRules::problem(ApprovalRules::SECOND, $change, 86400, $now, null));
        $this->assertStringContainsString('expired', (string) ApprovalRules::problem(ApprovalRules::REQUIRED, $change, 1800, $now, null));
        $this->assertStringContainsString('has not been approved', (string) ApprovalRules::problem(ApprovalRules::REQUIRED, ['approved_at' => null] + $change, 86400, $now, null));
        $this->assertStringContainsString('approved by its requester', (string) ApprovalRules::problem(ApprovalRules::SECOND, ['approved_user_id' => 1] + $change, 86400, $now, null),
            'switching to a second admin voids an approval of the requester');
        $this->assertSame('its approver no longer holds the approve permission',
            ApprovalRules::problem(ApprovalRules::REQUIRED, $change, 86400, $now, 'no longer holds the approve permission'));
        $this->assertSame('its approver bob no longer holds the approve permission',
            ApprovalRules::problem(ApprovalRules::REQUIRED, ['approved_by' => 'bob'] + $change, 86400, $now, 'no longer holds the approve permission'), 'named');
    }

    // ------------------------------------------------------------------ batches

    public function testWhenABatchStopsTheChange(): void
    {
        $ok = ['status' => 'success', 'unexpected_hosts' => null, 'commit_hash' => 'abc1234'];

        $this->assertNull(BatchOutcome::stopReason(1, $ok, [1 => 'ok', 2 => 'changed'], 1, 'abc1234'));
        $this->assertSame('1 device of batch 2 failed (the plan stops at 1)', BatchOutcome::stopReason(2, ['status' => 'failed'] + $ok, [1 => 'ok', 2 => 'unreachable'], 1, null));
        $this->assertNull(BatchOutcome::stopReason(2, ['status' => 'failed'] + $ok, [1 => 'ok', 2 => 'failed'], 2, null), 'below the threshold');
        $this->assertSame([2, 3, 4, 5], BatchOutcome::failed([1 => 'ok', 2 => 'unknown', 3 => 'not_run', 4 => null, 5 => 'failed', 6 => 'skipped']));
        $this->assertSame('batch 1 was stopped', BatchOutcome::stopReason(1, ['status' => 'stopped'] + $ok, [1 => 'ok'], 1, null));
        $this->assertStringContainsString('lost track of batch 1', (string) BatchOutcome::stopReason(1, ['status' => 'lost'] + $ok, [1 => 'ok'], 1, null));
        $this->assertSame('batch 1 was not launched: refused', BatchOutcome::stopReason(1, ['status' => 'not_launched', 'error' => 'refused'] + $ok, [], 1, null));
        $this->assertStringContainsString('hosts that were not its targets (x)', (string) BatchOutcome::stopReason(1, ['unexpected_hosts' => ['x']] + $ok, [1 => 'ok'], 1, null));
        $this->assertSame('batch 1 ran commit def5678, not commit abc1234 of the dry run',
            BatchOutcome::stopReason(1, ['commit_hash' => 'def5678'] + $ok, [1 => 'ok'], 1, 'abc1234'));
        $this->assertStringContainsString('without telling its commit', (string) BatchOutcome::stopReason(1, ['commit_hash' => null] + $ok, [1 => 'ok'], 1, 'abc1234'));
    }

    // ------------------------------------------------------------------ backups and maintenance windows

    public function testABackupIsFreshWhenAnAttemptAfterTheRequestSucceeded(): void
    {
        $requested = strtotime('2026-10-22 06:15:00 UTC');
        $state = ['requested_at' => $requested];

        $this->assertSame(['status' => 'requested', 'at' => null, 'error' => null], BackupState::evaluate($state, null, $requested + 5, 600), 'running');
        $this->assertSame('requested', BackupState::evaluate($state, ['start' => '2026-10-22 06:00:00 UTC', 'end' => '2026-10-22 06:00:04 UTC', 'status' => 'success'], $requested + 5, 600)['status'],
            'an older backup is not fresh');
        $this->assertSame(['status' => 'fresh', 'at' => $requested + 4, 'error' => null],
            BackupState::evaluate($state, ['start' => '2026-10-22 06:15:00 UTC', 'end' => '2026-10-22 06:15:04 UTC', 'status' => 'success'], $requested + 9, 600));
        $failed = BackupState::evaluate($state, ['start' => '2026-10-22 06:15:01 UTC', 'end' => '2026-10-22 06:15:31 UTC', 'status' => 'no_connection'], $requested + 40, 600);
        $this->assertSame('requested', $failed['status'], 'Oxidized may retry');
        $this->assertStringContainsString('"no_connection"', (string) $failed['error']);
        $this->assertSame(['status' => 'failed', 'at' => null, 'error' => 'Oxidized\'s backup ended with "no_connection"'],
            BackupState::evaluate($state, ['start' => '2026-10-22 06:15:01 UTC', 'end' => '2026-10-22 06:15:31 UTC', 'status' => 'no_connection'], $requested + 31 + 91, 600),
            'no retry within 90 seconds of the failed attempt: failed, before the timeout');
        $timeout = BackupState::evaluate(['error' => $failed['error']] + $state, null, $requested + 601, 600);
        $this->assertSame('failed', $timeout['status']);
        $this->assertStringContainsString('no_connection', (string) $timeout['error']);
        $this->assertStringContainsString('within 10 minutes', (string) BackupState::evaluate($state, null, $requested + 601, 600)['error']);
    }

    public function testMaintenanceWindowTimes(): void
    {
        $this->assertSame(1000 + 3600, MaintenanceTimes::opening(1000, 3600));
        $this->assertNull(MaintenanceTimes::extension(5000, 1000, 3600), 'far enough away');
        $this->assertSame(4200 + 3600, MaintenanceTimes::extension(5000, 4200, 3600), 'less than a quarter left');
        $this->assertSame(2000 + 600, MaintenanceTimes::closing(2000, null, 600));
        $this->assertSame(3800 + 600, MaintenanceTimes::closing(2000, 3800, 600), 'after the hold of a stopped batch');
    }
}
