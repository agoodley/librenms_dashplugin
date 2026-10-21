<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Routing\RoutingService;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

class RoutingServiceTest extends TestCase
{
    /**
     * The fixture network with a problem of every kind, PE-CE address reuse, a second network
     * (custA) that reuses a subnet of the first and has many addresses of its own, and neighbors
     * that only one relation makes known: sw9 only by LLDP (the address on an SVI), r18 only by its
     * address (r17 and r18 see other router IDs), r10 only by its own OSPF neighbor row
     * (it sees r2 in Init), r13 only by its router ID (r1's neighbor address is not in LibreNMS),
     * r14 only by r3's neighbor row (r3 sees it in Init, r14's OSPF is not polled), r15 only by its
     * BGP session to r1 (configured on r15 only), and r4 only by sharing a gateway address with r1.
     * PE-CE links from r4 to custA-r1 and (on a dot1q subinterface) to custA-r2 keep the provider and
     * custA apart (r2 and custA-r2 share an address).
     */
    private static function busy(): RoutingFixture
    {
        $net = RoutingFixture::network();
        $net->update('neighbors', ['device_id' => 3, 'ospfNbrIpAddr' => '10.1.34.2'], ['ospfNbrState' => 'init']);
        $net->update('ports', ['ospfIfIpAddress' => '10.0.12.2'], ['ospfIfHelloInterval' => 5, 'ospfIfRtrDeadInterval' => 20]);
        $net->router(5, 'r5')->ospfRouter(5, '3.3.3.3');
        $net->router(6, 'r6')->ospfRouter(6, '6.6.6.6')->area(6, '0.0.0.0', 3, 1);
        $net->ospfLink(3, 'Te0/0/4', 6, 'Gi0/1', '10.0.36.0/30', '0.0.0.0', ['ifMtu' => 9216], [], 'exchangeStart', 'exchangeStart');

        $net->update('peers', ['bgpPeerIdentifier' => '203.0.113.1'], ['bgpPeerState' => 'active', 'bgpPeerLastErrorCode' => 4]);
        $net->bgpPeer(3, '10.0.23.1', 65000, ['bgpPeerState' => 'active', 'bgpPeerFsmEstablishedTime' => 0]);
        $net->router(4, 'r4', 65004)->bgpPeer(3, '10.1.34.2', 65000, ['bgpPeerState' => 'idle', 'bgpPeerLastErrorCode' => 2, 'bgpPeerLastErrorSubCode' => 2]);
        $net->bgpPeer(4, '10.1.34.1', 65000, ['bgpPeerState' => 'active']);

        $net->address(3, 'Vl50', '10.50.0.9/24');
        $net->address(4, 'Vl50', '10.50.0.9/24');
        foreach ([1 => '10.60.0.2/24', 2 => '10.60.0.3/24'] as $device => $own) {
            $net->address($device, 'Vl60', $own);
            $net->address($device, 'Vl60', '10.60.0.1/24');
        }
        $net->address(1, 'Te0/0/7', '10.80.0.1/30');
        $net->address(2, 'Te0/0/7', '10.80.0.2/24');
        $net->vrfs = [1 => ['PROD' => null, 'DEV' => null], 2 => ['PROD' => null, 'DEV' => null]];
        $net->link(1, $net->address(1, 'Te0/0/5', '10.30.0.1/30', 'PROD'), 2, $net->address(2, 'Te0/0/5', '10.30.0.2/30', 'DEV'));

        $net->router(7, 'ce-a', 65101)->address(7, 'Gi0/0', '10.255.254.2/30');
        $net->router(8, 'ce-b', 65102)->address(8, 'Gi0/0', '10.255.254.2/30');
        $net->address(1, 'Gi0/1.100', '10.255.254.1/30', 'CUST-A');
        $net->address(1, 'Gi0/1.200', '10.255.254.1/30', 'CUST-B');
        $net->bgpPeer(1, '10.255.254.2', 65101, ['vrf' => 'CUST-A'])->bgpPeer(1, '10.255.254.2', 65102, ['vrf' => 'CUST-B', 'bgpPeerState' => 'active']);
        $net->bgpPeer(7, '10.255.254.1', 65000)->bgpPeer(8, '10.255.254.1', 65000, ['bgpPeerState' => 'active']);

        $net->router(11, 'custA-r1')->router(12, 'custA-r2')->ospfRouter(11, '11.11.11.11')->ospfRouter(12, '12.12.12.12');
        $net->ospfLink(11, 'Gi0/1', 12, 'Gi0/1', '10.0.12.0/30');
        for ($i = 1; $i <= 30; $i++) {
            $net->address(12, "Vl$i", "172.20.$i.1/24");
        }

        $net->router(9, 'sw9', null)->link(4, $net->address(4, 'Gi0/0/5', '10.90.0.1/30'), 9, $net->address(9, 'Gi1/0/48', '10.91.9.2/30'));
        $net->address(9, 'Vl1', '10.90.0.2/24');
        $net->router(10, 'r10')->ospfRouter(10, '10.10.10.10');
        $port = $net->address(2, 'Te0/0/8', '10.0.210.1/30');
        $net->ospf['ports'][] = ['device_id' => 2, 'port_id' => $port, 'ospfIfIpAddress' => '10.0.210.1', 'ospfIfAreaId' => '0.0.0.0', 'ospfIfType' => 'pointToPoint',
            'ospfIfAdminStat' => 'enabled', 'ospfIfState' => 'pointToPoint', 'context_name' => ''];
        $net->address(10, 'Gi0/1', '10.0.210.2/30');
        $net->neighbor(10, '10.0.210.1', '2.2.2.2', 'init');
        $net->address(2, 'Te0/0/9', '10.91.0.1/30');
        $net->address(10, 'Gi0/2', '10.91.0.2/24');
        $net->router(13, 'r13')->ospfRouter(13, '13.13.13.13')->neighbor(1, '10.0.113.2', '13.13.13.13');
        $net->address(1, 'Gi0/9', '10.92.0.1/30');
        $net->address(13, 'Gi0/9', '10.92.0.2/24');
        $net->router(14, 'r14')->neighbor(3, '10.0.134.2', '14.14.14.14', 'init');
        $net->address(14, 'Gi0/1', '10.0.134.2/30');
        $net->address(3, 'Gi0/9', '10.93.0.1/30');
        $net->address(14, 'Gi0/9', '10.93.0.2/24');
        $net->router(15, 'r15')->address(15, 'Gi0/1', '10.0.115.2/30');
        $net->bgpPeer(15, '1.1.1.1', 65000, ['bgpPeerState' => 'active', 'bgpPeerFsmEstablishedTime' => 0, 'bgpLocalAddr' => '10.0.115.2']);
        // r18 behind r17 on a circuit without LLDP, neither with the router ID the other sees: only their
        // addresses resolve the adjacency, which puts r18 into area 0 of the domain
        $net->router(17, 'r17')->ospfRouter(17, '17.17.17.70')->router(18, 'r18')->ospfRouter(18, '18.18.18.80')->area(18, '0.0.0.0', 12, 400000);
        $net->ospfLink(4, 'Te0/0/7', 17, 'Te0/0/1', '10.0.147.0/30', '0.0.0.1');
        $net->ospfLink(17, 'Te0/0/2', 18, 'Te0/0/1', '10.0.178.0/30');
        array_pop($net->links);
        // a PE-CE link (r4's end in VRF CUST): a routing boundary between the provider and custA
        $net->link(4, $net->address(4, 'Gi0/0/9', '10.201.0.1/30', 'CUST'), 11, $net->address(11, 'Gi0/2', '10.201.0.2/30'));
        $net->address(4, 'Gi0/0/8.300', '10.202.0.1/30', 'CUST2');
        $net->link(4, 990, 12, $net->address(12, 'Gi0/3', '10.202.0.2/30'), 'Gi0/0/8');
        // custB-ce behind r6 over a LAG (LLDP on the members, the addresses on the port-channels, an IPv6
        // link-local address on r6's member): a PE-CE boundary again; custB-ce reuses r2's 10.209.0.2
        $net->router(19, 'custB-ce', 65201);
        $net->address(6, 'Port-channel40.400', '10.204.0.1/30', 'CUSTB');
        $net->address(19, 'Port-channel1', '10.204.0.2/30');
        $net->address(6, 'Gi0/0/40', 'fe80::6/64', '', ['port_id' => 994]);
        $net->link(6, 994, 19, 995, 'Gi0/0/40', 'Gi0/1');
        $net->address(2, 'Vl209', '10.209.0.2/24');
        $net->address(19, 'Vl9', '10.209.0.2/24');
        foreach ([1 => '10.70.0.2/24', 4 => '10.70.0.3/24'] as $device => $own) {
            $net->address($device, 'Vl70', $own);
            $net->address($device, 'Vl70', '10.70.0.1/24');
        }

        return $net;
    }

    private static function params(?int $device): array
    {
        return ['device' => $device, 'group' => null, 'location' => null, 'vrf' => null];
    }

    public function testADeviceViewFindsWhatTheFullViewFindsForIt(): void
    {
        $net = self::busy();
        $service = new RoutingService(new FakeRoutingRepository($net), new PluginSettings([]));
        $full = $net->analyzer()->analyze();
        $this->assertGreaterThan(10, count($full['findings']));
        foreach (['sw9 Vl1 10.90.0.2/24', 'r10 Gi0/2 10.91.0.2/24', 'r13 Gi0/9 10.92.0.2/24', 'r14 Gi0/9 10.93.0.2/24'] as $end) {
            $this->assertNotEmpty(array_filter($full['findings'], fn ($f) => $f['code'] === 'ip_mask_mismatch' && str_contains($f['title'], $end)), $end);
        }
        $this->assertNotEmpty(array_filter($full['findings'], fn ($f) => $f['code'] === 'ip_duplicate' && $f['severity'] === 'info' && str_contains($f['title'], '10.70.0.1')));
        $this->assertNotEmpty(array_filter($full['findings'], fn ($f) => $f['code'] === 'bgp_one_sided' && str_contains($f['recommendation'], 'Add the neighbor 10.0.115.2 on r1')));
        $this->assertNotEmpty(array_filter($full['findings'], fn ($f) => $f['code'] === 'ospf_lsdb_mismatch' && str_contains($f['title'], 'r18: 12 LSAs')));
        $this->assertNotEmpty(array_filter($full['findings'], fn ($f) => $f['code'] === 'ip_duplicate' && $f['severity'] === 'info' && str_contains($f['title'], '10.209.0.2')));

        foreach (array_keys($net->devices) as $device) {
            $result = $service->analyze(self::params($device));
            unset($result['filter']);
            $this->assertSame($net->analyzer()->analyze($device), $result, "the view of device $device");
        }
    }

    public function testADeviceViewKeepsOnlyTheAddressesItsChecksNeed(): void
    {
        $net = self::busy();
        $service = new RoutingService(new FakeRoutingRepository($net), new PluginSettings([]));
        $all = array_keys($net->devices);
        $kept = function (int $device) use ($service, $net, $all): array {
            return (function () use ($device, $net, $all) {
                return iterator_to_array(self::only($net->addresses, ...$this->focus($device, $all)), false);
            })->call($service);
        };

        $r3 = $kept(3);
        $this->assertSame([], array_filter($r3, fn ($row) => $row['device_id'] === 12 && str_starts_with($row['address'], '172.20.')), 'custA-r2 is unrelated to r3');
        $this->assertCount(count(array_filter($net->addresses, fn ($row) => $row['device_id'] === 4)), array_filter($r3, fn ($row) => $row['device_id'] === 4), 'r4 is its neighbor');
        $this->assertNotEmpty(array_filter($r3, fn ($row) => $row['address'] === '10.0.12.1'), 'the addresses of OSPF neighbors');
        $this->assertLessThanOrEqual(count($net->addresses) - 30, count($r3), 'custA-r2\'s VLANs are left out');

        $custA = $kept(11);
        $this->assertCount(30, array_filter($custA, fn ($row) => str_starts_with($row['address'], '172.20.')), 'custA-r2 is its neighbor');
    }
}
