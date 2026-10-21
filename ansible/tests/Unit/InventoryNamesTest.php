<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Inventory\GroupNames;
use Agoodley\LibrenmsAnsible\Inventory\HostNames;
use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use PHPUnit\Framework\TestCase;

class InventoryNamesTest extends TestCase
{
    public function testSafeHostNames(): void
    {
        foreach (['sw1', 'core1.example.net', 'acc_sw-2', '10.1.2.3', '_x', str_repeat('a', 253)] as $name) {
            $this->assertTrue(HostNames::isSafe($name), $name);
        }
        foreach (['', '-x', '.x', 'a b', '2001:db8::1', 'a,b', 'a:b', '!a', 'a&b', 'a*', 'a?', 'a[1]', '~a', '@file', "a\n",
            'ü-sw', str_repeat('a', 254)] as $name) {
            $this->assertFalse(HostNames::isSafe($name), $name);
        }
    }

    public function testAssignInIdOrder(): void
    {
        $names = HostNames::assign([7 => 'sw1', 3 => 'SW1', 5 => 'ungrouped', 9 => 'Device12', 2 => 'web', 4 => 'group_core'], ['group_core']);

        $this->assertSame([2 => 'web', 3 => 'SW1', 4 => 'device4', 5 => 'device5', 7 => 'device7', 9 => 'device9'], $names);
    }

    public function testGroupSlugs(): void
    {
        $this->assertSame('location_lab_a_b_west', GroupNames::slug('location', 'Lab "A&B" <west>'));
        $this->assertSame('location_zurich_hb', GroupNames::slug('location', 'Zürich HB'));
        $this->assertSame('location_7', GroupNames::slug('location', '東京', '7'));
        $this->assertSame('os_arista_eos', GroupNames::slug('os', 'arista_eos'));
        $this->assertSame(64 + strlen('group_'), strlen(GroupNames::slug('group', str_repeat('ab ', 50))));
    }

    public function testGroupCollisionsAreNumbered(): void
    {
        $taken = [];
        $names = GroupNames::assign('group', [3 => 'lab 2', 1 => 'Lab', 2 => 'lab'], $taken);

        $this->assertSame([1 => 'group_lab', 2 => 'group_lab_2', 3 => 'group_lab_2_2'], $names);
        $this->assertArrayHasKey('group_lab_2_2', $taken);
    }

    public function testUntrustedTextKeepsASafeSet(): void
    {
        $this->assertSame('core-1.example.net', UntrustedText::clean('core-1.example.net'));
        $this->assertSame('Bldg 1, Floor 2 (west) 51.5,-0.12', UntrustedText::clean("Bldg 1, Floor 2 (west)\n[51.5,-0.12]"));
        $this->assertSame('lookup(pipe,id)', UntrustedText::clean("{{ lookup('pipe','id') }}"));
        $this->assertSame('rm -rf (x)', UntrustedText::clean('`rm -rf`; $(x) | & ! ? * ~ ^ \\'));
        $this->assertSame('Zürich', UntrustedText::clean('Zürich'));
        $this->assertSame('a b', UntrustedText::clean("a\u{202E}\u{200B}b"));
        $this->assertSame('ab', UntrustedText::clean("a\xff\xfeb"), 'invalid UTF-8 is dropped');
        $this->assertSame(str_repeat('x', 128), UntrustedText::clean(str_repeat('x', 500)));
        $this->assertSame('', UntrustedText::clean(null));
        $this->assertSame('', UntrustedText::clean(['x']));
        $this->assertSame('12', UntrustedText::clean(12));
    }

    public function testUntrustedLineOnlyDropsControls(): void
    {
        $this->assertSame("Port 'descriptions' {x}", UntrustedText::line("Port\n'descriptions'\t{x}"));
        $this->assertSame('abcd…', UntrustedText::line('abcdefgh', 5));
    }
}
