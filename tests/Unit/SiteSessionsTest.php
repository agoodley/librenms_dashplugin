<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Topology\SiteBuilder;
use Agoodley\LibrenmsTopology\Topology\SiteSessions;
use PHPUnit\Framework\TestCase;

class SiteSessionsTest extends TestCase
{
    private static function device(int $id, string $name, int $location, int $asn = 0): array
    {
        return [
            'device_id' => $id, 'display' => $name, 'hostname' => $name, 'status' => 1, 'disabled' => 0, 'role' => 'router', 'url' => "/device/$id",
            'location_id' => $location, 'location' => "Site $location", 'bgpLocalAs' => $asn, 'lat' => null, 'lng' => null,
        ];
    }

    /**
     * Address owners as SiteRepository::addressOwners() returns them.
     *
     * @param  array<int, array{0: string, 1: int, 2: int, 3?: string}>  $addresses  [address, device, prefix, vrf]
     */
    private static function owners(array $addresses): array
    {
        $owners = [];
        foreach ($addresses as $address) {
            $owners[$address[0]][] = ['device_id' => $address[1], 'prefixlen' => $address[2], 'vrf' => $address[3] ?? ''];
        }

        return $owners;
    }

    private static function ospf(int $device, string $address, string $state = 'full'): array
    {
        return ['device_id' => $device, 'port_id' => 0, 'ospfNbrIpAddr' => $address, 'ospfNbrRtrId' => $address, 'ospfNbrState' => $state, 'context_name' => ''];
    }

    private static function bgp(int $device, string $peer, int $remoteAs, string $state = 'established', string $local = '', string $admin = 'start', string $context = ''): array
    {
        return ['device_id' => $device, 'bgpPeerIdentifier' => $peer, 'bgpPeerRemoteAs' => $remoteAs, 'bgpPeerState' => $state, 'bgpPeerAdminStatus' => $admin,
            'bgpLocalAddr' => $local, 'bgpPeerDescr' => '', 'context_name' => $context];
    }

    public function testTheWorseSideOfAnAdjacencyWins(): void
    {
        // an MTU mismatch: rtr-a sees the adjacency full, rtr-b is stuck in exstart
        $devices = [1 => self::device(1, 'rtr-a', 1), 2 => self::device(2, 'rtr-b', 2)];
        $owners = self::owners([['10.0.0.1', 1, 30], ['10.0.0.2', 2, 30]]);
        $connections = (new SiteSessions($devices, $owners))->addOspf([self::ospf(1, '10.0.0.2'), self::ospf(2, '10.0.0.1', 'exstart')])->all();

        $this->assertCount(1, $connections, 'one adjacency, seen from both routers');
        $this->assertSame(['down', 'full / exstart', ['full', 'exstart'], '10.0.0.0/30'],
            [$connections[0]['status'], $connections[0]['state'], $connections[0]['states'], $connections[0]['network']]);

        $result = (new SiteBuilder($devices, $connections))->build();
        $this->assertSame(['down', 1], [$result['edges'][0]['status'], $result['stats']['down_links']]);

        // the same adjacency reported the other way round, rtr-a at the second site: the member turns with its states
        $devices = [1 => self::device(1, 'rtr-a', 2), 2 => self::device(2, 'rtr-b', 1)];
        $connections = (new SiteSessions($devices, $owners))->addOspf([self::ospf(1, '10.0.0.2'), self::ospf(2, '10.0.0.1', 'exstart')])->all();
        $member = (new SiteBuilder($devices, $connections))->build()['edges'][0]['members'][0];
        $this->assertSame(['rtr-b', 'exstart / full', 'down'], [$member['a']['device'], $member['state'], $member['status']]);
    }

    public function testOneAdjacencyAndSessionPerCircuit(): void
    {
        // two links between rtr-a and rtr-b (10.0.0.0/30, 10.0.0.4/30) with OSPF and eBGP on each, an unnumbered
        // adjacency and an idle session between the loopbacks
        $devices = [1 => self::device(1, 'rtr-a', 1, 65001), 2 => self::device(2, 'rtr-b', 2, 65002)];
        $owners = self::owners([['10.0.0.1', 1, 30], ['10.0.0.2', 2, 30], ['10.0.0.5', 1, 30], ['10.0.0.6', 2, 30], ['10.9.9.1', 1, 32], ['10.9.9.2', 2, 32]]);
        $sessions = (new SiteSessions($devices, $owners))
            ->addOspf([self::ospf(1, '10.0.0.2'), self::ospf(1, '10.0.0.6'), self::ospf(2, '10.0.0.1'), self::ospf(2, '10.0.0.5'),
                self::ospf(1, '10.9.9.2'), self::ospf(2, '10.9.9.1')])
            ->addBgp([
                self::bgp(1, '10.0.0.2', 65002, 'established', '10.0.0.1'), self::bgp(1, '10.0.0.6', 65002, 'established', '10.0.0.5'),
                self::bgp(2, '10.0.0.5', 65001, 'established', '10.0.0.6'), self::bgp(2, '10.0.0.1', 65001, 'established', '10.0.0.2'),
                self::bgp(1, '10.9.9.2', 65002, 'idle', '0.0.0.0'), self::bgp(2, '10.9.9.1', 65001, 'active'),
            ])->all();

        $ospf = array_values(array_filter($sessions, fn ($c) => $c['kind'] === 'ospf'));
        $this->assertSame(['10.0.0.0/30', '10.0.0.4/30', '10.9.9.1, 10.9.9.2'], array_column($ospf, 'network'));
        $this->assertSame(['up', 'up', 'up'], array_column($ospf, 'status'));

        $bgp = array_values(array_filter($sessions, fn ($c) => $c['kind'] === 'bgp'));
        $this->assertSame(['10.0.0.1, 10.0.0.2', '10.0.0.5, 10.0.0.6', '10.9.9.1, 10.9.9.2'], array_column($bgp, 'network'));
        $this->assertSame([['established', 'established'], ['established', 'established'], ['idle', 'active']], array_column($bgp, 'states'));
        $this->assertSame(['up', 'up', 'down'], array_column($bgp, 'status'));
        $this->assertSame('eBGP', $bgp[0]['type']);

        $edge = (new SiteBuilder($devices, $sessions))->build()['edges'][0];
        $this->assertSame([['ospf' => 3, 'bgp' => 3], 'degraded'], [$edge['routing'], $edge['status']]);
    }

    public function testSessionsShutDownByAnAdministratorAreDisabled(): void
    {
        $devices = [1 => self::device(1, 'rtr-a', 1, 65001), 2 => self::device(2, 'rtr-b', 2, 65002), 3 => self::device(3, 'rtr-c', 3, 65003)];
        $owners = self::owners([['10.0.0.1', 1, 30], ['10.0.0.2', 2, 30], ['10.0.0.9', 1, 30], ['10.0.0.10', 3, 30]]);
        $sessions = (new SiteSessions($devices, $owners))->addBgp([
            self::bgp(1, '10.0.0.2', 65002, 'established', '10.0.0.1'), self::bgp(2, '10.0.0.1', 65001, 'established', '10.0.0.2'),
            // shut down on rtr-a: rtr-c keeps trying
            self::bgp(1, '10.0.0.10', 65003, 'idle', '', 'stop'), self::bgp(3, '10.0.0.9', 65001, 'active'),
            // Junos: halted / running
            self::bgp(2, '203.0.113.1', 64496, 'idle', '', 'halted'), self::bgp(2, '203.0.113.5', 64496, 'established', '203.0.113.6', 'running'),
        ])->all();
        $this->assertSame(['up', 'disabled', 'disabled', 'up'], array_column($sessions, 'status'));

        $result = (new SiteBuilder($devices, $sessions))->build();
        $this->assertSame(['site:1|site:2' => 'up', 'site:1|site:3' => 'disabled', 'site:2|as:64496' => 'up'],
            array_column(array_map(fn ($e) => ['key' => $e['from'] . '|' . $e['to'], 'status' => $e['status']], $result['edges']), 'status', 'key'));
        $this->assertSame([0, 0], [$result['stats']['down_links'], $result['stats']['degraded_links']]);
    }

    public function testPeersOutsideTheScopeAndUnknownPeers(): void
    {
        // rtr-a's neighbors: rtr-x (5), a device the user may see outside the map's scope (a device group), and
        // a provider router LibreNMS does not monitor
        $devices = [1 => self::device(1, 'rtr-a', 1, 65001)];
        $owners = self::owners([['10.0.0.1', 1, 30], ['10.0.0.2', 5, 30]]);
        $sessions = (new SiteSessions($devices, $owners))
            ->addOspf([self::ospf(1, '10.0.0.2'), self::ospf(1, '192.0.2.99')])
            ->addBgp([self::bgp(1, '10.0.0.2', 65005), self::bgp(1, '198.51.100.1', 64496), self::bgp(1, '198.51.100.1', 64496)])
            ->all();

        $this->assertCount(1, $sessions, 'no cloud for a monitored device, a session listed twice is one');
        $this->assertSame(['as:64496', 'AS64496', 'Autonomous System 64496', 64496, false, false], array_values($sessions[0]['cloud']));
        $this->assertSame(['198.51.100.1', 'up', null], [$sessions[0]['network'], $sessions[0]['status'], $sessions[0]['b']]);
    }

    public function testPrivateAsCloudsArePerSite(): void
    {
        // every site's firewall is in AS65100, the provider in AS64496
        $devices = [1 => self::device(1, 'rtr-a', 1, 65001), 2 => self::device(2, 'rtr-b', 2, 65002), 3 => self::device(3, 'rtr-a2', 1, 65001)];
        $sessions = (new SiteSessions($devices, []))->addBgp([
            self::bgp(1, '192.0.2.1', 65100), self::bgp(2, '192.0.2.5', 65100), self::bgp(3, '192.0.2.9', 65100),
            self::bgp(1, '198.51.100.1', 64496), self::bgp(2, '198.51.100.5', 64496),
            self::bgp(2, '198.51.100.9', 4200000001), self::bgp(1, '198.51.100.13', 0),
        ])->all();

        $this->assertSame(['as:65100@site:1', 'as:65100@site:2', 'as:65100@site:1', 'as:64496', 'as:64496', 'as:4200000001@site:2', 'as:198.51.100.13'],
            array_map(fn ($c) => $c['cloud']['id'], $sessions));
        $this->assertSame(['AS65100', 'Autonomous System 65100 at Site 2', true], [$sessions[1]['cloud']['label'], $sessions[1]['cloud']['name'], $sessions[1]['cloud']['private']]);
        $this->assertSame(['198.51.100.13', 'BGP peer 198.51.100.13'], [$sessions[6]['cloud']['label'], $sessions[6]['cloud']['name']]);

        // the private clouds do not join the sites, the provider's does
        $result = (new SiteBuilder($devices, $sessions))->build(['center' => 1, 'depth' => 2]);
        $this->assertEqualsCanonicalizing(['site:1', 'site:2', 'as:65100@site:1', 'as:64496', 'as:198.51.100.13'], array_column($result['nodes'], 'id'));
    }

    public function testSitesPeeringWithTheSameRemoteGatewayShareItsPrivateAsCloud(): void
    {
        // three branches peer with the same cloud VPN gateway (AS65515 at 10.255.0.254) from their loopbacks; branch 3's
        // session is down (no local address); every branch also peers with its own firewall at 192.168.1.2 (AS65001) on
        // a /30 of the router
        $devices = [];
        $owners = [];
        $rows = [];
        foreach ([1, 2, 3] as $s) {
            $devices[$s] = self::device($s, "fw-$s", $s, 65000 + $s);
            $owners["10.255.$s.1"][] = ['device_id' => $s, 'prefixlen' => 32, 'vrf' => ''];
            $owners['192.168.1.1'][] = ['device_id' => $s, 'prefixlen' => 30, 'vrf' => ''];
            $rows[] = self::bgp($s, '10.255.0.254', 65515, $s === 3 ? 'active' : 'established', $s === 3 ? '0.0.0.0' : "10.255.$s.1");
            $rows[] = self::bgp($s, '192.168.1.2', 65001, 'established', '192.168.1.1');
        }
        $sessions = (new SiteSessions($devices, $owners))->addBgp($rows)->all();

        $this->assertSame(['as:65515@10.255.0.254', 'as:65001@site:1', 'as:65515@10.255.0.254', 'as:65001@site:2', 'as:65515@10.255.0.254', 'as:65001@site:3'],
            array_map(fn ($c) => $c['cloud']['id'], $sessions));
        $this->assertSame(['Autonomous System 65515', true, true], [$sessions[0]['cloud']['name'], $sessions[0]['cloud']['private'], $sessions[0]['cloud']['shared']]);
        $this->assertSame(['Autonomous System 65001 at Site 1', false], [$sessions[1]['cloud']['name'], $sessions[1]['cloud']['shared']]);

        $result = (new SiteBuilder($devices, $sessions))->build(['center' => 1, 'depth' => 2]);
        $this->assertEqualsCanonicalizing(['site:1', 'site:2', 'site:3', 'as:65515@10.255.0.254', 'as:65001@site:1'], array_column($result['nodes'], 'id'));

        // without the local addresses nothing shows that the gateway is not on a subnet of the routers: a cloud per site
        $alone = (new SiteSessions($devices, []))->addBgp($rows)->all();
        $this->assertSame('as:65515@site:2', $alone[2]['cloud']['id']);
    }

    public function testSharedSegmentsAndSessionsThatAreDown(): void
    {
        // three spokes of a DMVPN hub LibreNMS does not monitor (AS65000 at 10.0.0.1 on the tunnels' /24): each spoke has
        // an address of its own on the subnet, a shared segment
        $devices = [];
        $owners = [];
        $rows = [];
        $tunnel = ['ifType' => 'tunnel', 'ifName' => 'Tunnel0', 'ifDescr' => 'Tunnel0'];
        foreach ([1, 2, 3] as $s) {
            $devices[$s] = self::device($s, "spoke-$s", $s, 65100 + $s);
            $owners['10.0.0.' . (1 + $s)][] = ['device_id' => $s, 'prefixlen' => 24, 'vrf' => '', 'port' => $tunnel];
            $rows[] = self::bgp($s, '10.0.0.1', 65000, 'established', '10.0.0.' . (1 + $s));
        }
        $this->assertSame(['as:65000@10.0.0.1'], array_values(array_unique(array_map(fn ($c) => $c['cloud']['id'],
            (new SiteSessions($devices, $owners))->addBgp($rows)->all()))));

        // a template transit LAN: every branch's own firewall at .254 of the same /24, the routers at addresses of their
        // own on Ethernet: three firewalls, a cloud per site
        $lan = array_map(fn ($list) => array_map(fn ($owner) => ['port' => ['ifType' => 'ethernetCsmacd', 'ifName' => 'Gi0/0/1']] + $owner, $list), $owners);
        $this->assertSame(['as:65000@site:1', 'as:65000@site:2', 'as:65000@site:3'], array_map(fn ($c) => $c['cloud']['id'],
            (new SiteSessions($devices, $lan))->addBgp($rows)->all()));

        // every session down (no local address): the routers' interface addresses tell where the peer is, so the
        // cloud stays shared; the routers to look up are those of unknownSources()
        $down = array_map(fn ($row) => ['bgpPeerState' => 'active', 'bgpLocalAddr' => '0.0.0.0'] + $row, $rows);
        $this->assertSame([1, 2, 3], SiteSessions::unknownSources($down, $owners));
        $this->assertSame([], SiteSessions::unknownSources($rows, $owners));
        $interfaces = array_map(fn ($s) => [['10.0.0.' . (1 + $s), 24, $tunnel], ['192.168.' . $s . '.1', 24]], [1 => 1, 2 => 2, 3 => 3]);
        $this->assertSame(['as:65000@10.0.0.1'], array_values(array_unique(array_map(fn ($c) => $c['cloud']['id'],
            (new SiteSessions($devices, $owners, $interfaces))->addBgp($down)->all()))));

        // a cloud VPN gateway reached from the routers' tunnel /30s, every session down: still shared
        $gateway = array_map(fn ($row) => ['bgpPeerIdentifier' => '10.255.0.254', 'bgpPeerRemoteAs' => 65515] + $row, $down);
        $interfaces = array_map(fn ($s) => [['10.255.' . $s . '.1', 30]], [1 => 1, 2 => 2, 3 => 3]);
        $this->assertSame(['as:65515@10.255.0.254'], array_values(array_unique(array_map(fn ($c) => $c['cloud']['id'],
            (new SiteSessions($devices, [], $interfaces))->addBgp($gateway)->all()))));
    }

    public function testNeighborsInTheSameVrfAndAddressForms(): void
    {
        // 10.0.0.2 is ce-x in VRF X and ce-y in VRF Y
        $devices = [1 => self::device(1, 'pe-a', 1, 65000), 2 => self::device(2, 'ce-x', 2, 65010), 3 => self::device(3, 'ce-y', 3, 65020)];
        $owners = self::owners([['10.0.0.2', 2, 30, 'X'], ['10.0.0.2', 3, 30, 'Y'], ['2001:db8::2', 2, 126]]);
        $sessions = (new SiteSessions($devices, $owners))->addBgp([
            self::bgp(1, '10.0.0.2', 65020, 'established', '10.0.0.1', 'start', 'Y'),
            self::bgp(1, '2001:DB8:0:0::2', 65010, 'established', '2001:db8::1'),
        ])->all();

        $this->assertSame([[1, 3], [1, 2]], array_map(fn ($c) => [$c['a']['device_id'], $c['b']['device_id']], $sessions));
        $this->assertSame('2001:db8::1, 2001:db8::2', $sessions[1]['network']);
    }
}
