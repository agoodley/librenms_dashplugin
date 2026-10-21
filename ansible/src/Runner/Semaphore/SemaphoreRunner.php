<?php

namespace Agoodley\LibrenmsAnsible\Runner\Semaphore;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Runner\Http\HttpResponse;
use Agoodley\LibrenmsAnsible\Runner\Http\Transport;
use Agoodley\LibrenmsAnsible\Runner\Http\TransportError;
use Agoodley\LibrenmsAnsible\Runner\LaunchRequest;
use Agoodley\LibrenmsAnsible\Runner\Runner;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runner\RunnerHealth;
use Agoodley\LibrenmsAnsible\Runner\RunnerInventory;
use Agoodley\LibrenmsAnsible\Runner\RunnerOutput;
use Agoodley\LibrenmsAnsible\Runner\RunnerTask;
use Agoodley\LibrenmsAnsible\Runner\RunnerTemplate;
use Agoodley\LibrenmsAnsible\Runner\SurveyVar;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;

/**
 * Semaphore UI (2.19, free edition) through its REST API, authenticated with an API token (Bearer), in one
 * project. A task is started with the limit in params.limit and the survey answers in "environment" (JSON)
 * and "secret" (JSON, the secret ones: Semaphore passes them to Ansible without storing them); the output API
 * returns the whole log each time.
 */
final class SemaphoreRunner implements Runner
{
    /** Semaphore's survey variable types and the plugin's. */
    private const SURVEY_TYPES = ['' => 'string', 'text' => 'text', 'int' => 'int', 'enum' => 'enum', 'secret' => 'secret'];

    /** Bit of a template's "permissions" that allows running it. */
    private const PERMISSION_RUN = 1;

    /** What a template may let a task override besides the limit (task_params / template flags). */
    private const OVERRIDES = [
        'allow_override_args_in_task' => 'CLI arguments',
        'allow_override_inventory' => 'inventory',
        'allow_override_branch_in_task' => 'Git branch',
    ];

    /**
     * @param  string  $baseUrl  e.g. https://semaphore.example.com (without /api)
     */
    public function __construct(
        private readonly Transport $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $project,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'Semaphore UI';
    }

    /**
     * Version (GET /api/info), project name and the token's role in it (GET /api/project/{p}/role).
     */
    public function health(): RunnerHealth
    {
        $info = $this->get('/info', 'the version');
        $project = $this->get($this->projectPath('/'), "project {$this->project}");
        $role = $this->get($this->projectPath('/role'), "the token's role in project {$this->project}");
        $roleName = is_string($role['role'] ?? null) ? $role['role'] : null;
        $permissions = is_int($role['permissions'] ?? null) ? $role['permissions'] : 0;

        return new RunnerHealth(
            UntrustedText::line($info['version'] ?? 'unknown', 60),
            UntrustedText::line($project['name'] ?? "#{$this->project}", 120),
            $roleName,
            ($permissions & self::PERMISSION_RUN) === self::PERMISSION_RUN || in_array($roleName, ['owner', 'manager', 'task_runner'], true),
        );
    }

    /**
     * GET /api/project/{p}/templates (the list carries no permissions: canRun is unknown there).
     */
    public function templates(): array
    {
        $list = $this->get($this->projectPath('/templates'), 'the templates');
        if (! array_is_list($list)) {
            throw $this->badResponse('/templates');
        }

        return array_map(fn ($template) => $this->template_($template), array_values(array_filter($list, 'is_array')));
    }

    /**
     * GET /api/project/{p}/templates/{id}.
     */
    public function template(string $id): RunnerTemplate
    {
        return $this->template_($this->get($this->projectPath('/templates/' . rawurlencode($id)), "template $id"));
    }

    /**
     * GET /api/project/{p}/inventory/{id}.
     */
    public function inventory(string $id): RunnerInventory
    {
        $inventory = $this->get($this->projectPath('/inventory/' . rawurlencode($id)), "inventory $id");
        $type = (string) ($inventory['type'] ?? '');

        return new RunnerInventory((string) ($inventory['id'] ?? $id), UntrustedText::line($inventory['name'] ?? '', 120), $type,
            $type === 'file' ? UntrustedText::line($inventory['inventory'] ?? '', 200) : '');
    }

    /**
     * POST /api/project/{p}/tasks with the limit in params.limit (the top-level "limit" is a legacy alias) and the
     * answers in "environment" and "secret"; never an inventory, playbook, branch or arguments.
     */
    public function launch(LaunchRequest $request): RunnerTask
    {
        $params = ['limit' => array_values($request->limit)];
        if ($request->check) {
            $params['dry_run'] = true;
        }
        if ($request->diff) {
            $params['diff'] = true;
        }
        $body = [
            'template_id' => (int) $request->templateId,
            'message' => $request->message,
            'environment' => json_encode((object) $request->vars, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'params' => $params,
        ];
        if ($request->secrets) {
            $body['secret'] = json_encode((object) $request->secrets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        $task = $this->call('POST', $this->projectPath('/tasks'), "a task of template {$request->templateId}", $body);

        return $this->task_($task);
    }

    /**
     * GET /api/project/{p}/tasks/{id}; Semaphore answers 400 (2.19) or 404 for a task it does not have.
     */
    public function task(string $id): RunnerTask
    {
        return $this->task_($this->get($this->projectPath('/tasks/' . rawurlencode($id)), "task $id", true));
    }

    /**
     * GET /api/project/{p}/tasks/{id}/output: [{task_id, time, output}], the whole log each time, one record per
     * line (some end with a newline, a few hold several lines); the lines from $from on.
     */
    public function output(string $taskId, int $from = 0): RunnerOutput
    {
        $records = $this->get($this->projectPath('/tasks/' . rawurlencode($taskId) . '/output'), "the output of task $taskId", true);
        if (! array_is_list($records)) {
            throw $this->badResponse('/tasks/{id}/output');
        }
        $lines = [];
        foreach ($records as $record) {
            $text = is_array($record) ? (string) ($record['output'] ?? '') : '';
            foreach (explode("\n", rtrim($text, "\r\n")) as $line) {
                $lines[] = rtrim($line, "\r");
            }
        }

        return new RunnerOutput(array_slice($lines, max(0, $from)), count($lines));
    }

    /**
     * POST /api/project/{p}/tasks/{id}/stop with {"force": false} (an empty body is refused with 400).
     */
    public function stop(string $taskId): void
    {
        $this->call('POST', $this->projectPath('/tasks/' . rawurlencode($taskId) . '/stop'), "stopping task $taskId", ['force' => false], true);
    }

    /**
     * A template of Semaphore's JSON. Absent fields mean their zero value (Semaphore omits empty ones).
     */
    private function template_(array $t): RunnerTemplate
    {
        if (! isset($t['id']) || ! is_scalar($t['id'])) {
            throw $this->badResponse('/templates');
        }
        $params = is_array($t['task_params'] ?? null) ? $t['task_params'] : [];
        $limit = array_merge(self::list($params['limit'] ?? null), self::list($t['limit'] ?? null));
        $overrides = [];
        foreach (self::OVERRIDES as $flag => $what) {
            if (! empty($params[$flag]) || ! empty($t[$flag])) {
                $overrides[] = $what;
            }
        }

        return new RunnerTemplate(
            (string) $t['id'],
            UntrustedText::line($t['name'] ?? '', 120),
            UntrustedText::line($t['description'] ?? '', 300),
            UntrustedText::line($t['playbook'] ?? '', 200),
            (string) (($t['app'] ?? '') ?: 'ansible'),
            (string) ($t['type'] ?? ''),
            isset($t['inventory_id']) && is_numeric($t['inventory_id']) ? (string) (int) $t['inventory_id'] : null,
            ! empty($params['allow_override_limit']),
            $limit,
            self::arguments($t['arguments'] ?? null),
            is_string($t['arguments'] ?? null) ? $t['arguments'] : '',
            array_map(fn ($var) => self::surveyVar($var), array_values(array_filter(is_array($t['survey_vars'] ?? null) ? $t['survey_vars'] : [], 'is_array'))),
            is_int($t['permissions'] ?? null) ? ($t['permissions'] & self::PERMISSION_RUN) === self::PERMISSION_RUN : null,
            $overrides,
        );
    }

    /**
     * A survey variable of Semaphore's JSON.
     */
    private static function surveyVar(array $v): SurveyVar
    {
        $rawType = is_string($v['type'] ?? null) ? $v['type'] : '';
        $values = [];
        foreach (is_array($v['values'] ?? null) ? $v['values'] : [] as $value) {
            if (is_array($value) && isset($value['value']) && is_scalar($value['value'])) {
                $values[] = ['name' => (string) ($value['name'] ?? $value['value']), 'value' => (string) $value['value']];
            }
        }

        return new SurveyVar(
            is_string($v['name'] ?? null) ? $v['name'] : '',
            UntrustedText::line($v['title'] ?? '', 120),
            UntrustedText::line($v['description'] ?? '', 300),
            self::SURVEY_TYPES[$rawType] ?? 'unknown',
            $rawType,
            ! empty($v['required']),
            is_string($v['target'] ?? null) ? $v['target'] : '',
            $values,
            isset($v['default_value']) && is_scalar($v['default_value']) && (string) $v['default_value'] !== '' ? (string) $v['default_value'] : null,
        );
    }

    /**
     * A task of Semaphore's JSON.
     */
    private function task_(array $t): RunnerTask
    {
        if (! isset($t['id']) || ! is_scalar($t['id']) || ! is_string($t['status'] ?? null)) {
            throw $this->badResponse('/tasks');
        }

        return new RunnerTask((string) $t['id'], RunStatus::fromSemaphore($t['status']), $t['status'],
            self::time($t['start'] ?? null), self::time($t['end'] ?? null));
    }

    /**
     * The template's fixed CLI arguments: Semaphore keeps a JSON list as a string; null when it is not one.
     *
     * @return string[]|null
     */
    private static function arguments(mixed $raw): ?array
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return [];
        }
        if (! is_string($raw)) {
            return null;
        }
        $list = json_decode($raw, true);
        if (! is_array($list) || ! array_is_list($list)) {
            return null;
        }
        foreach ($list as $argument) {
            if (! is_string($argument)) {
                return null;
            }
        }

        return $list;
    }

    /**
     * A limit given as a list or as a comma separated string.
     *
     * @return string[]
     */
    private static function list(mixed $value): array
    {
        $items = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);

        return array_values(array_filter(array_map(fn ($item) => is_scalar($item) ? trim((string) $item) : '', $items), fn ($item) => $item !== ''));
    }

    /**
     * A Unix time of Semaphore's RFC 3339 times (nanoseconds, "Z"); null for none or the zero time.
     */
    private static function time(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)(?:\.\d+)?(Z|[+-]\d\d:?\d\d)?$/', $value, $m)) {
            return null;
        }
        $time = strtotime($m[1] . (($m[2] ?? '') === '' ? 'Z' : $m[2]));

        return $time !== false && $time > 0 ? $time : null;
    }

    /**
     * The API path of the project.
     */
    private function projectPath(string $path): string
    {
        return '/project/' . $this->project . $path;
    }

    /**
     * GET a JSON object or list.
     */
    private function get(string $path, string $what, bool $missingTask = false): array
    {
        return $this->call('GET', $path, $what, null, $missingTask);
    }

    /**
     * One API call; its JSON answer, or a RunnerError that says what went wrong and what to check.
     *
     * @param  bool  $missingTask  is a 400 the answer for a task Semaphore does not have
     */
    private function call(string $method, string $path, string $what, #[\SensitiveParameter] ?array $body = null, bool $missingTask = false): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        try {
            $response = $this->http->send($method, rtrim($this->baseUrl, '/') . '/api' . $path, $headers,
                $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (TransportError $e) {
            throw new RunnerError(RunnerError::UNREACHABLE, "Cannot reach Semaphore UI at {$this->host()}: " . UntrustedText::line($e->getMessage(), 200));
        }
        $this->check($response, $what, $missingTask);
        if ($response->status === 204 || trim($response->body) === '') {
            return [];
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw $this->badResponse($path);
        }

        return $json;
    }

    /**
     * Turn an HTTP error status into a RunnerError.
     */
    private function check(HttpResponse $response, string $what, bool $missingTask): void
    {
        $status = $response->status;
        if ($status >= 200 && $status < 300) {
            return;
        }
        $detail = self::errorText($response);
        throw match (true) {
            $status === 401 => new RunnerError(RunnerError::UNAUTHORIZED, 'Semaphore UI refused the API token (HTTP 401): it is wrong, expired or revoked. Create a token for the Task Runner user and save it in the plugin settings.', $status),
            $status === 403 => new RunnerError(RunnerError::FORBIDDEN, "The token's user may not do this in project {$this->project} (HTTP 403, $what). It needs the Task Runner role in the project.", $status),
            $status === 404 => new RunnerError(RunnerError::NOT_FOUND, "Semaphore UI does not have $what in project {$this->project} (HTTP 404), or the token's user is not a member of the project.", $status),
            $status === 400 && $missingTask => new RunnerError(RunnerError::NOT_FOUND, "Semaphore UI does not have $what in project {$this->project} (HTTP 400).", $status),
            $status >= 400 && $status < 500 => new RunnerError(RunnerError::REFUSED, "Semaphore UI refused $what (HTTP $status)" . ($detail !== '' ? ": $detail" : '.'), $status),
            default => new RunnerError(RunnerError::BAD_RESPONSE, "Semaphore UI failed on $what (HTTP $status)" . ($detail !== '' ? ": $detail" : '.') . ' Check its log.', $status),
        };
    }

    /**
     * Semaphore's error text ({"error": "..."}) or the start of the body, cleaned.
     */
    private static function errorText(HttpResponse $response): string
    {
        $json = $response->json();
        $text = is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : (is_array($json) ? '' : $response->body);

        return UntrustedText::line(strip_tags($text), 160);
    }

    /**
     * An answer the plugin cannot read.
     */
    private function badResponse(string $path): RunnerError
    {
        return new RunnerError(RunnerError::BAD_RESPONSE, "Semaphore UI's answer to $path is not what the plugin expects: is {$this->host()} a Semaphore UI 2.x?");
    }

    /**
     * Scheme, host and port of the runner, for messages.
     */
    private function host(): string
    {
        $parts = parse_url($this->baseUrl);
        $host = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '?') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return UntrustedText::line($host, 120);
    }
}
