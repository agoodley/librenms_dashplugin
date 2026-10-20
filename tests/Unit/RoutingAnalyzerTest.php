<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Routing\BgpErrors;
use PHPUnit\Framework\TestCase;

class RoutingAnalyzerTest extends TestCase
{
    private static function codes(array $result): array
    {
        $codes = array_column($result['findings'], 'code');
        sort($codes);

        return $codes;
    }

    private static function find(array $result, string $code, ?string $titlePart = null): ?array
    {
        foreach ($result['findings'] as $finding) {
            if ($finding['code'] === $code && ($titlePart === null || str_contains($finding['title'], $titlePart))) {
                return $finding;
            }
        }

        return null;
    }

    public function testHealthyNetworkHasNoFindings(): void
    {
        $result = RoutingFixture::network()->analyzer()->analyze();

        $this->assertSame([], $result['findings']);
        $this->assertSame('ok', $result['summary']['status']);
        $this->assertSame(['routers' => 4, 'neighbors' => 8, 'full' => 8], $result['summary']['ospf']);
        $this->assertSame(['sessions' => 3, 'established' => 3, 'shut' => 0], $result['summary']['bgp']);
        $this->assertSame(['0.0.0.0', '0.0.0.1'], $result['ospf_routers'][2]['areas'], 'r3 is an ABR between both areas');
        $isp = array_values(array_filter($result['bgp_sessions'], fn ($r) => $r['ip'] === '203.0.113.1'))[0];
        $this->assertSame(800, $isp['accepted']);
        $this->assertSame(2000, $isp['limit']);
    }

    public function testDuplicateRouterIds(): void
    {
        $net = RoutingFixture::network();
        $net->update('instances', ['device_id' => 2], ['ospfRouterId' => '1.1.1.1']);
        // r5 runs OSPF in a domain of its own with r3's router ID
        $net->router(5, 'lab1')->ospfRouter(5, '3.3.3.3');
        $result = $net->analyzer()->analyze();

        $same = self::find($result, 'ospf_duplicate_router_id', '1.1.1.1');
        $this->assertSame('critical', $same['severity']);
        $this->assertSame([1, 2], $same['devices']);
        $this->assertStringContainsString('same OSPF domain', $same['detail']);

        $apart = self::find($result, 'ospf_duplicate_router_id', '3.3.3.3');
        $this->assertSame('warning', $apart['severity']);
        $this->assertSame([3, 5], $apart['devices']);
    }

    public function testNeighborWithTheRoutersOwnId(): void
    {
        $net = RoutingFixture::network();
        $net->address(1, 'Te0/0/9', '10.9.9.1/30');
        $net->neighbor(1, '10.9.9.2', '1.1.1.1', 'exchangeStart');
        $result = $net->analyzer()->analyze();

        $finding = self::find($result, 'ospf_duplicate_router_id');
        $this->assertSame('critical', $finding['severity']);
        $this->assertSame('OSPF neighbor 10.9.9.2 of r1 uses the same router ID 1.1.1.1', $finding['title']);
        $this->assertSame([1], $finding['devices']);
    }

    public function testExStartWithAnMtuMismatch(): void
    {
        $net = RoutingFixture::network();
        $net->router(5, 'r5')->ospfRouter(5, '5.5.5.5');
        $net->ospfLink(3, 'Te0/0/4', 5, 'Gi0/1', '10.0.35.0/30', '0.0.0.0', ['ifMtu' => 9216], ['ifMtu' => 1500], 'exchangeStart', 'exchange');
        $result = $net->analyzer()->analyze();

        $this->assertSame(['ospf_mtu_mismatch'], self::codes($result), 'one finding for both sides of the adjacency');
        $finding = $result['findings'][0];
        $this->assertSame('critical', $finding['severity']);
        $this->assertSame('OSPF adjacency r3 Te0/0/4 ↔ r5 Gi0/1 stuck in ExStart: MTU 9216 vs 1500', $finding['title']);
        $this->assertStringContainsString('r3 sees 10.0.35.2 in ExStart, r5 sees 10.0.35.1 in Exchange', $finding['detail']);
        $this->assertSame([3, 5], $finding['devices']);
        $this->assertCount(2, $finding['ports']);
    }

    public function testExStartWithTheSameInterfaceMtu(): void
    {
        $net = RoutingFixture::network();
        $net->update('neighbors', ['device_id' => 1, 'ospfNbrIpAddr' => '10.0.12.2'], ['ospfNbrState' => 'exchangeStart']);
        $finding = self::find($net->analyzer()->analyze(), 'ospf_neighbor_stuck');

        $this->assertSame('warning', $finding['severity']);
        $this->assertStringContainsString('stuck in ExStart', $finding['title']);
        $this->assertStringContainsString('The interface MTUs match (1500)', $finding['detail']);
    }

    public function testStuckAdjacencies(): void
    {
        $net = RoutingFixture::network();
        $net->update('neighbors', ['device_id' => 2, 'ospfNbrIpAddr' => '10.0.23.2'], ['ospfNbrState' => 'init']);
        // 2-Way is normal between two routers that are neither DR nor BDR on a broadcast segment...
        $net->router(6, 'r6')->router(7, 'r7')->ospfRouter(6, '6.6.6.6')->ospfRouter(7, '7.7.7.7');
        $broadcast = ['ospfIfType' => 'broadcast', 'ospfIfState' => 'otherDesignatedRouter', 'ospfIfDesignatedRouter' => '10.0.67.3'];
        $net->ospfLink(6, 'Vl10', 7, 'Vl10', '10.0.67.0/24', '0.0.0.0', $broadcast, $broadcast, 'twoWay', 'twoWay');
        // ...but not on a point-to-point interface
        $net->update('neighbors', ['device_id' => 3, 'ospfNbrIpAddr' => '10.1.34.2'], ['ospfNbrState' => 'twoWay']);
        $result = $net->analyzer()->analyze();

        $this->assertNotNull(self::find($result, 'ospf_neighbor_stuck', 'r2 Te0/0/2 ↔ r3 Te0/0/2 stuck in Init'));
        $this->assertNotNull(self::find($result, 'ospf_neighbor_stuck', 'r3 Te0/0/3 ↔ r4 Te0/0/1 stays in 2-Way'));
        $this->assertNull(self::find($result, 'ospf_neighbor_stuck', 'r6'));
        $this->assertSame(2, count($result['findings']));
        $this->assertSame(10 - 2, $result['summary']['ospf']['full'], 'the 2-Way neighbors on the LAN count as fine');
    }

    public function testInterfaceSettingsThatDiffer(): void
    {
        $net = RoutingFixture::network();
        $net->update('ports', ['ospfIfIpAddress' => '10.0.12.2'], ['ospfIfHelloInterval' => 5, 'ospfIfRtrDeadInterval' => 20]);
        $net->update('ports', ['ospfIfIpAddress' => '10.0.13.2'], ['ospfIfAreaId' => '0.0.0.5', 'ospfIfAuthType' => 'md5']);
        $net->update('ports', ['ospfIfIpAddress' => '10.0.23.1'], ['ospfIfType' => 'broadcast', 'ospfIfMetricValue' => 100]);
        $result = $net->analyzer()->analyze();

        $timers = self::find($result, 'ospf_timer_mismatch');
        $this->assertSame('critical', $timers['severity']);
        $this->assertSame('OSPF hello / dead intervals differ on 10.0.12.0/30: r1 Te0/0/1: 10/40s, r2 Te0/0/1: 5/20s', $timers['title']);

        $area = self::find($result, 'ospf_area_mismatch');
        $this->assertSame('OSPF area differs on 10.0.13.0/30: r1 Te0/0/2: area 0.0.0.0, r3 Te0/0/1: area 0.0.0.5', $area['title']);
        $this->assertSame('critical', self::find($result, 'ospf_auth_mismatch')['severity']);
        $this->assertStringContainsString('r3 Te0/0/1: MD5', self::find($result, 'ospf_auth_mismatch')['title']);

        $type = self::find($result, 'ospf_network_type_mismatch');
        $this->assertSame('warning', $type['severity']);
        $this->assertSame('OSPF network type differs on 10.0.23.0/30: r2 Te0/0/2: broadcast, r3 Te0/0/2: point-to-point', $type['title']);

        $cost = self::find($result, 'ospf_cost_asymmetric');
        $this->assertSame('info', $cost['severity']);
        $this->assertSame('OSPF cost differs per direction on 10.0.23.0/30: r2 Te0/0/2: 100, r3 Te0/0/2: 10', $cost['title']);
    }

    public function testDrElectionOnAPointToPointLink(): void
    {
        $net = RoutingFixture::network();
        $net->update('ports', ['ospfIfIpAddress' => '10.0.12.1'], ['ospfIfType' => 'broadcast', 'ospfIfState' => 'designatedRouter', 'ospfIfDesignatedRouter' => '10.0.12.1']);
        $net->update('ports', ['ospfIfIpAddress' => '10.0.12.2'], ['ospfIfType' => 'broadcast', 'ospfIfState' => 'backupDesignatedRouter', 'ospfIfDesignatedRouter' => '10.0.12.1']);
        // a LAN with three routers elects a DR on purpose
        $net->router(6, 'r6')->router(7, 'r7')->router(8, 'r8')->ospfRouter(6, '6.6.6.6')->ospfRouter(7, '7.7.7.7')->ospfRouter(8, '8.8.8.8');
        $lan = ['ospfIfType' => 'broadcast', 'ospfIfState' => 'designatedRouter', 'ospfIfDesignatedRouter' => '10.0.67.1'];
        $net->ospfLink(6, 'Vl10', 7, 'Vl10', '10.0.67.0/24', '0.0.0.0', $lan, $lan);
        $net->address(8, 'Vl10', '10.0.67.3/24');
        $result = $net->analyzer()->analyze();

        $this->assertSame(['ospf_dr_on_p2p'], self::codes($result));
        $finding = $result['findings'][0];
        $this->assertSame('info', $finding['severity']);
        $this->assertSame('DR election on the point-to-point link 10.0.12.0/30: r1 Te0/0/1: broadcast, r2 Te0/0/1: broadcast', $finding['title']);
    }

    public function testPassiveInterfacesElectingThemselvesAreNotReported(): void
    {
        $net = RoutingFixture::network();
        // a customer link: OSPF on r1's side only (passive), no neighbor; and r1's loopback
        $port = $net->address(1, 'Gi0/0/5', '198.51.100.1/30');
        $net->ospf['ports'][] = ['device_id' => 1, 'port_id' => $port, 'ospfIfIpAddress' => '198.51.100.1', 'ospfIfAreaId' => '0.0.0.0', 'ospfIfType' => 'broadcast',
            'ospfIfAdminStat' => 'enabled', 'ospfIfState' => 'designatedRouter', 'ospfIfDesignatedRouter' => '198.51.100.1', 'context_name' => ''];
        $net->ospf['ports'][] = ['device_id' => 1, 'port_id' => 101, 'ospfIfIpAddress' => '1.1.1.1', 'ospfIfAreaId' => '0.0.0.0', 'ospfIfType' => 'broadcast',
            'ospfIfAdminStat' => 'enabled', 'ospfIfState' => 'designatedRouter', 'ospfIfDesignatedRouter' => '1.1.1.1', 'context_name' => ''];

        $this->assertSame([], $net->analyzer()->analyze()['findings']);

        // the same link with a neighbor on it elects a DR for nothing
        $net->neighbor(1, '198.51.100.2', '9.9.9.9');
        $this->assertSame(['ospf_dr_on_p2p'], self::codes($net->analyzer()->analyze()));
    }

    public function testAreaDatabasesAndTypes(): void
    {
        $net = RoutingFixture::network();
        $net->update('areas', ['device_id' => 2, 'ospfAreaId' => '0.0.0.0'], ['ospfAreaLsaCount' => 11]);
        $net->update('areas', ['device_id' => 4], ['ospfImportAsExtern' => 'importNoExternal']);
        // a router of another domain with a different area 0 is not compared
        $net->router(9, 'lab1')->ospfRouter(9, '9.9.9.9')->area(9, '0.0.0.0', 3, 100);
        $result = $net->analyzer()->analyze();

        $lsdb = self::find($result, 'ospf_lsdb_mismatch');
        $this->assertSame('warning', $lsdb['severity']);
        $this->assertSame('OSPF area 0.0.0.0: the link-state databases differ (r1: 12 LSAs, r2: 11 LSAs, r3: 12 LSAs)', $lsdb['title']);
        $this->assertSame([1, 2, 3], $lsdb['devices']);

        $type = self::find($result, 'ospf_area_type_mismatch');
        $this->assertSame('critical', $type['severity']);
        $this->assertSame('OSPF area 0.0.0.1 type differs: r3: normal, r4: stub', $type['title']);
        $this->assertCount(2, $result['findings']);
    }

    public function testSameChecksumSumWithDifferentContents(): void
    {
        $net = RoutingFixture::network();
        $net->update('areas', ['device_id' => 3, 'ospfAreaId' => '0.0.0.1'], ['ospfAreaLsaCksumSum' => 90001]);
        $finding = self::find($net->analyzer()->analyze(), 'ospf_lsdb_mismatch');

        $this->assertStringContainsString('the same number of LSAs but different contents', $finding['detail']);
    }

    public function testBgpSessionDownWithItsLastError(): void
    {
        $net = RoutingFixture::network();
        $net->update('peers', ['bgpPeerIdentifier' => '203.0.113.1'], [
            'bgpPeerState' => 'active', 'bgpPeerLastErrorCode' => 4, 'bgpPeerLastErrorSubCode' => 0, 'bgpPeerFsmEstablishedTime' => 7200,
        ]);
        $finding = self::find($net->analyzer()->analyze(), 'bgp_session_down');

        $this->assertSame('warning', $finding['severity']);
        $this->assertSame('BGP session r1 → 203.0.113.1 (AS 64500, ISP-A) is Active: Hold Timer Expired', $finding['title']);
        $this->assertStringContainsString('Last error: Hold Timer Expired (4/0): no keepalive arrived', $finding['detail']);
        $this->assertStringContainsString('Last established 2h ago', $finding['detail']);
        $this->assertSame([1], $finding['devices']);
    }

    public function testBgpSessionThatNeverOpened(): void
    {
        $net = RoutingFixture::network();
        $net->update('peers', ['bgpPeerIdentifier' => '203.0.113.1'], ['bgpPeerState' => 'connect', 'bgpPeerFsmEstablishedTime' => 0]);
        $finding = self::find($net->analyzer()->analyze(), 'bgp_session_down');

        $this->assertStringContainsString('the TCP connection to 203.0.113.1 never came up', $finding['detail']);
        $this->assertStringContainsString('TCP port 179', $finding['recommendation']);
        $this->assertStringContainsString('reachable from 203.0.113.2', $finding['recommendation']);
    }

    public function testBgpSessionShutDownByThePeerIsInfo(): void
    {
        $net = RoutingFixture::network();
        $net->update('peers', ['bgpPeerIdentifier' => '203.0.113.1'], ['bgpPeerState' => 'idle', 'bgpPeerLastErrorCode' => 6, 'bgpPeerLastErrorSubCode' => 2]);
        $finding = self::find($net->analyzer()->analyze(), 'bgp_session_down');

        $this->assertSame('info', $finding['severity']);
        $this->assertSame('BGP session r1 → 203.0.113.1 (AS 64500, ISP-A) is Idle: Cease / Administrative Shutdown', $finding['title']);
    }

    public function testShutDownSessionsAreNotReported(): void
    {
        $net = RoutingFixture::network();
        $net->update('peers', ['bgpPeerIdentifier' => '203.0.113.1'], ['bgpPeerState' => 'idle', 'bgpPeerAdminStatus' => 'stop']);
        $result = $net->analyzer()->analyze();

        $this->assertSame([], $result['findings']);
        $this->assertSame('shut down', $result['bgp_sessions'][0]['state']);
        $this->assertSame(1, $result['summary']['bgp']['shut']);
    }

    public function testRemoteAsMismatch(): void
    {
        $net = RoutingFixture::network();
        $net->router(4, 'r4', 65004);
        $net->bgpPeer(3, '10.1.34.2', 65000, ['bgpPeerState' => 'idle', 'bgpPeerLastErrorCode' => 2, 'bgpPeerLastErrorSubCode' => 2])
            ->bgpPeer(4, '10.1.34.1', 65000, ['bgpPeerState' => 'active']);
        $result = $net->analyzer()->analyze();

        $finding = self::find($result, 'bgp_remote_as_mismatch');
        $this->assertSame('critical', $finding['severity']);
        $this->assertSame('BGP session r3 → r4 (10.1.34.2, AS 65000): remote AS 65000 configured, r4 is AS 65004', $finding['title']);
        $this->assertSame('The session cannot come up: r4 opens it with AS 65004, which r3 rejects (OPEN Message Error / Bad Peer AS).', $finding['detail']);
        $this->assertSame([3, 4], $finding['devices']);
        $this->assertNull(self::find($result, 'bgp_session_down', 'r3 → r4'), 'the cause replaces the generic finding');
        $this->assertNotNull(self::find($result, 'bgp_session_down', 'r4 → r3'), 'r4 has the right AS for r3');
    }

    public function testSessionConfiguredOnOneSideOnly(): void
    {
        $net = RoutingFixture::network();
        $net->bgpPeer(3, '10.0.23.1', 65000, ['bgpPeerState' => 'active']);
        $result = $net->analyzer()->analyze();

        $finding = self::find($result, 'bgp_one_sided');
        $this->assertSame('warning', $finding['severity']);
        $this->assertSame('BGP session r3 → r2 (10.0.23.1, AS 65000) is configured on r3 only', $finding['title']);
        $this->assertStringContainsString('Add the neighbor 10.0.23.2 on r2', $finding['recommendation']);
        $this->assertNull(self::find($result, 'bgp_session_down'));

        // a peer that reports no BGP at all may simply not be polled
        $net = RoutingFixture::network();
        $net->bgpPeer(3, '10.1.34.2', 65000, ['bgpPeerState' => 'active']);
        $result = $net->analyzer()->analyze();
        $this->assertNull(self::find($result, 'bgp_one_sided'));
        $this->assertStringContainsString('r4 reports no BGP sessions', self::find($result, 'bgp_session_down')['detail']);
    }

    public function testRecentResets(): void
    {
        $net = RoutingFixture::network();
        $net->update('peers', ['bgpPeerIdentifier' => '203.0.113.1'], ['bgpPeerFsmEstablishedTime' => 1800, 'bgpPeerLastErrorCode' => 4]);
        $net->update('peers', ['bgpPeerIdentifier' => '1.1.1.1'], ['bgpPeerFsmEstablishedTime' => 600, 'bgpPeerLastErrorCode' => 6, 'bgpPeerLastErrorSubCode' => 4]);
        $result = $net->analyzer()->analyze();

        $isp = self::find($result, 'bgp_recent_reset', '203.0.113.1');
        $this->assertSame('warning', $isp['severity'], 'a hold timer expiry is a fault');
        $this->assertSame('BGP session r1 → 203.0.113.1 (AS 64500, ISP-A) was reset 30m ago', $isp['title']);
        $this->assertStringContainsString('Hold Timer Expired', $isp['detail']);

        $ibgp = self::find($result, 'bgp_recent_reset', 'r2 → r1');
        $this->assertSame('info', $ibgp['severity'], 'an administrative reset is not');
        $this->assertSame('Nothing to do if the reset was planned.', $ibgp['recommendation']);

        // the router itself restarted
        $net->router(1, 'r1', 65000, ['uptime' => 1900]);
        $restart = self::find($net->analyzer()->analyze(), 'bgp_recent_reset', '203.0.113.1');
        $this->assertSame('info', $restart['severity']);
        $this->assertStringContainsString('r1 itself restarted 32m ago', $restart['detail']);

        $this->assertNull(self::find($net->analyzer(['routing_bgp_reset_hours' => '0'])->analyze(), 'bgp_recent_reset'));
    }

    public function testPrefixLimits(): void
    {
        $net = RoutingFixture::network();
        $net->update('prefixes', ['bgpPeerIdentifier' => '203.0.113.1'], ['AcceptedPrefixes' => 1700]);
        $finding = self::find($net->analyzer()->analyze(), 'bgp_prefix_limit');
        $this->assertSame('warning', $finding['severity']);
        $this->assertSame('BGP session r1 → 203.0.113.1 (AS 64500, ISP-A) is at 85% of its prefix limit (IPv4 unicast: 1,700 of 2,000)', $finding['title']);
        $this->assertStringContainsString('own warning threshold is 75%', $finding['detail']);

        $this->assertNull(self::find($net->analyzer(['routing_prefix_warn_percent' => '90'])->analyze(), 'bgp_prefix_limit'));
        $this->assertNull(self::find($net->analyzer(['routing_prefix_warn_percent' => '0'])->analyze(), 'bgp_prefix_limit'), '0: only at the limit');

        $net->update('prefixes', ['bgpPeerIdentifier' => '203.0.113.1'], ['AcceptedPrefixes' => 2000]);
        $this->assertSame('critical', self::find($net->analyzer()->analyze(), 'bgp_prefix_limit')['severity']);
        $this->assertSame('critical', self::find($net->analyzer(['routing_prefix_warn_percent' => '0'])->analyze(), 'bgp_prefix_limit')['severity']);
    }

    public function testEstablishedSessionWithoutPrefixes(): void
    {
        $net = RoutingFixture::network();
        $net->update('prefixes', ['bgpPeerIdentifier' => '203.0.113.1'], ['AcceptedPrefixes' => 0, 'DeniedPrefixes' => 52]);
        $finding = self::find($net->analyzer()->analyze(), 'bgp_no_prefixes');

        $this->assertSame('info', $finding['severity']);
        $this->assertSame('BGP session r1 → 203.0.113.1 (AS 64500, ISP-A) is up but accepts no prefixes', $finding['title']);
        $this->assertStringContainsString('All 52 prefixes it received were denied', $finding['detail']);
    }

    public function testDuplicateAddresses(): void
    {
        $net = RoutingFixture::network();
        $net->address(3, 'Vl50', '10.50.0.9/24');
        $net->address(4, 'Vl50', '10.50.0.9/24');
        // a gateway shared by r1 and r2 (each has its own address in the subnet too)
        $net->address(1, 'Vl60', '10.60.0.2/24');
        $net->address(2, 'Vl60', '10.60.0.3/24');
        $net->address(1, 'Vl60', '10.60.0.1/24');
        $net->address(2, 'Vl60', '10.60.0.1/24');
        // anycast loopback on three routers, and the same address in two VRFs
        foreach ([1, 2, 3] as $id) {
            $net->address($id, 'Loopback9', '192.0.2.53/32', '', ['ifType' => 'softwareLoopback']);
        }
        $net->address(3, 'Vl70', '10.70.0.1/24', 'RED');
        $net->address(4, 'Vl70', '10.70.0.1/24', 'BLUE');
        $result = $net->analyzer()->analyze();

        $plain = self::find($result, 'ip_duplicate', '10.50.0.9');
        $this->assertSame('warning', $plain['severity']);
        $this->assertSame('duplicate IP address 10.50.0.9 on r3 Vl50, r4 Vl50', $plain['title']);
        $this->assertSame('info', self::find($result, 'ip_duplicate', '10.60.0.1')['severity']);
        $this->assertStringContainsString('shared gateway', self::find($result, 'ip_duplicate', '10.60.0.1')['detail']);
        $this->assertSame('info', self::find($result, 'ip_duplicate', '192.0.2.53')['severity']);
        $this->assertNull(self::find($result, 'ip_duplicate', '10.70.0.1'));

        $ignored = $net->analyzer(['routing_ignore_addresses' => '10.50.0.0/24 192.0.2.53'])->analyze();
        $this->assertNull(self::find($ignored, 'ip_duplicate', '10.50.0.9'));
        $this->assertNull(self::find($ignored, 'ip_duplicate', '192.0.2.53'));
    }

    public function testIpv6DuplicatesAndLinkLocalAddresses(): void
    {
        $net = RoutingFixture::network();
        $net->address(1, 'Te0/0/1', '2001:db8:12::1/64');
        $net->address(2, 'Te0/0/1', '2001:DB8:12:0::1/64');
        $net->address(1, 'Te0/0/2', 'fe80::1/64');
        $net->address(3, 'Te0/0/1', 'fe80::1/64');
        $result = $net->analyzer()->analyze();

        $this->assertSame(['ip_duplicate'], self::codes($result));
        $this->assertSame('duplicate IP address 2001:db8:12::1 on r1 Te0/0/1, r2 Te0/0/1', $result['findings'][0]['title']);
    }

    public function testMaskMismatch(): void
    {
        $net = RoutingFixture::network();
        $net->address(1, 'Te0/0/7', '10.80.0.1/30');
        $net->address(2, 'Te0/0/7', '10.80.0.2/24');
        // overlapping but not on one link: r4's address is outside r3's /30
        $net->address(3, 'Te0/0/8', '10.90.0.1/30');
        $net->address(4, 'Vl90', '10.90.0.100/24');
        $result = $net->analyzer()->analyze();

        $this->assertSame(['ip_mask_mismatch'], self::codes($result));
        $finding = $result['findings'][0];
        $this->assertSame('Subnet mask mismatch: r1 Te0/0/7 10.80.0.1/30 and r2 Te0/0/7 10.80.0.2/24', $finding['title']);
        $this->assertStringContainsString('Both addresses lie in 10.80.0.0/30, but r2 uses a /24', $finding['detail']);
        $this->assertSame([1, 2], $finding['devices']);
    }

    public function testSubnetInTwoVrfsOnNeighbors(): void
    {
        $net = RoutingFixture::network();
        $net->address(1, 'Te0/0/5', '10.30.0.1/30', 'PROD');
        $net->address(2, 'Te0/0/5', '10.30.0.2/30', 'DEV');
        // r1 and r9 are no LLDP neighbors
        $net->router(9, 'r9')->address(9, 'Te0/0/5', '10.30.0.1/30', 'LAB');
        // global table on a router without VRFs facing a VRF: a customer router on a provider edge
        $net->address(3, 'Te0/0/6', '10.31.0.1/30', 'CUST');
        $net->address(4, 'Te0/0/6', '10.31.0.2/30');
        $result = $net->analyzer()->analyze();

        $finding = self::find($result, 'ip_vrf_mismatch');
        $this->assertSame('10.30.0.0/30 is in VRF DEV on r2 Te0/0/5 and in VRF PROD on r1 Te0/0/5', $finding['title']);
        $this->assertSame(['DEV', 'PROD'], $finding['vrfs']);
        $this->assertNull(self::find($result, 'ip_vrf_mismatch', '10.31.0.0/30'));
        $this->assertCount(1, $result['findings']);

        // once r4 uses VRFs itself, its global address facing a VRF is a mistake
        $net->address(4, 'Te0/0/7', '10.32.0.1/30', 'MGMT');
        $this->assertNotNull(self::find($net->analyzer()->analyze(), 'ip_vrf_mismatch', '10.31.0.0/30 is in the global table on r4'));
    }

    public function testOspfNeighborsCountAsDirectlyConnected(): void
    {
        $net = RoutingFixture::network();
        $net->lldp = [];
        $net->address(3, 'Te0/0/5', '10.30.0.1/30', 'PROD');
        $net->address(4, 'Te0/0/5', '10.30.0.2/30', 'DEV');
        $finding = self::find($net->analyzer()->analyze(), 'ip_vrf_mismatch');

        $this->assertEqualsCanonicalizing([3, 4], $finding['devices'], 'r3 and r4 are OSPF neighbors on their area 1 link');
        $this->assertStringContainsString('are neighbors (LLDP/CDP or OSPF)', $finding['detail']);
    }

    public function testScopeDeviceAndVrfFilters(): void
    {
        $net = RoutingFixture::network();
        $net->update('neighbors', ['device_id' => 3, 'ospfNbrIpAddr' => '10.1.34.2'], ['ospfNbrState' => 'init']);
        $net->update('peers', ['bgpPeerIdentifier' => '203.0.113.1'], ['bgpPeerState' => 'active']);
        $net->address(1, 'Te0/0/5', '10.30.0.1/30', 'PROD');
        $net->address(2, 'Te0/0/5', '10.30.0.2/30', 'DEV');

        $all = $net->analyzer()->analyze();
        $this->assertSame(['bgp_session_down', 'ip_vrf_mismatch', 'ospf_neighbor_stuck'], self::codes($all));

        $r4 = $net->analyzer([], [4])->analyze();
        $this->assertSame(['ospf_neighbor_stuck'], self::codes($r4), 'findings that involve a device of the scope');
        $this->assertSame([4], array_values(array_unique(array_column($r4['ospf_neighbors'], 'device_id'))), 'tables: rows of the scope');
        $this->assertSame([], $r4['bgp_sessions']);

        $this->assertSame(['bgp_session_down', 'ip_vrf_mismatch'], self::codes($net->analyzer()->analyze(1)));
        $this->assertSame(['ip_vrf_mismatch'], self::codes($net->analyzer()->analyze(null, 'PROD')));
        $this->assertSame(['DEV', 'PROD'], $all['vrfs']);
    }

    public function testDisabledChecks(): void
    {
        $net = RoutingFixture::network();
        $net->update('neighbors', ['device_id' => 3, 'ospfNbrIpAddr' => '10.1.34.2'], ['ospfNbrState' => 'init']);
        $net->update('peers', ['bgpPeerIdentifier' => '203.0.113.1'], ['bgpPeerState' => 'active']);

        $this->assertSame(['ospf_neighbor_stuck'], self::codes($net->analyzer(['routing_disabled_checks' => 'bgp'])->analyze()));
        $this->assertSame(['bgp_session_down'], self::codes($net->analyzer(['routing_disabled_checks' => 'ospf_neighbor_stuck'])->analyze()));
    }

    public function testDownRoutersAreNotAnalyzedButNamed(): void
    {
        $net = RoutingFixture::network();
        $net->router(4, 'r4', 65000, ['status' => 0]);
        $net->update('neighbors', ['device_id' => 3, 'ospfNbrIpAddr' => '10.1.34.2'], ['ospfNbrState' => 'init']);
        $net->update('neighbors', ['device_id' => 4], ['ospfNbrState' => 'exchangeStart']);
        $result = $net->analyzer()->analyze();

        $finding = self::find($result, 'ospf_neighbor_stuck');
        $this->assertStringContainsString('r4 itself is down', $finding['detail']);
        $this->assertStringNotContainsString('r4 sees', $finding['detail'], "r4's own rows are from before it went down");
        $this->assertSame(3, $result['summary']['ospf']['routers']);
    }

    public function testNumericEnumerationsOfOlderVersions(): void
    {
        $net = RoutingFixture::network();
        $net->update('neighbors', ['device_id' => 1, 'ospfNbrIpAddr' => '10.0.12.2'], ['ospfNbrState' => '5']);
        $net->update('ports', ['ospfIfIpAddress' => '10.0.12.1'], ['ospfIfType' => '3', 'ospfIfAreaId' => '0']);
        $net->update('peers', ['bgpPeerIdentifier' => '203.0.113.1'], ['bgpPeerState' => '3', 'bgpPeerAdminStatus' => '2']);
        $result = $net->analyzer()->analyze();

        $this->assertSame(['bgp_session_down', 'ospf_neighbor_stuck'], self::codes($result));
        $this->assertStringContainsString('stuck in ExStart', self::find($result, 'ospf_neighbor_stuck')['title']);
        $this->assertStringContainsString('is Active', self::find($result, 'bgp_session_down')['title']);
    }

    public function testTables(): void
    {
        $net = RoutingFixture::network();
        $net->update('neighbors', ['device_id' => 3, 'ospfNbrIpAddr' => '10.1.34.2'], ['ospfNbrState' => 'init']);
        $net->update('peers', ['bgpPeerIdentifier' => '203.0.113.1'], ['bgpPeerState' => 'idle', 'bgpPeerLastErrorCode' => 6, 'bgpPeerLastErrorSubCode' => 1]);
        $result = $net->analyzer()->analyze();

        $first = $result['ospf_neighbors'][0];
        $this->assertSame(['device_id' => 3, 'port' => 'Te0/0/3', 'area' => '0.0.0.1', 'ip' => '10.1.34.2', 'router_id' => '4.4.4.4', 'remote_device' => 4, 'state' => 'Init', 'ok' => false],
            array_intersect_key($first, array_flip(['device_id', 'port', 'area', 'ip', 'router_id', 'remote_device', 'state', 'ok'])));

        $session = $result['bgp_sessions'][0];
        $this->assertSame('203.0.113.1', $session['ip']);
        $this->assertSame('Idle', $session['state']);
        $this->assertSame('Cease / Maximum Number of Prefixes Reached (6/1)', $session['error']);
        $this->assertSame('eBGP', $session['type']);
        $this->assertSame('iBGP', $result['bgp_sessions'][1]['type']);

        // without the router's own AS the session type is unknown
        $net->router(1, 'r1', null);
        $this->assertNull($net->analyzer()->analyze()['bgp_sessions'][0]['type']);
        $this->assertSame(['r1', 'r2', 'r3', 'r4'], array_map(fn ($r) => $result['device_names'][$r['device_id']], $result['ospf_routers']));
    }

    public function testBgpErrorsAsText(): void
    {
        $this->assertNull(BgpErrors::describe(0, 0));
        $this->assertNull(BgpErrors::describe(null, null));
        $this->assertSame('Hold Timer Expired (4/0)', BgpErrors::describe(4, 0)['text']);
        $this->assertSame('Cease / Administrative Reset (6/4)', BgpErrors::describe(6, 4)['text']);
        $this->assertSame('OPEN Message Error / Bad Peer AS (2/2)', BgpErrors::describe(2, 2)['text']);
        $this->assertStringContainsString('remote AS', BgpErrors::describe(2, 2)['cause']);
        $this->assertStringContainsString('malformed', BgpErrors::describe(3, 11)['cause'], 'the code\'s cause covers its subcodes');
        $this->assertSame('Unknown error 9 / subcode 1 (9/1)', BgpErrors::describe(9, 1)['text']);
        $this->assertSame('Cease / subcode 42 (6/42)', BgpErrors::describe(6, 42)['text']);
        $this->assertTrue(BgpErrors::administrative(6, 2));
        $this->assertFalse(BgpErrors::administrative(6, 1));
        $this->assertFalse(BgpErrors::administrative(4, 0));
    }
}
