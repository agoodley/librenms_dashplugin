<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Pages of the plugin. The map pages are shells filled by resources/assets/topology.js from the
 * JSON API; the STP and link diagnostics pages are rendered on the server.
 */
class PageController extends Controller
{
    use BuildsTopology;

    /**
     * plugin/topology/l2: Layer 2 map.
     */
    public function l2(Request $request): View
    {
        return $this->map($request, 'l2');
    }

    /**
     * plugin/topology/l3: Layer 3 map.
     */
    public function l3(Request $request): View
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
     * Diagnostics page rendered on the server: TopologyService::$analysis() shown with view
     * topology::$analysis, plus the filter choices; a failure is shown on the page.
     */
    private function diagnostics(Request $request, string $analysis, string $what): View
    {
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);
        $repo = new TopologyRepository($request->user());

        $result = null;
        $error = null;
        try {
            $result = $this->service($request, $settings)->$analysis($params);
        } catch (\Throwable $e) {
            $error = $this->failure($what, $e);
        }

        return view("topology::$analysis", [
            'params' => $params,
            'result' => $result,
            'error' => $error,
            'filters' => $repo->filterOptions(),
            'deviceName' => $params['device'] ? $repo->deviceName($params['device']) : null,
            'assetVersion' => self::assetVersion(),
        ]);
    }

    /**
     * Map page shell with the filter choices (device groups, locations) and current parameters.
     */
    private function map(Request $request, string $mode): View
    {
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);
        $repo = new TopologyRepository($request->user());

        return view('topology::map', [
            'mode' => $mode,
            'params' => $params,
            'filters' => $repo->filterOptions(),
            'deviceName' => $params['device'] ? $repo->deviceName($params['device']) : null,
            'assetVersion' => self::assetVersion(),
        ]);
    }

    /**
     * Cache busting version of the JS/CSS assets (newest modification time).
     */
    public static function assetVersion(): string
    {
        $files = glob(__DIR__ . '/../../../resources/assets/*') ?: [];

        return (string) array_reduce($files, fn ($carry, $file) => max($carry, (int) filemtime($file)), 0);
    }
}
