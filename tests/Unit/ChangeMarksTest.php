<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\History\ChangeMarks;
use Agoodley\LibrenmsTopology\History\ChangeReport;
use Agoodley\LibrenmsTopology\History\FactCollector;
use Agoodley\LibrenmsTopology\History\FactText;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Topology\L2Builder;
use PHPUnit\Framework\TestCase;

/**
 * Today's map with the changes since a time or since the baseline (ChangeMarks).
 */
class ChangeMarksTest extends TestCase
{
    private const NAMES = [1 => 'core1', 2 => 'core2', 3 => 'acc1', 4 => 'acc2'];

    private const T0 = 1_792_400_000;    // the start: since=
    private const T1 = self::T0 + 7200;  // the snapshot that saw the changes

    /** Bridge MACs of core1, core2 and acc1. */
    private const A1 = '0000000000a1';
    private const A2 = '0000000000a2';
    private const C1 = '0000000000c1';

    /** acc2 is hidden from the user */
    private const VISIBLE = [1 => true, 2 => true, 3 => true];

    private static int $id = 1000;

    private static function ports(): array
    {
        $ports = [];
        foreach ([[11, 1, 'Te1/0/1'], [12, 1, 'Te1/0/2'], [14, 1, 'Gi1/0/1'], [15, 1, 'Te1/0/3'], [16, 1, 'Te1/0/4'], [21, 2, 'Te1/0/1'],
            [31, 3, 'Gi1/0/49'], [32, 3, 'Gi1/0/50'], [33, 3, 'Po1'], [41, 4, 'Gi1/0/49']] as [$id, $device, $name]) {
            $ports[$id] = ['port_id' => $id, 'device_id' => $device, 'ifName' => $name, 'ifOperStatus' => 'up'];
        }

        return $ports;
    }

    private static function collector(): FactCollector
    {
        return new FactCollector(self::NAMES, self::ports());
    }

    private static function link(int $ld, int $lp, int $rd, ?int $rp, string $host = '', string $remotePort = '', string $platform = ''): array
    {
        return ['id' => 0, 'local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd, 'remote_port_id' => $rp, 'remote_hostname' => $host,
            'remote_port' => $remotePort, 'remote_platform' => $platform, 'remote_version' => '', 'protocol' => 'lldp'];
    }

    /**
     * Stored rows (HistoryRepository::factRow()) of facts with one period.
     *
     * @param  array<string, array>  $facts
     */
    private static function rows(array $facts, int $firstSeen, ?int $removedAt = null): array
    {
        $rows = [];
        foreach ($facts as $fact) {
            $rows[] = ['id' => self::$id++, 'kind' => $fact['kind'], 'hash' => $fact['hash'], 'key' => $fact['key'], 'fact_key' => $fact['key'],
                'device_a' => $fact['device_a'], 'device_b' => $fact['device_b'], 'data' => $fact['data'], 'data_hash' => $fact['data_hash'],
                'initial' => false, 'first_seen' => $firstSeen, 'removed_at' => $removedAt];
        }

        return $rows;
    }

    private static function devices(array $ids): array
    {
        $devices = [];
        foreach ($ids as $id) {
            $devices[$id] = ['device_id' => $id, 'hostname' => self::NAMES[$id], 'role' => 'switch', 'status' => 1, 'disabled' => 0];
        }

        return $devices;
    }

    private static function marksOf(array $item): array
    {
        return array_column($item['changes'] ?? [], 'text', 'type');
    }

    public function testChangesSinceATimeOnTheL2Map(): void
    {
        $c = self::collector();
        // at T0: acc1 Gi1/0/49 on core1, core1 to acc2 (hidden) and to an access point; root bridge core1 for VLANs 10 and 20
        $before = array_merge(
            self::rows($c->links([self::link(3, 31, 1, 11, 'core1', 'Te1/0/1'), self::link(4, 41, 1, 15, 'core1', 'Te1/0/3'),
                self::link(1, 14, 0, null, 'ap-lobby', 'eth0', 'Cisco AIR-AP2802I')]), self::T0 - 86400, self::T1),
            self::rows($c->stpRoots([['device_id' => 3, 'vlan' => 10, 'designatedRoot' => self::A1, 'bridgeAddress' => self::C1],
                ['device_id' => 3, 'vlan' => 20, 'designatedRoot' => self::A1, 'bridgeAddress' => self::C1],
                ['device_id' => 1, 'vlan' => 10, 'designatedRoot' => self::A1, 'bridgeAddress' => self::A1],
                ['device_id' => 2, 'vlan' => 10, 'designatedRoot' => self::A1, 'bridgeAddress' => self::A2]]), self::T0 - 86400, self::T1),
        );
        // now: the cable moved to core2, a new cable joined acc1's Po1, an unmanaged switch on core1, VLAN 20's root is core2 and
        // acc1 Gi1/0/49 blocks VLAN 20
        $now = [self::link(3, 31, 2, 21, 'core2', 'Te1/0/1'), self::link(3, 32, 1, 12, 'core1', 'Te1/0/2'), self::link(1, 16, 0, null, 'sw-lab', 'Gi0/24', 'Cisco WS-C2960')];
        $after = array_merge(
            self::rows($c->links($now), self::T1),
            self::rows($c->lagMembers([32 => 33]), self::T1),
            self::rows($c->stpRoots([['device_id' => 3, 'vlan' => 10, 'designatedRoot' => self::A1, 'bridgeAddress' => self::C1],
                ['device_id' => 3, 'vlan' => 20, 'designatedRoot' => self::A2, 'bridgeAddress' => self::C1],
                ['device_id' => 2, 'vlan' => 10, 'designatedRoot' => self::A1, 'bridgeAddress' => self::A2],
                ['device_id' => 2, 'vlan' => 20, 'designatedRoot' => self::A2, 'bridgeAddress' => self::A2],
                ['device_id' => 1, 'vlan' => 10, 'designatedRoot' => self::A1, 'bridgeAddress' => self::A1]]), self::T1),
            self::rows($c->blockedPortFacts([3 => [31 => [20]]]), self::T1),
        );
        // facts that did not change are in neither set: drop the unchanged root bridge facts of core1 and core2's VLAN 10
        $unchanged = array_intersect(array_column($before, 'data_hash', 'hash'), array_column($after, 'data_hash', 'hash'));
        $before = array_values(array_filter($before, fn ($row) => ! isset($unchanged[$row['hash']])));
        $after = array_values(array_filter($after, fn ($row) => ! isset($unchanged[$row['hash']])));

        $text = new FactText(self::NAMES, self::VISIBLE);
        $events = ChangeReport::build(array_merge($before, $after), self::T0 + 1, self::T1 + 60, $text)['events'];
        $map = (new L2Builder(self::devices([1, 2, 3]), $now, self::ports(), [], [32 => 33], new StpIndex))->build(['collapseLag' => true]);
        $result = ChangeMarks::apply('l2', $map, $before, $after, $text, $events);

        $edges = [];
        foreach ($result['edges'] as $edge) {
            $edges[$edge['from'] . '>' . $edge['to']] = $edge;
        }
        $moved = self::marksOf($edges['d:2>d:3']);
        $this->assertSame('acc1 Gi1/0/49 now connects to core2 Te1/0/1 (was core1 Te1/0/1)', $moved['link_new'], 'the moved cable in the Changes page\'s words');
        $this->assertSame('acc1 Gi1/0/49 started blocking in VLAN 20', $moved['blocking_started']);
        $this->assertSame(self::T1, $edges['d:2>d:3']['changes'][0]['time']);
        $joined = self::marksOf($edges['d:1>d:3']);
        $this->assertSame('acc1 Po1: Gi1/0/50 joined', $joined['member_added']);
        $this->assertArrayHasKey('link_new', $joined);
        $this->assertStringContainsString('sw-lab', self::marksOf($edges['d:1>u:sw-lab'])['link_new']);

        $ghosts = $result['changes']['ghosts'];
        $ghostEdges = [];
        foreach ($ghosts['edges'] as $edge) {
            $ghostEdges[$edge['to']] = $edge;
            $this->assertSame('ghost', $edge['kind']);
            $this->assertSame('d:1', $edge['from'], 'every gone link was on core1');
        }
        $this->assertSame('acc1 Gi1/0/49 now connects to core2 Te1/0/1 (was core1 Te1/0/1)', $ghostEdges['d:3']['changes'][0]['text']);
        $this->assertSame(['Te1/0/1', 'Gi1/0/49'], [$ghostEdges['d:3']['a']['short'], $ghostEdges['d:3']['b']['short']]);
        $nodes = array_column($ghosts['nodes'], null, 'name');
        $this->assertEqualsCanonicalizing(['another device', 'ap-lobby'], array_keys($nodes));
        $this->assertNull($nodes['another device']['device_id'], 'no id of a device the user may not see');
        $this->assertSame('wireless', $nodes['ap-lobby']['role']);
        $this->assertSame('Link gone: core1 Te1/0/3 ↔ another device Gi1/0/49', $ghostEdges[$nodes['another device']['id']]['changes'][0]['text']);
        $this->assertStringNotContainsString('acc2', (string) json_encode($result), 'nothing names the hidden device');

        $mapNodes = array_column($result['nodes'], null, 'id');
        $moves = array_column($mapNodes['d:2']['changes'], 'text');
        $this->assertContains('VLAN 20: root bridge core1 (00:00:00:00:00:a1) → core2 (00:00:00:00:00:a2) on acc1', $moves, 'the new root bridge of acc1\'s VLAN 20');
        $this->assertContains('VLAN 20: root bridge core2 (00:00:00:00:00:a2) on core2', $moves, 'core2\'s own new instance');
        $this->assertSame('VLAN 20: root bridge core1 (00:00:00:00:00:a1) → core2 (00:00:00:00:00:a2) on acc1', self::marksOf($mapNodes['d:1'])['root_old']);
        $this->assertEquals(['link_new' => 3, 'link_gone' => 3, 'member_added' => 1, 'root_new' => 2, 'root_old' => 1, 'blocking_started' => 1], $result['changes']['counts']);
        $this->assertSame(0, $result['changes']['unplaced']);
    }

    public function testChangesNotOnThisMapAreCounted(): void
    {
        $c = self::collector();
        $after = self::rows($c->links([self::link(3, 31, 2, 21, 'core2', 'Te1/0/1')]), self::T1);
        $before = self::rows($c->links([self::link(3, 31, 1, 11, 'core1', 'Te1/0/1')]), self::T0 - 86400, self::T1);
        // a map of core1 and core2 only: acc1 is not on it
        $map = (new L2Builder(self::devices([1, 2]), [], self::ports(), [], [], new StpIndex))->build();
        $result = ChangeMarks::apply('l2', $map, $before, $after, new FactText(self::NAMES, self::VISIBLE));

        $this->assertSame(1, $result['changes']['unplaced'], 'the new link has no edge on this map');
        $this->assertCount(1, $result['changes']['ghosts']['edges'], 'the gone link is drawn from core1 to a ghost of acc1');
        $ghost = $result['changes']['ghosts']['nodes'][0];
        $this->assertSame('acc1', $ghost['name']);
        $this->assertSame(3, $ghost['device_id'], 'a device the user may see keeps its id (its page)');
    }

    public function testSinceTheBaseline(): void
    {
        $c = self::collector();
        $missing = array_map(fn ($row) => ['first_seen' => null, 'removed_at' => null] + $row,
            self::rows($c->links([self::link(3, 32, 1, 12, 'core1', 'Te1/0/2')]), 0));
        $goneAt = [$missing[0]['hash'] => self::T1];
        $map = (new L2Builder(self::devices([1, 3]), [], self::ports(), [], [], new StpIndex))->build();

        $result = ChangeMarks::apply('l2', $map, $missing, [], new FactText(self::NAMES, self::VISIBLE), [], $goneAt);

        $ghost = $result['changes']['ghosts']['edges'][0];
        $this->assertSame(['d:1', 'd:3'], [$ghost['from'], $ghost['to']]);
        $this->assertSame(['type' => 'link_gone', 'time' => self::T1, 'text' => 'Link gone: core1 Te1/0/2 ↔ acc1 Gi1/0/50'], $ghost['changes'][0]);
    }

    public function testRoutingChangesOnTheL3Map(): void
    {
        $c = self::collector();
        $before = self::rows($c->ospf([['device_id' => 1, 'port_id' => 11, 'ospfNbrIpAddr' => '10.0.0.2', 'ospfNbrRtrId' => '10.255.0.2', 'ospfNbrState' => 'full',
            'context_name' => ''], ['device_id' => 2, 'port_id' => 21, 'ospfNbrIpAddr' => '10.0.0.1', 'ospfNbrRtrId' => '10.255.0.1', 'ospfNbrState' => 'full',
            'context_name' => '']], ['10.255.0.2' => 2, '10.255.0.1' => 1], ['10.0.0.2' => 2, '10.0.0.1' => 1])
            + $c->ospf([['device_id' => 1, 'port_id' => 14, 'ospfNbrIpAddr' => '172.16.0.9', 'ospfNbrRtrId' => '172.16.255.9', 'ospfNbrState' => 'full',
                'context_name' => '']], [], []), self::T0 - 86400, self::T1);
        $after = self::rows($c->bgp([['device_id' => 1, 'bgpPeerIdentifier' => '192.0.2.1', 'bgpPeerRemoteAs' => 64512, 'bgpPeerState' => 'established',
            'bgpPeerDescr' => 'ISP-A', 'context_name' => '']], []), self::T1);
        $map = [
            'nodes' => [
                ['id' => 'd:1', 'type' => 'device', 'device_id' => 1, 'name' => 'core1'],
                ['id' => 'd:2', 'type' => 'device', 'device_id' => 2, 'name' => 'core2'],
                ['id' => 'as:64512', 'type' => 'as', 'name' => 'Autonomous System 64512'],
            ],
            'edges' => [['id' => 'e0', 'kind' => 'bgp', 'from' => 'd:1', 'to' => 'as:64512', 'peers' => ['192.0.2.1'], 'a' => null, 'b' => null]],
        ];

        $result = ChangeMarks::apply('l3', $map, $before, $after, new FactText(self::NAMES, self::VISIBLE));

        $this->assertSame('BGP session established: core1 → 192.0.2.1 (AS 64512, ISP-A)', $result['edges'][0]['changes'][0]['text']);
        $ghosts = array_column($result['changes']['ghosts']['edges'], null, 'to');
        $this->assertCount(2, $ghosts, 'both routers\' reports of the lost adjacency are one ghost; the other neighbour is not monitored');
        $this->assertSame(['d:1', 'ospf', 'ospf_lost'], [$ghosts['d:2']['from'], $ghosts['d:2']['ghost_kind'], $ghosts['d:2']['changes'][0]['type']]);
        $external = $result['changes']['ghosts']['nodes'][0];
        $this->assertSame('router 172.16.255.9', $external['name']);
        // without the Changes page's events (the baseline), each router's report is a mark of its own
        $this->assertCount(2, $ghosts['d:2']['changes']);
        $this->assertEquals(['bgp_up' => 1, 'ospf_lost' => 3], $result['changes']['counts']);
    }
}
