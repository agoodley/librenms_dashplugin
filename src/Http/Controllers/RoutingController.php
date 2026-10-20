<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Data\RoutingRepository;
use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Routing\RoutingService;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Routing & IP diagnostics: the page (plugin/topology/routing) and its JSON (api/routing), with
 * the same query parameters (device, group, location, vrf).
 */
class RoutingController extends Controller
{
    use BuildsTopology;

    /**
     * plugin/topology/routing: findings and the OSPF and BGP tables.
     */
    public function index(Request $request): View
    {
        return $this->page($request, 'routing', 'routing analysis',
            fn (array $params, PluginSettings $settings) => $this->routing($request, $settings)->analyze($params));
    }

    /**
     * api/routing: the same data as JSON; errors become {error} with HTTP 500.
     */
    public function api(Request $request): JsonResponse
    {
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);

        return $this->json('routing analysis', fn () => $this->routing($request, $settings)->analyze($params));
    }

    /**
     * Routing service bound to the logged in user's permissions.
     */
    private function routing(Request $request, PluginSettings $settings): RoutingService
    {
        return new RoutingService(new RoutingRepository($request->user()), $settings);
    }
}
