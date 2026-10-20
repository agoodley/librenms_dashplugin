<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Data\SiteRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Topology\SiteService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Site / WAN map: plugin/topology/sites is a map shell drawn by resources/assets/topology.js from
 * plugin/topology/api/sites (query parameters: location = centered site, depth, group, ospf, bgp, isolated).
 */
class SiteController extends Controller
{
    use BuildsTopology;

    /**
     * plugin/topology/sites: the map page.
     */
    public function show(Request $request): View
    {
        return $this->page($request, 'map', 'site map', fn () => null, ['mode' => 'sites']);
    }

    /**
     * plugin/topology/api/sites: sites, site links and AS clouds (SiteService::sites()).
     */
    public function api(Request $request): JsonResponse
    {
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);
        $service = new SiteService(new TopologyRepository($request->user()), new SiteRepository($request->user()), $settings);

        return $this->json('site map', fn () => $service->sites($params));
    }
}
