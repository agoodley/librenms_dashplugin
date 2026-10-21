<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Inventory\PlatformMap;
use PHPUnit\Framework\TestCase;

class PlatformMapTest extends TestCase
{
    public function testDefaults(): void
    {
        $map = PlatformMap::parse('');

        $this->assertSame(['network_os' => 'cisco.ios.ios', 'connection' => 'ansible.netcommon.network_cli', 'become' => true], $map->for('iosxe'));
        $this->assertSame(['network_os' => 'cisco.nxos.nxos', 'connection' => 'ansible.netcommon.network_cli', 'become' => false], $map->for('NXOS'));
        $this->assertNull($map->for('linux'));
        $this->assertNull($map->for('asa'), 'cisco.asa is not in the runner');
        $this->assertSame([], $map->errors());
        $this->assertCount(10, $map->all());
    }

    public function testCustomLinesCommentsAndErrors(): void
    {
        $map = PlatformMap::parse("# my mapping\nios cisco.ios.ios become # enable first\nnxos cisco.nxos.nxos httpapi\n"
            . "eos arista.eos.eos ansible.netcommon.network_cli\nbad os here\nIOS-XR\nfoo bar.baz {{x}}\nios cisco.ios.ios");

        $this->assertSame(['network_os' => 'cisco.ios.ios', 'connection' => 'ansible.netcommon.network_cli', 'become' => false], $map->for('ios'),
            'a later line for the same os wins');
        $this->assertSame('ansible.netcommon.httpapi', $map->for('nxos')['connection']);
        $this->assertSame('ansible.netcommon.network_cli', $map->for('eos')['connection']);
        $this->assertNull($map->for('iosxe'), 'a custom mapping replaces the defaults');
        $this->assertCount(3, $map->errors());
        $this->assertStringContainsString('line 5 (bad os here)', $map->errors()[0]);
        $this->assertStringContainsString('network os', $map->errors()[1]);
        $this->assertStringContainsString('"{{x}}"', $map->errors()[2]);
    }

    public function testHostVars(): void
    {
        $this->assertSame(['ansible_network_os' => 'vyos.vyos.vyos', 'ansible_connection' => 'ansible.netcommon.network_cli'],
            PlatformMap::hostVars(['network_os' => 'vyos.vyos.vyos', 'connection' => 'ansible.netcommon.network_cli', 'become' => false]));
        $this->assertSame(['ansible_network_os' => 'x', 'ansible_connection' => 'y', 'ansible_become' => true, 'ansible_become_method' => 'enable'],
            PlatformMap::hostVars(['network_os' => 'x', 'connection' => 'y', 'become' => true]));
    }
}
