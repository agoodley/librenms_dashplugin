<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Data\InterfaceRepository;
use Agoodley\LibrenmsTopology\History\FindingBadges;
use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Interfaces\InterfaceService;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Interface health: the page (plugin/topology/interfaces) and its JSON (api/interfaces), with the
 * same query parameters (device, group, location).
 */
class InterfaceController extends Controller
{
    use BuildsTopology;

    /**
     * plugin/topology/interfaces: findings and the ports with changes.
     */
    public function index(Request $request): View
    {
        return $this->page($request, 'interfaces', 'interface health analysis',
            fn (array $params, PluginSettings $settings) => $this->analyze($request, $settings, $params));
    }

    /**
     * api/interfaces: the same data as JSON; errors become {error} with HTTP 500.
     */
    public function api(Request $request): JsonResponse
    {
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);

        return $this->json('interface health analysis', fn () => $this->analyze($request, $settings, $params), 'interfaces');
    }

    /**
     * The analysis with the logged in user's permissions, its findings marked from the history of
     * the background checks (FindingBadges).
     */
    private function analyze(Request $request, PluginSettings $settings, array $params): array
    {
        return FindingBadges::annotate('interfaces', (new InterfaceService(new InterfaceRepository($request->user()), $settings))->analyze($params),
            new HistoryRepository($request->user()), 120 * $settings->int('history_interval', 5, 1440));
    }
}
