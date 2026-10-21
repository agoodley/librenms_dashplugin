<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Coverage\CoverageService;
use Agoodley\LibrenmsTopology\Data\CoverageRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Monitoring coverage report: the page (plugin/topology/coverage) and its JSON (api/coverage), with
 * the same device, group and location parameters as the other diagnostics pages.
 */
class CoverageController extends Controller
{
    use BuildsTopology;

    /**
     * plugin/topology/coverage: unmonitored neighbours, devices without neighbour data and ports that
     * look like unmanaged switches.
     */
    public function index(Request $request): View
    {
        return $this->page($request, 'coverage', 'coverage report',
            fn (array $params, PluginSettings $settings) => $this->coverage($request, $settings)->report($params),
            ['canAddDevices' => self::canAddDevices($request->user())]);
    }

    /**
     * api/coverage: the same data as JSON.
     */
    public function api(Request $request): JsonResponse
    {
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);

        return $this->json('coverage report', fn () => $this->coverage($request, $settings)->report($params), 'coverage');
    }

    private function coverage(Request $request, PluginSettings $settings): CoverageService
    {
        return new CoverageService(new CoverageRepository($request->user()), new TopologyRepository($request->user()), $settings);
    }

    /**
     * May the user add devices (the "Add Device" page of LibreNMS)?
     */
    private static function canAddDevices(mixed $user): bool
    {
        try {
            return $user && ($user->can('device.create') || (method_exists($user, 'isAdmin') && $user->isAdmin()));
        } catch (\Throwable) {
            return false;
        }
    }
}
