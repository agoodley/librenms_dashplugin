<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Data\DependencyRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Topology\PlanningService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Rollout planner and device dependencies pages, their JSON API, and applying derived dependencies
 * (admin only).
 */
class PlanningController extends Controller
{
    use BuildsTopology;

    /** Devices that can be picked one by one for a rollout. */
    private const MAX_PICKED = 5000;

    /**
     * plugin/topology/rollout: batches for a firmware upgrade, reboot or change of many devices.
     */
    public function rollout(Request $request): View
    {
        $settings = $this->settings();
        [$picked, $size, $network] = $this->rolloutInput($request, $settings);
        $repo = new TopologyRepository($request->user());
        $devices = $repo->devices(array_values(array_filter($picked, fn ($id) => $repo->canSee($id))), roles: false);
        $names = [];
        foreach ($picked as $id) {
            if (isset($devices[$id])) {
                $names[$id] = (string) (($devices[$id]['display'] ?? '') ?: $devices[$id]['hostname']);
            }
        }

        return $this->page($request, 'rollout', 'rollout plan',
            fn (array $params) => $this->planning($request, $settings)->rollout($params, $picked, $size, $network),
            ['picked' => $names, 'batchSize' => $size, 'networkOnly' => $network]);
    }

    /**
     * api/rollout: the rollout plan as JSON (devices[]= or devices=1,2,3, group, location, size, network).
     */
    public function rolloutApi(Request $request): JsonResponse
    {
        $settings = $this->settings();
        [$picked, $size, $network] = $this->rolloutInput($request, $settings);
        $params = MapParams::parse($request->query(), $settings);

        return $this->json('rollout plan', fn () => $this->planning($request, $settings)->rollout($params, $picked, $size, $network));
    }

    /**
     * plugin/topology/dependencies: derived device dependencies next to the current ones.
     */
    public function dependencies(Request $request): View
    {
        return $this->page($request, 'dependencies', 'device dependencies',
            fn (array $params, PluginSettings $settings) => $this->planning($request, $settings)->dependencies($params),
            ['canApply' => $this->canApply($request), 'applied' => $request->session()->get('topology_dependencies')]);
    }

    /**
     * api/dependencies: the derived dependencies as JSON.
     */
    public function dependenciesApi(Request $request): JsonResponse
    {
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);

        return $this->json('device dependencies', fn () => $this->planning($request, $settings)->dependencies($params));
    }

    /**
     * POST plugin/topology/dependencies (admin only): set the derived parents of the selected devices
     * (devices[]=device_id:parents as previewed, and selected: how many were selected, to notice
     * what PHP's max_input_vars cut off), or with all=1 of every change of the filter's view
     * (filter[device|group|location] and the fingerprint of its preview).
     */
    public function applyDependencies(Request $request): RedirectResponse
    {
        abort_unless($this->canApply($request), 403);

        $filter = $request->input('filter');
        // a device, group or location of the view that is no id would be read as "every device"
        $invalid = is_array($filter) ? MapParams::invalidScope($filter) : ($request->filled('filter') ? 'filter' : null);
        abort_if($invalid !== null, 422, "The $invalid of this view is not a valid id: nothing changed.");
        $view = [];
        foreach (['device', 'group', 'location'] as $key) {
            $value = is_array($filter) ? ($filter[$key] ?? null) : null;
            $view[$key] = is_scalar($value) && is_numeric($value) && (int) $value > 0 ? (int) $value : null;
        }
        $devices = $request->input('devices');
        $devices = is_array($devices) ? $devices : [];
        $selected = $request->input('selected');

        try {
            $planning = $this->planning($request, $this->settings());
            if ($request->boolean('all')) {
                $fingerprint = $request->input('fingerprint');
                $result = $planning->applyDependencies([], $view, is_string($fingerprint) ? $fingerprint : null);
            } elseif (is_scalar($selected) && is_numeric($selected) && (int) $selected > count($devices)) {
                $result = ['changed' => [], 'stale' => [], 'refused' => [], 'error' => true,
                    'message' => 'Nothing changed: more devices were selected than this server accepts in one form (PHP max_input_vars). '
                        . 'Use "Apply every change of this view", or select fewer devices.'];
            } else {
                $result = $planning->applyDependencies(self::previewed($devices));
            }
        } catch (\Throwable $e) {
            $result = ['changed' => [], 'stale' => [], 'refused' => [], 'message' => $this->failure('device dependency update', $e), 'error' => true];
        }

        // back to the view the changes were made on
        return redirect()->route('topology.dependencies', array_filter($view))->with('topology_dependencies', $result);
    }

    /**
     * May the user change device dependencies? Only LibreNMS admins.
     */
    private function canApply(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && ($user->can('admin') || (method_exists($user, 'isAdmin') && $user->isAdmin()));
    }

    /**
     * Devices and the parents their preview showed, from the values "device_id:parent,parent" of the
     * selected checkboxes; other values are ignored.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, int[]>
     */
    private static function previewed(array $values): array
    {
        $previewed = [];
        foreach ($values as $value) {
            if (is_string($value) && preg_match('/^(\d+):(\d+(?:,\d+)*)?$/', $value, $match) && (int) $match[1] > 0) {
                $previewed[(int) $match[1]] = ($match[2] ?? '') !== '' ? array_map('intval', explode(',', $match[2])) : [];
            }
        }

        return $previewed;
    }

    /**
     * Devices picked one by one (devices[]=1&devices[]=2, devices=1,2 or the scope's device), the batch
     * size (0: no limit) and whether a group / location means its switches, routers and firewalls only.
     *
     * @return array{0: int[], 1: int, 2: bool}
     */
    private function rolloutInput(Request $request, PluginSettings $settings): array
    {
        $devices = $request->query('devices', []);
        $devices = is_array($devices) ? $devices : explode(',', (string) $devices);
        $devices[] = $request->query('device');
        $picked = array_slice(array_values(array_unique(array_filter(array_map('intval', array_filter($devices, 'is_scalar')), fn ($id) => $id > 0))), 0, self::MAX_PICKED);

        $size = $request->query('size');
        $size = is_scalar($size) && is_numeric($size) ? max(0, min(10000, (int) $size)) : $settings->int('rollout_batch_size', 0, 10000);
        $network = $request->query('network', '1');
        $network = ! is_scalar($network) || ! in_array(strtolower((string) $network), ['0', 'false', 'off', 'no'], true);

        return [$picked, $size, $network];
    }

    private function planning(Request $request, PluginSettings $settings): PlanningService
    {
        return new PlanningService(new TopologyRepository($request->user()), new DependencyRepository($request->user()), $settings);
    }
}
