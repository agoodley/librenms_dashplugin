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
        $this->assertSame(['sessions' => 3, 'established' => 3, 'shut' => 0, 'unknown' => 0], $result['summary']['bgp']);
        $this->assertSame(['0.0.0.0', '0.0.0.1'], $result['ospf_routers'][2]['areas'], 'r3 is an ABR between both areas');
        $isp = array_values(array_filter($result['bgp_sessions'], fn ($r) => $r['ip'] === '203.0.113.1'))[0];
        $this->assertSame([['family' => 'IPv4 unicast', 'accepted' => 800, 'limit' => 2000]], $isp['prefixes']);
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
        $net->update('areas', ['device_id' => 2, 'ospfAreaId' => '0.0.0.0'], ['ospfAreaLsaCount' => 9]);
        $net->update('areas', ['device_id' => 4], ['ospfImportAsExtern' => 'importNoExternal']);
        // a router of another domain with a different area 0 is not compared
        $net->router(9, 'lab1')->ospfRouter(9, '9.9.9.9')->area(9, '0.0.0.0', 3, 100);
        $result = $net->analyzer()->analyze();

        $lsdb = self::find($result, 'ospf_lsdb_mismatch');
        $this->assertSame('warning', $lsdb['severity']);
        $this->assertSame('OSPF area 0.0.0.0: the link-state databases differ (r1: 12 LSAs, r2: 9 LSAs, r3: 12 LSAs)', $lsdb['title']);
        $this->assertStringContainsString('if it persists', $lsdb['detail']);
        $this->assertSame([1, 2, 3], $lsdb['devices']);

        $type = self::find($result, 'ospf_area_type_mismatch');
        $this->assertSame('critical', $type['severity']);
        $this->assertSame('OSPF area 0.0.0.1 type differs: r3: normal, r4: stub', $type['title']);
        $this->assertCount(2, $result['findings']);
    }

    public function testChecksumSumsAndSmallLsaCountDifferencesAreNotReported(): void
    {
        // the checksum sum changes with every LSA refresh, and routers are polled at different moments
        $net = RoutingFixture::network();
        $net->update('areas', ['device_id' => 3, 'ospfAreaId' => '0.0.0.1'], ['ospfAreaLsaCksumSum' => 90001]);
        $this->assertNull(self::find($net->analyzer()->analyze(), 'ospf_lsdb_mismatch'));

        // one LSA, or 1 % of a large area, is a change in progress
        $net->update('areas', ['device_id' => 2, 'ospfAreaId' => '0.0.0.0'], ['ospfAreaLsaCount' => 11]);
        $this->assertNull(self::find($net->analyzer()->analyze(), 'ospf_lsdb_mismatch'));
        $net->update('areas', ['ospfAreaId' => '0.0.0.0'], ['ospfAreaLsaCount' => 5000]);
        $net->update('areas', ['device_id' => 2, 'ospfAreaId' => '0.0.0.0'], ['ospfAreaLsaCount' => 4950]);
        $this->assertNull(self::find($net->analyzer()->analyze(), 'ospf_lsdb_mismatch'));
        $net->update('areas', ['device_id' => 2, 'ospfAreaId' => '0.0.0.0'], ['ospfAreaLsaCount' => 4900]);
        $this->assertNotNull(self::find($net->analyzer()->analyze(), 'ospf_lsdb_mismatch'));
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
        $net->bgpPeer(3, '10.0.23.1', 65000, ['bgpPeerState' => 'active', 'bgpPeerFsmEstablishedTime' => 0]);
        $result = $net->analyzer()->analyze();

        $finding = self::find($result, 'bgp_one_sided');
        $this->assertSame('warning', $finding['severity']);
        $this->assertSame('BGP session r3 → r2 (10.0.23.1, AS 65000) is configured on r3 only', $finding['title']);
        $this->assertStringContainsString('Add the neighbor 10.0.23.2 on r2', $finding['recommendation']);
        $this->assertNull(self::find($result, 'bgp_session_down'));

        // a session that was established before was configured on both sides then
        $net->update('peers', ['device_id' => 3, 'bgpPeerIdentifier' => '10.0.23.1'], ['bgpPeerFsmEstablishedTime' => 3600]);
        $result = $net->analyzer()->analyze();
        $this->assertNull(self::find($result, 'bgp_one_sided'));
        $this->assertNotNull(self::find($result, 'bgp_session_down', 'r3 → r2'));

        // a peer that reports no BGP at all may simply not be polled
        $net = RoutingFixture::network();
        $net->bgpPeer(3, '10.1.34.2', 65000, ['bgpPeerState' => 'active', 'bgpPeerFsmEstablishedTime' => 0]);
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

    public function testLinkJoiningTwoVrfs(): void
    {
        // the two ports of an LLDP link, on routers that both have PROD and DEV
        $net = RoutingFixture::network();
        $net->vrfs = [1 => ['PROD' => null, 'DEV' => null], 2 => ['PROD' => null, 'DEV' => null]];
        $net->link(1, $net->address(1, 'Te0/0/5', '10.30.0.1/30', 'PROD'), 2, $net->address(2, 'Te0/0/5', '10.30.0.2/30', 'DEV'));
        $result = $net->analyzer()->analyze();

        $this->assertSame(['ip_vrf_mismatch'], self::codes($result));
        $finding = $result['findings'][0];
        $this->assertSame('info', $finding['severity']);
        $this->assertSame('VRF PROD on r1 Te0/0/5 is joined to VRF DEV on r2 Te0/0/5 (10.30.0.0/30)', $finding['title']);
        $this->assertStringContainsString('face each other (an LLDP/CDP link)', $finding['detail']);
        $this->assertSame(['PROD', 'DEV'], $finding['vrfs']);

        // the two addresses of an OSPF adjacency: r3's VRF RED instance is a neighbor of r4's VRF BLUE instance
        $net = RoutingFixture::network();
        $net->vrfs = [3 => ['RED' => null, 'BLUE' => null], 4 => ['RED' => null, 'BLUE' => null]];
        $net->ospfRouter(3, '3.3.3.30', 'RED')->ospfRouter(4, '4.4.4.40', 'BLUE');
        $net->address(3, 'Te0/0/9', '10.33.0.1/30', 'RED');
        $net->address(4, 'Te0/0/9', '10.33.0.2/30', 'BLUE');
        $net->neighbor(3, '10.33.0.2', '4.4.4.40', 'full', 'RED')->neighbor(4, '10.33.0.1', '3.3.3.30', 'full', 'BLUE');
        $finding = self::find($net->analyzer()->analyze(), 'ip_vrf_mismatch');
        $this->assertSame('VRF RED on r3 Te0/0/9 is joined to VRF BLUE on r4 Te0/0/9 (10.33.0.0/30)', $finding['title']);
        $this->assertStringContainsString('(an OSPF adjacency)', $finding['detail']);
    }

    public function testSubnetReusedInTwoCustomerVrfsOnCoreNeighbors(): void
    {
        // r1 and r2 are core neighbors; each reuses the same PE-CE /30 on a customer port that faces neither router
        $net = RoutingFixture::network();
        $net->address(1, 'Gi0/0/10', '192.168.255.1/30', 'CUST-A');
        $net->address(2, 'Gi0/0/10', '192.168.255.1/30', 'CUST-B');

        $this->assertSame([], $net->analyzer()->analyze()['findings']);
    }

    public function testSameVrfNamedInAnotherCase(): void
    {
        // PROD on a Cisco PE, prod on a Junos PE, on the two ends of one link
        $net = RoutingFixture::network();
        $net->link(1, $net->address(1, 'Te0/0/5', '10.30.0.1/30', 'PROD'), 2, $net->address(2, 'xe-0/0/5.0', '10.30.0.2/30', 'prod'));

        $this->assertSame([], $net->analyzer()->analyze()['findings']);
    }

    public function testCustomerRouterWithAManagementVrf(): void
    {
        // a managed CE (global table, and a Mgmt-intf VRF as every IOS-XE box) facing the PE's customer VRF
        $net = RoutingFixture::network();
        $net->link(3, $net->address(3, 'Gi0/0/7', '10.31.0.1/30', 'CUST'), 4, $net->address(4, 'Gi0/0/0', '10.31.0.2/30'));
        $net->address(4, 'Gi0', '192.0.2.44/24', 'Mgmt-intf');
        $this->assertSame([], $net->analyzer()->analyze()['findings'], 'r4 has no VRF CUST: the PE joins it to the CE on purpose');

        // a router that has VRF CUST itself and faces it with its global table
        $net->vrfs[4]['CUST'] = null;
        $finding = self::find($net->analyzer()->analyze(), 'ip_vrf_mismatch');
        $this->assertSame('VRF CUST on r3 Gi0/0/7 is joined to the global table on r4 Gi0/0/0 (10.31.0.0/30)', $finding['title']);
    }

    public function testMaskMismatchBetweenOspfNeighborsWithoutLldp(): void
    {
        $net = RoutingFixture::network();
        $net->links = [];
        $net->address(3, 'Te0/0/5', '10.30.0.1/30');
        $net->address(4, 'Te0/0/5', '10.30.0.2/24');
        $finding = self::find($net->analyzer()->analyze(), 'ip_mask_mismatch');

        $this->assertSame('Subnet mask mismatch: r3 Te0/0/5 10.30.0.1/30 and r4 Te0/0/5 10.30.0.2/24', $finding['title'], 'r3 and r4 are OSPF neighbors on their area 1 link');
    }

    public function testMaskCheckOnlyBetweenNeighbors(): void
    {
        // two unrelated customer sites (an MSP): a LAN 192.168.1.1/24 and a router-firewall /30 in the same range
        $net = RoutingFixture::network();
        $net->router(7, 'custX-rtr', null)->address(7, 'Vl1', '192.168.1.1/24');
        $net->router(8, 'custY-rtr', null)->address(8, 'Gi0/1', '192.168.1.2/30');
        $this->assertSame([], $net->analyzer()->analyze()['findings']);

        // the same addresses on two neighbors in different VRFs that do not face each other
        $net = RoutingFixture::network();
        $net->address(1, 'Vl1', '192.168.1.1/24', 'CUST-A');
        $net->address(2, 'Gi0/1', '192.168.1.2/30', 'CUST-B');
        $this->assertSame([], $net->analyzer()->analyze()['findings']);
    }

    public function testScopeDeviceAndVrfFilters(): void
    {
        $net = RoutingFixture::network();
        $net->update('neighbors', ['device_id' => 3, 'ospfNbrIpAddr' => '10.1.34.2'], ['ospfNbrState' => 'init']);
        $net->update('peers', ['bgpPeerIdentifier' => '203.0.113.1'], ['bgpPeerState' => 'active']);
        $net->vrfs = [1 => ['PROD' => null, 'DEV' => null], 2 => ['PROD' => null, 'DEV' => null]];
        $net->link(1, $net->address(1, 'Te0/0/5', '10.30.0.1/30', 'PROD'), 2, $net->address(2, 'Te0/0/5', '10.30.0.2/30', 'DEV'));

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

    public function testUnconnectedNetworksReusingASubnetAreNotCompared(): void
    {
        // two customers' OSPF networks that never meet use the same transit /30 with other settings
        $net = RoutingFixture::network();
        $net->router(11, 'custA-r1')->router(12, 'custA-r2')->router(13, 'custB-r1')->router(14, 'custB-r2');
        foreach ([11, 12, 13, 14] as $id) {
            $net->ospfRouter($id, "172.31.0.$id");
        }
        $net->ospfLink(11, 'Gi0/1', 12, 'Gi0/1', '10.99.0.0/30');
        $fast = ['ospfIfHelloInterval' => 5, 'ospfIfRtrDeadInterval' => 20];
        $net->ospfLink(13, 'Gi0/1', 14, 'Gi0/1', '10.99.0.0/30', '0.0.0.7', $fast, $fast);

        $result = $net->analyzer()->analyze();
        $this->assertSame(['ip_duplicate', 'ip_duplicate'], self::codes($result), 'no interface mismatch between the two networks');
        foreach ($result['findings'] as $finding) {
            $this->assertSame('info', $finding['severity']);
            $this->assertStringContainsString('may be separate networks', $finding['detail']);
        }
    }

    public function testInterfacesOfOneLinkWithoutAnAdjacencyAreCompared(): void
    {
        // r5's only link (to r4) has other timers, so no adjacency forms: r5 is an OSPF domain of its own,
        // but LLDP shows that the two interfaces face each other
        $net = RoutingFixture::network();
        $net->router(5, 'r5')->ospfRouter(5, '5.5.5.5');
        $net->ospfLink(4, 'Te0/0/2', 5, 'Te0/0/1', '10.1.45.0/30', '0.0.0.1', [], ['ospfIfHelloInterval' => 5, 'ospfIfRtrDeadInterval' => 20]);
        $net->ospf['neighbors'] = array_values(array_filter($net->ospf['neighbors'], fn ($n) => ! in_array($n['ospfNbrIpAddr'], ['10.1.45.1', '10.1.45.2'], true)));

        $finding = self::find($net->analyzer()->analyze(), 'ospf_timer_mismatch');
        $this->assertSame('OSPF hello / dead intervals differ on 10.1.45.0/30: r4 Te0/0/2: 10/40s, r5 Te0/0/1: 5/20s', $finding['title']);

        // without the LLDP link: the /30 is only on r4 and r5, so they are its two ends
        array_pop($net->links);
        $this->assertNotNull(self::find($net->analyzer()->analyze(), 'ospf_timer_mismatch'));

        // a third router with an address in it: nothing shows which two are on one link
        $net->router(6, 'lab1')->address(6, 'Gi0/0', '10.1.45.1/30');
        $this->assertNull(self::find($net->analyzer()->analyze(), 'ospf_timer_mismatch'));
    }

    public function testPeerAddressOnTwoCustomerRoutersIsNotGuessed(): void
    {
        // PE r1 reuses the PE-CE /30 for two customers; CE-B's session is down (its circuit is down)
        $net = RoutingFixture::network();
        $net->router(7, 'ce-a', 65101)->address(7, 'Gi0/0', '10.255.254.2/30');
        $net->router(8, 'ce-b', 65102)->address(8, 'Gi0/0', '10.255.254.2/30');
        $net->address(1, 'Gi0/1.100', '10.255.254.1/30', 'CUST-A');
        $net->address(1, 'Gi0/1.200', '10.255.254.1/30', 'CUST-B');
        $net->bgpPeer(1, '10.255.254.2', 65101, ['vrf' => 'CUST-A'])
            ->bgpPeer(1, '10.255.254.2', 65102, ['vrf' => 'CUST-B', 'bgpPeerState' => 'active', 'bgpPeerFsmEstablishedTime' => 600]);
        $net->bgpPeer(7, '10.255.254.1', 65000)->bgpPeer(8, '10.255.254.1', 65000, ['bgpPeerState' => 'active']);
        $result = $net->analyzer()->analyze();

        $this->assertSame(['bgp_session_down', 'bgp_session_down', 'ip_duplicate'], self::codes($result));
        $this->assertSame('info', self::find($result, 'ip_duplicate', '10.255.254.2')['severity'], 'ce-a and ce-b are not linked');
        $this->assertNotNull(self::find($result, 'bgp_session_down', 'r1 → 10.255.254.2 (AS 65102, VRF CUST-B) is Active'));
        $this->assertNotNull(self::find($result, 'bgp_session_down', 'ce-b → r1 (10.255.254.1, AS 65000) is Active'), 'r1 is the only device with the address');
        foreach ($result['bgp_sessions'] as $session) {
            if ($session['device_id'] === 1 && $session['ip'] === '10.255.254.2') {
                $this->assertNull($session['remote_device'], 'ce-a and ce-b both have the address');
            }
        }
    }

    public function testAnycastAddressOnTwoLoopbacksIsInfo(): void
    {
        // an anycast RP: the same /32 on Loopback1 of two routers
        $net = RoutingFixture::network();
        $net->address(1, 'Loopback1', '10.255.255.1/32', '', ['ifType' => 'softwareLoopback']);
        $net->address(2, 'Loopback1', '10.255.255.1/32', '', ['ifType' => 'softwareLoopback']);
        $finding = self::find($net->analyzer()->analyze(), 'ip_duplicate');

        $this->assertSame('info', $finding['severity']);
        $this->assertStringContainsString('anycast', $finding['detail']);
    }

    public function testReplacedRouterThatIsDownIsNoDuplicate(): void
    {
        // r4 was replaced: the old device is down and still has its addresses, the new device uses them
        $net = RoutingFixture::network();
        $net->router(4, 'r4-old', 65000, ['status' => 0]);
        $net->router(5, 'r4-new');
        $net->link(3, null, 5, null);
        $net->address(5, 'Te0/0/1', '10.1.34.2/30');

        $this->assertNull(self::find($net->analyzer()->analyze(), 'ip_duplicate'));
    }

    public function testDuplicateAddressesOnPortsThatAreDownAreIgnored(): void
    {
        // two servers (connected to one switch) with the same address on a port that is down
        $net = RoutingFixture::network();
        $net->router(7, 'srv1', null)->address(7, 'eth1', '192.168.50.1/24', '', ['ifOperStatus' => 'down']);
        $net->router(8, 'srv2', null)->address(8, 'eth1', '192.168.50.1/24', '', ['ifOperStatus' => 'lowerLayerDown']);
        $net->link(7, null, 8, null);
        $this->assertSame([], $net->analyzer()->analyze()['findings']);

        $net->update('addresses', ['address' => '192.168.50.1'], ['ifOperStatus' => 'up']);
        $this->assertSame('warning', self::find($net->analyzer()->analyze(), 'ip_duplicate')['severity']);
    }

    public function testLocalAsSessionDownForAnotherReason(): void
    {
        // r3 presents local-as 65100 to r4; the session is down because the hold timer expired
        $net = RoutingFixture::network();
        $net->router(4, 'r4', 65004);
        $hold = ['bgpPeerState' => 'idle', 'bgpPeerLastErrorCode' => 4, 'bgpPeerLastErrorSubCode' => 0];
        $net->bgpPeer(4, '10.1.34.1', 65100, $hold)->bgpPeer(3, '10.1.34.2', 65004, $hold);
        $result = $net->analyzer()->analyze();

        $this->assertNull(self::find($result, 'bgp_remote_as_mismatch'));
        $this->assertSame('BGP session r4 → r3 (10.1.34.1, AS 65100) is Idle: Hold Timer Expired', self::find($result, 'bgp_session_down', 'r4 → r3')['title']);

        // a session that never came up is checked
        $net->update('peers', ['device_id' => 4], ['bgpPeerFsmEstablishedTime' => 0]);
        $finding = self::find($net->analyzer()->analyze(), 'bgp_remote_as_mismatch');
        $this->assertSame('BGP session r4 → r3 (10.1.34.1, AS 65100): remote AS 65100 configured, r3 is AS 65000', $finding['title']);
        $this->assertStringContainsString('has never come up', $finding['detail']);
        $this->assertStringContainsString('Last error: Hold Timer Expired (4/0)', $finding['detail']);
    }

    public function testAsTransIsNotCompared(): void
    {
        // a two-byte-only peer sees the four-byte AS of r4 as AS_TRANS
        $net = RoutingFixture::network();
        $net->router(4, 'r4', 4200000004);
        $net->bgpPeer(3, '10.1.34.2', 23456, ['bgpPeerState' => 'active', 'bgpPeerFsmEstablishedTime' => 0]);

        $this->assertNull(self::find($net->analyzer()->analyze(), 'bgp_remote_as_mismatch'));
    }

    public function testOneSidedNeedsThePeersSessionsInTheVrf(): void
    {
        // ce1 peers with r1's VRF CUST address; r1's VRF sessions are not polled (no SNMP context)
        $net = RoutingFixture::network();
        $net->router(7, 'ce1', 65101)->address(7, 'Gi0/0', '10.40.0.2/30');
        $net->address(1, 'Gi0/2', '10.40.0.1/30', 'CUST');
        $net->bgpPeer(7, '10.40.0.1', 65000, ['bgpPeerState' => 'active', 'bgpPeerLastErrorCode' => 4, 'bgpPeerFsmEstablishedTime' => 0]);
        $result = $net->analyzer()->analyze();

        $this->assertNull(self::find($result, 'bgp_one_sided'));
        $finding = self::find($result, 'bgp_session_down', 'ce1 → r1');
        $this->assertSame('BGP session ce1 → r1 (10.40.0.1, AS 65000) is Active: Hold Timer Expired', $finding['title']);
        $this->assertStringContainsString('r1 reports no BGP sessions in VRF CUST', $finding['detail']);

        // once r1's sessions in VRF CUST are polled, the missing neighbor shows
        $net->bgpPeer(1, '10.40.0.6', 65102, ['vrf' => 'CUST']);
        $this->assertNotNull(self::find($net->analyzer()->analyze(), 'bgp_one_sided', 'ce1 → r1'));
    }

    public function testUnknownBgpStateIsNoData(): void
    {
        // Junos peers polled before the walk matched them: state and admin status "unknown"
        $net = RoutingFixture::network();
        $net->update('peers', ['bgpPeerIdentifier' => '203.0.113.1'], ['bgpPeerState' => 'unknown', 'bgpPeerAdminStatus' => 'unknown']);
        $result = $net->analyzer()->analyze();

        $this->assertSame([], $result['findings']);
        $isp = array_values(array_filter($result['bgp_sessions'], fn ($r) => $r['ip'] === '203.0.113.1'))[0];
        $this->assertSame('unknown', $isp['state']);
        $this->assertTrue($isp['unknown']);
        $this->assertSame(1, $result['summary']['bgp']['unknown']);
    }

    public function testPrefixCountersPerAddressFamily(): void
    {
        $net = RoutingFixture::network();
        $net->prefixes(1, '203.0.113.1', 300, 500, ['afi' => 'ipv6']);
        $result = $net->analyzer()->analyze();

        $isp = array_values(array_filter($result['bgp_sessions'], fn ($r) => $r['ip'] === '203.0.113.1'))[0];
        $this->assertSame([
            ['family' => 'IPv4 unicast', 'accepted' => 800, 'limit' => 2000],
            ['family' => 'IPv6 unicast', 'accepted' => 300, 'limit' => 500],
        ], $isp['prefixes']);
    }

    public function testSessionDownWithoutANotification(): void
    {
        $net = RoutingFixture::network();
        $net->update('peers', ['bgpPeerIdentifier' => '203.0.113.1'], ['bgpPeerState' => 'active']);
        $finding = self::find($net->analyzer()->analyze(), 'bgp_session_down');

        $this->assertStringContainsString('went down without a NOTIFICATION', $finding['detail']);
        $this->assertStringNotContainsString('never came up', $finding['detail']);
        $this->assertStringContainsString('Last established 30d ago', $finding['detail']);
        $this->assertStringContainsString('Check the path between 203.0.113.2 and 203.0.113.1', $finding['recommendation']);
    }

    public function testRowsCanBeStreamed(): void
    {
        // the repository passes generators, each read once
        $net = RoutingFixture::network();
        $net->update('neighbors', ['device_id' => 3, 'ospfNbrIpAddr' => '10.1.34.2'], ['ospfNbrState' => 'init']);
        $net->address(3, 'Vl50', '10.50.0.9/24');
        $net->address(4, 'Vl50', '10.50.0.9/24');
        $stream = function (array $rows) {
            yield from $rows;
        };
        $streamed = new \Agoodley\LibrenmsTopology\Routing\RoutingAnalyzer(
            $net->devices,
            array_keys($net->devices),
            $stream($net->addresses),
            new \Agoodley\LibrenmsTopology\Support\PluginSettings([]),
            array_map($stream, $net->ospf),
            array_map($stream, $net->bgp),
            $stream($net->links),
            $net->vrfs,
        );

        $this->assertSame($net->analyzer()->analyze(), $streamed->analyze());
        $this->assertSame(['ip_duplicate', 'ospf_neighbor_stuck'], self::codes($streamed->analyze()));
    }

    public function testProviderEdgeLinkIsARoutingBoundary(): void
    {
        // PE r1 reuses the PE-CE /30 for two customers, with LLDP to both CEs on the addressed ports
        $net = RoutingFixture::network();
        $net->router(7, 'ce-a', 65101)->router(8, 'ce-b', 65102);
        $net->link(1, $net->address(1, 'Gi0/1.100', '10.255.254.1/30', 'CUST-A'), 7, $net->address(7, 'Gi0/0', '10.255.254.2/30'));
        $net->link(1, $net->address(1, 'Gi0/2.200', '10.255.254.1/30', 'CUST-B'), 8, $net->address(8, 'Gi0/0', '10.255.254.2/30'));
        $finding = self::find($net->analyzer()->analyze(), 'ip_duplicate');
        $this->assertSame('info', $finding['severity'], 'the CEs only meet in two different VRFs of the PE');

        // the same with LLDP on the physical ports, the addresses on dot1q subinterfaces
        $net = RoutingFixture::network();
        $net->router(7, 'ce-a', 65101)->router(8, 'ce-b', 65102);
        $net->address(1, 'Gi0/1.100', '10.255.254.1/30', 'CUST-A');
        $net->address(1, 'Gi0/2.200', '10.255.254.1/30', 'CUST-B');
        $net->link(1, 901, 7, $net->address(7, 'Gi0/0', '10.255.254.2/30'), 'Gi0/1');
        $net->link(1, 902, 8, $net->address(8, 'Gi0/0', '10.255.254.2/30'), 'Gi0/2');
        $this->assertSame('info', self::find($net->analyzer()->analyze(), 'ip_duplicate')['severity']);

        // a CE behind a PE port in the global table is in the PE's network
        $net = RoutingFixture::network();
        $net->router(7, 'ce-a', 65101);
        $net->link(1, $net->address(1, 'Gi0/1', '10.255.254.1/30'), 7, $net->address(7, 'Gi0/0', '10.255.254.2/30'));
        $net->address(7, 'Vl10', '10.50.0.9/24');
        $net->address(3, 'Vl50', '10.50.0.9/24');
        $this->assertSame('warning', self::find($net->analyzer()->analyze(), 'ip_duplicate')['severity']);
    }

    public function testOspfBetweenProviderEdgeAndCustomersIsABoundary(): void
    {
        // PE-CE OSPF: r1's VRF instances CUST-A and CUST-B are neighbors of ce-a and ce-b, which reuse the
        // PE-CE /30; ce-a's own network reuses the provider core subnet 10.0.12.0/30 with other timers
        $net = RoutingFixture::network();
        $net->router(7, 'ce-a', 65101)->router(8, 'ce-b', 65102)->router(9, 'ce-a2', 65101);
        $net->ospfRouter(1, '1.1.1.101', 'CUST-A')->ospfRouter(1, '1.1.1.102', 'CUST-B');
        $net->ospfRouter(7, '7.7.7.7')->ospfRouter(8, '8.8.8.8')->ospfRouter(9, '9.9.9.9');
        $net->address(1, 'Gi0/1.100', '10.255.254.1/30', 'CUST-A');
        $net->address(1, 'Gi0/2.200', '10.255.254.1/30', 'CUST-B');
        $net->address(7, 'Gi0/0', '10.255.254.2/30');
        $net->address(8, 'Gi0/0', '10.255.254.2/30');
        $net->neighbor(1, '10.255.254.2', '7.7.7.7', 'full', 'CUST-A')->neighbor(1, '10.255.254.2', '8.8.8.8', 'full', 'CUST-B');
        $net->neighbor(7, '10.255.254.1', '1.1.1.101')->neighbor(8, '10.255.254.1', '1.1.1.102');
        $fast = ['ospfIfHelloInterval' => 5, 'ospfIfRtrDeadInterval' => 20];
        $net->ospfLink(7, 'Gi0/1', 9, 'Gi0/1', '10.0.12.0/30', '0.0.0.0', $fast, $fast);
        $result = $net->analyzer()->analyze();

        $this->assertSame(['ip_duplicate', 'ip_duplicate', 'ip_duplicate'], self::codes($result), 'no timer mismatch between provider and customer');
        $this->assertSame(['info', 'info', 'info'], array_column($result['findings'], 'severity'));
    }

    public function testProviderCoreSubnetReusedByACustomer(): void
    {
        // the provider core (r1-r2, area 0) and the customer's own network (area 5) both use 10.0.0.0/30;
        // they only meet on the PE-CE link, whose PE end is in VRF CUST
        $net = RoutingFixture::network();
        $net->router(21, 'cust-ce', 65101)->router(22, 'cust-ce2', 65101)->ospfRouter(21, '172.16.255.1')->ospfRouter(22, '172.16.255.2');
        $net->ospfLink(1, 'Te0/0/9', 2, 'Te0/0/9', '10.0.0.0/30');
        $fast = ['ospfIfHelloInterval' => 5, 'ospfIfRtrDeadInterval' => 20];
        $net->ospfLink(21, 'Gi0/1', 22, 'Gi0/1', '10.0.0.0/30', '0.0.0.5', $fast, $fast);
        $net->link(1, $net->address(1, 'Gi0/0/3', '10.200.0.1/30', 'CUST'), 21, $net->address(21, 'Gi0/0', '10.200.0.2/30'));
        $result = $net->analyzer()->analyze();

        $this->assertSame(['ip_duplicate', 'ip_duplicate'], self::codes($result), 'no timer or area mismatch between the two networks');
        $this->assertSame(['info', 'info'], array_column($result['findings'], 'severity'));
    }

    public function testCustomerLanOverlappingTheProviderCore(): void
    {
        $net = RoutingFixture::network();
        $net->router(21, 'cust-ce', 65101);
        $net->address(1, 'Te0/0/9', '10.0.0.1/30');
        $net->address(2, 'Te0/0/9', '10.0.0.2/30');
        $net->address(21, 'Vl10', '10.0.0.3/24');
        $net->link(1, $net->address(1, 'Gi0/0/3', '10.200.0.1/30', 'CUST'), 21, $net->address(21, 'Gi0/0', '10.200.0.2/30'));

        $this->assertNull(self::find($net->analyzer()->analyze(), 'ip_mask_mismatch'));
    }

    public function testGatewayAddressOnAFirewallWithoutLldp(): void
    {
        // a firewall that sends no LLDP/CDP (ASA, PAN-OS by default) configured with r1's gateway address
        $net = RoutingFixture::network();
        $net->address(1, 'Vl60', '10.60.0.1/24');
        $net->router(9, 'fw1', null)->address(9, 'inside', '10.60.0.1/24');
        $finding = self::find($net->analyzer()->analyze(), 'ip_duplicate');

        $this->assertSame('info', $finding['severity']);
        $this->assertStringContainsString('LibreNMS sees no link between these devices', $finding['detail']);
        $this->assertStringContainsString('If the networks are connected, give one of the interfaces another address', $finding['recommendation']);
    }

    public function testSingleHomedBranchWithAnAreaMismatch(): void
    {
        // a branch on a carrier circuit (no LLDP across it) in another area, so no OSPF neighbor at all
        $net = RoutingFixture::network();
        $net->router(9, 'b1')->ospfRouter(9, '9.9.9.9');
        foreach ([[1, 'Gi0/0/5', '10.77.0.1', '0.0.0.0'], [9, 'Gi0/0', '10.77.0.2', '0.0.0.9']] as [$device, $port, $ip, $area]) {
            $net->ospf['ports'][] = ['device_id' => $device, 'port_id' => $net->address($device, $port, "$ip/30"), 'ospfIfIpAddress' => $ip, 'ospfIfAreaId' => $area,
                'ospfIfType' => 'pointToPoint', 'ospfIfAdminStat' => 'enabled', 'ospfIfHelloInterval' => 10, 'ospfIfRtrDeadInterval' => 40, 'context_name' => ''];
        }
        $finding = self::find($net->analyzer()->analyze(), 'ospf_area_mismatch');

        $this->assertSame('critical', $finding['severity']);
        $this->assertSame('OSPF area differs on 10.77.0.0/30: r1 Gi0/0/5: area 0.0.0.0, b1 Gi0/0: area 0.0.0.9', $finding['title']);
    }

    public function testHostLocalBridgesAreNoDuplicates(): void
    {
        // libvirt and Docker bridges with their default addresses on two hosts behind one switch, with VMs running
        $net = RoutingFixture::network();
        $net->router(7, 'kvm1', null)->router(8, 'kvm2', null)->link(7, null, 8, null);
        foreach ([7, 8] as $host) {
            $net->address($host, 'virbr0', '192.168.122.1/24');
            $net->address($host, 'docker0', '172.17.0.1/16');
            $net->address($host, 'br-0123456789ab', '172.18.0.1/16');
        }

        $this->assertSame([], $net->analyzer()->analyze()['findings']);
    }

    public function testProviderEdgeWithTheCustomerAddressOnAnSvi(): void
    {
        // a switch-based PE: the LLDP port toward each CE has no address, the PE-CE address is on an SVI in the
        // customer's VRF; the CEs use a routed port in the global table
        $net = RoutingFixture::network();
        $net->router(7, 'ce-a', 65101)->router(8, 'ce-b', 65102);
        $net->address(1, 'Vlan200', '10.255.254.1/30', 'CUST-A');
        $net->address(1, 'Vlan300', '10.255.254.1/30', 'CUST-B');
        $net->link(1, 901, 7, $net->address(7, 'Gi0/0', '10.255.254.2/30'), 'Gi1/0/1');
        $net->link(1, 902, 8, $net->address(8, 'Gi0/0', '10.255.254.2/30'), 'Gi1/0/2');
        $this->assertSame('info', self::find($net->analyzer()->analyze(), 'ip_duplicate')['severity']);

        // the provider core /30 reused inside the customer's network behind such a PE-CE link
        $net = self::overlap();
        $net->address(1, 'Vlan200', '10.200.0.1/30', 'CUST');
        $net->link(1, 901, 21, $net->address(21, 'Gi0/0', '10.200.0.2/30'), 'Gi1/0/1');
        $result = $net->analyzer()->analyze();
        $this->assertSame(['ip_duplicate', 'ip_duplicate'], self::codes($result), 'no timer or area mismatch');
        $this->assertSame(['info', 'info'], array_column($result['findings'], 'severity'));
    }

    public function testProviderEdgeOverAPortChannel(): void
    {
        // LLDP runs on the member ports, the addresses are on the port-channels (Po10.100 / Po1)
        $net = RoutingFixture::network();
        $net->router(7, 'ce-a', 65101)->router(8, 'ce-b', 65102);
        $net->address(1, 'Port-channel10.100', '10.255.254.1/30', 'CUST-A');
        $net->address(1, 'Port-channel20.200', '10.255.254.1/30', 'CUST-B');
        $net->address(7, 'Port-channel1', '10.255.254.2/30');
        $net->address(8, 'Port-channel1', '10.255.254.2/30');
        $net->link(1, 901, 7, 911, 'Gi0/0/1', 'Gi0/1')->link(1, 902, 7, 912, 'Gi0/0/2', 'Gi0/2');
        $net->link(1, 903, 8, 913, 'Gi0/0/3', 'Gi0/1')->link(1, 904, 8, 914, 'Gi0/0/4', 'Gi0/2');
        $this->assertSame('info', self::find($net->analyzer()->analyze(), 'ip_duplicate')['severity']);

        $net = self::overlap();
        $net->address(1, 'Bundle-Ether100.200', '10.200.0.1/30', 'CUST');
        $net->address(21, 'Port-channel1', '10.200.0.2/30');
        $net->link(1, 901, 21, 911, 'Te0/0/0/1', 'Gi0/1');
        $result = $net->analyzer()->analyze();
        $this->assertSame(['ip_duplicate', 'ip_duplicate'], self::codes($result), 'no timer or area mismatch');
        $this->assertSame(['info', 'info'], array_column($result['findings'], 'severity'));
    }

    public function testCustomerRouterRowWithTheProviderPortUnresolved(): void
    {
        // dot1q PE-CE links, plus the CEs' own LLDP rows in which LibreNMS could not resolve the PE's port
        $net = RoutingFixture::network();
        $net->router(7, 'ce-a', 65101)->router(8, 'ce-b', 65102);
        $net->address(1, 'Gi0/1.100', '10.255.254.1/30', 'CUST-A');
        $net->address(1, 'Gi0/2.200', '10.255.254.1/30', 'CUST-B');
        $ceA = $net->address(7, 'Gi0/0', '10.255.254.2/30');
        $ceB = $net->address(8, 'Gi0/0', '10.255.254.2/30');
        $net->link(1, 901, 7, $ceA, 'Gi0/1')->link(1, 902, 8, $ceB, 'Gi0/2');
        $net->link(7, $ceA, 1, 0)->link(8, $ceB, 1, 0);

        $this->assertSame('info', self::find($net->analyzer()->analyze(), 'ip_duplicate')['severity']);
    }

    public function testCustomerRoutersOnTheSameProviderEdgeAddress(): void
    {
        // an MSP monitors two customers' CEs (not the PEs): both use 10.255.254.2/30 toward their PE, with OSPF in
        // other areas and timers; a /30 that two devices hold is only a link when they hold its two addresses
        $net = RoutingFixture::network();
        $net->router(7, 'ce-a', 65101)->router(8, 'ce-b', 65102)->ospfRouter(7, '7.7.7.7')->ospfRouter(8, '8.8.8.8');
        foreach ([7 => ['0.0.0.1', 10, 40], 8 => ['0.0.0.2', 5, 20]] as $device => [$area, $hello, $dead]) {
            $net->ospf['ports'][] = ['device_id' => $device, 'port_id' => $net->address($device, 'Gi0/0', '10.255.254.2/30'), 'ospfIfIpAddress' => '10.255.254.2',
                'ospfIfAreaId' => $area, 'ospfIfType' => 'pointToPoint', 'ospfIfAdminStat' => 'enabled', 'ospfIfHelloInterval' => $hello,
                'ospfIfRtrDeadInterval' => $dead, 'context_name' => ''];
            $net->neighbor($device, '10.255.254.1', '192.0.2.' . $device);
        }

        $this->assertSame(['ip_duplicate'], self::codes($net->analyzer()->analyze()));
    }

    public function testGlobalTableSitesStillJoin(): void
    {
        // a duplicate between two sites of one network must stay a warning however their link looks
        $links = [
            'routed link' => fn (RoutingFixture $n) => $n->link(31, $n->address(31, 'Gi0/0', '10.5.0.1/30'), 32, $n->address(32, 'Gi0/0', '10.5.0.2/30')),
            'through an L2 switch' => fn (RoutingFixture $n) => $n->router(33, 'sw', null)
                ->link(31, $n->address(31, 'Gi0/0', '10.5.0.1/24'), 33, 931, null, 'Gi1/0/1')->link(32, $n->address(32, 'Gi0/0', '10.5.0.2/24'), 33, 932, null, 'Gi1/0/2'),
            'port-channel' => function (RoutingFixture $n) {
                $n->address(31, 'Port-channel1', '10.5.0.1/30');
                $n->address(32, 'Port-channel1', '10.5.0.2/30');
                $n->link(31, 931, 32, 932, 'Gi0/1', 'Gi0/1');
            },
            'unnumbered' => fn (RoutingFixture $n) => $n->link(31, 931, 32, 932, 'Gi0/0', 'Gi0/0'),
            'Junos units' => function (RoutingFixture $n) {
                $n->address(31, 'ge-0/0/0.0', '10.5.0.1/30');
                $n->address(32, 'ge-0/0/0.0', '10.5.0.2/30');
                $n->link(31, 931, 32, 932, 'ge-0/0/0', 'ge-0/0/0');
            },
            'dot1q subinterface to an SVI' => function (RoutingFixture $n) {
                $n->address(31, 'Gi0/0.10', '10.5.0.1/24');
                $n->address(32, 'Vlan10', '10.5.0.2/24');
                $n->link(31, 931, 32, 932, 'Gi0/0', 'Gi1/0/48');
            },
        ];
        foreach ($links as $how => $build) {
            $net = RoutingFixture::network();
            $net->router(31, 'r-site1')->router(32, 'r-site2');
            $build($net);
            $net->address(31, 'Vl50', '172.30.0.1/24');
            $net->address(32, 'Vl51', '172.30.0.1/24');
            $this->assertSame('warning', self::find($net->analyzer()->analyze(), 'ip_duplicate', '172.30.0.1')['severity'] ?? null, $how);
        }
    }

    public function testNetworksOfManyRoutingTables(): void
    {
        // switches joined by trunks without addresses (every table), a router on sw1 in VRF RED only, and one
        // duplicate address per VRF; between sw3 and the router VRF RED connects them, VRF BLUE does not
        $net = RoutingFixture::network();
        $net->router(41, 'sw1', null)->router(42, 'sw2', null)->router(43, 'sw3', null)->router(44, 'r-red');
        $net->link(41, 941, 42, 942, 'Gi1/0/1', 'Gi1/0/1')->link(42, 943, 43, 944, 'Gi1/0/2', 'Gi1/0/2');
        $net->link(41, $net->address(41, 'Vlan10', '10.44.0.1/30', 'RED'), 44, $net->address(44, 'Gi0/0', '10.44.0.2/30', 'RED'));
        $net->vrfs[43]['BLUE'] = null;
        $net->vrfs[44]['BLUE'] = null;
        foreach (range(1, 40) as $i) {
            $net->address(41, "Vlan$i", "10.45.$i.1/24", "T$i");
            $net->address(43, "Vlan$i", "10.45.$i.1/24", "T$i");
        }
        $net->address(43, 'Vlan901', '10.46.0.1/24', 'RED');
        $net->address(44, 'Gi0/9', '10.46.0.1/24', 'RED');
        $net->address(43, 'Vlan902', '10.47.0.1/24', 'BLUE');
        $net->address(44, 'Gi0/8', '10.47.0.1/24', 'BLUE');
        $result = $net->analyzer()->analyze();

        $duplicates = array_values(array_filter($result['findings'], fn ($f) => $f['code'] === 'ip_duplicate'));
        $this->assertCount(42, $duplicates);
        $this->assertSame(['warning'], array_values(array_unique(array_column(array_filter($duplicates, fn ($f) => str_contains($f['title'], '10.45.')), 'severity'))),
            'sw1 and sw3 are joined in every table by the trunks');
        $this->assertSame('warning', self::find($result, 'ip_duplicate', '10.46.0.1')['severity'], 'r-red reaches sw3 in RED over sw1');
        $this->assertSame('info', self::find($result, 'ip_duplicate', '10.47.0.1')['severity'], 'but not in BLUE');
    }

    public function testSwitchesSharingOnlyAManagementSubnet(): void
    {
        // a trunk without addresses between two access switches whose management addresses share a subnet, and a
        // tenant's router in VRF TENANT on an access port of each, both with one address: the trunk is one L2 domain
        // that joins every table unless a subnet the switches share is in VRFs of different names; loopbacks and
        // ports linked to other devices do not count
        $variants = [
            'management subnet in the global table' => ['', '', null, 'warning'],
            'VRF names in another case' => ['mgmt', 'MGMT', null, 'warning'],
            'loopback of another VRF in the subnet' => ['', '', fn (RoutingFixture $n) => $n->address(52, 'Loopback1', '10.10.10.99/32', 'LAB'), 'warning'],
            'subnet reused in another VRF toward a firewall' => ['', '', fn (RoutingFixture $n) => $n->router(55, 'fw', null)
                ->link(52, $n->address(52, 'Gi1/0/40', '10.10.10.10/30', 'FW'), 55, $n->address(55, 'eth0', '10.10.10.9/30', 'FW')), 'warning'],
            'management subnet in VRFs of different names' => ['', 'MGMT', null, 'info'],
        ];
        foreach ($variants as $how => [$vrf1, $vrf2, $extra, $severity]) {
            $net = RoutingFixture::network();
            $net->router(51, 'sw1', null)->router(52, 'sw2', null)->router(53, 'r-tenant1')->router(54, 'r-tenant2');
            $net->address(51, 'Vlan1', '10.10.10.11/24', $vrf1);
            $net->address(52, 'Vlan1', '10.10.10.12/24', $vrf2);
            $net->link(51, 951, 52, 952, 'Gi1/0/48', 'Gi1/0/48');
            $net->link(53, $net->address(53, 'Gi0/0', '10.61.0.1/24', 'TENANT'), 51, 953, null, 'Gi1/0/1');
            $net->link(54, $net->address(54, 'Gi0/0', '10.61.0.2/24', 'TENANT'), 52, 954, null, 'Gi1/0/1');
            $net->address(53, 'Gi0/1', '10.60.0.1/24', 'TENANT');
            $net->address(54, 'Gi0/1', '10.60.0.1/24', 'TENANT');
            if ($extra) {
                $extra($net);
            }

            $this->assertSame($severity, self::find($net->analyzer()->analyze(), 'ip_duplicate', '10.60.0.1')['severity'] ?? null, $how);
        }
    }

    public function testVrfLiteTrunkWhoseSharedSubnetsAgree(): void
    {
        // a trunk without addresses between two distribution switches with SVIs of VRFs RED and BLUE in the same
        // subnets on both sides, and a router on each with subinterfaces in RED and GREEN (which has no SVIs on the
        // switches) reusing one address per VRF: every shared subnet agrees, so the trunk joins every table; one
        // shared subnet in VRFs of different names makes it a boundary that joins only the VRFs that agree (RED)
        foreach (['BLUE' => 'warning', 'BLUE2' => 'info'] as $blue => $green) {
            $net = RoutingFixture::network();
            $net->router(61, 'dist1', null)->router(62, 'dist2', null)->router(63, 'r-a')->router(64, 'r-b');
            $net->link(61, 961, 62, 962, 'Te1/1/1', 'Te1/1/1')->link(61, 963, 63, 973, 'Gi1/0/1', 'Gi0/0')->link(62, 964, 64, 974, 'Gi1/0/1', 'Gi0/0');
            foreach ([61 => 1, 62 => 2] as $device => $host) {
                $net->address($device, 'Vlan10', "10.71.10.$host/24", 'RED');
                $net->address($device, 'Vlan20', "10.71.20.$host/24", $device === 62 ? $blue : 'BLUE');
            }
            foreach ([63, 64] as $device) {
                $net->address($device, 'Gi0/0.11', '10.73.0.1/24', 'RED');
                $net->address($device, 'Gi0/0.30', '10.72.0.1/24', 'GREEN');
            }
            $result = $net->analyzer()->analyze();

            $this->assertSame('warning', self::find($result, 'ip_duplicate', '10.73.0.1')['severity'] ?? null, $blue);
            $this->assertSame($green, self::find($result, 'ip_duplicate', '10.72.0.1')['severity'] ?? null, $blue);
        }
    }

    /**
     * Provider r1-r2 core link 10.0.0.0/30 (area 0) and a customer cust-ce/cust-ce2 reusing it (area 5, 5/20 s).
     */
    private static function overlap(): RoutingFixture
    {
        $net = RoutingFixture::network();
        $net->router(21, 'cust-ce', 65101)->router(22, 'cust-ce2', 65101)->ospfRouter(21, '172.16.255.1')->ospfRouter(22, '172.16.255.2');
        $net->ospfLink(1, 'Te0/0/9', 2, 'Te0/0/9', '10.0.0.0/30');
        $fast = ['ospfIfHelloInterval' => 5, 'ospfIfRtrDeadInterval' => 20];
        $net->ospfLink(21, 'Gi0/1', 22, 'Gi0/1', '10.0.0.0/30', '0.0.0.5', $fast, $fast);

        return $net;
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
