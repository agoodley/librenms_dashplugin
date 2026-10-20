<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Links\LinkAnalyzer;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Topology\TopologyService;
use PHPUnit\Framework\TestCase;

class LinkAnalyzerTest extends TestCase
{
    private function analyze(
        array $ports = [],
        array $vlans = [],
        array $settings = [],
        ?array $links = null,
        ?array $lag = null,
        ?array $active = null,
        array $statistics = [],
        array $optics = [],
        ?array $devices = null,
        array $extraPorts = [],
    ): array {
        $devices ??= LinkFixture::devices();
        $analyzer = new LinkAnalyzer(
            $devices,
            array_keys($devices),
            $links ?? LinkFixture::links(),
            LinkFixture::ports($ports) + $extraPorts,
            LinkFixture::portVlans($vlans),
            $lag ?? LinkFixture::lagParent(),
            new PluginSettings($settings),
            $active ?? LinkFixture::activeVlans(),
            $statistics,
            $optics,
        );

        return $analyzer->analyze();
    }

    /**
     * @return array<string, array[]> findings by code
     */
    private function codes(array $result): array
    {
        $codes = [];
        foreach ($result['findings'] as $finding) {
            $codes[$finding['code']][] = $finding;
        }

        return $codes;
    }

    public function testConsistentNetworkHasNoFindings(): void
    {
        $result = $this->analyze();

        $this->assertSame([], $result['findings'], json_encode($result['findings']));
        $this->assertSame('ok', $result['summary']['status']);
        $this->assertSame(5, $result['summary']['links']);
        $this->assertSame(6, $result['summary']['physical']);
        $this->assertSame(1, $result['summary']['lags']);
        $this->assertSame(4, $result['summary']['devices']);

        $lag = array_values(array_filter($result['links'], fn ($link) => $link['lag'] !== null))[0];
        $this->assertSame(['members' => 2, 'up' => 2], $lag['lag']);
        $this->assertSame('Po1', $lag['a']['port']);
        $this->assertSame(['Te1/1/1', 'Te1/1/2'], $lag['a']['members']);
        $this->assertSame(20000000000, $lag['speed']);
        $this->assertSame('trunk', $lag['a']['mode']);
        $this->assertSame(1, $lag['a']['native']);
        $this->assertSame('1,10,20', $lag['a']['vlans']);
        $this->assertSame(['lldp'], $lag['protocols']);
    }

    public function testSpeedDuplexAndMtuMismatches(): void
    {
        $result = $this->analyze(ports: [
            31 => ['ifSpeed' => 1000000000],
            41 => ['ifDuplex' => 'halfDuplex'],
            32 => ['ifMtu' => 1500],
            42 => ['ifMtu' => 9198], // 18 bytes apart: header accounting differs between vendors
        ]);
        $codes = $this->codes($result);

        $this->assertSame('core1 Te1/0/3 runs at 10G, acc1 Te1/1/1 at 1G.', $codes['speed_mismatch'][0]['detail']);
        $this->assertSame('duplex mismatch: core1 Te1/0/4 ↔ acc2 Te1/1/1', $codes['duplex_mismatch'][0]['title']);
        $this->assertSame([1, 4], $codes['duplex_mismatch'][0]['devices']);
        $this->assertCount(1, $codes['mtu_mismatch']);
        $this->assertSame('core2 Te1/0/3 has MTU 9216, acc1 Te1/1/2 MTU 1500.', $codes['mtu_mismatch'][0]['detail']);
        $this->assertArrayNotHasKey('half_duplex', $codes);

        $row = $this->row($result, 'core1', 'Te1/0/3');
        $this->assertTrue($row['mismatch']['speed']);
        $this->assertSame(['speed_mismatch' => 'warning'], $row['issues']);
        $this->assertSame('warning', $row['severity']);
    }

    public function testLinksThatAreDownAreNotComparedForSpeed(): void
    {
        $result = $this->analyze(ports: [31 => ['ifSpeed' => 1000000000, 'ifOperStatus' => 'down']]);

        $this->assertArrayNotHasKey('speed_mismatch', $this->codes($result));
        $this->assertFalse($this->row($result, 'acc1', 'Te1/1/1')['mismatch']['speed']);
    }

    public function testPortModeAndVlanMismatches(): void
    {
        $codes = $this->codes($this->analyze(
            ports: [31 => ['ifTrunk' => null], 14 => ['ifTrunk' => null], 41 => ['ifTrunk' => null]],
            vlans: [
                31 => [10 => true],                     // access port facing a trunk
                23 => [99 => true, 10 => false],        // native VLAN 99 facing native VLAN 1
                14 => [20 => true], 41 => [30 => true], // access ports in different VLANs
            ],
        ));

        $this->assertSame('core1 Te1/0/3 is a trunk (native VLAN 1), acc1 Te1/1/1 an access port in VLAN 10.', $codes['mode_mismatch'][0]['detail']);
        $this->assertSame('core2 Te1/0/3 uses native VLAN 99, acc1 Te1/1/2 native VLAN 1.', $codes['native_vlan_mismatch'][0]['detail']);
        $this->assertSame('core1 Te1/0/4 is an access port in VLAN 20, acc2 Te1/1/1 in VLAN 30.', $codes['access_vlan_mismatch'][0]['detail']);
    }

    public function testCiscoTrunkCarryingOnlyItsNativeVlanIsStillATrunk(): void
    {
        $codes = $this->codes($this->analyze(vlans: [31 => [1 => true]], active: [1 => [], 2 => [], 3 => [], 4 => []]));

        $this->assertArrayNotHasKey('mode_mismatch', $codes);
    }

    public function testSplitVlanIsReportedWithTheTrunksMissingIt(): void
    {
        // acc1 has hosts in VLAN 10, but its uplinks do not carry it (the core ends do)
        $codes = $this->codes($this->analyze(vlans: [31 => [1 => true], 32 => [1 => true]]));

        $this->assertCount(1, $codes['vlan_split']);
        $split = $codes['vlan_split'][0];
        $this->assertSame('warning', $split['severity']);
        $this->assertSame('VLAN 10 is split into 2 unconnected parts', $split['title']);
        $this->assertSame(10, $split['vlan']);
        $this->assertStringContainsString('part 1: core1, core2; part 2: acc1', $split['detail']);
        $this->assertStringContainsString('core1 Te1/0/3 carries it, acc1 Te1/1/1 does not; core2 Te1/0/3 carries it, acc1 Te1/1/2 does not', $split['detail']);
        $this->assertSame([1, 2, 3], $split['devices']);
        // the same trunks are not reported a second time as allowed VLAN differences
        $this->assertArrayNotHasKey('trunk_vlan_mismatch', $codes);
    }

    public function testVlanAllowedTowardASwitchThatDoesNotUseItIsFine(): void
    {
        // the cores allow VLAN 30 toward acc1 and acc1 prunes it: normal while acc1 has no VLAN 30 hosts
        $active = LinkFixture::activeVlans();
        $active[1][30] = $active[2][30] = true;
        $coreTrunk = [1 => true, 10 => false, 20 => false, 30 => false];
        $result = $this->analyze(
            vlans: [10 => $coreTrunk, 20 => $coreTrunk, 13 => [1 => true, 10 => false, 30 => false], 23 => [1 => true, 10 => false, 30 => false]],
            active: $active,
        );

        $this->assertSame([], $result['findings'], json_encode($result['findings']));
    }

    public function testVlanJoinedThroughAnUnmanagedSwitchIsNotSplit(): void
    {
        // acc1's uplinks do not carry VLAN 10, but core1 and acc1 both reach the same unmanaged switch in it
        $links = LinkFixture::links();
        $links[] = LinkFixture::link(1, 15, 0, null, 'desk-sw', 'port 1');
        $links[] = LinkFixture::link(3, 33, 0, null, 'desk-sw', 'port 2');
        $codes = $this->codes($this->analyze(
            vlans: [31 => [1 => true], 32 => [1 => true], 15 => [10 => false], 33 => [10 => false]],
            links: $links,
            extraPorts: [15 => LinkFixture::port(15, 1, 'GigabitEthernet1/0/15'), 33 => LinkFixture::port(33, 3, 'GigabitEthernet1/0/33')],
        ));

        $this->assertArrayNotHasKey('vlan_split', $codes);
    }

    public function testVlanMissingOnARedundantTrunk(): void
    {
        // acc1 still reaches VLAN 10 through core1, but core2 does not allow it toward acc1
        $codes = $this->codes($this->analyze(vlans: [23 => [1 => true]]));

        $this->assertArrayNotHasKey('vlan_split', $codes);
        $this->assertSame('acc1 Te1/1/2 carries VLAN 10 that core2 Te1/0/3 does not, although both switches use these VLANs.', $codes['trunk_vlan_mismatch'][0]['detail']);
    }

    public function testIgnoredVlansAndDisabledChecks(): void
    {
        $result = $this->analyze(
            ports: [31 => ['ifSpeed' => 1000000000, 'ifAlias' => '']],
            vlans: [31 => [1 => true], 32 => [1 => true]],
            settings: ['links_disabled_checks' => 'mismatch description_missing', 'links_ignore_vlans' => '5-10'],
        );

        $this->assertSame([], $result['findings'], json_encode($result['findings']));
    }

    public function testPortChannelOnOneEndOnly(): void
    {
        $lag = LinkFixture::lagParent();
        unset($lag[22]); // core2 Te1/1/2 is no longer part of Po1
        $codes = $this->codes($this->analyze(lag: $lag));

        $this->assertSame('port-channel on one end only: core1 Po1', $codes['lag_unbundled'][0]['title']);
        $this->assertSame('core1 Po1 (members Te1/1/2) connects to core2 Te1/1/2, which is not part of a port-channel.', $codes['lag_unbundled'][0]['detail']);
        $this->assertCount(1, $codes);
    }

    public function testMissingPortChannelIsOnlyReportedWhenTheDeviceReportsPortChannels(): void
    {
        // acc2 bundles nothing: without ifStackTable data a missing aggregate cannot be told apart
        $lag = LinkFixture::lagParent() + [14 => 16];
        $codes = $this->codes($this->analyze(lag: $lag, extraPorts: [16 => LinkFixture::port(16, 1, 'Port-channel4', ['ifAlias' => 'acc2 Te1/1/1'])]));

        $this->assertArrayNotHasKey('lag_unbundled', $codes);
    }

    public function testPortChannelMembersInDifferentAggregates(): void
    {
        $lag = LinkFixture::lagParent();
        $lag[22] = 25; // core2 Te1/1/2 belongs to Po2
        $codes = $this->codes($this->analyze(lag: $lag, extraPorts: [25 => LinkFixture::port(25, 2, 'Port-channel2', ['ifAlias' => 'core1 Po1'])]));

        $this->assertSame('The members of core1 Po1 connect to 2 different port-channels on core2: Po1 (Te1/1/1), Po2 (Te1/1/2).', $codes['lag_split'][0]['detail']);
    }

    public function testDegradedPortChannelIsReportedOncePerLink(): void
    {
        $codes = $this->codes($this->analyze(ports: [12 => ['ifOperStatus' => 'down'], 22 => ['ifOperStatus' => 'down']]));

        $this->assertCount(1, $codes['lag_degraded']);
        $this->assertSame('core1 Po1: 1 of 2 members up, down: Te1/1/2; core2 Po1: 1 of 2 members up, down: Te1/1/2.', $codes['lag_degraded'][0]['detail']);
    }

    public function testShutDownMembersDoNotDegradeAPortChannel(): void
    {
        $down = ['ifOperStatus' => 'down', 'ifAdminStatus' => 'down'];
        $codes = $this->codes($this->analyze(ports: [12 => $down, 22 => $down]));

        $this->assertArrayNotHasKey('lag_degraded', $codes);
    }

    public function testUnevenPortChannelLoad(): void
    {
        $codes = $this->codes($this->analyze(ports: [11 => ['ifOutOctets_rate' => 937500000], 12 => ['ifOutOctets_rate' => 25000000]]));

        $this->assertCount(1, $codes['lag_imbalance']);
        $this->assertSame('Traffic sent by core1 Po1: Te1/1/1 7.5 Gbps (75%), Te1/1/2 200 Mbps (2%).', $codes['lag_imbalance'][0]['detail']);
        $this->assertStringStartsWith('The sender (core1)', $codes['lag_imbalance'][0]['recommendation']);
    }

    public function testEvenPortChannelLoadIsFine(): void
    {
        $codes = $this->codes($this->analyze(ports: [11 => ['ifOutOctets_rate' => 937500000], 12 => ['ifOutOctets_rate' => 625000000]]));

        $this->assertArrayNotHasKey('lag_imbalance', $codes);
    }

    public function testInterfaceErrorsAndDiscards(): void
    {
        $codes = $this->codes($this->analyze(
            ports: [31 => ['ifInErrors_delta' => 500], 13 => ['ifOutUcastPkts_delta' => 100000], 41 => ['ifInErrors_delta' => 5]],
            statistics: [13 => ['ifOutDiscards_delta' => 5000], 14 => ['ifOutDiscards_delta' => 50, 'ifOutUcastPkts_delta' => 0]],
        ));

        $this->assertCount(1, $codes['link_errors']);
        $this->assertSame('acc1 Te1/1/1: 500 input errors (0.05% of packets) in the last 5m.', $codes['link_errors'][0]['detail']);
        $this->assertCount(1, $codes['link_discards']);
        $this->assertSame('core1 Te1/0/3 discarded 4.8% of outgoing packets (5,000) in the last 5m.', $codes['link_discards'][0]['detail']);
    }

    public function testOpticalReceiveLevels(): void
    {
        $codes = $this->codes($this->analyze(optics: [
            31 => ['rx' => -14.5, 'low' => -14.0, 'low_warn' => -12.0, 'sensor' => 'Te1/1/1 Receive Power Sensor'],
            41 => ['rx' => -12.5, 'low' => -14.0, 'low_warn' => -12.0, 'sensor' => ''],
            32 => ['rx' => -12.5, 'low' => -14.0, 'low_warn' => null, 'sensor' => ''],
            42 => ['rx' => -3.0, 'low' => -14.0, 'low_warn' => -12.0, 'sensor' => ''],
            13 => ['rx' => -25.0, 'low' => null, 'low_warn' => null, 'sensor' => ''], // no thresholds from the device
        ]));

        $this->assertSame([
            'optical receive power below the alarm threshold: acc1 Te1/1/1' => 'critical',
            'optical receive power below the warning threshold: acc2 Te1/1/1' => 'warning',
            'optical receive power within 2 dB of the alarm threshold: acc1 Te1/1/2' => 'info',
        ], array_column($codes['optical_rx'], 'severity', 'title'));
        $this->assertSame('acc1 Te1/1/1 receives -14.5 dBm from core1 Te1/0/3 (Te1/1/1 Receive Power Sensor); transceiver thresholds: warning -12.0 dBm, alarm -14.0 dBm.',
            $codes['optical_rx'][0]['detail']);
    }

    public function testPortDescriptions(): void
    {
        $links = LinkFixture::links();
        $links[] = LinkFixture::link(3, 33, 0, null, 'ap-12.example.net', 'eth0', 'Cisco AIR-AP2802I');
        $codes = $this->codes($this->analyze(
            ports: [
                31 => ['ifAlias' => 'uplink to core2'],   // the neighbor is core1
                32 => ['ifAlias' => ''],
                13 => ['ifAlias' => 'core1 to acc1'],     // names itself and the right neighbor
                41 => ['ifAlias' => 'core1-te1/0/4'],
                42 => ['ifAlias' => 'to core20'],         // no such device
            ],
            links: $links,
            extraPorts: [33 => LinkFixture::port(33, 3, 'GigabitEthernet1/0/33', ['ifAlias' => 'to acc2'])],
        ));

        $this->assertCount(1, $codes['description_wrong']);
        $wrong = $codes['description_wrong'][0];
        $this->assertSame('port descriptions name the wrong neighbor: acc1', $wrong['title']);
        $this->assertSame('Te1/1/1 "uplink to core2" connects to core1 Te1/0/3; Gi1/0/33 "to acc2" connects to ap-12 eth0.', $wrong['detail']);
        $this->assertSame("interface TenGigabitEthernet1/1/1\n description core1 Te1/0/3\ninterface GigabitEthernet1/0/33\n description ap-12 eth0", $wrong['config']);

        $this->assertCount(1, $codes['description_missing']);
        $this->assertSame('1 port(s): Te1/1/2 (core2 Te1/0/3).', $codes['description_missing'][0]['detail']);
    }

    public function testDescriptionConfigUsesTheSyntaxOfTheOs(): void
    {
        $devices = LinkFixture::devices();
        $devices[4]['os'] = 'junos';
        $codes = $this->codes($this->analyze(ports: [41 => ['ifAlias' => '', 'ifName' => 'xe-0/0/1']], devices: $devices));

        $this->assertSame('set interfaces xe-0/0/1 description "core1 Te1/0/4"', $codes['description_missing'][0]['config']);
    }

    public function testNeighborsReportedByOneEndOnly(): void
    {
        // acc2 no longer lists core2 on Te1/1/2
        $links = array_values(array_filter(LinkFixture::links(), fn ($l) => ! ($l['local_device_id'] === 4 && $l['local_port_id'] === 42)));
        $codes = $this->codes($this->analyze(links: $links));
        $this->assertSame('acc2 does not list the LLDP/CDP neighbor on Te1/1/2 (core2 Te1/0/4), although the neighbor lists it.',
            $codes['neighbor_not_reported'][0]['detail']);

        // acc2 reports no neighbors at all
        $links = array_values(array_filter(LinkFixture::links(), fn ($l) => $l['local_device_id'] !== 4));
        $codes = $this->codes($this->analyze(links: $links));
        $this->assertSame('acc2 reports no LLDP/CDP neighbors', $codes['no_neighbor_data'][0]['title']);
        $this->assertArrayNotHasKey('neighbor_not_reported', $codes);
    }

    /**
     * leafA and leafB each bundle one link toward Po70 of an access switch (MLAG / vPC).
     */
    private function multiChassis(int $accessId = 7, array $portOverrides = []): array
    {
        $devices = [];
        foreach ([5 => 'leafA', 6 => 'leafB', $accessId => 'acc7'] as $id => $name) {
            $devices[$id] = ['device_id' => $id, 'hostname' => $name, 'display' => $name, 'os' => 'eos'];
        }
        $ports = [
            50 => LinkFixture::port(50, 5, 'Port-Channel10', ['ifAlias' => 'acc7 Po70']),
            51 => LinkFixture::port(51, 5, 'Ethernet1', ['ifAlias' => 'acc7 Te1/1/1']),
            60 => LinkFixture::port(60, 6, 'Port-Channel10', ['ifAlias' => 'acc7 Po70']),
            61 => LinkFixture::port(61, 6, 'Ethernet1', ['ifAlias' => 'acc7 Te1/1/2']),
            70 => LinkFixture::port(70, $accessId, 'Port-channel70', ['ifAlias' => 'leafA/leafB Po10']),
            71 => LinkFixture::port(71, $accessId, 'TenGigabitEthernet1/1/1', ['ifAlias' => 'leafA Ethernet1']),
            72 => LinkFixture::port(72, $accessId, 'TenGigabitEthernet1/1/2', ['ifAlias' => 'leafB Ethernet1']),
        ];
        foreach ($portOverrides as $portId => $values) {
            $ports[$portId] = array_merge($ports[$portId], $values);
        }
        $lag = [51 => 50, 61 => 60, 71 => 70, 72 => 70];
        $links = [
            LinkFixture::link(5, 51, $accessId, 71), LinkFixture::link($accessId, 71, 5, 51),
            LinkFixture::link(6, 61, $accessId, 72), LinkFixture::link($accessId, 72, 6, 61),
        ];
        $vlans = [50 => [1 => true, 10 => false], 60 => [1 => true, 10 => false], 70 => [1 => true, 10 => false]];

        $analyzer = new LinkAnalyzer($devices, array_keys($devices), $links, $ports, $vlans, $lag, new PluginSettings(), [5 => [10 => true], $accessId => [10 => true]]);

        return $analyzer->analyze();
    }

    public function testMultiChassisPortChannelIsConsistent(): void
    {
        $result = $this->multiChassis();

        $this->assertSame([], $result['findings'], json_encode($result['findings']));
        $this->assertSame(2, $result['summary']['links']);
        $this->assertSame(2, $result['summary']['lags']);
    }

    public function testUnevenLoadIsCheckedOnTheSingleChassisEndWhateverTheDeviceOrder(): void
    {
        $uneven = [71 => ['ifOutOctets_rate' => 937500000], 72 => ['ifOutOctets_rate' => 25000000]];
        foreach ([7, 3] as $accessId) {
            $codes = $this->codes($this->multiChassis($accessId, $uneven));
            $this->assertSame('Traffic sent by acc7 Po70: Te1/1/1 7.5 Gbps (75%), Te1/1/2 200 Mbps (2%).', $codes['lag_imbalance'][0]['detail'] ?? null,
                "access switch with device id $accessId");
        }
    }

    public function testSuggestedConfigurationCannotCarryCommandsFromAnLldpName(): void
    {
        $links = LinkFixture::links();
        $links[] = LinkFixture::link(3, 33, 0, null, "phone\nusername backdoor privilege 15 secret 0 pw", "port 1\r\nend");
        $codes = $this->codes($this->analyze(
            links: $links,
            extraPorts: [33 => LinkFixture::port(33, 3, 'GigabitEthernet1/0/33', ['ifAlias' => 'to acc2'])],
        ));

        $config = $codes['description_wrong'][0]['config'];
        $this->assertSame(['interface GigabitEthernet1/0/33', ' description phone_username backdoor privilege 15 secret 0 pw port 1_end'], explode("\n", $config));
    }

    public function testPortWithTwoMonitoredNeighborsMayNameEither(): void
    {
        // acc1 Gi1/0/33 sees core1 and acc2 through an unmanaged switch that passes LLDP
        $links = LinkFixture::links();
        foreach ([[3, 33, 1, 15], [1, 15, 3, 33], [3, 33, 4, 43], [4, 43, 3, 33]] as [$ld, $lp, $rd, $rp]) {
            $links[] = LinkFixture::link($ld, $lp, $rd, $rp);
        }
        $codes = $this->codes($this->analyze(links: $links, extraPorts: [
            15 => LinkFixture::port(15, 1, 'GigabitEthernet1/0/15', ['ifAlias' => 'acc1 Gi1/0/33']),
            33 => LinkFixture::port(33, 3, 'GigabitEthernet1/0/33', ['ifAlias' => 'core1 Gi1/0/15']),
            43 => LinkFixture::port(43, 4, 'GigabitEthernet1/0/43', ['ifAlias' => 'acc1 Gi1/0/33']),
        ]));

        $this->assertArrayNotHasKey('description_wrong', $codes);
    }

    public function testSplitVlanBehindATransitSwitchThatPrunesIt(): void
    {
        // core and acc both use VLAN 10 and allow it toward dist, but dist carries VLAN 1 only
        $devices = [];
        foreach ([1 => 'core', 2 => 'dist', 3 => 'acc'] as $id => $name) {
            $devices[$id] = ['device_id' => $id, 'hostname' => $name, 'display' => $name, 'os' => 'ios'];
        }
        $ports = [
            10 => LinkFixture::port(10, 1, 'TenGigabitEthernet1/0/1', ['ifAlias' => 'dist Te1/0/1']),
            20 => LinkFixture::port(20, 2, 'TenGigabitEthernet1/0/1', ['ifAlias' => 'core Te1/0/1']),
            21 => LinkFixture::port(21, 2, 'TenGigabitEthernet1/0/2', ['ifAlias' => 'acc Te1/0/1']),
            30 => LinkFixture::port(30, 3, 'TenGigabitEthernet1/0/1', ['ifAlias' => 'dist Te1/0/2']),
        ];
        $links = [];
        foreach ([[1, 10, 2, 20], [2, 20, 1, 10], [2, 21, 3, 30], [3, 30, 2, 21]] as [$ld, $lp, $rd, $rp]) {
            $links[] = LinkFixture::link($ld, $lp, $rd, $rp);
        }
        $vlans = [10 => [1 => true, 10 => false], 20 => [1 => true], 21 => [1 => true], 30 => [1 => true, 10 => false]];

        $analyzer = new LinkAnalyzer($devices, [1, 2, 3], $links, $ports, $vlans, [], new PluginSettings(), [1 => [10 => true], 3 => [10 => true]]);
        $codes = $this->codes($analyzer->analyze());

        $split = $codes['vlan_split'][0];
        $this->assertSame('warning', $split['severity']);
        $this->assertStringEndsWith('Where it stops: acc Te1/0/1 carries it, dist Te1/0/2 does not; core Te1/0/1 carries it, dist Te1/0/1 does not.', $split['detail']);
        $this->assertEqualsCanonicalizing([10, 20, 21, 30], $split['ports']);
    }

    public function testUnmanagedSwitchesWithFactoryNamesDoNotJoinASplitVlan(): void
    {
        // two different desk switches both advertise "Switch": they are not one device
        $links = LinkFixture::links();
        $links[] = LinkFixture::link(1, 15, 0, null, 'Switch', 'port 1');
        $links[] = LinkFixture::link(3, 33, 0, null, 'Switch', 'port 2');
        $codes = $this->codes($this->analyze(
            vlans: [31 => [1 => true], 32 => [1 => true], 15 => [10 => false], 33 => [10 => false]],
            links: $links,
            extraPorts: [15 => LinkFixture::port(15, 1, 'GigabitEthernet1/0/15'), 33 => LinkFixture::port(33, 3, 'GigabitEthernet1/0/33')],
        ));

        $this->assertSame('VLAN 10 is split into 2 unconnected parts', $codes['vlan_split'][0]['title'] ?? null);
    }

    public function testOpticsOfALinkShutDownOnPurposeAreIgnored(): void
    {
        $codes = $this->codes($this->analyze(
            ports: [13 => ['ifAdminStatus' => 'down', 'ifOperStatus' => 'down'], 31 => ['ifOperStatus' => 'down']],
            optics: [31 => ['rx' => -40.0, 'low' => -14.0, 'low_warn' => -12.0, 'sensor' => '']],
        ));

        $this->assertArrayNotHasKey('optical_rx', $codes);
    }

    public function testCountersAndOpticsOfAnUnreachableDeviceAreIgnored(): void
    {
        $devices = LinkFixture::devices();
        $devices[3]['status'] = 0;
        $codes = $this->codes($this->analyze(
            ports: [31 => ['ifInErrors_delta' => 500]],
            optics: [31 => ['rx' => -20.0, 'low' => -14.0, 'low_warn' => -12.0, 'sensor' => '']],
            devices: $devices,
        ));

        $this->assertArrayNotHasKey('link_errors', $codes);
        $this->assertArrayNotHasKey('optical_rx', $codes);
    }

    public function testDeviceFilterKeepsTheDevicesLinksAndFindings(): void
    {
        $result = $this->analyze(ports: [31 => ['ifSpeed' => 1000000000], 42 => ['ifMtu' => 1500]]);
        $filtered = TopologyService::filterLinksForDevice($result, 3);

        $this->assertSame(['speed_mismatch'], array_column($filtered['findings'], 'code'));
        $this->assertSame(2, $filtered['summary']['links']);
        $this->assertSame(1, $filtered['summary']['findings']['warning']);
        $this->assertSame('warning', $filtered['summary']['status']);
    }

    /**
     * Link row by the device and port of either end.
     */
    private function row(array $result, string $device, string $port): array
    {
        foreach ($result['links'] as $row) {
            foreach (['a', 'b'] as $end) {
                if ($row[$end]['name'] === $device && $row[$end]['port'] === $port) {
                    return $row;
                }
            }
        }
        $this->fail("no link row for $device $port");
    }
}
