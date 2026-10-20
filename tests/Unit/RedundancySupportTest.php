<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Redundancy\CoreFinder;
use Agoodley\LibrenmsTopology\Redundancy\PortUnits;
use Agoodley\LibrenmsTopology\Support\Graph;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use PHPUnit\Framework\TestCase;

class RedundancySupportTest extends TestCase
{
    public function testCutsOfAChain(): void
    {
        $graph = (new Graph)->addEdge('r', 'a', 'ra')->addEdge('a', 'b', 'ab')->addEdge('b', 'c', 'bc');
        $cuts = $graph->cuts('r');

        $this->assertSame(['r', 'a', 'b', 'c'], $cuts['order']);
        $this->assertEquals(['a' => ['b'], 'b' => ['c']], $cuts['vertices']);
        $this->assertEquals(['ra' => 'a', 'ab' => 'b', 'bc' => 'c'], $cuts['bridges']);
        $this->assertSame(3, $cuts['tout']['a']);
    }

    public function testCycleHasNoCuts(): void
    {
        $graph = (new Graph)->addEdge('r', 'a', 1)->addEdge('a', 'b', 2)->addEdge('b', 'r', 3)->addEdge('b', 'c', 4);
        $cuts = $graph->cuts('r');

        $this->assertSame(['b' => ['c']], $cuts['vertices']);
        $this->assertSame([4 => 'c'], $cuts['bridges']);
    }

    public function testParallelEdgesProtectAgainstAnEdgeNotANode(): void
    {
        $graph = (new Graph)->addEdge('r', 'a', 'x')->addEdge('a', 'b', 'y1')->addEdge('a', 'b', 'y2');
        $cuts = $graph->cuts('r');

        $this->assertSame(['a' => ['b']], $cuts['vertices']);
        $this->assertSame(['x' => 'a'], $cuts['bridges']);
    }

    public function testCutsOfAMissingRoot(): void
    {
        $this->assertSame([], (new Graph)->addEdge('a', 'b')->cuts('r')['order']);
    }

    public function testUnitFromInterfaceName(): void
    {
        $this->assertSame(['key' => 'fpc1', 'label' => 'FPC 1'], InterfaceName::unit('xe-1/0/2'));
        $this->assertSame(['key' => 'fpc0', 'label' => 'FPC 0'], InterfaceName::unit('et-0/0/48:2'));
        $this->assertSame(['key' => 'unit2', 'label' => 'member/slot 2'], InterfaceName::unit('GigabitEthernet2/0/1'));
        $this->assertSame('unit9', InterfaceName::unit('Ten-GigabitEthernet9/0/1')['key']);
        $this->assertSame('unit1', InterfaceName::unit('1/1/48')['key']);
        $this->assertSame('unit2', InterfaceName::unit('Gi2/1/0/1')['key']);
        $this->assertSame('unit3', InterfaceName::unit('3/12')['key']);
        $this->assertSame('unit2', InterfaceName::unit('2:5')['key']);

        foreach (['Gi0/1', 'Ethernet49/1', 'Ethernet1', 'eth0', 'Gi1/0/1.100', 'A1', ''] as $name) {
            $this->assertNull(InterfaceName::unit($name), $name);
        }

        $this->assertSame(['key' => 'unit2', 'label' => 'member 2'], InterfaceName::unit('2/A1', 'procurve'));
        $this->assertSame(['key' => 'moduleB', 'label' => 'module B'], InterfaceName::unit('B4', 'procurve'));
    }

    /**
     * entPhysical row as LibreNMS stores it.
     */
    private static function entity(int $index, int $parent, string $class, string $name, ?int $ifIndex = null, string $fru = 'false'): array
    {
        return [
            'entPhysicalIndex' => $index, 'entPhysicalContainedIn' => $parent, 'entPhysicalClass' => $class, 'entPhysicalName' => $name,
            'entPhysicalDescr' => '', 'entPhysicalIsFRU' => $fru, 'ifIndex' => $ifIndex,
        ];
    }

    public function testStackMembersFromTheInventory(): void
    {
        // Cisco stack: stack > chassis (member) > fixed module > (container) > port
        $rows = [
            self::entity(1, 0, 'stack', 'c93xx Stack'),
            self::entity(1000, 1, 'chassis', 'Switch 1', null, 'true'),
            self::entity(1001, 1000, 'module', 'Switch 1 Fixed Module 0'),
            self::entity(1010, 1001, 'port', 'GigabitEthernet1/0/1', 9),
            self::entity(2000, 1, 'chassis', 'Switch 2', null, 'true'),
            self::entity(2001, 2000, 'module', 'Switch 2 Fixed Module 0'),
            self::entity(2005, 2001, 'container', 'Te2/1/1 Container'),
            self::entity(2010, 2005, 'port', 'TenGigabitEthernet2/1/1', 77),
            self::entity(2011, 2010, 'module', 'SFP-10G-LR', 77, 'true'), // transceiver inherits the ifIndex
        ];

        $this->assertSame([
            9 => ['key' => 'member1000', 'label' => 'Switch 1'],
            77 => ['key' => 'member2000', 'label' => 'Switch 2'],
        ], PortUnits::fromEntities($rows));
    }

    public function testLineCardsFromTheInventory(): void
    {
        // chassis > slot > replaceable line card > port; numeric classes and TruthValue FRU flags
        $rows = [
            self::entity(1, 0, '3', 'Chassis'),
            self::entity(10, 1, '5', 'slot 1'),
            self::entity(11, 10, '9', 'Slot 1 Linecard', null, '1'),
            self::entity(12, 11, '9', 'Port group 1-24', null, '2'),
            self::entity(13, 12, '10', 'Gi1/0/1', 101),
            self::entity(20, 1, '5', 'slot 2'),
            self::entity(21, 20, '9', 'Slot 2 Linecard', null, '1'),
            self::entity(23, 21, '10', 'Gi2/0/1', 201),
        ];

        $this->assertSame([101 => ['key' => 'module11', 'label' => 'Slot 1 Linecard'], 201 => ['key' => 'module21', 'label' => 'Slot 2 Linecard']], PortUnits::fromEntities($rows));
    }

    public function testFixedSwitchAndConflictsHaveNoUnits(): void
    {
        $fixed = [
            self::entity(1, 0, 'chassis', 'Chassis', null, 'true'),
            self::entity(2, 1, 'module', 'Fixed ports'),
            self::entity(3, 2, 'port', 'Gi0/1', 1),
        ];
        $this->assertSame([], PortUnits::fromEntities($fixed));

        // two port entities claim ifIndex 5 on different members
        $conflict = [
            self::entity(1, 0, 'stack', 'Stack'),
            self::entity(10, 1, 'chassis', 'Switch 1'),
            self::entity(11, 10, 'port', 'Gi1/0/5', 5),
            self::entity(20, 1, 'chassis', 'Switch 2'),
            self::entity(21, 20, 'port', 'Gi2/0/5', 5),
            self::entity(22, 20, 'port', 'Gi2/0/6', 6),
        ];
        $this->assertSame([6 => ['key' => 'member20', 'label' => 'Switch 2']], PortUnits::fromEntities($conflict));
    }

    public function testInventoryLoopsAreCut(): void
    {
        $rows = [self::entity(1, 2, 'chassis', 'a'), self::entity(2, 1, 'chassis', 'b'), self::entity(3, 1, 'port', 'p', 7)];

        $this->assertSame([], PortUnits::fromEntities($rows));
    }

    public function testConfiguredCorePerPart(): void
    {
        $graph = (new Graph)->addEdge('a', 'b')->addEdge('b', 'c')->addEdge('x', 'y');
        $network = ['a' => true, 'b' => true, 'c' => true, 'x' => true, 'y' => true];

        // the part without a configured core device gets an automatic one
        $this->assertSame(['c' => 'configured', 'x' => 'automatic', 'y' => 'automatic'], CoreFinder::find($graph, $network, ['c' => true]));
    }

    public function testSpanningTreeRootChosenOnPurpose(): void
    {
        $graph = (new Graph)->addEdge('a', 'b')->addEdge('b', 'c')->addEdge('c', 'd');
        $network = ['a' => true, 'b' => true, 'c' => true, 'd' => true];

        // priorities carry the VLAN in the low bits (extended system ID)
        $this->assertSame(['a' => 'stp', 'd' => 'stp'], CoreFinder::find($graph, $network, [], ['a' => 24577, 'd' => 24577, 'b' => 28673]));
        // all at the default: the root is an accident of MAC addresses, use the center instead
        $this->assertSame(['b' => 'automatic', 'c' => 'automatic'], CoreFinder::find($graph, $network, [], ['a' => 32769, 'd' => 32769]));
    }

    public function testCentralDevicesOfALeafSpineFabric(): void
    {
        $graph = new Graph;
        $network = [];
        foreach (['spine1', 'spine2'] as $spine) {
            $network[$spine] = true;
            foreach (['leaf1', 'leaf2', 'leaf3', 'leaf4'] as $leaf) {
                $graph->addEdge($spine, $leaf);
                $network[$leaf] = true;
            }
        }
        // servers and access points do not pull the center toward a leaf
        for ($i = 0; $i < 30; $i++) {
            $graph->addEdge('leaf1', "server$i");
        }

        $this->assertSame(['spine1' => 'automatic', 'spine2' => 'automatic'], CoreFinder::find($graph, $network, []));
    }
}
