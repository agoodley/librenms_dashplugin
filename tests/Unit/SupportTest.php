<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Support\Cidr;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\Graph;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function testInterfaceNamesAreAbbreviated(): void
    {
        $this->assertSame('Gi1/0/1', InterfaceName::short('GigabitEthernet1/0/1'));
        $this->assertSame('Te1/1/1', InterfaceName::short('TenGigabitEthernet1/1/1'));
        $this->assertSame('Hu1/0/49', InterfaceName::short('HundredGigE1/0/49'));
        $this->assertSame('Po10', InterfaceName::short('Port-channel10'));
        $this->assertSame('Eth1/1', InterfaceName::short('Ethernet1/1'));
        $this->assertSame('Vl100', InterfaceName::short('Vlan100'));
        $this->assertSame('xe-0/0/1', InterfaceName::short('xe-0/0/1'));
        $this->assertSame('Ethernet-Switch', InterfaceName::short('Ethernet-Switch'));
        $this->assertSame(InterfaceName::normalize('Gi1/0/1'), InterfaceName::normalize('GigabitEthernet1/0/1'));
        $this->assertTrue(InterfaceName::isLagName('Port-channel1'));
        $this->assertTrue(InterfaceName::isLagName('ae0'));
        $this->assertTrue(InterfaceName::isLagName('bond0'));
        $this->assertFalse(InterfaceName::isLagName('GigabitEthernet1/0/1'));
    }

    public function testCidr(): void
    {
        $this->assertSame('10.1.2.0/24', Cidr::network('10.1.2.77', 24));
        $this->assertSame('10.0.0.4/30', Cidr::network('10.0.0.6', 30));
        $this->assertSame('192.168.1.1/32', Cidr::network('192.168.1.1', 32));
        $this->assertSame('2001:db8:1::/64', Cidr::network('2001:db8:1::25', 64));
        $this->assertNull(Cidr::network('not-an-ip', 24));
        $this->assertNull(Cidr::network('10.0.0.1', 33));
        $this->assertTrue(Cidr::contains('10.0.0.0/8', '10.20.30.0/24'));
        $this->assertFalse(Cidr::contains('10.0.0.0/8', '192.168.0.1'));
        $this->assertTrue(Cidr::contains('2001:db8::/32', '2001:db8:5::1'));
        $this->assertFalse(Cidr::contains('10.0.0.0/8', '2001:db8::1'));
        $this->assertTrue(Cidr::isIgnorable('127.0.0.1'));
        $this->assertTrue(Cidr::isIgnorable('169.254.10.1'));
        $this->assertTrue(Cidr::isIgnorable('fe80::1'));
        $this->assertFalse(Cidr::isIgnorable('10.0.0.1'));
    }

    public function testGraphDistancesAndComponents(): void
    {
        $graph = (new Graph)->addEdge(1, 2)->addEdge(2, 3)->addEdge(3, 4)->addEdge(10, 11);
        $this->assertSame([1 => 0, 2 => 1, 3 => 2], $graph->distances(1, 2));
        $this->assertCount(2, $graph->components());
        $this->assertNull($graph->findCycle());
    }

    public function testGraphFindsCycles(): void
    {
        $triangle = (new Graph)->addEdge(1, 2)->addEdge(2, 3)->addEdge(3, 1)->addEdge(3, 4);
        $cycle = $triangle->findCycle();
        $this->assertNotNull($cycle);
        $this->assertSame($cycle[0], end($cycle));
        $this->assertEqualsCanonicalizing([1, 2, 3], array_unique(array_slice($cycle, 0, -1)));

        // two parallel links between the same switches form a loop
        $parallel = (new Graph)->addEdge('a', 'b', 'x')->addEdge('a', 'b', 'y');
        $this->assertNotNull($parallel->findCycle());
    }

    public function testDeviceRoles(): void
    {
        $this->assertSame(DeviceRole::FIREWALL, DeviceRole::classify(['type' => 'firewall']));
        $this->assertSame(DeviceRole::L3SWITCH, DeviceRole::classify(['type' => 'network'], ['bridge' => true, 'l3_interfaces' => 5]));
        $this->assertSame(DeviceRole::SWITCH, DeviceRole::classify(['type' => 'network'], ['vlans' => 20, 'l3_interfaces' => 1]));
        $this->assertSame(DeviceRole::ROUTER, DeviceRole::classify(['type' => 'network', 'os' => 'iosxr']));
        $this->assertSame(DeviceRole::ROUTER, DeviceRole::classify(['type' => 'network'], ['l3_interfaces' => 4]));
        $this->assertSame(DeviceRole::PHONE, DeviceRole::fromPlatform('Cisco IP Phone 8845'));
        $this->assertSame(DeviceRole::WIRELESS, DeviceRole::fromPlatform('cisco AIR-AP2802I-E-K9'));
        $this->assertSame(DeviceRole::SWITCH, DeviceRole::fromPlatform('cisco WS-C2960X-48FPD-L'));
        $this->assertSame(DeviceRole::SERVER, DeviceRole::fromPlatform('VMware ESX'));
        $this->assertSame(DeviceRole::UNKNOWN, DeviceRole::fromPlatform(''));
    }

    public function testSettingsAndParams(): void
    {
        $settings = new PluginSettings(['max_nodes' => '50', 'stp_preferred_roots' => "core1, core2\ncore3", 'show_unmanaged' => '0']);
        $this->assertSame(50, $settings->int('max_nodes'));
        $this->assertSame(2, $settings->int('default_depth'));
        $this->assertSame(['core1', 'core2', 'core3'], $settings->list('stp_preferred_roots'));
        $this->assertFalse($settings->bool('show_unmanaged'));

        $params = MapParams::parse(['device' => '12', 'depth' => '99', 'vlan' => 'abc', 'lag' => '0'], $settings);
        $this->assertSame(12, $params['device']);
        $this->assertSame(10, $params['depth']);
        $this->assertNull($params['vlan']);
        $this->assertFalse($params['lag']);
        $this->assertFalse($params['unmanaged']); // from settings
        $this->assertTrue($params['ospf']);
    }
}
