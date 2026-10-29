<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Support\ComplianceFacts;
use PHPUnit\Framework\TestCase;

/**
 * The spanning tree page's findings that suspect edge ports without portfast say what the configuration backups
 * configure on those ports, when the compliance plugin knows them; the page is as it was without that plugin.
 */
class ComplianceFactsTest extends TestCase
{
    public function testWhatTheBackupsSayOfTheSuspectedPorts(): void
    {
        $ports = [
            103 => ['device_id' => 2, 'name' => 'GigabitEthernet1/0/3', 'mode' => 'access', 'portfast' => 'off', 'role' => 'host'],
            106 => ['device_id' => 2, 'name' => 'GigabitEthernet1/0/6', 'mode' => 'trunk', 'portfast' => 'off', 'role' => 'host'],
            101 => ['device_id' => 2, 'name' => 'GigabitEthernet1/0/1', 'mode' => 'access', 'portfast' => 'default', 'role' => 'host'],
            105 => ['device_id' => 2, 'name' => 'GigabitEthernet1/0/5', 'mode' => 'trunk', 'portfast' => 'off', 'role' => 'downstream'],
        ];
        $names = [2 => 'acc-sw1'];

        $this->assertSame('The configuration backups (compliance plugin): no portfast on acc-sw1 GigabitEthernet1/0/3 and acc-sw1 GigabitEthernet1/0/6 (a trunk), '
            . 'facing a host: portfast would end their topology changes; portfast already on acc-sw1 GigabitEthernet1/0/1; acc-sw1 GigabitEthernet1/0/5 faces a switch: '
            . 'portfast is no fix there, the link is.', ComplianceFacts::line([103, 106, 101, 105, 999], $ports, $names));
        $this->assertSame('The configuration backups (compliance plugin): no portfast on acc-sw1 GigabitEthernet1/0/3, facing a host: portfast would end its topology changes.',
            ComplianceFacts::line([103], $ports, $names));
        $this->assertNull(ComplianceFacts::line([999], $ports, $names), 'no backup says anything of the port');

        $many = [];
        foreach (range(1, 10) as $i) {
            $many[$i] = ['device_id' => 7, 'name' => "Ethernet$i", 'mode' => 'access', 'portfast' => 'off', 'role' => 'host'];
        }
        $this->assertStringContainsString('#7 Ethernet8 and 2 more, facing a host', ComplianceFacts::line(range(1, 10), $many, []));
    }

    public function testWithoutTheCompliancePluginThePageIsAsItWas(): void
    {
        $result = ['findings' => [['code' => 'port_flapping', 'devices' => [2], 'ports' => [103]]], 'device_names' => [2 => 'acc-sw1']];
        $this->assertFalse(class_exists(ComplianceFacts::API), 'the compliance plugin is not installed here');
        $this->assertSame($result, ComplianceFacts::annotate('stp', $result, null));
        $this->assertSame($result, ComplianceFacts::annotate('links', $result, null));
    }
}
