<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Support\PortStack;
use PHPUnit\Framework\TestCase;

class PortStackTest extends TestCase
{
    private static function ports(): array
    {
        $port = fn (int $id, string $name, string $type) => [$id => ['port_id' => $id, 'ifName' => $name, 'ifDescr' => $name, 'ifType' => $type]];

        return $port(1, 'Port-channel1', 'propVirtual') + $port(2, 'GigabitEthernet1/0/1', 'ethernetCsmacd')
            + $port(3, 'GigabitEthernet1/0/2', 'ethernetCsmacd') + $port(4, 'Port-channel1.100', 'l2vlan')
            + $port(5, 'Vlan100', 'propVirtual') + $port(10, 'ae0', 'ieee8023adLag') + $port(11, 'xe-0/0/0', 'ethernetCsmacd')
            + $port(12, 'ae0.0', 'propVirtual') + $port(13, 'irb.300', 'other') + $port(20, 'Trk1', 'ieee8023adLag')
            + $port(21, '1', 'ethernetCsmacd') + $port(30, 'bond0', 'ieee8023adLag') + $port(31, 'eth0', 'ethernetCsmacd')
            + $port(40, 'GigabitEthernet0/0/0', 'ethernetCsmacd') + $port(41, 'GigabitEthernet0/0/0.200', 'l2vlan');
    }

    public function testAggregateIsFoundWhicheverSideItIsStoredOn(): void
    {
        // LibreNMS 24.8 and later keep the higher layer (the port-channel) in low_port_id: rows are [high, low]
        $this->assertSame([2 => 1, 3 => 1], PortStack::lagMembers([[2, 1], [3, 1]], self::ports()));
        // before 24.8 the higher layer was port_id_high
        $this->assertSame([2 => 1, 3 => 1], PortStack::lagMembers([[1, 2], [1, 3]], self::ports()));
    }

    public function testVendorsNamingTheirAggregates(): void
    {
        $this->assertSame([11 => 10, 21 => 20, 31 => 30], PortStack::lagMembers([[11, 10], [20, 21], [31, 30]], self::ports()));
    }

    public function testSubInterfacesAndVlanInterfacesAreNotMembers(): void
    {
        // Po1.100 over Po1, ae0.0 / irb.300 over ae0, Vlan100 over Po1, a dot1q sub-interface over its port
        $pairs = [[4, 1], [1, 4], [12, 10], [13, 10], [5, 1], [41, 40], [40, 41]];

        $this->assertSame([], PortStack::lagMembers($pairs, self::ports()));
    }

    public function testFirstAggregateWinsAndUnknownPortsAreSkipped(): void
    {
        $this->assertSame([2 => 1], PortStack::lagMembers([[2, 1], [2, 10], [2, 2], [99, 1], [3, 99]], self::ports()));
    }
}
