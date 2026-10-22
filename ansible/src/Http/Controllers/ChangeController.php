<?php

namespace Agoodley\LibrenmsAnsible\Http\Controllers;

use Agoodley\LibrenmsAnsible\Changes\ApprovalRules;
use Agoodley\LibrenmsAnsible\Changes\BackupState;
use Agoodley\LibrenmsAnsible\Changes\ChangeLifecycle;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Changes\ChangeText;
use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runs\Ansi;
use Agoodley\LibrenmsAnsible\Runs\OutputBuffer;
use Agoodley\LibrenmsAnsible\Runs\RunResults;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Runs\TemplateCheck;
use Agoodley\LibrenmsAnsible\Runs\TemplateReview;
use Agoodley\LibrenmsAnsible\Services\ChangeFollower;
use Agoodley\LibrenmsAnsible\Services\ChangeService;
use Agoodley\LibrenmsAnsible\Services\Eventlog;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Services\TemplateReader;
use Agoodley\LibrenmsAnsible\Support\Access;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Agoodley\LibrenmsAnsible\Support\Format;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Changes: the list (the plugin's main page), a new change, a change's page with its live state, and the actions on
 * a change (dry run, approve, reject, apply, continue, stop, cancel). Every action goes through the change lifecycle
 * under the change's lock (Services\ChangeFollower), and every check is made there again, on the server.
 */
class ChangeController extends Controller
{
    private const PER_PAGE = 50;

    /** Most devices one request may name. */
    private const MAX_DEVICES = 2000;

    /** Most timeline entries the page shows. */
    private const TIMELINE = 500;

    // ------------------------------------------------------------------ list

    /**
     * plugin/ansible/changes: every change, newest first, with filters and paging.
     */
    public function index(Request $request): View
    {
        $changes = new ChangeRepository();
        $filters = self::filters($request);
        $page = max(1, (int) $request->query('page', '1'));
        $data = ['filters' => $filters, 'page' => $page, 'perPage' => self::PER_PAGE, 'rows' => [], 'total' => 0,
            'options' => ['users' => [], 'templates' => [], 'devices' => []], 'error' => null, 'canRun' => Access::canRun($request->user()),
            'assetVersion' => self::assetVersion()];
        try {
            if (! $changes->available() || ! (new RunRepository())->available()) {
                $data['error'] = 'The plugin\'s tables are missing or out of date: run ./lnms migrate.';
            } else {
                $data = array_merge($data, $changes->page($filters, $page, self::PER_PAGE));
                $data['options'] = $changes->filterOptions();
            }
        } catch (\Throwable $e) {
            $data['error'] = $this->failure('changes list', $e);
        }

        return view('ansible::changes', $data);
    }

    /**
     * plugin/ansible/api/changes: the changes list as JSON.
     */
    public function list(Request $request): JsonResponse
    {
        try {
            $result = (new ChangeRepository())->page(self::filters($request), max(1, (int) $request->query('page', '1')), self::PER_PAGE);
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure('changes list', $e)], 500);
        }

        return response()->json(['total' => $result['total'], 'per_page' => self::PER_PAGE, 'changes' => array_map(fn ($change) => [
            'id' => (int) $change['id'], 'created_at' => Clock::local($change['created_at']), 'finished_at' => Clock::local($change['finished_at']),
            'user' => $change['username'], 'template_id' => $change['template_id'], 'template' => $change['template_name'], 'reference' => $change['reference'],
            'status' => $change['status'], 'label' => ChangeStatus::label($change['status']), 'targets' => (int) $change['target_count'],
            'target_names' => $change['target_names'], 'batches' => (int) $change['batch_count'], 'current_batch' => (int) $change['current_batch'],
            'url' => route('ansible.changes.show', $change['id']),
        ], $result['rows'])]);
    }

    // ------------------------------------------------------------------ new change

    /**
     * plugin/ansible/changes/new[?template=ID][&device=ID]: the form. The templates come from the runner, each with
     * whether the plugin can make changes of it (the template checks and the dry-run check).
     */
    public function create(Request $request): View
    {
        $plugin = new Plugin();
        $connection = $plugin->connection();
        $templateId = is_string($request->query('template')) ? mb_substr($request->query('template'), 0, 64) : '';
        $data = ['usable' => [], 'unusable' => [], 'template' => null, 'templateReasons' => [], 'templateNotes' => [], 'runnerError' => null, 'blocked' => [],
            'device' => ctype_digit((string) $request->query('device', '')) ? (int) $request->query('device') : null,
            'requestToken' => bin2hex(random_bytes(16)), 'maxTargets' => $this->settings()->maxTargets(), 'assetVersion' => self::assetVersion(),
            'groups' => [], 'locations' => [], 'planText' => $this->settings()->batchPlan([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12])->describe()];
        try {
            $service = $this->inventoryService();
            $devices = $service->devices();
            [$data['groups'], $data['locations']] = [$devices->deviceGroups(), $devices->locations()];
            $data['blocked'] = array_values(array_filter([self::tablesProblem(), $this->settings()->error, $service->inventoryUserProblem()]));
            $runner = $plugin->runner($connection);
            $health = $runner->health();
            $block = $health->launchBlock();
            if ($block !== null) {
                array_unshift($data['blocked'], $block);
            }
            $review = new TemplateReview($runner, (string) $connection->inventory, $health);
            $reader = new TemplateReader($runner, $connection->project);
            $reasons = fn ($template) => array_merge($review->reasons($template), $reader->dryRunReasons($template));
            foreach ($runner->templates() as $template) {
                $why = $reasons($template);
                if ($why) {
                    $data['unusable'][] = ['template' => $template, 'reasons' => $why];
                } else {
                    $data['usable'][] = $template;
                }
            }
            if ($templateId === '' && count($data['usable']) === 1) {
                $templateId = $data['usable'][0]->id;
            }
            if ($templateId !== '') {
                $data['template'] = $runner->template($templateId);
                $data['templateReasons'] = $reasons($data['template']);
                $data['templateNotes'] = TemplateCheck::notes($data['template']);
                if ($data['templateReasons']) {
                    $chosen = $data['template'];
                    $data['usable'] = array_values(array_filter($data['usable'], fn ($t) => $t->id !== $chosen->id));
                    $data['unusable'] = array_values(array_filter($data['unusable'], fn ($u) => $u['template']->id !== $chosen->id));
                    $data['unusable'][] = ['template' => $chosen, 'reasons' => $data['templateReasons']];
                }
            }
        } catch (RunnerError $e) {
            $data['runnerError'] = $e->getMessage();
        } catch (\Throwable $e) {
            $data['runnerError'] = $this->failure('new change page', $e);
        }

        return view('ansible::new-change', $data);
    }

    /**
     * POST plugin/ansible/api/changes/preview {template, devices, vars, canary, reference}: exactly what the change
     * would be (template, targets, refused devices, answers, batch plan), or what to fix.
     */
    public function preview(Request $request): JsonResponse
    {
        if (($problem = self::tablesProblem()) !== null) {
            return response()->json(['error' => $problem], 503);
        }
        [$templateId, $devices, $answers, $canary, $reference] = self::input($request);
        try {
            return response()->json(ChangeService::forPage($this->changeService()->preview($templateId, $devices, $answers, $canary, $reference)));
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure('preview', $e)], 500);
        }
    }

    /**
     * POST plugin/ansible/changes {template, devices, vars, canary, reference, request_token}: create the change as a
     * draft, checked again; its URL, or what prevents it (HTTP 422). A form token that already made a change gives it.
     */
    public function store(Request $request): JsonResponse
    {
        if (($problem = self::tablesProblem()) !== null) {
            return response()->json(['errors' => [$problem]], 503);
        }
        [$templateId, $devices, $answers, $canary, $reference] = self::input($request);
        $token = is_string($request->input('request_token')) ? $request->input('request_token') : '';
        $expected = null;
        if (is_array($request->input('batches'))) {
            $expected = [];
            foreach (array_slice($request->input('batches'), 0, self::MAX_DEVICES) as $batch) {
                $expected[] = array_values(array_map('intval', array_filter(is_array($batch) ? array_slice($batch, 0, self::MAX_DEVICES) : [],
                    fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)))));
            }
        }
        try {
            $result = $this->changeService()->create($request->user(), $templateId, $devices, $answers, $canary, $reference, $token, $expected);
        } catch (\Throwable $e) {
            return response()->json(['errors' => [$this->failure('new change', $e)]], 500);
        }
        if ($result['change_id'] === null) {
            return response()->json(['errors' => $result['errors'], 'plan' => $result['preview'] ?: null], 422);
        }

        return response()->json(['change_id' => $result['change_id'], 'existing' => $result['existing'],
            'url' => route('ansible.changes.show', $result['change_id'])], $result['existing'] ? 200 : 201);
    }

    // ------------------------------------------------------------------ a change

    /**
     * plugin/ansible/changes/{id}: one change (refreshed first: its run from the runner, its batches, backups, windows).
     */
    public function show(Request $request, int $id): View
    {
        if (($problem = self::tablesProblem()) !== null) {
            abort(503, $problem);
        }
        $change = $this->changeFollower()->refresh($id);
        if ($change === null) {
            abort(404, "There is no Ansible change #$id.");
        }

        return view('ansible::change', $this->state($request, $change) + ['assetVersion' => self::assetVersion()]);
    }

    /**
     * plugin/ansible/api/changes/{id}?run=R&after=N: the change's state, and the log lines of the run it follows now
     * after the runner's line N (when it is still run R). Lines are runs of text with a CSS class: the page inserts
     * them as text, never as HTML.
     */
    public function poll(Request $request, int $id): JsonResponse
    {
        if (($problem = self::tablesProblem()) !== null) {
            return response()->json(['error' => $problem], 503);
        }
        try {
            $change = $this->changeFollower()->refresh($id);
            if ($change === null) {
                return response()->json(['error' => "There is no Ansible change #$id."], 404);
            }
            $state = $this->state($request, $change);
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure('change refresh', $e)], 500);
        }
        $run = $state['run'];
        $log = null;
        if ($run !== null) {
            $after = (int) $request->query('run') === (int) $run['id'] ? max(0, (int) $request->query('after', '0')) : 0;
            $since = OutputBuffer::since($run, $after);
            $log = ['run_id' => (int) $run['id'], 'from' => $after, 'next' => $since['next'], 'lines' => array_map(fn ($line) => Ansi::segments($line), $since['lines']),
                'status' => $run['status'], 'label' => RunStatus::label($run['status']), 'css' => RunStatus::css($run['status']), 'final' => RunStatus::isFinal($run['status']),
                'url' => route('ansible.runs.show', $run['id']), 'kind' => $run['kind'], 'batch' => $run['batch']];
        }

        return response()->json([
            'id' => $id, 'status' => $change['status'], 'label' => ChangeStatus::label($change['status']), 'css' => ChangeStatus::css($change['status']),
            'final' => ChangeStatus::isFinal($change['status']), 'version' => (int) $change['version'], 'pending' => $state['pending'],
            'waiting' => $change['waiting'], 'current_batch' => (int) $change['current_batch'], 'error' => $change['error'],
            'actions' => $state['actions'], 'signature' => $state['signature'], 'log' => $log,
        ]);
    }

    // ------------------------------------------------------------------ actions

    /**
     * POST plugin/ansible/changes/{id}/dry-run {secrets}: launch the dry run (again).
     */
    public function dryRun(Request $request, int $id): JsonResponse
    {
        $user = self::user($request);
        $secrets = $this->secrets($request, $id);

        return $this->act($id, fn (ChangeLifecycle $lifecycle) => $lifecycle->startDryRun($id, $user, $secrets, time()));
    }

    /**
     * POST plugin/ansible/changes/{id}/approve {comment}.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $user = self::user($request);
        $holds = Access::canApprove($request->user());

        return $this->act($id, fn (ChangeLifecycle $lifecycle) => $lifecycle->approve($id, $user, $holds, self::comment($request), time()));
    }

    /**
     * POST plugin/ansible/changes/{id}/reject {comment}.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $user = self::user($request);
        $holds = Access::canApprove($request->user());

        return $this->act($id, fn (ChangeLifecycle $lifecycle) => $lifecycle->reject($id, $user, $holds, self::comment($request), time()));
    }

    /**
     * POST plugin/ansible/changes/{id}/apply {secrets}: start the first batch.
     */
    public function apply(Request $request, int $id): JsonResponse
    {
        $user = self::user($request);
        $secrets = $this->secrets($request, $id);

        return $this->act($id, fn (ChangeLifecycle $lifecycle) => $lifecycle->apply($id, $user, $secrets, time()));
    }

    /**
     * POST plugin/ansible/changes/{id}/continue {secrets}: continue a paused change.
     */
    public function resume(Request $request, int $id): JsonResponse
    {
        $user = self::user($request);
        $secrets = $this->secrets($request, $id);

        return $this->act($id, fn (ChangeLifecycle $lifecycle) => $lifecycle->resume($id, $user, $secrets, time()));
    }

    /**
     * POST plugin/ansible/changes/{id}/stop: stop the running batch (or dry run) and cancel the rest.
     */
    public function stop(Request $request, int $id): JsonResponse
    {
        $username = (string) $request->user()->username;

        return $this->act($id, fn (ChangeLifecycle $lifecycle) => $lifecycle->stop($id, $username, time()));
    }

    /**
     * POST plugin/ansible/changes/{id}/cancel: drop a change that has not been applied.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $username = (string) $request->user()->username;

        return $this->act($id, fn (ChangeLifecycle $lifecycle) => $lifecycle->cancel($id, $username, time()));
    }

    /**
     * Carry out an action under the change's lock: {ok: true}, or {error} with HTTP 422.
     *
     * @param  \Closure(ChangeLifecycle): ?string  $action
     */
    private function act(int $id, \Closure $action): JsonResponse
    {
        if (($problem = self::tablesProblem()) !== null) {
            return response()->json(['error' => $problem], 503);
        }
        if ((new ChangeRepository())->find($id) === null) {
            return response()->json(['error' => "There is no Ansible change #$id."], 404);
        }
        try {
            $error = $this->changeFollower()->act($id, $action);
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure('change action', $e)], 500);
        }

        return $error === null ? response()->json(['ok' => true]) : response()->json(['error' => $error], 422);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Everything the change page shows.
     *
     * @return array<string, mixed>
     */
    private function state(Request $request, array $change): array
    {
        $id = (int) $change['id'];
        $changes = new ChangeRepository();
        $runs = new RunRepository();
        $follower = $this->changeFollower();
        $targets = $changes->targets($id);
        $batches = $changes->batches($id);
        $runId = $follower->currentRun($change);
        $run = $runId !== null ? $runs->find($runId) : null;
        $batchRuns = [];
        foreach ($batches as $number => $batch) {
            if ($batch['run_id'] !== null && ($batchRun = $runs->find((int) $batch['run_id'])) !== null) {
                $batchRuns[$number] = $batchRun;
            }
        }
        $dryRun = $change['dry_run_id'] !== null ? $runs->find((int) $change['dry_run_id']) : null;
        $settings = $this->settings()->changeSettings();
        $user = $request->user();
        $canRun = Access::canRun($user);
        $canApprove = Access::canApprove($user);
        $status = $change['status'];
        $diffs = [];
        $unattributed = [];
        foreach ($changes->diffs($id) as $diff) {
            if ($diff['device_id'] === null) {
                $unattributed[] = $diff;
            } else {
                $diffs[(int) $diff['device_id']][] = $diff;
            }
        }
        $secretVars = array_values(array_filter((array) ($change['variables'] ?? []), fn ($v) => is_array($v) && ! empty($v['secret'])));
        $approvalRefusal = $canApprove ? ApprovalRules::refusal($settings->approval, $change, (int) $user->user_id, true) : null;
        $approvals = $changes->approvals($id);
        $windows = $changes->windows($id);
        $events = $changes->events($id);
        // what the page shows changed: it fetches itself again (the log is followed apart)
        $signature = md5((string) json_encode([(int) $change['version'], $run['id'] ?? null, $run['status'] ?? null,
            array_map(fn ($b) => [$b['status'], $b['run_id']], $batches), array_map(fn ($r) => $r['status'], $batchRuns),
            array_map(fn ($w) => [$w['status'], $w['closing'], $w['ends_at']], $windows),
            array_map(fn ($t) => [$t['dry_result'], $t['result'], $t['before_status'], $t['after_status']], $targets), count($events), count($approvals)]));
        $dryRunAt = Clock::unix($change['dry_run_at']);
        $approvedAt = Clock::unix($change['approved_at']);
        $actions = [
            'dry_run' => $canRun && in_array($status, [ChangeStatus::DRAFT, ChangeStatus::AWAITING, ChangeStatus::READY], true),
            'approve' => $canApprove && $status === ChangeStatus::AWAITING && $approvalRefusal === null,
            'reject' => $canApprove && $status === ChangeStatus::AWAITING,
            'apply' => $canRun && $status === ChangeStatus::READY,
            'continue' => $canRun && $status === ChangeStatus::PAUSED,
            'stop' => $canRun && in_array($status, [ChangeStatus::APPLYING, ChangeStatus::PAUSED, ChangeStatus::DRY_RUN], true),
            'cancel' => $canRun && in_array($status, [ChangeStatus::DRAFT, ChangeStatus::AWAITING, ChangeStatus::READY], true),
        ];

        return [
            'change' => $change, 'targets' => $targets, 'batches' => $batches, 'batchRuns' => $batchRuns, 'run' => $run, 'dryRun' => $dryRun,
            'approvals' => $approvals, 'windows' => $windows, 'events' => array_slice($events, -self::TIMELINE), 'signature' => $signature,
            'diffs' => $diffs, 'unattributed' => $unattributed, 'secretVars' => $secretVars, 'settings' => $settings, 'canRun' => $canRun, 'canApprove' => $canApprove,
            'approvalRefusal' => $approvalRefusal, 'actions' => $actions, 'pending' => $follower->pending($id),
            'dryRunExpires' => $dryRunAt !== null && in_array($status, [ChangeStatus::AWAITING, ChangeStatus::READY], true) ? $dryRunAt + $settings->dryRunSeconds : null,
            'approvalExpires' => $approvedAt !== null && $status === ChangeStatus::READY ? $approvedAt + $settings->approvalSeconds : null,
            'next' => $run !== null ? OutputBuffer::since($run, 0)['next'] : 0,
            'lines' => $run !== null ? OutputBuffer::display($run) : [],
            'oxidized' => (new \Agoodley\LibrenmsAnsible\Services\OxidizedBackups())->enabled(),
        ];
    }

    /**
     * The user as the lifecycle takes it.
     *
     * @return array{user_id: int, username: string}
     */
    private static function user(Request $request): array
    {
        return ['user_id' => (int) $request->user()->user_id, 'username' => (string) $request->user()->username];
    }

    /**
     * The secret answers of a dry run, an apply or a continue: only the change's secret survey variables, as strings
     * (checked by type at the launch); never stored.
     *
     * @return array<string, string>
     */
    private function secrets(Request $request, int $id): array
    {
        $change = (new ChangeRepository())->find($id);
        $names = [];
        foreach ((array) ($change['variables'] ?? []) as $variable) {
            if (is_array($variable) && ! empty($variable['secret'])) {
                $names[] = (string) $variable['name'];
            }
        }
        $given = $request->input('secrets');
        $secrets = [];
        foreach ($names as $name) {
            if (is_array($given) && is_string($given[$name] ?? null) && $given[$name] !== '') {
                $secrets[$name] = $given[$name];
            }
        }

        return $secrets;
    }

    /**
     * An approval's or rejection's comment: one line, at most 500 characters (cleaned again by the lifecycle).
     */
    private static function comment(Request $request): string
    {
        return is_string($request->input('comment')) ? mb_substr($request->input('comment'), 0, 2000) : '';
    }

    private function changeService(): ChangeService
    {
        return new ChangeService(new Plugin(), new RunRepository(), new ChangeRepository(), $this->inventoryService(), $this->settings());
    }

    private function changeFollower(): ChangeFollower
    {
        return new ChangeFollower(new Plugin(), new RunRepository(), new ChangeRepository(), $this->settings(), $this->inventoryService(), new Eventlog());
    }

    /**
     * Why nothing can be done: the plugin's tables are missing or older than its code; null when they are there.
     */
    private static function tablesProblem(): ?string
    {
        return (new RunRepository())->available() && (new ChangeRepository())->available() ? null
            : 'The Ansible plugin\'s tables are missing or out of date: run ./lnms migrate.';
    }

    /**
     * The template id, device ids, answers (not the secret ones), canary devices and reference of a preview or create
     * request; anything of another shape is dropped.
     *
     * @return array{0: string, 1: int[], 2: array<string, mixed>, 3: int[], 4: string}
     */
    private static function input(Request $request): array
    {
        $ids = function (mixed $list): array {
            $ids = [];
            foreach (is_array($list) ? array_slice($list, 0, self::MAX_DEVICES) : [] as $id) {
                if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                    $ids[] = (int) $id;
                }
            }

            return array_values(array_unique($ids));
        };
        $answers = [];
        foreach (is_array($request->input('vars')) ? $request->input('vars') : [] as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value) || $value === null)) {
                $answers[$name] = $value;
            }
        }
        $template = $request->input('template');

        return [is_scalar($template) ? mb_substr((string) $template, 0, 64) : '', $ids($request->input('devices')), $answers, $ids($request->input('canary')),
            is_string($request->input('reference')) ? mb_substr($request->input('reference'), 0, 1000) : ''];
    }

    /**
     * The list's filters from the query string; values that do not fit are ignored.
     *
     * @return array{device: ?int, user: string, template: string, status: string, from: string, to: string}
     */
    private static function filters(Request $request): array
    {
        $string = fn (string $key, int $max) => mb_substr(trim((string) (is_string($request->query($key)) ? $request->query($key) : '')), 0, $max);
        $date = fn (string $key) => preg_match('/^\d{4}-\d\d-\d\d$/D', $string($key, 10)) ? $string($key, 10) : '';
        $status = $string('status', 24);
        $device = $string('device', 10);

        return [
            'device' => ctype_digit($device) && (int) $device > 0 ? (int) $device : null,
            'user' => $string('user', 255),
            'template' => $string('template', 64),
            'status' => in_array($status, array_merge(array_keys(ChangeStatus::LABELS), ['open']), true) ? $status : '',
            'from' => $date('from'),
            'to' => $date('to'),
        ];
    }

    /**
     * Labels and classes the views share.
     *
     * @return array<string, mixed>
     */
    public static function labels(): array
    {
        return ['dry' => ChangeText::DRY_RESULTS, 'results' => RunResults::LABELS, 'backups' => BackupState::LABELS];
    }

    /**
     * A duration for the views.
     */
    public static function duration(?int $seconds): string
    {
        return Format::duration($seconds);
    }
}
