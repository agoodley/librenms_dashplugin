<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\History\PastMap;
use Agoodley\LibrenmsTopology\Topology\L3Builder;
use PHPUnit\Framework\TestCase;

class L3BuilderTest extends TestCase
{
    private function devices(): array
    {
        $devices = [];
        foreach ([1 => 'rtr1', 2 => 'rtr2', 3 => 'core1', 4 => 'fw1'] as $id => $name) {
            $devices[$id] = ['device_id' => $id, 'display' => $name, 'hostname' => $name, 'status' => 1, 'role' => $id === 4 ? 'firewall' : 'router', 'bgpLocalAs' => 65000];
        }

        return $devices;
    }

    private function address(int $deviceId, int $portId, string $name, string $ip, int $prefix, string $vrf = '', string $type = 'ethernetCsmacd'): array
    {
        return ['device_id' => $deviceId, 'port_id' => $portId, 'ifName' => $name, 'ifDescr' => $name, 'ifOperStatus' => 'up',
            'ifType' => $type, 'address' => $ip, 'prefixlen' => $prefix, 'vrf' => $vrf];
    }

    private function addresses(): array
    {
        return [
            // rtr1 <-> rtr2 point-to-point /30
            $this->address(1, 11, 'GigabitEthernet0/0', '10.0.0.1', 30),
            $this->address(2, 21, 'GigabitEthernet0/0', '10.0.0.2', 30),
            // LAN segment shared by rtr1, rtr2, core1 and fw1
            $this->address(1, 12, 'GigabitEthernet0/1', '10.1.0.1', 24),
            $this->address(2, 22, 'GigabitEthernet0/1', '10.1.0.2', 24),
            $this->address(3, 31, 'Vlan100', '10.1.0.3', 24),
            $this->address(4, 41, 'port1', '10.1.0.4', 24),
            // stub user VLAN and loopbacks
            $this->address(3, 32, 'Vlan200', '10.2.0.1', 24),
            $this->address(1, 13, 'Loopback0', '10.255.0.1', 32, '', 'softwareLoopback'),
            $this->address(2, 23, 'Loopback0', '10.255.0.2', 32, '', 'softwareLoopback'),
            // VRF segment
            $this->address(1, 14, 'GigabitEthernet0/2.10', '172.16.0.1', 30, 'CUST'),
            $this->address(4, 42, 'port2', '172.16.0.2', 30, 'CUST'),
            // ignored
            $this->address(1, 15, 'mgmt', '169.254.1.1', 16),
        ];
    }

    public function testSubnetsBecomeLinksAndSegments(): void
    {
        $builder = new L3Builder($this->devices(), $this->addresses());
        $result = $builder->build(['ospf' => false, 'bgp' => false]);

        $p2p = array_column(array_filter($result['edges'], fn ($e) => $e['kind'] === 'p2p'), null, 'network');
        $this->assertCount(2, $p2p);
        $this->assertSame(['10.0.0.1'], $p2p['10.0.0.0/30']['a']['ips']);
        $this->assertSame('Gi0/0', $p2p['10.0.0.0/30']['a']['short']);
        $this->assertSame(['10.0.0.2'], $p2p['10.0.0.0/30']['b']['ips']);
        $this->assertSame('CUST', $p2p['172.16.0.0/30']['vrf']);

        $subnets = array_values(array_filter($result['nodes'], fn ($n) => $n['type'] === 'subnet'));
        $this->assertCount(1, $subnets, 'stub network hidden by default');
        $this->assertSame('10.1.0.0/24', $subnets[0]['network']);
        $this->assertCount(4, array_filter($result['edges'], fn ($e) => $e['kind'] === 'member'));

        $nodes = array_column($result['nodes'], null, 'id');
        $this->assertSame(['10.255.0.1'], $nodes['d:1']['loopbacks']);
        $this->assertSame(['10.2.0.0/24'], $nodes['d:3']['stubs']);
        $this->assertSame(['CUST'], $result['vrfs']);
    }

    public function testStubsExclusionAndVrfFilter(): void
    {
        $builder = new L3Builder($this->devices(), $this->addresses());
        $result = $builder->build(['hideStubs' => false, 'exclude' => ['10.1.0.0/16'], 'ospf' => false, 'bgp' => false]);
        $networks = array_column(array_filter($result['nodes'], fn ($n) => $n['type'] === 'subnet'), 'network');
        $this->assertSame(['10.2.0.0/24'], array_values($networks));

        $vrf = $builder->build(['vrf' => 'CUST', 'ospf' => false, 'bgp' => false]);
        $this->assertCount(1, $vrf['edges']);
        $this->assertSame('172.16.0.0/30', $vrf['edges'][0]['network']);
    }

    public function testHistoryRowsKeepTheNeighbourTheyRecorded(): void
    {
        // a map of the past: rtr1's adjacency and session were with rtr2 over addresses that today are core1's, and rtr2's
        // adjacency over 10.2.0.1 had no device then (core1 has that address today)
        $ospf = [PastMap::ospfRow(['device' => 1, 'ip' => '10.1.0.3', 'router_id' => '10.255.0.2', 'context' => '', 'neighbor' => 2]),
            PastMap::ospfRow(['device' => 2, 'ip' => '10.2.0.1', 'router_id' => '9.9.9.9', 'context' => '', 'neighbor' => null])];
        $bgp = [PastMap::bgpRow(['device' => 1, 'peer' => '10.1.0.3', 'remote_as' => 65000, 'context' => '', 'neighbor' => 2])];
        $result = (new L3Builder($this->devices(), $this->addresses(), $ospf, $bgp))->build();

        $pairs = array_map(fn ($e) => $e['kind'] . ' ' . $e['from'] . '-' . $e['to'], array_values(array_filter($result['edges'], fn ($e) => in_array($e['kind'], ['ospf', 'bgp'], true))));
        sort($pairs);
        $this->assertSame(['bgp d:1-d:2', 'ospf d:1-d:2', 'ospf d:2-x:9.9.9.9'], $pairs, 'as recorded, not by today\'s addresses');
        $this->assertContains(2, L3Builder::layerLookups([], $ospf, $bgp)['devices'], 'a recorded neighbour beyond the map is looked up as a device');
    }

    public function testRoutingOverlays(): void
    {
        $ospf = [
            ['device_id' => 1, 'ospfNbrIpAddr' => '10.0.0.2', 'ospfNbrRtrId' => '10.255.0.2', 'ospfNbrState' => 'full'],
            ['device_id' => 2, 'ospfNbrIpAddr' => '10.0.0.1', 'ospfNbrRtrId' => '10.255.0.1', 'ospfNbrState' => 'full'],
            ['device_id' => 2, 'ospfNbrIpAddr' => '10.9.9.9', 'ospfNbrRtrId' => '9.9.9.9', 'ospfNbrState' => 'exStart'],
        ];
        $bgp = [
            ['device_id' => 1, 'bgpPeerIdentifier' => '10.255.0.2', 'bgpPeerRemoteAs' => 65000, 'bgpPeerState' => 'established'],
            ['device_id' => 4, 'bgpPeerIdentifier' => '203.0.113.1', 'bgpPeerRemoteAs' => 64512, 'bgpPeerState' => 'established'],
            ['device_id' => 4, 'bgpPeerIdentifier' => '203.0.113.5', 'bgpPeerRemoteAs' => 64512, 'bgpPeerState' => 'active'],
        ];
        $result = (new L3Builder($this->devices(), $this->addresses(), $ospf, $bgp))->build();

        $ospfEdges = array_values(array_filter($result['edges'], fn ($e) => $e['kind'] === 'ospf'));
        $this->assertCount(2, $ospfEdges, 'bidirectional adjacency merged, unknown neighbor kept');
        $this->assertSame('full', $ospfEdges[0]['state']);
        $this->assertSame('down', $ospfEdges[1]['status']);

        $bgpEdges = array_values(array_filter($result['edges'], fn ($e) => $e['kind'] === 'bgp'));
        $this->assertCount(2, $bgpEdges);
        $this->assertSame('iBGP', $bgpEdges[0]['type']);
        $this->assertSame('eBGP', $bgpEdges[1]['type']);
        $this->assertSame('as:64512', $bgpEdges[1]['to']);
        $this->assertSame('down', $bgpEdges[1]['status'], 'one of two sessions to the AS is down');

        $nodes = array_column($result['nodes'], null, 'id');
        $this->assertSame(0, $nodes['as:64512']['level'], 'external AS drawn at the top');
    }

    public function testAllMembersOfALanAreOneHopApart(): void
    {
        $addresses = [
            $this->address(1, 12, 'Gi0/1', '10.1.0.1', 24),
            $this->address(2, 22, 'Gi0/1', '10.1.0.2', 24),
            $this->address(3, 31, 'Vlan100', '10.1.0.3', 24),
        ];
        $result = (new L3Builder($this->devices(), $addresses))->build(['root' => 2, 'depth' => 1, 'ospf' => false, 'bgp' => false]);
        $ids = array_column(array_filter($result['nodes'], fn ($n) => $n['type'] === 'device'), 'device_id');
        sort($ids);
        $this->assertSame([1, 2, 3], $ids);
        $this->assertCount(3, array_filter($result['edges'], fn ($e) => $e['kind'] === 'member'));
    }

    public function testScopeAroundDevice(): void
    {
        $addresses = $this->addresses();
        $addresses[] = $this->address(5, 51, 'Gi0/0', '10.5.0.2', 30);
        $addresses[] = $this->address(2, 24, 'Gi0/3', '10.5.0.1', 30);
        $devices = $this->devices() + [5 => ['device_id' => 5, 'display' => 'branch', 'status' => 1, 'role' => 'router']];

        $ospf = [['device_id' => 2, 'ospfNbrIpAddr' => '10.0.0.1', 'ospfNbrRtrId' => '10.255.0.1', 'ospfNbrState' => 'full']];
        $result = (new L3Builder($devices, $addresses, $ospf))->build(['root' => 5, 'depth' => 1, 'bgp' => false]);
        $ids = array_column(array_filter($result['nodes'], fn ($n) => $n['type'] === 'device' && empty($n['outside'])), 'device_id');
        sort($ids);
        $this->assertSame([2, 5], $ids);
        $this->assertEmpty(array_filter($result['nodes'], fn ($n) => $n['type'] === 'external'), 'out-of-scope OSPF peer is not drawn as unknown');

        // the OSPF neighbour beyond the hops is a stub of rtr1, outside the map
        $nodes = array_column($result['nodes'], null, 'id');
        $this->assertSame(['rtr1', true], [$nodes['d:1']['name'], $nodes['d:1']['outside']]);
        $this->assertSame(['d:2', 'd:1'], array_values(array_map(fn ($e) => [$e['from'], $e['to']], array_filter($result['edges'], fn ($e) => $e['kind'] === 'ospf')))[0]);
        $this->assertSame(1, $result['stats']['outside']);
    }
}
