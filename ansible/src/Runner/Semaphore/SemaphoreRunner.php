<?php

namespace Agoodley\LibrenmsAnsible\Runner\Semaphore;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Runner\Http\HttpResponse;
use Agoodley\LibrenmsAnsible\Runner\Http\Transport;
use Agoodley\LibrenmsAnsible\Runner\Http\TransportError;
use Agoodley\LibrenmsAnsible\Runner\LaunchRequest;
use Agoodley\LibrenmsAnsible\Runner\Runner;
use Agoodley\LibrenmsAnsible\Runner\RunnerEnvironment;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runner\RunnerHealth;
use Agoodley\LibrenmsAnsible\Runner\RunnerInventory;
use Agoodley\LibrenmsAnsible\Runner\RunnerOutput;
use Agoodley\LibrenmsAnsible\Runner\RunnerRepository;
use Agoodley\LibrenmsAnsible\Runner\RunnerTask;
use Agoodley\LibrenmsAnsible\Runner\RunnerTaskSearch;
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

    /** Tasks per page of GET /tasks/last: Semaphore UI 2.19 answers at most 200 (its maxTasksPageSize). */
    private const SEARCH_PAGE = 200;

    /** Pages read at most when looking for the task of a launch whose answer never came (1000 tasks back). */
    public const SEARCH_PAGES = 5;

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
     * Version (GET /api/info), project name, the token's role in it (GET /api/project/{p}/role) and whether its user
     * is a Semaphore admin (GET /api/user).
     */
    public function health(): RunnerHealth
    {
        $info = $this->get('/info', 'the version');
        $project = $this->get($this->projectPath('/'), "project {$this->project}");
        $role = $this->get($this->projectPath('/role'), "the token's role in project {$this->project}");
        $user = $this->get('/user', "the token's user");
        if (! is_bool($user['admin'] ?? null)) {
            // without it the plugin cannot tell whether the token's user may change every project
            throw $this->badResponse('/user');
        }
        $roleName = is_string($role['role'] ?? null) ? $role['role'] : null;
        $permissions = is_int($role['permissions'] ?? null) ? $role['permissions'] : 0;

        return new RunnerHealth(
            UntrustedText::line($info['version'] ?? 'unknown', 60),
            UntrustedText::line($project['name'] ?? "#{$this->project}", 120),
            $roleName === null ? null : UntrustedText::line($roleName, 40),
            ($permissions & self::PERMISSION_RUN) === self::PERMISSION_RUN || in_array($roleName, ['owner', 'manager', 'task_runner'], true),
            $user['admin'],
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
        return $this->inventory_($this->get($this->projectPath('/inventory/' . rawurlencode($id)), "inventory $id"), $id);
    }

    /**
     * GET /api/project/{p}/inventory: every inventory of the project.
     */
    public function inventories(): array
    {
        $list = $this->get($this->projectPath('/inventory'), 'the inventories');
        if (! array_is_list($list)) {
            throw $this->badResponse('/inventory');
        }

        return array_map(fn ($inventory) => $this->inventory_($inventory, ''), array_values(array_filter($list, 'is_array')));
    }

    /**
     * GET /api/project/{p}/environment/{id}: the variable group's environment variables (its "env" JSON, names and the
     * values, which are not secret), a hash of its extra variables ("json"), and the names of its secrets; Semaphore
     * never returns the secrets' values.
     */
    public function environment(string $id): RunnerEnvironment
    {
        $environment = $this->get($this->projectPath('/environment/' . rawurlencode($id)), "variable group $id");
        $env = is_string($environment['env'] ?? null) && trim($environment['env']) !== '' ? json_decode($environment['env'], true) : [];
        $extra = is_string($environment['json'] ?? null) && trim($environment['json']) !== '' ? json_decode($environment['json'], true) : [];
        if (! is_array($env) || ! is_array($extra)) {
            throw $this->badResponse('/environment/{id}');
        }
        $secrets = ['env' => [], 'var' => []];
        foreach (is_array($environment['secrets'] ?? null) ? $environment['secrets'] : [] as $secret) {
            if (is_array($secret) && in_array($secret['type'] ?? '', ['env', 'var'], true) && is_string($secret['name'] ?? null)) {
                $secrets[$secret['type']][] = $secret['name'];
            }
        }
        $values = [];
        foreach ($env as $name => $value) {
            $values[(string) $name] = is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : (string) json_encode($value);
        }

        return new RunnerEnvironment((string) ($environment['id'] ?? $id), UntrustedText::line($environment['name'] ?? '', 120),
            array_keys($values), array_values(array_unique($secrets['env'])), $values,
            $extra ? hash('sha256', (string) json_encode($extra)) : '', array_values(array_unique($secrets['var'])));
    }

    /**
     * GET /api/project/{p}/repositories/{id}: the repository's address and branch.
     */
    public function repository(string $id): RunnerRepository
    {
        $repository = $this->get($this->projectPath('/repositories/' . rawurlencode($id)), "repository $id");

        return new RunnerRepository((string) ($repository['id'] ?? $id), UntrustedText::line($repository['name'] ?? '', 120),
            UntrustedText::line($repository['git_url'] ?? '', 500), UntrustedText::line($repository['git_branch'] ?? '', 200));
    }

    /**
     * GET /api/project/{p}/role: the token's role in the project.
     */
    public function role(): string
    {
        $role = $this->get($this->projectPath('/role'), "the token's role in project {$this->project}");

        return is_string($role['role'] ?? null) ? $role['role'] : '';
    }

    /**
     * An inventory of Semaphore's JSON.
     */
    private function inventory_(array $inventory, string $id): RunnerInventory
    {
        $type = (string) ($inventory['type'] ?? '');

        return new RunnerInventory((string) ($inventory['id'] ?? $id), UntrustedText::line($inventory['name'] ?? '', 120), $type,
            $type === 'file' ? UntrustedText::line($inventory['inventory'] ?? '', 200) : '');
    }

    /**
     * POST /api/project/{p}/tasks with the limit in params.limit (the top-level "limit" is a legacy alias), a dry run
     * in params.dry_run and params.diff (Semaphore ignores them at the top level), the answers in "environment" and
     * "secret", and a commit in "commit_hash" (Semaphore checks it out instead of the branch's latest); never an
     * inventory, playbook, branch or arguments.
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
        if ($request->commit !== null) {
            if (! preg_match('/^[0-9a-f]{7,64}$/D', $request->commit)) {
                throw new RunnerError(RunnerError::REFUSED, 'The commit to run is not a Git commit hash.');
            }
            $body['commit_hash'] = $request->commit;
        }
        $task = $this->call('POST', $this->projectPath('/tasks'), "a task of template {$request->templateId}", $body);
        try {
            return $this->task_($task);
        } catch (RunnerError $e) {
            // an answer without a task: Semaphore may still have started it
            throw new RunnerError($e->kind, $e->getMessage(), $e->status, true);
        }
    }

    /**
     * GET /api/project/{p}/tasks/last?count=200, then the older pages (&before=the oldest id so far), newest first: the
     * newest task of the template with this message created at $notBefore or later. The search is complete when it
     * reached a task created before $notBefore, or the end of the list (an empty page), within SEARCH_PAGES pages; a
     * page that does not go further back (a Semaphore UI that ignores before=) leaves it incomplete. Semaphore UI caps
     * a page at 200 tasks whatever count asks, so one page says nothing about the tasks before it.
     */
    public function findTask(string $templateId, string $message, int $notBefore): RunnerTaskSearch
    {
        $before = null;
        for ($page = 0; $page < self::SEARCH_PAGES; $page++) {
            $tasks = $this->get($this->projectPath('/tasks/last?count=' . self::SEARCH_PAGE . ($before !== null ? '&before=' . $before : '')), 'the latest tasks');
            if (! array_is_list($tasks)) {
                throw $this->badResponse('/tasks/last');
            }
            if (! $tasks) {
                return new RunnerTaskSearch(null, true); // the end of the project's tasks
            }
            $found = null;
            $oldestId = null;
            $oldestCreated = null;
            foreach ($tasks as $task) {
                if (! is_array($task) || ! isset($task['id']) || ! is_numeric($task['id'])) {
                    continue;
                }
                $id = (int) $task['id'];
                $created = self::time($task['created'] ?? null);
                $oldestId = $oldestId === null ? $id : min($oldestId, $id);
                $oldestCreated = $created !== null && ($oldestCreated === null || $created < $oldestCreated) ? $created : $oldestCreated;
                if ((string) ($task['template_id'] ?? '') === $templateId && ($task['message'] ?? null) === $message
                    && $created !== null && $created >= $notBefore && ($found === null || $id > (int) $found['id'])) {
                    $found = $task;
                }
            }
            if ($found !== null) {
                return new RunnerTaskSearch($this->task_($found), true); // the pages before this one had no such task
            }
            if ($oldestCreated !== null && $oldestCreated < $notBefore) {
                return new RunnerTaskSearch(null, true); // back past the launch
            }
            if ($oldestId === null || ($before !== null && $oldestId >= $before)) {
                return new RunnerTaskSearch(null, false); // the list does not go further back
            }
            $before = $oldestId;
        }

        return new RunnerTaskSearch(null, false);
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
     * POST /api/project/{p}/tasks/{id}/stop with {"force": false}, or true to kill a task a stop did not end (an empty
     * body is refused with 400).
     */
    public function stop(string $taskId, bool $force = false): void
    {
        $this->call('POST', $this->projectPath('/tasks/' . rawurlencode($taskId) . '/stop'), "stopping task $taskId", ['force' => $force], true);
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
            isset($t['environment_id']) && is_numeric($t['environment_id']) && (int) $t['environment_id'] > 0 ? (string) (int) $t['environment_id'] : null,
            isset($t['repository_id']) && is_numeric($t['repository_id']) && (int) $t['repository_id'] > 0 ? (string) (int) $t['repository_id'] : null,
            array_values(array_map(fn ($id) => (string) (int) $id, array_filter(is_array($t['environment_ids'] ?? null) ? $t['environment_ids'] : [],
                fn ($id) => is_numeric($id) && (int) $id > 0))),
            is_string($t['git_branch'] ?? null) && trim($t['git_branch']) !== '' ? UntrustedText::line($t['git_branch'], 200) : null,
            // the template's own tags and skip tags (Ansible options), which Semaphore gives every task of it: what they leave out of a dry run
            // must not run at an apply, so they are part of the fingerprint
            array_merge(self::list($params['tags'] ?? null), self::list($t['tags'] ?? null)),
            array_merge(self::list($params['skip_tags'] ?? null), self::list($t['skip_tags'] ?? null)),
            // and the others it gives every task: whether it installs the repository's Galaxy requirements (the collections the
            // playbook runs), the vaults it opens, the runner and the container image it runs on
            array_filter([
                'skip_galaxy_install' => ! empty($params['skip_galaxy_install']),
                'vaults' => array_values(array_map(fn (array $vault) => array_filter($vault, 'is_scalar'), array_filter(is_array($t['vaults'] ?? null) ? $t['vaults'] : [], 'is_array'))),
                'runner_tag' => is_string($t['runner_tag'] ?? null) ? trim($t['runner_tag']) : '',
                'executor_image' => is_string($t['executor_image'] ?? null) ? trim($t['executor_image']) : '',
            ]),
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

        $commit = is_string($t['commit_hash'] ?? null) && preg_match('/^[0-9a-f]{7,64}$/D', $t['commit_hash']) ? $t['commit_hash'] : null;

        return new RunnerTask((string) $t['id'], RunStatus::fromSemaphore($t['status']), UntrustedText::line($t['status'], 32),
            self::time($t['start'] ?? null), self::time($t['end'] ?? null), self::time($t['created'] ?? null), $commit);
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
            throw new RunnerError(RunnerError::UNREACHABLE, "Cannot reach Semaphore UI at {$this->host()}: " . UntrustedText::line($e->getMessage(), 200),
                null, $method !== 'GET' && $e->maybeSent);
        }
        $this->check($response, $what, $missingTask, $method !== 'GET');
        if ($response->status === 204 || trim($response->body) === '') {
            return [];
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw $this->badResponse($path, $method !== 'GET');
        }

        return $json;
    }

    /**
     * Turn an HTTP error status into a RunnerError; a server error to a request that changes something leaves its
     * outcome uncertain.
     */
    private function check(HttpResponse $response, string $what, bool $missingTask, bool $changes = false): void
    {
        $status = $response->status;
        if ($status >= 200 && $status < 300) {
            return;
        }
        $detail = self::errorText($response);
        if (($status === 401 || ($status >= 300 && $status < 400)) && ! $this->looksLikeSemaphore()) {
            throw new RunnerError(RunnerError::BAD_RESPONSE, "{$this->host()} does not look like Semaphore UI: it answered HTTP $status to $what, and its /api/ping "
                . 'does not answer "pong". Check the runner URL (the address of Semaphore UI, without /api).', $status);
        }
        $project = "project {$this->project}";
        throw match (true) {
            $status === 401 => new RunnerError(RunnerError::UNAUTHORIZED, 'Semaphore UI refused the API token (HTTP 401): it is wrong, expired or revoked. Create a token for the Task Runner user and save it in the plugin settings.', $status),
            $status === 403 => new RunnerError(RunnerError::FORBIDDEN, "The token's user may not do this in $project (HTTP 403, $what). It needs the Task Runner role in the project.", $status),
            $status === 404 && $what === $project => new RunnerError(RunnerError::NOT_FOUND, "Semaphore UI has no $project (HTTP 404), or the token's user is not a member of it.", $status),
            $status === 404 => new RunnerError(RunnerError::NOT_FOUND, "Semaphore UI does not have $what in $project (HTTP 404), or the token's user is not a member of the project.", $status),
            $status === 400 && $missingTask => new RunnerError(RunnerError::NOT_FOUND, "Semaphore UI does not have $what in project {$this->project} (HTTP 400).", $status),
            $status >= 400 && $status < 500 => new RunnerError(RunnerError::REFUSED, "Semaphore UI refused $what (HTTP $status)" . ($detail !== '' ? ": $detail" : '.'), $status),
            default => new RunnerError(RunnerError::BAD_RESPONSE, "Semaphore UI failed on $what (HTTP $status)" . ($detail !== '' ? ": $detail" : '.') . ' Check its log.', $status, $changes),
        };
    }

    /**
     * Does the runner URL lead to Semaphore UI? Its /api/ping answers "pong" without a token (none is sent here).
     */
    private function looksLikeSemaphore(): bool
    {
        try {
            $response = $this->http->send('GET', rtrim($this->baseUrl, '/') . '/api/ping', ['Accept' => 'text/plain']);
        } catch (TransportError) {
            return false;
        }

        return $response->status === 200 && trim($response->body) === 'pong';
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
    private function badResponse(string $path, bool $uncertain = false): RunnerError
    {
        return new RunnerError(RunnerError::BAD_RESPONSE, "Semaphore UI's answer to $path is not what the plugin expects: is {$this->host()} a Semaphore UI 2.x?",
            null, $uncertain);
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
