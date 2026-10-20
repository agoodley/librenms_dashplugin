<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON API behind the map pages, also usable for automation:
 * GET plugin/topology/api/{l2,l3,stp,links,redundancy} with the same query parameters as the pages
 * (device, depth, group, location, vlan, vrf and the map toggles).
 */
class ApiController extends Controller
{
    use BuildsTopology;

    /**
     * Layer 2 map: devices, LLDP/CDP links, VLANs and STP state.
     */
    public function l2(Request $request): JsonResponse
    {
        return $this->respond($request, 'l2');
    }

    /**
     * Layer 3 map: devices, subnets, OSPF adjacencies and BGP sessions.
     */
    public function l3(Request $request): JsonResponse
    {
        return $this->respond($request, 'l3');
    }

    /**
     * Spanning tree diagnostics (findings, domains, bridges, blocked ports, events).
     */
    public function stp(Request $request): JsonResponse
    {
        return $this->respond($request, 'stp');
    }

    /**
     * Link and VLAN diagnostics (findings and one row per link with both ends).
     */
    public function links(Request $request): JsonResponse
    {
        return $this->respond($request, 'links');
    }

    /**
     * Redundancy and failure impact (core, findings, failure impact table, outages).
     */
    public function redundancy(Request $request): JsonResponse
    {
        return $this->respond($request, 'redundancy');
    }

    /**
     * Build one of the data sets; errors become a JSON {error} with HTTP 500.
     */
    private function respond(Request $request, string $map): JsonResponse
    {
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);

        try {
            return response()->json($this->service($request, $settings)->$map($params));
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure("$map view", $e)], 500);
        }
    }
}
