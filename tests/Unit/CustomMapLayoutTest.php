<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Sharing\CustomMapLayout;
use PHPUnit\Framework\TestCase;

class CustomMapLayoutTest extends TestCase
{
    private const STYLE = ['text_face' => 'arial', 'text_size' => 14, 'text_colour' => '#343434', 'edge_text_size' => 12, 'node_size' => 25];

    private static function device(int $id, string $role, string $label = ''): array
    {
        return ['id' => 'd:' . $id, 'type' => 'device', 'managed' => true, 'device_id' => $id, 'role' => $role, 'label' => $label ?: 'dev' . $id];
    }

    private static function neighbor(string $name, string $role): array
    {
        return ['id' => 'u:' . $name, 'type' => 'unmanaged', 'managed' => false, 'device_id' => null, 'role' => $role, 'label' => $name];
    }

    private static function link(string $from, string $to, ?int $portA, ?int $portB = null, int $speed = 10000000000, ?array $lag = null): array
    {
        return ['from' => $from, 'to' => $to, 'a' => ['port_id' => $portA], 'b' => $portB === null ? null : ['port_id' => $portB], 'speed' => $speed, 'lag' => $lag];
    }

    private static function build(array $nodes, array $edges, array $positions, ?array $images = null): array
    {
        return CustomMapLayout::build(['nodes' => $nodes, 'edges' => $edges], $positions, $images ?? array_values(CustomMapLayout::IMAGES), self::STYLE);
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

    public function testNodesGetLibreNmsIconsByRole(): void
    {
        $nodes = [
            self::device(1, 'router'), self::device(2, 'l3switch'), self::device(3, 'server'),
            self::neighbor('phone-1', 'phone'), self::neighbor('sw-x', 'switch'), self::neighbor('box', 'unknown'),
            ['id' => 's:abc', 'type' => 'subnet', 'managed' => false, 'role' => 'subnet', 'label' => "10.0.0.0/24\nBLUE"],
            ['id' => 'as:65001', 'type' => 'as', 'managed' => false, 'role' => 'cloud', 'label' => 'AS65001'],
        ];
        $layout = self::build($nodes, [], []);
        $n = $layout['nodes'];

        $this->assertSame(['image', 'router.svg', 1], [$n['d:1']['style'], $n['d:1']['image'], $n['d:1']['device_id']]);
        $this->assertSame('switch-l3.svg', $n['d:2']['image']);
        $this->assertSame(['icon', 'f233', null], [$n['d:3']['style'], $n['d:3']['icon'], $n['d:3']['colour_bdr']], 'LibreNMS colors a device icon by status');
        $this->assertSame(['icon', 'f3ce', null], [$n['u:phone-1']['style'], $n['u:phone-1']['icon'], $n['u:phone-1']['device_id']]);
        $this->assertSame(['image', 'switch-l2.svg'], [$n['u:sw-x']['style'], $n['u:sw-x']['image']]);
        $this->assertSame(['icon', 'f6ff'], [$n['u:box']['style'], $n['u:box']['icon']]);
        $this->assertSame(['box', '#FFF6D5', "10.0.0.0/24\nBLUE"], [$n['s:abc']['style'], $n['s:abc']['colour_bg'], $n['s:abc']['label']]);
        $this->assertSame(['icon', 'f0c2'], [$n['as:65001']['style'], $n['as:65001']['icon']]);
        $this->assertSame(25, $n['d:1']['size']);
        $this->assertLessThan(25, $n['u:phone-1']['size']);
        $this->assertSame(['devices' => 3, 'unmanaged' => 3, 'subnets' => 1, 'clouds' => 1], array_intersect_key($layout['stats'], array_flip(['devices', 'unmanaged', 'subnets', 'clouds'])));
    }

    public function testRolesWithoutTheirImageFallBack(): void
    {
        $layout = self::build([self::device(1, 'router'), self::neighbor('sw-x', 'switch')], [], [], ['firewall.svg']);

        $this->assertSame(['icon', 'f4d7', ''], [$layout['nodes']['d:1']['style'], $layout['nodes']['d:1']['icon'], $layout['nodes']['d:1']['image']]);
        $this->assertSame(['icon', 'f6ff', '#8B9CAD'], [$layout['nodes']['u:sw-x']['style'], $layout['nodes']['u:sw-x']['icon'], $layout['nodes']['u:sw-x']['colour_bdr']]);
    }

    public function testLabelsFitLibreNmsColumns(): void
    {
        $layout = self::build([self::device(1, 'router', str_repeat('ü', 60))], [], []);

        $this->assertSame(str_repeat('ü', 50), $layout['nodes']['d:1']['label']);
    }

    public function testLinksTakeThePortOfTheNearEndElseTheFarEnd(): void
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
