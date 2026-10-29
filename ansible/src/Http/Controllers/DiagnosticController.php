<?php

namespace Agoodley\LibrenmsAnsible\Http\Controllers;

use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Services\DiagnosticService;
use Agoodley\LibrenmsAnsible\Services\Eventlog;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Support\Access;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Diagnostic runs (Services\DiagnosticService): the form (a template, the devices, the answers, Run) with the latest
 * diagnostic runs, the launch, and what each task of a run printed per device for its page.
 */
class DiagnosticController extends Controller
{
    /** The latest diagnostic runs the page lists. */
    private const LATEST = 10;

    /**
     * plugin/ansible/diagnostics[?template=ID][&device=ID][&devices=ID,ID...][&from=RUN]: the form, for users with the run
     * permission (the devices chosen: a device's panel, the topology plugin's map, an earlier run), and the latest
     * diagnostic runs for every admin.
     */
    public function index(Request $request): View
    {
        $canRun = Access::canRun($request->user());
        $templateId = is_string($request->query('template')) ? mb_substr($request->query('template'), 0, 64) : '';
        $prefill = null;
        $from = (string) $request->query('from', '');
        $data = ['canRun' => $canRun, 'prefill' => null, 'devices' => self::deviceIds($request), 'requestToken' => bin2hex(random_bytes(16)),
            'maxTargets' => $this->settings()->maxTargets(), 'groups' => [], 'locations' => [], 'latest' => [], 'error' => null,
            'usable' => [], 'unusable' => [], 'template' => null, 'templateReasons' => [], 'templateNotes' => [], 'blocked' => [], 'runnerError' => null,
            'assetVersion' => self::assetVersion()];
        try {
            $runs = new RunRepository();
            if (! $runs->available() || ! (new ChangeRepository())->available()) {
                $data['error'] = 'The Ansible plugin\'s tables are missing or out of date: run ./lnms migrate.';

                return view('ansible::diagnostics', $data);
            }
            $data['latest'] = $runs->page(['kind' => 'diagnostic'], 1, self::LATEST)['rows'];
            if (! $canRun) {
                return view('ansible::diagnostics', $data);
            }
            if (ctype_digit($from) && ($prefill = $this->service()->prefill((int) $from)) !== null) {
                $templateId = $templateId !== '' ? $templateId : $prefill['template'];
                $data['devices'] = $data['devices'] ?: $prefill['devices'];
                $data['prefill'] = $prefill;
            }
            $service = $this->inventoryService();
            $devices = $service->devices();
            [$data['groups'], $data['locations']] = [$devices->deviceGroups(), $devices->locations()];
            $data['blocked'] = array_values(array_filter([$this->settings()->error, $service->inventoryUserProblem()]));
            $templates = $this->service()->templates($templateId);
            $data['blocked'] = array_merge($templates['blocked'], $data['blocked']);
            unset($templates['blocked']);
            $data = array_merge($data, $templates);
        } catch (\Throwable $e) {
            $data['runnerError'] = $this->failure('diagnostics page', $e);
        }

        return view('ansible::diagnostics', $data);
    }

    /**
     * POST plugin/ansible/diagnostics {template, devices, vars, request_token}: launch a diagnostic run, checked again; its
     * URL (HTTP 201, 200 for a form token that already launched one), or what prevents it (HTTP 422, with the answers'
     * errors).
     */
    public function store(Request $request): JsonResponse
    {
        if (! (new RunRepository())->available() || ! (new ChangeRepository())->available()) {
            return response()->json(['errors' => ['The Ansible plugin\'s tables are missing or out of date: run ./lnms migrate.']], 503);
        }
        $template = $request->input('template');
        $answers = [];
        foreach (is_array($request->input('vars')) ? $request->input('vars') : [] as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value) || $value === null)) {
                $answers[$name] = $value;
            }
        }
        try {
            $result = $this->service()->launch($request->user(), is_scalar($template) ? mb_substr((string) $template, 0, 64) : '', self::ids($request->input('devices')),
                $answers, is_string($request->input('request_token')) ? $request->input('request_token') : '');
        } catch (\Throwable $e) {
            return response()->json(['errors' => [$this->failure('diagnostic run', $e)]], 500);
        }
        if ($result['run_id'] === null) {
            return response()->json(['errors' => $result['errors'], 'variable_errors' => (object) $result['variable_errors']], 422);
        }

        return response()->json(['run_id' => $result['run_id'], 'existing' => $result['existing'], 'url' => route('ansible.runs.show', $result['run_id'])],
            $result['existing'] ? 200 : 201);
    }

    /**
     * plugin/ansible/api/runs/{id}/outputs: what each task of a finished run printed per device, grouped by identical
     * output (Runs\RunOutputs); {final: false} while it runs. Device output is text: the page inserts it as text.
     */
    public function outputs(int $id): JsonResponse
    {
        if (! (new RunRepository())->available()) {
            return response()->json(['error' => 'The Ansible plugin\'s tables are missing or out of date: run ./lnms migrate.'], 503);
        }
        try {
            $outputs = $this->service()->outputs($id);
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure('output of a run', $e)], 500);
        }

        return $outputs === null ? response()->json(['error' => "There is no Ansible run #$id."], 404) : response()->json($outputs);
    }

    private function service(): DiagnosticService
    {
        return new DiagnosticService(new Plugin(), new RunRepository(), $this->inventoryService(), $this->settings(), new Eventlog(), new ChangeRepository());
    }

    /**
     * The devices a link chose: ?device=ID (a device's panel), ?devices=ID,ID (the topology plugin's map).
     *
     * @return int[]
     */
    private static function deviceIds(Request $request): array
    {
        $device = (string) $request->query('device', '');
        $list = is_string($request->query('devices')) ? explode(',', mb_substr($request->query('devices'), 0, 8000)) : [];

        return self::ids(ctype_digit($device) ? array_merge([$device], $list) : $list);
    }

    /**
     * Device ids of a request, at most DiagnosticService::MAX_DEVICES; anything else is dropped.
     *
     * @return int[]
     */
    private static function ids(mixed $list): array
    {
        $ids = [];
        foreach (is_array($list) ? array_slice($list, 0, DiagnosticService::MAX_DEVICES) : [] as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
