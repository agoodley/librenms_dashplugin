<?php

namespace Agoodley\LibrenmsAnsible\Http\Controllers;

use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Runs\Ansi;
use Agoodley\LibrenmsAnsible\Runs\OutputBuffer;
use Agoodley\LibrenmsAnsible\Runs\RunDiagnosis;
use Agoodley\LibrenmsAnsible\Runs\RunLifecycle;
use Agoodley\LibrenmsAnsible\Runs\RunResults;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Runs\TaskLines;
use Agoodley\LibrenmsAnsible\Support\Access;
use Agoodley\LibrenmsAnsible\Support\Format;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The audit log: the runs list, a run with its targets and live log, and stopping a run.
 */
class RunController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * plugin/ansible/runs: every run, newest first, with filters and paging.
     */
    public function index(Request $request): View
    {
        $runs = new RunRepository();
        $filters = self::filters($request);
        $page = max(1, (int) $request->query('page', '1'));
        $data = ['filters' => $filters, 'page' => $page, 'perPage' => self::PER_PAGE, 'rows' => [], 'total' => 0,
            'options' => ['users' => [], 'templates' => [], 'devices' => []], 'error' => null, 'canRun' => Access::canRun($request->user()),
            'assetVersion' => self::assetVersion()];
        try {
            if (! $runs->available()) {
                $data['error'] = 'The plugin\'s tables are missing or out of date: run ./lnms migrate.';
            } else {
                $data = array_merge($data, $runs->page($filters, $page, self::PER_PAGE));
                $data['options'] = $runs->filterOptions();
            }
        } catch (\Throwable $e) {
            $data['error'] = $this->failure('runs list', $e);
        }

        return view('ansible::runs', $data);
    }

    /**
     * plugin/ansible/api/runs: the runs list as JSON.
     */
    public function list(Request $request): JsonResponse
    {
        try {
            $result = (new RunRepository())->page(self::filters($request), max(1, (int) $request->query('page', '1')), self::PER_PAGE);
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure('runs list', $e)], 500);
        }

        return response()->json(['total' => $result['total'], 'per_page' => self::PER_PAGE, 'runs' => array_map(fn ($run) => [
            'id' => (int) $run['id'], 'created_at' => $run['created_at'], 'started_at' => $run['started_at'], 'finished_at' => $run['finished_at'],
            'user' => $run['username'], 'template_id' => $run['template_id'], 'template' => $run['template_name'], 'status' => $run['status'],
            'targets' => $run['targets'], 'target_names' => $run['target_names'], 'results' => (object) ($run['summary'] ?? []), 'unexpected_hosts' => $run['unexpected_hosts'] ?? [],
            'url' => route('ansible.runs.show', $run['id']),
        ], $result['rows'])]);
    }

    /**
     * plugin/ansible/runs/{id}: one run (refreshed from the runner when it has not finished).
     */
    public function show(Request $request, int $id): View
    {
        if (! (new RunRepository())->available()) {
            abort(503, 'The Ansible plugin\'s tables are missing or out of date: run ./lnms migrate.');
        }
        $run = $this->follower()->refresh($id);
        if ($run === null) {
            abort(404, "There is no Ansible run #$id.");
        }
        $targets = (new RunRepository())->targets($id);

        return view('ansible::run', [
            'run' => $run,
            'targets' => $targets,
            'lines' => OutputBuffer::display($run),
            'stoppedNote' => self::stoppedNote($run, $targets),
            'held' => RunLifecycle::held($run, time()),
            'next' => OutputBuffer::since($run, 0)['next'],
            'canRun' => Access::canRun($request->user()),
            'releasable' => RunLifecycle::releasable($run, time()),
            'assetVersion' => self::assetVersion(),
        ]);
    }

    /**
     * plugin/ansible/api/runs/{id}?after=N: the run's status, targets and the log lines after the runner's line N
     * (with a line that says how many were left out of a long log), refreshed from the runner at most every two
     * seconds. Lines are runs of text with a CSS class: the page inserts them as text, never as HTML.
     */
    public function poll(Request $request, int $id): JsonResponse
    {
        if (! (new RunRepository())->available()) {
            return response()->json(['error' => 'The Ansible plugin\'s tables are missing or out of date: run ./lnms migrate.'], 503);
        }
        $run = $this->follower()->refresh($id);
        if ($run === null) {
            return response()->json(['error' => "There is no Ansible run #$id."], 404);
        }
        $log = OutputBuffer::since($run, max(0, (int) $request->query('after', '0')));
        $rows = (new RunRepository())->targets($id);
        $targets = [];
        foreach ($rows as $deviceId => $target) {
            $targets[] = ['device_id' => $deviceId, 'name' => $target['inventory_name'], 'display' => $target['display'], 'result' => $target['result'],
                'label' => $target['result'] !== null ? RunResults::LABELS[$target['result']] ?? $target['result'] : null,
                'css' => RunResults::css($target['result']), 'counts' => $target['counts'], 'unexpected' => $target['unexpected']];
        }

        return response()->json([
            'id' => (int) $run['id'],
            'status' => $run['status'],
            'label' => RunStatus::label($run['status']),
            'css' => RunStatus::css($run['status']),
            'final' => RunStatus::isFinal($run['status']),
            'runner_status' => $run['runner_status'],
            'runner_task_id' => $run['runner_task_id'],
            'started_at' => $run['started_at'],
            'finished_at' => $run['finished_at'],
            'duration' => Format::duration(Format::runDuration($run)),
            'summary' => (object) ($run['summary'] ?? []),
            'unexpected_hosts' => $run['unexpected_hosts'] ?? [],
            'error' => $run['error'],
            'poll_error' => $run['poll_error'],
            'stopped_by' => $run['stopped_by'],
            'stop_reason' => $run['stop_reason'],
            'released_by' => $run['released_by'],
            'releasable' => RunLifecycle::releasable($run, time()),
            'held' => RunLifecycle::held($run, time()),
            'held_until' => $run['held_until'] ?? null,
            'stopped_note' => self::stoppedNote($run, $rows),
            'targets' => $targets,
            'lines' => array_map(fn ($line) => Ansi::segments($line), $log['lines']),
            'next' => $log['next'],
            'truncated' => (bool) $run['output_truncated'],
        ]);
    }

    /**
     * POST plugin/ansible/runs/{id}/stop: ask the runner to stop the run.
     */
    public function stop(Request $request, int $id): JsonResponse
    {
        $error = $this->follower()->stop($id, (string) $request->user()->username);

        return $error === null ? response()->json(['ok' => true]) : response()->json(['error' => $error], 422);
    }

    /**
     * POST plugin/ansible/runs/{id}/release: release the devices of a run the runner could not be asked about for
     * a while, or those a stopped run holds (Runs\RunLifecycle::release()); written to the eventlog of its targets.
     */
    public function release(Request $request, int $id): JsonResponse
    {
        $error = $this->follower()->release($id, (string) $request->user()->username);

        return $error === null ? response()->json(['ok' => true]) : response()->json(['error' => $error], 422);
    }

    /**
     * For a stopped run: what the stop did not end on the runner (the task in progress) and how long the devices are
     * held, null otherwise.
     *
     * @param  array<int, array>  $targets  RunRepository::targets()
     */
    private static function stoppedNote(array $run, array $targets): ?string
    {
        if ($run['status'] !== RunStatus::STOPPED) {
            return null;
        }
        $names = array_values(array_map(fn ($target) => (string) $target['inventory_name'], array_filter($targets, fn ($target) => empty($target['unexpected']))));

        return RunDiagnosis::afterStop($run, TaskLines::inProgress(OutputBuffer::display($run), $names)['task'], time());
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
        $status = $string('status', 20);
        $device = $string('device', 10);

        return [
            'device' => ctype_digit($device) && (int) $device > 0 ? (int) $device : null,
            'user' => $string('user', 255),
            'template' => $string('template', 64),
            'status' => in_array($status, array_merge(RunStatus::FINAL, RunStatus::UNFINISHED, ['unfinished']), true) ? $status : '',
            'from' => $date('from'),
            'to' => $date('to'),
        ];
    }
}
