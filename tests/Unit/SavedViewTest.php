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
        $long = 'u:' . str_repeat('n', SavedView::MAX_ID_LENGTH - 2);
        $this->assertSame([$long], array_keys(SavedView::positions([$long => [1, 1], $long . 'x' => [1, 1]])), 'ids of up to 200 characters');
    }

    public function testAViewKeepsThePositionsOfItsMapAndNoMoreThanAMegabyte(): void
    {
        $map = [['id' => 'd:1'], ['id' => 'u:core1.example.net'], ['label' => 'no id']];
        $this->assertSame([['d:1' => [1.0, 2.0], 'u:core1.example.net' => [5.0, 6.0]], null],
            SavedView::forStorage(['d:1' => [1.0, 2.0], 'd:9' => [3.0, 4.0], 'junk' => [0.0, 0.0], 'u:core1.example.net' => [5.0, 6.0]], $map));
        $this->assertSame([[], null], SavedView::forStorage([], $map));

        // neighbors with ids of 200 characters: 226 bytes a position, 4000 fit in 1 MB, 6000 do not
        $layout = function (int $nodes) {
            $ids = array_map(fn (int $i) => 'u:' . str_pad((string) $i, SavedView::MAX_ID_LENGTH - 2, 'n'), range(1, $nodes));

            return SavedView::forStorage(array_fill_keys($ids, [1234567.1, -1234567.1]), array_map(fn (string $id) => ['id' => $id], $ids));
        };
        [$kept, $error] = $layout(4000);
        $this->assertCount(4000, $kept);
        $this->assertNull($error);
        [$kept, $error] = $layout(6000);
        $this->assertCount(6000, $kept);
        $this->assertSame('The layout of this view is too large to save (more than 1 MB).', $error);
    }

    public function testAUserKeepsAtMostTwoHundredViews(): void
    {
        $this->assertNull(SavedView::tooMany(0));
        $this->assertNull(SavedView::tooMany(SavedView::MAX_PER_USER - 1));
        $this->assertSame('You keep 200 saved views, the most a user may: delete some on the Saved Views page to save another.', SavedView::tooMany(200));
        $this->assertNotNull(SavedView::tooMany(250), 'views kept before the limit');
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

    public function testWhoMayOpenAndChangeAView(): void
    {
        $personal = ['user_id' => 7, 'shared' => 0];
        $shared = ['user_id' => '7', 'shared' => '1'];

        $this->assertTrue(SavedView::canOpen($personal, 7));
        $this->assertFalse(SavedView::canOpen($personal, 8), 'another user\'s personal view');
        $this->assertTrue(SavedView::canOpen($shared, 8));
        $this->assertTrue(SavedView::canEdit($personal, 7, false));
        $this->assertFalse(SavedView::canEdit($shared, 8, false), 'shared, but not theirs');
        $this->assertTrue(SavedView::canEdit($shared, 8, true), 'an admin changes any shared view');
        $this->assertFalse(SavedView::canEdit($personal, 8, true), 'not another user\'s personal one');

        $this->assertTrue(SavedView::canDelete($personal, 7, false), 'the owner deletes their view');
        $this->assertTrue(SavedView::canDelete($shared, 8, true), 'an admin deletes any shared view');
        $this->assertFalse(SavedView::canDelete($shared, 8, false));
        $this->assertFalse(SavedView::canDelete($personal, 8, true), 'not another user\'s personal one');

        // the personal view of a user that no longer exists (joined to users: owner_id null)
        $orphan = ['user_id' => 9, 'shared' => 0, 'owner_id' => null];
        $owned = ['user_id' => 9, 'shared' => 0, 'owner_id' => 9];
        $this->assertTrue(SavedView::canOpen($orphan, 8, true), 'an admin opens it');
        $this->assertTrue(SavedView::canDelete($orphan, 8, true), 'and deletes it');
        $this->assertFalse(SavedView::canEdit($orphan, 8, true), 'but does not change it');
        $this->assertFalse(SavedView::canOpen($orphan, 8, false), 'nobody else opens it');
        $this->assertFalse(SavedView::canDelete($orphan, 8, false));
        $this->assertFalse(SavedView::canOpen($owned, 8, true), 'not when its owner exists');
        $this->assertFalse(SavedView::canDelete($owned, 8, true));
        $this->assertFalse(SavedView::canDelete($personal, 8, true), 'nor when the owner is not known (no owner_id)');
    }

    public function testAViewShowsNothingTheUserMayNotSee(): void
    {
        $row = ['id' => '3', 'user_id' => '7', 'name' => 'Core', 'page' => 'l3', 'shared' => 1, 'updated_at' => '2026-10-20 10:00:00', 'username' => 'alice',
            'query' => json_encode(MapParams::parse(['device' => '1', 'group' => '3', 'location' => '4', 'vrf' => 'CUSTOMER-B'], new PluginSettings)),
            'options' => '{"layout":"organic","evil":"x"}'];

        $hidden = SavedView::present($row, 8, false, ['device' => null, 'group' => null, 'location' => null, 'vrf' => null]);
        $this->assertSame([null, null, null, null], [$hidden['params']['device'], $hidden['params']['group'], $hidden['params']['location'], $hidden['params']['vrf']]);
        $this->assertStringNotContainsString('CUSTOMER-B', json_encode($hidden));
        $this->assertSame('a device you cannot see, 2 hops · group (one you cannot see) · location (one you cannot see) · VRF (one you cannot see)', $hidden['scope']);
        $this->assertSame(['alice', false, true, false, false, ['layout' => 'organic']],
            [$hidden['owner'], $hidden['mine'], $hidden['shared'], $hidden['can_edit'], $hidden['can_delete'], $hidden['options']]);

        $seen = SavedView::present($row, 7, false, ['device' => 'core1', 'group' => 'Core', 'location' => 'HQ', 'vrf' => 'CUSTOMER-B']);
        $this->assertSame([1, 3, 4, 'CUSTOMER-B'], [$seen['params']['device'], $seen['params']['group'], $seen['params']['location'], $seen['params']['vrf']]);
        $this->assertSame(['you', true, true, true], [$seen['owner'], $seen['mine'], $seen['can_edit'], $seen['can_delete']]);
        $this->assertSame('a former user', SavedView::present(['username' => null] + $row, 8, false, [])['owner']);

        $orphan = SavedView::present(['username' => null, 'owner_id' => null, 'shared' => 0] + $row, 8, true, []);
        $this->assertSame(['a former user', false, false, true], [$orphan['owner'], $orphan['mine'], $orphan['can_edit'], $orphan['can_delete']],
            'an admin deletes the personal view of a former user');
    }

    public function testOpeningAViewKeepsItsOverlaysAndTheUrlWins(): void
    {
        $params = MapParams::parse(['mpls' => '1', 'isis' => '0', 'vrf' => 'BLUE'], new PluginSettings);

        $query = SavedView::mergeQuery('l3', ['view' => '32'], $params);
        $this->assertArrayNotHasKey('view', $query);
        foreach (['ospfv3' => '1', 'isis' => '0', 'ipsec' => '1', 'mpls' => '1', 'pw' => '1'] as $layer => $value) {
            $this->assertSame($value, $query[$layer] ?? null, "the map page submits the $layer toggle");
        }
        $reopened = MapParams::parse($query, new PluginSettings);
        $this->assertTrue($reopened['mpls'], 'MPLS stays on');
        $this->assertFalse($reopened['isis'], 'IS-IS stays off');
        $this->assertSame('RED', SavedView::mergeQuery('l3', ['view' => '32', 'vrf' => 'RED'], $params)['vrf'], 'the URL\'s own parameters win');
    }

    public function testScopeInWordsNamesOnlyWhatTheUserMaySee(): void
    {
        $params = MapParams::parse(['device' => '1', 'depth' => '2', 'group' => '3', 'location' => '4', 'vlan' => '30', 'vrf' => 'BLUE'], new PluginSettings);

        $this->assertSame('core1, 2 hops · group Core · location HQ · VLAN 30', SavedView::describe('l2', $params, ['device' => 'core1', 'group' => 'Core', 'location' => 'HQ']));
        $this->assertSame('a device you cannot see, 2 hops · group (one you cannot see) · location (one you cannot see) · VRF (one you cannot see)',
            SavedView::describe('l3', $params, ['device' => null, 'group' => null, 'location' => null, 'vrf' => null]));
        $this->assertSame('core1, 2 hops · group Core · location HQ · VRF BLUE',
            SavedView::describe('l3', $params, ['device' => 'core1', 'group' => 'Core', 'location' => 'HQ', 'vrf' => 'BLUE']));
        $this->assertSame('whole network', SavedView::describe('l2', MapParams::parse([], new PluginSettings), []));
        $this->assertSame('sw1, 1 hop', SavedView::describe('l2', MapParams::parse(['device' => '9', 'depth' => '1'], new PluginSettings), ['device' => 'sw1']));
    }
}
