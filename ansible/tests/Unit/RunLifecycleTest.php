<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runner\RunnerTask;
use Agoodley\LibrenmsAnsible\Runner\RunnerTaskSearch;
use Agoodley\LibrenmsAnsible\Runs\OutputBuffer;
use Agoodley\LibrenmsAnsible\Runs\RunDiagnosis;
use Agoodley\LibrenmsAnsible\Runs\RunLifecycle;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Runs\TaskLines;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeRunner;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\MemoryRunStore;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\RecordingEvents;
use PHPUnit\Framework\TestCase;

/**
 * The run lifecycle with a fake runner and an in-memory store: every transition, and that no run ends (or frees its
 * devices) on ambiguous evidence.
 */
class RunLifecycleTest extends TestCase
{
    private const TARGETS = [501 => 'lab-sw1.netops.lab', 502 => 'lab-sw2.netops.lab', 503 => 'lab-sw3.netops.lab'];

    private MemoryRunStore $store;

    private FakeRunner $runner;

    private RecordingEvents $events;

    private int $t0;

    protected function setUp(): void
    {
        $this->store = new MemoryRunStore();
        $this->runner = new FakeRunner();
        $this->events = new RecordingEvents();
        $this->t0 = strtotime('2026-10-22 10:00:00');
        $this->runner->startedAt = $this->t0 + 1; // a task that left the queue has a start time
    }

    private function lifecycle(int $outputMax = 1048576, int $stopAfter = 86400): RunLifecycle
    {
        return new RunLifecycle($this->store, fn () => $this->runner, $this->events, $outputMax, $stopAfter,
            fn (array $names) => array_intersect_key(['lab-sw9.netops.lab' => ['device_id' => 509, 'hostname' => 'lab-sw9.netops.lab', 'display' => 'lab-sw9']],
                array_flip($names)));
    }

    /** A run of the three switches whose task the runner has. */
    private function running(string $status = 'running', array $run = []): int
    {
        $id = $this->store->add($run + ['runner_task_id' => '90', 'status' => 'running', 'started_at' => '2026-10-22 10:00:01'], self::TARGETS);
        $this->runner->tasks['90'] = $status;

        return $id;
    }

    /**
     * A log with a PLAY RECAP.
     *
     * @param  array<string, string>  $hosts  host => recap counters "ok=2 changed=1 unreachable=0 failed=0"
     * @return string[]
     */
    private static function recapLog(array $hosts, array $before = []): array
    {
        $lines = array_merge(['PLAY [Show version] ************************************************************', '',
            'TASK [show version] ************************************************************'], $before);
        foreach ($hosts as $host => $counts) {
            $lines[] = (str_contains($counts, 'changed=1') ? 'changed' : 'ok') . ": [$host]";
        }
        $lines[] = '';
        $lines[] = 'PLAY RECAP *********************************************************************';
        foreach ($hosts as $host => $counts) {
            $lines[] = "$host : $counts skipped=0 rescued=0 ignored=0";
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * Refresh until the run is final (the runner's end settles over two refreshes), at most $times.
     */
    private function settle(RunLifecycle $lifecycle, int $id, int $now, int $times = 4): array
    {
        $run = $this->store->find($id);
        for ($i = 0; $i < $times && ! \Agoodley\LibrenmsAnsible\Runs\RunStatus::isFinal($run['status']); $i++) {
            $run = $lifecycle->refresh($id, $now + $i * 3);
        }

        return $run;
    }

    // ------------------------------------------------------------------ launch

    public function testALaunchTheRunnerAnsweredIsFollowedWithItsStartedEntries(): void
    {
        $id = $this->store->add([], self::TARGETS);

        $this->assertTrue($this->lifecycle()->launched($id, new RunnerTask('90', 'queued', 'waiting'), $this->t0));
        $run = $this->store->find($id);
        $this->assertSame('90', $run['runner_task_id']);
        $this->assertSame('queued', $run['status']);
        $this->assertCount(3, $this->events->entries);
        $this->assertSame('alice', $this->events->entries[0]['user'], 'attributed to who launched the run');
        $this->assertStringStartsWith("Ansible run #$id started: template 'Show version' by alice", $this->events->entries[0]['message']);
        $this->assertFalse($this->lifecycle()->launched($id, new RunnerTask('91', 'queued', 'waiting'), $this->t0), 'a run takes one task, once');
    }

    public function testALaunchTheRunnerRefusedWasNotLaunched(): void
    {
        $id = $this->store->add([], self::TARGETS);

        $this->lifecycle()->launchRefused($id, 'Semaphore UI refused a task of template 11 (HTTP 400): Task queue is full', $this->t0);
        $run = $this->store->find($id);
        $this->assertSame('not_launched', $run['status']);
        $this->assertSame('not_run', $this->store->targets[$id][501]['result']);
        $this->assertSame('warning', $this->events->entries[0]['severity']);
    }

    public function testAnUncertainLaunchIsFoundByItsMessageWithTheNonce(): void
    {
        $id = $this->store->add([], self::TARGETS);
        $lifecycle = $this->lifecycle();
        $lifecycle->launchUncertain($id, 'Cannot reach Semaphore UI: timed out', $this->t0);
        $this->assertSame('pending', $this->store->find($id)['status']);

        $this->runner->search = new RunnerTaskSearch(new RunnerTask('90', 'running', 'running', $this->t0 + 1), true);
        $run = $lifecycle->refresh($id, $this->t0 + 5);
        $this->assertSame('90', $run['runner_task_id']);
        $this->assertSame('running', $run['status']);
        $this->assertContains("find LibreNMS Ansible run #$id by alice [abcdef123456]", $this->runner->calls);
        $this->assertCount(3, $this->events->entries);
    }

    public function testAnUncertainLaunchIsNotGivenUpWhileTheSearchFailsOrDoesNotReachBack(): void
    {
        $id = $this->store->add([], self::TARGETS);
        $lifecycle = $this->lifecycle();
        $lifecycle->launchUncertain($id, 'timed out', $this->t0);

        // the search fails at the timeout: nothing is known, the run keeps its devices
        $this->runner->search = new RunnerError(RunnerError::UNREACHABLE, 'Cannot reach Semaphore UI: refused');
        $run = $lifecycle->refresh($id, $this->t0 + RunLifecycle::LAUNCH_TIMEOUT + 60);
        $this->assertSame('pending', $run['status']);
        $this->assertStringContainsString('keeps its devices', $run['poll_error']);

        // the list of tasks does not reach back to the launch (too many tasks since): not conclusive either
        $this->runner->search = new RunnerTaskSearch(null, false);
        $this->assertSame('pending', $lifecycle->refresh($id, $this->t0 + RunLifecycle::LAUNCH_TIMEOUT + 120)['status']);
        $this->assertSame([], $this->events->entries);

        // a complete search that finds nothing, after the timeout: not launched
        $this->runner->search = new RunnerTaskSearch(null, true);
        $run = $lifecycle->refresh($id, $this->t0 + RunLifecycle::LAUNCH_TIMEOUT + 180);
        $this->assertSame('not_launched', $run['status']);
        $this->assertStringContainsString('has no task for it', $run['error']);
    }

    public function testACompleteSearchBeforeTheTimeoutWaits(): void
    {
        $id = $this->store->add([], self::TARGETS);
        $lifecycle = $this->lifecycle();
        $lifecycle->launchUncertain($id, 'timed out', $this->t0);
        $this->runner->search = new RunnerTaskSearch(null, true);

        $this->assertSame('pending', $lifecycle->refresh($id, $this->t0 + 60)['status']);
        // a launch whose request is still at it is not looked for before the grace period
        $quiet = $this->store->add([], self::TARGETS);
        $lifecycle->refresh($quiet, $this->t0 + 30);
        $this->assertNotContains("find LibreNMS Ansible run #$quiet by alice [abcdef123456]", $this->runner->calls);
    }

    // ------------------------------------------------------------------ following and ending

    public function testARunEndsWithSuccessOnceTheLogSettled(): void
    {
        $id = $this->running();
        $lifecycle = $this->lifecycle();
        $this->runner->logs['90'] = ['PLAY [Show version] ****', ''];
        $this->assertSame('running', $lifecycle->refresh($id, $this->t0 + 10)['status']);

        $this->runner->tasks['90'] = 'success';
        $this->runner->logs['90'] = self::recapLog(['lab-sw1.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0',
            'lab-sw2.netops.lab' => 'ok=1 changed=1 unreachable=0 failed=0', 'lab-sw3.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0']);
        $run = $lifecycle->refresh($id, $this->t0 + 20);
        $this->assertSame('running', $run['status'], 'the runner reported the end with new lines: the log may not be complete yet');
        $this->assertNotNull($run['runner_final_at']);

        $run = $lifecycle->refresh($id, $this->t0 + 23);
        $this->assertSame('success', $run['status']);
        $this->assertSame(['changed' => 1, 'ok' => 2], $run['summary']);
        $this->assertSame('changed', $this->store->targets[$id][502]['result']);
        $this->assertSame(1, $this->store->targets[$id][502]['counts']['changed']);
        $this->assertCount(3, $this->events->entries);
        $this->assertSame('notice', $this->events->of(502)[0]['severity']);
        $this->assertSame('alice', $this->events->of(502)[0]['user']);
        $this->assertStringContainsString('PLAY RECAP', $run['output']);

        $lifecycle->refresh($id, $this->t0 + 30);
        $this->assertCount(3, $this->events->entries, 'an ended run is not ended again');
    }

    public function testASuccessfulTaskWithTargetsThatDidNotRunIsIncompleteWithTheCause(): void
    {
        // the inventory script failed: Ansible found no host and still ended with success
        $id = $this->running('success', ['runner_final_at' => '2026-10-22 10:00:30']);
        $this->runner->logs['90'] = ["\e[1;35m[WARNING]: Failed to parse inventory with 'script' plugin: Inventory script returned non-zero exit code 1.\e[0m",
            "\e[1;35mStandard error from inventory script:\e[0m",
            "\e[1;35mlibrenms_inventory: LibreNMS refused the API token (HTTP 401): check LIBRENMS_TOKEN (it may have been deleted or have expired)\e[0m",
            "\e[1;35m\e[0m", "\e[1;35m[WARNING]: Could not match supplied host pattern, ignoring: lab-sw1.netops.lab\e[0m", '',
            'PLAY [Show version] ****', "\e[0;36mskipping: no hosts matched\e[0m", '', 'PLAY RECAP ****', ''];

        $run = $this->lifecycle()->refresh($id, $this->t0 + 100);
        $this->assertSame('incomplete', $run['status']);
        $this->assertSame(['not_run' => 3], $run['summary']);
        $this->assertStringStartsWith('3 of 3 targets did not run: the runner reported success, but the PLAY RECAP does not name them. The inventory script failed: '
            . '"librenms_inventory: LibreNMS refused the API token (HTTP 401)', $run['error']);
        $this->assertStringContainsString('LIBRENMS_TOKEN', $run['error']);
        $this->assertSame('warning', $this->events->of(501)[0]['severity']);
        $this->assertStringStartsWith("Ansible run #$id was incomplete: not run", $this->events->of(501)[0]['message']);
    }

    public function testAStoppedRunNamesTheTaskItWasInAndHowLongItsDevicesAreHeld(): void
    {
        $lines = ['PLAY [Long run] ****', '', 'TASK [Set the description] ****', 'changed: [lab-sw3.netops.lab]', '', "\e[0;33mTASK [Wait] ****\e[0m"];
        $id = $this->store->add(['runner_task_id' => '205', 'status' => 'stopping', 'stopped_by' => 'bob', 'runner_final_at' => '2026-10-22 10:00:30'],
            [503 => 'lab-sw3.netops.lab']);
        $this->runner->tasks['205'] = 'stopped';
        $this->runner->logs['205'] = $lines;
        $this->runner->endedAt = $this->t0 + 30;

        $run = $this->lifecycle()->refresh($id, $this->t0 + 100);
        $this->assertSame('stopped', $run['status']);
        $this->assertSame('2026-10-22 10:30:30', $run['held_until'], 'held for the Hold after a stop setting after the runner\'s stop');
        $this->assertTrue(RunLifecycle::held($run, $this->t0 + 1000));
        $this->assertFalse(RunLifecycle::held($run, $this->t0 + 1830));
        $this->assertSame('Wait', TaskLines::inProgress(OutputBuffer::display($run), ['lab-sw3.netops.lab'])['task']);
        $this->assertStringEndsWith('(stopped by bob; the step in progress may still run on the runner: the devices are held until 2026-10-22 10:30)',
            $this->events->of(503)[0]['message']);

        $note = RunDiagnosis::afterStop($run, 'Wait', $this->t0 + 1000);
        $this->assertStringStartsWith('The run was stopped during the task "Wait". Semaphore UI stops only Ansible\'s main process: the step each target was in runs on', $note);
        $this->assertStringContainsString('with all its loop items, and a pause keeps waiting until that process is killed', $note);
        $this->assertStringContainsString('The devices stay held until 2026-10-22 10:30 because that step can still run on the runner: check the runner for the '
            . 'ansible-playbook processes of this run (task #205), end them, and release the devices when it is done.', $note);
        $this->assertStringContainsString('The plugin held its devices until 2026-10-22 10:30', RunDiagnosis::afterStop($run, 'Wait', $this->t0 + 1900));
    }

    public function testASuccessfulTaskWithOneTargetMissingIsIncomplete(): void
    {
        $id = $this->running('success', ['runner_final_at' => '2026-10-22 10:00:30']);
        $this->runner->logs['90'] = array_merge(["\e[1;35m[WARNING]: Could not match supplied host pattern, ignoring: lab-sw3.netops.lab\e[0m"],
            self::recapLog(['lab-sw1.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0', 'lab-sw2.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0']));

        $run = $this->lifecycle()->refresh($id, $this->t0 + 100);
        $this->assertSame('incomplete', $run['status']);
        $this->assertSame(['not_run' => 1, 'ok' => 2], $run['summary']);
        $this->assertStringContainsString("The runner's inventory did not have lab-sw3.netops.lab", $run['error']);
    }

    public function testAStoppedRunWithoutARecapTakesItsResultsFromTheTaskLinesUnknownWhereAStepWasInProgress(): void
    {
        // stopped during a pause after the change: lab-sw3 changed and lab-sw1 was ok in the task before, both are in the
        // pause, which runs on; lab-sw2 was never named (the runner did not find it), so it did not run
        $id = $this->store->add(['runner_task_id' => '90', 'status' => 'stopping', 'stopped_by' => 'bob', 'runner_final_at' => '2026-10-22 10:00:30'],
            self::TARGETS);
        $this->runner->tasks['90'] = 'stopped';
        $this->runner->logs['90'] = ['TASK [set description] ****', 'ok: [lab-sw1.netops.lab]', 'changed: [lab-sw3.netops.lab]',
            'TASK [pause] ****', 'Pausing for 30 seconds'];

        $run = $this->lifecycle()->refresh($id, $this->t0 + 100);
        $this->assertSame('stopped', $run['status']);
        $results = array_map(fn ($t) => $t['result'], $this->store->targets[$id]);
        $this->assertSame([501 => 'unknown', 502 => 'not_run', 503 => 'unknown'], $results);
        $this->assertSame(['unknown' => 2, 'not_run' => 1], $run['summary']);
        $this->assertStringContainsString('was stopped: unknown', $this->events->of(503)[0]['message']);
        $this->assertStringContainsString('(stopped by bob; the step in progress may still run on the runner: the devices are held until',
            $this->events->of(503)[0]['message']);
    }

    public function testALoopStoppedAfterItsFirstItemIsUnknownAndHoldsItsDevicesUntilReleased(): void
    {
        // Semaphore reports "stopped" after the first of six loop items: the worker goes on with the others, in neither log
        $id = $this->running('running', ['stopped_by' => 'bob', 'status' => 'stopping']);
        $this->runner->logs['90'] = ['PLAY [Loop change] ****', '', 'TASK [Set the description, six times] ****', 'changed: [lab-sw2.netops.lab] => (item=0)'];
        $lifecycle = $this->lifecycle();
        $this->assertSame('stopping', $lifecycle->refresh($id, $this->t0 + 10)['status']);
        $this->runner->tasks['90'] = 'stopped';
        $this->runner->endedAt = $this->t0 + 14;

        $run = $this->settle($lifecycle, $id, $this->t0 + 15);
        $this->assertSame('stopped', $run['status']);
        $this->assertSame('unknown', $this->store->targets[$id][502]['result'], 'not "changed": the loop goes on after the stop');
        $this->assertSame(['unknown' => 3], $run['summary'], 'the other targets were in that step too');
        $this->assertSame(date('Y-m-d H:i:s', $this->t0 + 14 + 1800), $run['held_until']);
        $this->assertTrue(RunLifecycle::held($run, $this->t0 + 60), 'the devices stay busy after the runner said "stopped"');
        $this->assertSame([], $this->runner->stops, 'a run stopping on the runner is not asked to stop again');

        // more refreshes change nothing: the run has ended, the hold stays
        $this->assertSame($run, $lifecycle->refresh($id, $this->t0 + 120));

        // an admin checked the runner: the devices are released at once, and it is logged
        $events = count($this->events->entries);
        $this->assertNull($lifecycle->release($id, 'carol', $this->t0 + 300));
        $run = $this->store->find($id);
        $this->assertSame('stopped', $run['status']);
        $this->assertFalse(RunLifecycle::held($run, $this->t0 + 301));
        $this->assertSame(date('Y-m-d H:i:s', $this->t0 + 300), $run['held_until']);
        $this->assertSame('carol', $run['released_by']);
        $released = array_slice($this->events->entries, $events);
        $this->assertCount(3, $released);
        $this->assertSame('warning', $released[0]['severity']);
        $this->assertStringStartsWith("Ansible run #$id: the devices held after its stop were released by carol (held until " . date('Y-m-d H:i', $this->t0 + 14 + 1800)
            . '; the step in progress at the stop may still run on the runner)', $released[0]['message']);
        $this->assertSame('The devices of this run are not held any more.', $lifecycle->release($id, 'carol', $this->t0 + 310));
        $this->assertStringContainsString('Its devices were released by carol at ' . date('Y-m-d H:i', $this->t0 + 300), RunDiagnosis::afterStop($run, null, $this->t0 + 400));
    }

    public function testAStopBetweenTwoTasksKeepsTheResultsOfTheLog(): void
    {
        $id = $this->running('stopped', ['stopped_by' => 'bob', 'status' => 'stopping', 'runner_final_at' => '2026-10-22 10:00:30']);
        $this->runner->logs['90'] = ['TASK [Set the description] ****', 'changed: [lab-sw1.netops.lab]', 'ok: [lab-sw2.netops.lab]', 'changed: [lab-sw3.netops.lab]'];

        $run = $this->lifecycle()->refresh($id, $this->t0 + 100);
        $this->assertSame(['unknown' => 1, 'changed' => 2], $run['summary'], 'every step had its result: nothing runs on');
        $this->assertNotNull($run['held_until'], 'a stopped run holds its devices all the same');
    }

    public function testATaskStoppedInTheRunnersQueueHoldsNothing(): void
    {
        // a second launch of a template waits for the first; stopped there, it never starts
        $id = $this->store->add(['runner_task_id' => '92', 'status' => 'stopping', 'stopped_by' => 'bob', 'runner_final_at' => '2026-10-22 10:00:30'], self::TARGETS);
        $this->runner->tasks['92'] = 'waiting';
        $this->assertNull($this->lifecycle()->refresh($id, $this->t0 + 20)['started_at'], 'no start time while queued');
        $this->runner->tasks['92'] = 'stopped';
        $this->runner->neverStarted = true;
        $this->runner->logs['92'] = [];

        $run = $this->settle($this->lifecycle(), $id, $this->t0 + 100);
        $this->assertSame('stopped', $run['status']);
        $this->assertNull($run['held_until'], 'nothing runs on: the devices are free at once');
        $this->assertFalse(RunLifecycle::held($run, $this->t0 + 101));
        $this->assertSame(['not_run' => 3], $run['summary']);
        $this->assertStringEndsWith('(stopped by bob before the runner started it)', $this->events->of(501)[0]['message']);
        $this->assertStringNotContainsString('may still run', $this->events->of(501)[0]['message']);
        $this->assertSame('The run was stopped before the runner started it: nothing ran, and its devices were free at once.',
            RunDiagnosis::afterStop($run, null, $this->t0 + 101));
        $this->assertSame('The devices of this run are not held any more.', $this->lifecycle()->release($id, 'carol', $this->t0 + 110));

        // a task that started holds its devices even when its log shows no step yet
        $started = $this->store->add(['runner_task_id' => '93', 'status' => 'stopping', 'stopped_by' => 'bob', 'runner_final_at' => '2026-10-22 10:00:30'], self::TARGETS);
        $this->runner->tasks['93'] = 'stopped';
        $this->runner->neverStarted = false;
        $this->runner->logs['93'] = ['PLAY [Show version] ****'];
        $this->assertNotNull($this->lifecycle()->refresh($started, $this->t0 + 100)['held_until']);
    }

    public function testStepsInProgressAtTheEndOfALog(): void
    {
        $hosts = ['a', 'b', 'c'];
        $this->assertSame(['task' => 'one', 'hosts' => ['a', 'b', 'c']], TaskLines::inProgress(['PLAY [p] ****', 'TASK [one] ****'], $hosts),
            'the first task of the play, no result yet');
        $this->assertSame(['task' => 'two', 'hosts' => ['a']], TaskLines::inProgress(['TASK [one] ****', 'ok: [a]', 'ok: [b]', 'TASK [two] ****', "\e[0;33mchanged: [a] => (item=1)\e[0m",
            'failed: [a] (item=2) => {"msg": "x"}', 'skipping: [b]'], $hosts), 'a loop partly done; c was never named');
        $this->assertSame(['task' => 'two', 'hosts' => ['b']], TaskLines::inProgress(['TASK [one] ****', 'ok: [a]', 'ok: [b]', 'TASK [two] ****',
            'FAILED - RETRYING: [b]: two (3 retries left).', 'ok: [a -> localhost]'], $hosts), 'retries are not a result; delegation is');
        $this->assertSame(['task' => null, 'hosts' => []], TaskLines::inProgress(['TASK [one] ****', 'included: /x/y.yml for a, b, c'], $hosts));
        $this->assertSame(['task' => null, 'hosts' => []], TaskLines::inProgress(['TASK [one] ****', 'ok: [a]', '', 'PLAY RECAP ****', 'a : ok=1 changed=0 unreachable=0 failed=0'],
            $hosts), 'a log with a PLAY RECAP has none');
        $this->assertSame(['task' => null, 'hosts' => []], TaskLines::inProgress(['PLAY [one] ****', 'TASK [x] ****', 'ok: [a]', 'PLAY [two] ****'], $hosts),
            'a play that has not started a task yet');
        $this->assertSame(['task' => 'restart', 'hosts' => ['a']], TaskLines::inProgress(['TASK [one] ****', 'changed: [a]', 'RUNNING HANDLER [restart] ****'], $hosts),
            'a handler is a step too');
        $this->assertSame(['task' => null, 'hosts' => []], TaskLines::inProgress([], $hosts));
    }

    public function testAStaleRefreshNeverWritesOverANewerOne(): void
    {
        // a page poll reads the run, its runner answer comes late; meanwhile the sync command ends the run
        $id = $this->running('success', ['runner_final_at' => '2026-10-22 10:00:30']);
        $this->runner->logs['90'] = self::recapLog(['lab-sw1.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0',
            'lab-sw2.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0', 'lab-sw3.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0']);
        $slow = $this->lifecycle();
        $this->store->beforeUpdate = function () use ($id): void {
            $this->runner->tasks['90'] = 'success';
            $this->lifecycle()->refresh($id, $this->t0 + 100); // the sync command, in between
        };
        $run = $slow->refresh($id, $this->t0 + 100);

        $this->assertSame('success', $run['status']);
        $this->assertSame(1, $this->store->conflicts, 'the slow refresh found the run changed and wrote nothing');
        $this->assertCount(3, $this->events->entries, 'one end entry per target');

        // the same with a slow refresh of an unfinished run: it cannot set the ended run back to running
        $other = $this->running('running');
        $this->store->beforeUpdate = function () use ($other): void {
            $this->store->runs[$other]['status'] = 'success';
            $this->store->runs[$other]['version']++;
        };
        $this->assertSame('success', $this->lifecycle()->refresh($other, $this->t0 + 10)['status']);
    }

    public function testHostsTheRunnerRanOnThatWereNotTargetsAreRecordedOnTheirDevices(): void
    {
        $id = $this->store->add(['runner_task_id' => '90', 'status' => 'running', 'runner_final_at' => '2026-10-22 10:00:30'], [501 => 'lab-sw1.netops.lab']);
        $this->runner->tasks['90'] = 'success';
        $this->runner->logs['90'] = self::recapLog(['lab-sw1.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0',
            'lab-sw9.netops.lab' => 'ok=1 changed=1 unreachable=0 failed=0', 'ghost.example' => 'ok=1 changed=0 unreachable=0 failed=0']);

        $run = $this->lifecycle()->refresh($id, $this->t0 + 100);
        $this->assertSame(['ghost.example', 'lab-sw9.netops.lab'], $run['unexpected_hosts']);
        $this->assertTrue($this->store->targets[$id][509]['unexpected']);
        $this->assertSame('changed', $this->store->targets[$id][509]['result']);
        $device = $this->events->of(509);
        $this->assertCount(1, $device);
        $this->assertSame('error', $device[0]['severity']);
        $this->assertStringContainsString('ran on this device although it was not a target (as lab-sw9.netops.lab): changed', $device[0]['message']);
        $this->assertStringContainsString('ghost.example', $this->events->of(null)[0]['message']);
    }

    // ------------------------------------------------------------------ missing tasks and lost runs

    public function testOneNotFoundIsNotEnoughToLoseARun(): void
    {
        $id = $this->running();
        unset($this->runner->tasks['90']); // 404
        $lifecycle = $this->lifecycle();

        $run = $lifecycle->refresh($id, $this->t0 + 10);
        $this->assertSame('running', $run['status']);
        $this->assertSame(1, $run['missing_checks']);
        $this->assertStringContainsString('does not find task #90', $run['poll_error']);

        // the token is out of the project for a moment: not counted
        $this->runner->role = new RunnerError(RunnerError::NOT_FOUND, 'not a member');
        $run = $lifecycle->refresh($id, $this->t0 + 400);
        $this->assertSame('running', $run['status']);
        $this->assertSame(1, $run['missing_checks']);

        // back in the project, the task found again: the count starts over
        $this->runner->role = 'task_runner';
        $this->runner->tasks['90'] = 'running';
        $run = $lifecycle->refresh($id, $this->t0 + 410);
        $this->assertSame(0, $run['missing_checks']);
        $this->assertNull($run['missing_since']);
        $this->assertSame([], $this->events->entries);
    }

    public function testATaskMissingForMinutesWhileTheProjectIsVisibleIsLostThenWatched(): void
    {
        $id = $this->running('running', ['output' => "TASK [x] ****\nok: [lab-sw1.netops.lab]", 'output_lines' => 2]);
        unset($this->runner->tasks['90']);
        $lifecycle = $this->lifecycle();
        $lifecycle->refresh($id, $this->t0 + 10);
        $lifecycle->refresh($id, $this->t0 + 20);
        $run = $lifecycle->refresh($id, $this->t0 + 200);
        $this->assertSame('running', $run['status'], 'three times, but not yet five minutes');

        $run = $lifecycle->refresh($id, $this->t0 + 400);
        $this->assertSame('lost', $run['status']);
        $this->assertStringContainsString('the runner no longer has task #90 (not found 4 times', $run['error']);
        $this->assertSame(['unknown' => 3], $run['summary'], 'the plugin lost track: what the log does not say is unknown');
        $this->assertNotNull($run['watch_until']);

        // the runner has the task again, finished: the real end is recorded
        $this->runner->tasks['90'] = 'success';
        $this->runner->logs['90'] = self::recapLog(['lab-sw1.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0',
            'lab-sw2.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0', 'lab-sw3.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0']);
        $this->assertSame('lost', $lifecycle->refresh($id, $this->t0 + 500)['status'], 'watched every few minutes only');
        $run = $lifecycle->refresh($id, $this->t0 + 400 + RunLifecycle::WATCH_INTERVAL);
        $this->assertSame('success', $run['status']);
        $this->assertNull($run['watch_until']);
        $this->assertStringContainsString('the runner reported this end after the plugin had given the run up as lost', $this->events->of(501)[1]['message']);
    }

    public function testAWatchedRunWhoseTaskRunsAgainIsFollowedAgain(): void
    {
        $id = $this->running('running', ['status' => 'lost', 'error' => 'the runner no longer has task #90', 'finished_at' => '2026-10-22 10:05:00',
            'watch_until' => '2026-10-29 10:05:00', 'polled_at' => '2026-10-22 10:05:00']);

        $run = $this->lifecycle()->refresh($id, $this->t0 + 3600);
        $this->assertSame('running', $run['status'], 'its devices are busy again');
        $this->assertNull($run['finished_at']);
        $this->assertStringStartsWith("Ansible run #$id: the runner has its task #90 again, unfinished", $this->events->of(502)[0]['message']);
    }

    public function testARunReleasedBeforeItsTaskWasKnownIsStillLookedForAndItsLateEndRecorded(): void
    {
        // an uncertain launch, the task list unreadable for a quarter of an hour: an admin released the devices
        $id = $this->store->add(['poll_error' => 'The launch did not complete: the plugin looks for its task in the runner.'], self::TARGETS);
        $this->runner->search = new RunnerError(RunnerError::BAD_RESPONSE, 'Semaphore UI failed on the latest tasks (HTTP 503).');
        $lifecycle = $this->lifecycle();
        $lifecycle->refresh($id, $this->t0 + 960);
        $this->assertNull($lifecycle->release($id, 'carol', $this->t0 + 970));
        $run = $this->store->find($id);
        $this->assertSame(['released', null], [$run['status'], $run['runner_task_id']]);
        $this->assertTrue(RunLifecycle::watched($run, $this->t0 + 960 + RunLifecycle::WATCH_INTERVAL), 'watched although it has no task id');

        // still nothing: asked again every few minutes
        $this->runner->search = new RunnerTaskSearch(null, true);
        $this->assertSame('released', $lifecycle->refresh($id, $this->t0 + 980 + RunLifecycle::WATCH_INTERVAL)['status']);
        $this->assertFalse(RunLifecycle::watched($this->store->find($id), $this->t0 + 990 + RunLifecycle::WATCH_INTERVAL));

        // the runner had started it after all (the nonce in its message tells it is this run's), and it changed lab-sw3
        $this->runner->search = new RunnerTaskSearch(new RunnerTask('305', RunStatus::SUCCESS, 'success'), true);
        $this->runner->tasks['305'] = 'success';
        $this->runner->logs['305'] = self::recapLog(['lab-sw1.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0',
            'lab-sw2.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0', 'lab-sw3.netops.lab' => 'ok=2 changed=1 unreachable=0 failed=0']);
        $run = $lifecycle->refresh($id, $this->t0 + 980 + 2 * RunLifecycle::WATCH_INTERVAL);
        $this->assertSame(['success', '305'], [$run['status'], $run['runner_task_id']]);
        $this->assertSame('changed', $this->store->targets[$id][503]['result']);
        $this->assertStringContainsString("find LibreNMS Ansible run #$id by alice [abcdef123456]", implode("\n", $this->runner->calls));
        $this->assertStringContainsString('the runner reported this end after the plugin had given the run up as released', $this->events->of(503)[1]['message']);
    }

    public function testATaskFoundForAReleasedRunThatStillRunsIsFollowedAgain(): void
    {
        $id = $this->store->add(['status' => 'released', 'released_by' => 'carol', 'finished_at' => '2026-10-22 10:16:00', 'watch_until' => '2026-10-29 10:16:00',
            'polled_at' => '2026-10-22 10:16:00'], self::TARGETS);
        $this->runner->search = new RunnerTaskSearch(new RunnerTask('305', RunStatus::RUNNING, 'running'), true);
        $this->runner->tasks['305'] = 'running';

        $run = $this->lifecycle()->refresh($id, $this->t0 + 3600);
        $this->assertSame(['running', '305'], [$run['status'], $run['runner_task_id']], 'its devices are busy again');
        $this->assertStringStartsWith("Ansible run #$id: the runner has a task for it after all, #305, unfinished: the plugin follows it again (it was released)",
            $this->events->of(501)[0]['message']);
    }

    public function testALateEndIsRecordedOnceTheRunnersLogSettled(): void
    {
        // Semaphore reports the end before the PLAY RECAP can be read
        $id = $this->running('success', ['status' => 'lost', 'error' => 'the runner no longer has task #90', 'finished_at' => '2026-10-22 10:05:00',
            'watch_until' => '2026-10-29 10:05:00', 'polled_at' => '2026-10-22 10:05:00']);
        $this->runner->endedAt = $this->t0 + 3598;
        $this->runner->logs['90'] = ['PLAY [Show version] ****', 'TASK [show version] ****', 'ok: [lab-sw1.netops.lab]'];
        $lifecycle = $this->lifecycle();

        $run = $lifecycle->refresh($id, $this->t0 + 3600);
        $this->assertSame('lost', $run['status'], 'not from a log that may not be complete');
        $this->assertNotNull($run['runner_final_at']);
        $this->assertFalse(RunLifecycle::watched($run, $this->t0 + 3602));
        $this->assertTrue(RunLifecycle::watched($run, $this->t0 + 3600 + RunLifecycle::SETTLE_POLL), 'asked again a few seconds later');

        $this->runner->logs['90'] = self::recapLog(['lab-sw1.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0',
            'lab-sw2.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0', 'lab-sw3.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0']);
        $run = $lifecycle->refresh($id, $this->t0 + 3605);
        $this->assertSame('success', $run['status'], 'with its PLAY RECAP');
        $this->assertSame(['ok' => 3], $run['summary']);

        // a log that never gets a recap (a stopped task) is taken a minute after the end
        $other = $this->running('stopped', ['status' => 'lost', 'runner_task_id' => '91', 'watch_until' => '2026-10-29 10:05:00', 'polled_at' => '2026-10-22 10:05:00']);
        $this->runner->tasks['91'] = 'stopped';
        $this->runner->logs['91'] = ['TASK [show version] ****', 'ok: [lab-sw1.netops.lab]'];
        $this->assertSame('lost', $lifecycle->refresh($other, $this->t0 + 3610)['status']);
        $this->assertSame('stopped', $lifecycle->refresh($other, $this->t0 + 3600 + RunLifecycle::SETTLE)['status']);
    }

    // ------------------------------------------------------------------ stops, the Stop after setting, release

    public function testARunPastStopAfterIsStoppedAndKeepsItsDevicesUntilTheRunnerConfirms(): void
    {
        $id = $this->running();
        $lifecycle = $this->lifecycle(1048576, 3600);
        $this->assertSame('running', $lifecycle->refresh($id, $this->t0 + 3000)['status']);

        $run = $lifecycle->refresh($id, $this->t0 + 3700);
        $this->assertSame('stopping', $run['status']);
        $this->assertSame([['90', false]], $this->runner->stops);
        $this->assertSame('stopped by the plugin: still unfinished after 1 hour (Stop after setting)', $run['stop_reason']);

        // not stopped five minutes later: asked again, forced
        $lifecycle->refresh($id, $this->t0 + 3800);
        $this->assertCount(1, $this->runner->stops);
        $lifecycle->refresh($id, $this->t0 + 4100);
        $this->assertSame(['90', true], $this->runner->stops[1]);

        // the runner confirms
        $this->runner->tasks['90'] = 'stopped';
        $run = $this->settle($lifecycle, $id, $this->t0 + 4200);
        $this->assertSame('stopped', $run['status']);
        $this->assertStringContainsString('(stopped by the plugin: still unfinished after 1 hour', $this->events->of(501)[0]['message']);
    }

    public function testAStopThatFailsAtTheTimeoutKeepsTheRunGoing(): void
    {
        $id = $this->running();
        $this->runner->stopError = new RunnerError(RunnerError::UNREACHABLE, 'Cannot reach Semaphore UI');

        $run = $this->lifecycle(1048576, 3600)->refresh($id, $this->t0 + 3700);
        $this->assertSame('running', $run['status']);
        $this->assertStringStartsWith('Still unfinished after 1 hour (Stop after setting): the plugin asked the runner to stop it, which failed', $run['poll_error']);
        $this->assertSame([], $this->events->entries);
    }

    public function testAUserStop(): void
    {
        $id = $this->running();

        $this->assertNull($this->lifecycle()->stop($id, 'bob', $this->t0 + 10));
        $run = $this->store->find($id);
        $this->assertSame('stopping', $run['status']);
        $this->assertSame('bob', $run['stopped_by']);
        $this->assertSame('The run has already ended.', $this->lifecycle()->stop($this->running('success', ['status' => 'success']), 'bob', $this->t0));
    }

    public function testDevicesAreReleasedOnlyWhenTheRunnerCouldNotBeAskedForLong(): void
    {
        $id = $this->running('running', ['runner_seen_at' => '2026-10-22 10:00:10']);
        $lifecycle = $this->lifecycle();
        $this->runner->tasks['90'] = new RunnerError(RunnerError::UNREACHABLE, 'Cannot reach Semaphore UI');
        $lifecycle->refresh($id, $this->t0 + 60);

        $this->assertSame('The plugin still gets answers about this run from the runner: wait for its end, or stop it.', $lifecycle->release($id, 'carol', $this->t0 + 120));
        $this->assertNull($lifecycle->release($id, 'carol', $this->t0 + 10 + RunLifecycle::RELEASE_AFTER));
        $run = $this->store->find($id);
        $this->assertSame('released', $run['status']);
        $this->assertSame('carol', $run['released_by']);
        $this->assertSame(['unknown' => 3], $run['summary']);
        $this->assertStringContainsString('devices released by carol while the runner could not be asked', $this->events->of(501)[0]['message']);
        $this->assertSame('alice', $this->events->of(501)[0]['user']);
    }

    // ------------------------------------------------------------------ the log

    public function testALongLogKeepsItsHeadAndItsTailWithThePlayRecap(): void
    {
        $id = $this->running('success', ['runner_final_at' => '2026-10-22 10:00:30']);
        $noise = array_map(fn ($i) => "  line $i " . str_repeat('x', 60), range(1, 2000));
        $this->runner->logs['90'] = array_merge(['PLAY [Show version] ****'], $noise, self::recapLog(['lab-sw1.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0',
            'lab-sw2.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0', 'lab-sw3.netops.lab' => 'ok=1 changed=0 unreachable=0 failed=0']));

        $run = $this->lifecycle(16384)->refresh($id, $this->t0 + 100);
        $this->assertSame('success', $run['status']);
        $this->assertTrue($run['output_truncated']);
        $this->assertLessThanOrEqual(16384, strlen($run['output']) + strlen($run['output_tail']));
        $display = OutputBuffer::display($run);
        $this->assertSame('PLAY [Show version] ****', $display[0]);
        $this->assertStringContainsString('lines left out here', implode("\n", $display));
        $this->assertStringContainsString('PLAY RECAP', $run['output_tail'], 'the end of the log survives');
        $since = OutputBuffer::since($run, 5);
        $this->assertSame($run['output_lines'], $since['next']);
    }
}
