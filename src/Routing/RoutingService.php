<?php

namespace Agoodley\LibrenmsTopology\Routing;

use Agoodley\LibrenmsTopology\Data\RoutingRepository;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * Loads the routing data of every device the user may see and runs the routing & IP checks,
 * reporting on the page's scope (routers outside a group or location still count as neighbors).
 */
class RoutingService
{
    public function __construct(
        private readonly RoutingRepository $repo,
        private readonly PluginSettings $settings,
    ) {
    }

    /**
     * @param  array{device: ?int, group: ?int, location: ?int, vrf: ?string}  $p  see MapParams::parse()
     */
    public function analyze(array $p): array
    {
        [$scope, $device] = $this->repo->scope($p);
        $all = $this->repo->deviceIds();

        $analyzer = new RoutingAnalyzer(
            $this->repo->devices($all),
            $scope,
            $this->repo->addresses($all),
            $this->settings,
            $this->repo->ospf($all),
            $this->repo->bgp($all),
            $this->repo->lldpNeighbors($all),
            $this->repo->vrfAs($all),
        );

        $result = $analyzer->analyze($device, $p['vrf'] ?? null);
        $result['filter'] = $p;

        return $result;
    }
}
