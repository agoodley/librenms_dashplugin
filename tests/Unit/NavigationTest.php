<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Support\Navigation;
use PHPUnit\Framework\TestCase;

class NavigationTest extends TestCase
{
    public function testEveryPageIsComplete(): void
    {
        foreach (Navigation::PAGES as $key => $page) {
            $this->assertArrayHasKey($page['group'], Navigation::GROUPS, $key);
            foreach (['route', 'tab', 'title', 'icon', 'description'] as $field) {
                $this->assertNotSame('', (string) ($page[$field] ?? ''), "$key.$field");
            }
            $this->assertSame('topology.' . $key, $page['route'], 'route names follow the page keys');
        }
    }

    public function testGroupsKeepTheirOrderAndSkipEmptyOnes(): void
    {
        $groups = Navigation::groups();

        $this->assertSame(array_values(array_intersect(array_keys(Navigation::GROUPS), array_column(Navigation::PAGES, 'group'))), array_keys($groups));
        $this->assertSame(['l2', 'l3', 'sites'], array_keys($groups['maps']['pages']));
        $this->assertSame(['hosts', 'rollout', 'dependencies'], array_keys($groups['tools']['pages']), 'the host locator first');
        $this->assertSame('Maps', $groups['maps']['label']);
    }

    public function testGroupOfAPage(): void
    {
        $this->assertSame('diagnostics', Navigation::groupOf('stp'));
        $this->assertNull(Navigation::groupOf('nope'));
        $this->assertTrue(Navigation::scoped(Navigation::PAGES['l2']));
        $this->assertFalse(Navigation::scoped(['scoped' => false]));
    }
}
