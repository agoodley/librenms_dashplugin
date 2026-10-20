<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Data\HostRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Hosts\HostLocator;
use Agoodley\LibrenmsTopology\Hosts\HostQuery;
use Agoodley\LibrenmsTopology\Hosts\HostService;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

/**
 * HostService on an in-memory repository: which entries each answer uses (the VLAN of the ARP entry,
 * one answer per VLAN for a virtual router MAC), what is asked of the database (freshness of every
 * switch, MAC counts of trail ends only) and name searches with and without DNS.
 */
class HostServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const HSRP = '00000c07ac01';

    private const LAPTOP = '5c8a38000001';

    /**
     * core1 / core2 joined by Po1, acc-sw1 and acc-sw2 below core1, esx-1 on acc-sw2, and 60 more access
     * switches (acc-20 ... acc-79) whose uplinks toward core1 all learn the laptop (one large VLAN).
     */
    private static function data(): array
    {
        $devices = [
            1 => ['core1', 'l3switch'], 2 => ['core2', 'l3switch'], 3 => ['acc-sw1', 'switch'], 4 => ['acc-sw2', 'switch'], 13 => ['esx-1', 'server'],
        ];
        $ports = [
            101 => [1, 'Po1', 'ieee8023adLag'], 102 => [1, 'Te1/1/1'], 104 => [1, 'Te1/0/1'], 105 => [1, 'Te1/0/2'],
            110 => [1, 'Vl10', 'l3ipvlan', '00ab01000110'], 111 => [1, 'Vl20', 'l3ipvlan', '00ab01000110'], 112 => [1, 'Vl30', 'l3ipvlan', '00ab01000110'],
            201 => [2, 'Po1', 'ieee8023adLag'], 202 => [2, 'Te1/1/1'], 209 => [2, 'Vl20', 'l3ipvlan'], 210 => [2, 'Vl30', 'l3ipvlan'],
            301 => [3, 'Te1/1/1'], 305 => [3, 'Gi1/0/1'],
            401 => [4, 'Te1/1/1'], 403 => [4, 'Gi1/0/3'], 405 => [4, 'Gi1/0/5'], 410 => [4, 'Gi1/0/10'],
            1301 => [13, 'vmnic0', 'ethernetCsmacd', '00ab13001301'],
        ];
        $neighbors = [
            102 => [self::neighbor(2, 'core2', 'l3switch', 'Te1/1/1')], 202 => [self::neighbor(1, 'core1', 'l3switch', 'Te1/1/1')],
            104 => [self::neighbor(3, 'acc-sw1', 'switch')], 105 => [self::neighbor(4, 'acc-sw2', 'switch')],
            301 => [self::neighbor(1, 'core1', 'l3switch', 'Te1/0/1')], 401 => [self::neighbor(1, 'core1', 'l3switch', 'Te1/0/2')],
            410 => [self::neighbor(13, 'esx-1', 'server', 'vmnic0')],
        ];
        $fdb = [
            // HSRP group 1: core2 is active in VLAN 20, core1 in VLAN 30
            self::fdb(self::HSRP, 1, 101, 20), self::fdb(self::HSRP, 3, 301, 20),
            self::fdb(self::HSRP, 2, 201, 30), self::fdb(self::HSRP, 4, 401, 30),
            // core1's VLAN 10 interface
            self::fdb('00ab01000110', 3, 301, 10), self::fdb('00ab01000110', 4, 401, 10),
            // esx-1 vmnic0
            self::fdb('00ab13001301', 1, 105, 10), self::fdb('00ab13001301', 4, 410, 10),
            // the laptop moved 3 days ago from acc-sw1 Gi1/0/1 to the hot desk acc-sw2 Gi1/0/3
            self::fdb(self::LAPTOP, 3, 305, 10, self::NOW - 3 * 86400), self::fdb(self::LAPTOP, 1, 104, 10, self::NOW - 3 * 86400),
            self::fdb(self::LAPTOP, 1, 105, 10), self::fdb(self::LAPTOP, 4, 403, 10),
            self::fdb('d4bed9010203', 4, 403, 10),
            // a printer that was silent when acc-sw2's table was read: only core1 still lists it
            self::fdb('3c2af4000001', 1, 105, 10),
            // a PC with an 802.1X session on acc-sw2 Gi1/0/5, of which only core1 has an entry
            self::fdb('d4bed9004002', 1, 105, 10),
        ];
        $since = array_fill_keys([1, 2, 3, 4], self::NOW - HostLocator::STALE_AFTER);
        for ($id = 20; $id < 80; $id++) {
            $devices[$id] = ['acc-' . $id, 'switch'];
            $ports[2000 + $id] = [$id, 'Te1/1/1'];
            $neighbors[2000 + $id] = [self::neighbor(1, 'core1', 'l3switch', 'Te2/0/' . $id)];
            $fdb[] = self::fdb(self::LAPTOP, $id, 2000 + $id, 10);
            $since[$id] = self::NOW - HostLocator::STALE_AFTER;
        }

        return [
            'devices' => array_map(fn ($device) => ['name' => $device[0], 'role' => $device[1], 'last_polled' => self::NOW - 60], $devices),
            'ports' => array_map(fn ($port) => ['device_id' => $port[0], 'ifName' => $port[1], 'ifDescr' => $port[1], 'ifAlias' => '', 'ifType' => $port[2] ?? 'ethernetCsmacd',
                'ifOperStatus' => 'up', 'ifPhysAddress' => $port[3] ?? null, 'ifVlan' => '', 'ifTrunk' => null], $ports),
            'neighbors' => $neighbors,
            'lag' => [102 => 101, 202 => 201],
            'fdb' => $fdb,
            'since' => $since,
            'counts' => [403 => 1, 305 => 1, 405 => 1, 410 => 2],
            'nac' => [
                // connected after the last forwarding table read: a session only
                self::session(1, 'd4bed9004001', 4, 405, 'kchen'),
                self::session(2, 'd4bed9004002', 4, 405, 'pwalsh'),
                // the laptop's session at the old desk ended, the one at the hot desk is current
                self::session(3, self::LAPTOP, 3, 305, 'asmith', true), self::session(4, self::LAPTOP, 4, 403, 'asmith'),
            ],
            'addresses' => [
                self::address('10.10.20.1', self::HSRP, 1, 111), self::address('10.10.20.1', self::HSRP, 2, 209),
                self::address('10.10.30.1', self::HSRP, 1, 112), self::address('10.10.30.1', self::HSRP, 2, 210),
                self::address('10.0.0.1', '00ab01000110', 2, 209),
                self::address('10.10.10.55', 'd4bed9010203', 1, 110),
            ],
            'named' => [
                'core1' => [['device_id' => 1, 'name' => 'core1', 'hostname' => 'core1.example.net', 'role' => 'l3switch', 'ips' => ['10.0.0.1']]],
                'esx-1' => [['device_id' => 13, 'name' => 'esx-1', 'hostname' => 'esx-1.example.net', 'role' => 'server', 'ips' => []]],
            ],
        ];
    }

    private static function neighbor(int $deviceId, string $name, string $role, string $port = ''): array
    {
        return ['name' => $name, 'port' => $port, 'device_id' => $deviceId, 'role' => $role, 'platform' => '', 'description' => ''];
    }

    private static function fdb(string $mac, int $deviceId, int $portId, int $vlan, int $lastSeen = self::NOW): array
    {
        return ['mac' => $mac, 'device_id' => $deviceId, 'port_id' => $portId, 'vlan' => $vlan, 'vlan_name' => null, 'first_seen' => $lastSeen - 3600, 'last_seen' => $lastSeen];
    }

    private static function address(string $ip, string $mac, int $deviceId, int $portId): array
    {
        return ['mac' => $mac, 'ip' => $ip, 'family' => 4, 'device_id' => $deviceId, 'port_id' => $portId, 'context' => null];
    }

    private static function session(int $id, string $mac, int $deviceId, int $portId, string $user, bool $historical = false): array
    {
        return ['id' => $id, 'mac' => $mac, 'device_id' => $deviceId, 'port_id' => $portId, 'username' => $user, 'domain' => 'data', 'ip' => '',
            'status' => 'authorized', 'auth_status' => 'authcSuccess', 'method' => 'dot1x', 'host_mode' => 'multiDomain', 'vlan' => 10,
            'historical' => $historical, 'first_seen' => self::NOW - 3600, 'started' => self::NOW - 3600, 'last_seen' => $historical ? self::NOW - 86400 : self::NOW - 120];
    }

    /**
     * @param  (\Closure(array): array)|null  $change  changes to the test data
     */
    private static function service(?FakeHostRepository &$repo = null, array $settings = [], ?callable $resolver = null, ?\Closure $change = null,
        string $class = HostService::class): HostService
    {
        $data = $change ? $change(self::data()) : self::data();
        $repo = new FakeHostRepository($data);

        return new $class($repo, new FakeTopologyRepository($data['lag']), new PluginSettings($settings), $resolver,
            fn (string $name, array $parameters = []) => $name . '?' . http_build_query($parameters));
    }

    /**
     * Router interfaces that do not name the VLAN (or name another one), and a firewall with ARP entries.
     */
    private static function subinterfaces(array $addresses): \Closure
    {
        return function (array $data) use ($addresses): array {
            $data['devices'][50] = ['name' => 'fw1', 'role' => 'firewall'];
            foreach ([230 => [2, 'Gi0/0.20'], 231 => [2, 'Gi0/0.30'], 232 => [2, 'irb.100'], 5001 => [50, 'ethernet1/1.10'], 5002 => [50, 'ethernet1/1.20']] as $portId => [$deviceId, $name]) {
                $data['ports'][$portId] = ['device_id' => $deviceId, 'ifName' => $name, 'ifDescr' => $name, 'ifAlias' => '', 'ifType' => 'l2vlan',
                    'ifOperStatus' => 'up', 'ifPhysAddress' => null, 'ifVlan' => '', 'ifTrunk' => null];
            }
            $data['addresses'] = $addresses;

            return $data;
        };
    }

    public function testAnIpAddressUsesTheVlanOfItsArpEntry(): void
    {
        // HSRP group 1 has the same MAC in VLANs 20 and 30: mixing them sends every trail to the other core ("a loop")
        $result = self::service()->search('10.10.20.1');

        $this->assertCount(1, $result['hosts']);
        $host = $result['hosts'][0];
        $this->assertSame([20, 'gateway', 'core2'], [$host['vlan'], $host['status'], $host['device']['name']]);
        $this->assertSame(['10.10.20.1'], array_values(array_unique(array_column($host['addresses'], 'display'))), 'the addresses of that VLAN');
        $this->assertSame('HSRP group 1', $host['gateway']['label']);
        $this->assertSame('topology.l2?device=2&depth=1', $host['map_url'], 'the map shows core2 itself');

        $this->assertSame('core1', self::service()->search('10.10.30.1')['hosts'][0]['device']['name']);
    }

    public function testAVirtualRouterMacGetsOneAnswerPerVlan(): void
    {
        $result = self::service()->search('0000.0c07.ac01');

        $this->assertSame([[20, 'gateway', 'core2'], [30, 'gateway', 'core1']],
            array_map(fn ($host) => [$host['vlan'], $host['status'], $host['device']['name']], $result['hosts']));
    }

    public function testEverySwitchGetsItsFreshnessHoweverManyLearnedTheMac(): void
    {
        // 63 switches learned the laptop (formerly "over 50": no freshness, MACs counted over 10 days of kept entries)
        $result = self::service($repo)->search('5c:8a:38:00:00:01');

        $host = $result['hosts'][0];
        $this->assertSame(['located', 4, 403], [$host['status'], $host['edge']['device_id'], $host['edge']['port_id']], 'the hot desk, not the old desk');
        $this->assertCount(63, $repo->calls['fdbSince'][0]);
        $this->assertSame([[403]], $repo->calls['macCounts'], 'MACs are counted on the trail end only, not on 62 uplinks');
        $stale = array_values(array_filter($host['seen'], fn ($entry) => $entry['reason'] === 'stale'));
        $this->assertSame([[1, 104], [3, 305]], array_map(fn ($entry) => [$entry['device_id'], $entry['port_id']], $stale));
        $this->assertSame('topology.l2?device=4&depth=1&host=5c8a38000001', $host['map_url']);
    }

    public function testASilentHostIsBehindTheSwitchThatNoLongerListsIt(): void
    {
        $result = self::service($repo)->search('3c:2a:f4:00:00:01');

        $host = $result['hosts'][0];
        $this->assertSame(['behind', 'acc-sw2', 'not-listed'], [$host['status'], $host['device']['name'], $host['edge']['via']]);
        $this->assertContains(4, $repo->calls['fdbSince'][0], 'the freshness of the neighbor the trail ends at is known');
        $this->assertSame('topology.l2?device=4&depth=1&host=3c2af4000001', $host['map_url'], 'drawn next to acc-sw2');
    }

    public function testASwitchOrRouterByNameIsTheAnswerItself(): void
    {
        $result = self::service($repo)->search('core1');

        $this->assertSame(['owner', 'core1'], [$result['hosts'][0]['status'], $result['hosts'][0]['device']['name']]);
        $this->assertSame('topology.l2?device=1&depth=1', $result['hosts'][0]['map_url'], 'no host drawn: the map shows core1');
        $this->assertNull($result['hosts'][0]['edge'], 'not "located on acc-sw1 Te1/1/1"');
        $this->assertArrayNotHasKey('deviceMacs', $repo->calls, 'the interface MACs of a switch are learned on uplinks only');
        $this->assertStringContainsString('This is the MAC address of core1 Vl10, Vl20 and Vl30.', implode(' ', array_column($result['hosts'][0]['notes'], 'text')));
    }

    public function testAServerByNameIsWhereItsInterfacesArePlugged(): void
    {
        $result = self::service()->search('esx-1');

        $this->assertCount(1, $result['hosts']);
        $this->assertSame(['located', 4, 410], [$result['hosts'][0]['status'], $result['hosts'][0]['edge']['device_id'], $result['hosts'][0]['edge']['port_id']]);
    }

    public function testDnsLookups(): void
    {
        $asked = [];
        $resolver = function (string $name, float $timeout) use (&$asked): array|string {
            $asked[] = $name;

            return ['pc-101' => ['10.10.10.55'], 'busy' => 'Too many DNS lookups: try again in a minute.',
                'nodns' => 'DNS lookups are unavailable: getent was not found on the LibreNMS server.'][$name] ?? [];
        };

        $found = self::service($repo, [], $resolver)->search('pc-101');
        $this->assertSame(['10.10.10.55'], $found['resolved']);
        $this->assertSame([4, 403], [$found['hosts'][0]['edge']['device_id'], $found['hosts'][0]['edge']['port_id']]);

        $this->assertSame('pc-102 is not a device in LibreNMS and does not resolve in DNS.', self::service($repo, [], $resolver)->search('pc-102')['message']);
        $this->assertSame('busy is not a device in LibreNMS. Too many DNS lookups: try again in a minute.', self::service($repo, [], $resolver)->search('busy')['message']);
        $this->assertSame('nodns is not a device in LibreNMS. DNS lookups are unavailable: getent was not found on the LibreNMS server.',
            self::service($repo, [], $resolver)->search('nodns')['message'], 'not "does not resolve"');
        $this->assertSame(['pc-101', 'pc-102', 'busy', 'nodns'], $asked);

        // a device without a stored address: its hostname
        $esx = self::service($repo, [], $resolver)->search('esx-1');
        $this->assertContains('esx-1.example.net', $asked);
        $this->assertCount(1, $esx['hosts'], 'its interface MAC is still located');
        $asked = array_values(array_diff($asked, ['esx-1.example.net']));

        $off = self::service($repo, ['hosts_dns' => '0'], $resolver)->search('pc-103');
        $this->assertSame('pc-103 is not a device in LibreNMS. DNS lookups are switched off in the plugin settings.', $off['message']);
        $this->assertSame(['pc-101', 'pc-102', 'busy', 'nodns'], $asked, 'no lookup when switched off');
    }

    public function testAllDnsLookupsOfASearchShareOneTimeBudget(): void
    {
        // a name matching many devices without a stored address: their hostnames are looked up one after another
        $calls = [];
        $resolver = function (string $name, float $timeout) use (&$calls): array {
            $calls[] = $timeout;
            usleep(120_000);

            return [];
        };
        $named = function (array $data): array {
            $data['named']['lab'] = array_map(fn ($i) => ['device_id' => 100 + $i, 'name' => 'lab-' . $i, 'hostname' => 'lab-' . $i . '.example.net',
                'role' => 'server', 'ips' => []], range(1, 20));

            return $data;
        };
        $started = microtime(true);
        self::service($repo, [], $resolver, $named, ShortBudgetHostService::class)->search('lab');

        $this->assertLessThan(0.6, microtime(true) - $started, 'the budget bounds the search: ' . json_encode($calls));
        $this->assertLessThanOrEqual(3, count($calls));
        foreach ($calls as $timeout) {
            $this->assertLessThanOrEqual(ShortBudgetHostService::BUDGET, $timeout, 'each lookup gets at most the time that is left');
        }
    }

    public function testAHostWithOnlyAnAuthenticationSessionIsLocatedByIt(): void
    {
        // connected after the last forwarding table read: no entry anywhere, an 802.1X session on acc-sw2 Gi1/0/5
        $result = self::service($repo)->search('d4:be:d9:00:40:01');

        $host = $result['hosts'][0];
        $this->assertSame(['located', 4, 405, 'nac'], [$host['status'], $host['edge']['device_id'], $host['edge']['port_id'], $host['edge']['via']]);
        $this->assertSame(['kchen'], array_column($host['nac'], 'username'));
        $this->assertStringContainsString('Located by its current 802.1X / MAB session on acc-sw2 Gi1/0/5', implode(' ', array_column($host['notes'], 'text')));
        $this->assertSame([[['d4bed9004001'], []], [['d4bed9004001'], null]], $repo->calls['nacByMacs'], 'by port first, then by MAC');
        $this->assertSame('topology.l2?device=4&depth=1&host=d4bed9004001', $host['map_url']);
    }

    public function testAnAuthenticationSessionLocatesAHostTheTrailOnlyLeadsTowards(): void
    {
        // core1 learned it toward acc-sw2, whose forwarding table does not list it: the session says the port
        $host = self::service()->search('d4:be:d9:00:40:02')['hosts'][0];

        $this->assertSame(['located', 4, 405, 'nac'], [$host['status'], $host['edge']['device_id'], $host['edge']['port_id'], $host['edge']['via']]);
        $this->assertNull($host['device']);
        $this->assertSame(['behind-monitored', 'toward acc-sw2, whose forwarding table does not list it'], [$host['seen'][0]['reason'], $host['seen'][0]['detail']]);
        $this->assertSame(['pwalsh'], array_column($host['nac'], 'username'));
    }

    public function testACurrentSessionOnTheLearnedPortNeedsNoSecondLookup(): void
    {
        $host = self::service($repo)->search('5c:8a:38:00:00:01')['hosts'][0];

        $this->assertSame([4, 403], [$host['edge']['device_id'], $host['edge']['port_id']]);
        $this->assertSame([[false, 403], [true, 305]], array_map(fn ($session) => [$session['historical'], $session['port_id']], $host['nac']), 'current first');
        $this->assertCount(1, $repo->calls['nacByMacs']);
    }

    public function testASessionNotUpdatedAtItsSwitchsLastPollsIsNoLocation(): void
    {
        // acc-sw2 is polled (a minute ago) but its NAC polling stopped two days ago: the session was never marked historical
        $stopped = function (array $data): array {
            $data['nac'][0]['last_seen'] = self::NOW - 2 * 86400;

            return $data;
        };
        $host = self::service(change: $stopped)->search('d4:be:d9:00:40:01')['hosts'][0];

        $this->assertSame('none', $host['status']);
        $this->assertSame([false], array_column($host['nac'], 'current'));
    }

    public function testAStoppedSessionOnTheLearnedPortDoesNotHideACurrentOneElsewhere(): void
    {
        // the forwarding tables saw the PC on acc-sw1 Gi1/0/1 3 hours ago, where the session stays (acc-sw1's NAC polling
        // stopped two days ago); 10 minutes ago it authenticated on acc-sw2 Gi1/0/5
        $moved = function (array $data): array {
            $data['since'][1] = $data['since'][3] = self::NOW - 4 * 3600;
            $data['fdb'][] = self::fdb('d4bed9004003', 3, 305, 10, self::NOW - 3 * 3600);
            $data['fdb'][] = self::fdb('d4bed9004003', 1, 104, 10, self::NOW - 3 * 3600);
            $data['nac'][] = ['started' => self::NOW - 3 * 86400, 'last_seen' => self::NOW - 2 * 86400] + self::session(5, 'd4bed9004003', 3, 305, 'lmoreau');
            $data['nac'][] = ['started' => self::NOW - 600] + self::session(6, 'd4bed9004003', 4, 405, 'lmoreau');

            return $data;
        };
        $host = self::service($repo, change: $moved)->search('d4:be:d9:00:40:03')['hosts'][0];

        $this->assertSame([[['d4bed9004003'], [305, 104]], [['d4bed9004003'], null]], $repo->calls['nacByMacs'], 'the session on the learned port is not current');
        $this->assertSame(['located', 4, 405, 'nac'], [$host['status'], $host['edge']['device_id'], $host['edge']['port_id'], $host['edge']['via']]);
        $this->assertStringContainsString('which started after the forwarding tables last saw the host on acc-sw1 Gi1/0/1: the host moved since.', implode(' ', array_column($host['notes'], 'text')));
    }

    public function testASessionOnASwitchBehindTheOneTheTrailEndsAt(): void
    {
        // the trail ends at acc-sw2, the session is on acc-sw9, which hangs off acc-sw2
        $chain = function (array $data): array {
            $data['devices'][9] = ['name' => 'acc-sw9', 'role' => 'switch', 'last_polled' => self::NOW - 60];
            $data['ports'][901] = $data['ports'][405] + ['port_id' => 901];
            $data['ports'][901]['device_id'] = 9;
            $data['links'] = [4 => [1, 9], 9 => [4], 1 => [2, 3, 4]];
            $data['nac'][1]['device_id'] = 9;
            $data['nac'][1]['port_id'] = 901;

            return $data;
        };
        $host = self::service($repo, change: $chain)->search('d4:be:d9:00:40:02')['hosts'][0];

        $this->assertSame(['located', 9, 901], [$host['status'], $host['edge']['device_id'], $host['edge']['port_id']]);
        $this->assertSame([[4], [9]], $repo->calls['linkedDevices'], 'from acc-sw2 (core1 is the trail) to acc-sw9, whose links end there');

        // dual-homed acc-sw2: core2 did not learn the MAC, but it links to core1 (the trail): it is in front, so is what hangs off it
        $dual = function (array $data) use ($chain): array {
            $data = $chain($data);
            $data['links'] = [4 => [1, 2, 9], 9 => [4], 2 => [1, 4, 20], 20 => [2], 1 => [2, 3, 4]];
            $data['devices'][20] = ['name' => 'acc-20', 'role' => 'switch', 'last_polled' => self::NOW - 60];
            $data['nac'][1]['device_id'] = 20;
            $data['nac'][1]['port_id'] = 2020;

            return $data;
        };
        $this->assertSame('behind', self::service(change: $dual)->search('d4:be:d9:00:40:02')['hosts'][0]['status'], 'a session behind the other core is not behind acc-sw2');
    }

    public function testOnlyLayer3EvidenceMakesADeviceWithoutForwardingTableTheGateway(): void
    {
        // core1 learned two virtual router MACs, toward rtr1 and toward dist-sw1 (an EX switch LibreNMS classifies as a
        // router by its OS); neither has forwarding table data
        $wan = function (array $data): array {
            $data['devices'][90] = ['name' => 'rtr1', 'role' => 'router', 'last_polled' => self::NOW - 60];
            $data['devices'][91] = ['name' => 'dist-sw1', 'role' => 'router', 'last_polled' => self::NOW - 60];
            foreach ([106 => [1, 'Te1/0/3'], 107 => [1, 'Te1/0/4'], 113 => [1, 'Vl40']] as $portId => [$deviceId, $name]) {
                $data['ports'][$portId] = ['device_id' => $deviceId, 'ifName' => $name, 'ifDescr' => $name, 'ifAlias' => '', 'ifType' => 'ethernetCsmacd',
                    'ifOperStatus' => 'up', 'ifPhysAddress' => null, 'ifVlan' => '', 'ifTrunk' => null];
            }
            $data['neighbors'][106] = [self::neighbor(90, 'rtr1', 'router', 'Gi0/1')];
            $data['neighbors'][107] = [self::neighbor(91, 'dist-sw1', 'router', 'xe-0/1/0')];
            $data['fdb'][] = self::fdb('00005e000114', 1, 106, 20);
            $data['fdb'][] = self::fdb('00005e000115', 1, 107, 40);
            $data['addresses'][] = self::address('10.10.20.5', '00005e000114', 1, 111);
            $data['addresses'][] = self::address('10.10.40.1', '00005e000115', 1, 113);
            $data['layer3'] = [90 => [['port' => 'Gi0/2', 'ip' => '10.10.20.2', 'prefix' => 24]], 91 => [['port' => 'vlan.99', 'ip' => '192.168.99.5', 'prefix' => 24]]];

            return $data;
        };
        $this->assertSame(['gateway', 'rtr1'], self::answer(self::service(change: $wan)->search('10.10.20.5')), 'an address in the subnet of the virtual address');
        $this->assertSame(['behind', 'dist-sw1'], self::answer(self::service(change: $wan)->search('10.10.40.1')), 'a switch with only its management address');

        $irb = function (array $data) use ($wan): array {
            $data = $wan($data);
            $data['layer3'][91][] = ['port' => 'irb.40', 'ip' => 'fd00:40::1', 'prefix' => 64];

            return $data;
        };
        $this->assertSame(['gateway', 'dist-sw1'], self::answer(self::service(change: $irb)->search('10.10.40.1')), 'a router interface in the VLAN');
    }

    private static function answer(array $result): array
    {
        return [$result['hosts'][0]['status'], $result['hosts'][0]['device']['name'] ?? null];
    }

    public function testRouterInterfacesThatDoNotNameTheVlan(): void
    {
        // the gateway's ARP entries are on subinterfaces: their unit is the VLAN
        $result = self::service(change: self::subinterfaces([self::address('10.10.20.1', self::HSRP, 2, 230), self::address('10.10.30.1', self::HSRP, 2, 231)]))
            ->search('10.10.20.1');
        $this->assertSame([[20, 'core2', ['10.10.20.1']]], array_map(fn ($host) => [$host['vlan'], $host['device']['name'], array_column($host['addresses'], 'display')], $result['hosts']));

        // a Juniper irb unit that is not the VLAN id: no VLAN matches, so one answer per VLAN with the searched address only
        $result = self::service(change: self::subinterfaces([self::address('10.10.20.1', self::HSRP, 2, 232), self::address('10.10.30.1', self::HSRP, 1, 112)]))
            ->search('10.10.20.1');
        $this->assertSame([[20, 'gateway', 'core2', ['10.10.20.1']], [30, 'gateway', 'core1', ['10.10.20.1']]],
            array_map(fn ($host) => [$host['vlan'], $host['status'], $host['device']['name'], array_column($host['addresses'], 'display')], $result['hosts']));

        // a host in the ARP tables of core1 Vl10 and of fw1 ethernet1/1.10, a gateway in those of core2 Vl20 and fw1 ethernet1/1.20: one answer each
        $both = self::subinterfaces([self::address('10.10.10.55', 'd4bed9010203', 1, 110), self::address('10.10.10.55', 'd4bed9010203', 50, 5001),
            self::address('10.10.20.1', self::HSRP, 2, 209), self::address('10.10.20.1', self::HSRP, 50, 5002)]);
        $this->assertCount(1, self::service(change: $both)->search('10.10.10.55')['hosts']);
        $this->assertSame([[20, 'core2']], array_map(fn ($host) => [$host['vlan'], $host['device']['name']], self::service(change: $both)->search('10.10.20.1')['hosts']));
    }

    public function testMessages(): void
    {
        $repo = new FakeHostRepository(['named' => ['acc-sw1' => [['device_id' => 3, 'name' => 'acc-sw1', 'hostname' => 'acc-sw1.example.net', 'role' => 'switch', 'ips' => []]]]] + self::data());
        $switch = (new HostService($repo, new FakeTopologyRepository([]), new PluginSettings([]), null, fn () => ''))->search('acc-sw1');
        $this->assertSame('acc-sw1 is a switch, router or firewall monitored by LibreNMS: its device page and the L2 map show its links.', $switch['message']);

        $this->assertSame('Enter an IPv4 or IPv6 address, a MAC address or a hostname.', self::service()->search('rm -rf /')['message']);
        $this->assertStringStartsWith('No ARP or IPv6 neighbor entry for 192.0.2.99 on the devices you can see.', self::service()->search('192.0.2.99')['message']);
        $none = self::service()->search('00:11:22:33:44:55');
        $this->assertSame(['none', null], [$none['hosts'][0]['status'], $none['message']]);
    }

    public function testPortTabOfAPortChannelMemberShowsThePortChannelEntries(): void
    {
        $tab = self::service($repo)->portTab(1, 102);

        $this->assertSame('Po1', $tab['fdb_port']['label']);
        $this->assertSame([self::HSRP], array_values(array_unique(array_column($tab['macs'], 'mac'))));
        $this->assertSame(['core2'], array_column($tab['neighbors'], 'name'));
        $this->assertSame([false], array_values(array_unique(array_column($tab['macs'], 'stale'))));
    }
}

/**
 * HostRepository answering from arrays, recording what it was asked.
 */
final class FakeHostRepository extends HostRepository
{
    /** @var array<string, array<int, mixed>> method => arguments of each call */
    public array $calls = [];

    public function __construct(private readonly array $data)
    {
        parent::__construct(null);
    }

    public function fdbByMacs(array $macs): array
    {
        return array_values(array_filter($this->data['fdb'], fn ($row) => in_array($row['mac'], $macs, true)));
    }

    public function fdbByPort(int $portId, int $limit): array
    {
        return array_slice(array_values(array_filter($this->data['fdb'], fn ($row) => $row['port_id'] === $portId)), 0, $limit);
    }

    public function fdbSince(array $deviceIds, array $rows = []): array
    {
        $this->calls['fdbSince'][] = array_values(array_unique($deviceIds));

        return array_intersect_key($this->data['since'], array_flip($deviceIds));
    }

    public function macCounts(array $portIds, array $since): array
    {
        $this->calls['macCounts'][] = array_values($portIds);

        return array_intersect_key($this->data['counts'], array_flip($portIds));
    }

    public function switchMacsOn(array $portIds, array $deviceIds, array $since): array
    {
        return [];
    }

    public function addressesByIps(array $ips): array
    {
        $ips = array_map(fn ($ip) => str_contains($ip, ':') ? HostQuery::ipv6Full($ip) : $ip, $ips);

        return array_values(array_filter($this->data['addresses'], fn ($row) => in_array($row['ip'], $ips, true)));
    }

    public function addressesByMacs(array $macs, bool $ipv6 = true): array
    {
        return array_values(array_filter($this->data['addresses'], fn ($row) => in_array($row['mac'], $macs, true) && ($ipv6 || $row['family'] === 4)));
    }

    public function nacByMacs(array $macs, ?array $portIds = null): array
    {
        $this->calls['nacByMacs'][] = [array_values($macs), $portIds];

        return array_values(array_filter($this->data['nac'] ?? [], fn ($row) => in_array($row['mac'], $macs, true)
            && ($portIds === null || in_array($row['port_id'], $portIds, true))));
    }

    public function nacByPort(int $portId): array
    {
        return [];
    }

    public function vendors(array $macs): array
    {
        return [];
    }

    public function owners(array $macs, array $deviceIds): array
    {
        $owners = [];
        foreach ($this->data['ports'] as $portId => $port) {
            if (in_array($port['device_id'], $deviceIds, true) && in_array($port['ifPhysAddress'], $macs, true)) {
                $owners[$port['ifPhysAddress']][] = ['device_id' => $port['device_id'], 'port_id' => $portId, 'port' => $port['ifName']];
            }
        }

        return $owners;
    }

    public function deviceMacs(array $deviceIds, int $limit = 200): array
    {
        $this->calls['deviceMacs'][] = $deviceIds;

        return array_values(array_unique(array_filter(array_map(fn ($port) => in_array($port['device_id'], $deviceIds, true) ? $port['ifPhysAddress'] : null, $this->data['ports']))));
    }

    public function neighbors(array $portIds): array
    {
        return array_intersect_key($this->data['neighbors'], array_flip($portIds));
    }

    public function linkedDevices(array $deviceIds): array
    {
        $this->calls['linkedDevices'][] = array_values($deviceIds);

        return array_intersect_key($this->data['links'] ?? [], array_flip($deviceIds));
    }

    public function layer3Addresses(array $deviceIds): array
    {
        return array_intersect_key($this->data['layer3'] ?? [], array_flip($deviceIds));
    }

    public function devicesByName(string $name): array
    {
        return $this->data['named'][$name] ?? [];
    }

    public function portVlans(int $deviceId, int $portId): array
    {
        return [];
    }

    public function stpRows(int $deviceId, array $portIds): array
    {
        return [[], []];
    }

    public function ports(array $portIds): array
    {
        $ports = [];
        foreach (array_intersect_key($this->data['ports'], array_flip($portIds)) as $portId => $port) {
            $ports[$portId] = $port + ['port_id' => $portId, 'url' => 'port/' . $portId];
        }

        return $ports;
    }

    public function deviceRows(array $deviceIds): array
    {
        $devices = [];
        foreach (array_intersect_key($this->data['devices'], array_flip($deviceIds)) as $deviceId => $device) {
            $devices[$deviceId] = $device + ['url' => 'device/' . $deviceId, 'status' => true, 'last_polled' => null];
        }

        return $devices;
    }
}

final class ShortBudgetHostService extends HostService
{
    public const BUDGET = 0.3;

    protected function dnsBudget(): float
    {
        return self::BUDGET;
    }
}

final class FakeTopologyRepository extends TopologyRepository
{
    public function __construct(private readonly array $lag)
    {
        parent::__construct(null);
    }

    public function lagParents(array $ids): array
    {
        return $this->lag;
    }
}
