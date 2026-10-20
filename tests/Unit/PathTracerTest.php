<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Path\L2Tracer;
use PHPUnit\Framework\TestCase;

/**
 * L2Tracer and L3Tracer on PathFixture's campus: forwarding paths per spanning tree instance, port-channels as one hop,
 * no transit through routers or routed ports, devices out of sight, and router-by-router lookups with ECMP, kept route
 * history, estimates where a router has no routes, and the ways a routed path ends.
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
}
