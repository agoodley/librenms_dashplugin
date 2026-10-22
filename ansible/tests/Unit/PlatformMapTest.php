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
        $this->assertSame('cisco.ios.ios', $map->for('iosxe')['network_os'], 'the lines add to the defaults');
        $this->assertCount(3, $map->errors());
        $this->assertStringContainsString('line 5 (bad os here)', $map->errors()[0]);
        $this->assertStringContainsString('network os', $map->errors()[1]);
        $this->assertStringContainsString('"{{x}}"', $map->errors()[2]);
    }

    public function testLinesAddToTheDefaultsReplaceThemOrSwitchThemOff(): void
    {
        $map = PlatformMap::parse("asa cisco.asa.asa network_cli become\nnxos cisco.nxos.nxos httpapi\niosxe -\nfoo -");

        $this->assertSame('cisco.asa.asa', $map->for('asa')['network_os'], 'added');
        $this->assertSame('ansible.netcommon.httpapi', $map->for('nxos')['connection'], 'replaced');
        $this->assertNull($map->for('iosxe'), 'switched off');
        $this->assertSame('cisco.ios.ios', $map->for('ios')['network_os'], 'the other defaults stay');
        $this->assertCount(10, $map->all());
        $this->assertSame([], $map->errors());
        $rows = $map->rows();
        $this->assertSame('line', $rows['asa']['source']);
        $this->assertSame('line', $rows['nxos']['source']);
        $this->assertSame(['platform' => null, 'source' => 'off'], $rows['iosxe']);
        $this->assertSame('default', $rows['ios']['source']);
        $this->assertStringContainsString('takes nothing after', PlatformMap::parse('iosxe - become')->errors()[0]);
    }

    public function testDescribeWritesAPlatformAsALine(): void
    {
        $map = PlatformMap::parse("ios cisco.ios.ios become\nx a.b.c community.general.linux\ny a.b ssh");

        $this->assertSame('cisco.ios.ios network_cli become', PlatformMap::describe($map->for('ios')));
        $this->assertSame('a.b.c community.general.linux', PlatformMap::describe($map->for('x')));
        $this->assertSame('a.b ssh', PlatformMap::describe($map->for('y')));
    }

    public function testHostVars(): void
    {
        $this->assertSame(['ansible_network_os' => 'vyos.vyos.vyos', 'ansible_connection' => 'ansible.netcommon.network_cli'],
            PlatformMap::hostVars(['network_os' => 'vyos.vyos.vyos', 'connection' => 'ansible.netcommon.network_cli', 'become' => false]));
        $this->assertSame(['ansible_network_os' => 'x', 'ansible_connection' => 'y', 'ansible_become' => true, 'ansible_become_method' => 'enable'],
            PlatformMap::hostVars(['network_os' => 'x', 'connection' => 'y', 'become' => true]));
    }
}
