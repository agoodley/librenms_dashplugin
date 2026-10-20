<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Topology\L2Builder;
use PHPUnit\Framework\TestCase;

class L2BuilderTest extends TestCase
{
    private function build(array $options = [], ?array $stpPorts = null): array
    {
        $stp = new StpIndex(CampusFixture::stpInstances(), $stpPorts ?? CampusFixture::stpPorts(), CampusFixture::lagParent());
        $builder = new L2Builder(
            CampusFixture::devices(),
            CampusFixture::links(),
            CampusFixture::ports(),
            CampusFixture::portVlans(),
            CampusFixture::lagParent(),
            $stp,
            [1 => [1 => 'default', 10 => 'users', 20 => 'voice'], 2 => [1 => 'default', 10 => 'users', 20 => 'voice'], 3 => [1 => 'default', 10 => 'users'], 4 => [1 => 'default', 20 => 'voice']],
        );

        return $builder->build($options);
    }

    private function edgeBetween(array $result, string $a, string $b): array
    {
        $edges = array_values(array_filter($result['edges'], fn ($e) => ($e['from'] === $a && $e['to'] === $b) || ($e['from'] === $b && $e['to'] === $a)));
        $this->assertNotEmpty($edges, "no edge $a - $b");

        return $edges;
    }

    public function testLinksAreDeduplicatedAndLagsCollapsed(): void
    {
        $result = $this->build();

        // 4 switches + 1 phone, links: Po1, 4 uplinks, phone
        $this->assertCount(5, $result['nodes']);
        $this->assertCount(6, $result['edges']);

        [$po] = $this->edgeBetween($result, 'd:1', 'd:2');
        $this->assertSame('Po1', $po['a']['short']);
        $this->assertSame('Po1', $po['b']['short']);
        $this->assertSame(2, $po['lag']['members']);
        $this->assertSame(20000000000, $po['speed']);
        $this->assertCount(2, $po['members']);

        [$uplink] = $this->edgeBetween($result, 'd:1', 'd:3');
        $this->assertEqualsCanonicalizing(['cdp', 'lldp'], $uplink['protocols']);
        $this->assertSame('Te1/0/3', $uplink['a']['short']);
        $this->assertSame('Gi1/0/49', $uplink['b']['short']);
        $this->assertSame(1000000000, $uplink['speed']);

        $phone = array_values(array_filter($result['nodes'], fn ($n) => $n['type'] === 'unmanaged'));
        $this->assertCount(1, $phone);
        $this->assertSame('phone', $phone[0]['role']);
    }

    public function testWithoutLagCollapseMembersAreParallelEdges(): void
    {
        $result = $this->build(['collapseLag' => false, 'unmanaged' => false]);
        $edges = $this->edgeBetween($result, 'd:1', 'd:2');
        $this->assertCount(2, $edges);
        $this->assertSame(2, $edges[0]['parallel']['count']);
        $this->assertCount(4, $result['nodes']);
    }

    public function testStpOverlay(): void
    {
        $result = $this->build();
        $nodes = array_column($result['nodes'], null, 'id');
        $this->assertTrue($nodes['d:1']['stp']['root']);
        $this->assertFalse($nodes['d:3']['stp']['root']);
        $this->assertSame('Gi1/0/49', $nodes['d:3']['stp']['rootPort']);
        $this->assertSame(0, $nodes['d:1']['level']);

        [$blocked] = $this->edgeBetween($result, 'd:2', 'd:3');
        $this->assertTrue($blocked['blocked']);
        $this->assertSame('designated', $blocked['a']['stp']['role']);
        $this->assertSame('alternate', $blocked['b']['stp']['role']);

        // STP runs on the port-channel, members inherit it
        [$po] = $this->edgeBetween($result, 'd:1', 'd:2');
        $this->assertSame('root', $po['b']['stp']['role']);
        $this->assertSame(2, $result['stats']['blocked']);
    }

    public function testVlanFilter(): void
    {
        $result = $this->build(['vlan' => 20]);
        $nodes = array_column($result['nodes'], null, 'id');
        $this->assertFalse($nodes['d:3']['vlanMember']);
        $this->assertTrue($nodes['d:4']['vlanMember']);

        [$acc1] = $this->edgeBetween($result, 'd:1', 'd:3');
        $this->assertFalse($acc1['carriesVlan']);
        [$acc2] = $this->edgeBetween($result, 'd:1', 'd:4');
        $this->assertTrue($acc2['carriesVlan']);
        $this->assertArrayHasKey(20, $result['vlans']);
    }

    public function testUnmanagedNeighbourBehindRootPortIsSuspectedRoot(): void
    {
        $rogue = '50c7bf123456';
        $instances = CampusFixture::stpInstances();
        foreach ($instances as &$instance) {
            $instance['designatedRoot'] = $rogue;
            $instance['rootBridge'] = 0;
            if ($instance['device_id'] === 3) {
                $instance['rootPort'] = 33; // toward the unmanaged neighbour
            }
        }
        $stp = new StpIndex($instances, CampusFixture::stpPorts(), CampusFixture::lagParent());
        $builder = new L2Builder(CampusFixture::devices(), CampusFixture::links(), CampusFixture::ports(), CampusFixture::portVlans(), CampusFixture::lagParent(), $stp);
        $nodes = array_column($builder->build()['nodes'], null, 'id');

        $this->assertTrue($nodes['u:sep001122334455']['stp']['suspected']);
        $this->assertSame('50:c7:bf:12:34:56', $nodes['u:sep001122334455']['stp']['designatedRoot']);
        $this->assertFalse($nodes['d:1']['stp']['root']);
    }

    public function testScopeAroundDevice(): void
    {
        $ids = L2Builder::scopeDevices(CampusFixture::links(), [1, 2, 3, 4], 3, 1);
        $this->assertSame([3, 1, 2], $ids);
        $this->assertSame([1, 2, 3, 4], L2Builder::scopeDevices(CampusFixture::links(), [1, 2, 3, 4], null, 1));
    }

    public function testReverseLinksWithoutRemotePortAreMerged(): void
    {
        $ports = CampusFixture::ports();
        $links = [
            ['local_device_id' => 1, 'local_port_id' => 13, 'remote_device_id' => 3, 'remote_port_id' => null, 'remote_hostname' => 'acc1', 'remote_port' => 'GigabitEthernet1/0/49', 'protocol' => 'lldp'],
            ['local_device_id' => 3, 'local_port_id' => 31, 'remote_device_id' => 1, 'remote_port_id' => null, 'remote_hostname' => 'core1', 'remote_port' => 'Te1/0/3', 'protocol' => 'lldp'],
        ];
        $builder = new L2Builder(array_intersect_key(CampusFixture::devices(), [1 => 1, 3 => 3]), $links, $ports, [], [], new StpIndex());
        $result = $builder->build();
        $this->assertCount(1, $result['edges']);
        $this->assertSame(31, $result['edges'][0]['b']['port_id']);
    }
}
