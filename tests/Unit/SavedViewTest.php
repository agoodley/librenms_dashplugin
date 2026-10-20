<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Sharing\SavedView;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

class SavedViewTest extends TestCase
{
    public function testQueryOfAViewKeepsTheValuesOfItsMap(): void
    {
        $params = MapParams::parse(['device' => '12', 'depth' => '3', 'vlan' => '30', 'unmanaged' => '0', 'vrf' => 'BLUE'], new PluginSettings);

        $l2 = SavedView::query('l2', $params);
        $this->assertSame(['device' => '12', 'depth' => '3', 'vlan' => '30', 'unmanaged' => '0'], array_intersect_key($l2, array_flip(['device', 'depth', 'vlan', 'unmanaged'])));
        $this->assertArrayNotHasKey('vrf', $l2);
        $this->assertArrayNotHasKey('ospf', $l2);
        $this->assertArrayNotHasKey('group', $l2, 'empty values are left out');

        $l3 = SavedView::query('l3', $params);
        $this->assertSame('BLUE', $l3['vrf']);
        $this->assertArrayNotHasKey('vlan', $l3);
        $this->assertSame('1', $l3['ospf']);

        $this->assertArrayNotHasKey('depth', SavedView::query('l2', MapParams::parse(['location' => '2'], new PluginSettings)), 'hops need a centered device');
        $this->assertEquals($params, MapParams::parse(SavedView::query('l2', $params) + ['vrf' => 'BLUE'] + SavedView::query('l3', $params), new PluginSettings),
            'the query reads back as the same parameters');
    }

    public function testPositionsFromTheBrowserAreChecked(): void
    {
        $positions = SavedView::positions([
            'd:1' => ['x' => 10.04, 'y' => -20.06],
            'u:phone' => [5, '7.5'],
            'd:2' => ['x' => 'left', 'y' => 1],
            'd:3' => [1e8, 0],
            '' => [1, 1],
            'd:4' => 'nope',
            str_repeat('x', 301) => [1, 1],
            7 => [1, 1],
        ]);

        $this->assertSame(['d:1' => [10.0, -20.1], 'u:phone' => [5.0, 7.5]], $positions);
        $this->assertSame(['d:1' => [1.0, 2.0]], SavedView::positions('{"d:1": [1, 2]}'));
        $this->assertSame([], SavedView::positions('not json'));
        $this->assertCount(SavedView::MAX_POSITIONS, SavedView::positions(array_fill_keys(array_map(fn ($i) => "d:$i", range(1, SavedView::MAX_POSITIONS + 5)), [1, 1])));
    }

    public function testOnlyTheNodesOfTheViewersMapGetPositions(): void
    {
        $positions = ['d:1' => [1, 2], 'd:5' => [3, 4], 'u:secret-host.example.net' => [5, 6]];
        $map = [['id' => 'd:5'], ['id' => 'd:6'], ['id' => 'u:core1.example.net']];

        $this->assertSame(['d:5' => ['x' => 3.0, 'y' => 4.0]], SavedView::forMap($positions, $map));
        $this->assertSame([], SavedView::forMap([], $map));
    }

    public function testOptionsAndNames(): void
    {
        $this->assertSame(['layout' => 'organic', 'colorBy' => 'util', 'stp' => false],
            SavedView::options(['layout' => 'organic', 'colorBy' => 'util', 'stp' => false, 'linkLabels' => 'yes', 'evil' => '<script>', 'outages' => null]));
        $this->assertSame([], SavedView::options('nope'));
        $this->assertSame('Core "A" & B', SavedView::name("  Core \"A\"\t& B \n"));
        $this->assertSame(100, mb_strlen(SavedView::name(str_repeat('ü', 150))));
        $this->assertNull(SavedView::name('   '));
        $this->assertNull(SavedView::name(['x']));
    }

    public function testScopeInWordsNamesOnlyWhatTheUserMaySee(): void
    {
        $params = MapParams::parse(['device' => '1', 'depth' => '2', 'group' => '3', 'location' => '4', 'vlan' => '30', 'vrf' => 'BLUE'], new PluginSettings);

        $this->assertSame('core1, 2 hops · group Core · location HQ · VLAN 30', SavedView::describe('l2', $params, ['device' => 'core1', 'group' => 'Core', 'location' => 'HQ']));
        $this->assertSame('a device you cannot see, 2 hops · group (one you cannot see) · location (one you cannot see) · VRF BLUE',
            SavedView::describe('l3', $params, ['device' => null, 'group' => null, 'location' => null]));
        $this->assertSame('whole network', SavedView::describe('l2', MapParams::parse([], new PluginSettings), []));
        $this->assertSame('sw1, 1 hop', SavedView::describe('l2', MapParams::parse(['device' => '9', 'depth' => '1'], new PluginSettings), ['device' => 'sw1']));
    }
}
