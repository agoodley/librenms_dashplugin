<?php

namespace Agoodley\LibrenmsTopology\Routing;

use Agoodley\LibrenmsTopology\Data\RoutingRepository;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * Loads the routing data of every device the user may see and runs the routing & IP checks,
 * reporting on the page's scope (routers outside a group or location still count as neighbors).
 * The rows are streamed into the analyzer; with a device selected only the addresses its checks
 * need are read (see focus()).
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

        $addresses = $this->repo->addresses($all);
        if ($device !== null) {
            $addresses = self::only($addresses, ...$this->focus($device, $all));
        }

        $analyzer = new RoutingAnalyzer(
            $this->repo->devices($all),
            $scope,
            $addresses,
            $this->settings,
            $this->repo->ospf($all),
            $this->repo->bgp($all),
            $this->repo->links($all),
            $this->repo->vrfs($all),
        );

        $result = $analyzer->analyze($device, $p['vrf'] ?? null);
        $result['filter'] = $p;

        return $result;
    }

    /**
     * What the checks of one device need from the address table, by far the largest one (the OSPF,
     * BGP and link tables are read whole): all addresses of the device and of the devices it is
     * related to (LLDP/CDP neighbors, OSPF neighbors and BGP peers in either direction, routers with
     * its router ID or one of its neighbors'), the address of every OSPF neighbor (the OSPF domains
     * and adjacencies of the whole network are made from them, so they come out as without the
     * device filter), every address on a linked port or its subinterfaces and all addresses of the
     * devices with a link end without addresses (they decide which routing tables each link joins),
     * and every address in one of the device's subnets (other devices' own addresses next to a shared
     * one). RoutingServiceTest checks that a device's view stays the same.
     *
     * @param  int[]  $all  the devices the user may see
     * @return array{0: array<int, true>, 1: array<string, true>, 2: array<string, true>, 3: array<int, true>, 4: array<int, array<string, int>>}
     *                devices, binary addresses, subnet keys, linked ports, linked port names per device (=> port)
     */
    private function focus(int $device, array $all): array
    {
        $related = [$device => true];
        $mine = [];
        $subnets = [];
        foreach ($this->repo->addresses([$device]) as $row) {
            if (($packed = Address::pack($row['address'] ?? null)) !== null) {
                $mine[$packed] = true;
                $subnets[Address::networkKey($packed, (int) ($row['prefixlen'] ?? strlen($packed) * 8))] = true;
            }
        }
        $ports = [];
        $parents = [];
        $ends = [];
        foreach ($this->repo->links($all) as $link) {
            if ((int) $link['local_device_id'] === $device) {
                $related[(int) $link['remote_device_id']] = true;
            } elseif ((int) $link['remote_device_id'] === $device) {
                $related[(int) $link['local_device_id']] = true;
            }
            foreach (['local', 'remote'] as $end) {
                $port = (int) ($link[$end . '_port_id'] ?? 0);
                $ends[] = [(int) $link[$end . '_device_id'], $port];
                if ($port > 0) {
                    $ports[$port] = true;
                    if (($name = RoutingAnalyzer::portName($link, $end)) !== null) {
                        $parents[(int) $link[$end . '_device_id']][$name] = $port;
                    }
                }
            }
        }
        // the devices with a link end that has no address: the VRFs of the end come from all their addresses
        $addressed = [];
        foreach ($ends ? $this->repo->addresses($all) : [] as $row) {
            if (($packed = Address::pack($row['address'] ?? null)) === null || RoutingAnalyzer::ignorable($packed)) {
                continue;
            }
            $parent = isset($parents[(int) $row['device_id']]) ? RoutingAnalyzer::parentPort(InterfaceName::short(InterfaceName::label($row))) : null;
            $addressed[$parents[(int) $row['device_id']][$parent ?? ''] ?? (int) ($row['port_id'] ?? 0)] = true;
        }
        foreach ($ends as [$deviceId, $port]) {
            if (! isset($addressed[$port]) || $port === 0) {
                $related[$deviceId] = true;
            }
        }

        // what the device's own OSPF and BGP rows point at
        $own = $this->repo->ospf([$device]);
        $routerIds = [];
        $theirIds = [];
        $targets = [];
        foreach ($own['instances'] as $row) {
            $routerIds[trim((string) ($row['ospfRouterId'] ?? ''))] = true;
        }
        foreach ($own['neighbors'] as $row) {
            $targets[] = $row['ospfNbrIpAddr'] ?? null;
            $theirIds[trim((string) ($row['ospfNbrRtrId'] ?? ''))] = true;
        }
        foreach ($this->repo->bgp([$device])['peers'] as $row) {
            $targets[] = $row['bgpPeerIdentifier'] ?? null;
            $targets[] = $row['bgpPeerRemoteAddr'] ?? null;
        }
        unset($routerIds[''], $routerIds['0.0.0.0'], $theirIds[''], $theirIds['0.0.0.0']);

        // the routers pointing at this one (and those with its router IDs), and the address of every
        // OSPF neighbor: the OSPF domains and adjacencies of the whole network are made from them
        $neighbors = $mine;
        $ospf = $this->repo->ospf($all);
        foreach ($ospf['instances'] as $row) {
            $routerId = trim((string) ($row['ospfRouterId'] ?? ''));
            if (isset($routerIds[$routerId]) || isset($theirIds[$routerId])) {
                $related[(int) $row['device_id']] = true;
            }
        }
        foreach ($ospf['neighbors'] as $row) {
            $packed = Address::pack($row['ospfNbrIpAddr'] ?? null);
            if ($packed !== null) {
                $neighbors[$packed] = true;
            }
            if (($packed !== null && isset($mine[$packed])) || isset($routerIds[trim((string) ($row['ospfNbrRtrId'] ?? ''))])) {
                $related[(int) $row['device_id']] = true;
            }
        }
        foreach ($this->repo->bgp($all)['peers'] as $row) {
            foreach (['bgpPeerIdentifier', 'bgpPeerRemoteAddr'] as $column) {
                if (isset($mine[(string) Address::pack($row[$column] ?? null)])) {
                    $related[(int) $row['device_id']] = true;
                }
            }
        }

        // the devices owning what this device points at
        $texts = [];
        foreach ($targets as $target) {
            if (($packed = Address::pack($target)) !== null) {
                $texts[(string) inet_ntop($packed)] = true;
            }
        }
        foreach ($this->repo->devicesWithAddresses(array_map('strval', array_keys($texts)), $all) as $deviceId) {
            $related[$deviceId] = true;
        }

        return [$related, $neighbors, $subnets, $ports, $parents];
    }

    /**
     * The address rows of the related devices, at the addresses, in the subnets and on the linked
     * ports (or their subinterfaces).
     *
     * @param  array<int, true>  $devices
     * @param  array<string, true>  $addresses  binary
     * @param  array<string, true>  $subnets  Address::networkKey()
     * @param  array<int, true>  $ports
     * @param  array<int, array<string, int>>  $parents  device => linked port names => port
     */
    private static function only(iterable $rows, array $devices, array $addresses, array $subnets, array $ports, array $parents): \Generator
    {
        foreach ($rows as $row) {
            $packed = Address::pack($row['address'] ?? null);
            $deviceId = (int) ($row['device_id'] ?? 0);
            if ($packed !== null && (isset($devices[$deviceId]) || isset($addresses[$packed]) || isset($ports[(int) ($row['port_id'] ?? 0)])
                || isset($subnets[Address::networkKey($packed, (int) ($row['prefixlen'] ?? strlen($packed) * 8))])
                || (isset($parents[$deviceId]) && isset($parents[$deviceId][RoutingAnalyzer::parentPort(InterfaceName::short(InterfaceName::label($row))) ?? ''])))) {
                yield $row;
            }
        }
    }
}
