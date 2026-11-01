<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Capacity\CapacityAnalyzer as Capacity;
use Agoodley\LibrenmsTopology\Capacity\CapacityCsv;
use Agoodley\LibrenmsTopology\Support\Csv;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

class CapacityAnalyzerTest extends TestCase
{
    private const NOW = 1793530800; // 2026-11-01 12:00:00 UTC

    private const DAY = 86400;

    /**
     * Closet A: sw1 (up 200 days) with every kind of port, sw2 (restarted 3 days ago); Closet B: sw3, 20 ports of which
     * one is free (PoE data: ports 1-15 PoE); sw4 without a location, up 2 days, the eventlog empty.
     */
    private function analyzer(array $settings = [], ?int $days = null): Capacity
    {
        $polled = self::NOW - 300;
        $device = fn (int $id, string $name, ?int $location, string $locationName, int $uptimeDays) => [
            'device_id' => $id, 'name' => $name, 'hostname' => $name . '.example.net', 'location_id' => $location, 'location' => $locationName,
            'uptime' => $uptimeDays * self::DAY, 'last_polled' => $polled, 'inserted' => self::NOW - 400 * self::DAY, 'url' => "/device/$id",
        ];
        $devices = [
            1 => $device(1, 'sw1', 10, 'Closet A', 200),
            2 => $device(2, 'sw2', 10, 'Closet A', 3),
            3 => $device(3, 'sw3', 20, 'Closet B', 200),
            4 => $device(4, 'sw4', null, '', 2),
        ];
        // ifLastChange of a change $ago days before the poll, on a switch up $uptime days
        $ticks = fn (int $uptimeDays, float $agoDays) => (int) round(($uptimeDays - $agoDays) * self::DAY * 100);
        $port = fn (int $id, int $deviceId, string $name, string $oper, string $admin = 'up', int $lastChange = 0, string $alias = '', ?string $vlan = '20',
            ?string $trunk = null) => [
            'port_id' => $id, 'device_id' => $deviceId, 'ifName' => $name, 'ifDescr' => $name, 'ifAlias' => $alias, 'ifType' => 'ethernetCsmacd',
            'ifSpeed' => 1000000000, 'ifOperStatus' => $oper, 'ifAdminStatus' => $admin, 'ifLastChange' => $lastChange, 'ifVlan' => $vlan,
            'ifTrunk' => $trunk, 'poll_time' => $polled, 'url' => "/port/$id",
        ];
        $ports = [
            101 => $port(101, 1, 'GigabitEthernet1/0/1', 'up', lastChange: $ticks(200, 30)),
            102 => $port(102, 1, 'GigabitEthernet1/0/2', 'down', lastChange: $ticks(200, 60), alias: 'desk 2'),
            103 => $port(103, 1, 'GigabitEthernet1/0/3', 'down', lastChange: $ticks(200, 5)),
            104 => $port(104, 1, 'GigabitEthernet1/0/4', 'down', 'down', 3000),
            105 => $port(105, 1, 'GigabitEthernet1/0/48', 'up', lastChange: $ticks(200, 100)),
            106 => $port(106, 1, 'GigabitEthernet1/1/1', 'notPresent'),
            107 => $port(107, 1, 'GigabitEthernet1/0/7', 'down', lastChange: $ticks(200, 2)),
            108 => $port(108, 1, 'GigabitEthernet1/0/8', 'down', lastChange: $ticks(200, 40)),
            109 => $port(109, 1, 'TenGigabitEthernet1/1/1', 'down', lastChange: $ticks(200, 50)),
            110 => $port(110, 1, 'GigabitEthernet1/0/45', 'up', lastChange: $ticks(200, 100)),
            111 => $port(111, 1, 'GigabitEthernet1/0/46', 'up', lastChange: $ticks(200, 100)),
            // sw2 restarted 3 days ago: every port changed then
            201 => $port(201, 2, 'Gi1/0/1', 'down', lastChange: 4000),
            202 => $port(202, 2, 'Gi1/0/2', 'down', lastChange: 4000),
            203 => $port(203, 2, 'Gi1/0/3', 'down', lastChange: 4000),
            205 => $port(205, 2, 'Gi1/0/5', 'down', lastChange: 4000),
            206 => $port(206, 2, 'Gi1/0/6', 'up', lastChange: 4000),
            401 => $port(401, 4, 'Gi0/1', 'down', lastChange: 0),
            402 => $port(402, 4, 'Gi0/2', 'up', lastChange: 0),
            // trunks: a spare uplink in trunk mode (no access VLAN while the other ports have one), an access point on a
            // trunk (an access port in use), a trunk to something without LLDP/CDP
            403 => $port(403, 4, 'Gi0/3', 'down', lastChange: 0, vlan: null),
            404 => $port(404, 4, 'Gi0/4', 'up', lastChange: 0, vlan: '1', trunk: 'dot1Q'),
            405 => $port(405, 4, 'Gi0/5', 'up', lastChange: 0, vlan: '1', trunk: 'dot1Q'),
        ];
        $poe = [];
        for ($i = 1; $i <= 20; $i++) {
            $ports[300 + $i] = $port(300 + $i, 3, 'Gi1/0/' . $i, $i === 20 ? 'up' : ($i === 3 ? 'down' : 'up'), lastChange: $ticks(200, $i === 3 ? 90 : 1));
            $poe[300 + $i] = $i <= 15;
        }
        $neighbors = [
            101 => ['uplink' => false, 'name' => 'SEP001122334455 Port 1'],
            105 => ['uplink' => true, 'name' => 'dist1 Te1/0/1'],
            111 => ['uplink' => true, 'name' => 'dist2 Te1/0/1'],
            404 => ['uplink' => false, 'name' => 'ap-12'],
        ];
        $lags = [110 => 199, 111 => 199];
        $records = [
            201 => ['first_seen' => self::NOW - 200 * self::DAY, 'last_up' => self::NOW - 100 * self::DAY, 'err_disabled_at' => null, 'err_disabled_message' => null],
            // recovered: up after the message, then unplugged
            108 => ['first_seen' => self::NOW - 200 * self::DAY, 'last_up' => null, 'err_disabled_at' => self::NOW - 41 * self::DAY,
                'err_disabled_message' => '%PM-4-ERR_DISABLE: bpduguard error detected on Gi1/0/8, putting Gi1/0/8 in err-disable state'],
        ];
        $eventUps = [203 => self::NOW - 10 * self::DAY];
        $eventSince = [1 => self::NOW - 30 * self::DAY, 2 => self::NOW - 30 * self::DAY, 3 => self::NOW - 30 * self::DAY];
        $errors = [
            107 => ['time' => self::NOW - 2 * self::DAY - 300, 'message' => '%PM-4-ERR_DISABLE: bpduguard error detected on Gi1/0/7, putting Gi1/0/7 in err-disable state'],
            // before the restart: the restart cleared it
            205 => ['time' => self::NOW - 5 * self::DAY, 'message' => '%PM-4-ERR_DISABLE: loopback error detected on Gi1/0/5'],
        ];

        return new Capacity($devices, $ports, $neighbors, $lags, $records, $eventUps, $eventSince, $errors, $poe,
            new PluginSettings($settings + ['capacity_exclude_ports' => '^Te']), self::NOW, $days);
    }

    private static function ports(array $result): array
    {
        return array_column($result['ports'], null, 'port_id');
    }

    private static function devices(array $result): array
    {
        return array_column($result['devices'], null, 'device_id');
    }

    public function testStatesOfThePorts(): void
    {
        $ports = self::ports($this->analyzer()->analyze());

        $this->assertSame(Capacity::IN_USE, $ports[101]['state']);
        $this->assertSame('SEP001122334455 Port 1', $ports[101]['neighbor'], 'a phone is no uplink');
        $this->assertSame(Capacity::FREE, $ports[102]['state']);
        $this->assertSame(Capacity::SHUTDOWN, $ports[104]['state']);
        $this->assertSame(Capacity::UPLINK, $ports[105]['state']);
        $this->assertSame(Capacity::NOT_PRESENT, $ports[106]['state']);
        $this->assertSame(Capacity::ERR_DISABLED, $ports[107]['state']);
        $this->assertStringContainsString('bpduguard', $ports[107]['err']['message']);
        $this->assertSame(Capacity::FREE, $ports[108]['state'], 'up again after its err-disable: its last change is a day after the message');
        $this->assertSame(Capacity::EXCLUDED, $ports[109]['state'], 'the setting leaves Te ports out');
        $this->assertSame(Capacity::UPLINK, $ports[110]['state'], 'a member of a port-channel whose other member faces a switch');
        $this->assertSame(Capacity::UPLINK, $ports[111]['state']);
        $this->assertSame(Capacity::TRUNK, $ports[403]['state'], 'no access VLAN while the switch\'s other ports have one');
        $this->assertSame(Capacity::IN_USE, $ports[404]['state'], 'an access point on a trunk');
        $this->assertSame(Capacity::TRUNK, $ports[405]['state'], 'trunking, nothing LLDP/CDP names on it');
        $this->assertSame(20, $ports[102]['vlan']);
        $this->assertNull($ports[403]['vlan']);
        $this->assertSame(Capacity::FREE, $ports[205]['state'], 'an err-disable from before the restart no longer holds');
        $this->assertSame(Capacity::SAMPLED, $ports[205]['age_kind'], 'but it shows the port in use then');
        $this->assertSame(5, $ports[205]['age_days']);
        $this->assertFalse($ports[205]['unused']);
        $this->assertSame('Gi1/0/2', $ports[102]['port']);
        $this->assertSame('desk 2', $ports[102]['alias']);
    }

    public function testHowLongPortsHaveBeenDown(): void
    {
        $ports = self::ports($this->analyzer()->analyze());

        // exact: the last change and the uptime
        $this->assertSame(Capacity::EXACT, $ports[102]['age_kind']);
        $this->assertSame(60, $ports[102]['age_days']);
        $this->assertTrue($ports[102]['unused']);
        $this->assertSame(5, $ports[103]['age_days']);
        $this->assertFalse($ports[103]['unused'], 'down 5 days: recently used');
        // shut down at the start of a switch up 200 days: at least that long
        $this->assertSame(Capacity::AT_LEAST, $ports[104]['age_kind']);
        $this->assertSame(200, $ports[104]['age_days']);
        $this->assertTrue($ports[104]['unused']);
        // sw2 restarted 3 days ago: the record knows port 201 was last up 100 days ago
        $this->assertSame(Capacity::SAMPLED, $ports[201]['age_kind']);
        $this->assertSame(100, $ports[201]['age_days']);
        $this->assertTrue($ports[201]['unused']);
        // no change of port 202 in the 30 days of eventlog: down at least that long
        $this->assertSame(Capacity::AT_LEAST, $ports[202]['age_kind']);
        $this->assertSame(30, $ports[202]['age_days']);
        $this->assertTrue($ports[202]['unused']);
        // the eventlog saw port 203 up 10 days ago
        $this->assertSame(Capacity::SAMPLED, $ports[203]['age_kind']);
        $this->assertSame(10, $ports[203]['age_days']);
        $this->assertFalse($ports[203]['unused']);
        // sw4 is up 2 days and nothing reaches back further: unknown
        $this->assertSame(Capacity::AT_LEAST, $ports[401]['age_kind']);
        $this->assertSame(2, $ports[401]['age_days']);
        $this->assertNull($ports[401]['unused']);
        $this->assertNull($ports[101]['down_since'], 'a port in use has no down time');
    }

    public function testTheDaysCanBeChangedPerView(): void
    {
        $result = $this->analyzer(days: 90)->analyze();
        $ports = self::ports($result);

        $this->assertSame(90, $result['days']);
        $this->assertFalse($ports[102]['unused'], 'down 60 days');
        $this->assertTrue($ports[201]['unused'], 'down 100 days');
        $this->assertNull($ports[202]['unused'], 'at least 30 days: unknown for 90');
        $this->assertSame(30, $this->analyzer()->analyze()['days'], 'the setting');
        $this->assertSame(3650, $this->analyzer(days: 99999)->analyze()['days']);
    }

    public function testCountsOfSwitchesLocationsAndTotal(): void
    {
        $result = $this->analyzer()->analyze();
        $sw1 = self::devices($result)[1];

        $this->assertSame(6, $sw1['ports']);
        $this->assertSame(1, $sw1['in_use']);
        $this->assertSame(3, $sw1['free']);
        $this->assertSame(1, $sw1['shutdown']);
        $this->assertSame(1, $sw1['err_disabled']);
        $this->assertSame(4, $sw1['available']);
        $this->assertSame(3, $sw1['unused'], 'ports 102, 104 and 108');
        $this->assertSame(1, $sw1['recent']);
        $this->assertSame(0, $sw1['unknown_age']);
        $this->assertSame(3, $sw1['uplinks']);
        $this->assertSame(1, $sw1['not_present']);
        $this->assertSame(1, $sw1['excluded']);
        $this->assertSame(16.7, $sw1['use_percent']);
        $this->assertNull($sw1['poe'], 'no PoE data of sw1');
        $this->assertSame(self::NOW - 300 - 200 * self::DAY, $sw1['boot']);

        $sw3 = self::devices($result)[3];
        $this->assertSame(20, $sw3['ports']);
        $this->assertSame(15, $sw3['poe']);
        $this->assertSame(1, $sw3['poe_available']);

        $locations = array_column($result['locations'], null, 'name');
        $this->assertSame([3], $locations['Closet B']['devices']);
        $this->assertSame([1, 2], $locations['Closet A']['devices']);
        $this->assertSame(11, $locations['Closet A']['ports'], 'sw1 6 and sw2 5');
        $this->assertSame(['Closet B', 'Closet A', ''], array_column($result['locations'], 'name'), 'fullest first, no location last');
        $this->assertNull($result['locations'][2]['location_id']);
        $this->assertSame(['sw3', 'sw1', 'sw2', 'sw4'], array_column($result['devices'], 'name'), 'in the order of their locations');

        $summary = $result['summary'];
        $this->assertSame(6 + 5 + 20 + 3, $summary['ports']);
        $this->assertSame(2, $summary['trunks']);
        $this->assertSame(4, $summary['devices']);
        $this->assertSame(3, $summary['locations']);
        $this->assertSame(15, $summary['poe']);
    }

    public function testFindings(): void
    {
        $result = $this->analyzer()->analyze();
        $findings = array_column($result['findings'], null, 'code');

        $this->assertSame(['capacity_low', 'poe_low', 'ports_err_disabled'], array_column($result['findings'], 'code'));
        $this->assertSame('few free access ports in Closet B', $findings['capacity_low']['title']);
        $this->assertSame('warning', $findings['capacity_low']['severity']);
        $this->assertSame([3], $findings['capacity_low']['devices']);
        $this->assertSame([], $findings['capacity_low']['ports'], 'its identity is the location, not the ports that are free today');
        $this->assertStringContainsString('1 of 20 access ports available (1 free, 0 shut down), 1 of them unused for 30 days or more; 19 in use (95%)',
            $findings['capacity_low']['detail']);
        $this->assertSame('few free PoE ports in Closet B', $findings['poe_low']['title']);
        $this->assertStringContainsString('1 of 15 PoE access ports available', $findings['poe_low']['detail']);
        $this->assertSame('info', $findings['ports_err_disabled']['severity']);
        $this->assertSame('err-disabled ports on sw1', $findings['ports_err_disabled']['title']);
        $this->assertSame([107], $findings['ports_err_disabled']['ports']);
        $this->assertStringContainsString('Gi1/0/7 since', $findings['ports_err_disabled']['detail']);
        $this->assertSame('warning', $result['summary']['status']);
        $this->assertSame(['critical' => 0, 'warning' => 2, 'info' => 1], $result['summary']['findings']);
    }

    public function testFindingsCanBeSwitchedOff(): void
    {
        $this->assertSame(['ports_err_disabled'], array_column($this->analyzer(['capacity_disabled_checks' => 'capacity'])->analyze()['findings'], 'code'));
        $this->assertSame(['capacity_low'], array_column($this->analyzer(['capacity_disabled_checks' => 'poe_low ports'])->analyze()['findings'], 'code'));
        $this->assertSame(['ports_err_disabled'], array_column($this->analyzer(['capacity_low_percent' => '0'])->analyze()['findings'], 'code'));
        // a switch without a location is its own unit, from MIN_PORTS access ports: sw4 has 3 (1 available)
        $titles = array_column($this->analyzer(['capacity_low_percent' => '80'])->analyze()['findings'], 'title');
        $this->assertContains('few free access ports in Closet A', $titles, '8 of 11 available');
        $this->assertNotContains('few free access ports on sw4', $titles, 'too few access ports to tell');
    }

    public function testAnInvalidExcludePatternCountsEveryPort(): void
    {
        $result = (new \ReflectionClass(Capacity::class))->newInstanceArgs([[1 => ['device_id' => 1, 'name' => 'sw1', 'uptime' => 100, 'last_polled' => self::NOW]],
            [1 => ['port_id' => 1, 'device_id' => 1, 'ifName' => 'Te1/1/1', 'ifOperStatus' => 'down', 'ifAdminStatus' => 'up', 'ifLastChange' => 0]],
            [], [], [], [], [], [], [], new PluginSettings(['capacity_exclude_ports' => '(unclosed']), self::NOW])->analyze();

        $this->assertSame(Capacity::FREE, $result['ports'][0]['state']);
        $this->assertCount(1, $result['notices']);
    }

    public function testARecoveryEndsAnErrDisable(): void
    {
        $analyze = fn (array $errors) => self::ports((new Capacity(
            [1 => ['device_id' => 1, 'name' => 'sw1', 'uptime' => 100 * self::DAY, 'last_polled' => self::NOW]],
            [1 => ['port_id' => 1, 'device_id' => 1, 'ifName' => 'Gi1/0/1', 'ifOperStatus' => 'down', 'ifAdminStatus' => 'up', 'ifLastChange' => 0]],
            [], [], [1 => ['first_seen' => self::NOW - 50 * self::DAY, 'last_up' => self::NOW - 20 * self::DAY, 'err_disabled_at' => self::NOW - 20 * self::DAY,
                'err_disabled_message' => '%PM-4-ERR_DISABLE: bpduguard error detected on Gi1/0/1']],
            [], [], $errors, [], new PluginSettings, self::NOW))->analyze())[1];

        $this->assertSame(Capacity::ERR_DISABLED, $analyze([])['state'], 'recorded, and not up since');
        $this->assertSame(20, $analyze([])['age_days']);
        $this->assertSame(Capacity::FREE, $analyze([1 => ['time' => null, 'message' => null, 'recovered' => self::NOW - 19 * self::DAY]])['state'],
            'errdisable recovery re-enabled it, and nothing came up');
        $again = $analyze([1 => ['time' => self::NOW - 18 * self::DAY, 'message' => 'again', 'recovered' => self::NOW - 19 * self::DAY]]);
        $this->assertSame(Capacity::ERR_DISABLED, $again['state'], 'err-disabled again after the recovery');
        $this->assertSame('again', $again['err']['message']);
    }

    public function testListsOfPorts(): void
    {
        $rows = $this->analyzer()->analyze()['ports'];
        $ids = fn (string $list) => array_column(Capacity::filter($rows, $list), 'port_id');

        $this->assertSame([102, 103, 104, 107, 108], array_slice($ids('not_in_use'), 0, 5));
        $this->assertNotContains(101, $ids('not_in_use'));
        $this->assertSame([102, 104, 108, 201, 202, 303], $ids('unused'));
        $this->assertSame([303], $ids('poe'));
        $this->assertSame([107], $ids('err_disabled'));
        $this->assertSame([104], $ids('shutdown'));
        $this->assertSame([110, 111, 105, 106, 109, 403, 405], $ids('uplinks'), 'by name: Gi1/0/45, Gi1/0/46, Gi1/0/48, Gi1/1/1, Te1/1/1, then sw4');
        $this->assertCount(count($rows), Capacity::filter($rows, 'all'));
        $this->assertSame($ids('not_in_use'), $ids('nonsense'), 'an unknown list is the default');
        $this->assertSame(range(301, 320), array_values(array_filter(array_column($rows, 'port_id'), fn ($id) => $id > 300 && $id < 400)),
            'Gi1/0/2 before Gi1/0/10');
    }

    public function testCsvExports(): void
    {
        $result = $this->analyzer()->analyze();

        $ports = CapacityCsv::rows('ports', $result, 'err_disabled');
        $this->assertCount(2, $ports);
        $this->assertSame('switch', $ports[0][0]);
        $this->assertContains('unused 30+ days', $ports[0]);
        $this->assertSame(['sw1', 'Closet A', 'GigabitEthernet1/0/7'], array_slice($ports[1], 0, 3));
        $this->assertSame('err-disabled', $ports[1][6]);

        $switches = CapacityCsv::rows('switches', $result);
        $this->assertCount(5, $switches);
        $this->assertSame(['sw3', 'Closet B', 200, 20, 19, 95.0], array_slice($switches[1], 0, 6));
        $locations = CapacityCsv::rows('locations', $result);
        $this->assertSame('(no location)', $locations[3][0]);

        $this->assertSame("a,\"b,c\",'=1+1,-5,yes,\r\n", Csv::of([['a', 'b,c', '=1+1', -5, true, null]]));
    }

    public function testPercentText(): void
    {
        $this->assertSame('95', Capacity::percentText(95.0));
        $this->assertSame('16.7', Capacity::percentText(16.7));
        $this->assertSame('-', Capacity::percentText(null));
    }
}
