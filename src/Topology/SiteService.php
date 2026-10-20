<?php

namespace Agoodley\LibrenmsTopology\Topology;

use Agoodley\LibrenmsTopology\Data\SiteRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * The site / WAN map: every device the user may see grouped by location, joined by the neighbor
 * links, point-to-point subnets and routing sessions between locations. Links are found as on the
 * L2 map (L2Builder::uniqueLinks()) and subnets as on the L3 map (L3Builder), from the addresses
 * with a point-to-point prefix only; routing neighbors are looked up among every device the user
 * may see (SiteSessions).
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
        $ids = $this->sites->deviceIds($p['group']);
        $devices = $this->repo->devices($ids);
        foreach ($this->sites->locations($ids) as $id => $location) {
            if (isset($devices[$id])) {
                $devices[$id] = $location + $devices[$id];
            }
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

        // point-to-point subnets between locations, on the interfaces under them
        $prefix4 = $this->settings->int('l3_p2p_prefix', 8, 32);
        $l3 = (new L3Builder($devices, $this->sites->subnetAddresses($ids, $prefix4, 126)))->build([
            'hideStubs' => true,
            'p2pPrefix4' => $prefix4,
            'p2pPrefix6' => 126,
            'exclude' => $this->settings->list('l3_exclude'),
            'ospf' => false,
            'bgp' => false,
        ]);
        $subnetPorts = $connections->subnetPorts($l3);
        if ($subnetPorts) {
            $ports = $this->repo->ports($subnetPorts);
            $stack = $this->stack($ports);
            $below = SiteConnections::below($subnetPorts, $stack);
            $connections->addSubnets($l3, $ports + $this->repo->ports($below), $stack, $this->sites->portNeighbors(array_merge($subnetPorts, $below)));
        }

        // OSPF adjacencies and BGP sessions
        $ospf = $p['ospf'] ? $this->repo->ospfNeighbors($ids) : [];
        $bgp = $p['bgp'] ? $this->repo->bgpPeers($ids) : [];
        $owners = $ospf || $bgp ? $this->sites->addressOwners(array_merge(array_column($ospf, 'ospfNbrIpAddr'),
            array_column($ospf, 'ospfNbrRtrId'), array_column($bgp, 'bgpPeerIdentifier'), array_column($bgp, 'bgpLocalAddr'))) : [];
        $sessions = (new SiteSessions($devices, $owners, $this->sites->interfaceAddresses(SiteSessions::unknownSources($bgp, $owners))))
            ->addOspf($ospf)->addBgp($bgp);

        $down = array_keys(array_filter($devices, fn ($device) => L2Builder::deviceStatus($device) === 'down'));
        $maxSites = $this->settings->int('max_nodes', 10, 5000);
        $result = (new SiteBuilder($devices, array_merge($connections->all(), $sessions->all()), $this->repo->outageStarts($down)))->build([
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

    /**
     * Interface stack under the subnet ports' devices: ifStackTable pairs (either orientation),
     * port-channel membership (also Cisco PAgP) and the parents of sub-interfaces the stack does not
     * list, by name.
     *
     * @param  array<int, array>  $ports  port_id => row of the subnet ports
     * @return array<int, int[]> port_id => ports right below it
     */
    private function stack(array $ports): array
    {
        $deviceIds = array_values(array_unique(array_map('intval', array_column($ports, 'device_id'))));
        $pairs = $this->sites->stackPairs($deviceIds);
        $paired = $pairs ? $this->repo->ports(array_merge(...$pairs), ['port_id', 'device_id', 'ifName', 'ifDescr', 'ifType']) : [];
        $stack = SiteConnections::stack($pairs, $paired, $this->repo->lagParents($deviceIds));

        $parents = SiteConnections::unitParents($ports, $stack);
        $names = [];
        foreach ($parents as $parent) {
            $names[$parent['device_id']] = array_merge($names[$parent['device_id']] ?? [], $parent['names']);
        }
        $found = $this->sites->portsByName($names);
        foreach ($parents as $portId => $parent) {
            foreach ($parent['names'] as $name) {
                if (isset($found[$parent['device_id']][$name])) {
                    $stack[$portId] = [$found[$parent['device_id']][$name]];
                    break;
                }
            }
        }

        return $stack;
    }
}
