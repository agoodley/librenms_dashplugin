<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Coverage\CoverageAnalyzer;
use Agoodley\LibrenmsTopology\Links\NeighborReports;
use PHPUnit\Framework\TestCase;

class CoverageAnalyzerTest extends TestCase
{
    private static function find(array $result, string $code, ?string $titlePart = null): ?array
    {
        foreach ($result['findings'] as $finding) {
            if ($finding['code'] === $code && ($titlePart === null || str_contains($finding['title'], $titlePart))) {
                return $finding;
            }
        }

        return null;
    }

    private static function codes(array $result): array
    {
        $codes = array_column($result['findings'], 'code');
        sort($codes);

        return $codes;
    }

    private static function sortedTitles(array $result, string $code): array
    {
        $titles = array_column(array_filter($result['findings'], fn ($f) => $f['code'] === $code), 'title');
        sort($titles);

        return $titles;
    }

    /**
     * Two access switches with an uplink to each other, both reporting it.
     */
    private static function pair(): CoverageFixture
    {
        return (new CoverageFixture)->device(1, 'acc1')->device(2, 'acc2')->link(1, 'Gi1/0/48', 2, 'Gi1/0/48');
    }

    public function testFullyCoveredNetworkHasNoFindings(): void
    {
        $result = self::pair()->analyzer()->analyze();

        $this->assertSame([], $result['findings']);
        $this->assertSame([], $result['neighbors']);
        $this->assertSame([], $result['devices']);
        $this->assertSame('ok', $result['summary']['status']);
    }

    public function testUnmonitoredSwitchIsOneNeighborWithAllItsSightings(): void
    {
        $sysDescr = 'Cisco IOS Software, C2960X Software (C2960X-UNIVERSALK9-M), Version 15.2(7)E3, RELEASE SOFTWARE (fc2)';
        $net = self::pair()
            ->neighbor(1, 'Gi1/0/1', 'lab-sw', $sysDescr, 'Gi0/1')
            ->neighbor(1, 'Gi1/0/1', 'lab-sw', 'cisco WS-C2960X-48FPD-L', 'GigabitEthernet0/1', 'cdp')
            ->neighbor(2, 'Gi1/0/5', 'LAB-SW', '', 'Gi0/2');
        $result = $net->analyzer()->analyze();

        $this->assertCount(1, $result['neighbors']);
        $neighbor = $result['neighbors'][0];
        $this->assertSame('u:lab-sw', $neighbor['id']);
        $this->assertSame('switch', $neighbor['role']);
        $this->assertSame(['cisco WS-C2960X-48FPD-L', $sysDescr], [$neighbor['platform'], $neighbor['description']]);
        $this->assertSame('not_monitored', $neighbor['status']);
        $this->assertSame(['lldp', 'cdp'], $neighbor['protocols']);
        $this->assertSame([[1, 'Gi1/0/1', 'Gi0/1'], [2, 'Gi1/0/5', 'Gi0/2']],
            array_map(fn ($s) => [$s['device_id'], $s['port'], $s['remote_port']], $neighbor['sightings']));

        $finding = self::find($result, 'not_monitored');
        $this->assertSame('warning', $finding['severity']);
        $this->assertSame('switch not monitored: lab-sw', $finding['title']);
        $this->assertStringContainsString('lab-sw (cisco WS-C2960X-48FPD-L) is an LLDP/CDP neighbor of acc1 Gi1/0/1 (its port Gi0/1) and acc2 Gi1/0/5 (its port Gi0/2)', $finding['detail']);
        $this->assertSame([1, 2], $finding['devices']);
        $this->assertSame([$net->port(1, 'Gi1/0/1'), $net->port(2, 'Gi1/0/5')], $finding['ports']);
        $this->assertSame('unmonitored', $finding['category']);
    }

    public function testLldpNeighborsAreRecognizedFromTheirSystemDescription(): void
    {
        // LLDP rows as LibreNMS stores them: no platform, the system description in remote_version
        $result = self::pair()
            ->neighbor(1, 'Gi1/0/1', 'bldg2-idf3', 'Juniper Networks, Inc. ex2300-48p Ethernet Switch, kernel JUNOS 20.4R3-S1.3', 'ge-0/0/47')
            ->neighbor(1, 'Gi1/0/2', 'edge-gw', 'MikroTik RouterOS 7.12 (stable) CCR2004-16G-2S+', 'ether2')
            ->neighbor(1, 'Gi1/0/3', 'b2-ap-114', 'ArubaOS (MODEL: 515), Version 8.10.0.6', 'eth0')
            ->neighbor(1, 'Gi1/0/4', 'leaf-9', 'SONiC Software Version: SONiC.202305 - HwSku: Accton-AS7326-56X - Distribution: Debian 11.7', 'Ethernet0')
            ->neighbor(1, 'Gi1/0/5', 'dc-leaf1', 'Arista Networks EOS version 4.28.3M running on an Arista Networks DCS-7050SX3-48YC12', 'Ethernet49/1')
            ->neighbor(1, 'Gi1/0/6', 'dc-spine1', 'Cisco Nexus Operating System (NX-OS) Software 9.3(10)', 'Ethernet1/1')
            ->neighbor(1, 'Gi1/0/7', 'b3-cx1', 'Aruba JL658A 6300M 24SFP+ 4SFP56 Swch FL.10.10.1020', '1/1/1')
            ->neighbor(1, 'Gi1/0/8', 'lab-x440', 'ExtremeXOS (X440G2-48p-10G4) version 30.2.1.8 30.2.1.8 by release-manager', '1:1')
            ->neighbor(1, 'Gi1/0/9', 'pe1', 'Cisco IOS XR Software (ASR9K), Version 7.5.2', 'Te0/0/0/1')
            ->neighbor(1, 'Gi1/0/10', 'desk-2960', 'Cisco IOS Software, C2960X Software (C2960X-UNIVERSALK9-M), Version 15.2(7)E8', 'Gi0/1')
            ->analyzer()->analyze();

        $this->assertSame(['edge-gw', 'pe1', 'b3-cx1', 'bldg2-idf3', 'dc-leaf1', 'dc-spine1', 'desk-2960', 'lab-x440', 'leaf-9', 'b2-ap-114'],
            array_column($result['neighbors'], 'name'));
        $this->assertSame(['router', 'router', 'switch', 'switch', 'switch', 'switch', 'switch', 'switch', 'switch', 'wireless'],
            array_column($result['neighbors'], 'role'));
        $this->assertSame(array_fill(0, 10, ''), array_column($result['neighbors'], 'platform'));
        $this->assertCount(9, array_filter($result['findings'], fn ($f) => $f['severity'] === 'warning'), 'every switch and router is a warning');
        $this->assertSame('info', self::find($result, 'not_monitored', 'b2-ap-114')['severity']);
        $this->assertStringStartsWith('bldg2-idf3 (Juniper Networks, Inc. ex2300-48p Ethernet Switch, kernel JUNOS 20.4R…) is an LLDP/CDP neighbor of acc1 Gi1/0/1',
            self::find($result, 'not_monitored', 'bldg2-idf3')['detail']);
    }

    public function testNeighborRolesDecideTheFindingsAndTheOrder(): void
    {
        $result = self::pair()
            ->neighbor(1, 'Gi1/0/1', 'phone-1', 'Cisco IP Phone 8865', 'Port 1', 'cdp')
            ->neighbor(1, 'Gi1/0/2', 'esx-9', 'VMware ESX', 'vmnic0', 'cdp')
            ->neighbor(1, 'Gi1/0/3', 'mystery', '')
            ->neighbor(1, 'Gi1/0/4', 'AP-2F-01', 'cisco AIR-AP2802I-E-K9', 'Gi0', 'cdp')
            ->neighbor(1, 'Gi1/0/5', 'desk-switch', '')
            ->neighbor(1, 'Gi1/0/6', 'fw1', 'FortiGate-100F v7.2.5,build1517,230606 (GA.F)')
            ->neighbor(1, 'Gi1/0/7', 'branch-rtr', 'cisco ISR4331/K9', 'Gi0/0/0', 'cdp')
            ->analyzer()->analyze();

        // network devices and devices of unknown type get a row, phones and servers are only counted
        $this->assertSame(['branch-rtr', 'fw1', 'desk-switch', 'AP-2F-01', 'mystery'], array_column($result['neighbors'], 'name'));
        $this->assertSame(['router', 'firewall', 'switch', 'wireless', 'unknown'], array_column($result['neighbors'], 'role'));
        $this->assertSame([true, true, true, true, false], array_column($result['neighbors'], 'network'));
        $this->assertSame(['server' => 1, 'phone' => 1], $result['summary']['other_neighbors']);
        $this->assertSame([7, 7], [$result['summary']['neighbors'], $result['summary']['statuses']['not_monitored']]);

        // switches, routers and firewalls are warnings, access points info; the rest is only listed
        $this->assertSame(['access point not monitored: AP-2F-01', 'firewall not monitored: fw1', 'router not monitored: branch-rtr', 'switch not monitored: desk-switch'],
            self::sortedTitles($result, 'not_monitored'));
        $this->assertSame('info', self::find($result, 'not_monitored', 'AP-2F-01')['severity']);
        $this->assertSame('warning', self::find($result, 'not_monitored', 'fw1')['severity']);
        $this->assertSame(4, $result['summary']['not_monitored']);
        $this->assertSame(['router' => 1, 'firewall' => 1, 'switch' => 1, 'wireless' => 1, 'unknown' => 1, 'server' => 1, 'phone' => 1], $result['summary']['roles']);
    }

    public function testRecommendationFollowsTheAutodiscoverySetting(): void
    {
        $net = self::pair()->neighbor(1, 'Gi1/0/1', 'lab-sw', 'cisco WS-C2960X-48FPD-L', 'Gi0/1', 'cdp');

        $on = self::find($net->analyzer()->analyze(), 'not_monitored')['recommendation'];
        $this->assertStringContainsString('Add it in LibreNMS', $on);
        $this->assertStringContainsString('autodiscovery is on but did not add it; when adding fails, LibreNMS says why in the eventlog of acc1 ("... discovery of lab-sw failed")', $on);
        $this->assertStringContainsString('discovery_by_ip', $on);
        $this->assertStringContainsString('autodiscovery.xdp_exclude and cdp_exclude patterns', $on);

        $net->autodiscovery = 'no_nets';
        $this->assertStringContainsString('autodiscovery is on, but the discovery networks (nets) are empty, so LibreNMS adds no neighbor',
            self::find($net->analyzer()->analyze(), 'not_monitored')['recommendation']);

        $net->autodiscovery = 'off';
        $this->assertStringContainsString('turn on LLDP/CDP autodiscovery (autodiscovery.xdp) and list the networks of your devices in nets',
            self::find($net->analyzer()->analyze(), 'not_monitored')['recommendation']);
    }

    public function testNeighborNamedLikeAMonitoredDeviceIsNotLinked(): void
    {
        $net = self::pair()
            ->device(3, 'core1')
            ->device(4, 'sw1', 'switch', ['hostname' => 'sw1.b.example.net', 'sysName' => 'sw1.b.example.net', 'display' => 'sw1.b'])
            ->device(5, 'dist1', 'switch', ['hostname' => '10.1.1.5', 'sysName' => 'dist1-old']);
        $net->addresses[3] = ['10.0.0.1'];
        $net->neighbor(1, 'Gi1/0/1', 'CORE1', 'cisco C9500-24Y4C', 'Te1/0/1', 'cdp')       // the sysName of core1
            ->neighbor(1, 'Gi1/0/2', 'core1.other.net', 'cisco C9500-24Y4C', 'Te1/1/2', 'cdp') // with a domain: the plain sysName core1
            ->neighbor(2, 'Gi1/0/3', '10.0.0.1', '')                                         // an address of core1
            ->neighbor(2, 'Gi1/0/4', 'sw1.a.example.net', 'cisco WS-C3850-48P', 'Gi1/0/1', 'cdp') // another domain: not sw1.b
            ->neighbor(2, 'Gi1/0/6', 'dist1', 'cisco WS-C3850-48P', 'Gi1/0/2', 'cdp');        // only the display name of dist1
        $result = $net->analyzer()->analyze();

        $byName = array_column($result['neighbors'], null, 'name');
        $this->assertSame(['not_linked', 3], [$byName['CORE1']['status'], $byName['CORE1']['device_id']]);
        $this->assertSame(['not_linked', 3], [$byName['core1.other.net']['status'], $byName['core1.other.net']['device_id']]);
        $this->assertSame(['not_linked', 3], [$byName['10.0.0.1']['status'], $byName['10.0.0.1']['device_id']]);
        $this->assertSame(['not_linked', 5], [$byName['dist1']['status'], $byName['dist1']['device_id']]);
        $this->assertSame('10.0.0.1', $byName['10.0.0.1']['address']);
        $this->assertSame('not_monitored', $byName['sw1.a.example.net']['status']);

        $finding = self::find($result, 'not_linked', 'CORE1');
        $this->assertSame('info', $finding['severity']);
        $this->assertSame('neighbor not linked to its device: CORE1 (core1)', $finding['title']);
        $this->assertStringContainsString('acc1 Gi1/0/1 (its port Te1/0/1) sees CORE1 (cisco C9500-24Y4C), which is the name of the monitored device core1', $finding['detail']);
        $this->assertStringEndsWith('The names already match, so the link is probably older than core1 in LibreNMS, then run discovery on acc1 again.', $finding['recommendation']);
        $this->assertSame([1, 3], $finding['devices']);

        $this->assertStringEndsWith("The names only differ by the domain: set mydomain to that domain, or make core1's sysName or hostname 'core1.other.net', then run discovery on acc1 again.",
            self::find($result, 'not_linked', 'core1.other.net')['recommendation']);
        $byAddress = self::find($result, 'not_linked', '10.0.0.1');
        $this->assertStringContainsString('sees 10.0.0.1, which is an address of the monitored device core1', $byAddress['detail']);
        $this->assertStringContainsString('core1 advertises the address 10.0.0.1 instead of its name', $byAddress['recommendation']);
        $this->assertStringEndsWith("Make dist1's sysName or hostname 'dist1', then run discovery on acc2 again.", self::find($result, 'not_linked', 'dist1')['recommendation']);

        // a device's page lists the neighbours that are probably that device
        $this->assertSame(['CORE1', 'core1.other.net', '10.0.0.1'], array_column($net->analyzer()->analyze(3)['neighbors'], 'name'));
    }

    public function testADeviceSeeingItsOwnNameIsNotAMatch(): void
    {
        $result = self::pair()->neighbor(1, 'Gi1/0/9', 'acc1', 'Cisco IOS Software [Cupertino], Catalyst L3 Switch Software (CAT9K_IOSXE)')->analyzer()->analyze();

        $this->assertSame('not_monitored', $result['neighbors'][0]['status']);
    }

    public function testSharedAndFactoryDefaultNames(): void
    {
        $net = self::pair()
            ->device(3, 'core1', 'switch', ['hostname' => 'core1.site-a.example.net', 'display' => 'core1.site-a'])
            ->device(4, 'core1', 'switch', ['hostname' => 'core1.site-b.example.net', 'display' => 'core1.site-b'])
            ->device(5, 'dist1', 'switch', ['hostname' => 'dist1', 'display' => 'dist1-a'])
            ->device(6, 'dist1-b', 'switch', ['sysName' => 'dist1'])
            ->device(7, 'MikroTik', 'router', ['hostname' => '10.9.9.1', 'display' => '10.9.9.1']); // left at its factory name
        $net->neighbor(2, 'Gi1/0/49', 'core1', 'cisco C9500-24Y4C', 'Te1/0/9', 'cdp')  // the sysName of two devices
            ->neighbor(1, 'Gi1/0/9', 'dist1', 'cisco WS-C3850-48P', 'Gi1/0/1', 'cdp')  // the hostname of one, the sysName of another
            ->neighbor(1, 'Gi1/0/5', 'MikroTik', 'MikroTik', 'ether1', 'cdp')
            ->neighbor(1, 'Gi1/0/6', 'MikroTik', 'MikroTik', 'ether1', 'cdp')
            ->neighbor(2, 'Gi1/0/7', 'MikroTik.lan', 'MikroTik RouterOS 7.12 (stable)', 'ether1');
        $result = $net->analyzer()->analyze();

        $byName = [];
        foreach ($result['neighbors'] as $neighbor) {
            $byName[$neighbor['name']][] = [$neighbor['status'], $neighbor['candidates']];
        }
        $this->assertSame([['ambiguous', [3, 4]]], $byName['core1']);
        $this->assertSame([['not_linked', [5]]], $byName['dist1'], 'LibreNMS tries the hostname first');
        $this->assertSame([['not_monitored', []], ['not_monitored', []]], $byName['MikroTik'], 'a factory name matches no device');
        $this->assertSame([['not_monitored', []]], $byName['MikroTik.lan']);
        $this->assertSame(['not_monitored' => 3, 'not_linked' => 1, 'ambiguous' => 1, 'disabled' => 0, 'hidden' => 0], $result['summary']['statuses']);

        $finding = self::find($result, 'ambiguous_name');
        $this->assertSame('info', $finding['severity']);
        $this->assertSame('neighbor name of several devices: core1', $finding['title']);
        $this->assertStringContainsString('acc2 Gi1/0/49 (its port Te1/0/9) sees core1 (cisco C9500-24Y4C), but LibreNMS monitors several devices by that name (core1.site-a, core1.site-b)', $finding['detail']);
        $this->assertSame([2, 3, 4], $finding['devices']);
        $this->assertSame(['router not monitored: MikroTik on acc1 Gi1/0/5', 'router not monitored: MikroTik on acc1 Gi1/0/6', 'router not monitored: MikroTik.lan on acc2 Gi1/0/7'],
            self::sortedTitles($result, 'not_monitored'));
    }

    public function testEndDevicesAreCountedPerDevice(): void
    {
        $net = self::pair()
            ->neighbor(1, 'Gi1/0/1', 'SEP000000000001', 'Cisco IP Phone 8841', 'Port 1', 'cdp')
            ->neighbor(1, 'Gi1/0/2', 'SEP000000000002', 'Cisco IP Phone 8841', 'Port 1', 'cdp')
            ->neighbor(2, 'Gi1/0/1', 'SEP000000000003', 'Cisco IP Phone 8841', 'Port 1', 'cdp')
            ->neighbor(2, 'Gi1/0/2', 'esx-9', 'VMware ESX', 'vmnic0', 'cdp')
            ->neighbor(2, 'Gi1/0/3', 'esx-9', 'VMware ESX', 'vmnic1', 'cdp') // one server on two ports
            ->neighbor(2, 'Gi1/0/4', 'acc2', 'Linux acc2 5.15.0')               // a device that sees its own name
            ->neighbor(1, 'Gi1/0/5', 'lab-sw', 'cisco WS-C2960X-48FPD-L', 'Gi0/1', 'cdp');

        $all = $net->analyzer()->analyze();
        $this->assertSame(['lab-sw'], array_column($all['neighbors'], 'name'));
        $this->assertSame(['server' => 2, 'phone' => 3], $all['summary']['other_neighbors']);
        $this->assertSame(['switch' => 1, 'server' => 2, 'phone' => 3], $all['summary']['roles']);
        $this->assertSame(6, $all['summary']['neighbors']);

        $acc2 = $net->analyzer()->analyze(2);
        $this->assertSame([], $acc2['neighbors']);
        $this->assertSame(['server' => 2, 'phone' => 1], $acc2['summary']['other_neighbors']);
    }

    public function testLinksAreReadTwiceAndOnlyTheNamedPortsAreLoaded(): void
    {
        $net = self::pair()->device(3, 'dist1')->link(1, 'Gi1/0/49', 3, 'Gi1/0/1', false)
            ->neighbor(1, 'Gi1/0/1', 'lab-sw', 'cisco WS-C2960X-48FPD-L', 'Gi0/1', 'cdp')
            ->neighbor(2, 'Gi1/0/2', 'SEP000000000001', 'Cisco IP Phone 8841', 'Port 1', 'cdp');
        $busy = $net->busy(2, 'Gi1/0/11', 9);
        $loads = [];
        $loader = function (array $portIds) use ($net, &$loads): array {
            $loads[] = $portIds;

            return array_intersect_key($net->ports, array_flip($portIds));
        };
        $known = [];
        foreach ($net->devices as $id => $device) {
            $known[$id] = ['name' => $device['display'], 'hostname' => strtolower($device['hostname']), 'sysname' => strtolower($device['sysName']),
                'names' => [strtolower($device['hostname']), strtolower($device['sysName'])], 'disabled' => false];
        }
        $explained = null;
        $busyFor = function (array $ports) use ($net, &$explained): array {
            $explained = $ports;

            return $net->busy;
        };
        $analyzer = new CoverageAnalyzer($net->devices, array_keys($net->devices), new \ArrayIterator($net->links), $loader, [], $known,
            $busyFor, fn (array $portIds) => [], [], 'on', new \Agoodley\LibrenmsTopology\Support\PluginSettings([]));
        $result = $analyzer->analyze();

        $this->assertSame(['hub_suspected', 'not_monitored', 'silent_device'], self::codes($result));
        // the MAC addresses are counted after the first pass, without the ports that face a neighbour other than a phone
        $this->assertArrayHasKey($net->port(1, 'Gi1/0/1'), $explained);
        $this->assertArrayHasKey($net->port(3, 'Gi1/0/1'), $explained);
        $this->assertArrayNotHasKey($net->port(2, 'Gi1/0/2'), $explained);
        $this->assertCount(1, $loads, 'one load');
        $loaded = $loads[0];
        sort($loaded);
        // the busy port, where lab-sw is seen, and both ends of the link only acc1 reports; not the phone's port
        $expected = [$busy, $net->port(1, 'Gi1/0/1'), $net->port(1, 'Gi1/0/49'), $net->port(3, 'Gi1/0/1')];
        sort($expected);
        $this->assertSame($expected, $loaded);
        $this->assertStringContainsString('acc1 Gi1/0/49 on its port Gi1/0/1', self::find($result, 'silent_device')['detail']);
    }

    public function testNeighborsMonitoredOutOfSight(): void
    {
        $net = (new CoverageFixture)->device(1, 'acc1');
        // LibreNMS linked this one to a device the user may not see: the repository removed its ids and set 'hidden'
        $net->neighbor(1, 'Gi1/0/49', 'core1', 'Cisco IOS Software [Cupertino], Catalyst L3 Switch Software (CAT9K_IOSXE), Version 17.9.4',
            'Te1/0/1', 'lldp', ['hidden' => true]);
        // LibreNMS did not link this one; its name belongs to a device the user may not see, which is not passed
        $net->neighbor(1, 'Gi1/0/50', 'secret-dist', 'Cisco IOS Software [Cupertino], Catalyst L3 Switch Software (CAT9K_IOSXE), Version 17.9.4', 'Te1/0/2');
        $result = $net->analyzer()->analyze();

        $byName = array_column($result['neighbors'], null, 'name');
        $this->assertSame(['hidden', 'switch', [], null], [$byName['core1']['status'], $byName['core1']['role'], $byName['core1']['candidates'], $byName['core1']['device_id']]);
        $this->assertSame(['not_monitored', []], [$byName['secret-dist']['status'], $byName['secret-dist']['candidates']]);
        $this->assertSame(['switch not monitored: secret-dist'], array_column($result['findings'], 'title'), 'no finding for the monitored core1');
        $this->assertSame(['not_monitored' => 1, 'not_linked' => 0, 'ambiguous' => 0, 'disabled' => 0, 'hidden' => 1], $result['summary']['statuses']);
        $this->assertSame(1, $result['summary']['not_monitored']);
        $this->assertSame([1], array_keys($result['device_names']));
    }

    public function testNeighborsOfUnknownTypeAreListedUpToTheLimit(): void
    {
        $net = self::pair()->neighbor(1, 'Gi1/0/1', 'lab-sw', 'cisco WS-C2960X-48FPD-L', 'Gi0/1', 'cdp');
        for ($i = 1; $i <= CoverageAnalyzer::ROWS + 5; $i++) {
            $net->neighbor(2, "Gi2/0/$i", sprintf('cam-%03d', $i), 'AXIS P3245-V Network Camera 10.12.199', 'eth0');
        }
        $net->neighbor(1, 'Gi1/0/2', 'SEP000000000001', 'Cisco IP Phone 8841', 'Port 1', 'cdp');
        $result = $net->analyzer()->analyze();

        $this->assertCount(CoverageAnalyzer::ROWS, $result['neighbors']);
        $this->assertSame(['lab-sw', 'cam-001', 'cam-002'], array_slice(array_column($result['neighbors'], 'name'), 0, 3));
        $this->assertSame(sprintf('cam-%03d', CoverageAnalyzer::ROWS - 1), end($result['neighbors'])['name']);
        $this->assertSame(1 + CoverageAnalyzer::ROWS + 5, $result['neighbors_total']);
        $this->assertSame(['switch' => 1, 'unknown' => CoverageAnalyzer::ROWS + 5, 'phone' => 1], $result['summary']['roles']);
        $this->assertSame(['phone' => 1], $result['summary']['other_neighbors']);
        $this->assertSame(['switch not monitored: lab-sw'], array_column($result['findings'], 'title'), 'no finding for a device of unknown type');

        $acc1 = $net->analyzer()->analyze(1);
        $this->assertSame(['lab-sw'], array_column($acc1['neighbors'], 'name'));
        $this->assertSame([1, ['phone' => 1]], [$acc1['neighbors_total'], $acc1['summary']['other_neighbors']]);
    }

    public function testWirelessControllerNotMonitored(): void
    {
        $result = self::pair()->neighbor(1, 'Gi1/0/1', 'wlc1', 'Cisco IOS Software [Cupertino], C9800 Software (C9800_IOSXE-K9), Version 17.9.4a', 'Te0/0/0')
            ->neighbor(1, 'Gi1/0/2', 'ap-lobby', 'U6-LR 6.5.62.14789', 'eth0')
            ->analyzer()->analyze();

        $this->assertSame(['wireless', 'wireless'], array_column($result['neighbors'], 'role'));
        $this->assertSame(['access point not monitored: ap-lobby', 'wireless controller not monitored: wlc1'], self::sortedTitles($result, 'not_monitored'));
    }

    public function testDisabledNeighbor(): void
    {
        $net = self::pair()->known(60, 'old-sw');
        $net->links[] = CoverageFixture::row(1, $net->port(1, 'Gi1/0/9'), 60, null, 'old-sw', 'Gi0/1');
        // not linked by LibreNMS, but named like the disabled device
        $net->neighbor(2, 'Gi1/0/9', 'old-sw.example.net', '');
        $result = $net->analyzer()->analyze();

        $this->assertCount(2, $result['neighbors']);
        $this->assertSame(['disabled', 'disabled'], array_column($result['neighbors'], 'status'));
        $this->assertSame([60, 60], array_column($result['neighbors'], 'device_id'));
        $finding = self::find($result, 'neighbor_disabled');
        $this->assertSame('neighbor disabled in LibreNMS: old-sw', $finding['title']);
        $this->assertStringContainsString('acc1 Gi1/0/9 (its port Gi0/1) sees old-sw, which is disabled in LibreNMS', $finding['detail']);
        $this->assertSame('old-sw', $result['device_names'][60]);
    }

    public function testGenericNamesAreOneNeighborPerPort(): void
    {
        $net = self::pair()
            ->neighbor(1, 'Gi1/0/1', 'Switch', 'cisco WS-C2960-24TT-L', 'Fa0/24', 'cdp')
            ->neighbor(1, 'Gi1/0/2', 'switch', 'cisco WS-C2960-24TT-L', 'Fa0/24', 'cdp');
        $result = $net->analyzer()->analyze();

        $this->assertSame(['u:1:' . $net->port(1, 'Gi1/0/1'), 'u:1:' . $net->port(1, 'Gi1/0/2')], array_column($result['neighbors'], 'id'));
        $this->assertSame(['switch not monitored: Switch on acc1 Gi1/0/1', 'switch not monitored: switch on acc1 Gi1/0/2'], self::sortedTitles($result, 'not_monitored'));
    }

    public function testSilentSwitchSeenByItsNeighbors(): void
    {
        $net = self::pair()->device(3, 'dist1')->link(1, 'Gi1/0/49', 3, 'Gi1/0/1', false)->link(2, 'Gi1/0/49', 3, 'Gi1/0/2', false);
        $result = $net->analyzer()->analyze();

        $this->assertSame(['silent_device'], self::codes($result));
        $finding = $result['findings'][0];
        $this->assertSame('info', $finding['severity'], 'like the same finding of the Link & VLAN page');
        $this->assertSame('no LLDP/CDP neighbors reported by dist1', $finding['title']);
        $this->assertStringContainsString('2 monitored neighbors see dist1 (acc1 Gi1/0/49 on its port Gi1/0/1, acc2 Gi1/0/49 on its port Gi1/0/2)', $finding['detail']);
        $this->assertStringContainsString('(the Link & VLAN page reports the same)', $finding['detail']);
        $this->assertStringContainsString('Enable LLDP or CDP on dist1', $finding['recommendation']);
        $this->assertSame([3, 1, 2], $finding['devices']);

        $row = $result['devices'][0];
        $this->assertSame(['silent', true, false], [$row['state'], $row['expected'], $row['module_off']]);
        $this->assertSame([1, 2], array_column($row['seen_by'], 'device_id'));

        $net->moduleOff[3] = true;
        $this->assertStringContainsString('The discovery-protocols module is disabled for dist1 in LibreNMS', $net->analyzer()->analyze()['findings'][0]['recommendation']);
    }

    public function testDevicesWithoutNeighborDataByRole(): void
    {
        $net = self::pair()
            ->device(3, 'rtr1', 'router')
            ->device(4, 'ap9', 'wireless')
            ->device(5, 'sw-down', 'switch', ['status' => 0])
            ->device(6, 'srv1', 'server')
            ->device(7, 'printer1', 'other', ['type' => 'printer'])
            ->device(8, 'fw1', 'firewall', ['os' => 'fortigate'])
            ->device(9, 'asa1', 'firewall', ['os' => 'asa']);
        $result = $net->analyzer()->analyze();

        $rtr1 = self::find($result, 'isolated_device', 'rtr1');
        $this->assertSame('warning', $rtr1['severity']);
        $this->assertSame('no neighbor data: rtr1', $rtr1['title']);
        $this->assertStringContainsString('stands alone on the maps', $rtr1['detail']);
        $this->assertSame('info', self::find($result, 'isolated_device', 'ap9')['severity']);
        $fw1 = self::find($result, 'isolated_device', 'fw1');
        $this->assertSame('info', $fw1['severity'], 'many firewalls run no LLDP/CDP');
        $this->assertStringContainsString('Enable LLDP or CDP on fw1 if its OS supports them', $fw1['recommendation']);
        $down = self::find($result, 'isolated_device', 'sw-down');
        $this->assertSame('info', $down['severity']);
        $this->assertStringContainsString('sw-down is down', $down['detail']);
        $this->assertNull(self::find($result, 'isolated_device', 'srv1'));
        $this->assertNull(self::find($result, 'isolated_device', 'asa1'), 'Cisco ASA supports neither LLDP nor CDP');

        // servers, printers and firewalls without LLDP/CDP are listed as quiet, after the problems
        $this->assertSame(['isolated', 'isolated', 'isolated', 'isolated', 'quiet', 'quiet', 'quiet'], array_column($result['devices'], 'state'));
        $this->assertSame([4, 8, 3, 5, 9, 7, 6], array_column($result['devices'], 'device_id'));
        $this->assertTrue($result['devices'][4]['no_xdp']);
        $this->assertSame(['silent' => 0, 'isolated' => 4, 'quiet' => 3], $result['summary']['states']);
        $this->assertSame(4, $result['summary']['devices']);
    }

    public function testServerSeenBySwitchesIsQuiet(): void
    {
        $result = self::pair()->device(3, 'esx1', 'server')->link(1, 'Gi1/0/10', 3, 'vmnic0', false)->analyzer()->analyze();

        $this->assertSame([], $result['findings']);
        $this->assertSame('quiet', $result['devices'][0]['state']);
        $this->assertSame([1], array_column($result['devices'][0]['seen_by'], 'device_id'));
    }

    public function testBusyAccessPorts(): void
    {
        $net = self::pair()->neighbor(1, 'Gi1/0/3', 'AP-1', 'cisco AIR-AP2802I-E-K9', 'Gi0', 'cdp');
        $hub = $net->busy(1, 'Gi1/0/11', 7, [20, 10], ['ifAlias' => 'room 12']);
        $net->busy(1, 'Gi1/0/12', 3);                                       // phone and PC
        $net->busy(1, 'Gi1/0/13', 30, [10, 20], ['ifTrunk' => 'dot1Q']);   // trunk to a hypervisor
        $net->busy(1, 'Gi1/0/3', 25);                                       // the access point's clients
        $net->busy(2, 'Gi1/0/48', 40);                                      // uplink to acc1
        $member = $net->busy(1, 'Gi1/0/20', 12);                            // port-channel member
        $aggregate = $net->busy(1, 'Po1', 12, [10], ['ifType' => 'ieee8023adLag']);
        $net->lag[$member] = $aggregate;
        $net->busy(1, 'Vlan10', 50, [10], ['ifType' => 'l3ipvlan']);
        $net->busy(1, 'Gi1/0/14', 9, [10], ['deleted' => 1]);               // gone from the device
        $junosTrunk = $net->busy(1, 'ge-0/0/1', 20, [10, 20]);               // trunk without the Cisco flag
        $net->portVlans[$junosTrunk] = [10 => false, 20 => false, 30 => false];
        $voice = $net->busy(1, 'Gi1/0/21', 5, [10, 20]);                     // access port with a voice VLAN
        $net->portVlans[$voice] = [10 => true, 20 => false];
        $net->busy(1, 'Gi1/0/22', 12, [10, 20, 30]);                         // three VLANs: an access point or a hypervisor
        $net->busy(1, 'wifi0', 30, [10], ['ifType' => 'ieee80211']);         // the radio of an access point
        $result = $net->analyzer()->analyze();

        $this->assertSame([$hub, $voice], array_column($result['ports'], 'port_id'));
        $this->assertSame([10, 20], $result['ports'][0]['vlans']);
        $this->assertSame([], $result['ports'][0]['neighbors']);
        $finding = self::find($result, 'hub_suspected');
        $this->assertSame('info', $finding['severity']);
        $this->assertSame('possible unmanaged switch or hub: acc1 Gi1/0/11', $finding['title']);
        $this->assertStringContainsString('acc1 Gi1/0/11 (room 12) has 7 MAC addresses in VLANs 10, 20 and no LLDP/CDP neighbor:', $finding['detail']);
        $this->assertSame(2, $result['summary']['ports']);

        $this->assertSame([], $net->analyzer(['shared_port_macs' => '8'])->analyze()['ports']);
        $this->assertSame([$hub], array_column($net->analyzer(['shared_port_macs' => '6'])->analyze()['ports'], 'port_id'));
        $this->assertSame([], $net->analyzer(['shared_port_macs' => '0'])->analyze()['ports']);
    }

    public function testPhonesAndSeveralNeighborsOnOnePort(): void
    {
        $net = self::pair();
        // a phone with an unmanaged switch behind it: the phone explains one PC, not 14 addresses
        $net->neighbor(1, 'Gi1/0/30', 'SEP001122334455', 'Cisco IP Phone 8841', 'Port 1');
        $phonePort = $net->busy(1, 'Gi1/0/30', 14, [10, 20]);
        // a phone and the PC behind it
        $net->neighbor(1, 'Gi1/0/31', 'SEP00AABBCCDDEE', 'Cisco IP Phone 8841', 'Port 1');
        $net->busy(1, 'Gi1/0/31', 2, [10, 20]);
        // two phones on one port (the repository counts the addresses of ports without other neighbours)
        $net->neighbor(1, 'Gi1/0/32', 'SEP001111111111', 'Cisco IP Phone 8841', 'Port 1')
            ->neighbor(1, 'Gi1/0/32', 'SEP002222222222', 'Cisco IP Phone 8841', 'Port 1');
        $twoPhones = $net->busy(1, 'Gi1/0/32', 6, [10, 20]);
        // two switches on one port
        $net->neighbor(2, 'Gi1/0/40', 'desk-sw1', 'cisco WS-C2960C-8PC-L', 'Gi0/1', 'cdp')
            ->neighbor(2, 'Gi1/0/40', 'desk-sw2', 'cisco WS-C2960C-8PC-L', 'Gi0/1', 'cdp');
        $twoSwitches = $net->port(2, 'Gi1/0/40');
        // one switch, seen by LLDP and CDP under two forms of its name
        $net->neighbor(2, 'Gi1/0/41', 'lab-sw.example.net', 'Cisco IOS Software, C2960X Software', 'Gi0/1')
            ->neighbor(2, 'Gi1/0/41', 'LAB-SW(FOC1234X5Y6)', 'cisco WS-C2960X-48FPD-L', 'Gi0/1', 'cdp');
        $result = $net->analyzer()->analyze();

        $this->assertSame([$twoPhones, $twoSwitches, $phonePort], array_column($result['ports'], 'port_id'));
        $this->assertSame([['SEP001111111111', 'SEP002222222222'], ['desk-sw1', 'desk-sw2'], []], array_column($result['ports'], 'neighbors'));
        $this->assertSame([6, null, 14], array_column($result['ports'], 'macs'));

        $switches = self::find($result, 'several_neighbors', 'acc2 Gi1/0/40');
        $this->assertSame('info', $switches['severity']);
        $this->assertSame('several neighbors on one port: acc2 Gi1/0/40', $switches['title']);
        $this->assertStringStartsWith('acc2 Gi1/0/40 sees 2 LLDP/CDP neighbors (desk-sw1, desk-sw2): something LibreNMS does not see connects them', $switches['detail']);
        $this->assertStringContainsString('sees 2 LLDP/CDP neighbors (SEP001111111111, SEP002222222222) and 6 MAC addresses:', self::find($result, 'several_neighbors', 'acc1 Gi1/0/32')['detail']);
        $this->assertNull(self::find($result, 'several_neighbors', 'Gi1/0/41'));
        $this->assertNull(self::find($result, 'several_neighbors', 'Gi1/0/31'));

        $phone = self::find($result, 'hub_suspected');
        $this->assertSame('possible unmanaged switch or hub: acc1 Gi1/0/30', $phone['title']);
        $this->assertStringContainsString('has 14 MAC addresses in VLANs 10, 20 and no LLDP/CDP neighbor but the phone SEP001122334455:', $phone['detail']);
        $this->assertSame(3, $result['summary']['ports']);
    }

    public function testPortOfASilentDeviceSeenByItsNeighborIsNoHub(): void
    {
        // dist1 reports nothing, but acc1 sees it on dist1's Gi1/0/1: that port faces a switch
        $net = self::pair()->device(3, 'dist1')->link(1, 'Gi1/0/49', 3, 'Gi1/0/1', false);
        $net->busy(3, 'Gi1/0/1', 60);
        $result = $net->analyzer()->analyze();

        $this->assertSame([], $result['ports']);
    }

    public function testCurrentMacCountsAreThoseOfTheLastDiscovery(): void
    {
        $groups = [
            // acc1: its last discovery ended at 1000 after 30 s and stamped the addresses it found with 980
            ['port_id' => 11, 'device_id' => 1, 'seen' => 980, 'macs' => 6],
            ['port_id' => 11, 'device_id' => 1, 'seen' => 500, 'macs' => 20], // older addresses, kept for ports_fdb_purge days
            ['port_id' => 12, 'device_id' => 1, 'seen' => 500, 'macs' => 9],  // nothing on this port any more
            ['port_id' => 13, 'device_id' => 1, 'seen' => 980, 'macs' => 2],  // too few
            // acc2: its last discovery found no address on these ports, all they have is older
            ['port_id' => 21, 'device_id' => 2, 'seen' => 500, 'macs' => 9],
            // acc3: a rediscovery was requested, the time of its last discovery is unknown: its latest addresses count
            ['port_id' => 31, 'device_id' => 3, 'seen' => 700, 'macs' => 5],
            ['port_id' => 31, 'device_id' => 3, 'seen' => 600, 'macs' => 50],
        ];
        $discovered = [1 => [1000, 30.4], 2 => [1000, 30.0], 3 => [null, 0.0]];
        $counts = CoverageAnalyzer::currentMacCounts($groups, $discovered, 4);
        $reversed = CoverageAnalyzer::currentMacCounts(new \ArrayIterator(array_reverse($groups)), $discovered, 4);
        ksort($counts);
        ksort($reversed);
        $this->assertSame($counts, $reversed, 'in any order');

        $this->assertSame([11, 31], array_keys($counts));
        $this->assertSame(['device_id' => 1, 'macs' => 6, 'last_seen' => 980], $counts[11]);
        $this->assertSame(['device_id' => 3, 'macs' => 5, 'last_seen' => 700], $counts[31]);
    }

    public function testListsAreCutButCountedInFull(): void
    {
        $net = self::pair();
        for ($i = 1; $i <= CoverageAnalyzer::ROWS + 20; $i++) {
            $net->neighbor(1, sprintf('Gi2/0/%d', $i), sprintf('desk-sw%03d', $i), 'cisco WS-C2960C-8PC-L', 'Gi0/1', 'cdp');
        }
        $result = $net->analyzer()->analyze();

        $this->assertSame(CoverageAnalyzer::ROWS, $result['rows']);
        $this->assertCount(CoverageAnalyzer::ROWS, $result['neighbors']);
        $this->assertCount(CoverageAnalyzer::ROWS, $result['findings']);
        $this->assertSame(CoverageAnalyzer::ROWS + 20, $result['neighbors_total']);
        $this->assertSame(CoverageAnalyzer::ROWS + 20, $result['findings_total']);
        $this->assertSame(CoverageAnalyzer::ROWS + 20, $result['summary']['not_monitored']);
        $this->assertSame(CoverageAnalyzer::ROWS + 20, $result['summary']['findings']['warning']);
        $this->assertSame('desk-sw001', $result['neighbors'][0]['name']);
    }

    public function testDisabledChecks(): void
    {
        $net = self::pair()->device(3, 'rtr1', 'router')
            ->neighbor(1, 'Gi1/0/1', 'lab-sw', 'cisco WS-C2960X-48FPD-L', 'Gi0/1', 'cdp')
            ->neighbor(1, 'Gi1/0/2', 'acc2.example.net', '');
        $net->busy(1, 'Gi1/0/11', 9);

        $this->assertSame(['hub_suspected', 'isolated_device', 'not_linked', 'not_monitored'], self::codes($net->analyzer()->analyze()));
        $this->assertSame(['hub_suspected', 'isolated_device'], self::codes($net->analyzer(['coverage_disabled_checks' => 'unmonitored'])->analyze()));
        $this->assertSame(['not_monitored'], self::codes($net->analyzer(['coverage_disabled_checks' => 'devices, ports not_linked'])->analyze()));
    }

    public function testDeviceFilter(): void
    {
        $net = self::pair()->device(3, 'rtr1', 'router')
            ->neighbor(1, 'Gi1/0/1', 'lab-sw', 'cisco WS-C2960X-48FPD-L', 'Gi0/1', 'cdp')
            ->neighbor(2, 'Gi1/0/1', 'lab-sw2', 'cisco WS-C2960X-48FPD-L', 'Gi0/1', 'cdp');
        $net->busy(2, 'Gi1/0/11', 9);
        $result = $net->analyzer()->analyze(2);

        $this->assertSame(['lab-sw2'], array_column($result['neighbors'], 'name'));
        $this->assertSame([], $result['devices']);
        $this->assertCount(1, $result['ports']);
        $this->assertSame(['hub_suspected', 'not_monitored'], self::codes($result));
        $this->assertSame(1, $result['summary']['not_monitored']);
    }

    public function testOnlyTheScopeIsAnalyzed(): void
    {
        $net = self::pair()->device(3, 'rtr1', 'router')->neighbor(2, 'Gi1/0/1', 'lab-sw', 'cisco WS-C2960X-48FPD-L', 'Gi0/1', 'cdp');

        $result = $net->analyzer([], [1])->analyze();
        $this->assertSame([], $result['neighbors']);
        $this->assertSame([], $result['findings']);
    }

    public function testNeighborReportsIndex(): void
    {
        $net = self::pair()->device(3, 'dist1')->link(1, 'Gi1/0/49', 3, 'Gi1/0/1', false)->neighbor(2, 'Gi1/0/2', 'lab-sw');
        $reports = new NeighborReports($net->links, $net->devices);

        $this->assertTrue($reports->reportsNeighbors(1));
        $this->assertTrue($reports->reportsNeighbors(2));
        $this->assertFalse($reports->reportsNeighbors(3));
        $this->assertTrue($reports->reports(1, $net->port(1, 'Gi1/0/49'), 3));
        $this->assertFalse($reports->reports(3, $net->port(3, 'Gi1/0/1'), 1));
        $this->assertSame([['device_id' => 1, 'port_id' => $net->port(1, 'Gi1/0/49'), 'remote_port_id' => $net->port(3, 'Gi1/0/1')]], $reports->seenBy(3));
        $this->assertSame([], $reports->seenBy(99));

        // rows of devices outside the given set are ignored, their neighbours count as unmanaged
        $partial = new NeighborReports($net->links, [1 => true, 3 => true]);
        $this->assertFalse($partial->reportsNeighbors(2));
        $this->assertFalse($partial->reports(1, $net->port(1, 'Gi1/0/48'), 2));
    }
}
