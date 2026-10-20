<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\History\ChangeReport;
use Agoodley\LibrenmsTopology\History\FactCollector;
use Agoodley\LibrenmsTopology\History\FactText;
use PHPUnit\Framework\TestCase;

class ChangeReportTest extends TestCase
{
    private const NAMES = [1 => 'core1', 2 => 'core2', 3 => 'acc1', 4 => 'acc2'];

    private const T0 = 1_700_000_000;      // window start
    private const T1 = self::T0 + 3600;    // a snapshot inside the window
    private const T2 = self::T0 + 7200;    // the next one

    private static function ports(): array
    {
        $ports = [];
        foreach ([[11, 1, 'Te1/0/1'], [12, 1, 'Te1/0/2'], [21, 2, 'Te1/0/1'], [22, 2, 'Te1/0/9'], [31, 3, 'Gi1/0/49'], [32, 3, 'Gi1/0/50'],
            [33, 3, 'Gi1/0/20'], [13, 1, 'Po1'], [14, 1, 'Te1/0/3'], [15, 1, 'Te1/0/4']] as [$id, $device, $name]) {
            $ports[$id] = ['port_id' => $id, 'device_id' => $device, 'ifName' => $name];
        }

        return $ports;
    }

    private static function collector(): FactCollector
    {
        return new FactCollector(self::NAMES, self::ports());
    }

    private static function link(int $ld, int $lp, int $rd, ?int $rp, string $host = '', string $remotePort = '', string $platform = ''): array
    {
        return ['local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd, 'remote_port_id' => $rp, 'remote_hostname' => $host,
            'remote_port' => $remotePort, 'remote_platform' => $platform, 'remote_version' => '', 'protocol' => 'lldp'];
    }

    /**
     * Stored rows of facts with their period of presence.
     *
     * @param  array<string, array>  $facts
     */
    private static function rows(array $facts, int $firstSeen, ?int $removedAt = null, bool $initial = false): array
    {
        $rows = [];
        foreach ($facts as $fact) {
            $rows[] = ['kind' => $fact['kind'], 'fact_key' => $fact['key'], 'device_a' => $fact['device_a'], 'device_b' => $fact['device_b'],
                'data' => $fact['data'], 'initial' => $initial, 'first_seen' => $firstSeen, 'removed_at' => $removedAt];
        }

        return $rows;
    }

    private static function report(array $rows, ?array $visible = null, array $newDevices = [], array $priorNodes = []): array
    {
        return ChangeReport::build($rows, self::T0, self::T0 + 86400, new FactText(self::NAMES, $visible), $newDevices, $priorNodes);
    }

    public function testMovedCable(): void
    {
        // acc1 Gi1/0/49 went from core1 Te1/0/2 to core2 Te1/0/9 between two snapshots
        $old = self::rows(self::collector()->links([self::link(3, 31, 1, 12)]), self::T0 - 86400, self::T1);
        $new = self::rows(self::collector()->links([self::link(3, 31, 2, 22)]), self::T1);

        $report = self::report(array_merge($old, $new));

        $this->assertCount(1, $report['events']);
        $event = $report['events'][0];
        $this->assertSame('cable_moved', $event['type']);
        $this->assertSame('acc1 Gi1/0/49 now connects to core2 Te1/0/9 (was core1 Te1/0/2)', $event['text']);
        $this->assertEqualsCanonicalizing([1, 2, 3], $event['devices']);
        $this->assertSame(self::T1, $event['time']);
        $this->assertSame(1, $report['counts']['cable_moved']);
    }

    public function testLinkThatCameBackAndPlainChanges(): void
    {
        $flap = self::collector()->links([self::link(3, 31, 1, 12)]);
        $rows = array_merge(
            self::rows($flap, self::T0 - 86400, self::T1),
            self::rows($flap, self::T2),
            self::rows(self::collector()->links([self::link(3, 32, 2, 21)]), self::T0 - 86400, self::T2),
            self::rows(self::collector()->links([self::link(1, 11, 2, 22)]), self::T2),
        );

        $types = array_column(self::report($rows)['events'], 'type', 'text');

        $this->assertSame('link_back', $types['core1 Te1/0/2 ↔ acc1 Gi1/0/49: back after it was gone 1h'] ?? null);
        $this->assertSame('link_removed', $types['Link gone: core2 Te1/0/1 ↔ acc1 Gi1/0/50'] ?? null);
        $this->assertSame('link_added', $types['New link core1 Te1/0/1 ↔ core2 Te1/0/9'] ?? null);
    }

    public function testNewUnmanagedSwitchIsCalledOut(): void
    {
        $rows = array_merge(
            self::rows(self::collector()->links([self::link(3, 33, 0, null, 'desk-switch', 'port 1', 'TP-Link TL-SG108E')]), self::T1),
            self::rows(self::collector()->links([self::link(3, 32, 0, null, 'old-hub', 'port 2', 'HP ProCurve')]), self::T1),
            self::rows(self::collector()->links([self::link(4, 31, 0, null, 'printer-1', 'eth0', 'HP LaserJet')]), self::T1),
        );

        $events = self::report($rows, null, [], ['u:old-hub' => true])['events'];
        $byType = [];
        foreach ($events as $event) {
            $byType[$event['type']][] = $event['text'];
        }

        $this->assertSame(['New switch not monitored: desk-switch (TP-Link TL-SG108E) on acc1 Gi1/0/20'], $byType['unmanaged_new']);
        $this->assertSame(['New neighbor not monitored: printer-1 (HP LaserJet) on acc2 Gi1/0/49'], $byType['neighbor_new'], 'an unknown LLDP speaker, as info');
        $this->assertSame(['New link acc1 Gi1/0/50 ↔ old-hub port 2'], $byType['link_added'], 'a known unmanaged switch on a new port is a plain new link');
    }

    public function testFirstSnapshotIsNoChangeButLaterRemovalsAre(): void
    {
        $links = self::collector()->links([self::link(3, 31, 1, 12), self::link(3, 32, 2, 21)]);
        $rows = self::rows($links, self::T1, null, true);
        $this->assertSame([], self::report($rows)['events'], 'what the first snapshot finds existed before');

        $rows[0]['removed_at'] = self::T2;
        $this->assertSame(['link_removed'], array_column(self::report($rows)['events'], 'type'));
    }

    public function testChangesOutsideTheWindowAreLeftOut(): void
    {
        $rows = self::rows(self::collector()->links([self::link(3, 31, 1, 12)]), self::T0 - 5, self::T0 + 86400 + 5);

        $this->assertSame([], self::report($rows)['events']);
    }

    public function testRootBridgeMoveIsOneEventPerInstance(): void
    {
        $stp = fn (string $root) => self::collector()->stpRoots([
            ['device_id' => 1, 'vlan' => 1, 'designatedRoot' => $root, 'bridgeAddress' => '00ab01000001'],
            ['device_id' => 3, 'vlan' => 1, 'designatedRoot' => $root, 'bridgeAddress' => '00ab03000001'],
            ['device_id' => 4, 'vlan' => 1, 'designatedRoot' => $root, 'bridgeAddress' => '00ab04000001'],
        ]);
        $rows = array_merge(self::rows($stp('00ab01000001'), self::T0 - 86400, self::T1), self::rows($stp('00ab03000001'), self::T1));

        $events = self::report($rows)['events'];

        $this->assertCount(1, $events);
        $this->assertSame('root_changed', $events[0]['type']);
        $this->assertSame('VLAN 1 / CST: root bridge changed from core1 (00:ab:01:00:00:01) to acc1 (00:ab:03:00:00:01) (seen by acc1, acc2, core1)', $events[0]['text']);
    }

    public function testNewAndRemovedInstancesAndBlocking(): void
    {
        $collector = self::collector();
        $rows = array_merge(
            self::rows($collector->stpRoots([['device_id' => 3, 'vlan' => 30, 'designatedRoot' => '00ab01000001', 'bridgeAddress' => '00ab03000001'],
                ['device_id' => 1, 'vlan' => 30, 'designatedRoot' => '00ab01000001', 'bridgeAddress' => '00ab01000001']]), self::T1),
            self::rows($collector->stpRoots([['device_id' => 3, 'vlan' => 40, 'designatedRoot' => '00ab01000001', 'bridgeAddress' => '00ab03000001']]), self::T0 - 86400, self::T1),
            self::rows($collector->blockedPorts([['device_id' => 3, 'port_id' => 32, 'vlan' => 30, 'state' => 'blocking'],
                ['device_id' => 3, 'port_id' => 32, 'vlan' => 1, 'state' => 'blocking']]), self::T2),
            self::rows($collector->blockedPorts([['device_id' => 3, 'port_id' => 31, 'vlan' => 1, 'state' => 'blocking']]), self::T0 - 86400, self::T2),
        );

        $texts = array_column(self::report($rows)['events'], 'text', 'type');

        $this->assertSame('VLAN 30: root bridge core1 (00:ab:01:00:00:01) (new instance on acc1, core1)', $texts['root_new']);
        $this->assertSame('VLAN 40: no longer reported by acc1 (root bridge was 00:ab:01:00:00:01)', $texts['root_gone'], 'core1 did not report VLAN 40');
        $this->assertSame('acc1 Gi1/0/50 started blocking in VLAN 1 / CST, VLAN 30', $texts['blocking_started']);
        $this->assertSame('acc1 Gi1/0/49 stopped blocking in VLAN 1 / CST', $texts['blocking_stopped']);
    }

    public function testPortChannelMembers(): void
    {
        $rows = array_merge(
            self::rows(self::collector()->lagMembers([14 => 13, 15 => 13]), self::T1),
            self::rows(self::collector()->lagMembers([11 => 13]), self::T0 - 86400, self::T2),
        );

        $texts = array_column(self::report($rows)['events'], 'text', 'type');

        $this->assertSame('core1 Po1: Te1/0/3, Te1/0/4 joined', $texts['lag_member_added']);
        $this->assertSame('core1 Po1: Te1/0/1 left', $texts['lag_member_removed']);
    }

    public function testBothReportsOfAnAdjacencyAreOneEvent(): void
    {
        $collector = self::collector();
        $ospf = $collector->ospf([
            ['device_id' => 1, 'port_id' => 11, 'ospfNbrIpAddr' => '10.0.0.2', 'ospfNbrRtrId' => '2.2.2.2', 'ospfNbrState' => 'full', 'context_name' => ''],
            ['device_id' => 2, 'port_id' => 21, 'ospfNbrIpAddr' => '10.0.0.1', 'ospfNbrRtrId' => '1.1.1.1', 'ospfNbrState' => 'full', 'context_name' => ''],
        ], ['1.1.1.1' => 1, '2.2.2.2' => 2], []);
        $bgp = $collector->bgp([['device_id' => 1, 'bgpPeerIdentifier' => '192.0.2.1', 'bgpPeerRemoteAs' => 65001, 'bgpPeerState' => 'established',
            'bgpPeerDescr' => 'ISP-A', 'context_name' => '']], []);
        $rows = array_merge(self::rows($ospf, self::T0 - 86400, self::T1), self::rows($bgp, self::T0 - 86400, self::T2));

        $events = self::report($rows)['events'];

        $this->assertSame(['bgp_lost', 'ospf_lost'], array_column($events, 'type'));
        $this->assertStringEndsWith('lost (reported by both routers)', $events[1]['text']);
        $this->assertSame('BGP session core1 → 192.0.2.1 (AS 65001, ISP-A) down', $events[0]['text']);
    }

    public function testDeviceAddedToLibrenmsIsOneEvent(): void
    {
        $collector = self::collector();
        $rows = array_merge(
            self::rows($collector->links([self::link(4, 31, 1, 12), self::link(4, 32, 2, 21)]), self::T1),
            self::rows($collector->blockedPorts([['device_id' => 4, 'port_id' => 32, 'vlan' => 1, 'state' => 'blocking']]), self::T1),
            // core1 knew acc2 as an unmanaged neighbor before LibreNMS monitored it: the same cable
            self::rows($collector->links([self::link(1, 12, 0, null, 'acc2.example.net', 'GigabitEthernet1/0/49', 'Cisco C9300')]), self::T0 - 86400, self::T1),
        );

        $events = self::report($rows, null, [4 => true])['events'];

        $this->assertCount(1, $events);
        $this->assertSame('acc2 was added to LibreNMS: 2 links, 1 blocked port', $events[0]['text']);
    }

    public function testNeighborThatLibrenmsStartsOrStopsToMonitorIsNoChange(): void
    {
        $unmanaged = self::collector()->links([self::link(1, 12, 0, null, 'acc2', 'Gi1/0/49', 'Cisco C9300')]);
        $managed = self::collector()->links([self::link(4, 31, 1, 12)]);

        // core1's rediscovery resolves the neighbor, or the device is deleted and core1 sees it as unmanaged again
        $this->assertSame([], self::report(array_merge(self::rows($unmanaged, self::T0 - 86400, self::T1), self::rows($managed, self::T1)))['events']);
        $this->assertSame([], self::report(array_merge(self::rows($managed, self::T0 - 86400, self::T1), self::rows($unmanaged, self::T2)))['events']);

        // another neighbor on that port is a moved cable
        $other = self::collector()->links([self::link(1, 12, 0, null, 'old-hub', 'port 2', 'HP ProCurve')]);
        $events = self::report(array_merge(self::rows($managed, self::T0 - 86400, self::T1), self::rows($other, self::T1)))['events'];
        $this->assertSame(['cable_moved'], array_column($events, 'type'));
    }

    public function testPvstRootMoves(): void
    {
        // core1 is the root of the odd VLANs, core2 of the even ones; then core1 fails
        $stp = fn (callable $root) => self::collector()->stpRoots(array_merge(...array_map(fn ($device) => array_map(fn ($vlan) => [
            'device_id' => $device, 'vlan' => $vlan, 'designatedRoot' => $root($vlan), 'bridgeAddress' => "00ab0{$device}000001"], range(1, 50)), [1, 2, 3])));
        $before = $stp(fn ($vlan) => $vlan % 2 ? '00ab01000001' : '00ab02000001');
        $after = $stp(fn ($vlan) => '00ab02000001');

        $events = self::report(array_merge(self::rows($before, self::T0 - 86400, self::T1), self::rows($after, self::T1)))['events'];

        $this->assertSame(['root_changed'], array_column($events, 'type'), 'one event for all instances and switches');
        $this->assertStringStartsWith('VLANs 1, 3, 5, 7, 9, 11', $events[0]['text']);
        $this->assertStringContainsString('root bridge changed from core1 (00:ab:01:00:00:01) to core2 (00:ab:02:00:00:01) (seen by acc1, core1, core2)', $events[0]['text']);

        // one VLAN moves while core1 stays the root of the others
        $partial = $stp(fn ($vlan) => $vlan === 31 ? '00ab02000001' : ($vlan % 2 ? '00ab01000001' : '00ab02000001'));
        $events = self::report(array_merge(self::rows($before, self::T0 - 86400, self::T1), self::rows($partial, self::T1)))['events'];
        $this->assertSame(['VLAN 31: root bridge changed from core1 (00:ab:01:00:00:01) to core2 (00:ab:02:00:00:01) (seen by acc1, core1, core2)'],
            array_column($events, 'text'));
    }

    public function testParallelAdjacenciesStayApart(): void
    {
        $ospf = self::collector()->ospf([
            ['device_id' => 1, 'port_id' => 11, 'ospfNbrIpAddr' => '10.0.0.2', 'ospfNbrRtrId' => '2.2.2.2', 'ospfNbrState' => 'full', 'context_name' => ''],
            ['device_id' => 1, 'port_id' => 12, 'ospfNbrIpAddr' => '10.0.1.2', 'ospfNbrRtrId' => '2.2.2.2', 'ospfNbrState' => 'full', 'context_name' => ''],
        ], ['2.2.2.2' => 2], []);

        $events = self::report(self::rows($ospf, self::T0 - 86400, self::T1))['events'];

        $this->assertSame(['ospf_lost', 'ospf_lost'], array_column($events, 'type'));
    }

    public function testDevicesTheUserMayNotSeeAreNotNamed(): void
    {
        $old = self::rows(self::collector()->links([self::link(3, 31, 1, 12, 'core1-lldp-name')]), self::T0 - 86400, self::T1);
        $new = self::rows(self::collector()->links([self::link(3, 31, 2, 22)]), self::T1);

        $events = self::report(array_merge($old, $new), [3 => true])['events'];

        $this->assertSame('acc1 Gi1/0/49 now connects to another device Te1/0/9 (was core1-lldp-name Te1/0/2)', $events[0]['text']);
        $this->assertSame([3], $events[0]['devices']);
    }

    public function testNewestFirst(): void
    {
        $rows = array_merge(
            self::rows(self::collector()->links([self::link(1, 11, 2, 21)]), self::T1),
            self::rows(self::collector()->links([self::link(3, 32, 2, 22)]), self::T2),
        );

        $this->assertSame([self::T2, self::T1], array_column(self::report($rows)['events'], 'time'));
    }
}
