<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Topology\L2Builder;
use Agoodley\LibrenmsTopology\Topology\L3Builder;
use Agoodley\LibrenmsTopology\Topology\SiteConnections;
use PHPUnit\Framework\TestCase;

/**
 * Connections of the site map built from the same rows as the L2 and L3 maps:
 *
 *   HQ: core1 (1), core2 (2)        Branch: rtr-b (7), sw-b (8)        Remote: rtr-r (9)
 *   core1 Te1/1/4 <-> rtr-b Te0/0/0   LLDP, 10.0.0.0/30 and 2001:db8::/126 on the same ports, OSPF, BGP
 *   core2 Po10 (Te1/1/1+2) <-> sw-b Po1 (Te1/1/1+2)   port-channel with 10.0.0.4/30 on the aggregates
 *   rtr-b Tu1 <-> rtr-r Tu1           10.0.0.8/30, no LLDP
 *   rtr-b Gi0/0/1 <-> sw-b Gi1/0/1    inside the branch
 *   rtr-b and rtr-r: eBGP to AS65000 (not monitored), rtr-r with one session idle
 */
class SiteConnectionsTest extends TestCase
{
    private static function device(int $id, string $name, int $location, string $role = 'router', int $asn = 0): array
    {
        return [
            'device_id' => $id, 'display' => $name, 'hostname' => $name, 'status' => 1, 'disabled' => 0, 'role' => $role, 'url' => "/device/$id",
            'location_id' => $location, 'location' => "Site $location", 'bgpLocalAs' => $asn, 'lat' => null, 'lng' => null,
        ];
    }

    private static function port(int $id, int $device, string $name, int $speed = 10000000000, string $oper = 'up', int $inRate = 0, int $outRate = 0): array
    {
        return [
            'port_id' => $id, 'device_id' => $device, 'ifName' => $name, 'ifDescr' => $name, 'ifSpeed' => $speed, 'ifOperStatus' => $oper,
            'ifInOctets_rate' => $inRate, 'ifOutOctets_rate' => $outRate, 'url' => "/port/$id",
        ];
    }

    private static function lldp(int $ld, int $lp, int $rd, int $rp, string $remotePort): array
    {
        return [
            'local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd, 'remote_port_id' => $rp, 'remote_hostname' => '',
            'remote_port' => $remotePort, 'remote_platform' => '', 'remote_version' => '', 'protocol' => 'lldp',
        ];
    }

    private static function address(int $device, array $port, string $address, int $prefix): array
    {
        return ['device_id' => $device, 'port_id' => $port['port_id'], 'ifName' => $port['ifName'], 'ifDescr' => $port['ifName'], 'ifAlias' => '',
            'ifOperStatus' => $port['ifOperStatus'], 'ifType' => 'ethernetCsmacd', 'address' => $address, 'prefixlen' => $prefix, 'vrf' => ''];
    }

    private static function bgp(int $device, string $peer, int $remoteAs, string $state = 'established'): array
    {
        return ['device_id' => $device, 'bgpPeerIdentifier' => $peer, 'bgpPeerRemoteAs' => $remoteAs, 'bgpPeerState' => $state,
            'bgpPeerAdminStatus' => 'start', 'bgpLocalAddr' => '', 'bgpPeerDescr' => '', 'context_name' => ''];
    }

    private function connections(): array
    {
        $devices = [
            1 => self::device(1, 'core1', 1, 'l3switch', 65001), 2 => self::device(2, 'core2', 1, 'l3switch', 65001),
            7 => self::device(7, 'rtr-b', 2, 'router', 65002), 8 => self::device(8, 'sw-b', 2, 'switch'), 9 => self::device(9, 'rtr-r', 3, 'router', 65003),
        ];
        $ports = [];
        foreach ([
            self::port(101, 1, 'TenGigabitEthernet1/1/4', 10000000000, 'up', 250000000, 125000000),
            self::port(201, 2, 'TenGigabitEthernet1/1/1'), self::port(202, 2, 'TenGigabitEthernet1/1/2', 10000000000, 'down'), self::port(210, 2, 'Port-channel10', 20000000000),
            self::port(701, 7, 'TenGigabitEthernet0/0/0'), self::port(702, 7, 'GigabitEthernet0/0/1', 1000000000), self::port(703, 7, 'Tunnel1', 100000000, 'up', 1000000, 2000000),
            self::port(801, 8, 'TenGigabitEthernet1/1/1'), self::port(802, 8, 'TenGigabitEthernet1/1/2', 10000000000, 'down'), self::port(810, 8, 'Port-channel1', 20000000000),
            self::port(803, 8, 'GigabitEthernet1/0/1', 1000000000), self::port(903, 9, 'Tunnel1', 100000000), self::port(904, 9, 'GigabitEthernet0/0/0', 1000000000),
        ] as $port) {
            $ports[$port['port_id']] = $port;
        }
        $lag = [201 => 210, 202 => 210, 801 => 810, 802 => 810];

        $links = [
            self::lldp(1, 101, 7, 701, 'Te0/0/0'), self::lldp(7, 701, 1, 101, 'Te1/1/4'),
            self::lldp(2, 201, 8, 801, 'Te1/1/1'), self::lldp(2, 202, 8, 802, 'Te1/1/2'),
            self::lldp(7, 702, 8, 803, 'Gi1/0/1'),
        ];
        $addresses = [
            self::address(1, $ports[101], '10.0.0.1', 30), self::address(7, $ports[701], '10.0.0.2', 30),
            self::address(1, $ports[101], '2001:db8::1', 126), self::address(7, $ports[701], '2001:db8::2', 126),
            self::address(2, $ports[210], '10.0.0.5', 30), self::address(8, $ports[810], '10.0.0.6', 30),
            self::address(7, $ports[703], '10.0.0.9', 30), self::address(9, $ports[903], '10.0.0.10', 30),
            self::address(7, $ports[702], '10.1.0.1', 30), self::address(8, $ports[803], '10.1.0.2', 30),
            self::address(9, $ports[904], '192.0.2.1', 30),
        ];
        $ospf = [
            ['device_id' => 1, 'port_id' => 101, 'ospfNbrIpAddr' => '10.0.0.2', 'ospfNbrRtrId' => '10.0.0.2', 'ospfNbrState' => 'full', 'context_name' => ''],
            ['device_id' => 7, 'port_id' => 701, 'ospfNbrIpAddr' => '10.0.0.1', 'ospfNbrRtrId' => '10.0.0.1', 'ospfNbrState' => 'full', 'context_name' => ''],
            ['device_id' => 9, 'port_id' => 904, 'ospfNbrIpAddr' => '192.0.2.2', 'ospfNbrRtrId' => '192.0.2.2', 'ospfNbrState' => 'full', 'context_name' => ''],
        ];
        $bgp = [
            self::bgp(1, '10.0.0.2', 65002), self::bgp(7, '10.0.0.1', 65001),
            self::bgp(7, '198.51.100.1', 65000), self::bgp(9, '198.51.100.5', 65000), self::bgp(9, '198.51.100.9', 65000, 'idle'),
        ];

        $l3 = (new L3Builder($devices, $addresses, $ospf, $bgp))->build(['hideStubs' => true, 'p2pPrefix4' => 29, 'p2pPrefix6' => 126]);
        $connections = (new SiteConnections($devices))->addLinks(L2Builder::uniqueLinks($links, $devices, $ports), $ports, $lag);
        $this->assertEqualsCanonicalizing([101, 701, 210, 810, 703, 903], $connections->subnetPorts($l3), 'only subnets between sites need their ports');

        return $connections->addL3($l3, $ports, $bgp)->all();
    }

    private static function find(array $connections, string $kind, int $a, ?int $b = null): array
    {
        return array_values(array_filter($connections, fn ($c) => $c['kind'] === $kind
            && in_array($a, [$c['a']['device_id'], $c['b']['device_id'] ?? null], true)
            && ($b === null || in_array($b, [$c['a']['device_id'], $c['b']['device_id'] ?? null], true))));
    }

    public function testCablesBetweenSitesAndTheirSubnets(): void
    {
        $connections = $this->connections();

        // one connection per cable; the subnets of both families on the cable only add their networks
        $wan = self::find($connections, 'l2', 1, 7);
        $this->assertCount(1, $wan);
        $this->assertSame('10.0.0.0/30, 2001:db8::/126', $wan[0]['network']);
        $this->assertSame(10000000000, $wan[0]['speed']);
        $this->assertSame([2000000000, 1000000000, 20.0], [$wan[0]['in_bps'], $wan[0]['out_bps'], $wan[0]['util']]);
        $this->assertSame(['Te1/1/4', '/port/101'], [$wan[0]['a']['port'], $wan[0]['a']['port_url']]);
        $this->assertSame([], self::find($connections, 'p2p', 1, 7));

        // members of a port-channel: the subnet on the aggregates belongs to the cables
        $lag = self::find($connections, 'l2', 2, 8);
        $this->assertCount(2, $lag);
        $this->assertSame(['Po10', 'Po1'], [$lag[0]['a']['aggregate'], $lag[0]['b']['aggregate']]);
        $this->assertSame(['up', 'down'], array_column($lag, 'status'));
        $this->assertSame('10.0.0.4/30', $lag[0]['network'] ?? $lag[1]['network']);

        // a subnet without a neighbor link is a connection of its own
        $tunnel = self::find($connections, 'p2p', 7, 9);
        $this->assertCount(1, $tunnel);
        $this->assertSame(['10.0.0.8/30', 100000000, 'up', 'Tu1'], [$tunnel[0]['network'], $tunnel[0]['speed'], $tunnel[0]['status'], $tunnel[0]['a']['port']]);

        // nothing inside a site
        $this->assertSame([], self::find($connections, 'l2', 7, 8));
        $this->assertSame([], self::find($connections, 'p2p', 7, 8));
    }

    public function testRoutingSessionsBetweenSitesAndToClouds(): void
    {
        $connections = $this->connections();

        // an adjacency or session between two monitored routers is one connection, seen from both
        $ospf = self::find($connections, 'ospf', 1, 7);
        $this->assertCount(1, $ospf);
        $this->assertSame(['up', 'full'], [$ospf[0]['status'], $ospf[0]['state']]);
        $this->assertCount(1, self::find($connections, 'bgp', 1, 7));
        $this->assertSame([], self::find($connections, 'ospf', 9), 'an OSPF neighbor LibreNMS does not monitor has no site');

        // sessions to an AS LibreNMS does not monitor: one connection each
        $cloud = array_values(array_filter($connections, fn ($c) => $c['kind'] === 'bgp' && $c['b'] === null));
        $this->assertCount(3, $cloud);
        $this->assertSame(['as:65000', 'AS65000', 65000], [$cloud[0]['cloud']['id'], $cloud[0]['cloud']['label'], $cloud[0]['cloud']['remote_as']]);
        $remote = array_values(array_filter($cloud, fn ($c) => $c['a']['device_id'] === 9));
        $this->assertSame(['198.51.100.5' => 'up', '198.51.100.9' => 'down'], array_column($remote, 'status', 'network'));
        $this->assertSame('idle', $remote[1]['state']);
    }
}
