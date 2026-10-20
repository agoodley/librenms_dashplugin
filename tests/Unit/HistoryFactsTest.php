<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\History\FactCollector;
use Agoodley\LibrenmsTopology\History\FactDiff;
use Agoodley\LibrenmsTopology\History\Interval;
use PHPUnit\Framework\TestCase;

class HistoryFactsTest extends TestCase
{
    private const NAMES = [1 => 'core1', 2 => 'core2', 3 => 'acc1'];

    private static function ports(): array
    {
        $ports = [];
        foreach ([[11, 1, 'Te1/0/1'], [12, 1, 'Te1/0/2'], [13, 1, 'Port-channel1'], [21, 2, 'Te1/0/1'], [22, 2, 'Te1/0/2'], [23, 2, 'Port-channel1'],
            [31, 3, 'Gi1/0/49'], [32, 3, 'Gi1/0/50'], [33, 3, 'Gi1/0/10']] as [$id, $device, $name]) {
            $ports[$id] = ['port_id' => $id, 'device_id' => $device, 'ifName' => $name, 'ifDescr' => $name];
        }

        return $ports;
    }

    private static function link(int $ld, ?int $lp, int $rd, ?int $rp, string $host = '', string $remotePort = '', string $platform = ''): array
    {
        return ['local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd, 'remote_port_id' => $rp, 'remote_hostname' => $host,
            'remote_port' => $remotePort, 'remote_platform' => $platform, 'remote_version' => '', 'protocol' => 'lldp'];
    }

    public function testLinksAreOneFactPerCableWhicheverSideReportsIt(): void
    {
        $collector = new FactCollector(self::NAMES, self::ports());
        $facts = $collector->links([
            self::link(1, 11, 2, 21, 'core2', 'Te1/0/1'),
            self::link(2, 21, 1, 11, 'core1', 'Te1/0/1'), // the same cable from the other side
            self::link(3, 31, 1, 12, 'core1.example.net', 'Te1/0/2'),
            self::link(3, 33, 0, null, 'ap-floor2', 'eth0', 'Cisco AIR-AP2802I'),
        ]);

        $this->assertCount(3, $facts);
        $keys = array_column($facts, 'key');
        sort($keys);
        $this->assertSame(['d1:te1/0/1|d2:te1/0/1', 'd1:te1/0/2|d3:gi1/0/49', 'd3:gi1/0/10|u:ap-floor2:eth0'], $keys);

        $ap = array_values(array_filter($facts, fn ($f) => str_contains($f['key'], 'ap-floor2')))[0];
        $this->assertSame([3, null], [$ap['device_a'], $ap['device_b']]);
        $this->assertSame('wireless', $ap['data']['b']['role']);
        $this->assertSame('u:ap-floor2', $ap['data']['b']['node']);

        $uplink = array_values(array_filter($facts, fn ($f) => $f['key'] === 'd1:te1/0/2|d3:gi1/0/49'))[0];
        $this->assertSame([1, 3], [$uplink['device_a'], $uplink['device_b']]);
        $this->assertSame('core1.example.net', $uplink['data']['a']['host'], 'the LLDP name stands in for a device the user may not see');
        $this->assertSame(40, strlen($uplink['hash']));
    }

    public function testIdentityFollowsInterfaceNamesNotPortIds(): void
    {
        $before = (new FactCollector(self::NAMES, self::ports()))->links([self::link(3, 31, 1, 12)]);

        // LibreNMS rediscovered acc1's port under a new port_id: same cable, same fact
        $ports = self::ports();
        $ports[99] = ['port_id' => 99, 'device_id' => 3, 'ifName' => 'Gi1/0/49', 'ifDescr' => 'GigabitEthernet1/0/49'];
        unset($ports[31]);
        $after = (new FactCollector(self::NAMES, $ports))->links([self::link(3, 99, 1, 12)]);

        $this->assertSame(array_keys($before), array_keys($after));
        $this->assertNotSame(array_column($before, 'data_hash'), array_column($after, 'data_hash'), 'the new port id is a detail that changed');
    }

    public function testPhonesAreNoTopology(): void
    {
        $facts = (new FactCollector(self::NAMES, self::ports()))->links([
            self::link(3, 32, 0, null, 'SEP001122334455', 'Port 1', 'Cisco IP Phone 8851'),
            self::link(3, 33, 0, null, 'esx-12', 'vmnic0', 'VMware ESX Server'),
        ]);

        $this->assertSame(['d3:gi1/0/10|u:esx-12:vmnic0'], array_column($facts, 'key'));
    }

    public function testGenericNeighborNamesStayApartPerPort(): void
    {
        $facts = (new FactCollector(self::NAMES, self::ports()))->links([
            self::link(3, 32, 0, null, 'Switch', 'Port 1'),
            self::link(3, 33, 0, null, 'switch', 'Port 1'),
        ]);

        $this->assertCount(2, $facts);
    }

    public function testPortChannelMembers(): void
    {
        $facts = (new FactCollector(self::NAMES, self::ports()))->lagMembers([11 => 13, 12 => 13, 21 => 23, 999 => 13]);

        $this->assertSame(['d1:po1>te1/0/1', 'd1:po1>te1/0/2', 'd2:po1>te1/0/1'], array_column($facts, 'key'));
        $this->assertSame('Po1', array_values($facts)[0]['data']['aggregate_name']);
    }

    public function testRootBridgesEachSwitchSees(): void
    {
        $facts = (new FactCollector(self::NAMES, self::ports()))->stpRoots([
            ['device_id' => 1, 'vlan' => null, 'designatedRoot' => '8000.00ab01000001', 'bridgeAddress' => '00:ab:01:00:00:01'],
            ['device_id' => 3, 'vlan' => 1, 'designatedRoot' => '00ab01000001', 'bridgeAddress' => '00ab03000001'],
            ['device_id' => 3, 'vlan' => 20, 'designatedRoot' => '00ab01000001', 'bridgeAddress' => '00ab03000001'],
            ['device_id' => 3, 'vlan' => 30, 'designatedRoot' => '7000.00cc00000001', 'bridgeAddress' => '00ab03000001'],
            ['device_id' => 2, 'vlan' => 1, 'designatedRoot' => '', 'bridgeAddress' => '00ab02000001'],
        ]);

        $this->assertSame(['d1|00ab01000001', 'd3|00ab01000001', 'd3|00cc00000001'], array_column($facts, 'key'));
        $byKey = array_column($facts, null, 'key');
        $this->assertSame([0, 20], $byKey['d3|00ab01000001']['data']['vlans'], 'one fact per root bridge, with its instances');
        $this->assertSame([3, null], [$byKey['d3|00ab01000001']['device_a'], $byKey['d3|00ab01000001']['device_b']],
            'only the observing switch is involved: who may see the root bridge does not see the switches behind it');
        $this->assertSame('core1', $byKey['d3|00ab01000001']['data']['root_name']);
        $this->assertNull($byKey['d3|00cc00000001']['data']['root_device'], 'a root bridge LibreNMS does not monitor');
    }

    public function testPvstIsOneFactPerSwitchAndRoot(): void
    {
        $stp = [];
        foreach ([1, 2, 3] as $device) {
            foreach (range(1, 50) as $vlan) {
                $stp[] = ['device_id' => $device, 'vlan' => $vlan, 'designatedRoot' => $vlan % 2 ? '00ab01000001' : '00ab02000001', 'bridgeAddress' => "00ab0{$device}000001"];
            }
        }

        $facts = (new FactCollector(self::NAMES, self::ports()))->stpRoots($stp);

        $this->assertCount(6, $facts, '3 switches x 2 root bridges, not x 50 VLANs');
        $this->assertCount(25, array_values($facts)[0]['data']['vlans']);
    }

    public function testBlockedPortIsOneFactWithItsInstances(): void
    {
        $facts = (new FactCollector(self::NAMES, self::ports()))->blockedPorts([
            ['device_id' => 3, 'port_id' => 32, 'vlan' => 1, 'state' => 'blocking'],
            ['device_id' => 3, 'port_id' => 32, 'vlan' => 20, 'state' => 'discarding'],
            ['device_id' => 3, 'port_id' => 31, 'vlan' => 1, 'state' => 'forwarding'],
        ]);

        $this->assertSame(['d3:gi1/0/50'], array_column($facts, 'key'));
        $this->assertSame([0, 20], array_values($facts)[0]['data']['vlans']);
    }

    public function testMonitoredNeighborStillReportedAsUnmanagedIsOneCable(): void
    {
        $ports = self::ports() + [91 => ['port_id' => 91, 'device_id' => 9, 'ifName' => 'Te1/0/1', 'ifDescr' => 'TenGigabitEthernet1/0/1']];
        $links = [
            // core1 has not rediscovered yet: newsw is still an unmanaged neighbor there
            self::link(1, 11, 0, null, 'newsw.example.net', 'TenGigabitEthernet1/0/1', 'Cisco C9300'),
            self::link(9, 91, 1, 11, 'core1', 'Te1/0/1'),
            // a different neighbor on another port stays
            self::link(1, 12, 0, null, 'ap-floor2', 'eth0', 'Cisco AIR-AP2802I'),
        ];

        $facts = (new FactCollector(self::NAMES + [9 => '10.1.1.9'], $ports, [9 => ['newsw', '10.1.1.9']]))->links($links);

        $this->assertSame(['d1:te1/0/1|d9:te1/0/1', 'd1:te1/0/2|u:ap-floor2:eth0'], array_values(array_column($facts, 'key')),
            'matched by its sysName, the display name being an address');
    }

    public function testBgpPeerInTwoVrfs(): void
    {
        $peer = fn (int $vrf) => ['device_id' => 1, 'bgpPeerIdentifier' => '10.9.9.9', 'bgpPeerRemoteAs' => 65009, 'bgpPeerState' => 'established',
            'bgpPeerDescr' => '', 'context_name' => '', 'vrf_id' => $vrf];

        $facts = (new FactCollector(self::NAMES, self::ports()))->bgp([$peer(0), $peer(3), $peer(4)], []);

        $this->assertSame(['d1||10.9.9.9', 'd1||vrf3|10.9.9.9', 'd1||vrf4|10.9.9.9'], array_column($facts, 'key'));
    }

    public function testWorkingAdjacenciesAndSessions(): void
    {
        $collector = new FactCollector(self::NAMES, self::ports());
        $ospf = $collector->ospf([
            ['device_id' => 1, 'port_id' => 11, 'ospfNbrIpAddr' => '10.0.0.2', 'ospfNbrRtrId' => '2.2.2.2', 'ospfNbrState' => 'full', 'context_name' => ''],
            ['device_id' => 1, 'port_id' => 12, 'ospfNbrIpAddr' => '10.0.1.9', 'ospfNbrRtrId' => '9.9.9.9', 'ospfNbrState' => 'twoWay', 'context_name' => ''],
            ['device_id' => 1, 'port_id' => 12, 'ospfNbrIpAddr' => '10.0.1.8', 'ospfNbrRtrId' => '8.8.8.8', 'ospfNbrState' => 'exStart', 'context_name' => ''],
        ], ['2.2.2.2' => 2], []);
        $this->assertSame(['d1||2.2.2.2|10.0.0.2', 'd1||9.9.9.9|10.0.1.9'], array_column($ospf, 'key'), 'a neighbor stuck in exstart is no adjacency');
        $this->assertSame(2, array_values($ospf)[0]['device_b']);
        $this->assertSame('core2', array_values($ospf)[0]['data']['neighbor_name']);

        $bgp = $collector->bgp([
            ['device_id' => 1, 'bgpPeerIdentifier' => '192.0.2.1', 'bgpPeerRemoteAs' => 65001, 'bgpPeerState' => 'established', 'bgpPeerDescr' => 'ISP-A', 'context_name' => ''],
            ['device_id' => 1, 'bgpPeerIdentifier' => '10.0.0.2', 'bgpPeerRemoteAs' => 65000, 'bgpPeerState' => 'established', 'bgpPeerDescr' => '', 'context_name' => 'vrf-a'],
            ['device_id' => 1, 'bgpPeerIdentifier' => '192.0.2.5', 'bgpPeerRemoteAs' => 65002, 'bgpPeerState' => 'active', 'bgpPeerDescr' => '', 'context_name' => ''],
        ], ['10.0.0.2' => 2]);
        $this->assertSame(['d1||192.0.2.1', 'd1|vrf-a|10.0.0.2'], array_column($bgp, 'key'));
        $this->assertSame([null, 2], array_column($bgp, 'device_b'));
    }

    public function testAdjacencyNamesTheNeighborByItsAddressBeforeItsRouterId(): void
    {
        // a router ID can be duplicated (the routing page reports it): the neighbor's interface address tells them apart
        $ospf = (new FactCollector(self::NAMES, self::ports()))->ospf([
            ['device_id' => 1, 'port_id' => 11, 'ospfNbrIpAddr' => '10.0.0.3', 'ospfNbrRtrId' => '2.2.2.2', 'ospfNbrState' => 'full', 'context_name' => ''],
            ['device_id' => 1, 'port_id' => 12, 'ospfNbrIpAddr' => '10.0.0.9', 'ospfNbrRtrId' => '2.2.2.2', 'ospfNbrState' => 'full', 'context_name' => ''],
        ], ['2.2.2.2' => 2], ['10.0.0.3' => 3]);

        $this->assertSame([3, 2], array_column($ospf, 'device_b'), 'by address, else by router ID');
    }

    public function testDevicesOutsideTheScopeAreLeftOut(): void
    {
        $collector = new FactCollector([3 => 'acc1'], self::ports());

        $this->assertSame([], $collector->links([self::link(3, 31, 1, 12)]));
        $this->assertSame([], $collector->blockedPorts([['device_id' => 1, 'port_id' => 12, 'vlan' => 1, 'state' => 'blocking']]));
    }

    public function testChangedSpanningTreeFactStartsANewPeriod(): void
    {
        $now = FactCollector::fact('stp_root', 'd3|00ab01000001', 3, null, ['vlans' => [0, 30]]);
        $hash = array_key_first($now);
        $open = [['id' => 7, 'hash' => $hash, 'data_hash' => md5('vlans 0 only')]];

        $this->assertSame(['insert' => [], 'remove' => [], 'update' => [7 => $now[$hash]]], FactDiff::diff($open, $now));
        $this->assertSame(['insert' => $now, 'remove' => [7], 'update' => []], FactDiff::diff($open, $now, true));
    }

    public function testSnapshotWritesOnlyChanges(): void
    {
        $current = FactCollector::fact('link', 'a|b', 1, 2, ['x' => 1])
            + FactCollector::fact('link', 'c|d', 1, 3, ['x' => 2])
            + FactCollector::fact('lag', 'e', 1, null, ['x' => 3]);
        $hashes = array_keys($current);
        $open = [
            ['id' => 1, 'hash' => $hashes[0], 'data_hash' => $current[$hashes[0]]['data_hash']], // unchanged
            ['id' => 2, 'hash' => $hashes[1], 'data_hash' => 'old'],                              // details changed
            ['id' => 3, 'hash' => sha1('gone'), 'data_hash' => 'x'],                               // gone
            ['id' => 4, 'hash' => $hashes[0], 'data_hash' => 'x'],                                 // duplicate open period
        ];

        $diff = FactDiff::diff($open, $current);

        $this->assertSame([$hashes[2]], array_keys($diff['insert']));
        $this->assertSame([3, 4], $diff['remove']);
        $this->assertSame([2], array_keys($diff['update']));
        $this->assertSame(['insert' => [], 'remove' => [], 'update' => []], FactDiff::diff([
            ['id' => 1, 'hash' => $hashes[0], 'data_hash' => $current[$hashes[0]]['data_hash']],
        ], [$hashes[0] => $current[$hashes[0]]]));
    }

    public function testScheduleInterval(): void
    {
        $this->assertSame('*/15 * * * *', Interval::cron(15));
        $this->assertSame('*/30 * * * *', Interval::cron(45));
        $this->assertSame('7 * * * *', Interval::cron(60));
        $this->assertSame('7 */2 * * *', Interval::cron(150));
        $this->assertSame('7 */6 * * *', Interval::cron(360));
        $this->assertSame('7 3 * * *', Interval::cron(1440));
        $this->assertSame('*/5 * * * *', Interval::cron(1));
    }
}
