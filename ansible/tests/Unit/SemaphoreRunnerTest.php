<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Runner\Http\HttpResponse;
use Agoodley\LibrenmsAnsible\Runner\LaunchRequest;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runner\Semaphore\SemaphoreRunner;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Runs\TemplateCheck;
use PHPUnit\Framework\TestCase;

class SemaphoreRunnerTest extends TestCase
{
    private const TOKEN = 'secret-token-value-xyz=';

    private FakeTransport $http;

    protected function setUp(): void
    {
        $this->http = new FakeTransport([
            'GET /api/info' => new HttpResponse(200, FakeTransport::fixture('info.json')),
            'GET /api/project/2/' => new HttpResponse(200, FakeTransport::fixture('project.json')),
            'GET /api/project/2/role' => new HttpResponse(200, FakeTransport::fixture('role.json')),
            'GET /api/project/2/templates' => new HttpResponse(200, FakeTransport::fixture('templates.json')),
            'GET /api/project/2/templates/5' => new HttpResponse(200, FakeTransport::fixture('template-survey.json')),
            'GET /api/project/2/inventory/3' => new HttpResponse(200, FakeTransport::fixture('inventory-static.json')),
            'POST /api/project/2/tasks' => new HttpResponse(201, FakeTransport::fixture('task-launched.json')),
            'GET /api/project/2/tasks/18' => new HttpResponse(200, FakeTransport::fixture('task-success.json')),
            'GET /api/project/2/tasks/19' => new HttpResponse(200, FakeTransport::fixture('task-error.json')),
            'GET /api/project/2/tasks/18/output' => new HttpResponse(200, FakeTransport::fixture('task-output.json')),
            'POST /api/project/2/tasks/18/stop' => fn (?string $body) => new HttpResponse($body === '{"force":false}' ? 204 : 400, ''),
        ]);
    }

    private function runner(): SemaphoreRunner
    {
        return new SemaphoreRunner($this->http, 'https://semaphore.example.com/', self::TOKEN, 2);
    }

    public function testHealth(): void
    {
        $health = $this->runner()->health();

        $this->assertStringStartsWith('v2.19.17', $health->version);
        $this->assertSame('LibreNMS plugin (explore)', $health->project);
        $this->assertSame('task_runner', $health->role);
        $this->assertTrue($health->canRun);
        $this->assertFalse($health->tooPowerful());
        $this->assertSame('Bearer ' . self::TOKEN, $this->http->requests[0]['headers']['Authorization']);
        $this->assertSame('https://semaphore.example.com/api/info', $this->http->requests[0]['url']);
    }

    public function testManagerTokenIsTooPowerful(): void
    {
        $this->http->on('GET /api/project/2/role', new HttpResponse(200, '{"role":"manager","permissions":7}'));

        $this->assertTrue($this->runner()->health()->tooPowerful());
    }

    public function testTemplatesFromTheRecordedList(): void
    {
        $templates = [];
        foreach ($this->runner()->templates() as $template) {
            $templates[$template->name] = $template;
        }

        $survey = $templates['Survey check'];
        $this->assertSame('5', $survey->id);
        $this->assertSame('3', $survey->inventoryId);
        $this->assertTrue($survey->limitOverride);
        $this->assertSame([], $survey->arguments);
        $this->assertNull($survey->canRun, 'the list carries no permissions');
        $this->assertSame(['note', 'count', 'mode', 'lines', 'api_secret', 'CHANGE_REF'], array_map(fn ($v) => $v->name, $survey->surveyVars));
        $this->assertSame(['string', 'int', 'enum', 'text', 'secret', 'string'], array_map(fn ($v) => $v->type, $survey->surveyVars));
        $this->assertTrue($survey->surveyVars[0]->required);
        $this->assertSame('env', $survey->surveyVars[5]->target);
        $this->assertSame([['name' => 'Fast', 'value' => 'fast'], ['name' => 'Slow', 'value' => 'slow']], $survey->surveyVars[2]->values);
        $this->assertSame([], TemplateCheck::reasons($survey, '3'));

        $this->assertSame(['lab-sw2'], $templates['Template limit']->fixedLimit);
        $this->assertSame(['--limit', 'lab-sw3'], $templates['Arguments limit']->arguments);
        $this->assertNotSame([], TemplateCheck::reasons($templates['Arguments limit'], '3'));
        $this->assertNotSame([], TemplateCheck::reasons($templates['Arguments extra vars'], '3'));
        $this->assertNotSame([], TemplateCheck::reasons($templates['Template limit'], '3'));
    }

    public function testTemplateDetailHasThePermission(): void
    {
        $template = $this->runner()->template('5');

        $this->assertTrue($template->canRun);
        $this->assertSame('lnms_survey_check.yml', $template->playbook);
    }

    public function testInventory(): void
    {
        $inventory = $this->runner()->inventory('3');

        $this->assertSame('Static lab', $inventory->name);
        $this->assertFalse($inventory->isFile());
        $this->assertSame('', $inventory->source, 'the hosts of a static inventory are not kept');
    }

    public function testLaunchSendsOnlyLimitAndAnswers(): void
    {
        $task = $this->runner()->launch(new LaunchRequest('5', ['lab-sw1', 'lab-sw2'], ['note' => 'fixture', 'count' => 5], ['api_secret' => 'pw'],
            'LibreNMS Ansible run #1 by admin'));

        $this->assertSame('18', $task->id);
        $this->assertSame(RunStatus::QUEUED, $task->status);
        $this->assertSame('waiting', $task->rawStatus);
        $body = $this->http->lastBody();
        $this->assertSame(['template_id', 'message', 'environment', 'params', 'secret'], array_keys($body));
        $this->assertSame(5, $body['template_id']);
        $this->assertSame(['limit' => ['lab-sw1', 'lab-sw2']], $body['params']);
        $this->assertSame('{"note":"fixture","count":5}', $body['environment']);
        $this->assertSame('{"api_secret":"pw"}', $body['secret']);
    }

    public function testLaunchWithoutAnswers(): void
    {
        $this->runner()->launch(new LaunchRequest('5', ['a'], [], [], 'm', true, true));
        $body = $this->http->lastBody();

        $this->assertSame('{}', $body['environment']);
        $this->assertArrayNotHasKey('secret', $body);
        $this->assertSame(['limit' => ['a'], 'dry_run' => true, 'diff' => true], $body['params'], 'the flags of the later dry runs');
    }

    public function testTaskStatusesAndTimes(): void
    {
        $success = $this->runner()->task('18');
        $this->assertSame(RunStatus::SUCCESS, $success->status);
        $this->assertSame(strtotime('2026-10-21T22:13:27Z'), $success->started);
        $this->assertSame(strtotime('2026-10-21T22:13:28Z'), $success->ended);

        $this->assertSame(RunStatus::FAILED, $this->runner()->task('19')->status);
    }

    public function testOutputFromAPosition(): void
    {
        $all = $this->runner()->output('18');
        $this->assertSame(32, $all->next);
        $this->assertSame('Task Survey check added to queue', $all->lines[0]);
        $this->assertSame("Started task #18 of template 'Survey check'", $all->lines[1], 'the newline of a record is not a line');

        $rest = $this->runner()->output('18', 30);
        $this->assertSame(32, $rest->next);
        $this->assertCount(2, $rest->lines);
        $this->assertSame([], $this->runner()->output('18', 40)->lines);
    }

    public function testStopSendsForceFalse(): void
    {
        $this->runner()->stop('18');

        $this->assertSame('{"force":false}', $this->http->requests[0]['body']);
    }

    /**
     * @return array<string, array{0: HttpResponse|\Throwable, 1: string, 2: string}>
     */
    public static function errors(): array
    {
        return [
            'unreachable' => [FakeTransport::unreachable(), RunnerError::UNREACHABLE, 'Cannot reach Semaphore UI at https://semaphore.example.com: Failed to connect'],
            'bad token' => [new HttpResponse(401, ''), RunnerError::UNAUTHORIZED, 'refused the API token (HTTP 401)'],
            'not allowed' => [new HttpResponse(403, ''), RunnerError::FORBIDDEN, 'Task Runner role'],
            'no project' => [new HttpResponse(404, ''), RunnerError::NOT_FOUND, 'does not have template 5 in project 2 (HTTP 404)'],
            'server error' => [new HttpResponse(500, '{"error":"database is locked"}'), RunnerError::BAD_RESPONSE, 'HTTP 500): database is locked'],
            'not json' => [new HttpResponse(200, '<html>login</html>'), RunnerError::BAD_RESPONSE, 'not what the plugin expects'],
            'wrong shape' => [new HttpResponse(200, '{"name":"x"}'), RunnerError::BAD_RESPONSE, 'not what the plugin expects'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrorsSayWhatToFixWithoutTheToken(HttpResponse|\Throwable $answer, string $kind, string $message): void
    {
        $this->http->on('GET /api/project/2/templates/5', $answer);
        try {
            $this->runner()->template('5');
            $this->fail('no error');
        } catch (RunnerError $e) {
            $this->assertSame($kind, $e->kind);
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testMissingTaskAnswers400(): void
    {
        $this->http->on('GET /api/project/2/tasks/77', new HttpResponse(400, ''));
        try {
            $this->runner()->task('77');
            $this->fail('no error');
        } catch (RunnerError $e) {
            $this->assertSame(RunnerError::NOT_FOUND, $e->kind);
        }
    }

    /**
     * @return array<string, array{0: HttpResponse|\Throwable, 1: bool}>
     */
    public static function launchFailures(): array
    {
        return [
            'timeout after sending' => [new \Agoodley\LibrenmsAnsible\Runner\Http\TransportError('Operation timed out after 15001 milliseconds', true), true],
            'no connection' => [new \Agoodley\LibrenmsAnsible\Runner\Http\TransportError('Failed to connect', false), false],
            'server error' => [new HttpResponse(502, 'Bad Gateway'), true],
            'answer without a task' => [new HttpResponse(201, '{"ok":true}'), true],
            'refused' => [new HttpResponse(400, '{"error":"bad"}'), false],
            'forbidden' => [new HttpResponse(403, ''), false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('launchFailures')]
    public function testALaunchWithoutAProperAnswerIsUncertain(HttpResponse|\Throwable $answer, bool $uncertain): void
    {
        $this->http->on('POST /api/project/2/tasks', $answer);
        try {
            $this->runner()->launch(new LaunchRequest('5', ['a'], [], [], 'm'));
            $this->fail('no error');
        } catch (RunnerError $e) {
            $this->assertSame($uncertain, $e->uncertain);
        }
    }

    public function testReadsAreNeverUncertain(): void
    {
        $this->http->on('GET /api/project/2/templates/5', new \Agoodley\LibrenmsAnsible\Runner\Http\TransportError('timed out', true));
        try {
            $this->runner()->template('5');
            $this->fail('no error');
        } catch (RunnerError $e) {
            $this->assertFalse($e->uncertain);
        }
    }

    public function testFindTheTaskOfALaunchByItsMessage(): void
    {
        $this->http->on('GET /api/project/2/tasks/last?count=200', new HttpResponse(200, FakeTransport::fixture('tasks-last.json')));

        $task = $this->runner()->findTask('15', 'LibreNMS Ansible run #41 by admin');
        $this->assertSame('61', $task->id);
        $this->assertSame(RunStatus::STOPPED, $task->status);
        $this->assertNull($this->runner()->findTask('11', 'LibreNMS Ansible run #41 by admin'), 'another template');
        $this->assertNull($this->runner()->findTask('15', 'LibreNMS Ansible run #4 by admin'), 'the message must match exactly');
    }

    public function testCurlErrorsThatNeverSent(): void
    {
        foreach ([6, 7, 35, 60] as $errno) {
            $this->assertFalse(\Agoodley\LibrenmsAnsible\Runner\Http\CurlTransport::maybeSent($errno), "curl error $errno");
        }
        foreach ([28, 52, 56, 92] as $errno) {
            $this->assertTrue(\Agoodley\LibrenmsAnsible\Runner\Http\CurlTransport::maybeSent($errno), "curl error $errno");
        }
    }

    public function testRefusedLaunch(): void
    {
        $this->http->on('POST /api/project/2/tasks', new HttpResponse(400, '{"error":"Template is not allowed"}'));
        try {
            $this->runner()->launch(new LaunchRequest('5', ['a'], [], [], 'm'));
            $this->fail('no error');
        } catch (RunnerError $e) {
            $this->assertSame(RunnerError::REFUSED, $e->kind);
            $this->assertStringContainsString('refused a task of template 5 (HTTP 400): Template is not allowed', $e->getMessage());
        }
    }
}
