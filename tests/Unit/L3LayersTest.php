<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Support\Cidr;
use Agoodley\LibrenmsTopology\Topology\L3Builder;
use PHPUnit\Framework\TestCase;

/**
 * The overlay layers of the L3 map beyond OSPF and BGP (OSPFv3, IS-IS, IPsec, MPLS LSPs and pseudowires)
 * and the far ends every overlay shares: stubs of devices outside the map, external nodes by address.
 *
 *   pe1 (1) --- p1 (3) --- pe2 (2)        fw1 (4) ~~ IPsec ~~ fw2 (6)
 *                 |
 *                pe3 (5)
 */
class L3LayersTest extends TestCase
{
    private const NAMES = [1 => 'pe1', 2 => 'pe2', 3 => 'p1', 4 => 'fw1', 5 => 'pe3', 6 => 'fw2'];

    private static function devices(): array
    {
        $devices = [];
        foreach (self::NAMES as $id => $name) {
            $devices[$id] = ['device_id' => $id, 'display' => $name, 'hostname' => $name, 'status' => 1, 'role' => $id === 4 || $id === 6 ? 'firewall' : 'router',
                'os' => 'iosxr'];
        }

        return $devices;
    }

    private static function address(int $deviceId, int $portId, string $name, string $ip, int $prefix, string $type = 'ethernetCsmacd'): array
    {
        return ['device_id' => $deviceId, 'port_id' => $portId, 'ifName' => $name, 'ifDescr' => $name, 'ifOperStatus' => 'up',
            'ifType' => $type, 'address' => $ip, 'prefixlen' => $prefix, 'vrf' => ''];
    }

    private static function addresses(): array
    {
        $rows = [];
        foreach ([1 => '10.255.0.1', 2 => '10.255.0.2', 3 => '10.255.0.3', 5 => '10.255.0.5'] as $id => $loopback) {
            $rows[] = self::address($id, $id * 10, 'Loopback0', $loopback, 32, 'softwareLoopback');
        }

        return array_merge($rows, [
            self::address(1, 11, 'GigabitEthernet0/0/0/1', '10.0.13.1', 30),
            self::address(3, 31, 'GigabitEthernet0/0/0/1', '10.0.13.2', 30),
            self::address(2, 21, 'GigabitEthernet0/0/0/1', '10.0.23.1', 30),
            self::address(3, 32, 'GigabitEthernet0/0/0/2', '10.0.23.2', 30),
            self::address(5, 51, 'GigabitEthernet0/0/0/1', '10.0.53.1', 30),
            self::address(3, 33, 'GigabitEthernet0/0/0/3', '10.0.53.2', 30),
            self::address(4, 41, 'outside', '198.51.100.1', 29),
            self::address(6, 61, 'wan1', '203.0.113.1', 29),
        ]);
    }

    /**
     * A device the user may see that is not one of the map's devices (TopologyRepository::devices() row).
     */
    private static function other(int $id, string $name, string $role = 'router'): array
    {
        return ['device_id' => $id, 'display' => $name, 'hostname' => $name, 'status' => 1, 'role' => $role, 'os' => 'iosxe', 'location' => 'Branch'];
    }

    private static function build(array $layers, array $known = [], array $options = [], array $ospf = [], array $bgp = []): array
    {
        return (new L3Builder(self::devices(), self::addresses(), $ospf, $bgp, $layers, $known))->build($options + ['hideStubs' => false]);
    }

    /**
     * @return array<int, array> edges of one kind
     */
    private static function edges(array $result, string $kind): array
    {
        return array_values(array_filter($result['edges'], fn ($e) => $e['kind'] === $kind));
    }

    /**
     * @return array<string, array> edges of one kind by "from-to"
     */
    private static function pairs(array $result, string $kind): array
    {
        return array_column(array_map(fn ($e) => $e + ['pair' => $e['from'] . '-' . $e['to']], self::edges($result, $kind)), null, 'pair');
    }

    private static function nodes(array $result): array
    {
        return array_column($result['nodes'], null, 'id');
    }

    private static function ospfv3(int $device, string $rid, string $state, string $address = 'fe80::1', string $context = ''): array
    {
        return ['device_id' => $device, 'port_id' => null, 'router_id' => $rid, 'ospfv3NbrRtrId' => ip2long($rid), 'ospfv3NbrAddress' => $address,
            'ospfv3NbrState' => $state, 'context_name' => $context];
    }

    private static function isis(int $device, string $sysId, string $ip, string $state = 'up', string $admin = 'on'): array
    {
        return ['device_id' => $device, 'port_id' => $device * 10 + 1, 'ifName' => 'GigabitEthernet0/0/0/1', 'isisISAdjState' => $state,
            'isisISAdjNeighSysType' => 'L2', 'isisISAdjNeighSysID' => $sysId, 'isisISAdjAreaAddress' => '49.00.01', 'isisISAdjIPAddrAddress' => $ip,
            'isisCircAdminState' => $admin];
    }

    private static function tunnel(int $device, string $peer, string $status, string $name = 'vpn'): array
    {
        return ['device_id' => $device, 'peer_addr' => $peer, 'local_addr' => '', 'tunnel_name' => $name, 'tunnel_status' => $status];
    }

    private static function lsp(int $device, string $name, string $to, string $oper = 'inService', string $admin = 'inService'): array
    {
        return ['device_id' => $device, 'mplsLspName' => $name, 'mplsLspFromAddr' => '10.255.0.' . $device, 'mplsLspToAddr' => $to,
            'mplsLspAdminState' => $admin, 'mplsLspOperState' => $oper, 'mplsLspType' => 'dynamic', 'mplsLspFastReroute' => 'true',
            'mplsLspTransitions' => 3, 'mplsLspConfiguredPaths' => 2, 'mplsLspOperationalPaths' => 1];
    }

    /**
     * A pseudowire row as LibreNMS stores it: peer_device_id 0 and peer_ldp_id capped at 2147483647 from 128.0.0.0 on,
     * pw_local_mtu / pw_peer_mtu never filled; ifMtu is the attachment circuit's (joined from ports).
     */
    private static function pw(int $device, int $vc, int $ifMtu, string|int $peer = 2147483647): array
    {
        return ['device_id' => $device, 'port_id' => $device * 100 + $vc % 100, 'ifName' => 'GigabitEthernet0/0/0/9.' . $vc, 'ifMtu' => $ifMtu,
            'peer_device_id' => 0, 'peer_ldp_id' => is_string($peer) ? ip2long($peer) : $peer, 'cpwVcID' => $vc, 'pw_type' => 'ethernet',
            'pw_psntype' => 'mpls', 'pw_local_mtu' => 0, 'pw_peer_mtu' => 0, 'pw_descr' => "customer $vc"];
    }

    public function testOspfv3NeighboursAreFoundByRouterId(): void
    {
        $result = self::build(['ospfv3' => [
            // pe1 and p1 see each other (router ID = loopback); fe80::1 is on every router and must not decide
            self::ospfv3(1, '10.255.0.3', 'full'),
            self::ospfv3(3, '10.255.0.1', 'full'),
            // p1 -> pe2 by a router ID that is no address, known from pe2's OSPFv3 instance; pe2 is stuck
            self::ospfv3(3, '192.0.2.2', 'full'),
            self::ospfv3(2, '10.255.0.3', 'exchangeStart'),
            // an unmonitored neighbour
            self::ospfv3(5, '10.9.9.9', 'full', '2001:db8:9::9'),
        ]], ['routerIds' => ['|192.0.2.2' => 2]]);

        $edges = self::pairs($result, 'ospfv3');
        $this->assertCount(3, $edges);
        $this->assertSame('up', $edges['d:1-d:3']['status']);
        $this->assertSame(['10.255.0.3', '10.255.0.1'], $edges['d:1-d:3']['routerIds']);
        $this->assertSame(['exchangestart', 'down'], [$edges['d:3-d:2']['state'], $edges['d:3-d:2']['status']]);
        $external = self::nodes($result)['x:10.9.9.9'];
        $this->assertSame(['10.9.9.9', ['OSPFv3 neighbor'], ['10.9.9.9', '2001:db8:9::9']], [$external['label'], $external['seen'], $external['addresses']]);
        $this->assertSame(3, $result['stats']['ospfv3']);
        $this->assertSame(1, $result['stats']['down']);
    }

    public function testOspfv3RouterIdsAreMatchedInTheirContext(): void
    {
        $result = self::build(['ospfv3' => [
            // a CE in VRF CUST_A with router ID 1.1.1.1 is not p1, whose global instance has that router ID
            self::ospfv3(1, '1.1.1.1', 'full', 'fe80::c1', 'CUST_A'),
            self::ospfv3(2, '1.1.1.1', 'full'),
            // two instances have 10.255.0.5 (pe3's loopback): neither is picked, nor is the loopback tried
            self::ospfv3(3, '10.255.0.5', 'full'),
            // no instance has 10.255.0.1: pe1's loopback
            self::ospfv3(5, '10.255.0.1', 'full'),
        ]], ['routerIds' => ['|1.1.1.1' => 3], 'ambiguousRouterIds' => ['|10.255.0.5' => true]]);

        $this->assertSame(['d:1-x:CUST_A|1.1.1.1', 'd:2-d:3', 'd:3-x:10.255.0.5', 'd:5-d:1'], array_keys(self::pairs($result, 'ospfv3')));
        $ce = self::nodes($result)['x:CUST_A|1.1.1.1'];
        $this->assertSame(["1.1.1.1\nCUST_A", 'CUST_A'], [$ce['label'], $ce['context']]);
    }

    public function testOspfv3GlobalAddressIsTheLastResort(): void
    {
        // no router ID match: the neighbour's global address, never a link-local one (the same on many routers)
        $result = self::build(['ospfv3' => [
            self::ospfv3(1, '172.31.0.3', 'full', '10.0.13.2'),
            self::ospfv3(2, '172.31.0.4', 'full', '2001:db8::4'),
            self::ospfv3(5, '172.31.0.5', 'full', 'fe80::5'),
        ]], ['addresses' => ['2001:db8::4' => 3, 'fe80::5' => 1]]);

        $this->assertSame(['d:1-d:3', 'd:2-d:3', 'd:5-x:172.31.0.5'], array_keys(self::pairs($result, 'ospfv3')));
    }

    public function testIsisAdjacenciesByAddressOrSystemId(): void
    {
        $result = self::build(['isis' => [
            self::isis(1, '01.00.25.50.00.03', '10.0.13.2'),
            self::isis(3, '01.00.25.50.00.01', '10.0.13.1'),
            // no address: the system ID encodes pe2's loopback 10.255.0.2
            self::isis(3, '0102.5500.0002', '', 'initializing'),
            self::isis(5, '00.1b.2c.3d.4e.5f', ''),
            self::isis(2, '01.00.25.50.00.03', '10.0.23.2', 'down', 'off'),
        ]]);

        $edges = self::pairs($result, 'isis');
        $this->assertSame(['d:1-d:3', 'd:3-d:2', 'd:5-x:001b.2c3d.4e5f'], array_keys($edges));
        $this->assertSame('up', $edges['d:1-d:3']['status']);
        $this->assertSame(['0100.2550.0003', '0100.2550.0001'], $edges['d:1-d:3']['systemIds']);
        $this->assertSame(['L2'], $edges['d:1-d:3']['levels']);
        $this->assertSame('Gi0/0/0/1', $edges['d:1-d:3']['interfaces'][0]['name']);
        // p1 still initializing toward pe2, whose circuit is shut down: the first non-up state wins
        $this->assertSame('initializing', $edges['d:3-d:2']['state']);
        $this->assertSame('down', $edges['d:3-d:2']['status']);
        $external = self::nodes($result)['x:001b.2c3d.4e5f'];
        $this->assertSame(['001b.2c3d.4e5f', ['IS-IS neighbor'], ''], [$external['label'], $external['seen'], $external['address']]);
    }

    public function testIsisTrustsNoLinkLocalAddressAndOnlyLoopbackSystemIds(): void
    {
        $result = self::build(['isis' => [
            // fe80::1 is on pe2 among the devices the user may see, and on many others
            self::isis(3, '00.1b.2c.3d.4e.5f', 'fe80::1'),
            // 0100.0001.3001 reads 10.0.13.1: pe1's /30 interface address, no loopback
            self::isis(3, '0100.0001.3001', ''),
            // pe1 found p1 by address, so pe3's adjacency to that system ID without an address is p1 too
            self::isis(1, '01.00.25.50.00.03', '10.0.13.2'),
            self::isis(5, '01.00.25.50.00.03', ''),
            // a loopback of a device outside the map
            self::isis(3, '0100.0000.0099', ''),
        ]], ['addresses' => ['fe80::1' => 2], 'loopbacks' => ['10.0.0.99' => 7], 'devices' => [7 => self::other(7, 'p7')]]);

        $this->assertSame(['d:3-x:001b.2c3d.4e5f', 'd:3-x:0100.0001.3001', 'd:1-d:3', 'd:5-d:3', 'd:3-d:7'], array_keys(self::pairs($result, 'isis')));
        $this->assertTrue(self::nodes($result)['d:7']['outside']);
    }

    public function testIsisSystemIdFormats(): void
    {
        $this->assertSame('0100.0000.0001', L3Builder::isisSystemId('01.00.00.00.00.01'));
        $this->assertSame('0100.0000.0001', L3Builder::isisSystemId('0100.0000.0001'));
        $this->assertSame('10.0.0.1', L3Builder::isisSystemIdAddress('0100.0000.0001'));
        $this->assertSame('192.168.1.1', L3Builder::isisSystemIdAddress('1921.6800.1001'));
        $this->assertNull(L3Builder::isisSystemIdAddress('001b.2c3d.4e5f'), 'hex digits');
        $this->assertNull(L3Builder::isisSystemIdAddress('2560.0000.0001'), 'octet above 255');
        $this->assertNull(L3Builder::isisSystemIdAddress('0000.0000.0000'));
    }

    public function testIpsecTunnelsToDevicesStubsAndExternalPeers(): void
    {
        $result = self::build(['ipsec' => [
            self::tunnel(4, '203.0.113.1', 'active', 'to-fw2'),
            self::tunnel(6, '198.51.100.1', 'established', 'to-fw1'),
            // a device the user may see that is not on this map: a stub with its name
            self::tunnel(4, '192.0.2.77', 'active'),
            // an unknown peer (or one on a device the user may not see): only its address is shown
            self::tunnel(4, '192.0.2.50', 'destroy', 'partner'),
        ]], ['addresses' => ['192.0.2.77' => 99], 'devices' => [99 => self::other(99, 'fw-branch', 'firewall')]]);

        $edges = self::pairs($result, 'ipsec');
        $this->assertSame(['d:4-d:6', 'd:4-d:99', 'd:4-x:192.0.2.50'], array_keys($edges));
        $this->assertSame('up', $edges['d:4-d:6']['status']);
        $this->assertCount(2, $edges['d:4-d:6']['tunnels']);
        $this->assertSame(['destroy', 'down'], [$edges['d:4-x:192.0.2.50']['state'], $edges['d:4-x:192.0.2.50']['status']]);

        $nodes = self::nodes($result);
        $this->assertSame(['fw-branch', 'firewall', true, 'Branch'], [$nodes['d:99']['name'], $nodes['d:99']['role'], $nodes['d:99']['outside'], $nodes['d:99']['location']]);
        $peer = $nodes['x:192.0.2.50'];
        $this->assertSame(['external', 'firewall', '192.0.2.50', ['IPsec peer']], [$peer['type'], $peer['role'], $peer['name'], $peer['seen']]);
        $this->assertSame([6, 1], [$result['stats']['devices'], $result['stats']['outside']], 'the stub is no device of the map');
    }

    public function testLspsWithDirectionsAndStates(): void
    {
        $result = self::build(['mpls' => [
            self::lsp(1, 'pe1-pe2', '10.255.0.2'),
            self::lsp(1, 'pe1-pe2-gold', '10.255.0.2', 'outOfService'),
            self::lsp(2, 'pe2-pe1', '10.255.0.1'),
            self::lsp(1, 'pe1-pe3', '10.255.0.5', 'outOfService', 'outOfService'),
            self::lsp(5, 'pe3-far', '10.77.0.1'),
            self::lsp(5, 'p2mp', '0.0.0.0'),
        ]]);

        $edges = self::edges($result, 'mpls');
        $this->assertCount(3, $edges);
        [$pe1pe2, $pe1pe3, $far] = $edges;
        $this->assertSame(['to, from', '1 of 3 down', 'down'], [$pe1pe2['arrows'], $pe1pe2['state'], $pe1pe2['status']]);
        $this->assertSame(['pe1-pe2', 'pe1-pe2-gold', 'pe2-pe1'], array_column($pe1pe2['lsps'], 'name'));
        $this->assertSame('1/2', $pe1pe2['lsps'][0]['paths']);
        $this->assertSame(['to', 'disabled', 'disabled'], [$pe1pe3['arrows'], $pe1pe3['state'], $pe1pe3['status']]);
        $this->assertSame(['d:5', 'x:10.77.0.1', 'up'], [$far['from'], $far['to'], $far['status']]);
        $this->assertSame(5, $result['stats']['mpls']);
        $this->assertSame([], $result['limits']);
    }

    public function testAnLspFullMeshIsCappedKeepingThePairsWithLspsDown(): void
    {
        // 33 PEs: 528 pairs, over the limit of 500
        $devices = [];
        $addresses = [];
        $lsps = [];
        foreach (range(1, 33) as $id) {
            $devices[$id] = ['device_id' => $id, 'display' => "pe$id", 'hostname' => "pe$id", 'status' => 1, 'role' => 'router'];
            $addresses[] = self::address($id, $id, 'Loopback0', "10.254.0.$id", 32, 'softwareLoopback');
            $addresses[] = self::address($id, 100 + $id, 'GigabitEthernet0/0/0/0', "10.253.0.$id", 24);
        }
        foreach (range(1, 33) as $head) {
            foreach (range(1, 33) as $tail) {
                if ($head !== $tail) {
                    // pe8 - pe9 sorts last: kept only because an LSP is down
                    $lsps[] = self::lsp($head, "pe$head-pe$tail", "10.254.0.$tail", $head === 8 && $tail === 9 ? 'outOfService' : 'inService');
                }
            }
        }
        $result = (new L3Builder($devices, $addresses, [], [], ['mpls' => $lsps]))->build();

        $edges = self::pairs($result, 'mpls');
        $this->assertCount(L3Builder::MAX_LSP_EDGES, $edges);
        $this->assertSame(['shown' => 500, 'total' => 528], $result['limits']['mpls']);
        $this->assertSame('down', $edges['d:8-d:9']['status']);
        $this->assertSame(1000, $result['stats']['mpls']);
    }

    public function testPseudowiresArePairedByVcIdWhenLibreNmsLostThePeer(): void
    {
        $result = self::build(['pw' => [
            // pe1 and pe3 (LDP IDs above 128.0.0.0, stored as 2147483647): VC 100 and 200 between them
            self::pw(1, 100, 1500),
            self::pw(5, 100, 1500),
            self::pw(1, 200, 1500),
            self::pw(5, 200, 1500, 0),
            // a VC ID on three PEs: not paired at random
            self::pw(1, 300, 1500),
            self::pw(2, 300, 1500),
            self::pw(5, 300, 1500),
            // VC 400 on pe3 and pe1, which points to pe3 by a usable LDP ID, and on pe2, which points to p1
            self::pw(5, 400, 1500),
            self::pw(1, 400, 1500, '10.255.0.5'),
            self::pw(2, 400, 1500, '10.255.0.3'),
        ]]);

        $edges = self::pairs($result, 'pw');
        $this->assertSame(['d:1-d:5', 'd:1-xu:pw:1', 'd:2-xu:pw:2', 'd:5-xu:pw:5', 'd:2-d:3'], array_keys($edges));
        $this->assertSame([100, 200, 400], array_column($edges['d:1-d:5']['vcs'], 'vc'));
        $this->assertSame(['Gi0/0/0/9.100', 'Gi0/0/0/9.100'], [$edges['d:1-d:5']['vcs'][0]['a']['port'], $edges['d:1-d:5']['vcs'][0]['b']['port']]);
        $this->assertSame([300], array_column($edges['d:1-xu:pw:1']['vcs'], 'vc'));
        $this->assertSame([300], array_column($edges['d:5-xu:pw:5']['vcs'], 'vc'));
        // pe2's VC 400 goes to p1, which has no such VC: the end is missing
        $this->assertNull($edges['d:2-d:3']['vcs'][0]['b']);

        $unknown = self::nodes($result)['xu:pw:1'];
        $this->assertSame(['external', 'Unknown pseudowire peers of pe1'], [$unknown['type'], $unknown['name']]);
        $this->assertNotContains('x:127.255.255.255', array_keys(self::nodes($result)));
    }

    public function testPseudowireAttachmentCircuitMtusAreCompared(): void
    {
        $devices = self::devices();
        $devices[5]['os'] = 'iosxe';
        $layers = ['pw' => [
            // VC 100 agrees; VC 200: 9000 against 1500; VC 300: 1514 on IOS XR is 1500 on IOS XE (header counted)
            self::pw(1, 100, 1500), self::pw(5, 100, 1500),
            self::pw(1, 200, 9000), self::pw(5, 200, 1500),
            self::pw(1, 300, 1514), self::pw(5, 300, 1500),
            // a VC to an unmonitored PE: one end only; an AC whose MTU is unknown
            self::pw(2, 500, 1500, '10.66.0.1'),
            self::pw(2, 501, 0, '10.66.0.1'),
        ]];
        $result = (new L3Builder($devices, self::addresses(), [], [], $layers))->build();

        $edges = self::pairs($result, 'pw');
        $this->assertSame(['d:1-d:5', 'd:2-x:10.66.0.1'], array_keys($edges));
        $pe1pe3 = $edges['d:1-d:5'];
        $this->assertSame(['MTU mismatch', 'warning', 1], [$pe1pe3['state'], $pe1pe3['status'], $pe1pe3['mtu_mismatch']]);
        $this->assertSame([[1500, 1500, false], [9000, 1500, true], [1514, 1500, false]],
            array_map(fn ($vc) => [$vc['mtu_a'], $vc['mtu_b'], $vc['mismatch']], $pe1pe3['vcs']));

        $far = $edges['d:2-x:10.66.0.1'];
        $this->assertSame(['not polled', 'unknown'], [$far['state'], $far['status']], 'LibreNMS keeps no pseudowire state');
        $this->assertSame([[1500, null, false], [null, null, false]], array_map(fn ($vc) => [$vc['mtu_a'], $vc['mtu_b'], $vc['mismatch']], $far['vcs']));
        $this->assertSame(['pw' => 5, 'mtu' => 1], array_intersect_key($result['stats'], ['pw' => 0, 'mtu' => 0]));
    }

    public function testPseudowireEndOutsideTheMapComesFromItsOwnRow(): void
    {
        // pe1's VC 700 to a PE in another location: paired through that PE's row, whose AC has another MTU
        $known = ['pseudowires' => [self::pw(8, 700, 9000)], 'devices' => [8 => self::other(8, 'pe8')]];
        $edge = self::pairs(self::build(['pw' => [self::pw(1, 700, 1500)]], $known), 'pw')['d:1-d:8'];
        $this->assertSame([1500, 9000, true], [$edge['vcs'][0]['mtu_a'], $edge['vcs'][0]['mtu_b'], $edge['vcs'][0]['mismatch']]);

        // with pe3 having VC 700 too there are two candidates: no pairing
        $result = self::build(['pw' => [self::pw(1, 700, 1500), self::pw(5, 700, 1500)]], $known);
        $this->assertSame(['d:1-xu:pw:1', 'd:5-xu:pw:5'], array_keys(self::pairs($result, 'pw')));
    }

    public function testLdpIdsLibreNmsCannotStoreAreUnknown(): void
    {
        $lookups = L3Builder::layerLookups(['pw' => [self::pw(1, 1, 1500), self::pw(1, 2, 1500, 0), self::pw(1, 3, 1500, '10.255.0.5'), self::pw(1, 4, 1500, -5)]]);

        $this->assertSame(['10.255.0.5'], $lookups['addresses']);
        $this->assertSame([1, 2, 3, 4], $lookups['vcs']);
    }

    public function testOneExternalNodePerAddressAcrossLayers(): void
    {
        // an unmonitored ASBR 10.77.0.1 (OSPF router ID; interface 10.0.37.7): OSPF neighbour, LSP tail, pseudowire and IPsec peer
        $ospf = [['device_id' => 3, 'ospfNbrIpAddr' => '10.0.37.7', 'ospfNbrRtrId' => '10.77.0.1', 'ospfNbrState' => 'full', 'context_name' => '']];
        $result = self::build([
            'mpls' => [self::lsp(1, 'to-asbr', '10.77.0.1')],
            'pw' => [self::pw(2, 7, 1500, '10.77.0.1')],
            'ipsec' => [self::tunnel(4, '10.0.37.7', 'active'), self::tunnel(6, '192.0.2.9', 'active')],
        ], [], [], $ospf);

        $externals = array_values(array_filter($result['nodes'], fn ($n) => $n['type'] === 'external'));
        $this->assertSame(['x:10.77.0.1', 'x:192.0.2.9'], array_column($externals, 'id'));
        $this->assertSame(['OSPF neighbor', 'IPsec peer', 'LSP tail', 'Pseudowire peer'], $externals[0]['seen']);
        $this->assertSame(['10.77.0.1', '10.0.37.7'], $externals[0]['addresses']);
        $this->assertSame(['router', 'firewall'], array_column($externals, 'role'), 'a firewall icon only when nothing but IPsec saw it');
        foreach (['ospf' => 'd:3', 'mpls' => 'd:1', 'pw' => 'd:2', 'ipsec' => 'd:4'] as $kind => $from) {
            $this->assertContains($from . '-x:10.77.0.1', array_keys(self::pairs($result, $kind)), $kind);
        }
    }

    public function testFarEndsOutsideTheMapAreStubsOnEveryLayer(): void
    {
        $ospf = [['device_id' => 3, 'ospfNbrIpAddr' => '10.0.13.1', 'ospfNbrRtrId' => '10.255.0.1', 'ospfNbrState' => 'full', 'context_name' => '']];
        $bgp = [
            ['device_id' => 3, 'bgpPeerIdentifier' => '10.255.0.1', 'bgpPeerRemoteAs' => 65000, 'bgpPeerState' => 'established'],
            ['device_id' => 3, 'bgpPeerIdentifier' => '172.20.0.8', 'bgpPeerRemoteAs' => 65008, 'bgpPeerState' => 'established'],
            ['device_id' => 3, 'bgpPeerIdentifier' => '203.0.113.99', 'bgpPeerRemoteAs' => 64999, 'bgpPeerState' => 'established'],
        ];
        $layers = [
            'isis' => [self::isis(3, '01.00.25.50.00.01', '10.0.13.1')],
            'mpls' => [self::lsp(3, 'p1-pe1', '10.255.0.1'), self::lsp(3, 'p1-pe8', '172.20.0.8')],
        ];
        // centered on pe2 with one hop: p1 is drawn, pe1 is two hops away and pe8 in another location
        $result = self::build($layers, ['addresses' => ['172.20.0.8' => 8], 'devices' => [8 => self::other(8, 'pe8')]], ['root' => 2, 'depth' => 1], $ospf, $bgp);

        foreach (['ospf', 'isis'] as $kind) {
            $this->assertSame(['d:3-d:1'], array_keys(self::pairs($result, $kind)), $kind);
        }
        $this->assertSame(['d:3-d:1', 'd:3-d:8'], array_keys(self::pairs($result, 'mpls')));
        $this->assertSame(['d:3-d:1', 'd:3-d:8', 'd:3-as:64999'], array_keys(self::pairs($result, 'bgp')), 'only peers no device owns go to the AS cloud');

        $nodes = self::nodes($result);
        $this->assertSame([true, true], [$nodes['d:1']['outside'], $nodes['d:8']['outside']]);
        $this->assertArrayNotHasKey('outside', $nodes['d:3']);
        $this->assertSame([2, 2], [$result['stats']['devices'], $result['stats']['outside']]);

        // rows of a device off the map are not drawn; a layer can be switched off
        $this->assertSame([], self::edges(self::build(['isis' => [self::isis(1, '', '10.0.13.2')]], [], ['root' => 2, 'depth' => 1]), 'isis'));
        $this->assertSame([], self::edges(self::build(['ipsec' => [self::tunnel(4, '203.0.113.1', 'active')]], [], ['ipsec' => false]), 'ipsec'));
    }

    public function testLookupsCollectFarEndsNotOnTheMap(): void
    {
        $layers = [
            'ospfv3' => [self::ospfv3(1, '10.255.0.3', 'full'), self::ospfv3(1, '10.255.0.9', 'full', '2001:DB8::9', 'CUST')],
            'isis' => [self::isis(1, '01.00.00.00.00.01', '10.0.13.2'), self::isis(1, '00.1b.2c.3d.4e.5f', 'fe80::2')],
            'ipsec' => [self::tunnel(4, '192.0.2.50', 'active')],
            'mpls' => [self::lsp(1, 'x', '10.255.0.2'), self::lsp(1, 'y', '0.0.0.0')],
            'pw' => [['peer_device_id' => 5] + self::pw(1, 7, 1500, '10.255.0.5'), self::pw(1, 8, 1500)],
        ];
        $ospf = [['device_id' => 1, 'ospfNbrIpAddr' => '10.0.13.2', 'ospfNbrRtrId' => '10.255.7.7', 'context_name' => '']];
        $bgp = [['device_id' => 1, 'bgpPeerIdentifier' => '203.0.113.9']];

        $all = L3Builder::layerLookups($layers, $ospf, $bgp);
        $this->assertSame(['|10.255.7.7', '|10.255.0.3', 'CUST|10.255.0.9'], $all['routerIds']);
        $this->assertEqualsCanonicalizing(['10.0.13.2', '10.255.7.7', '203.0.113.9', '10.255.0.3', '10.255.0.9', '2001:db8::9', '10.0.0.1',
            '192.0.2.50', '10.255.0.2', '10.255.0.5'], $all['addresses'], 'canonical, without link-local and 0.0.0.0');
        $this->assertSame([7, 8], $all['vcs']);
        $this->assertSame([5], $all['devices']);

        $lookups = (new L3Builder(self::devices(), self::addresses(), $ospf, $bgp, $layers))->lookups();
        $this->assertEqualsCanonicalizing(['10.255.7.7', '203.0.113.9', '10.255.0.9', '2001:db8::9', '10.0.0.1', '192.0.2.50'], $lookups['addresses'],
            'the addresses of the map are known already');
    }

    public function testAdjacencyStateIsTheWorseSideWhateverTheRowOrder(): void
    {
        $ospf = fn (int $device, string $state) => ['device_id' => $device, 'ospfNbrIpAddr' => $device === 1 ? '10.0.13.2' : '10.0.13.1',
            'ospfNbrRtrId' => $device === 1 ? '10.255.0.3' : '10.255.0.1', 'ospfNbrState' => $state, 'context_name' => ''];

        foreach ([['full', 'exchangeStart', 'exchangestart', 'down'], ['twoWay', 'init', 'init', 'down'], ['full', 'twoWay', 'twoway', 'up']] as [$one, $two, $state, $status]) {
            foreach ([[$ospf(1, $one), $ospf(3, $two)], [$ospf(3, $two), $ospf(1, $one)]] as $rows) {
                $edges = self::edges((new L3Builder(self::devices(), self::addresses(), $rows))->build(['bgp' => false]), 'ospf');
                $this->assertSame([$state, $status], [$edges[0]['state'], $edges[0]['status']], "OSPF $one / $two");

                $v3 = array_map(fn ($row) => self::ospfv3($row['device_id'], $row['ospfNbrRtrId'], $row['ospfNbrState']), $rows);
                $edges = self::edges(self::build(['ospfv3' => $v3]), 'ospfv3');
                $this->assertSame([$state, $status], [$edges[0]['state'], $edges[0]['status']], "OSPFv3 $one / $two");
            }
        }
    }

    public function testCanonicalAddresses(): void
    {
        $this->assertSame('2001:db8::1', Cidr::canonical('2001:0DB8:0000::0001'));
        $this->assertSame('fe80::1', Cidr::canonical('fe80::1%eth0'));
        $this->assertSame('10.0.0.1', Cidr::canonical(' 10.0.0.1 '));
        $this->assertNull(Cidr::canonical('pe1.example.net'));
        $this->assertNull(Cidr::canonical(''));
    }
}
