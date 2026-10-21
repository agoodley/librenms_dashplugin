<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Path\L2Tracer;
use Agoodley\LibrenmsTopology\Path\L3Tracer;
use PHPUnit\Framework\TestCase;

/**
 * L2Tracer and L3Tracer on PathFixture's campus: forwarding paths per spanning tree instance, port-channels as one hop,
 * no transit through routers or routed ports, devices out of sight, what stops a VLAN, how far the links must be loaded,
 * and router-by-router lookups with ECMP, kept route history, interfaces that are down, estimates where a router has no
 * current routes (and why), never back onto the path, IPv6 link-local and recursive next hops, an address several
 * devices have, and the ways a routed path ends.
 */
class PathTracerTest extends TestCase
{
    private const D1 = PathFixture::DIST1;

    private const D2 = PathFixture::DIST2;

    private const A1 = PathFixture::ACC1;

    private const A2 = PathFixture::ACC2;

    public function testTheL2PathFollowsTheForwardingPortsOfTheVlansInstance(): void
    {
        $net = PathFixture::campus();

        $trace = $net->l2(110)->trace(self::A1, self::A2, 110, ['enter' => 305, 'exit' => 406]);
        $this->assertTrue($trace['found']);
        $this->assertSame(['acc1', 'dist1', 'acc2'], PathFixture::names($trace), 'VLAN 110: through its root dist1, not the blocked uplinks to dist2');
        $this->assertSame([[305, 301], [111, 120], [400, 406]], array_map(fn ($hop) => [$hop['in_port'], $hop['out_port']], $trace['hops']),
            'in and out ports, the port-channels by their aggregate');
        $this->assertSame(0, $trace['alternatives']);

        $trace = $net->l2(120)->trace(self::A1, self::A2, 120, ['enter' => 305, 'exit' => 406]);
        $this->assertSame(['acc1', 'dist2', 'acc2'], PathFixture::names($trace), 'VLAN 120: through dist2');
        $this->assertSame([302, 403], [$trace['hops'][0]['out_port'], $trace['hops'][2]['in_port']]);
    }

    public function testWithoutSpanningTreeDataEqualPathsAreReported(): void
    {
        $net = PathFixture::campus();
        $net->instances = [];
        $net->stpPorts = [];

        $trace = $net->l2(110)->trace(self::A1, self::A2, 110);
        $this->assertSame(1, $trace['alternatives'], 'through dist1 and through dist2');
        $this->assertTrue($trace['unknown_stp']);
        $this->assertSame('dist1', $trace['hops'][1]['name'], 'the faster uplinks (10G to dist1, 1G to dist2)');
    }

    public function testDownPortsAndVlansNotCarriedAreAvoided(): void
    {
        $net = PathFixture::campus();
        $net->ports[301]['ifOperStatus'] = 'down';
        $net->instances = [];
        $net->stpPorts = [];

        $this->assertSame(['acc1', 'dist2', 'acc2'], PathFixture::names($net->l2(110)->trace(self::A1, self::A2, 110)), 'the uplink to dist1 is down');

        $net = PathFixture::campus();
        $net->portVlans[400] = [1 => true, 120 => false]; // acc2's Po1 no longer carries VLAN 110
        $tracer = $net->l2(110);
        $this->assertFalse($tracer->trace(self::A1, self::A2, 110)['found'], 'Po1 does not carry it and the uplink to dist2 is blocking');
        $why = array_column($tracer->blockers(self::A1, self::A2, 110), 'why', 'port_id');
        $this->assertSame('does not carry VLAN 110', $why[400]);
        $this->assertSame('blocking', $why[403]);
        $this->assertSame([], $net->l2(110)->blockers(self::A1, 99, 110), 'not linked at all: no blockers');
    }

    public function testRoutersAndRoutedPortsAreNoTransit(): void
    {
        $net = PathFixture::campus();
        // a second link between dist2 and rtr1 must not make rtr1 a shortcut between the distribution switches
        $net->instances = [];
        $net->stpPorts = [];
        $net->port(150, self::D1, 'Te1/0/50')->port(250, self::D2, 'Te1/0/50');
        $net->ports[100]['ifOperStatus'] = 'down';
        $net->ports[101]['ifOperStatus'] = $net->ports[102]['ifOperStatus'] = 'down';
        $trace = $net->l2(null)->trace(self::D1, self::D2, null);
        $this->assertNotContains('rtr1', PathFixture::names($trace), 'never through the router');
        $this->assertSame(['dist1', 'acc2', 'dist2'], PathFixture::names($trace), 'over acc2\'s faster uplinks');

        // a path may start on a routed port: the router's link to dist1
        $trace = $net->l2(null)->trace(PathFixture::RTR1, self::D1, null, ['leave' => 501, 'arrive' => 148]);
        $this->assertSame(['rtr1', 'dist1'], PathFixture::names($trace));
        $this->assertNull($net->l2(null)->directEdge(PathFixture::RTR1, self::D1, 502, 148), 'Gi0/0/1 goes to dist2');
        $this->assertNotNull($net->l2(null)->directEdge(PathFixture::RTR1, self::D1, 501, 148));
    }

    public function testADeviceOutOfSightIsCrossedAnonymously(): void
    {
        $net = PathFixture::campus();
        unset($net->devices[self::D1], $net->devices[self::D2]);
        // what the repository makes of the links to them: one marker per hidden device
        foreach ($net->links as &$link) {
            if (in_array($link['remote_device_id'], [self::D1, self::D2], true)) {
                $link = ['remote_device_id' => 0, 'remote_port_id' => null, 'remote_hostname' => L2Tracer::HIDDEN . $link['remote_device_id'], 'remote_port' => ''] + $link;
            }
        }
        unset($link);
        $net->instances = array_values(array_filter($net->instances, fn ($row) => in_array($row['device_id'], [self::A1, self::A2], true)));

        $trace = $net->l2(110)->trace(self::A1, self::A2, 110);
        $this->assertSame(['acc1', 'a device you may not see', 'acc2'], PathFixture::names($trace));
        $this->assertSame(['device', 'hidden', 'device'], array_column($trace['hops'], 'kind'));
        $this->assertNull($trace['hops'][1]['device_id']);
        $this->assertSame([null, null], [$trace['hops'][1]['in_port'], $trace['hops'][1]['out_port']], 'no ports of it');
    }

    public function testAnUnmanagedSwitchIsAHopButAnAccessPointIsNot(): void
    {
        $net = PathFixture::campus();
        $net->instances = [];
        $net->stpPorts = [];
        // acc1 and acc2 also meet through a desk switch nobody monitors, and both see the same access point
        $net->port(310, self::A1, 'Gi1/0/10', 1)->port(410, self::A2, 'Gi1/0/10', 1)->port(311, self::A1, 'Gi1/0/11', 1)->port(411, self::A2, 'Gi1/0/11', 1);
        $net->neighbor(310, 'desk-sw.example.net', 'port 1', 'Netgear GS108')->neighbor(410, 'desk-sw.example.net', 'port 2', 'Netgear GS108');
        $net->neighbor(311, 'ap-7', 'eth0', 'Cisco AIR-AP2802I')->neighbor(411, 'ap-7', 'eth1', 'Cisco AIR-AP2802I');
        foreach ([301, 302, 400, 403] as $uplink) {
            $net->ports[$uplink]['ifOperStatus'] = 'down';
        }

        $trace = $net->l2(110)->trace(self::A1, self::A2, 110);
        $this->assertSame(['acc1', 'desk-sw.example.net', 'acc2'], PathFixture::names($trace), 'through the desk switch, never the access point');
        $this->assertSame(['device', 'unmanaged', 'device'], array_column($trace['hops'], 'kind'));
        $this->assertSame([310, 410], [$trace['hops'][0]['out_port'], $trace['hops'][2]['in_port']]);
    }

    public function testAPortChannelAcrossASwitchPairIsReported(): void
    {
        $net = PathFixture::campus();
        $net->instances = [];
        $net->stpPorts = [];
        // acc3 and acc4, each with one port-channel to both distribution switches (vPC)
        $net->device(8, 'acc3', 'switch')->device(9, 'acc4', 'switch');
        $net->port(800, 8, 'Po3', 20, 'ieee8023adLag')->port(801, 8, 'Te1/1/1')->port(802, 8, 'Te1/1/2')->member(801, 800)->member(802, 800);
        $net->port(900, 9, 'Po4', 20, 'ieee8023adLag')->port(901, 9, 'Te1/1/1')->port(902, 9, 'Te1/1/2')->member(901, 900)->member(902, 900);
        $net->port(130, self::D1, 'Po30', 10, 'ieee8023adLag')->port(131, self::D1, 'Te1/0/31')->member(131, 130);
        $net->port(230, self::D2, 'Po30', 10, 'ieee8023adLag')->port(231, self::D2, 'Te1/0/31')->member(231, 230);
        $net->port(140, self::D1, 'Po40', 10, 'ieee8023adLag')->port(141, self::D1, 'Te1/0/41')->member(141, 140);
        $net->port(240, self::D2, 'Po40', 10, 'ieee8023adLag')->port(241, self::D2, 'Te1/0/41')->member(241, 240);
        $net->link(801, 131)->link(802, 231)->link(901, 141)->link(902, 241);

        $trace = $net->l2(null)->trace(8, 9, null);
        $this->assertSame(1, $trace['alternatives']);
        $this->assertTrue($trace['mlag']);
        $this->assertSame(900, $trace['hops'][2]['in_port'], 'into acc4 by its port-channel');
    }

    public function testTheRoutedPathGoesRouterByRouterToTheConnectedSubnet(): void
    {
        $net = PathFixture::campus();
        $trace = $net->l3()->trace(self::D1, 1110, '', '10.90.130.31');

        $this->assertSame('connected', $trace['end']);
        $this->assertSame([self::D1, PathFixture::RTR1, PathFixture::RTR3, PathFixture::RTR2], array_column($trace['hops'], 'device_id'));
        [$dist1, $rtr1, $rtr3, $rtr2] = $trace['hops'];
        $this->assertSame(['0.0.0.0/0', '10.90.0.0', 148, 'static'], [$dist1['route']['prefix'], $dist1['next_hop'], $dist1['out_port'], $dist1['route']['protocol']]);
        $this->assertSame(['10.90.130.0/24', '10.90.0.5', 'OSPF'], [$rtr1['route']['prefix'], $rtr1['next_hop'], $rtr1['route']['protocol']],
            'the current route, not the kept one via 10.90.0.99');
        $this->assertSame([501, '10.90.0.0'], [$rtr1['in_port'], $rtr1['in_address']]);
        $this->assertNotNull($rtr3['estimated'], 'rtr3 has no routes: estimated from its OSPF neighbor');
        $this->assertStringContainsString('its OSPF neighbor rtr2, which has the destination\'s subnet connected', $rtr3['estimated']);
        $this->assertSame([702, 601], [$rtr3['out_port'], $rtr2['in_port']]);
        $this->assertSame(6130, $trace['egress']['port_id']);
        $this->assertNull($rtr2['estimated']);
    }

    public function testEqualCostNextHopsAreAllListed(): void
    {
        $trace = PathFixture::campus()->l3()->trace(PathFixture::RTR1, null, '', '10.90.110.11');

        $rtr1 = $trace['hops'][0];
        $this->assertSame(['10.90.0.1', '10.90.0.3'], $rtr1['route']['next_hops']);
        $this->assertSame('10.90.0.1', $rtr1['next_hop'], 'the lowest address');
        $this->assertSame([PathFixture::RTR1, self::D1], array_column($trace['hops'], 'device_id'));
        $this->assertSame([1110, '10.90.110.2'], [$trace['egress']['port_id'], $trace['egress']['address']]);
    }

    public function testTheWaysARoutedPathEnds(): void
    {
        $net = PathFixture::campus();
        $outside = $net->l3()->trace(self::D1, 1110, '', '8.8.8.8');
        $this->assertSame(['outside', '203.0.113.1'], [$outside['end'], $outside['next_hop']]);
        $this->assertSame([self::D1, PathFixture::RTR1], array_column($outside['hops'], 'device_id'));

        $local = $net->l3()->trace(self::D1, 1110, '', '10.255.0.1');
        $this->assertSame(['local', PathFixture::RTR1], [$local['end'], end($local['hops'])['device_id']], 'rtr1\'s loopback');

        $net->routes[PathFixture::RTR2] = [];
        $net->route(PathFixture::RTR2, '10.0.0.0', 8, '0.0.0.0', 0, 'static', 0, null, 5);
        $this->assertSame('blackhole', $net->l3()->trace(PathFixture::RTR2, null, '', '10.1.2.3')['end']);
        $this->assertSame('no_route', $net->l3()->trace(PathFixture::RTR2, null, '', '192.0.2.1')['end']);

        // rtr1 and dist1 pointing at each other
        $loop = PathFixture::campus();
        $loop->route(self::D1, '192.0.2.0', 24, '10.90.0.0', 148, 'static')->route(PathFixture::RTR1, '192.0.2.0', 24, '10.90.0.1', 501, 'static');
        $trace = $loop->l3()->trace(self::D1, null, '', '192.0.2.10');
        $this->assertSame(['loop', 0], [$trace['end'], $trace['loop']]);

        // rtr3 without routes and no router with the destination connected anywhere
        $this->assertSame('stuck', PathFixture::campus()->l3()->trace(PathFixture::RTR3, null, '', '192.0.2.10')['end']);
    }

    public function testVrfRoutesAndAnMplsNextHop(): void
    {
        $net = PathFixture::campus();
        // PE rtr1 has CUST-A routes toward PE rtr2's loopback (global, the MPLS next hop); rtr2 has the customer subnet in CUST-A
        $net->address(PathFixture::RTR2, 699, 'Lo0', '10.255.0.2', 32)->address(PathFixture::RTR1, 520, 'Gi0/0/3.300', '172.16.1.1', 30, 'CUST-A')
            ->address(PathFixture::RTR2, 620, 'Gi0/0/3.300', '172.16.2.1', 24, 'CUST-A');
        $net->route(PathFixture::RTR1, '172.16.2.0', 24, '10.255.0.2', 0, 'bgp', 0, null, 4, 'CUST-A');
        $net->route(PathFixture::RTR1, '172.16.2.0', 24, '10.90.0.5', 503, 'static'); // the same prefix in the global table goes elsewhere

        $trace = $net->l3()->trace(PathFixture::RTR1, 520, 'CUST-A', '172.16.2.20');
        $this->assertSame('connected', $trace['end']);
        $this->assertSame([PathFixture::RTR1, PathFixture::RTR2], array_column($trace['hops'], 'device_id'), 'the VRF route, not the global one');
        $this->assertSame(['CUST-A', 'CUST-A'], array_column($trace['hops'], 'vrf'), 'still in the VRF on the far PE');
        $this->assertSame(620, $trace['egress']['port_id']);
    }

    public function testGatewaysAreTheRoutersWithTheSubnetConnected(): void
    {
        $l3 = PathFixture::campus()->l3();

        $this->assertSame([self::D1, self::D2], array_column($l3->gateways('10.90.110.11'), 'device_id'));
        $this->assertSame([], $l3->gateways('192.0.2.1'));
        $this->assertSame(PathFixture::RTR2, $l3->owners('10.90.0.7')[0]['device_id']);
    }

    public function testTheHorizonTellsWhenThePathFoundIsTheShortest(): void
    {
        $net = PathFixture::campus();
        $tracer = $net->l2(110);
        $expanded = ['d:3' => true, 'd:4' => true];

        // acc1 and acc2 read: dist1 is one forwarding hop from both, dist2 only over links blocked in VLAN 110
        $near = $tracer->horizon('d:3', 110, null, [], $expanded);
        $this->assertSame([0, ['d:1']], [$near['radius'], $near['frontier']]);
        $this->assertSame(2, $near['distances']['d:2'], 'through dist1: acc1 Gi1/0/50 blocks VLAN 110');
        $wider = $tracer->horizon('d:3', 110, null, [], $expanded + ['d:1' => true]);
        $this->assertSame([1, ['d:2']], [$wider['radius'], $wider['frontier']]);
        // over any link, whatever its state
        $this->assertSame(1, $tracer->horizon('d:3', null, null, [], $expanded, false)['distances']['d:2']);
        // nothing left to read once the switches it reaches are
        $this->assertNull($tracer->horizon('d:3', 110, null, [], $expanded + ['d:1' => true, 'd:2' => true])['radius']);
    }

    public function testAnEstimateNeverLeadsBackToARouterOnThePath(): void
    {
        // a ring rtr1 - rtr3 - rtr2 - rtr4 - rtr1 (the last link a backup); rtr1 routes the destination via rtr3, rtr3 and rtr2 have no routes
        $net = self::ring();
        $net->route(5, '192.0.2.0', 24, '10.0.13.2', 51, 'ospf', 30);

        $trace = $net->l3()->trace(5, null, '', '192.0.2.10');
        $this->assertSame('connected', $trace['end']);
        $this->assertSame([5, 7, 6, 10], array_column($trace['hops'], 'device_id'), 'rtr3 is estimated on to rtr2, not back to rtr1 (its neighbor with the lower id)');
        $this->assertStringContainsString('the next hop is taken to be rtr2, on a subnet they share, on the shortest way toward rtr4', $trace['hops'][1]['estimated']);

        // a real route back closes a loop through the estimate: the way is not known, it is not a routing loop
        $net->route(6, '192.0.2.0', 24, '10.0.32.1', 61, 'static');
        $trace = $net->l3()->trace(5, null, '', '192.0.2.10');
        $this->assertSame(['loop', 1, true], [$trace['end'], $trace['loop'], $trace['uncertain_loop']]);
    }

    public function testAConnectedSubnetOnADownInterfaceDoesNotCount(): void
    {
        $net = PathFixture::campus();
        // rtr1 has 10.90.130.2/24 on a shut down port, and its current OSPF route to the branch via rtr3
        $net->address(PathFixture::RTR1, 530, 'Gi0/0/9', '10.90.130.2', 24, '', false);
        $this->assertSame([PathFixture::RTR2], array_column($net->l3()->gateways('10.90.130.31'), 'device_id'));
        $path = [self::D1, PathFixture::RTR1, PathFixture::RTR3, PathFixture::RTR2];
        $this->assertSame($path, array_column($net->l3()->trace(self::D1, 1110, '', '10.90.130.31')['hops'], 'device_id'));

        // the interface route of that port, read before it went down, no longer counts either
        $net->route(PathFixture::RTR1, '10.90.130.0', 24, '0.0.0.0', 530, 'local', 0, null, 3);
        $trace = $net->l3()->trace(self::D1, 1110, '', '10.90.130.31');
        $this->assertSame(['connected', $path], [$trace['end'], array_column($trace['hops'], 'device_id')]);
        $this->assertSame('OSPF', $trace['hops'][1]['route']['protocol']);
    }

    public function testRoutesThatCannotBeUsedAreEstimatedAndSayWhy(): void
    {
        $path = [self::D1, PathFixture::RTR1, PathFixture::RTR3, PathFixture::RTR2];

        // rtr1's last discovery started two days after its newest route: the module no longer reads them (or too many)
        $net = PathFixture::campus();
        $net->routeData[PathFixture::RTR1]['discovered'] = PathFixture::NOW + 2 * 86400;
        $trace = $net->l3()->trace(self::D1, 1110, '', '10.90.130.31');
        $this->assertSame($path, array_column($trace['hops'], 'device_id'));
        $rtr1 = $trace['hops'][1];
        $this->assertSame([L3Tracer::ROUTES_STALE, null], [$rtr1['estimated_why'], $rtr1['route']]);
        $this->assertStringStartsWith("LibreNMS' routes of rtr1 date from 2d before its last discovery", $rtr1['estimated']);
        $this->assertSame(PathFixture::NOW, $rtr1['routes_read']);

        // routes of another VRF only
        $net = PathFixture::campus();
        $net->routeData[PathFixture::RTR1]['contexts'] = ['CUST-A' => 5];
        $rtr1 = $net->l3()->trace(self::D1, 1110, '', '10.90.130.31')['hops'][1];
        $this->assertSame(L3Tracer::ROUTES_VRF, $rtr1['estimated_why']);
        $this->assertStringStartsWith('LibreNMS has routes of rtr1, but none in its global table', $rtr1['estimated']);

        // the route table not read at all
        $trace = PathFixture::campus()->l3(true)->trace(self::D1, 1110, '', '10.90.130.31');
        $this->assertSame([L3Tracer::ROUTES_SKIPPED], array_values(array_unique(array_filter(array_column($trace['hops'], 'estimated_why')))));
        $this->assertStringStartsWith('The route table of LibreNMS was not read', $trace['hops'][0]['estimated']);
        $this->assertSame('connected', $trace['end']);
    }

    public function testAnIpv6NextHopIsTheLinkLocalAddressOfTheNeighborOnTheEgressPort(): void
    {
        $net = PathFixture::campus();
        // rtr1 routes 2001:db8:130::/64 via fe80::2 out of Gi0/0/2, where LLDP sees rtr3; rtr2 has fe80::2 too (link-local addresses repeat)
        $net->address(PathFixture::RTR1, 503, 'Gi0/0/2', 'fe80::1', 64)->address(PathFixture::RTR3, 701, 'Gi0/0/0', 'fe80::2', 64)
            ->address(PathFixture::RTR2, 601, 'Gi0/0/0', 'fe80::2', 64)->address(PathFixture::RTR3, 703, 'Gi0/0/2', '2001:db8:130::1', 64);
        $net->link(503, 701);
        $net->route(PathFixture::RTR1, '2001:db8:130::', 64, 'fe80:0000:0000:0000:0000:0000:0000:0002', 503, 'ospf', 10);

        $trace = $net->l3()->trace(PathFixture::RTR1, null, '', '2001:db8:130::25');
        $this->assertSame('connected', $trace['end']);
        $this->assertSame([PathFixture::RTR1, PathFixture::RTR3], array_column($trace['hops'], 'device_id'), 'the fe80::2 on the link of Gi0/0/2, not rtr2\'s');
        $this->assertSame(['fe80::2', 503, 701], [$trace['hops'][0]['next_hop'], $trace['hops'][0]['out_port'], $trace['hops'][1]['in_port']]);
        $this->assertSame(703, $trace['egress']['port_id']);

        // LibreNMS without the link-local addresses: the LLDP/CDP neighbor on that port
        $net->interfaces = array_values(array_filter($net->interfaces, fn ($interface) => ! str_starts_with($interface['address'], 'fe80')));
        $trace = $net->l3()->trace(PathFixture::RTR1, null, '', '2001:db8:130::25');
        $this->assertSame(['connected', [PathFixture::RTR1, PathFixture::RTR3]], [$trace['end'], array_column($trace['hops'], 'device_id')]);
    }

    public function testANextHopSeveralDevicesHaveIsTheOneOnTheEgressLink(): void
    {
        $net = PathFixture::campus();
        // two customer routers with 10.0.0.2 (the same PE-CE subnet behind two PEs), ce2 on rtr1's Gi0/0/5
        $net->device(11, 'ce1', 'router')->device(12, 'ce2', 'router');
        $net->address(11, 1101, 'Gi0/0', '10.0.0.2', 30)->address(11, 1102, 'Gi0/1', '172.20.1.1', 24)
            ->address(12, 1201, 'Gi0/0', '10.0.0.2', 30)->address(12, 1202, 'Gi0/1', '172.20.2.1', 24)
            ->address(PathFixture::RTR1, 505, 'Gi0/0/5', '10.0.0.1', 30);
        $net->link(505, 1201);
        $net->route(PathFixture::RTR1, '172.20.2.0', 24, '10.0.0.2', 505, 'static');

        $trace = $net->l3()->trace(PathFixture::RTR1, null, '', '172.20.2.9');
        $this->assertSame([PathFixture::RTR1, 12], array_column($trace['hops'], 'device_id'), 'ce2, the LLDP neighbor of the egress port, not ce1');
        $this->assertSame([], $trace['hops'][0]['ambiguous']);

        // nothing tells them apart: the first is taken, the other one reported
        $net->links = array_values(array_filter($net->links, fn ($link) => $link['local_port_id'] !== 505 && $link['local_port_id'] !== 1201));
        $trace = $net->l3()->trace(PathFixture::RTR1, null, '', '172.20.2.9');
        $this->assertSame([PathFixture::RTR1, 11], array_slice(array_column($trace['hops'], 'device_id'), 0, 2));
        $this->assertSame([12], $trace['hops'][0]['ambiguous']);
    }

    public function testARecursiveNextHopIsFollowedThroughTheRoutersOnTheWay(): void
    {
        $net = PathFixture::campus();
        // rtr1 learns the branch subnet by iBGP from rtr2's loopback, which its OSPF route reaches via rtr3
        $net->address(PathFixture::RTR2, 699, 'Lo0', '10.255.0.2', 32);
        $net->routes[PathFixture::RTR1] = array_values(array_filter($net->routes[PathFixture::RTR1], fn ($route) => $route['dest'] !== '10.90.130.0'));
        $net->route(PathFixture::RTR1, '10.90.130.0', 24, '10.255.0.2', 0, 'bgp', 200)->route(PathFixture::RTR1, '10.255.0.2', 32, '10.90.0.5', 503, 'ospf', 30);

        $trace = $net->l3()->trace(self::D1, 1110, '', '10.90.130.31');
        $this->assertSame('connected', $trace['end']);
        $this->assertSame([self::D1, PathFixture::RTR1, PathFixture::RTR3, PathFixture::RTR2], array_column($trace['hops'], 'device_id'), 'rtr3 between rtr1 and its BGP next hop');
        [, $rtr1, $rtr3] = $trace['hops'];
        $this->assertSame(['10.255.0.2', '10.90.0.5', 503, false, 'BGP'], [$rtr1['next_hop'], $rtr1['via'], $rtr1['out_port'], $rtr1['recursive'], $rtr1['route']['protocol']]);
        $this->assertSame('10.255.0.2', $rtr3['toward']);
        $this->assertStringEndsWith("rtr2, which has the next hop's address.", $rtr3['estimated']);

        // no route toward the next hop: on at its router, the routers between not known
        $net->routes[PathFixture::RTR1] = array_values(array_filter($net->routes[PathFixture::RTR1], fn ($route) => $route['dest'] !== '10.255.0.2'));
        $trace = $net->l3()->trace(self::D1, 1110, '', '10.90.130.31');
        $this->assertSame([self::D1, PathFixture::RTR1, PathFixture::RTR2], array_column($trace['hops'], 'device_id'));
        $this->assertTrue($trace['hops'][1]['recursive']);
    }

    public function testOnlyTheRoutersTheWalkReachesAreRead(): void
    {
        // a router far from the path, with many interfaces
        $net = PathFixture::campus();
        $net->device(30, 'far', 'router');
        for ($i = 0; $i < 20; $i++) {
            $net->address(30, 3000 + $i, 'Gi0/' . $i, '10.30.' . $i . '.1', 24);
        }

        $trace = $net->l3(false, $data)->trace(self::D1, 1110, '', '10.90.130.31');
        $this->assertSame('connected', $trace['end']);
        $read = array_values(array_unique(array_merge(...$data->interfaceReads)));
        sort($read);
        $this->assertSame([self::D1, PathFixture::RTR1, PathFixture::RTR2, PathFixture::RTR3], $read, 'the routers on the path, not far');
        $this->assertSame(['10.90.130.31'], $data->subnetReads, 'the gateways of the destination, for the estimate at rtr3');
    }

    public function testARouterWithARouteToTheDestinationRoutesItOnARecursiveWay(): void
    {
        // rtr1 learns 172.30.0.0/24 by iBGP from rtr2's loopback, resolved via rtr3; rtr3 routes 172.30.0.0/24 itself, without a route to the loopback
        $net = self::bgp();
        $net->route(PathFixture::RTR3, '172.30.0.0', 24, '10.90.0.7', 702, 'static');

        $trace = $net->l3()->trace(PathFixture::RTR1, null, '', '172.30.0.9');
        $this->assertSame(['connected', [PathFixture::RTR1, PathFixture::RTR3, PathFixture::RTR2]], [$trace['end'], array_column($trace['hops'], 'device_id')]);
        [$rtr1, $rtr3] = $trace['hops'];
        $this->assertSame(['10.255.0.2', '10.90.0.5'], [$rtr1['next_hop'], $rtr1['via']]);
        $this->assertSame([null, '172.30.0.0/24', '10.90.0.7'], [$rtr3['toward'], $rtr3['route']['prefix'], $rtr3['next_hop']], 'plain IP: its own route');

        // a BGP-free core: rtr3 has no route to the destination, but one to the loopback: toward it
        $net = self::bgp();
        $net->route(PathFixture::RTR3, '10.255.0.2', 32, '10.90.0.7', 702, 'ospf');
        $trace = $net->l3()->trace(PathFixture::RTR1, null, '', '172.30.0.9');
        $this->assertSame(['connected', [PathFixture::RTR1, PathFixture::RTR3, PathFixture::RTR2]], [$trace['end'], array_column($trace['hops'], 'device_id')]);
        $this->assertSame(['10.255.0.2', PathFixture::RTR1, '10.255.0.2/32'], [$trace['hops'][1]['toward'], $trace['hops'][1]['toward_from'], $trace['hops'][1]['route']['prefix']]);

        // neither: no route there, toward the next hop of rtr1's route
        $net = self::bgp();
        $net->route(PathFixture::RTR3, '10.99.0.0', 16, '10.90.0.7', 702, 'static');
        $trace = $net->l3()->trace(PathFixture::RTR1, null, '', '172.30.0.9');
        $this->assertSame(['no_route', '10.255.0.2', PathFixture::RTR1], [$trace['end'], end($trace['hops'])['toward'], end($trace['hops'])['toward_from']]);
    }

    public function testANextHopSeveralDevicesHaveIsNotTheOneThatLeadsBack(): void
    {
        // dist2 has rtr3's address 10.90.0.5 on the same /31, and rtr1's Gi0/0/2 has no LLDP neighbor: dist2 (the lower id) routes back to rtr1
        $net = PathFixture::campus();
        $net->address(PathFixture::DIST2, 249, 'Te1/0/49', '10.90.0.5', 31);
        $trace = $net->l3()->trace(PathFixture::RTR1, null, '', '10.90.130.31');
        $this->assertSame([PathFixture::RTR1, PathFixture::RTR3, PathFixture::RTR2], array_column($trace['hops'], 'device_id'));
        $this->assertSame([PathFixture::DIST2], $trace['hops'][0]['ambiguous'], 'still a guess');
        $this->assertSame('connected', $trace['end']);

        // both lead back: the walk on from the guess loops, and that loop is uncertain
        $net->route(PathFixture::RTR3, '10.90.130.0', 24, '10.90.0.4', 701, 'static');
        $trace = $net->l3()->trace(PathFixture::RTR1, null, '', '10.90.130.31');
        $this->assertSame(['loop', true], [$trace['end'], $trace['uncertain_loop']]);
        $this->assertSame([PathFixture::RTR1, PathFixture::DIST2], array_column($trace['hops'], 'device_id'));
    }

    public function testARouteTableReadAgainAfterTheSummaryIsNotStale(): void
    {
        // rtr1's newest route in the summary is two days older than its last discovery
        $net = PathFixture::campus();
        $net->routeData[PathFixture::RTR1]['discovered'] = PathFixture::NOW + 2 * 86400;
        $net->routeData[PathFixture::RTR1]['finished'] = PathFixture::NOW + 2 * 86400 + 60;

        // the summary was read before that discovery ended: it may have read the routes again
        $net->routeData[PathFixture::RTR1]['built'] = PathFixture::NOW + 2 * 86400 - 10;
        $this->assertSame(L3Tracer::ROUTES_OK, $net->l3()->routeState(PathFixture::RTR1, ''));
        // read after it: the routes were not read again
        $net->routeData[PathFixture::RTR1]['built'] = PathFixture::NOW + 2 * 86400 + 120;
        $this->assertSame(L3Tracer::ROUTES_STALE, $net->l3()->routeState(PathFixture::RTR1, ''));
    }

    public function testAnIpv6NeighborOnTheLinkMustRoute(): void
    {
        // rtr1 routes 2001:db8:130::/64 via fe80::2 out of Gi0/0/6, where LLDP sees acc1 (a switch between rtr1 and rtr3 Gi0/0/6); no link-local addresses in LibreNMS
        $net = PathFixture::campus();
        $net->address(PathFixture::RTR3, 703, 'Gi0/0/2', '2001:db8:130::1', 64);
        $net->port(506, PathFixture::RTR1, 'Gi0/0/6', 1)->port(706, PathFixture::RTR3, 'Gi0/0/6', 1)->port(390, self::A1, 'Gi1/0/40', 1)->port(391, self::A1, 'Gi1/0/41', 1);
        $net->link(506, 390)->link(706, 391);
        $net->route(PathFixture::RTR1, '2001:db8:130::', 64, 'fe80::2', 506, 'ospf', 10);

        $trace = $net->l3()->trace(PathFixture::RTR1, null, '', '2001:db8:130::25');
        $this->assertSame(['outside', [PathFixture::RTR1]], [$trace['end'], array_column($trace['hops'], 'device_id')], 'not the switch: no router on that link is known');

        // rtr1 and rtr3 share a subnet on that link: rtr3 is the router there
        $net->address(PathFixture::RTR1, 506, 'Gi0/0/6', '2001:db8:4::1', 64)->address(PathFixture::RTR3, 706, 'Gi0/0/6', '2001:db8:4::2', 64);
        $trace = $net->l3()->trace(PathFixture::RTR1, null, '', '2001:db8:130::25');
        $this->assertSame(['connected', [PathFixture::RTR1, PathFixture::RTR3]], [$trace['end'], array_column($trace['hops'], 'device_id')]);
        $this->assertSame([506, 706], [$trace['hops'][0]['out_port'], $trace['hops'][1]['in_port']]);
    }

    public function testRoutesTheRoleMayNotViewOrThatWereNotReadAreEstimatedNotMissing(): void
    {
        $path = [self::D1, PathFixture::RTR1, PathFixture::RTR3, PathFixture::RTR2];

        // the user's LibreNMS role may not view routes: every router is estimated, and says why
        $l3 = PathFixture::campus()->l3(false, $data);
        $data->routesHidden = true;
        $trace = $l3->trace(self::D1, 1110, '', '10.90.130.31');
        $this->assertSame(['connected', $path], [$trace['end'], array_column($trace['hops'], 'device_id')]);
        $this->assertSame([L3Tracer::ROUTES_HIDDEN], array_values(array_unique(array_filter(array_column($trace['hops'], 'estimated_why')))));
        $this->assertSame([null, null, null], array_column(array_slice($trace['hops'], 0, 3), 'route'));
        $this->assertStringStartsWith('Your LibreNMS role may not view routes: the next hop is taken to be rtr1', $trace['hops'][0]['estimated']);

        // the routes toward the destination were not read (a trace reads them toward a few addresses): estimated, not "no route"
        $l3 = PathFixture::campus()->l3(false, $data);
        $data->unread = ['192.0.2.1'];
        $stuck = $l3->trace(PathFixture::RTR2, null, '', '192.0.2.1');
        $this->assertSame(['stuck', L3Tracer::ROUTES_UNREAD], [$stuck['end'], $stuck['stuck']], 'not "no route"');
        $l3 = PathFixture::campus()->l3(false, $data);
        $data->unread = ['10.90.130.31'];
        $trace = $l3->trace(self::D1, 1110, '', '10.90.130.31');
        $this->assertSame(['connected', $path], [$trace['end'], array_column($trace['hops'], 'device_id')]);
        $this->assertSame([L3Tracer::ROUTES_UNREAD, L3Tracer::ROUTES_UNREAD], array_column(array_slice($trace['hops'], 0, 2), 'estimated_why'));
        $this->assertStringStartsWith('The routes toward 10.90.130.31 were not read', $trace['hops'][0]['estimated']);

        // nor are a recursive next hop's: the next hop is reached on at its router, the routers between not known
        $net = PathFixture::campus();
        $net->address(PathFixture::RTR2, 699, 'Lo0', '10.255.0.2', 32);
        $net->routes[PathFixture::RTR1] = array_values(array_filter($net->routes[PathFixture::RTR1], fn ($route) => $route['dest'] !== '10.90.130.0'));
        $net->route(PathFixture::RTR1, '10.90.130.0', 24, '10.255.0.2', 0, 'bgp', 200)->route(PathFixture::RTR1, '10.255.0.2', 32, '10.90.0.5', 503, 'ospf', 30);
        $l3 = $net->l3(false, $data);
        $data->unread = ['10.255.0.2'];
        $trace = $l3->trace(self::D1, 1110, '', '10.90.130.31');
        $this->assertSame(['connected', [self::D1, PathFixture::RTR1, PathFixture::RTR2]], [$trace['end'], array_column($trace['hops'], 'device_id')]);
        $this->assertSame([true, '10.255.0.2', null], [$trace['hops'][1]['recursive'], $trace['hops'][1]['unread'], $trace['hops'][1]['via']]);
    }

    public function testASubnetWithoutANetworkInLibreNmsIsFoundThroughItsArpEntries(): void
    {
        // rtr2's Gi0/0/1.140 has an address row without a network id: the subnet is not found by its network
        $net = PathFixture::campus();
        $net->address(PathFixture::RTR2, 6140, 'Gi0/0/1.140', '10.90.140.1', 24, network: false);
        $net->route(PathFixture::RTR1, '10.90.140.0', 24, '10.90.0.5', 503, 'ospf', 20);
        $l3 = $net->l3();
        $this->assertSame([], $l3->gateways('10.90.140.41'));
        $this->assertSame('stuck', $l3->trace(self::D1, 1110, '', '10.90.140.41')['end'], 'rtr3 has no way toward a router with the subnet');

        // the router interface with the host's ARP entry is its gateway, and what estimates head for
        $l3 = $net->l3();
        $l3->arpEntries('10.90.140.41', [['device_id' => PathFixture::ACC3, 'port_id' => 803], ['device_id' => PathFixture::RTR2, 'port_id' => 6140]]);
        $this->assertSame([[PathFixture::RTR2, 6140, '10.90.140.1']], array_map(fn ($interface) => [$interface['device_id'], $interface['port_id'], $interface['address']],
            $l3->gateways('10.90.140.41')), 'not the switch');
        $trace = $l3->trace(self::D1, 1110, '', '10.90.140.41');
        $this->assertSame(['connected', [self::D1, PathFixture::RTR1, PathFixture::RTR3, PathFixture::RTR2]], [$trace['end'], array_column($trace['hops'], 'device_id')]);
        $this->assertSame('10.90.140.1', $trace['egress']['address']);

        // a subnet with a network is found by it, the ARP entries are not needed
        $this->assertSame([PathFixture::RTR2], array_column($net->l3()->gateways('10.90.130.31'), 'device_id'));
    }

    public function testAnEstimateFollowsOnlySubnetsWithANetwork(): void
    {
        // rtr3 and rtr2 share 10.90.0.6/31, which LibreNMS keeps no network of, and no OSPF: rtr3 does not find rtr2
        $net = PathFixture::campus();
        $net->adjacencies = array_values(array_filter($net->adjacencies, fn ($row) => $row['neighbor_ip'] !== '10.90.0.7'));
        $this->assertSame([self::D1, PathFixture::RTR1, PathFixture::RTR3, PathFixture::RTR2], array_column($net->l3()->trace(self::D1, 1110, '', '10.90.130.31')['hops'], 'device_id'),
            'over the subnet');
        $net->withoutNetwork('10.90.0.6', '10.90.0.7');
        $trace = $net->l3()->trace(self::D1, 1110, '', '10.90.130.31');
        $this->assertSame(['stuck', [self::D1, PathFixture::RTR1, PathFixture::RTR3]], [$trace['end'], array_column($trace['hops'], 'device_id')]);
    }

    /**
     * The campus with 172.30.0.0/24 on rtr2 (Gi0/0/3) and rtr2's loopback 10.255.0.2, which rtr1 learns 172.30.0.0/24 by
     * iBGP from and reaches by OSPF via rtr3; rtr3 has a route table in LibreNMS.
     */
    private static function bgp(): PathFixture
    {
        $net = PathFixture::campus();
        $net->address(PathFixture::RTR2, 699, 'Lo0', '10.255.0.2', 32)->address(PathFixture::RTR2, 620, 'Gi0/0/3', '172.30.0.1', 24);
        $net->route(PathFixture::RTR1, '172.30.0.0', 24, '10.255.0.2', 0, 'bgp', 200)->route(PathFixture::RTR1, '10.255.0.2', 32, '10.90.0.5', 503, 'ospf', 30);
        $net->route(PathFixture::RTR3, '10.90.0.4', 31, '0.0.0.0', 701, 'local', 0, null, 3);

        return $net;
    }

    /**
     * Four routers in a ring with point-to-point /30s: rtr1 (5) - rtr3 (7) - rtr2 (6) - rtr4 (10) - rtr1, 192.0.2.0/24 on rtr4.
     */
    private static function ring(): PathFixture
    {
        $net = new PathFixture;
        $net->device(5, 'rtr1', 'router')->device(7, 'rtr3', 'router')->device(6, 'rtr2', 'router')->device(10, 'rtr4', 'router');
        $net->address(5, 51, 'Gi0/1', '10.0.13.1', 30)->address(7, 71, 'Gi0/1', '10.0.13.2', 30)
            ->address(7, 72, 'Gi0/2', '10.0.32.1', 30)->address(6, 61, 'Gi0/1', '10.0.32.2', 30)
            ->address(6, 62, 'Gi0/2', '10.0.24.1', 30)->address(10, 101, 'Gi0/1', '10.0.24.2', 30)
            ->address(10, 102, 'Gi0/2', '10.0.41.1', 30)->address(5, 52, 'Gi0/2', '10.0.41.2', 30)
            ->address(10, 103, 'Gi0/3', '192.0.2.1', 24);

        return $net;
    }
}
