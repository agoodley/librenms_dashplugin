<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Topology\L2Builder;
use Agoodley\LibrenmsTopology\Topology\L3Builder;
use Agoodley\LibrenmsTopology\Topology\SiteBuilder;
use Agoodley\LibrenmsTopology\Topology\SiteConnections;
use PHPUnit\Framework\TestCase;

/**
 * Cables and point-to-point subnets of the site map, built from the same rows as the L2 and L3 maps.
 */
class SiteConnectionsTest extends TestCase
{
    /**
     * A device of the map, its role by its name: switches (sw-, core, acc-), carrier CPEs (other), routers.
     */
    private static function device(int $id, string $name, int $location): array
    {
        $role = match (true) {
            (bool) preg_match('/^(sw|core|acc)/', $name) => 'switch',
            str_starts_with($name, 'cpe') => 'other',
            default => 'router',
        };

        return [
            'device_id' => $id, 'display' => $name, 'hostname' => $name, 'status' => 1, 'disabled' => 0, 'role' => $role, 'url' => "/device/$id",
            'location_id' => $location, 'location' => "Site $location", 'bgpLocalAs' => 0, 'lat' => null, 'lng' => null,
        ];
    }

    private static function port(int $id, int $device, string $name, string $type = 'ethernetCsmacd', int $speed = 10000000000, string $oper = 'up',
        int $inRate = 0, int $outRate = 0): array
    {
        return [
            'port_id' => $id, 'device_id' => $device, 'ifName' => $name, 'ifDescr' => $name, 'ifType' => $type, 'ifSpeed' => $speed,
            'ifOperStatus' => $oper, 'ifInOctets_rate' => $inRate, 'ifOutOctets_rate' => $outRate, 'url' => "/port/$id",
        ];
    }

    private static function lldp(int $ld, int $lp, int $rd, int $rp): array
    {
        return [
            'local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd, 'remote_port_id' => $rp, 'remote_hostname' => '',
            'remote_port' => '', 'remote_platform' => '', 'remote_version' => '', 'protocol' => 'lldp',
        ];
    }

    private static function address(array $port, string $address, int $prefix, string $vrf = ''): array
    {
        return ['device_id' => $port['device_id'], 'port_id' => $port['port_id'], 'ifName' => $port['ifName'], 'ifDescr' => $port['ifName'], 'ifAlias' => '',
            'ifOperStatus' => $port['ifOperStatus'], 'ifType' => $port['ifType'], 'address' => $address, 'prefixlen' => $prefix, 'vrf' => $vrf];
    }

    /**
     * Connections as SiteService builds them: cables, then the subnets with the interface stack from ports_stack
     * pairs (either orientation) and port-channel membership, completed by name (unitParents() looked up in the
     * given ports).
     *
     * @param  array<int, array>  $ports  every port, by id
     * @param  array<int, array{0: array, 1: string, 2: int, 3?: string}>  $addresses  [port, address, prefix, vrf]
     * @param  array<int, array{0: int, 1: int}>  $pairs  ports_stack rows as [high_port_id, low_port_id]
     * @param  array<int, string[]>|null  $asked  receives the site lists the links inside sites were asked for
     */
    private static function connections(array $devices, array $ports, array $links, array $addresses, array $lag = [], array $pairs = [],
        ?array &$asked = null): array
    {
        $connections = (new SiteConnections($devices))->addLinks(L2Builder::uniqueLinks($links, $devices, $ports), $ports, $lag);
        $rows = array_map(fn ($a) => self::address(...$a), $addresses);
        $l3 = (new L3Builder($devices, $rows))->build(['hideStubs' => true, 'p2pPrefix4' => 29, 'p2pPrefix6' => 126, 'ospf' => false, 'bgp' => false]);

        $subnetPorts = $connections->subnetPorts($l3);
        $stack = SiteConnections::stack($pairs, $ports, $lag);
        foreach (SiteConnections::unitParents(array_intersect_key($ports, array_flip($subnetPorts)), $stack) as $portId => $parent) {
            foreach ($ports as $candidate) {
                if ($candidate['device_id'] === $parent['device_id'] && in_array($candidate['ifName'], $parent['names'], true)) {
                    $stack[$portId] = [$candidate['port_id']];
                }
            }
        }

        // the neighbors of each port and the links inside each site, as SiteRepository::portNeighbors() and
        // siteAdjacency() find them
        $neighbors = [];
        $inside = [];
        foreach ($links as $link) {
            [$a, $b] = [$link['local_device_id'], $link['remote_device_id']];
            $neighbors[$link['local_port_id']][] = ['device_id' => $b, 'location_id' => $devices[$b]['location_id']];
            $neighbors[$link['remote_port_id']][] = ['device_id' => $a, 'location_id' => $devices[$a]['location_id']];
            if (SiteBuilder::siteId($devices[$a]) === SiteBuilder::siteId($devices[$b])) {
                $inside[SiteBuilder::siteId($devices[$a])][$a][] = $b;
                $inside[SiteBuilder::siteId($devices[$a])][$b][] = $a;
            }
        }

        $asked = [];
        $siteLinks = function (array $sites) use ($inside, &$asked) {
            $asked[] = $sites;

            return array_intersect_key($inside, array_flip($sites));
        };

        return $connections->addSubnets($l3, $ports, $stack, $neighbors, $siteLinks)->all();
    }

    private static function ports(array ...$ports): array
    {
        return array_column($ports, null, 'port_id');
    }

    private static function find(array $connections, string $kind): array
    {
        return array_values(array_filter($connections, fn ($c) => $c['kind'] === $kind));
    }

    public function testCablesBetweenSitesAndTheirSubnets(): void
    {
        //   Site 1: core1 (1), core2 (2)      Site 2: rtr-b (7), sw-b (8)      Site 3: rtr-r (9)
        $devices = [1 => self::device(1, 'core1', 1), 2 => self::device(2, 'core2', 1), 7 => self::device(7, 'rtr-b', 2),
            8 => self::device(8, 'sw-b', 2), 9 => self::device(9, 'rtr-r', 3)];
        $ports = self::ports(
            self::port(101, 1, 'TenGigabitEthernet1/1/4', 'ethernetCsmacd', 10000000000, 'up', 250000000, 125000000),
            self::port(201, 2, 'TenGigabitEthernet1/1/1'), self::port(202, 2, 'TenGigabitEthernet1/1/2', 'ethernetCsmacd', 10000000000, 'down'),
            self::port(210, 2, 'Port-channel10', 'propVirtual', 20000000000),
            self::port(701, 7, 'TenGigabitEthernet0/0/0'), self::port(702, 7, 'GigabitEthernet0/0/1', 'ethernetCsmacd', 1000000000),
            self::port(703, 7, 'Tunnel1', 'tunnel', 100000, 'up', 625000, 312500),
            self::port(801, 8, 'TenGigabitEthernet1/1/1'), self::port(802, 8, 'TenGigabitEthernet1/1/2', 'ethernetCsmacd', 10000000000, 'down'),
            self::port(810, 8, 'Port-channel1', 'propVirtual', 20000000000), self::port(803, 8, 'GigabitEthernet1/0/1', 'ethernetCsmacd', 1000000000),
            self::port(903, 9, 'Tunnel1', 'tunnel', 100000), self::port(904, 9, 'GigabitEthernet0/0/0', 'ethernetCsmacd', 1000000000),
        );
        $links = [self::lldp(1, 101, 7, 701), self::lldp(7, 701, 1, 101), self::lldp(2, 201, 8, 801), self::lldp(2, 202, 8, 802), self::lldp(7, 702, 8, 803)];
        $connections = self::connections($devices, $ports, $links, [
            [$ports[101], '10.0.0.1', 30], [$ports[701], '10.0.0.2', 30], [$ports[101], '2001:db8::1', 126], [$ports[701], '2001:db8::2', 126],
            [$ports[210], '10.0.0.5', 30], [$ports[810], '10.0.0.6', 30],
            [$ports[703], '10.0.0.9', 30], [$ports[903], '10.0.0.10', 30],
            [$ports[702], '10.1.0.1', 30], [$ports[803], '10.1.0.2', 30],
            [$ports[904], '192.0.2.1', 30],
        ], [201 => 210, 202 => 210, 801 => 810, 802 => 810]);

        $cables = self::find($connections, 'l2');
        $this->assertCount(3, $cables, 'one connection per cable, nothing inside a site');
        // the subnets of both families on the cable only add their networks
        $this->assertSame([1, 7, '10.0.0.0/30, 2001:db8::/126'], [$cables[0]['a']['device_id'], $cables[0]['b']['device_id'], $cables[0]['network']]);
        $this->assertSame([10000000000, 2000000000, 1000000000, 20.0], [$cables[0]['speed'], $cables[0]['in_bps'], $cables[0]['out_bps'], $cables[0]['util']]);
        $this->assertSame(['Te1/1/4', '/port/101'], [$cables[0]['a']['port'], $cables[0]['a']['port_url']]);

        // a subnet on two port-channels belongs to their members' cables
        $this->assertSame([['Po10', 'Po1', 'up'], ['Po10', 'Po1', 'down']], array_map(fn ($c) => [$c['a']['aggregate'], $c['b']['aggregate'], $c['status']], [$cables[1], $cables[2]]));
        $this->assertSame(['10.0.0.4/30', '10.0.0.4/30'], [$cables[1]['network'], $cables[2]['network']]);

        // a tunnel without LLDP is a link of its own; its 100 kbit/s ifSpeed is the default bandwidth, not a capacity
        $tunnel = self::find($connections, 'p2p');
        $this->assertCount(1, $tunnel);
        $this->assertSame([7, 9, '10.0.0.8/30', 'up', 'Tu1'], [$tunnel[0]['a']['device_id'], $tunnel[0]['b']['device_id'], $tunnel[0]['network'], $tunnel[0]['status'], $tunnel[0]['a']['port']]);
        $this->assertSame([0, null, 5000000, 2500000], [$tunnel[0]['speed'], $tunnel[0]['util'], $tunnel[0]['in_bps'], $tunnel[0]['out_bps']], 'traffic only');
    }

    public function testSubInterfacesAndUnitsOnACable(): void
    {
        // rtr-a (Junos) xe-0/1/0 <-> rtr-b (IOS-XE) Te0/0/0: unit 0 / .100 in the global table, unit 200 / .200 in VRF CUST.
        // The IOS-XE sub-interfaces are in the interface stack, the Junos units only by their names.
        $devices = [1 => self::device(1, 'rtr-a', 1), 2 => self::device(2, 'rtr-b', 2)];
        $ports = self::ports(
            self::port(11, 1, 'xe-0/1/0', 'ethernetCsmacd', 10000000000, 'up', 12500000, 6250000),
            self::port(12, 1, 'xe-0/1/0.0', 'propVirtual', 0), self::port(13, 1, 'xe-0/1/0.200', 'propVirtual', 0),
            self::port(21, 2, 'TenGigabitEthernet0/0/0'),
            self::port(22, 2, 'TenGigabitEthernet0/0/0.100', 'l2vlan'), self::port(23, 2, 'TenGigabitEthernet0/0/0.200', 'l2vlan'),
        );
        $addresses = [[$ports[12], '10.0.0.1', 30], [$ports[22], '10.0.0.2', 30], [$ports[13], '10.0.0.5', 30, 'CUST'], [$ports[23], '10.0.0.6', 30, 'CUST']];

        // LibreNMS 24.8+ keeps the sub-interface in low_port_id, before it was the high side: the same either way
        foreach ([[[21, 22], [21, 23]], [[22, 21], [23, 21]]] as $pairs) {
            $connections = self::connections($devices, $ports, [self::lldp(1, 11, 2, 21)], $addresses, [], $pairs);
            $this->assertCount(1, $connections, 'the subnets of the sub-interfaces are on the cable');
            $this->assertSame(['l2', '10.0.0.0/30, 10.0.0.4/30 (CUST)', 10000000000, 1.0], [$connections[0]['kind'], $connections[0]['network'],
                $connections[0]['speed'], $connections[0]['util']]);
        }
    }

    public function testVlanInterfacesOnATrunk(): void
    {
        // sw-a Vlan300 <-> sw-b Vlan300 over the trunk Te1/1/1 <-> Te1/1/1: VLAN interfaces have no port in the stack
        $devices = [3 => self::device(3, 'sw-a', 1), 4 => self::device(4, 'sw-b', 2)];
        $ports = self::ports(self::port(31, 3, 'TenGigabitEthernet1/1/1'), self::port(32, 3, 'Vlan300', 'propVirtual', 1000000000),
            self::port(41, 4, 'TenGigabitEthernet1/1/1'), self::port(42, 4, 'Vlan300', 'propVirtual', 1000000000));
        $connections = self::connections($devices, $ports, [self::lldp(3, 31, 4, 41)], [[$ports[32], '10.0.0.9', 30], [$ports[42], '10.0.0.10', 30]]);

        $this->assertCount(1, $connections);
        $this->assertSame(['l2', '10.0.0.8/30', 10000000000], [$connections[0]['kind'], $connections[0]['network'], $connections[0]['speed']]);

        // Junos EX irb.300 (its irb parent: ifType other, 1G) <-> Cisco Vlan300, with and without the stack rows
        $devices = [1 => self::device(1, 'ex-a', 1), 2 => self::device(2, 'cat-b', 2)];
        $ports = self::ports(self::port(11, 1, 'xe-0/1/0'), self::port(12, 1, 'irb', 'other', 1000000000), self::port(13, 1, 'irb.300', 'propVirtual', 0),
            self::port(14, 1, 'irb.400', 'propVirtual', 0), self::port(21, 2, 'TenGigabitEthernet1/1/1'), self::port(22, 2, 'Vlan300', 'propVirtual', 1000000000));
        foreach ([[], [[12, 13], [12, 14]], [[13, 12], [14, 12]]] as $pairs) {
            $connections = self::connections($devices, $ports, [self::lldp(1, 11, 2, 21)], [[$ports[13], '10.0.0.1', 30], [$ports[22], '10.0.0.2', 30]], [], $pairs);
            $this->assertSame([['l2', '10.0.0.0/30', 10000000000]], array_map(fn ($c) => [$c['kind'], $c['network'], $c['speed']], $connections));
        }
    }

    public function testLogicalInterfacesHaveNoCapacity(): void
    {
        // subnets without a cable on interfaces whose type says nothing: their names do (legacy Junos EX vlan, SRX reth,
        // BVI, a Linux VLAN sub-interface), a port-channel keeps its capacity
        $devices = [1 => self::device(1, 'a', 1), 2 => self::device(2, 'b', 2)];
        foreach (['vlan' => 0, 'reth0' => 0, 'BVI1' => 0, 'eth0.100' => 0, 'irb' => 0, 'ae0' => 1000000000, 'GigabitEthernet0/0/2' => 1000000000] as $name => $speed) {
            $ports = self::ports(self::port(11, 1, $name, $name === 'irb' ? 'other' : 'ethernetCsmacd', 1000000000), self::port(21, 2, $name, 'ethernetCsmacd', 1000000000));
            $connections = self::connections($devices, $ports, [], [[$ports[11], '10.0.0.1', 30], [$ports[21], '10.0.0.2', 30]]);
            $this->assertSame(['p2p', $speed], [$connections[0]['kind'], $connections[0]['speed']], $name);
        }
    }

    public function testTransitVlanToARouterBehindTheFarSwitch(): void
    {
        // dark fiber HQ core Te1/1/1 <-> branch switch sw-b Te1/1/1; the transit /29 runs from the core's Vlan900 to the
        // branch router rtr-b's Gi0/0/0.900 (rtr-b is cabled to sw-b inside the branch): one physical path
        $devices = [1 => self::device(1, 'core', 1), 2 => self::device(2, 'sw-b', 2), 3 => self::device(3, 'rtr-b', 2)];
        $ports = self::ports(
            self::port(11, 1, 'TenGigabitEthernet1/1/1', 'ethernetCsmacd', 10000000000, 'up', 125000000, 62500000),
            self::port(12, 1, 'Vlan900', 'propVirtual', 1000000000, 'up', 125000000, 62500000),
            self::port(21, 2, 'TenGigabitEthernet1/1/1'), self::port(22, 2, 'GigabitEthernet1/0/1', 'ethernetCsmacd', 1000000000),
            self::port(31, 3, 'GigabitEthernet0/0/0', 'ethernetCsmacd', 1000000000), self::port(32, 3, 'GigabitEthernet0/0/0.900', 'l2vlan', 1000000000),
        );
        $connections = self::connections($devices, $ports, [self::lldp(1, 11, 2, 21), self::lldp(3, 31, 2, 22)],
            [[$ports[12], '10.9.0.1', 29], [$ports[32], '10.9.0.2', 29]], [], [[31, 32]]);

        $this->assertSame([['l2', 'Te1/1/1', '10.9.0.0/29', 10000000000, 1000000000]],
            array_map(fn ($c) => [$c['kind'], $c['a']['port'], $c['network'], $c['speed'], $c['in_bps']], $connections), 'the subnet is on the fiber, not a second link');

        // the same VLAN to a router whose port has no neighbor (a carrier handoff): a circuit of its own
        $connections = self::connections($devices, $ports, [self::lldp(1, 11, 2, 21)], [[$ports[12], '10.9.0.1', 29], [$ports[32], '10.9.0.2', 29]], [], [[31, 32]]);
        $this->assertSame([['l2', ''], ['p2p', '10.9.0.0/29']], array_map(fn ($c) => [$c['kind'], (string) $c['network']], $connections));
    }

    public function testPortChannelsWithUnknownMembers(): void
    {
        // 2 x 10G between core (Po10 over Te1/1/1-2) and rtr (ae0 over xe-0/0/0-1), the /30 on the aggregates
        $devices = [1 => self::device(1, 'core', 1), 2 => self::device(2, 'rtr', 2)];
        $ports = self::ports(self::port(11, 1, 'TenGigabitEthernet1/1/1'), self::port(12, 1, 'TenGigabitEthernet1/1/2'),
            self::port(10, 1, 'Port-channel10', 'propVirtual', 20000000000),
            self::port(21, 2, 'xe-0/0/0'), self::port(22, 2, 'xe-0/0/1'), self::port(20, 2, 'ae0', 'ieee8023adLag', 20000000000));
        $links = [self::lldp(1, 11, 2, 21), self::lldp(1, 12, 2, 22)];
        $addresses = [[$ports[10], '10.0.0.5', 30], [$ports[20], '10.0.0.6', 30]];

        // the Juniper side's ae0 membership is unknown, the Cisco side's is
        $connections = self::connections($devices, $ports, $links, $addresses, [11 => 10, 12 => 10]);
        $this->assertSame(['l2', 'l2'], array_column($connections, 'kind'));
        $this->assertSame(['10.0.0.4/30', '10.0.0.4/30'], array_column($connections, 'network'));
        $this->assertSame([10000000000, 10000000000], array_column($connections, 'speed'), 'no capacity added for the port-channels');

        // neither membership is known: the subnet belongs to the cables between the two devices
        $connections = self::connections($devices, $ports, $links, $addresses);
        $this->assertSame(['10.0.0.4/30', '10.0.0.4/30'], array_column($connections, 'network'));
    }

    public function testSubInterfacesWithoutACableAreMeasuredOnTheirParents(): void
    {
        // the same VRF-lite pair of sub-interfaces as above, but no LLDP between the routers
        $devices = [1 => self::device(1, 'rtr-a', 1), 2 => self::device(2, 'rtr-b', 2)];
        $ports = self::ports(
            self::port(11, 1, 'xe-0/1/0', 'ethernetCsmacd', 1000000000, 'up', 25000000, 50000000),
            self::port(12, 1, 'xe-0/1/0.0', 'propVirtual', 0, 'up', 1, 1), self::port(13, 1, 'xe-0/1/0.200', 'propVirtual', 0),
            self::port(21, 2, 'TenGigabitEthernet0/0/0'),
            self::port(22, 2, 'TenGigabitEthernet0/0/0.100', 'l2vlan'), self::port(23, 2, 'TenGigabitEthernet0/0/0.200', 'l2vlan'),
        );
        $connections = self::connections($devices, $ports, [], [
            [$ports[12], '10.0.0.1', 30], [$ports[22], '10.0.0.2', 30], [$ports[13], '10.0.0.5', 30, 'CUST'], [$ports[23], '10.0.0.6', 30, 'CUST'],
        ], [], [[21, 22], [21, 23], [11, 12], [11, 13]]);

        $this->assertCount(1, $connections, 'one link per pair of physical ports');
        $link = $connections[0];
        $this->assertSame(['p2p', 'xe-0/1/0', 'Te0/0/0', '10.0.0.0/30, 10.0.0.4/30 (CUST)'], [$link['kind'], $link['a']['port'], $link['b']['port'], $link['network']]);
        $this->assertSame([1000000000, 200000000, 400000000, 40.0], [$link['speed'], $link['in_bps'], $link['out_bps'], $link['util']], 'the slower physical port');
    }

    public function testOtherLinksBetweenDevicesThatShareACable(): void
    {
        // rtr-a and rtr-b: a cable Gi0/0/1 (LLDP, with its /30), a routed Gi0/0/2 without LLDP, and a GRE tunnel
        $devices = [1 => self::device(1, 'rtr-a', 1), 2 => self::device(2, 'rtr-b', 2)];
        $ports = self::ports(
            self::port(11, 1, 'GigabitEthernet0/0/1', 'ethernetCsmacd', 1000000000), self::port(12, 1, 'GigabitEthernet0/0/2', 'ethernetCsmacd', 1000000000),
            self::port(13, 1, 'Tunnel10', 'tunnel', 100000, 'up', 1000, 2000),
            self::port(21, 2, 'GigabitEthernet0/0/1', 'ethernetCsmacd', 1000000000), self::port(22, 2, 'GigabitEthernet0/0/2', 'ethernetCsmacd', 1000000000),
            self::port(23, 2, 'Tunnel10', 'tunnel', 100000),
        );
        $connections = self::connections($devices, $ports, [self::lldp(1, 11, 2, 21)], [
            [$ports[11], '10.0.0.1', 30], [$ports[21], '10.0.0.2', 30], [$ports[12], '10.0.0.5', 30], [$ports[22], '10.0.0.6', 30],
            [$ports[13], '10.0.0.9', 30], [$ports[23], '10.0.0.10', 30],
        ]);

        $this->assertSame([['l2', '10.0.0.0/30', 1000000000], ['p2p', '10.0.0.4/30', 1000000000], ['p2p', '10.0.0.8/30', 0]],
            array_map(fn ($c) => [$c['kind'], $c['network'], $c['speed']], $connections));
        $this->assertSame([8000, 16000, null], [$connections[2]['in_bps'], $connections[2]['out_bps'], $connections[2]['util']]);
    }

    public function testCarrierCircuitsNextToACable(): void
    {
        // (a) an LLDP fiber Te0/0/0 and a carrier circuit handed off on Gi0/0/1.100 between the same two routers:
        // the handoff port has no neighbor, it is a circuit of its own (ports_stack rows as LibreNMS writes them)
        $devices = [1 => self::device(1, 'rtr-a', 1), 2 => self::device(2, 'rtr-b', 2)];
        $ports = self::ports(
            self::port(11, 1, 'TenGigabitEthernet0/0/0', 'ethernetCsmacd', 10000000000, 'up', 125000000, 62500000),
            self::port(13, 1, 'GigabitEthernet0/0/1', 'ethernetCsmacd', 1000000000, 'up', 50000000, 25000000),
            self::port(14, 1, 'GigabitEthernet0/0/1.100', 'l2vlan', 1000000000), self::port(21, 2, 'TenGigabitEthernet0/0/0'),
            self::port(23, 2, 'GigabitEthernet0/0/1', 'ethernetCsmacd', 1000000000), self::port(24, 2, 'GigabitEthernet0/0/1.100', 'l2vlan', 1000000000),
        );
        $connections = self::connections($devices, $ports, [self::lldp(1, 11, 2, 21)], [[$ports[11], '10.0.0.1', 30], [$ports[21], '10.0.0.2', 30],
            [$ports[14], '10.0.1.1', 30], [$ports[24], '10.0.1.2', 30]], [], [[13, 14], [23, 24]]);
        $this->assertSame([['l2', 'Te0/0/0', '10.0.0.0/30', 10000000000, 1000000000], ['p2p', 'Gi0/0/1', '10.0.1.0/30', 1000000000, 400000000]],
            array_map(fn ($c) => [$c['kind'], $c['a']['port'], $c['network'], $c['speed'], $c['in_bps']], $connections));

        // (b) the fiber between the core switches, the circuit between the routers
        $devices = [1 => self::device(1, 'core-a', 1), 2 => self::device(2, 'core-b', 2), 3 => self::device(3, 'rtr-a', 1), 4 => self::device(4, 'rtr-b', 2)];
        $ports = self::ports(self::port(11, 1, 'TenGigabitEthernet1/1/1'), self::port(21, 2, 'TenGigabitEthernet1/1/1'),
            self::port(33, 3, 'GigabitEthernet0/0/1', 'ethernetCsmacd', 1000000000), self::port(34, 3, 'GigabitEthernet0/0/1.100', 'l2vlan', 1000000000),
            self::port(43, 4, 'GigabitEthernet0/0/1', 'ethernetCsmacd', 1000000000), self::port(44, 4, 'GigabitEthernet0/0/1.100', 'l2vlan', 1000000000));
        $connections = self::connections($devices, $ports, [self::lldp(1, 11, 2, 21)], [[$ports[34], '10.0.1.1', 30], [$ports[44], '10.0.1.2', 30]],
            [], [[33, 34], [43, 44]]);
        $this->assertSame([['l2', 10000000000], ['p2p', 1000000000]], array_map(fn ($c) => [$c['kind'], $c['speed']], $connections));

        // (c) Junos: the fiber on xe-0/1/0 with its unit 0, the circuit on xe-0/2/0 unit 0, with and without stack rows
        $devices = [1 => self::device(1, 'mx-a', 1), 2 => self::device(2, 'mx-b', 2)];
        $ports = self::ports(self::port(11, 1, 'xe-0/1/0'), self::port(12, 1, 'xe-0/1/0.0', 'propVirtual', 0), self::port(13, 1, 'xe-0/2/0'),
            self::port(14, 1, 'xe-0/2/0.0', 'propVirtual', 0), self::port(21, 2, 'xe-0/1/0'), self::port(22, 2, 'xe-0/1/0.0', 'propVirtual', 0),
            self::port(23, 2, 'xe-0/2/0'), self::port(24, 2, 'xe-0/2/0.0', 'propVirtual', 0));
        foreach ([[[11, 12], [13, 14], [21, 22], [23, 24]], []] as $pairs) {
            $connections = self::connections($devices, $ports, [self::lldp(1, 11, 2, 21)], [[$ports[12], '10.0.0.1', 30], [$ports[22], '10.0.0.2', 30],
                [$ports[14], '10.0.1.1', 30], [$ports[24], '10.0.1.2', 30]], [], $pairs);
            $this->assertSame([['l2', 'xe-0/1/0', '10.0.0.0/30', 10000000000], ['p2p', 'xe-0/2/0', '10.0.1.0/30', 10000000000]],
                array_map(fn ($c) => [$c['kind'], $c['a']['port'], $c['network'], $c['speed']], $connections));
        }
    }

    public function testOnlyPortsThatReachTheOtherSiteThroughTheirOwnSiteRideItsCables(): void
    {
        // router ports facing the carrier's CPEs, monitored at the sites, and a fiber between the sites' switches: the
        // CPEs lead to no cable to the other site, the routers' /30 is a circuit of its own
        $devices = [1 => self::device(1, 'rtr-a', 1), 2 => self::device(2, 'rtr-b', 2), 3 => self::device(3, 'sw-a', 1), 4 => self::device(4, 'sw-b', 2),
            5 => self::device(5, 'cpe-hq', 1), 6 => self::device(6, 'cpe-br', 2)];
        $ports = self::ports(self::port(11, 1, 'GigabitEthernet0/0/1', 'ethernetCsmacd', 1000000000), self::port(21, 2, 'GigabitEthernet0/0/1', 'ethernetCsmacd', 1000000000),
            self::port(31, 3, 'TenGigabitEthernet1/1/1'), self::port(41, 4, 'TenGigabitEthernet1/1/1'),
            self::port(51, 5, 'ge-0/0/1', 'ethernetCsmacd', 1000000000), self::port(61, 6, 'ge-0/0/1', 'ethernetCsmacd', 1000000000));
        $connections = self::connections($devices, $ports, [self::lldp(1, 11, 5, 51), self::lldp(2, 21, 6, 61), self::lldp(3, 31, 4, 41)],
            [[$ports[11], '10.0.1.1', 30], [$ports[21], '10.0.1.2', 30]]);
        $this->assertSame([['l2', ''], ['p2p', '10.0.1.0/30']], array_map(fn ($c) => [$c['kind'], (string) $c['network']], $connections));

        // a transit VLAN from the HQ core to a branch router behind an access switch behind the branch core, which has
        // the fiber: it rides the fiber
        $devices = [1 => self::device(1, 'core', 1), 2 => self::device(2, 'core-b', 2), 3 => self::device(3, 'acc-b', 2), 4 => self::device(4, 'rtr-b', 2)];
        $ports = self::ports(self::port(11, 1, 'TenGigabitEthernet1/1/1'), self::port(12, 1, 'Vlan900', 'propVirtual', 1000000000),
            self::port(21, 2, 'TenGigabitEthernet1/1/1'), self::port(22, 2, 'TenGigabitEthernet1/1/2'), self::port(31, 3, 'TenGigabitEthernet1/1/1'),
            self::port(32, 3, 'GigabitEthernet1/0/1', 'ethernetCsmacd', 1000000000), self::port(41, 4, 'GigabitEthernet0/0/0', 'ethernetCsmacd', 1000000000),
            self::port(42, 4, 'GigabitEthernet0/0/0.900', 'l2vlan', 1000000000));
        $links = [self::lldp(1, 11, 2, 21), self::lldp(2, 22, 3, 31), self::lldp(4, 41, 3, 32)];
        $addresses = [[$ports[12], '10.9.0.1', 29], [$ports[42], '10.9.0.2', 29]];
        $this->assertSame([['l2', '10.9.0.0/29']], array_map(fn ($c) => [$c['kind'], $c['network']], self::connections($devices, $ports, $links, $addresses, [], [[41, 42]])));

        // the same with the CPEs' management ports cabled to the switches with the fiber: the search goes no further
        // than the CPE, which is no switch
        $ports += self::ports(self::port(52, 5, 'mgmt0', 'ethernetCsmacd', 100000000), self::port(62, 6, 'mgmt0', 'ethernetCsmacd', 100000000),
            self::port(32, 3, 'GigabitEthernet1/0/10', 'ethernetCsmacd', 1000000000), self::port(42, 4, 'GigabitEthernet1/0/10', 'ethernetCsmacd', 1000000000));
        $cpes = [1 => self::device(1, 'rtr-a', 1), 2 => self::device(2, 'rtr-b', 2), 3 => self::device(3, 'sw-a', 1), 4 => self::device(4, 'sw-b', 2),
            5 => self::device(5, 'cpe-hq', 1), 6 => self::device(6, 'cpe-br', 2)];
        $connections = self::connections($cpes, $ports, [self::lldp(1, 11, 5, 51), self::lldp(2, 21, 6, 61), self::lldp(3, 31, 4, 41),
            self::lldp(5, 52, 3, 32), self::lldp(6, 62, 4, 42)], [[$ports[11], '10.0.1.1', 30], [$ports[21], '10.0.1.2', 30]]);
        $this->assertSame([['l2', ''], ['p2p', '10.0.1.0/30']], array_map(fn ($c) => [$c['kind'], (string) $c['network']], $connections));

        // the access switch only reaches the fiber through the router itself (cabled to the core-b behind it): not by its site
        $links = [self::lldp(1, 11, 2, 21), self::lldp(4, 41, 3, 32), self::lldp(4, 43, 2, 22)];
        $ports += self::ports(self::port(43, 4, 'GigabitEthernet0/0/2', 'ethernetCsmacd', 1000000000));
        $this->assertSame([['l2', ''], ['p2p', '10.9.0.0/29']],
            array_map(fn ($c) => [$c['kind'], (string) $c['network']], self::connections($devices, $ports, $links, $addresses, [], [[41, 42]])));
    }

    public function testTheLinksInsideSitesAreAskedForOnce(): void
    {
        // two branches, each router behind an access switch behind the branch core with the fiber to HQ: one request
        // for the links inside both branches
        $devices = [1 => self::device(1, 'core', 1), 2 => self::device(2, 'rtr-hq', 1)];
        $ports = self::ports(self::port(11, 1, 'GigabitEthernet1/0/1'), self::port(21, 2, 'GigabitEthernet0/0/0'));
        $links = [self::lldp(2, 21, 1, 11)];
        $addresses = [];
        foreach ([2, 3] as $k) {
            [$core, $acc, $rtr] = [10 * $k, 10 * $k + 1, 10 * $k + 2];
            $devices += [$core => self::device($core, "core-$k", $k), $acc => self::device($acc, "acc-$k", $k), $rtr => self::device($rtr, "rtr-$k", $k)];
            $ports += self::ports(self::port(100 * $k + 1, $core, 'TenGigabitEthernet1/1/1'), self::port(100 * $k + 2, 1, "TenGigabitEthernet1/1/$k"),
                self::port(100 * $k + 3, $acc, 'GigabitEthernet1/0/49'), self::port(100 * $k + 4, $core, 'GigabitEthernet1/0/1'),
                self::port(100 * $k + 5, $rtr, 'GigabitEthernet0/0/0', 'ethernetCsmacd', 1000000000), self::port(100 * $k + 6, $acc, 'GigabitEthernet1/0/1'),
                self::port(100 * $k + 7, $rtr, 'GigabitEthernet0/0/0.900', 'l2vlan', 1000000000), self::port(100 * $k + 8, 2, "GigabitEthernet0/0/0.9$k", 'l2vlan', 1000000000));
            $links = array_merge($links, [self::lldp($core, 100 * $k + 1, 1, 100 * $k + 2), self::lldp($acc, 100 * $k + 3, $core, 100 * $k + 4),
                self::lldp($rtr, 100 * $k + 5, $acc, 100 * $k + 6)]);
            $addresses = array_merge($addresses, [[$ports[100 * $k + 8], "10.9.$k.1", 30], [$ports[100 * $k + 7], "10.9.$k.2", 30]]);
        }
        $pairs = [[21, 208], [21, 308], [205, 207], [305, 307]];
        $connections = self::connections($devices, $ports, $links, $addresses, [], $pairs, $asked);

        $this->assertSame([['site:2', 'site:3']], $asked);
        $this->assertSame([['l2', '10.9.2.0/30'], ['l2', '10.9.3.0/30']], array_map(fn ($c) => [$c['kind'], $c['network']], $connections));
    }

    public function testRouteBasedVpnInterfacesAreTunnels(): void
    {
        // a /30 between two firewalls on such interfaces carries 50 Mbit/s: traffic only, whatever their ifType and ifSpeed
        $devices = [1 => self::device(1, 'fw-a', 1), 2 => self::device(2, 'fw-b', 2)];
        foreach ([['ipsec0', 'ethernetCsmacd', 10000000], ['ipsec1', 'other', 0], ['wg0', 'other', 0], ['wg: hub', 'other', 0], ['wireguard1', 'other', 0],
            ['xfrm0', 'other', 0], ['ovpns1', 'other', 0], ['ovpnc1', 'other', 0], ['GRE-TUNNEL-1', 'propVirtual', 0], ['gre0', 'other', 0],
            ['Virtual-Access2', 'propVirtual', 0], ['st0.1', 'propVirtual', 0]] as [$name, $type, $speed]) {
            $ports = self::ports(self::port(11, 1, $name, $type, $speed, 'up', 6250000, 3125000), self::port(21, 2, $name, $type, $speed));
            $this->assertTrue(SiteConnections::isTunnel($ports[11]), $name);
            $connections = self::connections($devices, $ports, [], [[$ports[11], '10.0.0.1', 30], [$ports[21], '10.0.0.2', 30]]);
            $this->assertSame([0, null, 50000000], [$connections[0]['speed'], $connections[0]['util'], $connections[0]['in_bps']], $name);
        }
        foreach (['GigabitEthernet0/0/1', 'green0', 'wan1', 'tunnelbroker'] as $name) {
            $this->assertSame($name === 'tunnelbroker', SiteConnections::isTunnel(self::port(1, 1, $name)), $name);
        }
    }

    public function testInterfaceStackHelpers(): void
    {
        // stack pairs of either orientation: the upper port is the sub-interface, the VLAN or IRB interface, the port-channel
        $ports = self::ports(self::port(1, 5, 'GigabitEthernet0/0/1'), self::port(2, 5, 'GigabitEthernet0/0/1.100', 'l2vlan'),
            self::port(3, 5, 'irb', 'other'), self::port(4, 5, 'irb.300', 'propVirtual'), self::port(5, 5, 'Vlan10', 'propVirtual'),
            self::port(6, 5, 'Port-channel1', 'propVirtual'), self::port(7, 5, 'TenGigabitEthernet1/1/1'), self::port(8, 5, 'TenGigabitEthernet1/1/2'),
            self::port(9, 5, 'ae0', 'ieee8023adLag'), self::port(10, 5, 'ae0.0', 'propVirtual'));
        $expected = [6 => [7, 8], 2 => [1], 4 => [3], 5 => [6], 10 => [9]];
        $this->assertEquals($expected, SiteConnections::stack([[1, 2], [3, 4], [6, 5], [9, 10], [1, 7]], $ports, [7 => 6, 8 => 6]), 'LibreNMS 24.8+');
        $this->assertEquals($expected, SiteConnections::stack([[2, 1], [4, 3], [5, 6], [10, 9], [7, 1], [6, 7]], $ports, [7 => 6, 8 => 6]), 'before 24.8');

        $this->assertEqualsCanonicalizing([10, 11, 12, 21], SiteConnections::below([30, 40], [30 => [10], 10 => [11, 12], 40 => [21], 21 => [10]]));
        $ports = self::ports(self::port(1, 5, 'xe-0/1/0.0', 'propVirtual'), self::port(2, 5, 'Te0/0/0.100', 'l2vlan'), self::port(3, 5, 'Vlan300', 'propVirtual'),
            self::port(4, 5, 'ae0.0', 'propVirtual'));
        $ports[2]['ifDescr'] = 'TenGigabitEthernet0/0/0.100';
        $this->assertSame([
            1 => ['device_id' => 5, 'names' => ['xe-0/1/0']],
            2 => ['device_id' => 5, 'names' => ['Te0/0/0', 'TenGigabitEthernet0/0/0']],
        ], SiteConnections::unitParents($ports, [4 => [9]]), 'only names with a unit, and only where the stack has nothing');
    }
}
