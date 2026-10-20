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
            401 => [4, 'Te1/1/1'], 403 => [4, 'Gi1/0/3'], 410 => [4, 'Gi1/0/10'],
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
            'devices' => array_map(fn ($device) => ['name' => $device[0], 'role' => $device[1]], $devices),
            'ports' => array_map(fn ($port) => ['device_id' => $port[0], 'ifName' => $port[1], 'ifDescr' => $port[1], 'ifAlias' => '', 'ifType' => $port[2] ?? 'ethernetCsmacd',
                'ifOperStatus' => 'up', 'ifPhysAddress' => $port[3] ?? null, 'ifVlan' => '', 'ifTrunk' => null], $ports),
            'neighbors' => $neighbors,
            'lag' => [102 => 101, 202 => 201],
            'fdb' => $fdb,
            'since' => $since,
            'counts' => [403 => 1, 305 => 1, 410 => 2],
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

    private static function service(?FakeHostRepository &$repo = null, array $settings = [], ?callable $resolver = null): HostService
    {
        $repo = new FakeHostRepository(self::data());

        return new HostService($repo, new FakeTopologyRepository(self::data()['lag']), new PluginSettings($settings), $resolver,
            fn (string $name, array $parameters = []) => $name . '?' . http_build_query($parameters));
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
        $resolver = function (string $name) use (&$asked): ?array {
            $asked[] = $name;

            $answers = ['pc-101' => ['10.10.10.55'], 'busy' => null];

            return array_key_exists($name, $answers) ? $answers[$name] : [];
        };

        $found = self::service($repo, [], $resolver)->search('pc-101');
        $this->assertSame(['10.10.10.55'], $found['resolved']);
        $this->assertSame([4, 403], [$found['hosts'][0]['edge']['device_id'], $found['hosts'][0]['edge']['port_id']]);

        $this->assertSame('pc-102 is not a device in LibreNMS and does not resolve in DNS.', self::service($repo, [], $resolver)->search('pc-102')['message']);
        $this->assertSame('Too many DNS lookups: try again in a minute.', self::service($repo, [], $resolver)->search('busy')['message']);
        $this->assertSame(['pc-101', 'pc-102', 'busy'], $asked);

        // a device without a stored address: its hostname
        $esx = self::service($repo, [], $resolver)->search('esx-1');
        $this->assertContains('esx-1.example.net', $asked);
        $this->assertCount(1, $esx['hosts'], 'its interface MAC is still located');
        $asked = array_values(array_diff($asked, ['esx-1.example.net']));

        $off = self::service($repo, ['hosts_dns' => '0'], $resolver)->search('pc-103');
        $this->assertSame('pc-103 is not a device in LibreNMS (DNS lookups are switched off in the plugin settings).', $off['message']);
        $this->assertSame(['pc-101', 'pc-102', 'busy'], $asked, 'no lookup when switched off');
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

    public function nacByMacs(array $macs, array $portIds): array
    {
        return [];
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
            $devices[$deviceId] = $device + ['url' => 'device/' . $deviceId, 'status' => true];
        }

        return $devices;
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
