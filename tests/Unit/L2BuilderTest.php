<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Topology\L2Builder;
use PHPUnit\Framework\TestCase;

class L2BuilderTest extends TestCase
{
    private function build(array $options = [], ?array $stpPorts = null, ?array $links = null, array $groups = []): array
    {
        $stp = new StpIndex(CampusFixture::stpInstances(), $stpPorts ?? CampusFixture::stpPorts(), CampusFixture::lagParent());
        $builder = new L2Builder(
            CampusFixture::devices(),
            $links ?? CampusFixture::links(),
            CampusFixture::ports(),
            CampusFixture::portVlans(),
            CampusFixture::lagParent(),
            $stp,
            [1 => [1 => 'default', 10 => 'users', 20 => 'voice'], 2 => [1 => 'default', 10 => 'users', 20 => 'voice'], 3 => [1 => 'default', 10 => 'users'], 4 => [1 => 'default', 20 => 'voice']],
            $groups,
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

    public function testEndDevicesOfALargeMapAreGroupedPerSwitchAndKind(): void
    {
        // the phone's row is not among the links: the service counted it and the like (TopologyService::l2())
        $links = array_values(array_filter(CampusFixture::links(), fn (array $link) => ! empty($link['remote_device_id'])));
        $names = array_map(fn ($i) => sprintf('SEP%012d', $i), range(1, 25));
        $groups = [3 => ['phone' => ['count' => 30, 'names' => $names], 'wireless' => ['count' => 1, 'names' => ['ap-1']]]];
        $result = $this->build([], null, $links, $groups);

        $phones = array_values(array_filter($result['nodes'], fn ($n) => $n['id'] === 'g:3:phone'));
        $this->assertCount(1, $phones);
        $this->assertSame(['30 phones', '30 phones on acc1', 'phone', false], [$phones[0]['label'], $phones[0]['name'], $phones[0]['role'], $phones[0]['managed']]);
        $this->assertSame(['device_id' => 3, 'count' => 30, 'names' => array_slice($names, 0, L2Builder::GROUP_NAMES)], $phones[0]['group']);
        $this->assertSame('1 access point', array_values(array_filter($result['nodes'], fn ($n) => $n['id'] === 'g:3:wireless'))[0]['label']);

        [$edge] = $this->edgeBetween($result, 'd:3', 'g:3:phone');
        $this->assertSame(['30 ports', null, [], ['count' => 30]], [$edge['a']['name'], $edge['b']['port_id'], $edge['members'], $edge['group']]);
        $this->assertSame(31, $result['stats']['grouped']);
        $this->assertSame(2, $result['stats']['unmanaged']);

        $this->assertSame(0, $this->build(['unmanaged' => false], null, $links, $groups)['stats']['grouped'], 'no neighbors, no groups');
    }

    public function testAggregatesThatAreWhollyTheLinkAreFlagged(): void
    {
        [$po] = $this->edgeBetween($this->build(), 'd:1', 'd:2');
        $this->assertTrue($po['lag']['a_whole']);
        $this->assertTrue($po['lag']['b_whole']);

        // core1's Po1 also takes the uplink to acc1 (an MLAG member): it is not only the link to core2
        $lagParent = CampusFixture::lagParent() + [13 => 10];
        $builder = new L2Builder(CampusFixture::devices(), CampusFixture::links(), CampusFixture::ports(), CampusFixture::portVlans(), $lagParent,
            new StpIndex(CampusFixture::stpInstances(), CampusFixture::stpPorts(), $lagParent));
        [$po] = $this->edgeBetween($builder->build(), 'd:1', 'd:2');
        $this->assertFalse($po['lag']['a_whole']);
        $this->assertTrue($po['lag']['b_whole']);
    }

    /**
     * acc10 (device 10) bundles Te1/1/1-4 (101-104) in Po1 (100). Its links rows only show Te1/1/1-2 to core-a (device
     * 30): Te1/1/3-4 go to core-b, which speaks CDP while acc10 speaks LLDP. Whether Po1 is only the link to core-a
     * follows the port stack and the status of the members, not the links rows.
     */
    public function testAWholeAggregateCountsTheMembersThatAreUpInThePortStack(): void
    {
        $whole = function (array $oper, array $lagParent = [101 => 100, 102 => 100, 103 => 100, 104 => 100], array $missing = []): bool {
            $devices = [];
            foreach ([10 => 'acc10', 30 => 'core-a'] as $id => $name) {
                $devices[$id] = ['device_id' => $id, 'hostname' => $name, 'display' => $name, 'status' => 1, 'disabled' => 0, 'role' => 'switch'];
            }
            $ports = [];
            foreach ([100 => 'Port-channel1', 101 => 'Te1/1/1', 102 => 'Te1/1/2', 103 => 'Te1/1/3', 104 => 'Te1/1/4', 301 => 'Te1/0/1', 302 => 'Te1/0/2'] as $id => $name) {
                if (! in_array($id, $missing, true)) {
                    $ports[$id] = ['port_id' => $id, 'device_id' => $id < 300 ? 10 : 30, 'ifIndex' => $id, 'ifName' => $name, 'ifDescr' => $name,
                        'ifSpeed' => 10000000000, 'ifOperStatus' => $oper[$id] ?? 'up', 'ifAdminStatus' => 'up', 'ifType' => 'ethernetCsmacd'];
                }
            }
            $links = [];
            foreach ([[101, 301], [102, 302]] as [$local, $remote]) {
                $links[] = ['local_device_id' => 10, 'local_port_id' => $local, 'remote_device_id' => 30, 'remote_port_id' => $remote,
                    'remote_hostname' => 'core-a', 'remote_port' => $ports[$remote]['ifName'] ?? '', 'remote_platform' => '', 'remote_version' => '', 'protocol' => 'lldp'];
            }
            $map = (new L2Builder($devices, $links, $ports, [], $lagParent, new StpIndex([], [], $lagParent)))->build(['collapseLag' => true]);
            [$edge] = $this->edgeBetween($map, 'd:10', 'd:30');
            $this->assertSame('d:10', $edge['from']);

            return $edge['lag']['a_whole'];
        };

        // the cables to core-a are down, those to core-b up: Po1 is up and carries core-b's traffic
        $this->assertFalse($whole([101 => 'down', 102 => 'down', 301 => 'down', 302 => 'down']));
        $this->assertFalse($whole([]), 'Te1/1/3-4 are up: Po1 also goes elsewhere');
        // members that are down do not count, with or without a links row
        $this->assertTrue($whole([103 => 'down', 104 => 'lowerLayerDown']));
        $this->assertTrue($whole([102 => 'down', 103 => 'notPresent', 104 => 'down'], [101 => 100, 102 => 100, 103 => 100, 104 => 100]));
        // a member without its port row may carry traffic
        $this->assertFalse($whole([103 => 'down'], [101 => 100, 102 => 100, 103 => 100, 104 => 100], [104]));
        // only the members of this port-channel count
        $this->assertTrue($whole([], [101 => 100, 102 => 100, 103 => 105, 104 => 105]));
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

    public function testUnmanagedNeighboursAreMergedByNameExceptDefaultNames(): void
    {
        $neighbour = fn (int $device, int $port, string $host) => ['local_device_id' => $device, 'local_port_id' => $port, 'remote_device_id' => 0,
            'remote_port_id' => null, 'remote_hostname' => $host, 'remote_port' => 'Port 1', 'remote_platform' => '', 'protocol' => 'lldp'];
        $links = [
            $neighbour(3, 33, 'Switch'), $neighbour(4, 41, 'Switch'),                 // factory default name: two devices
            $neighbour(3, 34, 'switch.local'), $neighbour(4, 42, 'switch.local'),     // default name with a domain
            $neighbour(1, 13, 'nas01.corp.example'), $neighbour(2, 23, 'nas01.corp.example'), // one dual-homed device
            $neighbour(1, 14, 'printer1.siteA.example'), $neighbour(2, 24, 'printer1.siteB.example'),
        ];
        $builder = new L2Builder(CampusFixture::devices(), $links, CampusFixture::ports(), [], [], new StpIndex());
        $unmanaged = array_column(array_filter($builder->build()['nodes'], fn ($n) => ! $n['managed']), 'name');
        sort($unmanaged);
        $this->assertSame(['Switch', 'Switch', 'nas01.corp.example', 'printer1.siteA.example', 'printer1.siteB.example', 'switch.local', 'switch.local'], $unmanaged);
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
