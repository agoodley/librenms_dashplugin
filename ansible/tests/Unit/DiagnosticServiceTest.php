<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Data\DeviceRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Services\DiagnosticService;
use Agoodley\LibrenmsAnsible\Services\Eventlog;
use Agoodley\LibrenmsAnsible\Services\InventoryService;
use Agoodley\LibrenmsAnsible\Services\LaunchPlan;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeRunner;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\SqliteSql;
use PHPUnit\Framework\TestCase;

/**
 * Diagnostic runs around their launch (which RunLauncherTest checks): what the launch answers the page, a run again from
 * an earlier run, and the output per device from the log as the run keeps it, or as the runner has it when it was cut.
 * The runs are recorded in SQLite; the runner is a fake.
 */
class DiagnosticServiceTest extends TestCase
{
    private RunRepository $runs;

    private FakeRunner $runner;

    protected function setUp(): void
    {
        $this->runs = new RunRepository(new SqliteSql());
        $this->runner = new FakeRunner();
    }

    private function service(?\Closure $launcher = null): DiagnosticService
    {
        $settings = new PluginSettings();

        return new DiagnosticService(new Plugin(), $this->runs, new InventoryService(new DeviceRepository(), $settings), $settings, new Eventlog(), null,
            $launcher, fn () => $this->runner);
    }

    /** A diagnostic run of inst-sw1 and inst-sw2 (and a host it ran on that was no target). */
    private function record(array $run = []): int
    {
        $id = $this->runs->create($run + ['user_id' => 1, 'username' => 'alice', 'runner' => 'semaphore', 'runner_project' => 1, 'template_id' => '3',
            'template_name' => 'Show commands', 'playbook' => 'show_commands.yml', 'limit' => 'inst-sw1,inst-sw2', 'request_token' => bin2hex(random_bytes(16)),
            'variables' => [['name' => 'commands', 'label' => 'Show commands', 'type' => 'text', 'value' => "show version\nshow vlan", 'secret' => false, 'required' => true],
                ['name' => 'enable', 'label' => 'Enable', 'type' => 'secret', 'value' => '********', 'secret' => true, 'required' => false]],
            'status' => RunStatus::SUCCESS, 'kind' => 'diagnostic', 'context' => 'diagnostic run'],
            [1 => ['inventory_name' => 'inst-sw1', 'hostname' => 'inst-sw1', 'display' => 'inst-sw1'], 2 => ['inventory_name' => 'inst-sw2', 'hostname' => 'inst-sw2', 'display' => '']]);
        $this->runs->addUnexpected($id, [9 => ['inventory_name' => 'other', 'hostname' => 'other', 'display' => 'other', 'result' => 'ok', 'counts' => null]]);

        return $id;
    }

    public function testTheLaunchSaysWhatTheAnswersLack(): void
    {
        $plan = new LaunchPlan();
        $plan->variableErrors = ['commands' => 'is required'];
        $refused = $this->service(fn () => ['run_id' => null, 'plan' => $plan, 'errors' => ['Fix the answers to the survey variables.'], 'existing' => false])
            ->launch((object) [], '3', [1], [], str_repeat('a', 32));
        $this->assertSame([null, ['Fix the answers to the survey variables.'], ['commands' => 'is required']], [$refused['run_id'], $refused['errors'], $refused['variable_errors']]);

        $plan = new LaunchPlan();
        $plan->errors = ['The template "Fix" cannot be used for diagnostic runs: no variable group.'];
        $this->assertSame(['The template "Fix" cannot be used for diagnostic runs: no variable group.'],
            $this->service(fn () => ['run_id' => null, 'plan' => $plan, 'errors' => [], 'existing' => false])->launch((object) [], '3', [1], [], str_repeat('a', 32))['errors'],
            'a refusal without its own errors says the plan\'s');

        $launched = $this->service(fn () => ['run_id' => 26, 'plan' => new LaunchPlan(), 'errors' => [], 'existing' => true])->launch((object) [], '3', [1], [], str_repeat('a', 32));
        $this->assertSame([26, [], [], true], [$launched['run_id'], $launched['errors'], $launched['variable_errors'], $launched['existing']]);
    }

    public function testARunAgainTakesTheTemplateTargetsAndAnswersButTheSecretOnes(): void
    {
        $id = $this->record();

        $this->assertSame(['run_id' => $id, 'template' => '3', 'devices' => [1, 2], 'vars' => ['commands' => "show version\nshow vlan"]], $this->service()->prefill($id),
            'not the host it ran on that was no target, nor the secret answer');
        $this->assertNull($this->service()->prefill($id + 1));
    }

    public function testTheOutputPerDeviceComesFromTheWholeLog(): void
    {
        $log = array_merge(['DRY RUN ****', 'PLAY [Show commands] [CHECK MODE] ****', 'TASK [What the device answered] [CHECK MODE] ****'],
            ['ok: [inst-sw1] => {', '    "msg": "12.2(58)SE2"', '}', 'ok: [inst-sw2] => {', '    "msg": "12.2(58)SE2"', '}', 'PLAY RECAP ****'],
            ['inst-sw1 : ok=1 changed=0 unreachable=0 failed=0 skipped=0 rescued=0 ignored=0', 'inst-sw2 : ok=1 changed=0 unreachable=0 failed=0 skipped=0 rescued=0 ignored=0']);

        $running = $this->record(['status' => RunStatus::RUNNING]);
        $this->assertSame(['final' => false], $this->service()->outputs($running), 'nothing until the run ended');
        $this->assertNull($this->service()->outputs($running + 100));

        // the log the run keeps whole
        $stored = $this->record(['output' => implode("\n", $log) . "\n"]);
        $outputs = $this->service()->outputs($stored);
        $this->assertSame([true, DiagnosticService::STORED, ['1' => 'inst-sw1', '2' => 'inst-sw2']], [$outputs['final'], $outputs['source'], (array) $outputs['devices']],
            'each target by its name (its hostname when it has no display name), not the host that was no target');
        $this->assertSame([[1, 2]], array_column($outputs['tasks'][0]['groups'], 'devices'), 'the same answer: one group');

        // cut to the Output size: the runner's whole log, else nothing is attributed
        $cut = $this->record(['output' => $log[0] . "\n", 'output_tail' => end($log) . "\n", 'output_truncated' => 1, 'runner_task_id' => '90']);
        $this->runner->logs['90'] = $log;
        $outputs = $this->service()->outputs($cut);
        $this->assertSame(DiagnosticService::RUNNER, $outputs['source']);
        $this->assertCount(1, $outputs['tasks']);
        $this->runner->outputError = new RunnerError(RunnerError::NOT_FOUND, 'the runner has no task #90');
        $outputs = $this->service()->outputs($cut);
        $this->assertSame([DiagnosticService::CUT, [], []], [$outputs['source'], $outputs['tasks'], $outputs['unattributed']], 'a log with a gap is not read');
    }
}
