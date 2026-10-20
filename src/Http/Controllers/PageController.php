<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Data\ViewRepository;
use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Sharing\SavedView;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Pages of the plugin. The map pages are shells filled by resources/assets/topology.js from the
 * JSON API; the STP, link and redundancy diagnostics pages are rendered on the server.
 */
class PageController extends Controller
{
    use BuildsTopology;

    /**
     * plugin/topology/l2: Layer 2 map.
     */
    public function l2(Request $request): View|RedirectResponse
    {
        return $this->map($request, 'l2');
    }

    /**
     * plugin/topology/l3: Layer 3 map.
     */
    public function l3(Request $request): View|RedirectResponse
    {
        return $this->map($request, 'l3');
    }

    /**
     * plugin/topology/stp: spanning tree diagnostics.
     */
    public function stp(Request $request): View
    {
        return $this->diagnostics($request, 'stp', 'spanning tree analysis');
    }

    /**
     * plugin/topology/links: link and VLAN diagnostics.
     */
    public function links(Request $request): View
    {
        return $this->diagnostics($request, 'links', 'link analysis');
    }

    /**
     * plugin/topology/redundancy: single points of failure, fake and lost redundancy, outages.
     */
    public function redundancy(Request $request): View
    {
        return $this->diagnostics($request, 'redundancy', 'redundancy analysis');
    }

    /**
     * Diagnostics page rendered on the server: TopologyService::$analysis() shown with view
     * topology::$analysis, plus the filter choices; a failure is shown on the page.
     */
    private function diagnostics(Request $request, string $analysis, string $what): View
    {
        return $this->page($request, $analysis, $what, fn (array $params, $settings) => $this->service($request, $settings)->$analysis($params));
    }

    /**
     * Map page shell with the filter choices (device groups, locations) and current parameters. With
     * ?view=, a saved view the user may open gives the parameters (the URL's own ones win), and the map
     * of its page is opened.
     */
    private function map(Request $request, string $mode): View|RedirectResponse
    {
        $settings = $this->settings();
        $query = $request->query();
        $views = new ViewRepository($request->user());
        $savedView = null;
        $viewError = null;
        if (isset($query['view'])) {
            $view = is_scalar($query['view']) && ctype_digit((string) $query['view']) ? $views->find((int) $query['view']) : null;
            unset($query['view']);
            if ($view !== null && $view['page'] !== $mode) {
                return redirect()->route('topology.' . $view['page'], ['view' => $view['id']] + $query);
            }
            if ($view !== null) {
                unset($view['positions']);
                $savedView = $view;
                $query += SavedView::query($mode, $view['params']);
            } else {
                $viewError = 'This saved view does not exist or is not shared with you.';
            }
        }
        $params = MapParams::parse($query, $settings);
        $repo = new TopologyRepository($request->user());

        return view('topology::map', [
            'mode' => $mode,
            'savedView' => $savedView,
            'viewError' => $viewError,
            'views' => $views->visible($mode),
            'params' => $params,
            'filters' => $repo->filterOptions(),
            'deviceName' => $params['device'] ? $repo->deviceName($params['device']) : null,
            'assetVersion' => self::assetVersion(),
            'canExportCustomMap' => ExportController::allowed($request->user()),
        ]);
    }
}
