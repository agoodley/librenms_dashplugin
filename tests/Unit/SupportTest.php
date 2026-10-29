<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Links\LinkAnalyzer;
use Agoodley\LibrenmsTopology\Support\Cidr;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\ErrorText;
use Agoodley\LibrenmsTopology\Support\Graph;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Support\Text;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function testDevicesNamedInASetting(): void
    {
        $settings = new PluginSettings(['stp_preferred_roots' => 'CORE1.example.net, 7 sw-old']);
        $devices = [
            1 => ['hostname' => 'core1.example.net', 'sysName' => 'core1', 'display' => 'core1'],
            2 => ['hostname' => '10.0.0.2', 'sysName' => 'SW-OLD', 'display' => ''],
            7 => ['hostname' => 'dist7', 'sysName' => '', 'display' => null],
            8 => ['hostname' => 'acc8'],
        ];

        $this->assertSame([1 => true, 2 => true, 7 => true], $settings->devicesIn('stp_preferred_roots', $devices));
        $this->assertSame([], $settings->devicesIn('redundancy_core', $devices));
    }

    public function testAnErrorInOneLineNeverCarriesSql(): void
    {
        $this->assertSame('RuntimeException: the check timed out', ErrorText::line(new \RuntimeException("the check\ntimed out\x1b[0m")));
        $pdo = new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'librenms.ospf_nbrs' doesn't exist (Connection: mysql, SQL: select * from ospf_nbrs where device_id = 7)");
        $pdo->errorInfo = ['42S02', 1146, "Table 'librenms.ospf_nbrs' doesn't exist"];
        $this->assertSame("database error 42S02/1146: Table 'librenms.ospf_nbrs' doesn't exist", ErrorText::line($pdo), 'a database error: its code and the driver\'s words');
        $wrapped = new \RuntimeException('cannot read the OSPF neighbours: ' . $pdo->getMessage(), 0, $pdo);
        $this->assertSame("RuntimeException: cannot read the OSPF neighbours: database error 42S02/1146: Table 'librenms.ospf_nbrs' doesn't exist", ErrorText::line($wrapped),
            'wrapped: the message up to the SQL, then the database error\'s words');
        $this->assertSame('database error 08S01', ErrorText::line(new \PDOException('SQLSTATE[08S01]: Communication link failure (SQL: select secret)', 0)), 'no SQL from the message');
        $long = ErrorText::line(new \LogicException(str_repeat('x', 500)), 60);
        $this->assertSame(60, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
        $this->assertSame('ErrorException: bad byte ? here', str_replace("\u{FFFD}", '?', ErrorText::line(new \ErrorException("bad byte \xff here"))));
    }

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

    public function testSubInterfacesAndVlanInterfaces(): void
    {
        foreach (['Gi0/0.100', 'ae0.0', 'Po1.100', 'GigabitEthernet0/0/1.20 '] as $name) {
            $this->assertTrue(InterfaceName::isSubInterface($name), $name);
        }
        foreach (['Gi0/0', 'Vlan100', 'xe-0/0/0', '10.10.10.1/24 ', ''] as $name) {
            $this->assertFalse(InterfaceName::isSubInterface($name), $name);
        }
        $this->assertSame('Gi0/0', InterfaceName::parentName('Gi0/0.100'));
        $this->assertSame('ae0', InterfaceName::parentName('ae0.0'));
        $this->assertNull(InterfaceName::parentName('Gi0/0'));

        // VLAN interfaces: the "VLAN in use" of the link checks and the host locator's router interfaces
        foreach (['Vl10' => 10, 'Vlan10' => 10, 'vlan 20' => 20, 'Vlanif30' => 30, 'Vlan-interface40' => 40, 'irb.50' => 50,
            'vlan.60' => 60, 've 70' => 70, 'Ve80' => 80] as $name => $vlan) {
            $this->assertSame($vlan, InterfaceName::vlanInterface($name), $name);
        }
        foreach (['Vlan0', 'Vlan4095', 'Gi0/0.10', 'veth0', 'BVI1', 'irb', 'Vl10a'] as $name) {
            $this->assertNull(InterfaceName::vlanInterface($name), $name);
        }
        $this->assertSame(10, InterfaceName::subInterfaceUnit('Gi0/0.10'));
        $this->assertSame(40, InterfaceName::subInterfaceUnit('ae0.40'));
        $this->assertNull(InterfaceName::subInterfaceUnit('xe-0/0/0.0'));
        $this->assertNull(InterfaceName::subInterfaceUnit('10.10.10.1'));
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
        $this->assertTrue(Cidr::contains('10.1.1.1', '10.1.1.1'));
        foreach (['10.255.0.0/', '10.255.0.0/x', '10.255.0.0/33', '10.255.0.0/-1'] as $malformed) {
            $this->assertFalse(Cidr::contains($malformed, '192.168.1.0/24'), "$malformed must not match everything");
        }
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

    public function testNodesOnCycles(): void
    {
        // triangle 1-2-3 with a tail 3-4-5: only the triangle is on a cycle
        $graph = (new Graph)->addEdge(1, 2)->addEdge(2, 3)->addEdge(3, 1)->addEdge(3, 4)->addEdge(4, 5);
        $nodes = $graph->nodesOnCycles();
        sort($nodes);
        $this->assertSame([1, 2, 3], $nodes);

        // two independent links between the same switches are a loop, a tree is not
        $this->assertCount(2, (new Graph)->addEdge('a', 'b', 'x')->addEdge('a', 'b', 'y')->nodesOnCycles());
        $this->assertSame([], (new Graph)->addEdge(1, 2)->addEdge(2, 3)->addEdge(2, 4)->nodesOnCycles());

        // two rings joined by a bridge edge 3-10
        $rings = (new Graph)->addEdge(1, 2)->addEdge(2, 3)->addEdge(3, 1)->addEdge(3, 10)
            ->addEdge(10, 11)->addEdge(11, 12)->addEdge(12, 10)->addNode(99);
        $nodes = $rings->nodesOnCycles();
        sort($nodes);
        $this->assertSame([1, 2, 3, 10, 11, 12], $nodes);
    }

    public function testDeviceRoles(): void
    {
        $this->assertSame(DeviceRole::FIREWALL, DeviceRole::classify(['type' => 'firewall']));
        $this->assertSame(DeviceRole::L3SWITCH, DeviceRole::classify(['type' => 'network'], ['bridge' => true, 'l3_interfaces' => 5]));
        $this->assertSame(DeviceRole::SWITCH, DeviceRole::classify(['type' => 'network'], ['vlans' => 20, 'l3_interfaces' => 1]));
        $this->assertSame(DeviceRole::ROUTER, DeviceRole::classify(['type' => 'network', 'os' => 'iosxr']));
        $this->assertSame(DeviceRole::ROUTER, DeviceRole::classify(['type' => 'network'], ['l3_interfaces' => 4]));
        $this->assertSame(DeviceRole::PHONE, DeviceRole::fromNeighbor('Cisco IP Phone 8845', null));
        $this->assertSame(DeviceRole::WIRELESS, DeviceRole::fromNeighbor('cisco AIR-AP2802I-E-K9', null));
        $this->assertSame(DeviceRole::SWITCH, DeviceRole::fromNeighbor('cisco WS-C2960X-48FPD-L', null));
        $this->assertSame(DeviceRole::SERVER, DeviceRole::fromNeighbor('VMware ESX', null));
        $this->assertSame(DeviceRole::UNKNOWN, DeviceRole::fromNeighbor('', null));
    }

    public function testEndDevicesAndHosts(): void
    {
        foreach ([DeviceRole::PHONE, DeviceRole::SERVER, DeviceRole::WIRELESS, DeviceRole::OTHER] as $role) {
            $this->assertTrue(DeviceRole::isEndDevice($role), $role);
        }
        foreach ([DeviceRole::SWITCH, DeviceRole::L3SWITCH, DeviceRole::ROUTER, DeviceRole::FIREWALL, DeviceRole::UNKNOWN, null] as $role) {
            $this->assertFalse(DeviceRole::isEndDevice($role), (string) $role);
        }
        $this->assertSame([], array_intersect(DeviceRole::NETWORK, DeviceRole::END_DEVICES));

        // hosts by the LibreNMS device type that gave them their role: not appliances, load balancers or storage
        $host = fn (string $type) => DeviceRole::isHost(['type' => $type, 'role' => DeviceRole::classify(['type' => $type])]);
        foreach (['server', 'workstation', 'collaboration'] as $type) {
            $this->assertTrue($host($type), $type);
        }
        foreach (['appliance', 'loadbalancer', 'storage', 'wireless', 'firewall', 'network', 'power', 'printer'] as $type) {
            $this->assertFalse($host($type), $type);
        }
        $this->assertTrue(DeviceRole::isHost(['role' => DeviceRole::SERVER]));
    }

    public function testRolesOfLldpNeighborsComeFromTheirSystemDescription(): void
    {
        // LibreNMS stores no platform for LLDP neighbors, only the sysDescr (remote_version)
        $this->assertSame(DeviceRole::SWITCH, DeviceRole::fromNeighbor(null, 'Juniper Networks, Inc. ex2300-48p Ethernet Switch, kernel JUNOS 21.4R3-S4', 'bldg2-idf3'));
        $this->assertSame(DeviceRole::ROUTER, DeviceRole::fromNeighbor('', 'MikroTik RouterOS 7.12 (stable) CCR2004-1G-12S+2XS', 'edge-gw'));
        $this->assertSame(DeviceRole::SERVER, DeviceRole::fromNeighbor('', 'VMware ESXi 8.0.2 build-22380479 VMware, Inc. VMware7,1', 'esx01'));
        $this->assertSame(DeviceRole::SERVER, DeviceRole::fromNeighbor('', 'Linux srv1 5.15.0-91-generic #101-Ubuntu SMP x86_64', 'srv1'));
        $this->assertSame(DeviceRole::PHONE, DeviceRole::fromNeighbor('', 'Cisco IP Phone CP-8845, V3, sip88xx.14-1-1-0001-136', 'SEP0011223344AB'));
        $this->assertSame(DeviceRole::FIREWALL, DeviceRole::fromNeighbor('', 'pfSense fw1.example.net 2.7.2-RELEASE FreeBSD 14.0-CURRENT amd64', 'fw1'));
        // network operating systems on Linux are not servers
        $this->assertSame(DeviceRole::SWITCH, DeviceRole::fromNeighbor('', 'SONiC Software Version: SONiC.202305 - HwSku: Accton-AS7726-32X - Distribution: Debian 11.7 - Kernel: 5.10.0-18-2-amd64', 'leaf1'));
        $this->assertSame(DeviceRole::SWITCH, DeviceRole::fromNeighbor('', 'Cumulus Linux version 5.4.0 running on Mellanox SN2010', 'leaf2'));
        // a CDP platform wins over the description; nothing known stays unknown
        $this->assertSame(DeviceRole::SWITCH, DeviceRole::fromNeighbor('cisco WS-C2960X-48FPD-L', 'Linux', 'sw1'));
        $this->assertSame(DeviceRole::UNKNOWN, DeviceRole::fromNeighbor(null, 'Acme NetOS 1.2', 'box1'));
        $this->assertSame(DeviceRole::UNKNOWN, DeviceRole::fromNeighbor(null, '', ''));

        // the system descriptions of common switches and routers
        $descriptions = [
            'Cisco IOS Software, C2960X Software (C2960X-UNIVERSALK9-M), Version 15.2(7)E3, RELEASE SOFTWARE (fc1)' => DeviceRole::SWITCH,
            'Cisco IOS Software [Cupertino], Catalyst L3 Switch Software (CAT9K_IOSXE), Version 17.9.4' => DeviceRole::SWITCH,
            'Cisco NX-OS(tm) n9000, Software (n9000-dk9), Version 9.3(10), RELEASE SOFTWARE' => DeviceRole::SWITCH,
            'Arista Networks EOS version 4.28.3M running on an Arista Networks DCS-7050SX3-48YC8' => DeviceRole::SWITCH,
            'Aruba JL658A 6300M 24SFP+ 4SFP56 Swch FL.10.10.1020' => DeviceRole::SWITCH,
            'ExtremeXOS (X460G2-48p-10G4) version 30.7.1.1 30.7.1.1-patch1-5' => DeviceRole::SWITCH,
            'Ruckus ICX7150-48P, IronWare Version 08.0.95 Compiled on Mar 23 2022' => DeviceRole::SWITCH,
            'Dell EMC Networking OS10 Enterprise. Copyright (c) 1999-2023 by Dell Inc.' => DeviceRole::SWITCH,
            'Cisco IOS Software, C2900 Software (C2900-UNIVERSALK9-M), Version 15.7(3)M8' => DeviceRole::ROUTER,
            'Cisco IOS XR Software, Version 7.5.2' => DeviceRole::ROUTER,
            'VyOS 1.4-rolling-202307250317' => DeviceRole::ROUTER,
            'ArubaOS (MODEL: 515), Version 8.10.0.6' => DeviceRole::WIRELESS,
            'Cisco IOS Software [Cupertino], C9800 Software (C9800_IOSXE-K9), Version 17.9.4a' => DeviceRole::WIRELESS,
            'U6-LR 6.5.62.14789' => DeviceRole::WIRELESS,
            'Cisco Firepower Threat Defense, Version 7.2.5' => DeviceRole::FIREWALL,
        ];
        foreach ($descriptions as $description => $role) {
            $this->assertSame($role, DeviceRole::fromNeighbor(null, $description, 'neighbor'), $description);
        }
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

    public function testAScopeThatIsNoIdIsRefusedForWrites(): void
    {
        // parse() reads these as "no filter": a write would cover every device
        foreach (['abc', '0', '-1', '1x', [5], [], true, false] as $value) {
            $this->assertSame('device', MapParams::invalidScope(['device' => $value]), var_export($value, true));
        }
        $this->assertSame('group', MapParams::invalidScope(['device' => '5', 'group' => '0']));
        $this->assertSame('location', MapParams::invalidScope(['location' => 'none']));

        // not given, or valid ids
        foreach ([[], ['device' => ''], ['device' => '  '], ['device' => null], ['device' => '12', 'group' => '3', 'location' => 7]] as $input) {
            $this->assertNull(MapParams::invalidScope($input), json_encode($input));
        }
        $settings = new PluginSettings();
        $this->assertSame([12, 3, 7], array_values(array_intersect_key(MapParams::parse(['device' => '12', 'group' => '3', 'location' => 7], $settings),
            array_flip(['device', 'group', 'location']))));
    }

    public function testCheckboxGroupsAreStoredAsLists(): void
    {
        // LibreNMS stores settings[history_checks][] as a list; the hidden empty entry keeps "none checked" apart from unset
        $this->assertSame('stp routing', (new PluginSettings(['history_checks' => ['', 'stp', 'routing']]))->all()['history_checks']);
        $this->assertSame('', (new PluginSettings(['history_checks' => ['']]))->all()['history_checks']);
        $this->assertSame('stp links redundancy routing interfaces', (new PluginSettings())->all()['history_checks']);
    }

    public function testOneSharedPortThresholdForCoverageAndHostLocator(): void
    {
        $this->assertSame(4, (new PluginSettings())->sharedPortMacs());
        $this->assertSame(0, (new PluginSettings(['shared_port_macs' => '0']))->sharedPortMacs(), '0 switches it off');
        $this->assertSame(2, (new PluginSettings(['shared_port_macs' => '1']))->sharedPortMacs(), 'one MAC is every port');
        $this->assertSame(12, (new PluginSettings(['shared_port_macs' => '12']))->sharedPortMacs());
        $this->assertArrayNotHasKey('hosts_shared_port_macs', PluginSettings::DEFAULTS);
        $this->assertArrayNotHasKey('coverage_mac_threshold', PluginSettings::DEFAULTS);
    }

    public function testCountsAgreeWithTheirNoun(): void
    {
        $this->assertSame('1 warning', Text::count(1, 'warning'));
        $this->assertSame('0 warnings', Text::count(0, 'warning'));
        $this->assertSame('1,242 switches', Text::count(1242, 'switch', 'switches'));
        $this->assertSame('2 L2 domains', Text::count('2', 'L2 domain'));
    }

    public function testListsInWords(): void
    {
        $this->assertSame('', Text::join([]));
        $this->assertSame('ports', Text::join(['ports']));
        $this->assertSame('ports and routes', Text::join([3 => 'ports', 7 => 'routes']));
        $this->assertSame('LLDP/CDP links, ports, OSPF and BGP sessions', Text::join(['LLDP/CDP links', 'ports', 'OSPF', 'BGP sessions']));
    }

    public function testIntegerSetsWithRanges(): void
    {
        $settings = new PluginSettings(['links_ignore_vlans' => '999 1002-1005, 4095 x 0']);
        $this->assertSame([999, 1002, 1003, 1004, 1005], array_keys($settings->intSet('links_ignore_vlans', 1, 4094)));
        $this->assertSame([], (new PluginSettings())->intSet('links_ignore_vlans', 1, 4094));
    }

    public function testLinkFormatting(): void
    {
        $this->assertSame('1,10-12,20', LinkAnalyzer::ranges([20, 10, 11, 1, 12, 11]));
        $this->assertSame('', LinkAnalyzer::ranges([]));
        $this->assertSame('100M', LinkAnalyzer::speed(100000000));
        $this->assertSame('2.5G', LinkAnalyzer::speed(2500000000));
        $this->assertSame('10G', LinkAnalyzer::speed(10000000000));
    }
}
