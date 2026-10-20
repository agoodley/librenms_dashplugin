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
    private const MAX_CHOSEN = 5000;

    /**
     * plugin/topology/rollout: batches for a firmware upgrade, reboot or change of many devices.
     */
    public function rollout(Request $request): View
    {
        $settings = $this->settings();
        [$chosen, $size, $network] = $this->rolloutInput($request, $settings);
        $repo = new TopologyRepository($request->user());
        $names = [];
        foreach ($chosen as $id) {
            if (($name = $repo->deviceName($id)) !== null) {
                $names[$id] = $name;
            }
        }

        return $this->page($request, 'rollout', 'rollout plan',
            fn (array $params) => $this->planning($request, $settings)->rollout($params, $chosen, $size, $network),
            ['chosen' => $names, 'batchSize' => $size, 'networkOnly' => $network]);
    }

    /**
     * api/rollout: the rollout plan as JSON (devices[]= or devices=1,2,3, group, location, size, network).
     */
    public function rolloutApi(Request $request): JsonResponse
    {
        $settings = $this->settings();
        [$chosen, $size, $network] = $this->rolloutInput($request, $settings);
        $params = MapParams::parse($request->query(), $settings);

        return $this->json('rollout plan', fn () => $this->planning($request, $settings)->rollout($params, $chosen, $size, $network));
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
     * POST plugin/topology/dependencies: set the derived parents of the posted devices (admin only).
     */
    public function applyDependencies(Request $request): RedirectResponse
    {
        abort_unless($this->canApply($request), 403);

        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) $request->input('devices', []))))), 0, self::MAX_CHOSEN);
        try {
            $result = $this->planning($request, $this->settings())->applyDependencies($ids);
        } catch (\Throwable $e) {
            $result = ['changed' => [], 'message' => $this->failure('device dependency update', $e), 'error' => true];
        }

        // back to the view the changes were made on
        $filter = array_intersect_key((array) $request->input('filter', []), array_flip(['device', 'group', 'location']));

        return redirect()->route('topology.dependencies', array_filter($filter, fn ($value) => is_numeric($value) && (int) $value > 0))
            ->with('topology_dependencies', $result);
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
        $chosen = array_slice(array_values(array_unique(array_filter(array_map('intval', array_filter($devices, 'is_scalar')), fn ($id) => $id > 0))), 0, self::MAX_CHOSEN);

        $size = $request->query('size');
        $size = is_numeric($size) ? max(0, min(10000, (int) $size)) : $settings->int('rollout_batch_size', 0, 10000);
        $network = ! in_array(strtolower((string) $request->query('network', '1')), ['0', 'false', 'off', 'no'], true);

        return [$chosen, $size, $network];
    }

    private function planning(Request $request, PluginSettings $settings): PlanningService
    {
        return new PlanningService(new TopologyRepository($request->user()), new DependencyRepository($request->user()), $settings);
    }
}
