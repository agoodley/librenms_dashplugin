<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Data\DeviceRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Runner\RunnerTask;
use Agoodley\LibrenmsAnsible\Runner\RunnerTemplate;
use Agoodley\LibrenmsAnsible\Runs\RunLifecycle;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Services\Eventlog;
use Agoodley\LibrenmsAnsible\Services\InventoryService;
use Agoodley\LibrenmsAnsible\Services\LaunchPlan;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Services\RunLauncher;
use Agoodley\LibrenmsAnsible\Services\RunSpec;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeRunner;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\RecordingEvents;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\SqliteSql;
use PHPUnit\Framework\TestCase;

/**
 * What RunLauncher decides once a launch's plan passed its checks: under the lock of the launches, the run an earlier
 * launch with the same one-time token recorded and a change's stop asked for meanwhile, then right before the runner is
 * asked, that stop again. The runs are recorded in SQLite as in LibreNMS' database; the runner is a fake.
 */
class RunLauncherTest extends TestCase
{
    private RunRepository $runs;

    private FakeRunner $runner;

    private RecordingEvents $events;

    /** Called by the planner, after the token was first looked for (another request recording a run meanwhile). */
    private ?\Closure $whilePlanned = null;

    protected function setUp(): void
    {
        $this->runs = new RunRepository(new SqliteSql());
        $this->runner = new FakeRunner();
        $this->runner->launchTask = new RunnerTask('900', RunStatus::QUEUED, 'waiting');
        $this->events = new RecordingEvents();
    }

    private function launcher(): RunLauncher
    {
        $settings = new PluginSettings();

        return new RunLauncher(new Plugin(), $this->runs, new InventoryService(new DeviceRepository(), $settings), $settings, new Eventlog(), null,
            planner: function (): LaunchPlan {
                if ($this->whilePlanned !== null) {
                    ($this->whilePlanned)();
                }
                $plan = new LaunchPlan();
                $plan->runner = $this->runner;
                $plan->project = 3;
                $plan->template = new RunnerTemplate('11', 'Port description', '', 'desc.yml', 'ansible', '', '7', true, [], [], '', []);
                $plan->targets = [501 => ['device_id' => 501, 'name' => 'lab-sw1.netops.lab', 'display' => 'lab-sw1', 'hostname' => 'lab-sw1.netops.lab', 'os' => 'ios',
                    'platform' => 'cisco.ios.ios']];

                return $plan;
            },
            lock: fn () => new class
            {
                public function block($seconds): bool
                {
                    return true;
                }

                public function release(): bool
                {
                    return true;
                }
            },
            lifecycle: fn () => new RunLifecycle($this->runs, fn () => $this->runner, $this->events, 1048576, 86400));
    }

    /** Batch 1 of change $changeId, whose stop $halt tells (RunSpec::halted()). */
    private static function spec(?\Closure $halt = null, int $changeId = 7): RunSpec
    {
        return new RunSpec($changeId, RunSpec::BATCH, 1, "change #$changeId, batch 1 of 1", 'abc1234', null, $halt, 'created 2026-10-22 10:00:00');
    }

    private function launch(RunSpec $spec): array
    {
        return $this->launcher()->launch((object) ['user_id' => 1, 'username' => 'alice'], '11', [501], [], '', $spec);
    }

    /** A run recorded with the spec's token, as another request would. */
    private function recordWithToken(RunSpec $spec, int $changeId, string $kind = RunSpec::BATCH, ?int $batch = 1): int
    {
        return $this->runs->create(['user_id' => 1, 'username' => 'alice', 'runner' => 'semaphore', 'runner_project' => 3, 'template_id' => '11',
            'template_name' => 'Port description', 'playbook' => 'desc.yml', 'limit' => 'lab-sw1.netops.lab', 'variables' => [], 'request_token' => $spec->token(),
            'launch_nonce' => 'abcdef123456', 'status' => RunStatus::PENDING, 'change_id' => $changeId, 'kind' => $kind, 'batch' => $batch, 'context' => 'x'],
            [501 => ['inventory_name' => 'lab-sw1.netops.lab', 'hostname' => 'lab-sw1.netops.lab', 'display' => 'lab-sw1']]);
    }

    public function testALaunchRecordsItsRunThenAsksTheRunner(): void
    {
        $result = $this->launch(self::spec());
        $this->assertSame([[], false], [$result['errors'], $result['existing']]);
        $run = $this->runs->find((int) $result['run_id']);
        $this->assertSame([7, 'batch', 1, 3, '900'], [(int) $run['change_id'], $run['kind'], (int) $run['batch'], (int) $run['runner_project'], $run['runner_task_id']]);
        $this->assertCount(1, $this->runner->launched);
        $this->assertSame('abc1234', $this->runner->launched[0]->commit, 'a batch is pinned to its dry run\'s commit');
    }

    public function testAStopAskedWhileTheLaunchWasCheckedRecordsAndLaunchesNothing(): void
    {
        $spec = self::spec(fn () => 'the change was stopped before the batch was launched');
        $result = $this->launch($spec);
        $this->assertSame([null, ['the change was stopped before the batch was launched']], [$result['run_id'], $result['errors']]);
        $this->assertNull($this->runs->byRequestToken($spec->token()), 'no run recorded');
        $this->assertSame([], $this->runner->launched, 'the runner was not asked');
    }

    public function testAStopAskedOnceTheRunIsRecordedKeepsTheTaskFromTheRunner(): void
    {
        // the stop comes after the check under the lock, before the runner is asked: the run is recorded as not launched, with why
        $calls = 0;
        $spec = self::spec(function () use (&$calls): ?string {
            return ++$calls === 1 ? null : 'the change was stopped before the batch was launched';
        });
        $result = $this->launch($spec);
        $this->assertSame(2, $calls, 'asked under the lock, then right before the runner');
        $run = $this->runs->find((int) $result['run_id']);
        $this->assertSame([RunStatus::NOT_LAUNCHED, null], [$run['status'], $run['runner_task_id']]);
        $this->assertStringContainsString('the change was stopped before the batch was launched', (string) $run['error']);
        $this->assertSame([], $this->runner->launched, 'the runner was not asked');
    }

    public function testARunOfAnotherChangeThatHoldsTheTokenIsNeverTakenForIt(): void
    {
        // a run of another change (an earlier installation's change with the same id and salt, say) holds the token: refused, nothing
        // recorded or launched; found before the plan, and under the lock when it is recorded meanwhile
        foreach (['before the plan' => false, 'under the lock' => true] as $when => $meanwhile) {
            $this->setUp();
            $spec = self::spec();
            $foreign = null;
            if ($meanwhile) {
                $this->whilePlanned = function () use ($spec, &$foreign) {
                    $foreign = $this->recordWithToken($spec, 8);
                };
            } else {
                $foreign = $this->recordWithToken($spec, 8);
            }
            $result = $this->launch($spec);
            $this->assertSame([null, ["Run #$foreign, which is not a run of this change, holds this launch's one-time token: nothing was launched."]],
                [$result['run_id'], $result['errors']], $when);
            $this->assertSame([], $this->runner->launched, $when);
            $this->assertSame(RunStatus::PENDING, $this->runs->find((int) $foreign)['status'], "$when: the other run is left as it is");
            $this->whilePlanned = null;
        }

        // a dry run of the same change does not belong to its batch either
        $this->setUp();
        $spec = self::spec();
        $dryRun = $this->recordWithToken($spec, 7, RunSpec::DRY_RUN, null);
        $this->assertSame([null, ["Run #$dryRun, which is not a run of this change, holds this launch's one-time token: nothing was launched."]],
            array_values(array_intersect_key($this->launch($spec), array_flip(['run_id', 'errors']))));

        // its own run is the launch's, as it is: nothing launched again
        $this->setUp();
        $spec = self::spec();
        $own = $this->recordWithToken($spec, 7);
        $result = $this->launch($spec);
        $this->assertSame([$own, true, []], [$result['run_id'], $result['existing'], $this->runner->launched]);
    }

    public function testADiagnosticRunCarriesItsFormsTokenAndRunsInCheckMode(): void
    {
        $token = str_repeat('ab', 16);
        $result = $this->launcher()->launch((object) ['user_id' => 1, 'username' => 'alice'], '11', [501], [], $token, RunSpec::diagnostic());
        $this->assertSame([[], false], [$result['errors'], $result['existing']]);
        $run = $this->runs->find((int) $result['run_id']);
        $this->assertSame([null, 'diagnostic', null, 'diagnostic run', $token], [$run['change_id'], $run['kind'], $run['batch'], $run['context'], $run['request_token']],
            'a run of no change, with the form\'s one-time token');
        $request = $this->runner->launched[0];
        $this->assertSame([true, true, null], [$request->check, $request->diff, $request->commit], 'in check mode with diffs, as a dry run, on the runner\'s latest commit');

        // the same form submitted again: that run, nothing launched again
        $again = $this->launcher()->launch((object) ['user_id' => 1, 'username' => 'alice'], '11', [501], [], $token, RunSpec::diagnostic());
        $this->assertSame([(int) $result['run_id'], true], [$again['run_id'], $again['existing']]);
        $this->assertCount(1, $this->runner->launched);

        // a token that is no form's is refused before anything is recorded
        $this->assertSame(['The launch form is out of date: reload the page.'],
            $this->launcher()->launch((object) ['user_id' => 1, 'username' => 'alice'], '11', [501], [], 'x', RunSpec::diagnostic())['errors']);
    }

    public function testARunOfAChangeThatHoldsAFormsTokenIsNotADiagnosticRun(): void
    {
        $token = str_repeat('cd', 16);
        $batch = $this->runs->create(['user_id' => 1, 'username' => 'alice', 'runner' => 'semaphore', 'runner_project' => 3, 'template_id' => '11',
            'template_name' => 'Port description', 'playbook' => 'desc.yml', 'limit' => 'lab-sw1.netops.lab', 'variables' => [], 'request_token' => $token,
            'launch_nonce' => 'abcdef123456', 'status' => RunStatus::PENDING, 'change_id' => 7, 'kind' => RunSpec::BATCH, 'batch' => 1, 'context' => 'x'],
            [501 => ['inventory_name' => 'lab-sw1.netops.lab', 'hostname' => 'lab-sw1.netops.lab', 'display' => 'lab-sw1']]);

        $result = $this->launcher()->launch((object) ['user_id' => 1, 'username' => 'alice'], '11', [501], [], $token, RunSpec::diagnostic());
        $this->assertSame([null, ["Run #$batch, which is not a diagnostic run, holds this launch's one-time token: nothing was launched."]],
            [$result['run_id'], $result['errors']]);
        $this->assertSame([], $this->runner->launched);
    }
}
