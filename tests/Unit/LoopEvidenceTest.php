<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Stp\Finding;
use Agoodley\LibrenmsTopology\Stp\LoopEvidence;
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
        $this->assertSame(['multicast_storm' => 'warning'], self::codes($net->evidence(['stp_disabled_checks' => 'broadcast_storm'])));
        $this->assertSame([], self::codes($net->evidence(['stp_disabled_checks' => 'storms'])));
        $off = $net->evidence(['stp_storm_pps' => '0']);
        $this->assertSame([], $off['findings']);
        $this->assertCount(1, $off['evidence']['storms'], 'the rates are still listed');
        $this->assertSame(0, $off['evidence']['storm_threshold']);
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
        $this->assertStringStartsWith('2 storm control messages in the last 24 hours: sw-a (1 message, port Gi1/0/49, latest 13m ago); sw-b', $finding['detail']);
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

        // the same on two switches: a loop again
        $single->device(3, 'sw-b')->log(3, '', '%L2FM-4-L2FM_MAC_MOVE: Mac 0050.56a1.0001 in vlan 10 has moved from Eth1/1 to Eth1/2');
        $this->assertSame('critical', self::find($single->evidence(), 'mac_flapping')['severity']);
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
    }

    public function testScanPattern(): void
    {
        $pattern = LoopEvidence::scanPattern(new PluginSettings);
        $this->assertStringStartsWith('~(?:loop[ _-]?back[ _-]?detected|loopback error|loop[ _-]?detect)|(?:udld|unidirectional link)|', $pattern);
        $this->assertStringEndsWith('~i', $pattern);
        $this->assertSame(10, substr_count($pattern, '(?:'));
        $this->assertSame(1, preg_match($pattern, '%SW_MATM-4-MACFLAP_NOTIF Host 0050.56a1.0001 in vlan 10 is flapping between port Gi1/0/49 and port Gi1/0/50'));
        $this->assertSame(0, preg_match($pattern, '%LINK-3-UPDOWN Interface GigabitEthernet1/0/49, changed state to up'));

        $settings = array_fill_keys(array_column(LoopEvidence::SYSLOG, 'setting'), '');
        $this->assertNull(LoopEvidence::scanPattern(new PluginSettings($settings)));
        $this->assertSame('~(?:udld)~i', LoopEvidence::scanPattern(new PluginSettings(['stp_syslog_udld' => 'udld', 'stp_syslog_duplex' => 'dup(lex'] + $settings)));
        $this->assertSame('~(?:a\~b)~i', LoopEvidence::scanPattern(new PluginSettings(['stp_syslog_udld' => 'a~b'] + $settings)));
        $this->assertNull(LoopEvidence::scanPattern(new PluginSettings(['stp_disabled_checks' => 'syslog'])));
        $this->assertSame(9, substr_count(LoopEvidence::scanPattern(new PluginSettings(['stp_disabled_checks' => 'udld'])), '(?:'));
    }

    public function testHiddenAndTruncatedSyslog(): void
    {
        $net = self::vendors();

        $hidden = $net->evidence([], ['syslog_hidden' => true, 'syslog_devices' => 5, 'syslog_scope' => 5]);
        $this->assertSame([], $hidden['findings']);
        $this->assertSame([], $hidden['evidence']['syslog']);
        $this->assertTrue($hidden['evidence']['syslog_hidden']);

        $truncated = $net->evidence([], ['syslog_truncated' => true, 'syslog_scan_limited' => 100000, 'syslog_devices' => 4, 'syslog_scope' => 5]);
        $this->assertSame([
            'Only the newest 100,000 syslog messages of the last 24 hours were searched; shorten the syslog window in the settings to cover it all.',
            'Only the newest 23 matching syslog messages of the last 24 hours were analyzed.',
        ], $truncated['evidence']['notices']);
        $this->assertSame(4, $truncated['evidence']['syslog_devices']);
        $this->assertSame(5, $truncated['evidence']['syslog_scope']);
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

        // one device: its own ports and messages, and its neighbors' ports toward it
        $sw = LoopEvidence::forDevice($result['loop_evidence'], 3);
        $this->assertSame(['sw-a'], array_column($sw['storms'], 'name'));
        $this->assertSame([3], array_column($sw['syslog'], 'device_id'));
        $this->assertSame([], LoopEvidence::forDevice($result['loop_evidence'], 9)['syslog']);
        $this->assertSame([], LoopEvidence::forDevice([], 3));
    }
}
