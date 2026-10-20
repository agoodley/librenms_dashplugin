<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

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
        $net = self::pair()
            ->neighbor(1, 'Gi1/0/1', 'lab-sw', 'Cisco WS-C2960X-48FPD-L', 'Gi0/1')
            ->neighbor(1, 'Gi1/0/1', 'lab-sw', 'cisco WS-C2960X-48FPD-L', 'GigabitEthernet0/1', 'cdp')
            ->neighbor(2, 'Gi1/0/5', 'LAB-SW', '', 'Gi0/2');
        $result = $net->analyzer()->analyze();

        $this->assertCount(1, $result['neighbors']);
        $neighbor = $result['neighbors'][0];
        $this->assertSame('u:lab-sw', $neighbor['id']);
        $this->assertSame('switch', $neighbor['role']);
        $this->assertSame('not_monitored', $neighbor['status']);
        $this->assertSame(['lldp', 'cdp'], $neighbor['protocols']);
        $this->assertSame([[1, 'Gi1/0/1', 'Gi0/1'], [2, 'Gi1/0/5', 'Gi0/2']],
            array_map(fn ($s) => [$s['device_id'], $s['port'], $s['remote_port']], $neighbor['sightings']));

        $finding = self::find($result, 'not_monitored');
        $this->assertSame('warning', $finding['severity']);
        $this->assertSame('switch not monitored: lab-sw', $finding['title']);
        $this->assertStringContainsString('lab-sw (Cisco WS-C2960X-48FPD-L) is an LLDP/CDP neighbor of acc1 Gi1/0/1 (its port Gi0/1) and acc2 Gi1/0/5 (its port Gi0/2)', $finding['detail']);
        $this->assertSame([1, 2], $finding['devices']);
        $this->assertSame([$net->port(1, 'Gi1/0/1'), $net->port(2, 'Gi1/0/5')], $finding['ports']);
        $this->assertSame('unmonitored', $finding['category']);
    }

    public function testNeighborRolesDecideTheFindingsAndTheOrder(): void
    {
        $result = self::pair()
            ->neighbor(1, 'Gi1/0/1', 'phone-1', 'Cisco IP Phone 8865')
            ->neighbor(1, 'Gi1/0/2', 'esx-9', 'VMware ESX')
            ->neighbor(1, 'Gi1/0/3', 'mystery', '')
            ->neighbor(1, 'Gi1/0/4', 'AP-2F-01', 'Cisco AIR-AP2802I')
            ->neighbor(1, 'Gi1/0/5', 'desk-switch', '')
            ->neighbor(1, 'Gi1/0/6', 'fw1', 'FortiGate-100F')
            ->neighbor(1, 'Gi1/0/7', 'branch-rtr', 'Cisco ISR4331/K9')
            ->analyzer()->analyze();

        $this->assertSame(['branch-rtr', 'fw1', 'desk-switch', 'AP-2F-01', 'mystery', 'esx-9', 'phone-1'], array_column($result['neighbors'], 'name'));
        $this->assertSame(['router', 'firewall', 'switch', 'wireless', 'unknown', 'server', 'phone'], array_column($result['neighbors'], 'role'));
        $this->assertSame([true, true, true, true, false, false, false], array_column($result['neighbors'], 'network'));

        // switches, routers and firewalls are warnings, access points info; the rest is only listed
        $this->assertSame(['access point not monitored: AP-2F-01', 'firewall not monitored: fw1', 'router not monitored: branch-rtr', 'switch not monitored: desk-switch'],
            self::sortedTitles($result, 'not_monitored'));
        $this->assertSame('info', self::find($result, 'not_monitored', 'AP-2F-01')['severity']);
        $this->assertSame('warning', self::find($result, 'not_monitored', 'fw1')['severity']);
        $this->assertSame(4, $result['summary']['not_monitored']);
        $this->assertSame(['router' => 1, 'firewall' => 1, 'switch' => 1, 'wireless' => 1, 'unknown' => 1, 'server' => 1, 'phone' => 1], $result['summary']['roles']);
    }

    private static function sortedTitles(array $result, string $code): array
    {
        $titles = array_column(array_filter($result['findings'], fn ($f) => $f['code'] === $code), 'title');
        sort($titles);

        return $titles;
    }

    public function testRecommendationFollowsTheAutodiscoverySetting(): void
    {
        $net = self::pair()->neighbor(1, 'Gi1/0/1', 'lab-sw', 'Cisco WS-C2960X');

        $on = self::find($net->analyzer()->analyze(), 'not_monitored')['recommendation'];
        $this->assertStringContainsString('Add it in LibreNMS', $on);
        $this->assertStringContainsString('autodiscovery is on but did not add it', $on);

        $net->autodiscovery = false;
        $off = self::find($net->analyzer()->analyze(), 'not_monitored')['recommendation'];
        $this->assertStringContainsString('turn on LLDP/CDP autodiscovery (autodiscovery.xdp)', $off);
    }

    public function testNeighborNamedLikeAMonitoredDeviceIsNotLinked(): void
    {
        $net = self::pair()->device(3, 'core1')->device(4, 'sw1', 'switch', ['hostname' => 'sw1.b.example.net', 'sysName' => 'sw1.b.example.net', 'display' => 'sw1.b']);
        $net->addresses[3] = ['10.0.0.1'];
        $net->neighbor(1, 'Gi1/0/1', 'CORE1', 'Cisco C9500')                  // no domain: matches core1.example.net
            ->neighbor(1, 'Gi1/0/2', 'core1.other.net', 'Cisco C9500', 'Te1/1/2') // domain: matches the plain sysName core1
            ->neighbor(2, 'Gi1/0/3', '10.0.0.1', '')                          // an address of core1
            ->neighbor(2, 'Gi1/0/4', 'sw1.a.example.net', 'Cisco C9300');      // another domain: not sw1.b
        $result = $net->analyzer()->analyze();

        $byName = array_column($result['neighbors'], null, 'name');
        $this->assertSame(['not_linked', 3], [$byName['CORE1']['status'], $byName['CORE1']['device_id']]);
        $this->assertSame(['not_linked', 3], [$byName['core1.other.net']['status'], $byName['core1.other.net']['device_id']]);
        $this->assertSame(['not_linked', 3], [$byName['10.0.0.1']['status'], $byName['10.0.0.1']['device_id']]);
        $this->assertSame('10.0.0.1', $byName['10.0.0.1']['address']);
        $byAddress = self::find($result, 'not_linked', '10.0.0.1');
        $this->assertStringContainsString('sees 10.0.0.1, which is an address of the monitored device core1', $byAddress['detail']);
        $this->assertStringContainsString('core1 advertises the address 10.0.0.1 instead of its name', $byAddress['recommendation']);
        $this->assertSame('not_monitored', $byName['sw1.a.example.net']['status']);

        $finding = self::find($result, 'not_linked', 'CORE1');
        $this->assertSame('info', $finding['severity']);
        $this->assertSame('neighbor not linked to its device: CORE1 (core1)', $finding['title']);
        $this->assertStringContainsString('acc1 Gi1/0/1 (its port Gi0/1) sees CORE1 (Cisco C9500), which is the name of the monitored device core1', $finding['detail']);
        $this->assertStringContainsString("Make core1's sysName or hostname match 'CORE1', then run discovery on acc1 again.", $finding['recommendation']);
        $this->assertSame([1, 3], $finding['devices']);
    }

    public function testADeviceSeeingItsOwnNameIsNotAMatch(): void
    {
        $result = self::pair()->neighbor(1, 'Gi1/0/9', 'acc1', 'Cisco C9300')->analyzer()->analyze();

        $this->assertSame('not_monitored', $result['neighbors'][0]['status']);
    }

    public function testNeighborsMonitoredOutOfSightAreLeftOut(): void
    {
        $net = (new CoverageFixture)->device(1, 'acc1');
        // a monitored neighbour on a device the user may not see (the repository removed its ids)
        $net->neighbor(1, 'Gi1/0/49', 'secret-core', 'Cisco C9500', 'Te1/0/1', 'lldp', ['hidden' => true]);
        // LibreNMS did not link this one, but its name belongs to a device the user may not see
        $net->known(50, 'secret-dist', false)->neighbor(1, 'Gi1/0/50', 'secret-dist', 'Cisco C9500');
        $result = $net->analyzer()->analyze();

        $this->assertSame([], $result['neighbors']);
        $this->assertSame([], $result['findings'], 'acc1 reports neighbors: no device finding either');
        $this->assertArrayNotHasKey(50, $result['device_names']);
    }

    public function testDisabledNeighbor(): void
    {
        $net = self::pair()->known(60, 'old-sw', true, true);
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
        $result = self::pair()
            ->neighbor(1, 'Gi1/0/1', 'Switch', 'Cisco WS-C2960')
            ->neighbor(1, 'Gi1/0/2', 'switch', 'Cisco WS-C2960')
            ->analyzer()->analyze();

        $this->assertSame(['u:1:' . 5003, 'u:1:' . 5004], array_column($result['neighbors'], 'id'));
        $this->assertSame(['switch not monitored: Switch on acc1 Gi1/0/1', 'switch not monitored: switch on acc1 Gi1/0/2'], self::sortedTitles($result, 'not_monitored'));
    }

    public function testSilentSwitchSeenByItsNeighbors(): void
    {
        $net = self::pair()->device(3, 'dist1')->link(1, 'Gi1/0/49', 3, 'Gi1/0/1', false)->link(2, 'Gi1/0/49', 3, 'Gi1/0/2', false);
        $result = $net->analyzer()->analyze();

        $this->assertSame(['silent_device'], self::codes($result));
        $finding = $result['findings'][0];
        $this->assertSame('warning', $finding['severity']);
        $this->assertSame('no LLDP/CDP neighbors reported by dist1', $finding['title']);
        $this->assertStringContainsString('2 monitored neighbors see dist1 (acc1 Gi1/0/49 on its port Gi1/0/1, acc2 Gi1/0/49 on its port Gi1/0/2)', $finding['detail']);
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
            ->device(7, 'printer1', 'other', ['type' => 'printer']);
        $result = $net->analyzer()->analyze();

        $this->assertSame('warning', self::find($result, 'isolated_device', 'rtr1')['severity']);
        $this->assertSame('no neighbor data: rtr1', self::find($result, 'isolated_device', 'rtr1')['title']);
        $this->assertStringContainsString('stands alone on the maps', self::find($result, 'isolated_device', 'rtr1')['detail']);
        $this->assertSame('info', self::find($result, 'isolated_device', 'ap9')['severity']);
        $down = self::find($result, 'isolated_device', 'sw-down');
        $this->assertSame('info', $down['severity']);
        $this->assertStringContainsString('sw-down is down', $down['detail']);
        $this->assertNull(self::find($result, 'isolated_device', 'srv1'));

        // servers and printers are listed as quiet, after the problems
        $this->assertSame(['isolated', 'isolated', 'isolated', 'quiet', 'quiet'], array_column($result['devices'], 'state'));
        $this->assertSame([4, 3, 5, 7, 6], array_column($result['devices'], 'device_id'));
        $this->assertSame(['silent' => 0, 'isolated' => 3, 'quiet' => 2], $result['summary']['states']);
        $this->assertSame(3, $result['summary']['devices']);
    }

    public function testServerSeenBySwitchesIsQuiet(): void
    {
        $result = self::pair()->device(3, 'esx1', 'server')->link(1, 'Gi1/0/10', 3, 'vmnic0', false)->analyzer()->analyze();

        $this->assertSame([], $result['findings']);
        $this->assertSame('quiet', $result['devices'][0]['state']);
        $this->assertSame([1], array_column($result['devices'][0]['seen_by'], 'device_id'));
    }

    public function testBusyPortsWithoutNeighbor(): void
    {
        $net = self::pair()->neighbor(1, 'Gi1/0/3', 'AP-1', 'Cisco AIR-AP2802I');
        $hub = $net->busy(1, 'Gi1/0/11', 7, [20, 10], ['ifAlias' => 'room 12']);
        $net->busy(1, 'Gi1/0/12', 3);                                     // phone and PC
        $net->busy(1, 'Gi1/0/13', 30, [10, 20, 30], ['ifTrunk' => 'dot1Q']); // trunk to a hypervisor
        $net->busy(1, 'Gi1/0/3', 25);                                     // the access point's clients
        $net->busy(2, 'Gi1/0/48', 40);                                    // uplink to acc1
        $member = $net->busy(1, 'Gi1/0/20', 12);                          // port-channel member
        $aggregate = $net->busy(1, 'Po1', 12, [10], ['ifType' => 'ieee8023adLag']);
        $net->lag[$member] = $aggregate;
        $net->busy(1, 'Vlan10', 50, [10], ['ifType' => 'l3ipvlan']);
        $net->busy(1, 'Gi1/0/14', 9, [10], ['deleted' => 1]);             // gone from the device
        $junosTrunk = $net->busy(1, 'ge-0/0/1', 20, [10, 20]);             // trunk without the Cisco flag
        $net->portVlans[$junosTrunk] = [10 => false, 20 => false, 30 => false];
        $voice = $net->busy(1, 'Gi1/0/21', 5, [10, 20]);                   // access port with a voice VLAN
        $net->portVlans[$voice] = [10 => true, 20 => false];
        $result = $net->analyzer()->analyze();

        $this->assertSame([$hub, $voice], array_column($result['ports'], 'port_id'));
        $this->assertSame([10, 20], $result['ports'][0]['vlans']);
        $finding = self::find($result, 'hub_suspected');
        $this->assertSame('info', $finding['severity']);
        $this->assertSame('possible unmanaged switch or hub: acc1 Gi1/0/11', $finding['title']);
        $this->assertStringContainsString('acc1 Gi1/0/11 (room 12) has 7 MAC addresses in VLANs 10, 20 and no LLDP/CDP neighbor', $finding['detail']);

        $this->assertSame([], $net->analyzer(['coverage_mac_threshold' => '8'])->analyze()['ports']);
        $this->assertSame([$hub], array_column($net->analyzer(['coverage_mac_threshold' => '6'])->analyze()['ports'], 'port_id'));
        $this->assertSame([], $net->analyzer(['coverage_mac_threshold' => '0'])->analyze()['ports']);
    }

    public function testPortOfASilentDeviceSeenByItsNeighborIsNoHub(): void
    {
        // dist1 reports nothing, but acc1 sees it on dist1's Gi1/0/1: that port faces a switch
        $net = self::pair()->device(3, 'dist1')->link(1, 'Gi1/0/49', 3, 'Gi1/0/1', false);
        $net->busy(3, 'Gi1/0/1', 60);
        $result = $net->analyzer()->analyze();

        $this->assertSame([], $result['ports']);
    }

    public function testDisabledChecks(): void
    {
        $net = self::pair()->device(3, 'rtr1', 'router')->neighbor(1, 'Gi1/0/1', 'lab-sw', 'Cisco WS-C2960X')->neighbor(1, 'Gi1/0/2', 'acc2.example.net', '');
        $net->busy(1, 'Gi1/0/11', 9);

        $this->assertSame(['hub_suspected', 'isolated_device', 'not_linked', 'not_monitored'], self::codes($net->analyzer()->analyze()));
        $this->assertSame(['hub_suspected', 'isolated_device'], self::codes($net->analyzer(['coverage_disabled_checks' => 'unmonitored'])->analyze()));
        $this->assertSame(['not_monitored'], self::codes($net->analyzer(['coverage_disabled_checks' => 'devices, ports not_linked'])->analyze()));
    }

    public function testDeviceFilter(): void
    {
        $net = self::pair()->device(3, 'rtr1', 'router')
            ->neighbor(1, 'Gi1/0/1', 'lab-sw', 'Cisco WS-C2960X')
            ->neighbor(2, 'Gi1/0/1', 'lab-sw2', 'Cisco WS-C2960X');
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
        $net = self::pair()->device(3, 'rtr1', 'router')->neighbor(2, 'Gi1/0/1', 'lab-sw', 'Cisco WS-C2960X');

        $result = $net->analyzer([], [1]);
        $result = $result->analyze();
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
