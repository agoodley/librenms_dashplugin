<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Interfaces\InterfaceAnalyzer;
use Agoodley\LibrenmsTopology\Interfaces\RrdTotals;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

class InterfaceAnalyzerTest extends TestCase
{
    private const NOW = 1800000000;

    private const G = 1000000000;

    private const M100 = 100000000;

    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    /**
     * Two switches: sw1 Gi1/0/1 (uplink to sw2, a monitored device), Gi1/0/2 (an access point), Gi1/0/3 and Gi1/0/4
     * (desks: no LLDP/CDP neighbor); sw2 Gi1/0/1 (the uplink's other end). Every port up at 1G full duplex.
     */
    private static function ports(array $changes = []): array
    {
        $port = fn (int $device, string $name) => ['device_id' => $device, 'ifName' => $name, 'ifDescr' => $name, 'ifAlias' => '',
            'ifType' => 'ethernetCsmacd', 'ifSpeed' => self::G, 'ifDuplex' => 'fullDuplex', 'ifOperStatus' => 'up', 'ifAdminStatus' => 'up'];
        $ports = [
            11 => $port(1, 'GigabitEthernet1/0/1'),
            12 => $port(1, 'GigabitEthernet1/0/2'),
            13 => $port(1, 'GigabitEthernet1/0/3'),
            14 => $port(1, 'GigabitEthernet1/0/4'),
            21 => $port(2, 'GigabitEthernet1/0/1'),
        ];
        foreach ($changes as $portId => $values) {
            $ports[$portId] = $values + $ports[$portId];
        }

        return $ports;
    }

    private static function neighbors(): array
    {
        return [
            11 => ['class' => InterfaceAnalyzer::INTER_SWITCH, 'name' => 'sw2', 'port' => 'Gi1/0/1'],
            21 => ['class' => InterfaceAnalyzer::INTER_SWITCH, 'name' => 'sw1', 'port' => 'Gi1/0/1'],
            12 => ['class' => InterfaceAnalyzer::NEIGHBOR, 'name' => 'ap-3', 'port' => 'eth0'],
        ];
    }

    /**
     * An eventlog change of a port: $ago seconds before now.
     */
    private static function event(int $portId, int $ago, string $message): array
    {
        return ['port_id' => $portId, 'time' => self::NOW - $ago] + InterfaceAnalyzer::parseEvent($message);
    }

    /**
     * A link going down and up again $ago seconds before now (two polls five minutes apart).
     */
    private static function bounce(int $portId, int $ago): array
    {
        return [self::event($portId, $ago, 'ifOperStatus: up -> down'), self::event($portId, $ago - 300, 'ifOperStatus: down -> up')];
    }

    private function analyze(array $events = [], array $ports = [], array $settings = [], array $counters = [], string $source = 'rrd',
        array $reboots = []): array
    {
        usort($events, fn ($a, $b) => $a['time'] <=> $b['time']);

        return (new InterfaceAnalyzer(
            [1 => ['hostname' => 'sw1', 'display' => ''], 2 => ['hostname' => 'sw2', 'display' => '']],
            self::ports($ports),
            $events,
            $reboots,
            self::neighbors(),
            $counters,
            $source,
            new PluginSettings($settings),
            self::NOW,
        ))->analyze();
    }

    /**
     * @return array<string, array[]>
     */
    private static function codes(array $result): array
    {
        $codes = [];
        foreach ($result['findings'] as $finding) {
            $codes[$finding['code']][] = $finding;
        }

        return $codes;
    }

    public function testParsesThePollersPortChanges(): void
    {
        $this->assertSame(['field' => 'oper', 'from' => 'up', 'to' => 'down'], InterfaceAnalyzer::parseEvent('ifOperStatus: up -> down'));
        $this->assertSame(['field' => 'admin', 'from' => 'up', 'to' => 'down'], InterfaceAnalyzer::parseEvent('ifAdminStatus: up -> down'));
        $this->assertSame(['field' => 'speed', 'from' => self::G, 'to' => self::M100], InterfaceAnalyzer::parseEvent('ifSpeed: 1 Gbps -> 100 Mbps'));
        $this->assertSame(['field' => 'speed', 'from' => 2500000000, 'to' => 10000000000], InterfaceAnalyzer::parseEvent('ifSpeed: 2.5 Gbps -> 10 Gbps'));
        $this->assertSame(['field' => 'speed', 'from' => 0, 'to' => null], InterfaceAnalyzer::parseEvent('ifSpeed: 0 bps -> NULL'));
        $this->assertSame(['field' => 'speed', 'from' => 10000000, 'to' => self::G], InterfaceAnalyzer::parseEvent('ifSpeed: 10000000 -> 1000000000'));
        $this->assertSame(['field' => 'duplex', 'from' => 'fullDuplex', 'to' => 'halfDuplex'], InterfaceAnalyzer::parseEvent('ifDuplex: fullDuplex -> halfDuplex'));
        $this->assertNull(InterfaceAnalyzer::parseEvent('ifAlias: uplink -> server'));
        $this->assertNull(InterfaceAnalyzer::parseEvent('Gi1/0/1 Port speed set manually: 100000000'));
        $this->assertNull(InterfaceAnalyzer::parseEvent('MAC change: 10.0.0.1 : 00:11 -> 00:22'));
    }

    public function testQuietNetworkHasNoFindings(): void
    {
        $result = $this->analyze();

        $this->assertSame([], $result['findings']);
        $this->assertSame([], $result['ports']);
        $this->assertSame('ok', $result['summary']['status']);
        $this->assertSame(['hours' => 24, 'downgrade_days' => 7, 'counters' => 'rrd'], $result['window']);
    }

    public function testLinkFlappingOnAPortWithANeighbor(): void
    {
        $events = [...self::bounce(12, 7200), ...self::bounce(12, 5400), ...self::bounce(12, 3600)];
        $result = $this->analyze($events);
        $flaps = self::codes($result)['link_flapping'] ?? [];

        $this->assertCount(1, $flaps);
        $this->assertSame('warning', $flaps[0]['severity']);
        $this->assertSame('link flapping: sw1 Gi1/0/2', $flaps[0]['title']);
        $this->assertSame('Gi1/0/2 (toward ap-3 eth0) went down 3 times in the last 24 hours, last at 2027-01-15 07:00.', $flaps[0]['detail']);
        $this->assertSame([1], $flaps[0]['devices']);
        $this->assertSame([12], $flaps[0]['ports']);
        $this->assertSame(3, $result['ports'][0]['downs']);
        $this->assertSame(['link_flapping' => 'warning'], $result['ports'][0]['issues']);
        // a speed reported while down (Cisco: 10M) is no speed change
        $this->assertSame(0, $result['ports'][0]['speed_changes']);
    }

    public function testAccessPortsHaveTheirOwnThreshold(): void
    {
        // a PC switched off and on a few times a day: listed, not a finding
        $few = [...self::bounce(13, 30000), ...self::bounce(13, 20000), ...self::bounce(13, 10000)];
        $result = $this->analyze($few);
        $this->assertArrayNotHasKey('link_flapping', self::codes($result));
        $this->assertSame([['port' => 'Gi1/0/3', 'downs' => 3, 'severity' => 'ok']],
            array_map(fn ($row) => ['port' => $row['port'], 'downs' => $row['downs'], 'severity' => $row['severity']], $result['ports']));

        $many = [];
        foreach (range(1, 10) as $i) {
            $many = [...$many, ...self::bounce(13, $i * 3000)];
        }
        $flaps = self::codes($this->analyze($many))['link_flapping'] ?? [];
        $this->assertCount(1, $flaps);
        $this->assertStringContainsString('switched off or sleeps', $flaps[0]['recommendation']);
        $this->assertArrayNotHasKey('link_flapping', self::codes($this->analyze($many, settings: ['interfaces_flaps_access' => '0'])), '0 switches it off');
    }

    public function testShutdownsRebootsAndOldChangesAreNoFlaps(): void
    {
        $shutdowns = [];
        foreach ([9000, 6000, 3000] as $ago) {
            $shutdowns[] = self::event(12, $ago, 'ifAdminStatus: up -> down');
            $shutdowns[] = self::event(12, $ago - 10, 'ifOperStatus: up -> down');
            $shutdowns[] = self::event(12, $ago - 600, 'ifAdminStatus: down -> up');
            $shutdowns[] = self::event(12, $ago - 600, 'ifOperStatus: down -> up');
        }
        $this->assertSame([], $this->analyze($shutdowns)['findings']);

        $reboots = [...self::bounce(12, 9000), ...self::bounce(12, 6000), ...self::bounce(12, 3000)];
        $this->assertSame([], $this->analyze($reboots, reboots: [1 => [self::NOW - 9200, self::NOW - 5900, self::NOW - 3000]])['findings']);
        $this->assertCount(1, $this->analyze($reboots, reboots: [2 => [self::NOW - 9000, self::NOW - 6000, self::NOW - 3000]])['findings'], 'another device\'s reboot');

        $old = [...self::bounce(12, 90000), ...self::bounce(12, 95000), ...self::bounce(12, 100000)];
        $this->assertSame([], $this->analyze($old)['findings']);
        $this->assertCount(1, $this->analyze($old, settings: ['interfaces_window_hours' => '48'])['findings']);
    }

    public function testSpeedFlappingBetween1GAnd100M(): void
    {
        // renegotiations between two polls that found the port up: 1G, 100M, 1G, 100M
        $events = [
            self::event(12, 9000, 'ifSpeed: 1 Gbps -> 100 Mbps'),
            self::event(12, 6000, 'ifSpeed: 100 Mbps -> 1 Gbps'),
            self::event(12, 3000, 'ifSpeed: 1 Gbps -> 100 Mbps'),
        ];
        $result = $this->analyze($events, [12 => ['ifSpeed' => self::M100]]);
        $codes = self::codes($result);

        $this->assertSame('speed flapping: sw1 Gi1/0/2', $codes['speed_flapping'][0]['title']);
        $this->assertSame('Gi1/0/2 (toward ap-3 eth0) changed speed 3 times in the last 24 hours (1G / 100M); the last: '
            . '2027-01-15 05:30 1G to 100M, 2027-01-15 06:20 100M to 1G, 2027-01-15 07:10 1G to 100M.', $codes['speed_flapping'][0]['detail']);
        $this->assertArrayNotHasKey('speed_downgraded', $codes, 'the flapping says it');
        $this->assertSame([self::G, self::M100], $result['ports'][0]['speeds']);
        $this->assertSame(3, $result['ports'][0]['speed_changes']);
    }

    public function testASpeedSeenWhileDownIsNoChange(): void
    {
        // a Cisco port reports 10M while down: up 1G, down 10M, up 1G, three times
        $events = [];
        foreach ([9000, 6000, 3000] as $ago) {
            $events[] = self::event(12, $ago, 'ifOperStatus: up -> down');
            $events[] = self::event(12, $ago, 'ifSpeed: 1 Gbps -> 10 Mbps');
            $events[] = self::event(12, $ago - 300, 'ifOperStatus: down -> up');
            $events[] = self::event(12, $ago - 300, 'ifSpeed: 10 Mbps -> 1 Gbps');
        }
        $result = $this->analyze($events);

        $this->assertSame(['link_flapping'], array_keys(self::codes($result)));
        $this->assertSame(0, $result['ports'][0]['speed_changes']);
    }

    public function testSpeedDowngradedAfterALinkCameBackAt100M(): void
    {
        // three days ago the AP came back at 100M after a link down
        $events = [
            self::event(12, 3 * 86400, 'ifOperStatus: up -> down'),
            self::event(12, 3 * 86400, 'ifSpeed: 1 Gbps -> 10 Mbps'),
            self::event(12, 3 * 86400 - 300, 'ifOperStatus: down -> up'),
            self::event(12, 3 * 86400 - 300, 'ifSpeed: 10 Mbps -> 100 Mbps'),
        ];
        $codes = self::codes($this->analyze($events, [12 => ['ifSpeed' => self::M100]]));

        $this->assertSame(['speed_downgraded'], array_keys($codes));
        $this->assertSame('warning', $codes['speed_downgraded'][0]['severity']);
        $this->assertSame('Gi1/0/2 (toward ap-3 eth0) runs at 100M since 2027-01-12 08:05; before it ran at 1G.', $codes['speed_downgraded'][0]['detail']);

        // a desk: info (a PC that sleeps runs at 100M too); a port down now, or back at 1G, or older than the lookback: none
        $desk = array_map(fn ($e) => ['port_id' => 13] + $e, $events);
        $this->assertSame('info', self::codes($this->analyze($desk, [13 => ['ifSpeed' => self::M100]]))['speed_downgraded'][0]['severity']);
        $this->assertSame([], $this->analyze($events, [12 => ['ifSpeed' => self::M100, 'ifOperStatus' => 'down']])['findings']);
        $this->assertSame([], $this->analyze($events)['findings'], 'it runs at 1G again');
        $this->assertSame([], $this->analyze($events, [12 => ['ifSpeed' => self::M100]], ['interfaces_downgrade_days' => '2'])['findings']);
    }

    public function testHalfDuplexOutsideTheLinksBetweenMonitoredDevices(): void
    {
        $events = [self::event(13, 4000, 'ifDuplex: fullDuplex -> halfDuplex'), self::event(13, 4000, 'ifSpeed: 1 Gbps -> 100 Mbps')];
        $half = ['ifDuplex' => 'halfDuplex', 'ifSpeed' => self::M100];
        $codes = self::codes($this->analyze($events, [13 => $half, 11 => $half, 14 => $half + ['ifOperStatus' => 'down']]));

        $this->assertCount(1, $codes['port_half_duplex']);
        $this->assertSame('half duplex: sw1 Gi1/0/3', $codes['port_half_duplex'][0]['title']);
        $this->assertSame('Gi1/0/3 runs 100M half duplex since 2027-01-15 06:53.', $codes['port_half_duplex'][0]['detail']);
        $this->assertStringContainsString('duplex mismatch', $codes['port_half_duplex'][0]['recommendation']);
    }

    public function testErrorsOverTheWindow(): void
    {
        $counters = [
            13 => ['in_errors' => 1500, 'out_errors' => 0, 'in_packets' => 3000000, 'out_packets' => 4000000, 'fcs' => 1400, 'late_collisions' => null, 'seconds' => 86400],
            14 => ['in_errors' => 99, 'out_errors' => 0, 'in_packets' => 3000000, 'out_packets' => 4000000, 'fcs' => null, 'late_collisions' => null, 'seconds' => 86400],
            11 => ['in_errors' => 0, 'out_errors' => 250, 'in_packets' => 1000, 'out_packets' => 1000, 'fcs' => null, 'late_collisions' => 12, 'seconds' => 7200],
        ];
        $result = $this->analyze(counters: $counters);
        $codes = self::codes($result);

        $this->assertSame(['interface errors: sw1 Gi1/0/1', 'interface errors: sw1 Gi1/0/3'], array_column($codes['port_errors'], 'title'));
        $this->assertSame('Gi1/0/3: 1,500 input errors (0.05% of packets) in the last 24 hours; 1,400 of the input errors are CRC (FCS) errors.',
            $codes['port_errors'][1]['detail']);
        $this->assertSame('Gi1/0/1 (toward sw2 Gi1/0/1): 250 output errors (20% of packets) in the last 2h (the data LibreNMS has).', $codes['port_errors'][0]['detail']);
        $this->assertSame('Gi1/0/1 (toward sw2 Gi1/0/1) counted 12 late collisions in the last 2h (the data LibreNMS has) (it runs full duplex now).',
            $codes['late_collisions'][0]['detail']);
        $this->assertSame(['in' => 1500, 'out' => 0, 'fcs' => 1400, 'late_collisions' => null],
            array_values(array_filter($result['ports'], fn ($row) => $row['port_id'] === 13))[0]['errors']);

        // the last poll: a link between monitored devices is the Link & VLAN page's
        $poll = self::codes($this->analyze(counters: $counters, source: 'poll'));
        $this->assertSame(['interface errors: sw1 Gi1/0/3'], array_column($poll['port_errors'], 'title'));
        $this->assertStringContainsString('in the last poll', $poll['port_errors'][0]['detail']);
        $this->assertArrayNotHasKey('port_errors', self::codes($this->analyze(counters: $counters, settings: ['interfaces_errors' => '0'])));
    }

    public function testChecksCanBeSwitchedOff(): void
    {
        $events = [...self::bounce(12, 7200), ...self::bounce(12, 5400), ...self::bounce(12, 3600)];
        $half = [13 => ['ifDuplex' => 'halfDuplex']];

        $this->assertSame(['link_flapping', 'port_half_duplex'], array_keys(self::codes($this->analyze($events, $half))));
        $this->assertSame(['port_half_duplex'], array_keys(self::codes($this->analyze($events, $half, ['interfaces_disabled_checks' => 'link_flapping']))));
        $this->assertSame(['link_flapping'], array_keys(self::codes($this->analyze($events, $half, ['interfaces_disabled_checks' => 'duplex']))));
    }

    public function testOnlyPhysicalEthernetPortsAreChecked(): void
    {
        $result = $this->analyze([...self::bounce(13, 7200)], [13 => ['ifType' => 'l2vlan', 'ifDuplex' => 'halfDuplex']]);

        $this->assertSame([], $result['findings']);
        $this->assertSame([], $result['ports']);
    }

    public function testRrdCommandReadsTotalsOfEachPortInOneRun(): void
    {
        $command = RrdTotals::command([
            11 => ['port' => '/rrd/sw1/port-id11.rrd', 'dot3' => null],
            12 => ['port' => '/rrd/2001:db8__1/port-id12.rrd', 'dot3' => '/rrd/2001:db8__1/port-id12-dot3.rrd'],
        ], 86400, 'unix:/run/rrdcached.sock');

        $this->assertSame(['graph', '/dev/null', '--start', 'end-86400', '--end', 'now', '--daemon', 'unix:/run/rrdcached.sock'], array_slice($command['args'], 0, 8));
        $this->assertContains('DEF:d0=/rrd/sw1/port-id11.rrd:INERRORS:AVERAGE', $command['args']);
        $this->assertContains('CDEF:s1=d0,UN,0,d0,IF', $command['args']);
        $this->assertContains('CDEF:k2=d0,UN,0,1,IF', $command['args']);
        $this->assertContains('DEF:d5=/rrd/sw1/port-id11.rrd:INUCASTPKTS:AVERAGE', $command['args']);
        $this->assertContains('CDEF:s7=d5,UN,0,d5,IF,d6,UN,0,d6,IF,+', $command['args']);
        $this->assertContains('DEF:d11=/rrd/2001\:db8__1/port-id12.rrd:INERRORS:AVERAGE', $command['args']);
        $this->assertContains('DEF:d22=/rrd/2001\:db8__1/port-id12-dot3.rrd:FCSErrors:AVERAGE', $command['args']);
        $this->assertSame([[11, 'in_errors'], [11, 'seconds'], [11, 'out_errors'], [11, 'in_packets'], [11, 'out_packets'],
            [12, 'in_errors'], [12, 'seconds'], [12, 'out_errors'], [12, 'in_packets'], [12, 'out_packets'], [12, 'fcs'], [12, 'late_collisions']],
            $command['prints']);

        $this->assertSame(
            [11 => ['in_errors' => 660, 'seconds' => 6600, 'out_errors' => 0, 'in_packets' => 6600000, 'out_packets' => 0]],
            RrdTotals::parse("0x0\n660\n6600\n-nan\n6600000\nnan\n", array_slice($command['prints'], 0, 5)),
        );
        $this->assertNull(RrdTotals::parse("0x0\n660\n", array_slice($command['prints'], 0, 5)), 'fewer lines than prints');
        $this->assertNull(RrdTotals::parse("0x0\nERROR: oops\n1\n2\n3\n", array_slice($command['prints'], 0, 5)));
        $this->assertSame('_rrd_x__1', RrdTotals::safeName('/rrd x::1'));
    }
}
