<?php

namespace Agoodley\LibrenmsAnsible\Http\Controllers;

use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runs\TargetSelection;
use Agoodley\LibrenmsAnsible\Runs\TemplateCheck;
use Agoodley\LibrenmsAnsible\Runs\TemplateReview;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * New run: the template (only those the plugin can launch safely; the others with the reasons), the targets
 * (every device with its platform and why it cannot be a target), the survey variables, the preview of exactly
 * what will be launched, and the launch.
 */
class LaunchController extends Controller
{
    /** Most devices one request may name. */
    private const MAX_DEVICES = 2000;

    /**
     * plugin/ansible/runs/new[?template=ID][&device=ID]: the form. The templates come from the runner; the chosen
     * one is asked again on its own (with the token's permission on it).
     */
    public function create(Request $request): View
    {
        $plugin = new Plugin();
        $connection = $plugin->connection();
        $templateId = is_string($request->query('template')) ? mb_substr($request->query('template'), 0, 64) : '';
        $data = ['usable' => [], 'unusable' => [], 'template' => null, 'templateReasons' => [], 'templateNotes' => [], 'runnerError' => null, 'blocked' => [],
            'device' => ctype_digit((string) $request->query('device', '')) ? (int) $request->query('device') : null,
            'requestToken' => bin2hex(random_bytes(16)), 'maxTargets' => $this->settings()->maxTargets(), 'assetVersion' => self::assetVersion(),
            'groups' => [], 'locations' => []];
        try {
            $service = $this->inventoryService();
            $devices = $service->devices();
            [$data['groups'], $data['locations']] = [$devices->deviceGroups(), $devices->locations()];
            // what refuses every launch, said up front (the preview and the launch check it again)
            $data['blocked'] = array_values(array_filter([$this->settings()->error, $service->inventoryUserProblem()]));
            $runner = $plugin->runner($connection);
            $block = $runner->health()->launchBlock();
            if ($block !== null) {
                array_unshift($data['blocked'], $block);
            }
            $review = new TemplateReview($runner, (string) $connection->inventory);
            foreach ($runner->templates() as $template) {
                $reasons = $review->reasons($template);
                if ($reasons) {
                    $data['unusable'][] = ['template' => $template, 'reasons' => $reasons];
                } else {
                    $data['usable'][] = $template;
                }
            }
            if ($templateId === '' && count($data['usable']) === 1) {
                $templateId = $data['usable'][0]->id;
            }
            if ($templateId !== '') {
                $data['template'] = $runner->template($templateId);
                $data['templateReasons'] = $review->reasons($data['template']);
                $data['templateNotes'] = TemplateCheck::notes($data['template']);
                if ($data['templateReasons']) {
                    // the template on its own can say more than the list (the token's permission on it): list it once, as unusable
                    $chosen = $data['template'];
                    $data['usable'] = array_values(array_filter($data['usable'], fn ($t) => $t->id !== $chosen->id));
                    $data['unusable'] = array_values(array_filter($data['unusable'], fn ($u) => $u['template']->id !== $chosen->id));
                    $data['unusable'][] = ['template' => $chosen, 'reasons' => $data['templateReasons']];
                }
            }
        } catch (RunnerError $e) {
            $data['runnerError'] = $e->getMessage();
        } catch (\Throwable $e) {
            $data['runnerError'] = $this->failure('new run page', $e);
        }

        return view('ansible::new-run', $data);
    }

    /**
     * plugin/ansible/api/targets: every device with its inventory name, platform, LibreNMS status and, when it
     * cannot be a target, why (TargetSelection).
     */
    public function targets(): JsonResponse
    {
        try {
            $service = $this->inventoryService();
            $inventory = $service->build();
            $devices = $service->devices();
            $inventoryUser = $service->inventoryUser();
            $selection = new TargetSelection($inventory, $devices->exists(), (new RunRepository())->busy(), $inventoryUser['visible'] ?? null,
                (string) ($inventoryUser['user']->username ?? ''));
            $memberships = $devices->memberships();
            $locations = $devices->locations();
            $map = $this->settings()->platformMap();
            $list = [];
            foreach ($devices->devices() as $deviceId => $row) {
                $platform = $map->for((string) $row['os']);
                $list[] = [
                    'id' => $deviceId,
                    'display' => (string) (($row['display'] ?? '') ?: $row['hostname']),
                    'hostname' => (string) $row['hostname'],
                    'name' => $inventory->nameOf($deviceId),
                    'os' => (string) $row['os'],
                    'platform' => $platform['network_os'] ?? null,
                    'status' => $row['disabled'] ? 'disabled' : ($row['status'] ? 'up' : 'down'),
                    'location_id' => $row['location_id'] !== null ? (int) $row['location_id'] : null,
                    'location' => $locations[(int) $row['location_id']] ?? '',
                    'groups' => $memberships[$deviceId] ?? [],
                    'refusal' => $selection->refusal($deviceId),
                ];
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure('target list', $e)], 500);
        }

        return response()->json(['devices' => $list, 'max_targets' => $this->settings()->maxTargets(),
            'inventory_user' => $inventoryUser['user']->username ?? null, 'fetched_at' => $inventoryUser['fetch']['fetched_at'] ?? null]);
    }

    /**
     * POST plugin/ansible/api/preview {template, devices, vars}: exactly what a launch would start (secret answers
     * masked), or what to fix.
     */
    public function preview(Request $request): JsonResponse
    {
        [$templateId, $devices, $answers] = self::input($request);
        try {
            return response()->json($this->launcher()->plan($templateId, $devices, $answers)->forPage());
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure('preview', $e)], 500);
        }
    }

    /**
     * POST plugin/ansible/runs {template, devices, vars, request_token}: launch, checked again; the URL of the run,
     * or what prevents it (HTTP 422). A form token that already started a run gives that run.
     */
    public function store(Request $request): JsonResponse
    {
        [$templateId, $devices, $answers] = self::input($request);
        $token = is_string($request->input('request_token')) ? $request->input('request_token') : '';
        try {
            $result = $this->launcher()->launch($request->user(), $templateId, $devices, $answers, $token);
        } catch (\Throwable $e) {
            return response()->json(['errors' => [$this->failure('launch', $e)]], 500);
        }
        if ($result['run_id'] === null) {
            return response()->json(['errors' => $result['errors'], 'plan' => $result['plan']->forPage()], 422);
        }

        return response()->json(['run_id' => $result['run_id'], 'existing' => $result['existing'],
            'url' => route('ansible.runs.show', $result['run_id'])], $result['existing'] ? 200 : 201);
    }

    /**
     * The template id, device ids and answers of a preview or launch request; anything of another shape is
     * dropped (the checks then say what is missing).
     *
     * @return array{0: string, 1: int[], 2: array<string, mixed>}
     */
    private static function input(Request $request): array
    {
        $template = $request->input('template');
        $devices = $request->input('devices');
        $vars = $request->input('vars');
        $ids = [];
        foreach (is_array($devices) ? array_slice($devices, 0, self::MAX_DEVICES) : [] as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        $answers = [];
        foreach (is_array($vars) ? $vars : [] as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value) || $value === null)) {
                $answers[$name] = $value;
            }
        }

        return [is_scalar($template) ? mb_substr((string) $template, 0, 64) : '', array_values(array_unique($ids)), $answers];
    }
}
