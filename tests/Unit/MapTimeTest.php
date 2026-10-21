<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\History\FactCollector;
use Agoodley\LibrenmsTopology\History\PastMap;
use Agoodley\LibrenmsTopology\Sharing\SavedView;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Topology\L2Builder;
use PHPUnit\Framework\TestCase;

/**
 * Maps at a point in time: the at / since parameters and the map as of a snapshot (PastMap).
 */
class MapTimeTest extends TestCase
{
    private const NOW = 1_792_573_200; // 2026-10-21 09:00 UTC

    private const NAMES = [1 => 'core1', 2 => 'core2', 3 => 'acc1', 4 => 'acc2', 5 => 'old-dist'];

    private static function ports(): array
    {
        $ports = [];
        foreach ([[11, 1, 'TenGigabitEthernet1/0/1'], [12, 1, 'TenGigabitEthernet1/0/2'], [13, 1, 'Port-channel1'], [14, 1, 'GigabitEthernet1/0/1'],
            [15, 1, 'TenGigabitEthernet1/0/3'], [16, 1, 'TenGigabitEthernet1/0/4'], [21, 2, 'TenGigabitEthernet1/0/1'], [31, 3, 'GigabitEthernet1/0/49'],
            [32, 3, 'GigabitEthernet1/0/50'], [33, 3, 'Port-channel1'], [41, 4, 'GigabitEthernet1/0/49'], [51, 5, 'Te1/1']] as [$id, $device, $name]) {
            $ports[$id] = ['port_id' => $id, 'device_id' => $device, 'ifName' => $name, 'ifOperStatus' => 'up', 'ifSpeed' => 10_000_000_000];
        }

        return $ports;
    }

    private static function link(int $ld, int $lp, int $rd, ?int $rp, string $host = '', string $remotePort = '', string $platform = ''): array
    {
        return ['local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd, 'remote_port_id' => $rp, 'remote_hostname' => $host,
            'remote_port' => $remotePort, 'remote_platform' => $platform, 'remote_version' => '', 'protocol' => 'lldp'];
    }

    /**
     * Stored facts (FactCollector) as HistoryRepository::factRow() decodes them.
     *
     * @param  array<string, array>  $facts
     */
    private static function rows(array $facts, int $firstSeen = self::NOW - 86400, ?int $removedAt = null): array
    {
        $rows = [];
        $id = 100;
        foreach ($facts as $fact) {
            $rows[] = ['id' => $id++, 'kind' => $fact['kind'], 'hash' => $fact['hash'], 'key' => $fact['key'], 'fact_key' => $fact['key'],
                'device_a' => $fact['device_a'], 'device_b' => $fact['device_b'], 'data' => $fact['data'], 'data_hash' => $fact['data_hash'],
                'initial' => false, 'first_seen' => $firstSeen, 'removed_at' => $removedAt];
        }

        return $rows;
    }

    private static function linkFacts(array $links): array
    {
        return self::rows((new FactCollector(self::NAMES, self::ports(), [3 => ['acc1.example.net']]))->links($links));
    }

    // ---------------------------------------------------------------- parameters

    public function testTimeParameters(): void
    {
        $this->assertSame(['at' => null, 'since' => null, 'time_error' => null], MapParams::time([], self::NOW));
        $this->assertSame(strtotime('2026-10-20T09:30'), MapParams::time(['at' => '2026-10-20T09:30'], self::NOW)['at']);
        $this->assertSame(strtotime('2026-10-20 09:30:15'), MapParams::time(['at' => '2026-10-20 09:30:15'], self::NOW)['at']);
        $this->assertSame(strtotime('2026-10-20 00:00'), MapParams::time(['at' => '2026-10-20'], self::NOW)['at'], 'a date alone is its midnight');
        $this->assertSame(strtotime('2026-10-20T07:30Z'), MapParams::time(['at' => '2026-10-20T09:30+02:00'], self::NOW)['at'], 'an offset is honored');
        $this->assertSame(MapParams::BASELINE, MapParams::time(['since' => 'Baseline'], self::NOW)['since']);
        $this->assertSame(strtotime('2026-10-14T09:00'), MapParams::time(['since' => '2026-10-14T09:00', 'at' => ''], self::NOW)['since'], 'an empty at is no time');

        foreach (['yesterday', '-1 hour', '2026-13-01', '2026-02-30T10:00', '2026-10-20T25:00', '1792573200', 'baseline'] as $invalid) {
            $time = MapParams::time(['at' => $invalid], self::NOW);
            $this->assertNull($time['at'], $invalid);
            $this->assertStringContainsString('is not a date and time', (string) $time['time_error'], $invalid);
        }
        $this->assertStringContainsString('is not a date and time', (string) MapParams::time(['at' => ['2026-10-20']], self::NOW)['time_error']);
        $future = MapParams::time(['since' => '2026-10-22T09:00'], self::NOW);
        $this->assertNull($future['since']);
        $this->assertStringContainsString('in the future', (string) $future['time_error']);
        $both = MapParams::time(['at' => '2026-10-20T09:00', 'since' => 'baseline'], self::NOW);
        $this->assertNull($both['at']);
        $this->assertNull($both['since']);
        $this->assertStringContainsString('not both', (string) $both['time_error']);

        $this->assertSame('at', MapParams::timeMode(['at' => 1, 'since' => null]));
        $this->assertSame('since', MapParams::timeMode(['at' => null, 'since' => 'baseline']));
        $this->assertNull(MapParams::timeMode(MapParams::parse([], new PluginSettings)));
        $this->assertTrue(MapParams::asksForTime(['since' => 'garbage']));
        $this->assertFalse(MapParams::asksForTime(['at' => '', 'since' => ' ']));
    }

    public function testSavedViewsKeepNoTime(): void
    {
        $params = MapParams::parse(['device' => '3', 'at' => '2026-10-20T09:00'], new PluginSettings);
        $this->assertNotNull($params['at']);
        $query = SavedView::query('l2', $params);
        $this->assertArrayNotHasKey('at', $query);
        $this->assertArrayNotHasKey('time_error', $query);
        $this->assertSame('3', $query['device']);
    }

    // ---------------------------------------------------------------- the snapshot's facts

    public function testFactPresentAtItsBoundaries(): void
    {
        $t = self::NOW - 3600;
        $this->assertTrue(PastMap::presentAt(['first_seen' => $t, 'removed_at' => null], $t), 'first seen at that snapshot');
        $this->assertFalse(PastMap::presentAt(['first_seen' => $t - 3600, 'removed_at' => $t], $t), 'that snapshot no longer saw it');
        $this->assertTrue(PastMap::presentAt(['first_seen' => $t - 3600, 'removed_at' => $t + 1], $t));
        $this->assertFalse(PastMap::presentAt(['first_seen' => $t + 1, 'removed_at' => null], $t), 'first seen later');
    }

    public function testLinkRowsOfManagedUnmanagedAndHiddenEnds(): void
    {
        $visible = [1 => true, 2 => true, 3 => true];
        $facts = self::linkFacts([
            self::link(3, 31, 1, 11, 'core1', 'Te1/0/1'),                             // both monitored
            self::link(1, 14, 0, null, 'ap-lobby', 'eth0', 'Cisco AIR-AP2802I'),    // unmanaged access point
            self::link(1, 0, 0, null, '', 'Gi0/1'),                                  // unmanaged without a name
            self::link(4, 41, 1, 15, 'core1', 'Te1/0/3'),                             // acc2 is not visible
            self::link(5, 51, 2, 21, 'core2.example.net', 'Te1/0/1'),                 // a device deleted since
        ]);
        $byKey = [];
        foreach ($facts as $fact) {
            $byKey[$fact['key']] = $fact;
        }

        $row = PastMap::linkRow($byKey['d1:te1/0/1|d3:gi1/0/49'], $visible, $visible);
        $this->assertSame([1, 11, 3, 31], [$row['local_device_id'], $row['local_port_id'], $row['remote_device_id'], $row['remote_port_id']]);
        $this->assertSame('Te1/0/1', $row['local_port_name']);
        $this->assertSame('', $row['remote_hostname'], 'core1 reported no LLDP neighbour name for acc1: only acc1 did, for core1');

        // seen from the end in the map's scope
        $row = PastMap::linkRow($byKey['d1:te1/0/1|d3:gi1/0/49'], $visible, [3 => true]);
        $this->assertSame([3, 31, 1, 11], [$row['local_device_id'], $row['local_port_id'], $row['remote_device_id'], $row['remote_port_id']]);
        $this->assertSame('Gi1/0/49', $row['local_port_name']);
        $this->assertSame('core1', $row['remote_hostname'], 'the name acc1\'s LLDP reported');

        $ap = PastMap::linkRow(self::find($facts, 'ap-lobby'), $visible, $visible);
        $this->assertSame(0, $ap['remote_device_id']);
        $this->assertSame('ap-lobby', $ap['remote_hostname']);
        $this->assertSame('wireless', $ap['remote_role'], 'the role as recorded');
        $unnamed = PastMap::linkRow(self::find($facts, 'Gi0/1', 'port_name'), $visible, $visible);
        $this->assertSame('', $unnamed['remote_hostname'], 'stored as "unknown neighbor": no name, one node per local port');

        // acc2 is not visible: an unmanaged neighbour named as core1 never heard it (LLDP of acc2 reported core1)
        $hidden = PastMap::linkRow($byKey['d1:te1/0/3|d4:gi1/0/49'], $visible, $visible);
        $this->assertSame([1, 15, 0, null], [$hidden['local_device_id'], $hidden['local_port_id'], $hidden['remote_device_id'], $hidden['remote_port_id']]);
        $this->assertSame('', $hidden['remote_hostname']);
        $this->assertSame(PastMap::MASKED, $hidden['remote_label']);
        $this->assertSame('Gi1/0/49', $hidden['remote_port']);
        $this->assertArrayNotHasKey('deleted', $hidden);
        $this->assertNull(PastMap::linkRow($byKey['d1:te1/0/3|d4:gi1/0/49'], [3 => true], [3 => true]), 'neither end visible');

        // device 5 no longer exists: its stored name for a user who may see every device, masked for the others
        $deletedFact = $byKey['d2:te1/0/1|d5:te1/1'];
        $everyone = PastMap::linkRow($deletedFact, $visible, $visible, [1 => true, 2 => true, 3 => true, 4 => true]);
        $this->assertSame('old-dist', $everyone['remote_hostname']);
        $this->assertTrue($everyone['deleted']);
        $restricted = PastMap::linkRow($deletedFact, $visible, $visible);
        $this->assertSame('', $restricted['remote_hostname']);
        $this->assertSame(PastMap::MASKED, $restricted['remote_label']);
    }

    public function testPastL2MapFromTheSnapshot(): void
    {
        $links = self::linkFacts([
            self::link(3, 31, 1, 11, 'core1', 'Te1/0/1'),
            self::link(3, 32, 1, 12, 'core1', 'Te1/0/2'),
            self::link(1, 16, 2, 21, 'core2', 'Te1/0/1'),
            self::link(4, 41, 1, 15, 'core1', 'Te1/0/3'),
            self::link(1, 14, 0, null, 'ap-lobby', 'eth0', 'Cisco AIR-AP2802I'),
        ]);
        $collector = new FactCollector(self::NAMES, self::ports());
        $lagMembers = [31 => 33, 32 => 33, 11 => 13, 12 => 13];
        $lag = self::rows($collector->lagMembers($lagMembers));
        $stp = self::rows($collector->stpRoots([
            ['device_id' => 1, 'vlan' => null, 'designatedRoot' => '0000000000a1', 'bridgeAddress' => '0000000000a1'],
            ['device_id' => 3, 'vlan' => null, 'designatedRoot' => '0000000000a1', 'bridgeAddress' => '0000000000c1'],
        ]) + $collector->blockedPortFacts([1 => [16 => [0]]]));
        $history = new FakeHistory(array_merge($links, $lag, $stp));
        $visible = [1 => true, 2 => true, 3 => true];
        $past = new PastMap($history, ['time' => self::NOW - 3600, 'started_at' => '2026-10-21 08:00:00', 'next' => null, 'recorded' => FactCollector::KINDS,
            'skipped' => []], $visible, FactCollector::KINDS);

        $ids = [1, 2, 3];
        $rows = array_values($past->linksWhere($ids, fn () => true));
        $this->assertCount(5, $rows, 'acc2 is hidden: its link becomes an unmanaged neighbour of core1');
        $lagParents = $past->lagParents($ids);
        $this->assertEquals($lagMembers, $lagParents);
        $index = $past->stp($ids, null, $lagParents);
        $this->assertTrue(StpIndex::isRoot($index->instance(1, 0)));
        $this->assertFalse(StpIndex::isRoot($index->instance(3, 0)));
        $this->assertSame('blocking', $index->portStatus(1, 16, null)['state']);
        $this->assertNull($index->portStatus(3, 31, null));

        // port 21 (core2 Te1/0/1) is no longer in LibreNMS: its stored name
        $ports = array_diff_key(self::ports(), [21 => true]);
        $ports = $past->withStoredPorts($ports, [11, 12, 13, 14, 15, 16, 21, 31, 32, 33]);
        $this->assertSame('Te1/0/1', $ports[21]['ifName']);
        $this->assertTrue($ports[21]['stored']);

        $devices = [];
        foreach ($ids as $id) {
            $devices[$id] = ['device_id' => $id, 'hostname' => self::NAMES[$id], 'role' => 'switch', 'status' => 1, 'disabled' => 0];
        }
        $built = (new L2Builder($devices, $rows, $ports, [], $lagParents, $index))->build(['collapseLag' => true]);
        $result = $past->finish($built, [1 => 'up', 2 => 'down', 3 => 'new']);

        $edges = [];
        foreach ($result['edges'] as $edge) {
            $edges[$edge['from'] . '>' . $edge['to']] = $edge;
            $this->assertSame('unknown', $edge['status'], 'no status from today');
            $this->assertNull($edge['util']);
        }
        $this->assertSame(2, $edges['d:1>d:3']['lag']['members'] ?? null, 'Po1 to Po1: one edge for both members');
        $this->assertSame([null, null], array_column($edges['d:1>d:3']['members'], 'up'));
        $this->assertTrue($edges['d:1>d:2']['blocked'], 'core1 Te1/0/4 was blocking');
        $this->assertSame('Te1/0/1', $edges['d:1>d:2']['b']['short'], 'the stored name of a port LibreNMS no longer has');
        $nodes = array_column($result['nodes'], null, 'id');
        $this->assertSame('down', $nodes['d:2']['status'], 'an outage covered that time');
        $this->assertSame('unknown', $nodes['d:3']['status']);
        $this->assertSame('Not in LibreNMS yet at that time', $nodes['d:3']['note']);
        $this->assertTrue($nodes['d:1']['stp']['root']);
        $this->assertTrue($nodes['d:1']['stp']['recorded']);
        $this->assertArrayNotHasKey('priority', $nodes['d:1']['stp'], 'the history keeps no priorities');
        $masked = array_values(array_filter($result['nodes'], fn ($node) => $node['name'] === PastMap::MASKED));
        $this->assertCount(1, $masked, 'acc2 is "another device"');
        $this->assertNull($masked[0]['device_id']);
        $this->assertSame('wireless', $nodes['u:ap-lobby']['role']);
        $this->assertSame(0, $result['stats']['down']);
    }

    public function testPastRoutingRowsAndUnreadableKinds(): void
    {
        $collector = new FactCollector(self::NAMES, self::ports());
        $facts = self::rows($collector->ospf([['device_id' => 1, 'port_id' => 11, 'ospfNbrIpAddr' => '10.0.0.2', 'ospfNbrRtrId' => '10.255.0.2',
            'ospfNbrState' => 'full', 'context_name' => '']], ['10.255.0.2' => 2], ['10.0.0.2' => 2])
            + $collector->bgp([['device_id' => 1, 'bgpPeerIdentifier' => '192.0.2.1', 'bgpPeerRemoteAs' => 64512, 'bgpPeerState' => 'established',
                'bgpPeerDescr' => 'ISP', 'context_name' => '']], []));
        $snapshot = ['time' => self::NOW, 'started_at' => '2026-10-21 09:00:00', 'next' => null, 'recorded' => FactCollector::KINDS, 'skipped' => []];

        $past = new PastMap(new FakeHistory($facts), $snapshot, [1 => true, 2 => true], FactCollector::KINDS);
        $this->assertSame([['device_id' => 1, 'port_id' => 0, 'ospfNbrIpAddr' => '10.0.0.2', 'ospfNbrRtrId' => '10.255.0.2', 'ospfNbrState' => 'full',
            'context_name' => '']], $past->ospfNeighbors([1, 2]));
        $bgp = $past->bgpPeers([1]);
        $this->assertSame('192.0.2.1', $bgp[0]['bgpPeerIdentifier']);
        $this->assertSame(64512, $bgp[0]['bgpPeerRemoteAs']);
        $this->assertSame('established', $bgp[0]['bgpPeerState']);

        // a role that may not read BGP sessions gets none of the history's either
        $limited = new PastMap(new FakeHistory($facts), $snapshot, [1 => true, 2 => true], [FactCollector::OSPF]);
        $this->assertSame([], $limited->bgpPeers([1]));
        $this->assertCount(1, $limited->ospfNeighbors([1]));
    }

    /**
     * The stored fact whose far (b) end has this name or port name.
     */
    private static function find(array $facts, string $value, string $field = 'name'): array
    {
        foreach ($facts as $fact) {
            if (($fact['data']['b'][$field] ?? null) === $value) {
                return $fact;
            }
        }
        self::fail("no fact with $field $value");
    }
}

/**
 * The history's facts of one snapshot from an array (HistoryRepository::factsAt()), filtered as the query does.
 */
class FakeHistory extends HistoryRepository
{
    /**
     * @param  array<int, array>  $facts  stored facts present at the snapshot
     */
    public function __construct(private readonly array $facts)
    {
        parent::__construct(null);
    }

    public function factsAt(string $at, array $ids, array $kinds, bool $reporting = false): \Generator
    {
        $ids = array_flip($ids);
        foreach ($this->facts as $fact) {
            if (in_array($fact['kind'], $kinds, true)
                && (isset($ids[(int) $fact['device_a']]) || (! $reporting && isset($ids[(int) $fact['device_b']])))) {
                yield $fact;
            }
        }
    }
}
