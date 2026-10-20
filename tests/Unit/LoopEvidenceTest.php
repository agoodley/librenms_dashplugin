<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Stp\Finding;
use Agoodley\LibrenmsTopology\Stp\LoopEvidence;
use Agoodley\LibrenmsTopology\Stp\SyslogPatterns;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

class LoopEvidenceTest extends TestCase
{
    private static function find(array $result, string $code): ?array
    {
        foreach ($result['findings'] as $finding) {
            if ($finding['code'] === $code) {
                return $finding;
            }
        }

        return null;
    }

    private static function codes(array $result): array
    {
        return array_column($result['findings'], 'severity', 'code');
    }

    /**
     * Syslog evidence rows of one check: device_id => row.
     */
    private static function rows(array $result, string $code): array
    {
        $rows = [];
        foreach ($result['evidence']['syslog'] as $row) {
            if ($row['code'] === $code) {
                $rows[$row['device_id']] = $row;
            }
        }

        return $rows;
    }

    public function testBroadcastStormBetweenSwitches(): void
    {
        $net = (new LoopFixture)->device(2, 'sw-a')->device(3, 'sw-b')->link(2, 'Gi1/0/49', 3, 'Ethernet1/49');
        $net->set(2, 'Gi1/0/49', ['ifInBroadcastPkts_rate' => 250000, 'ifInUcastPkts_rate' => 10000, 'ifInMulticastPkts_rate' => 400,
            'ifOutBroadcastPkts_rate' => 240000, 'ifOutUcastPkts_rate' => 9000]);
        $net->set(3, 'Ethernet1/49', ['ifInBroadcastPkts_rate' => 240000, 'ifInUcastPkts_rate' => 9000,
            'ifOutBroadcastPkts_rate' => 250000, 'ifOutUcastPkts_rate' => 10000]);
        $result = $net->evidence();

        $finding = self::find($result, 'broadcast_storm');
        $this->assertSame('critical', $finding['severity']);
        $this->assertSame('storms', $finding['category']);
        $this->assertSame('broadcast storm on inter-switch links', $finding['title']);
        $this->assertSame('2 inter-switch ports carried broadcast traffic at storm level at the last poll: '
            . 'sw-a Gi1/0/49 (to sw-b Eth1/49): 250,000 broadcast packets/s in, 96% of its packets, at least 17% of the link; '
            . 'sw-b Eth1/49 (to sw-a Gi1/0/49): 250,000 broadcast packets/s out, 96% of its packets, at least 17% of the link.', $finding['detail']);
        $this->assertSame([2, 3], $finding['devices']);
        $this->assertEqualsCanonicalizing([$net->portId(2, 'Gi1/0/49'), $net->portId(3, 'Ethernet1/49')], $finding['ports']);
        $this->assertNull(self::find($result, 'multicast_storm'));
        $this->assertSame(['sw-a', 'sw-b'], array_column(array_slice($result['evidence']['storms'], 0, 2), 'name'));
        $this->assertSame('critical', $result['evidence']['storms'][0]['broadcast_level']);
        $this->assertSame(2, $result['evidence']['inter_switch_ports']);
        $this->assertSame(['sw-a', 'sw-b'], array_values($result['device_names']));
    }

    public function testBusyHealthyTrunksAreNoStorm(): void
    {
        $net = (new LoopFixture)->device(1, 'core1')->device(2, 'sw-a')
            ->link(2, 'Te1/1/1', 1, 'Te1/0/1')->link(2, 'Gi1/0/48', 1, 'Gi1/0/48');
        // a large flat network: thousands of broadcasts, but a small share of a fast link
        $net->set(2, 'Te1/1/1', ['ifSpeed' => 10000000000, 'ifInBroadcastPkts_rate' => 3000, 'ifInUcastPkts_rate' => 300000]);
        // below the threshold however dominant
        $net->set(2, 'Gi1/0/48', ['ifInBroadcastPkts_rate' => 800, 'ifInUcastPkts_rate' => 100]);
        $result = $net->evidence();

        $this->assertSame([], $result['findings']);
        $this->assertCount(2, $result['evidence']['storms']);
        $this->assertSame('Te1/1/1', $result['evidence']['storms'][0]['port'], 'busiest first');
        $this->assertNull($result['evidence']['storms'][0]['broadcast_level']);
        $this->assertSame(0.01, round($result['evidence']['storms'][0]['broadcast']['share'], 2));
    }

    public function testAStormDominatesThePortOrTakesPartOfTheLink(): void
    {
        $net = (new LoopFixture)->device(2, 'sw-a')->device(3, 'sw-b')->device(4, 'sw-c')
            ->link(2, 'Gi1/0/1', 3, 'Ethernet1/1')->link(2, 'Gi1/0/2', 3, 'Ethernet1/2')->link(2, 'Fa0/3', 4, 'Ethernet3');
        $net->set(2, 'Gi1/0/1', ['ifSpeed' => 10000000000, 'ifInBroadcastPkts_rate' => 1500, 'ifInUcastPkts_rate' => 5000]); // 23% of the packets
        $net->set(2, 'Gi1/0/2', ['ifSpeed' => 10000000000, 'ifInBroadcastPkts_rate' => 1500, 'ifInUcastPkts_rate' => 50000]); // 3%, tiny load
        $net->set(2, 'Fa0/3', ['ifSpeed' => 100000000, 'ifInBroadcastPkts_rate' => 2000, 'ifInUcastPkts_rate' => 100000]); // 2%, 1.3% of 100M
        $finding = self::find($net->evidence(), 'broadcast_storm');

        $this->assertSame('warning', $finding['severity']);
        $this->assertEqualsCanonicalizing([$net->portId(2, 'Gi1/0/1'), $net->portId(2, 'Fa0/3')], $finding['ports']);
        $this->assertStringContainsString('sw-a Fa0/3 (to sw-c Eth3): 2,000 broadcast packets/s in, 2% of its packets, at least 1% of the link', $finding['detail']);
        $this->assertStringContainsString('sw-a Gi1/0/1 (to sw-b Eth1/1): 1,500 broadcast packets/s in, 23% of its packets.', $finding['detail']);

        // ten times the threshold is critical
        $net->set(2, 'Gi1/0/2', ['ifInBroadcastPkts_rate' => 12000, 'ifInUcastPkts_rate' => 20000]);
        $this->assertSame('critical', self::find($net->evidence(), 'broadcast_storm')['severity']);
    }

    public function testMulticastStaysAWarning(): void
    {
        $net = (new LoopFixture)->device(2, 'sw-a')->device(3, 'sw-b')->link(2, 'Gi1/0/5', 3, 'Ethernet1/5');
        $net->set(2, 'Gi1/0/5', ['ifInMulticastPkts_rate' => 200000, 'ifInUcastPkts_rate' => 10000, 'ifInBroadcastPkts_rate' => 50]);
        $result = $net->evidence();

        $this->assertSame(['multicast_storm' => 'warning'], self::codes($result));
        $this->assertSame('heavy multicast on inter-switch links', self::find($result, 'multicast_storm')['title']);
    }

    public function testEndDevicesDownAndStalePortsAreIgnored(): void
    {
        $net = (new LoopFixture)->device(2, 'sw-a')->device(3, 'sw-b')->device(7, 'ap1', 'wireless')->device(8, 'srv1', 'server')
            ->device(9, 'sw-down', 'switch', 0)
            ->link(2, 'Gi1/0/10', 7, 'eth0')
            ->neighbor(2, 'Gi1/0/11', 'lab-ap', 'Cisco AIR-AP2802I-E-K9')
            ->link(2, 'Gi1/0/12', 8, 'vmnic0')
            ->neighbor(2, 'Gi1/0/13', 'desk-switch')
            ->link(9, 'Gi1/0/1', 3, 'Ethernet1/9')
            ->link(2, 'Gi1/0/14', 3, 'Ethernet1/14')
            ->link(2, 'Gi1/0/15', 3, 'Ethernet1/15')
            ->link(2, 'Gi1/0/16', 3, 'Ethernet1/16');
        $storm = ['ifInBroadcastPkts_rate' => 50000, 'ifInUcastPkts_rate' => 100];
        foreach (['Gi1/0/10', 'Gi1/0/11', 'Gi1/0/12', 'Gi1/0/13', 'Gi1/0/14', 'Gi1/0/15'] as $port) {
            $net->set(2, $port, $storm);
        }
        $net->set(9, 'Gi1/0/1', $storm);
        $net->set(2, 'Gi1/0/14', ['ifOperStatus' => 'down']);
        $net->set(2, 'Gi1/0/15', ['poll_time' => LoopFixture::NOW - 2 * 86400]);
        $result = $net->evidence();

        // an unmanaged neighbor without a platform counts as a switch; access points and servers do not
        $this->assertSame([$net->portId(2, 'Gi1/0/13')], self::find($result, 'broadcast_storm')['ports']);
        $this->assertStringContainsString('sw-a Gi1/0/13 (to desk-switch eth0)', self::find($result, 'broadcast_storm')['detail']);
        $this->assertSame(9, $result['evidence']['inter_switch_ports']);
        $this->assertSame(1, $result['evidence']['ports_with_counters']);
    }

    public function testStormChecksCanBeSwitchedOff(): void
    {
        $net = (new LoopFixture)->device(2, 'sw-a')->device(3, 'sw-b')->link(2, 'Gi1/0/1', 3, 'Ethernet1/1');
        $net->set(2, 'Gi1/0/1', ['ifInBroadcastPkts_rate' => 90000, 'ifInMulticastPkts_rate' => 90000, 'ifInUcastPkts_rate' => 100]);

        $this->assertSame(['broadcast_storm' => 'critical', 'multicast_storm' => 'warning'], self::codes($net->evidence()));
        $this->assertFalse($net->evidence()['evidence']['storms_off']);

        $multicast = $net->evidence(['stp_disabled_checks' => 'broadcast_storm']);
        $this->assertSame(['multicast_storm' => 'warning'], self::codes($multicast));
        $this->assertSame([null, 'warning'], [$multicast['evidence']['storms'][0]['broadcast_level'], $multicast['evidence']['storms'][0]['multicast_level']],
            'a disabled check marks no storm in the table either');
        $this->assertFalse($multicast['evidence']['storms_off']);

        $category = $net->evidence(['stp_disabled_checks' => 'storms']);
        $this->assertSame([], self::codes($category));
        $this->assertTrue($category['evidence']['storms_off']);

        $off = $net->evidence(['stp_storm_pps' => '0']);
        $this->assertSame([], $off['findings']);
        $this->assertCount(1, $off['evidence']['storms'], 'the rates are still listed');
        $this->assertSame([null, null], [$off['evidence']['storms'][0]['broadcast_level'], $off['evidence']['storms'][0]['multicast_level']]);
        $this->assertTrue($off['evidence']['storms_off']);
    }

    public function testZeroCountersAreNoCountersAndOldOnesShowTheirAge(): void
    {
        // LibreNMS stores 0 for the counters a device does not report
        $net = (new LoopFixture)->device(2, 'sw-a')->device(3, 'sw-b')->device(4, 'sw-c')
            ->link(2, 'Gi1/0/1', 3, 'Gi1/0/1')->link(2, 'Gi1/0/2', 4, 'Gi1/0/1');
        $net->set(2, 'Gi1/0/1', ['ifInBroadcastPkts' => 0, 'ifOutBroadcastPkts' => 0, 'ifInMulticastPkts' => 0, 'ifOutMulticastPkts' => 0,
            'ifInBroadcastPkts_rate' => 0, 'ifOutBroadcastPkts_rate' => 0, 'ifInMulticastPkts_rate' => 0, 'ifOutMulticastPkts_rate' => 0,
            'ifInUcastPkts_rate' => 5000, 'ifOutUcastPkts_rate' => 5000]);
        $net->set(2, 'Gi1/0/2', ['ifInBroadcastPkts' => 0, 'ifOutBroadcastPkts' => 0, 'ifInMulticastPkts' => 81234, 'ifOutMulticastPkts' => 91234,
            'ifInBroadcastPkts_rate' => 0, 'ifOutBroadcastPkts_rate' => 0, 'ifInMulticastPkts_rate' => 3, 'ifOutMulticastPkts_rate' => 4,
            'ifInUcastPkts_rate' => 5000, 'ifOutUcastPkts_rate' => 5000]);
        $evidence = $net->evidence()['evidence'];
        $this->assertSame(4, $evidence['inter_switch_ports']);
        $this->assertSame(1, $evidence['ports_with_counters'], 'only the port with multicast counters');
        $this->assertSame('Gi1/0/2', $evidence['storms'][0]['port']);
        $this->assertNull($evidence['storms'][0]['broadcast'], 'broadcast counters at 0: not reported');
        $this->assertSame(['pps' => 4, 'direction' => 'out'], array_slice($evidence['storms'][0]['multicast'], 0, 2));
        $this->assertNull($evidence['storms'][0]['age']);

        // counters of an older poll carry their age, in the table and in the finding
        $net->set(3, 'Gi1/0/1', ['ifInBroadcastPkts' => 7, 'ifInBroadcastPkts_rate' => 250000, 'ifInUcastPkts_rate' => 1000, 'poll_time' => LoopFixture::NOW - 3 * 3600]);
        $result = $net->evidence();
        $this->assertStringContainsString('sw-b Gi1/0/1 (to sw-a Gi1/0/1, polled 3h ago): 250,000 broadcast packets/s in, 100% of its packets',
            self::find($result, 'broadcast_storm')['detail']);
        $this->assertSame(3 * 3600, $result['evidence']['storms'][0]['age']);
        $net->set(3, 'Gi1/0/1', ['poll_time' => LoopFixture::NOW - 600]);
        $this->assertNull($net->evidence()['evidence']['storms'][0]['age'], 'within two poll periods');
    }

    /**
     * Switches of several vendors with the messages they log; the ports are named like their ifName.
     */
    private static function vendors(): LoopFixture
    {
        $net = (new LoopFixture)->device(2, 'sw-a')->device(3, 'sw-b')->device(4, 'sw-c')->device(5, 'sw-d')->device(6, 'sw-e');
        foreach (['GigabitEthernet1/0/12', 'GigabitEthernet1/0/20', 'GigabitEthernet1/0/21', 'GigabitEthernet1/0/22', 'GigabitEthernet1/0/49',
            'GigabitEthernet1/0/50', 'GigabitEthernet1/0/51', 'TenGigabitEthernet1/1/1'] as $name) {
            $net->port(2, $name);
        }
        $net->port(3, 'Ethernet1/49');
        $net->port(3, 'Ethernet1/51');
        $net->port(4, 'Ethernet49');
        $net->port(4, 'Ethernet50');
        $net->port(5, 'ge-0/2/0');
        $net->port(5, 'ge-0/0/5');
        $net->port(6, '5');

        // Cisco IOS / IOS-XE: LibreNMS splits the mnemonic into the program
        $net->log(2, '%PM-4-ERR_DISABLE', 'loopback error detected on Gi1/0/12, putting Gi1/0/12 in err-disable state', 300)
            ->log(2, '%ETHCNTR-3-LOOP_BACK_DETECTED', 'Loop-back detected on GigabitEthernet1/0/12.', 320)
            ->log(2, '%SPANTREE-2-BLOCK_BPDUGUARD', 'Received BPDU on port Gi1/0/21 with BPDU Guard enabled. Disabling port.', 400)
            ->log(2, '%PM-4-ERR_DISABLE', 'bpduguard error detected on Gi1/0/21, putting Gi1/0/21 in err-disable state', 401)
            ->log(2, '%PM-4-ERR_DISABLE', 'psecure-violation error detected on Gi1/0/20, putting Gi1/0/20 in err-disable state', 500)
            ->log(2, '%SPANTREE-2-LOOPGUARD_BLOCK', 'Loop guard blocking port GigabitEthernet1/0/50 on VLAN0010.', 600)
            ->log(2, '%UDLD-4-UDLD_PORT_DISABLED', 'UDLD disabled interface Te1/1/1, unidirectional link detected', 700)
            ->log(2, '%PM-4-ERR_DISABLE', 'udld error detected on Te1/1/1, putting Te1/1/1 in err-disable state', 701)
            ->log(2, '%STORM_CONTROL-3-FILTERED', 'A packet storm was detected on Gi1/0/49. A packet filter action has been applied on the interface.', 800)
            ->log(2, '%CDP-4-NATIVE_VLAN_MISMATCH', 'Native VLAN mismatch discovered on GigabitEthernet1/0/51 (1), with sw-d ge-0/2/0 (10).', 900)
            ->log(2, '%SPANTREE-2-RECV_PVID_ERR', 'Received BPDU with inconsistent peer vlan id 10 on GigabitEthernet1/0/51 VLAN1.', 901)
            ->log(2, '%CDP-4-DUPLEX_MISMATCH', 'duplex mismatch discovered on GigabitEthernet1/0/22 (not full duplex), with SEP0011 port 1 (full duplex).', 1000)
            ->log(2, '%SYS-5-CONFIG_I', 'Configured from console by admin on vty0 (10.0.0.1)', 100)
            ->log(2, '%LINK-3-UPDOWN', 'Interface GigabitEthernet1/0/49, changed state to up', 110)
            ->log(2, '%SW_MATM-4-MACFLAP_NOTIF', 'Host 0050.56a1.0001 in vlan 10 is flapping between port Gi1/0/49 and port Gi1/0/50', 3 * 86400);
        // NX-OS: the mnemonic stays in the message
        $net->log(3, '', '%STP-2-ROOTGUARD_BLOCK: Root guard blocking port Ethernet1/51 on VLAN0010.', 300)
            ->log(3, '', '%ETHPORT-5-STORM_CONTROL_ABOVE_THRESHOLD: Traffic in port Ethernet1/49 exceeds the configured threshold , action - Trap', 350);
        // Arista
        $net->log(4, 'EBRA', '%ETH-4-HOST_FLAPPING: Host 00:50:56:a1:00:03 in VLAN 10 is flapping between interface Ethernet49 and interface Ethernet50', 200);
        // Junos
        $net->log(5, 'ESWD', 'ESWD_STP_LOOP_PROTECT_IN_EFFECT: ge-0/2/0.0: loop protect in effect for instance 0', 300)
            ->log(5, 'ESWD', 'ESWD_BPDU_BLOCK_ERROR_DISABLED: ge-0/0/5.0: bpdu-block disabled port', 310)
            ->log(5, 'ESWD', 'ESWD_STP_ROOT_PROTECT_IN_EFFECT: ge-0/2/0.0: root protect in effect for instance 0', 320);
        // ProCurve / ArubaOS-Switch
        $net->log(6, '00437', 'loop-protect:  port 5 disabled - loop detected.', 250)
            ->log(6, 'PORTS', 'port 5 is now off-line [00076]', 260);

        return $net;
    }

    public function testSyslogMessagesOfSeveralVendors(): void
    {
        $result = self::vendors()->evidence();

        // in matching order, which is also the order of the findings
        $this->assertSame([
            'loop_detected' => 'critical',
            'udld' => 'warning',
            'bpdu_guard' => 'warning',
            'loop_guard' => 'warning',
            'root_guard' => 'warning',
            'storm_control' => 'critical',
            'mac_flapping' => 'warning',
            'syslog_native_vlan' => 'warning',
            'syslog_duplex_mismatch' => 'warning',
            'err_disabled' => 'warning',
        ], self::codes($result));
        $this->assertCount(10, $result['findings']);
        $order = array_map(fn ($row) => Finding::SEVERITY_ORDER[$row['severity']], $result['evidence']['syslog']);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'critical evidence first');
        $this->assertSame(['loop_detected', 'loop_detected', 'storm_control'], array_slice(array_column($result['evidence']['syslog'], 'code'), 0, 3));

        $ports = fn (string $code, int $device) => array_column(self::rows($result, $code)[$device]['ports'], 'name');
        $this->assertSame([2, 6], self::find($result, 'loop_detected')['devices']);
        $this->assertSame(2, self::rows($result, 'loop_detected')[2]['count'], 'loopback err-disable and keepalive message');
        $this->assertSame(['Gi1/0/12'], $ports('loop_detected', 2));
        $this->assertSame(['5'], $ports('loop_detected', 6), 'ProCurve "port 5"');
        $this->assertSame(['Gi1/0/21'], $ports('bpdu_guard', 2));
        $this->assertSame(2, self::rows($result, 'bpdu_guard')[2]['count'], 'the err-disable caused by BPDU guard counts as BPDU guard');
        $this->assertSame(['ge-0/0/5'], $ports('bpdu_guard', 5), 'Junos unit .0 of the port');
        $this->assertSame(['Gi1/0/20'], $ports('err_disabled', 2));
        $this->assertSame(['Te1/1/1'], $ports('udld', 2));
        $this->assertSame(['Gi1/0/50'], $ports('loop_guard', 2));
        $this->assertSame(['ge-0/2/0'], $ports('loop_guard', 5));
        $this->assertSame(['Eth1/51'], $ports('root_guard', 3));
        $this->assertSame(['Gi1/0/49'], $ports('storm_control', 2));
        $this->assertSame(['Eth1/49'], $ports('storm_control', 3));
        $this->assertSame(['Gi1/0/51'], $ports('syslog_native_vlan', 2));
        $this->assertSame([1], self::rows($result, 'syslog_native_vlan')[2]['vlans']);
        $this->assertSame(['Gi1/0/22'], $ports('syslog_duplex_mismatch', 2));

        $flap = self::rows($result, 'mac_flapping')[4];
        $this->assertSame(['Eth49', 'Eth50'], array_column($flap['ports'], 'name'));
        $this->assertSame(1, $flap['macs']);
        $this->assertSame(['00:50:56:a1:00:03'], $flap['mac_examples']);
        $this->assertSame([10], $flap['vlans']);
        $this->assertSame('EBRA', $flap['program']);
        $this->assertSame(date('Y-m-d H:i:s', LoopFixture::NOW - 200), $flap['last']);
        $this->assertSame([4], self::find($result, 'mac_flapping')['devices'], 'the 3 day old Cisco message is outside the window');
        $this->assertSame('%PM-4-ERR_DISABLE loopback error detected on Gi1/0/12, putting Gi1/0/12 in err-disable state',
            self::rows($result, 'loop_detected')[2]['message'], 'newest message');

        $finding = self::find($result, 'storm_control');
        $this->assertStringStartsWith('2 storm messages in the last 24 hours: sw-a (1 message, port Gi1/0/49, latest 13m ago); sw-b', $finding['detail']);
        $this->assertSame('syslog', $finding['category']);
        $this->assertStringStartsWith('1 err-disable message in the last 24 hours: sw-a (1 message, port Gi1/0/20, latest 8m ago).',
            self::find($result, 'err_disabled')['detail']);
    }

    public function testMacFlappingOfManyAddressesIsALoop(): void
    {
        $net = (new LoopFixture)->device(2, 'sw-a')->device(3, 'sw-b');
        $net->port(2, 'GigabitEthernet1/0/49');
        $net->port(2, 'GigabitEthernet1/0/50');
        for ($i = 1; $i <= 12; $i++) {
            $net->log(2, '%SW_MATM-4-MACFLAP_NOTIF', sprintf('Host 0050.56a1.00%02x in vlan %d is flapping between port Gi1/0/49 and port Gi1/0/50', $i, $i > 9 ? 20 : 10), 60 + $i);
        }
        $result = $net->evidence();

        $finding = self::find($result, 'mac_flapping');
        $this->assertSame('critical', $finding['severity']);
        $this->assertSame('12 MAC move messages in the last 24 hours: sw-a (12 messages, 12 MAC addresses, VLAN 10, 20, ports Gi1/0/49, '
            . 'Gi1/0/50, latest 61s ago). The messages are listed under Loop evidence.', $finding['detail']);

        // one address on one switch: a host, not a loop
        $single = (new LoopFixture)->device(2, 'sw-a');
        $single->log(2, '%SW_MATM-4-MACFLAP_NOTIF', 'Host 0050.56a1.0001 in vlan 10 is flapping between port Gi1/0/1 and port Gi1/0/2');
        $this->assertSame('warning', self::find($single->evidence(), 'mac_flapping')['severity']);

        // the same address on two switches: a dual-homed host or NIC teaming, still no loop
        $single->device(3, 'sw-b')->log(3, '', '%L2FM-4-L2FM_MAC_MOVE: Mac 0050.56a1.0001 in vlan 10 has moved from Eth1/1 to Eth1/2');
        $this->assertSame('warning', self::find($single->evidence(), 'mac_flapping')['severity']);

        // ten addresses between them: a loop
        for ($i = 2; $i <= 9; $i++) {
            $single->log(2 + $i % 2, '%SW_MATM-4-MACFLAP_NOTIF', sprintf('Host 0050.56a1.%04x in vlan 10 is flapping between port Gi1/0/1 and port Gi1/0/2', $i));
        }
        $this->assertSame('warning', self::find($single->evidence(), 'mac_flapping')['severity'], '9 addresses');
        $single->log(3, '%SW_MATM-4-MACFLAP_NOTIF', 'Host 0050.56a1.000a in vlan 10 is flapping between port Gi1/0/1 and port Gi1/0/2');
        $this->assertSame('critical', self::find($single->evidence(), 'mac_flapping')['severity'], '10 addresses on two switches');
    }

    public function testPatternsAreSettings(): void
    {
        $net = self::vendors();
        $net->log(2, 'MYOS', 'MAC-MOVE-ALERT station 0050.56a1.0009 port Gi1/0/49', 100);

        // a site specific pattern
        $custom = $net->evidence(['stp_syslog_mac_flapping' => 'host[ _-]flapping|MAC-MOVE-ALERT']);
        $this->assertSame([2, 4], self::find($custom, 'mac_flapping')['devices']);

        // an empty pattern switches the check off: the loopback err-disable falls through to err-disable
        $off = $net->evidence(['stp_syslog_loop_detected' => '']);
        $this->assertNull(self::find($off, 'loop_detected'));
        $this->assertSame(['Gi1/0/12', 'Gi1/0/20'], array_column(self::rows($off, 'err_disabled')[2]['ports'], 'name'));

        // an invalid pattern is reported and its check skipped
        $invalid = $net->evidence(['stp_syslog_udld' => 'udld(']);
        $this->assertNull(self::find($invalid, 'udld'));
        $this->assertStringContainsString('(stp_syslog_udld) is not a valid regular expression', $invalid['evidence']['notices'][0]);

        // disabled checks: a code or the whole category
        $this->assertNull(self::find($net->evidence(['stp_disabled_checks' => 'mac_flapping']), 'mac_flapping'));
        $this->assertSame([], array_filter(array_column($net->evidence(['stp_disabled_checks' => 'syslog'])['findings'], 'category'),
            fn ($category) => $category === 'syslog'));

        // a disabled check keeps its messages: the err-disables caused by loop detection and BPDU guard do not turn
        // into err-disable findings, unlike with an empty pattern above
        $disabled = $net->evidence(['stp_disabled_checks' => 'loop_detected bpdu_guard']);
        $this->assertNull(self::find($disabled, 'loop_detected'));
        $this->assertNull(self::find($disabled, 'bpdu_guard'));
        $this->assertSame([], array_filter($disabled['evidence']['syslog'], fn ($row) => in_array($row['code'], ['loop_detected', 'bpdu_guard'], true)));
        $this->assertSame(1, self::rows($disabled, 'err_disabled')[2]['count']);
        $this->assertSame(['Gi1/0/20'], array_column(self::rows($disabled, 'err_disabled')[2]['ports'], 'name'), 'the port security err-disable only');

        // every syslog check off: the page says so instead of "no messages"
        $this->assertFalse($net->evidence()['evidence']['syslog_off']);
        $this->assertTrue($net->evidence(['stp_disabled_checks' => 'syslog'])['evidence']['syslog_off']);
        $empty = array_fill_keys(array_column(LoopEvidence::SYSLOG, 'setting'), '');
        $broken = $net->evidence(['stp_syslog_udld' => 'udld('] + $empty);
        $this->assertTrue($broken['evidence']['syslog_off']);
        $this->assertSame([], $broken['evidence']['syslog']);
        $this->assertCount(1, $broken['evidence']['notices'], 'the invalid pattern is still reported');
        $this->assertCount(0, $net->evidence(['stp_syslog_udld' => 'udld(', 'stp_disabled_checks' => 'udld'])['evidence']['notices'],
            'but not the one of a disabled check');
    }

    public function testSyslogPatterns(): void
    {
        $patterns = new SyslogPatterns(new PluginSettings);
        $this->assertTrue($patterns->usable());
        $this->assertSame('mac_flapping', $patterns->classify('%SW_MATM-4-MACFLAP_NOTIF Host 0050.56a1.0001 in vlan 10 is flapping between port Gi1/0/49 and port Gi1/0/50'));
        $this->assertNull($patterns->classify('%LINK-3-UPDOWN Interface GigabitEthernet1/0/49, changed state to up'));
        $this->assertSame([], $patterns->notices());

        // bare patterns are case-insensitive, an unescaped delimiter is escaped and an escaped one kept
        $this->assertSame('~udld~i', SyslogPatterns::compile('udld'));
        $this->assertSame('~a\~b~i', SyslogPatterns::compile('a~b'));
        $this->assertSame('~a\~b~i', SyslogPatterns::compile('a\~b'));
        $this->assertSame('~a\\\\\\~b~i', SyslogPatterns::compile('a\\\\~b'), 'a literal backslash before the delimiter');
        $this->assertNull(SyslogPatterns::compile('udld('));
        // patterns with delimiters and flags, as LibreNMS' syslog hooks write them, are used as they are
        $this->assertSame('/udld/i', SyslogPatterns::compile('/udld/i'));
        $this->assertSame('#^%UDLD#', SyslogPatterns::compile('#^%UDLD#'));
        $this->assertNull(SyslogPatterns::compile('/udld/q'), 'unknown flag');
        $this->assertSame('~/udld~i', SyslogPatterns::compile('/udld'), 'no closing delimiter: a bare pattern');

        $settings = array_fill_keys(array_column(LoopEvidence::SYSLOG, 'setting'), '');
        $this->assertFalse((new SyslogPatterns(new PluginSettings($settings)))->usable());
        $this->assertFalse((new SyslogPatterns(new PluginSettings(['stp_disabled_checks' => 'syslog'])))->usable());

        // each pattern is matched on its own: the same group name in two patterns and backreferences work as written
        $own = new SyslogPatterns(new PluginSettings([
            'stp_syslog_udld' => 'udld.*?(?<if>\S+)$', 'stp_syslog_bpdu_guard' => 'bpdu.*?(?<if>\S+)$', 'stp_syslog_root_guard' => '(root)-\1',
        ] + $settings));
        $this->assertSame('udld', $own->classify('UDLD disabled interface Te1/1/1'));
        $this->assertSame('bpdu_guard', $own->classify('BPDU guard disabled Gi1/0/5'));
        $this->assertSame('root_guard', $own->classify('root-root'));
        $this->assertNull($own->classify('root-guard'));
        $this->assertSame([], $own->notices());

        // a pattern that fails on a message (here invalid UTF-8 with the u flag) is reported
        $utf8 = new SyslogPatterns(new PluginSettings(['stp_syslog_udld' => '/udld/iu'] + $settings));
        $this->assertNull($utf8->classify("UDLD \xff disabled"));
        $this->assertSame('udld', $utf8->classify('UDLD disabled'));
        $this->assertSame(['The syslog pattern for unidirectional links (UDLD) (stp_syslog_udld) failed on 1 message '
            . '(Malformed UTF-8 characters, possibly incorrectly encoded), which it did not match.'], $utf8->notices());

        // an invalid pattern to ignore: nothing is ignored
        $ignore = new SyslogPatterns(new PluginSettings(['stp_syslog_ignore' => 'logged command(']));
        $this->assertSame('storm_control', $ignore->classify('%PARSER-5-CFGLOG_LOGGEDCMD User:netops logged command:storm-control broadcast level 1.00'));
        $this->assertSame(['The syslog pattern for messages to ignore (stp_syslog_ignore) is not a valid regular expression; no message is ignored.'],
            $ignore->notices());
    }

    public function testDelimitedPatternsAndNoticesOnThePage(): void
    {
        $net = (new LoopFixture)->device(2, 'sw-a');
        $net->log(2, '%UDLD-4-UDLD_PORT_DISABLED', 'UDLD disabled interface Te1/1/1, unidirectional link detected');

        $this->assertSame(['udld' => 'warning'], self::codes($net->evidence(['stp_syslog_udld' => '/udld/i'])));
        $this->assertSame([], self::codes($net->evidence(['stp_syslog_udld' => '/udld/'])), 'case-sensitive as written');
        $escaped = $net->evidence(['stp_syslog_udld' => 'udld|a\~b']);
        $this->assertSame(['udld' => 'warning'], self::codes($escaped));
        $this->assertSame([], $escaped['evidence']['notices']);

        // problems of the patterns are not shown to users who may not see syslog
        $this->assertCount(1, $net->evidence(['stp_syslog_udld' => 'udld('])['evidence']['notices']);
        $this->assertSame([], $net->evidence(['stp_syslog_udld' => 'udld('], ['syslog_hidden' => true])['evidence']['notices']);
    }

    public function testConfigurationCommandsAndRecoveriesAreIgnored(): void
    {
        $net = (new LoopFixture)->device(2, 'sw-a')->device(3, 'sw-b')->device(5, 'ex-1')->device(6, 'nx-1');
        $net->port(2, 'GigabitEthernet1/0/5');
        $net->port(2, 'GigabitEthernet1/0/21');
        $net->port(2, 'GigabitEthernet1/0/51');
        $net->port(5, 'ge-0/0/5');
        // configuration commands echoed to syslog (archive log config, Junos command line, Arista accounting)
        $net->log(2, '%PARSER-5-CFGLOG_LOGGEDCMD', 'User:netops  logged command:storm-control broadcast level 1.00')
            ->log(3, '%PARSER-5-CFGLOG_LOGGEDCMD', 'User:netops  logged command:storm-control broadcast level 1.00')
            ->log(2, '%PARSER-5-CFGLOG_LOGGEDCMD', 'User:netops  logged command:spanning-tree bpduguard enable')
            ->log(5, 'MGD', "UI_CMDLINE_READ_LINE: User 'netops', command 'set protocols layer2-control bpdu-block interface ge-0/0/5 '")
            ->log(5, 'MGD', 'UI_CFG_AUDIT_SET: User \'netops\' set: [protocols layer2-control bpdu-block interface ge-0/0/5]')
            ->log(3, 'Aaa', '%ACCOUNTING-6-CMD: netops vty3 10.0.0.1 stop service=shell priv-lvl=15 cmd=spanning-tree guard root');
        // recoveries
        $net->log(2, '%SPANTREE-2-ROOTGUARD_BLOCK', 'Root guard blocking port GigabitEthernet1/0/51 on VLAN0010.', 900)
            ->log(2, '%SPANTREE-2-ROOTGUARD_UNBLOCK', 'Root guard unblocking port GigabitEthernet1/0/51 on VLAN0010.', 600)
            ->log(2, '%PM-4-ERR_RECOVER', 'Attempting to recover from bpduguard err-disable state on Gi1/0/21', 500)
            ->log(6, '', '%ETHPORT-5-STORM_CONTROL_BELOW_THRESHOLD: Traffic in port Ethernet1/49 has fallen below the configured threshold', 400)
            ->log(5, 'ESWD', 'ESWD_STP_LOOP_PROTECT_CLEARED: ge-0/0/5.0: loop protect cleared for instance 0', 300);

        $result = $net->evidence();
        $this->assertSame(['root_guard' => 'warning'], self::codes($result));
        $this->assertStringStartsWith('1 root guard message in the last 24 hours: sw-a (1 message, VLAN 10, port Gi1/0/51', self::find($result, 'root_guard')['detail']);

        // the messages to ignore are a setting too
        $this->assertSame(['root_guard' => 'warning'], self::codes($net->evidence(['stp_syslog_ignore' => 'unblock|logged command|UI_|cmd=|recover|threshold|cleared'])));
        $own = $net->evidence(['stp_syslog_ignore' => 'ROOTGUARD_BLOCK|unblock']);
        $this->assertArrayNotHasKey('root_guard', self::codes($own));
        $this->assertSame('critical', self::codes($own)['storm_control'], 'the commands count again');
    }

    public function testMoreVendorMessages(): void
    {
        $net = (new LoopFixture)->device(3, 'nx-1')->device(6, 'hp-1');
        $net->port(3, 'Ethernet1/53');
        $net->port(3, 'Ethernet1/54');
        $net->port(6, 'A1');
        $net->log(3, '', '%L2FM-2-L2FM_MAC_FLAP_DISABLE_LEARN_N3K: Loops detected in the network among ports Eth1/53 and Eth1/54 vlan 1 - '
            . 'Disabling dynamic learning notifications for a period between 120 and 240 seconds on vlan 1')
            ->log(6, 'FFI', 'port A1-Excessive Broadcasts. See help. [00331]');

        $result = $net->evidence();
        $this->assertSame(['loop_detected' => 'critical', 'storm_control' => 'warning'], self::codes($result));
        $this->assertSame(['Eth1/53', 'Eth1/54'], array_column(self::rows($result, 'loop_detected')[3]['ports'], 'name'));
        $this->assertSame([1], self::rows($result, 'loop_detected')[3]['vlans']);
        $this->assertSame(['A1'], array_column(self::rows($result, 'storm_control')[6]['ports'], 'name'), 'ProCurve "port A1-..."');
    }

    public function testCdpNamesTheNeighborPortAfterWith(): void
    {
        // the neighbor's ports exist on this switch too, so only the cut keeps them out
        $net = (new LoopFixture)->device(2, 'sw-a');
        foreach (['GigabitEthernet1/0/1', 'GigabitEthernet1/0/2', 'FastEthernet0/1', 'FastEthernet0/3'] as $name) {
            $net->port(2, $name);
        }
        $net->log(2, '%CDP-4-NATIVE_VLAN_MISMATCH', 'Native VLAN mismatch discovered on GigabitEthernet1/0/1 (10), with sw-b GigabitEthernet1/0/2 (20).')
            ->log(2, '%CDP-4-DUPLEX_MISMATCH', 'duplex mismatch discovered on FastEthernet0/1 (not half duplex), with sw-c FastEthernet0/3 (half duplex).');

        $result = $net->evidence();
        $this->assertSame(['Gi1/0/1'], array_column(self::rows($result, 'syslog_native_vlan')[2]['ports'], 'name'));
        $this->assertSame(['Fa0/1'], array_column(self::rows($result, 'syslog_duplex_mismatch')[2]['ports'], 'name'));
    }

    public function testDeletedPortsAreNotNamed(): void
    {
        // after a reload renumbered the ports, LibreNMS keeps the old row as deleted next to the new one
        $net = (new LoopFixture)->device(2, 'sw-a');
        $net->port(2, 'GigabitEthernet1/0/21', ['deleted' => 1]);
        $live = $net->port(2, 'GigabitEthernet1/0/21');
        $net->log(2, '%PM-4-ERR_DISABLE', 'bpduguard error detected on Gi1/0/21, putting Gi1/0/21 in err-disable state');

        $this->assertSame([['port_id' => $live, 'name' => 'Gi1/0/21']], $net->evidence()['evidence']['syslog'][0]['ports']);
    }

    public function testTheSyslogLinkFiltersOnASharedProgramOnly(): void
    {
        $net = self::vendors();
        $result = $net->evidence();
        $this->assertSame('', self::rows($result, 'bpdu_guard')[2]['program'], 'BLOCK_BPDUGUARD and ERR_DISABLE');
        $this->assertSame('%SPANTREE-2-LOOPGUARD_BLOCK', self::rows($result, 'loop_guard')[2]['program']);
        $this->assertSame('EBRA', self::rows($result, 'mac_flapping')[4]['program']);
        $this->assertSame('', self::rows($result, 'root_guard')[3]['program'], 'NX-OS: the mnemonic is in the message');
    }

    public function testHiddenAndTruncatedSyslog(): void
    {
        $net = self::vendors();

        $hidden = $net->evidence([], ['syslog_hidden' => true, 'syslog_devices' => 5, 'syslog_scope' => 5]);
        $this->assertSame([], $hidden['findings']);
        $this->assertSame([], $hidden['evidence']['syslog']);
        $this->assertTrue($hidden['evidence']['syslog_hidden']);

        $limited = $net->evidence([], ['syslog_limited' => 2, 'syslog_scanned' => 100000, 'syslog_devices' => 4, 'syslog_scope' => 5]);
        $this->assertSame(['100,000 syslog messages of the last 24 hours were searched, which did not reach back to the start of the window on '
            . '2 busy devices; shorten the syslog window in the settings to search all of it.'], $limited['evidence']['notices']);
        $this->assertSame(4, $limited['evidence']['syslog_devices']);
        $this->assertSame(5, $limited['evidence']['syslog_scope']);

        $truncated = $net->evidence([], ['syslog_truncated' => true, 'syslog_limited' => 3, 'syslog_scanned' => 20000]);
        $this->assertSame(['More syslog messages of the last 24 hours match the checks than are analyzed at once: only the newest 23, '
            . 'taken in turns from each device, were.'], $truncated['evidence']['notices']);
    }

    public function testMergeIntoTheSpanningTreeResult(): void
    {
        $net = (new LoopFixture)->device(2, 'sw-a')->device(3, 'sw-b')->link(2, 'Gi1/0/49', 3, 'Ethernet1/49');
        $net->set(2, 'Gi1/0/49', ['ifInBroadcastPkts_rate' => 250000, 'ifInUcastPkts_rate' => 1000]);
        $net->port(3, 'Ethernet1/51');
        $net->log(3, '', '%STP-2-ROOTGUARD_BLOCK: Root guard blocking port Ethernet1/51 on VLAN0010.');
        $stp = [
            'device_names' => [1 => 'core1'],
            'summary' => ['recent_tc_window' => 3600],
            'domains' => [],
            'bridges' => [],
            'blocked' => [],
            'findings' => [['severity' => 'info', 'code' => 'tc_recent', 'title' => 'recent topology change', 'vlan' => 1, 'devices' => [1], 'ports' => []]],
        ];
        $result = LoopEvidence::merge($stp, $net->evidence());

        $this->assertSame(['broadcast_storm', 'root_guard', 'tc_recent'], array_column($result['findings'], 'code'));
        $this->assertSame(['critical' => 1, 'warning' => 1, 'info' => 1], $result['summary']['findings']);
        $this->assertSame('critical', $result['summary']['status']);
        $this->assertSame([1 => 'core1', 2 => 'sw-a', 3 => 'sw-b'], $result['device_names']);
        $this->assertCount(1, $result['loop_evidence']['syslog']);
    }
}
