<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Data\ViewRepository;
use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * JSON API behind the map pages, also usable for automation:
 * GET plugin/topology/api/{l2,l3,stp,links,redundancy} with the same query parameters as the pages
 * (device, depth, group, location, vlan, vrf and the map toggles), and api/simulate?fail=...
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
     * Failure simulation of the L2 map: GET api/simulate?fail=d:12,p:345 (devices by map node id,
     * links by one of their ports, see RedundancyAnalyzer::simulate()).
     */
    public function simulate(Request $request): JsonResponse
    {
        $refs = array_slice(array_values(array_filter(array_map('trim', explode(',', (string) $request->query('fail', ''))),
            fn ($ref) => $ref !== '' && strlen($ref) <= 300)), 0, 50);

        return $this->json('failure simulation', fn () => $this->service($request)->simulate($refs));
    }

    /**
     * Build one of the data sets; errors become a JSON {error} with HTTP 500. A map with ?view= also
     * gets the saved view (view) and its positions for this map's nodes (view_layout).
     */
    private function respond(Request $request, string $map): JsonResponse
    {
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);

        return $this->json("$map view", function () use ($request, $settings, $map, $params) {
            $result = $this->service($request, $settings)->$map($params);
            $view = $request->query('view');
            if (in_array($map, ['l2', 'l3'], true) && $view !== null) {
                try {
                    $saved = is_scalar($view) && ctype_digit((string) $view)
                        ? (new ViewRepository($request->user()))->layoutFor((int) $view, $map, $result['nodes'] ?? []) : null;
                } catch (\Throwable $e) {
                    // the map works without its saved view (table missing or broken)
                    Log::warning('topology plugin: cannot read saved view ' . $view . ': ' . $e->getMessage());
                    $saved = null;
                }
                $result['view'] = $saved['view'] ?? null;
                $result['view_layout'] = $saved !== null ? (object) $saved['layout'] : null;
                if ($saved === null) {
                    $result['view_error'] = 'This saved view does not exist or is not shared with you.';
                }
            }

            return $result;
        });
    }
}
