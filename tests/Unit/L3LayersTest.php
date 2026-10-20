<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Support\Cidr;
use Agoodley\LibrenmsTopology\Topology\L3Builder;
use PHPUnit\Framework\TestCase;

/**
 * The overlay layers of the L3 map beyond OSPF and BGP: OSPFv3, IS-IS, IPsec, MPLS LSPs and pseudowires.
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
            $devices[$id] = ['device_id' => $id, 'display' => $name, 'hostname' => $name, 'status' => 1, 'role' => $id === 4 || $id === 6 ? 'firewall' : 'router'];
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

    private static function build(array $layers, array $known = [], array $options = []): array
    {
        return (new L3Builder(self::devices(), self::addresses(), [], [], $layers, $known))->build($options + ['hideStubs' => false]);
    }

    /**
     * @return array<int, array> edges of one kind
     */
    private static function edges(array $result, string $kind): array
    {
        return array_values(array_filter($result['edges'], fn ($e) => $e['kind'] === $kind));
    }

    private static function ospfv3(int $device, string $rid, string $state, string $address = 'fe80::1', ?int $portId = null): array
    {
        return ['device_id' => $device, 'port_id' => $portId, 'router_id' => $rid, 'ospfv3NbrRtrId' => ip2long($rid), 'ospfv3NbrAddress' => $address,
            'ospfv3NbrState' => $state, 'context_name' => ''];
    }

    public function testOspfv3NeighboursAreFoundByRouterId(): void
    {
        $result = self::build(['ospfv3' => [
            // pe1 and p1 see each other (router ID = loopback); fe80::1 is on every router and must not decide
            self::ospfv3(1, '10.255.0.3', 'full'),
            self::ospfv3(3, '10.255.0.1', 'full'),
            // p1 -> pe2 by a router ID that is no address, known from pe2's OSPFv3 instance; pe2 is stuck
            self::ospfv3(3, '192.0.2.2', 'full'),
            self::ospfv3(2, '10.255.0.3', 'exStart'),
            // an unmonitored neighbour
            self::ospfv3(5, '10.9.9.9', 'full', '2001:db8:9::9'),
        ]], ['routerIds' => ['192.0.2.2' => 2]]);

        $edges = array_column(array_map(fn ($e) => $e + ['pair' => $e['from'] . '-' . $e['to']], self::edges($result, 'ospfv3')), null, 'pair');
        $this->assertCount(3, $edges);
        $this->assertSame('up', $edges['d:1-d:3']['status']);
        $this->assertSame(['10.255.0.3', '10.255.0.1'], $edges['d:1-d:3']['routerIds']);
        $this->assertSame('exstart', $edges['d:3-d:2']['state']);
        $this->assertSame('down', $edges['d:3-d:2']['status']);
        $this->assertSame('OSPF 10.9.9.9', array_column($result['nodes'], null, 'id')['x:10.9.9.9']['label']);
        $this->assertSame(3, $result['stats']['ospfv3']);
        $this->assertSame(1, $result['stats']['down']);
    }

    public function testOspfv3GlobalAddressAndPortAreLastResorts(): void
    {
        // no router ID match: the global neighbour address, then the port LibreNMS found for it
        $result = self::build(['ospfv3' => [
            self::ospfv3(1, '172.31.0.3', 'full', '10.0.13.2'),
            self::ospfv3(2, '172.31.0.4', 'full', '2001:db8::4', 999),
            self::ospfv3(5, '172.31.0.5', 'full', 'fe80::5', 998),
        ]], ['ports' => [999 => 3, 998 => 1]]);

        $pairs = array_map(fn ($e) => $e['from'] . '-' . $e['to'], self::edges($result, 'ospfv3'));
        $this->assertSame(['d:1-d:3', 'd:2-d:3', 'd:5-x:172.31.0.5'], $pairs, 'a port found for a link-local address is not trusted');
    }

    public function testIsisAdjacenciesByAddressOrSystemId(): void
    {
        $isis = fn (int $device, string $sysId, string $ip, string $state = 'up', string $admin = 'on') => ['device_id' => $device, 'port_id' => $device * 10 + 1,
            'ifName' => 'GigabitEthernet0/0/0/1', 'isisISAdjState' => $state, 'isisISAdjNeighSysType' => 'L2', 'isisISAdjNeighSysID' => $sysId,
            'isisISAdjAreaAddress' => '49.00.01', 'isisISAdjIPAddrAddress' => $ip, 'isisCircAdminState' => $admin];

        $result = self::build(['isis' => [
            $isis(1, '01.00.25.50.00.03', '10.0.13.2'),
            $isis(3, '01.00.25.50.00.01', '10.0.13.1'),
            // no address: the system ID encodes pe2's loopback 10.255.0.2
            $isis(3, '0102.5500.0002', '', 'initializing'),
            $isis(5, '00.1b.2c.3d.4e.5f', ''),
            $isis(2, '01.00.25.50.00.03', '10.0.23.2', 'down', 'off'),
        ]]);

        $edges = array_column(array_map(fn ($e) => $e + ['pair' => $e['from'] . '-' . $e['to']], self::edges($result, 'isis')), null, 'pair');
        $this->assertSame(['d:1-d:3', 'd:3-d:2', 'd:5-xi:001b.2c3d.4e5f'], array_keys($edges));
        $this->assertSame('up', $edges['d:1-d:3']['status']);
        $this->assertSame(['0100.2550.0003', '0100.2550.0001'], $edges['d:1-d:3']['systemIds']);
        $this->assertSame(['L2'], $edges['d:1-d:3']['levels']);
        $this->assertSame('Gi0/0/0/1', $edges['d:1-d:3']['interfaces'][0]['name']);
        // p1 still initializing toward pe2, whose circuit is shut down: the first non-up state wins
        $this->assertSame('initializing', $edges['d:3-d:2']['state']);
        $this->assertSame('down', $edges['d:3-d:2']['status']);
        $this->assertSame('IS-IS 001b.2c3d.4e5f', array_column($result['nodes'], null, 'id')['xi:001b.2c3d.4e5f']['label']);
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

    public function testIpsecTunnelsToDevicesAndExternalPeers(): void
    {
        $tunnel = fn (int $device, string $peer, string $status, string $name = 'vpn') => ['device_id' => $device, 'peer_addr' => $peer,
            'local_addr' => '', 'tunnel_name' => $name, 'tunnel_status' => $status];

        $result = self::build(['ipsec' => [
            $tunnel(4, '203.0.113.1', 'active', 'to-fw2'),
            $tunnel(6, '198.51.100.1', 'established', 'to-fw1'),
            // a monitored device the user may see that is not on this map: left out, like OSPF
            $tunnel(4, '192.0.2.77', 'active'),
            // an unknown peer (or one on a device the user may not see): only its address is shown
            $tunnel(4, '192.0.2.50', 'destroy', 'partner'),
        ]], ['addresses' => ['192.0.2.77' => 99]]);

        $edges = self::edges($result, 'ipsec');
        $this->assertCount(2, $edges);
        $this->assertSame(['d:4', 'd:6', 'up'], [$edges[0]['from'], $edges[0]['to'], $edges[0]['status']]);
        $this->assertCount(2, $edges[0]['tunnels']);
        $this->assertSame(['d:4', 'xp:192.0.2.50', 'destroy', 'down'], [$edges[1]['from'], $edges[1]['to'], $edges[1]['state'], $edges[1]['status']]);
        $peer = array_column($result['nodes'], null, 'id')['xp:192.0.2.50'];
        $this->assertSame(['external', 'firewall', 'IPsec peer 192.0.2.50'], [$peer['type'], $peer['role'], $peer['name']]);
    }

    public function testLspsWithDirectionsAndStates(): void
    {
        $lsp = fn (int $device, string $name, string $to, string $oper = 'inService', string $admin = 'inService') => ['device_id' => $device,
            'mplsLspName' => $name, 'mplsLspFromAddr' => '10.255.0.' . $device, 'mplsLspToAddr' => $to, 'mplsLspAdminState' => $admin,
            'mplsLspOperState' => $oper, 'mplsLspType' => 'dynamic', 'mplsLspFastReroute' => 'true', 'mplsLspTransitions' => 3,
            'mplsLspConfiguredPaths' => 2, 'mplsLspOperationalPaths' => 1];

        $result = self::build(['mpls' => [
            $lsp(1, 'pe1-pe2', '10.255.0.2'),
            $lsp(1, 'pe1-pe2-gold', '10.255.0.2', 'outOfService'),
            $lsp(2, 'pe2-pe1', '10.255.0.1'),
            $lsp(1, 'pe1-pe3', '10.255.0.5', 'outOfService', 'outOfService'),
            $lsp(5, 'pe3-far', '10.77.0.1'),
            $lsp(5, 'p2mp', '0.0.0.0'),
        ]]);

        $edges = self::edges($result, 'mpls');
        $this->assertCount(3, $edges);
        [$pe1pe2, $pe1pe3, $far] = $edges;
        $this->assertSame(['to, from', '1 of 3 down', 'down'], [$pe1pe2['arrows'], $pe1pe2['state'], $pe1pe2['status']]);
        $this->assertSame(['pe1-pe2', 'pe1-pe2-gold', 'pe2-pe1'], array_column($pe1pe2['lsps'], 'name'));
        $this->assertSame('1/2', $pe1pe2['lsps'][0]['paths']);
        $this->assertSame(['to', 'disabled', 'disabled'], [$pe1pe3['arrows'], $pe1pe3['state'], $pe1pe3['status']]);
        $this->assertSame(['d:5', 'xm:10.77.0.1', 'up'], [$far['from'], $far['to'], $far['status']]);
        $this->assertSame(5, $result['stats']['mpls']);
    }

    public function testPseudowireEndsArePairedAndTheirMtusCompared(): void
    {
        $pw = fn (int $device, int $vc, string $peer, int $mtu, int $peerMtu = 0, int $peerDevice = 0) => ['device_id' => $device,
            'port_id' => $device * 10 + 2, 'ifName' => 'GigabitEthernet0/0/0/9.' . $vc, 'peer_device_id' => $peerDevice,
            'peer_ldp_id' => ip2long($peer), 'cpwVcID' => $vc, 'pw_type' => 'ethernet', 'pw_psntype' => 'mpls', 'pw_local_mtu' => $mtu,
            'pw_peer_mtu' => $peerMtu, 'pw_descr' => "customer $vc"];

        $result = self::build(['pw' => [
            // VC 100 agrees, VC 200 does not: pe3 says 1500, pe1 9000
            $pw(1, 100, '10.255.0.5', 1500),
            $pw(5, 100, '10.255.0.1', 1500),
            $pw(1, 200, '10.255.0.5', 9000),
            $pw(5, 200, '10.255.0.1', 1500, 0, 1),
            // VC 300 to an unmonitored PE, known only from pe2's own view; VC 301: MTU unknown (0)
            $pw(2, 300, '10.66.0.1', 1500, 9100),
            $pw(2, 301, '10.66.0.1', 0, 1500),
        ]]);

        $edges = array_column(self::edges($result, 'pw'), null, 'to');
        $this->assertSame(['d:5', 'xw:10.66.0.1'], array_keys($edges));
        $pe1pe3 = $edges['d:5'];
        $this->assertSame(['MTU mismatch', 'warning', 1], [$pe1pe3['state'], $pe1pe3['status'], $pe1pe3['mtu_mismatch']]);
        $this->assertSame([100, 200], array_column($pe1pe3['vcs'], 'vc'));
        $this->assertSame([1500, 1500, false], [$pe1pe3['vcs'][0]['mtu_a'], $pe1pe3['vcs'][0]['mtu_b'], $pe1pe3['vcs'][0]['mismatch']]);
        $this->assertSame([9000, 1500, true], [$pe1pe3['vcs'][1]['mtu_a'], $pe1pe3['vcs'][1]['mtu_b'], $pe1pe3['vcs'][1]['mismatch']]);
        $this->assertSame('Gi0/0/0/9.200', $pe1pe3['vcs'][1]['b']['port']);

        $far = $edges['xw:10.66.0.1'];
        $this->assertSame([1500, 9100, true], [$far['vcs'][0]['mtu_a'], $far['vcs'][0]['mtu_b'], $far['vcs'][0]['mismatch']]);
        $this->assertSame([null, 1500, false], [$far['vcs'][1]['mtu_a'], $far['vcs'][1]['mtu_b'], $far['vcs'][1]['mismatch']]);
        $this->assertNull($far['vcs'][0]['b']);
        $this->assertSame(['pw' => 4, 'mtu' => 2], array_intersect_key($result['stats'], ['pw' => 0, 'mtu' => 0]));
    }

    public function testPseudowirePeerAbove127IsReadFromTheSignedColumn(): void
    {
        // peer_ldp_id is a signed INT in LibreNMS: 200.0.0.1 comes back negative
        $row = ['device_id' => 1, 'port_id' => 12, 'peer_device_id' => 0, 'peer_ldp_id' => ip2long('200.0.0.1') - 4294967296, 'cpwVcID' => 7,
            'pw_type' => 'ethernet', 'pw_psntype' => 'mpls', 'pw_local_mtu' => 1500, 'pw_peer_mtu' => 1500, 'pw_descr' => ''];

        $this->assertSame(['200.0.0.1'], L3Builder::layerLookups(['pw' => [$row]])['addresses']);
        $this->assertSame('xw:200.0.0.1', self::edges(self::build(['pw' => [$row]]), 'pw')[0]['to']);
    }

    public function testLayersCanBeSwitchedOffAndFollowTheDepth(): void
    {
        $tunnel = ['device_id' => 4, 'peer_addr' => '203.0.113.1', 'local_addr' => '', 'tunnel_name' => 'x', 'tunnel_status' => 'active'];
        $isis = ['device_id' => 1, 'port_id' => 11, 'isisISAdjState' => 'up', 'isisISAdjNeighSysType' => 'L2', 'isisISAdjNeighSysID' => '',
            'isisISAdjAreaAddress' => '', 'isisISAdjIPAddrAddress' => '10.0.13.2', 'isisCircAdminState' => 'on'];

        $this->assertSame([], self::edges(self::build(['ipsec' => [$tunnel]], [], ['ipsec' => false]), 'ipsec'));
        // centered on pe2 with one hop: p1 is on the map, pe1 is not
        $this->assertSame([], self::edges(self::build(['isis' => [$isis]], [], ['root' => 2, 'depth' => 1]), 'isis'));
        $this->assertCount(1, self::edges(self::build(['isis' => [$isis]], [], ['root' => 2, 'depth' => 2]), 'isis'));
    }

    public function testLookupsCollectEveryFarEnd(): void
    {
        $lookups = L3Builder::layerLookups([
            'ospfv3' => [self::ospfv3(1, '10.255.0.3', 'full'), self::ospfv3(1, '10.255.0.9', 'full', '2001:DB8::9', 77)],
            'isis' => [['isisISAdjIPAddrAddress' => '10.0.13.2', 'isisISAdjNeighSysID' => '01.00.00.00.00.01']],
            'ipsec' => [['peer_addr' => '192.0.2.50']],
            'mpls' => [['mplsLspToAddr' => '10.255.0.2']],
            'pw' => [['peer_ldp_id' => ip2long('10.255.0.5'), 'peer_device_id' => 5]],
        ]);

        $this->assertSame(['10.255.0.3', '10.255.0.9'], $lookups['routerIds']);
        $this->assertEqualsCanonicalizing(['10.255.0.3', '10.255.0.9', '2001:db8::9', '10.0.13.2', '10.0.0.1', '192.0.2.50', '10.255.0.2', '10.255.0.5'], $lookups['addresses']);
        $this->assertSame([77], $lookups['ports'], 'only for global neighbour addresses');
        $this->assertSame([5], $lookups['devices']);
    }

    public function testOspfPairTurnsDownWhenOneSideIsNotFull(): void
    {
        $ospf = [
            ['device_id' => 1, 'ospfNbrIpAddr' => '10.0.13.2', 'ospfNbrRtrId' => '10.255.0.3', 'ospfNbrState' => 'full', 'context_name' => ''],
            ['device_id' => 3, 'ospfNbrIpAddr' => '10.0.13.1', 'ospfNbrRtrId' => '10.255.0.1', 'ospfNbrState' => 'exchange', 'context_name' => ''],
        ];
        $result = (new L3Builder(self::devices(), self::addresses(), $ospf))->build(['bgp' => false]);

        $edge = self::edges($result, 'ospf')[0];
        $this->assertSame(['exchange', 'down'], [$edge['state'], $edge['status']]);
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
