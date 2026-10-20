<?php

namespace Agoodley\LibrenmsTopology\Topology;

use Agoodley\LibrenmsTopology\Data\SiteRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * The site / WAN map: every device the user may see grouped by location, joined by the neighbor
 * links, point-to-point subnets and routing sessions between locations. Links and subnets are
 * found the same way as on the L2 and L3 maps (L2Builder::uniqueLinks(), L3Builder).
 */
class SiteService
{
    public function __construct(
        private readonly TopologyRepository $repo,
        private readonly SiteRepository $sites,
        private readonly PluginSettings $settings,
    ) {
    }

    /**
     * @param  array{group: ?int, location: ?int, depth: int, ospf: bool, bgp: bool, isolated: bool}  $p  the location
     *                                                                                              is the centered site
     */
    public function sites(array $p): array
    {
        $devices = $this->sites->devices($this->sites->deviceIds($p['group']));
        // the roles the maps show (bridging and routing facts), not only the device type
        foreach ($this->repo->devices(array_keys($devices)) as $id => $device) {
            $devices[$id]['role'] = $device['role'];
        }
        $ids = array_keys($devices);
        $connections = new SiteConnections($devices);

        // neighbor links between locations, one per cable
        $links = $this->sites->siteLinks($ids);
        if ($links) {
            $linkDevices = array_unique(array_merge(array_column($links, 'local_device_id'), array_column($links, 'remote_device_id')));
            $lag = $this->repo->lagParents(array_map('intval', $linkDevices));
            $portIds = array_values(array_filter(array_unique(array_merge(array_column($links, 'local_port_id'), array_column($links, 'remote_port_id')))));
            $ports = $this->repo->ports(array_merge($portIds, array_values(array_intersect_key($lag, array_flip($portIds)))));
            $connections->addLinks(L2Builder::uniqueLinks($links, $devices, $ports), $ports, $lag);
        }

        // point-to-point subnets and routing sessions, as on the L3 map
        $bgp = $p['bgp'] ? $this->repo->bgpPeers($ids) : [];
        $l3 = (new L3Builder($devices, $this->repo->addresses($ids, true), $p['ospf'] ? $this->repo->ospfNeighbors($ids) : [], $bgp))->build([
            'hideStubs' => true,
            'p2pPrefix4' => $this->settings->int('l3_p2p_prefix', 8, 32),
            'p2pPrefix6' => 126,
            'exclude' => $this->settings->list('l3_exclude'),
            'ospf' => $p['ospf'],
            'bgp' => $p['bgp'],
        ]);
        $connections->addL3($l3, $this->repo->ports($connections->subnetPorts($l3)), $bgp);

        $down = array_keys(array_filter($devices, fn ($device) => L2Builder::deviceStatus($device) === 'down'));
        $maxSites = $this->settings->int('max_nodes', 10, 5000);
        $result = (new SiteBuilder($devices, $connections->all(), $this->repo->outageStarts($down)))->build([
            'center' => $p['location'],
            'depth' => $p['depth'],
            'isolated' => $p['isolated'],
            'maxSites' => $maxSites,
        ]);

        $center = $result['center'] ? current(array_filter($result['nodes'], fn ($node) => $node['id'] === $result['center'])) : null;
        $result['scope'] = [
            'root' => $center ? $center['location_id'] : null,
            'root_name' => $center ? $center['name'] : null,
            'depth' => $p['depth'],
            'group' => $p['group'],
            'location' => $p['location'],
            'total' => $result['total'],
            'shown' => $result['stats']['sites'],
            'truncated' => $result['truncated'],
            'max_nodes' => $maxSites,
        ];

        return $result;
    }
}
