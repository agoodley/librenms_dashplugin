<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Data\HostRepository;
use Agoodley\LibrenmsTopology\Hosts\DnsResolver;
use Agoodley\LibrenmsTopology\Hosts\HostLocator;
use Agoodley\LibrenmsTopology\Hosts\HostQuery;
use Agoodley\LibrenmsTopology\Hosts\HostService;
use Agoodley\LibrenmsTopology\Hosts\Mac;
use PHPUnit\Framework\TestCase;

/**
 * The host locator on a campus like the e2e test data: core1/core2, access switches, access points,
 * an IP phone, a VM host, a server on a switch pair and an unmanaged desk switch. Unmanaged neighbors
 * are built from links rows as LibreNMS stores them: LLDP without platform (the system description
 * in remote_version), CDP with one.
 */
class HostLocatorTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const DEVICES = [
        1 => ['name' => 'core1', 'role' => 'l3switch'], 2 => ['name' => 'core2', 'role' => 'l3switch'], 3 => ['name' => 'acc-sw1', 'role' => 'switch'],
        4 => ['name' => 'acc-sw2', 'role' => 'switch'], 5 => ['name' => 'acc-sw3', 'role' => 'switch'], 6 => ['name' => 'acc-sw4', 'role' => 'switch'],
        7 => ['name' => 'acc-sw5', 'role' => 'switch'], 8 => ['name' => 'acc-sw6', 'role' => 'switch'], 10 => ['name' => 'acc-sw7', 'role' => 'switch'],
        11 => ['name' => 'acc-sw8', 'role' => 'switch'], 12 => ['name' => 'acc-sw9', 'role' => 'switch'], 13 => ['name' => 'esx-1', 'role' => 'server'],
        14 => ['name' => 'rtr1', 'role' => 'router'], 15 => ['name' => 'rtr2', 'role' => 'router'], 20 => ['name' => 'dist-sw1', 'role' => 'router'],
        30 => ['name' => 'fw-transparent', 'role' => 'firewall'], 100 => ['name' => 'lab-sw1', 'role' => 'switch'],
    ];

    /** Port-channel members => port-channel. */
    private const LAGS = [102 => 101, 103 => 101, 105 => 104, 106 => 104, 202 => 201, 203 => 201, 321 => 320, 421 => 420];

    /**
     * port_id => device, name, MACs learned on it, aggregate
     */
    private static function ports(array $macs = []): array
    {
        $ports = [];
        foreach ([
            101 => [1, 'Po1', 4000, true], 102 => [1, 'Te1/1/1', 0], 103 => [1, 'Te1/1/2', 0],
            104 => [1, 'Po11', 40, true], 105 => [1, 'Te1/0/1', 0], 106 => [1, 'Te1/0/2', 0], 107 => [1, 'Te1/0/3', 30], 108 => [1, 'Te1/0/4', 20],
            110 => [1, 'Vl10', 0], 120 => [1, 'Te1/0/6', 5],
            201 => [2, 'Po1', 4000, true], 202 => [2, 'Te1/1/1', 0], 203 => [2, 'Te1/1/2', 0],
            204 => [2, 'Te1/0/1', 40], 205 => [2, 'Te1/0/3', 30], 220 => [2, 'Te1/0/6', 5], 221 => [2, 'Te1/0/7', 8], 222 => [2, 'Te1/0/8', 2],
            302 => [3, 'Te1/1/1', 300], 305 => [3, 'Gi1/0/1', 1], 307 => [3, 'Gi1/0/10', 25], 320 => [3, 'Po10', 3, true], 321 => [3, 'Gi1/0/20', 0],
            404 => [4, 'Gi1/0/48', 12], 405 => [4, 'Gi1/0/5', 2], 406 => [4, 'Gi1/0/47', 300], 412 => [4, 'Gi1/0/12', 30], 420 => [4, 'Po10', 3, true], 421 => [4, 'Gi1/0/20', 0],
            503 => [5, 'Gi1/0/1', 1], 505 => [5, 'Gi1/0/24', 6], 506 => [5, 'Gi1/0/48', 3],
            603 => [6, 'ge-0/0/1', 7], 701 => [7, 'Gi1/1/1', 200], 703 => [7, 'Gi2/0/1', 2], 803 => [8, 'Gi1/0/2', 12], 1005 => [10, 'Gi1/0/5', 1],
            1201 => [12, 'Gi1/0/1', 1], 223 => [2, 'Te1/0/9', 12], 224 => [2, 'Te1/0/10', 30], 225 => [2, 'Te1/0/11', 2],
            10011 => [100, 'Gi1/0/5', 1], 10012 => [100, 'Gi1/0/6', 29],
        ] as $portId => $port) {
            $ports[$portId] = ['device_id' => $port[0], 'name' => $port[1], 'macs' => $macs[$portId] ?? $port[2], 'aggregate' => $port[3] ?? false];
        }

        return $ports;
    }

    private static function monitored(int $deviceId, string $port = ''): array
    {
        return ['name' => self::DEVICES[$deviceId]['name'], 'port' => $port, 'device_id' => $deviceId, 'role' => self::DEVICES[$deviceId]['role']];
    }

    /**
     * An unmanaged LLDP neighbor: no platform, the system description in remote_version.
     */
    private static function lldp(string $name, string $description, string $port = ''): array
    {
        return HostRepository::unmanagedNeighbor(['remote_hostname' => $name, 'remote_port' => $port, 'remote_platform' => null, 'remote_version' => $description]);
    }

    /**
     * An unmanaged CDP neighbor with its platform.
     */
    private static function cdp(string $name, string $platform, string $port = ''): array
    {
        return HostRepository::unmanagedNeighbor(['remote_hostname' => $name, 'remote_port' => $port, 'remote_platform' => $platform, 'remote_version' => '']);
    }

    private static function neighbors(array $extra = []): array
    {
        $server = 'Linux srv-db-1 6.1.0-18-amd64 #1 SMP PREEMPT_DYNAMIC Debian 6.1.76-1 (2024-02-01) x86_64';

        return $extra + [
            102 => [self::monitored(2, 'Te1/1/1')], 103 => [self::monitored(2, 'Te1/1/2')],
            105 => [self::monitored(3, 'Te1/1/1')], 106 => [self::monitored(3, 'Te1/1/2')],
            107 => [self::monitored(4)], 108 => [self::monitored(5)], 120 => [self::monitored(7)],
            202 => [self::monitored(1, 'Te1/1/1')], 203 => [self::monitored(1, 'Te1/1/2')],
            204 => [self::monitored(3)], 205 => [self::monitored(4)], 220 => [self::monitored(7)], 221 => [self::monitored(10)], 222 => [self::monitored(14)],
            223 => [self::monitored(20, 'xe-0/1/0')], 224 => [self::monitored(30, 'ethernet1/1')], 225 => [self::monitored(15)],
            302 => [self::monitored(1, 'Te1/0/1')],
            307 => [self::lldp('AP-2F-07', 'Cisco AP Software, ap3g2-k9w8 Version: 8.10.185.0', 'eth0')],
            321 => [self::lldp('srv-db-1.example.net', $server, 'eno1')],
            404 => [self::monitored(8)],
            412 => [self::lldp('AP-3F-02', "Cisco AP Software, ap3g3-k9w8 Version: 17.9.4.27\nTechnical Support: http://www.cisco.com/techsupport", 'Gi0')],
            421 => [self::lldp('srv-db-1.example.net', $server, 'eno2')],
            505 => [self::lldp('desk-switch', '', 'port 1')],
            506 => [self::monitored(11)],
            701 => [self::monitored(1, 'Te1/0/6')],
            703 => [self::cdp('SEP00AB11CD22EF', 'Cisco IP Phone 8841', 'Port 1')],
            803 => [self::monitored(13, 'vmnic0')],
        ];
    }

    /**
     * @param  array<int, int>  $since  device_id => start of its last forwarding table read (default: 15 minutes before NOW)
     * @param  array<string, \Closure>  $lookups  see HostLocator
     */
    private static function locator(array $since = [], array $extraNeighbors = [], array $switchMacs = [], array $lookups = [], array $macs = []): HostLocator
    {
        $since += array_fill_keys([1, 2, 3, 4, 5, 6, 7, 8, 11, 100], self::NOW - HostLocator::STALE_AFTER);

        return new HostLocator(self::ports($macs), self::neighbors($extraNeighbors), self::LAGS, $since, self::DEVICES, 5, $switchMacs, $lookups);
    }

    private static function row(int $deviceId, int $portId, ?int $vlan = 10, int $lastSeen = self::NOW): array
    {
        return ['device_id' => $deviceId, 'port_id' => $portId, 'vlan' => $vlan, 'first_seen' => $lastSeen - 3600, 'last_seen' => $lastSeen];
    }

    private static function texts(array $result): string
    {
        return implode(' | ', array_column($result['notes'], 'text'));
    }

    private static function reasons(array $result): array
    {
        return array_column($result['seen'], 'reason');
    }

    public function testComputerBehindAnIpPhone(): void
    {
        $result = self::locator()->locate('d4bed9010203', [self::row(1, 120), self::row(2, 220), self::row(7, 703)]);

        $this->assertSame('located', $result['status']);
        $this->assertSame([7, 703, 'end-device'], [$result['edge']['device_id'], $result['edge']['port_id'], $result['edge']['via']]);
        $this->assertStringContainsString("Behind IP phone SEP00AB11CD22EF: a computer on the phone's PC port", self::texts($result));
        $this->assertSame(['toward-switch', 'toward-switch'], self::reasons($result));
        $this->assertSame('link toward acc-sw5, which also learned it', $result['seen'][0]['detail']);
        $this->assertNull($result['device']);
    }

    public function testTheIpPhoneItself(): void
    {
        $result = self::locator()->locate('00ab11cd22ef', [self::row(7, 703)]);

        $this->assertStringContainsString('This is the IP phone SEP00AB11CD22EF itself.', self::texts($result));
    }

    public function testWirelessClientBehindAnLldpAccessPointThroughPortChannels(): void
    {
        $result = self::locator()->locate('3c22fb000001', [self::row(1, 104), self::row(2, 204), self::row(3, 307)]);

        $this->assertSame([3, 307], [$result['edge']['device_id'], $result['edge']['port_id']]);
        $this->assertSame(25, $result['edge']['macs']);
        $this->assertStringContainsString('Behind access point AP-2F-07: a wireless client', self::texts($result), 'LLDP: the role comes from the system description');
        $this->assertContains('port-channel toward acc-sw1, which also learned it', array_column($result['seen'], 'detail'));
        $this->assertStringNotContainsString('unmanaged switch, hub', self::texts($result), 'an access point port is not a shared port');
    }

    public function testVirtualMachineBehindAMonitoredServer(): void
    {
        $result = self::locator()->locate('005056000001', [self::row(1, 107), self::row(2, 205), self::row(4, 404), self::row(8, 803)]);

        $this->assertSame([8, 803, 'end-device'], [$result['edge']['device_id'], $result['edge']['port_id'], $result['edge']['via']]);
        $this->assertStringContainsString('Behind server esx-1: a virtual machine', self::texts($result));
        $this->assertCount(3, $result['seen']);
    }

    public function testTheServerItselfByItsInterfaceMac(): void
    {
        $result = self::locator()->locate('00ab13001301', [self::row(4, 404), self::row(8, 803)], [['device_id' => 13, 'port' => 'vmnic0']]);

        $this->assertSame('located', $result['status'], 'a server is where it is plugged in');
        $this->assertSame([803, 'owner'], [$result['edge']['port_id'], $result['edge']['via']]);
        $this->assertStringContainsString('This is the MAC address of esx-1 vmnic0.', self::texts($result));
    }

    public function testRouterInterfaceIsTheRouterItself(): void
    {
        // core1 keeps its forwarding table but does not list its own interface MAC
        $result = self::locator()->locate('00ab01000110', [self::row(3, 302)], [['device_id' => 1, 'port' => 'Vl10']]);

        $this->assertSame('owner', $result['status']);
        $this->assertSame(['device_id' => 1, 'name' => 'core1'], $result['device']);
        $this->assertNull($result['edge'], 'no host to draw: the answer is core1');
        $this->assertSame(['toward-owner', 'link toward core1, the device with this address'], [$result['seen'][0]['reason'], $result['seen'][0]['detail']]);
        $this->assertStringContainsString('This is the MAC address of core1 Vl10.', self::texts($result));

        // learned on two uplinks toward core1: two paths to the same device, no conflict
        $both = self::locator()->locate('00ab01000110', [self::row(3, 302), self::row(7, 701)], [['device_id' => 1, 'port' => 'Vl10']]);
        $this->assertEqualsCanonicalizing(['same-destination', 'toward-owner'], self::reasons($both));
        $this->assertContains('another path to core1', array_column($both['seen'], 'detail'));
        $this->assertStringNotContainsString('Also learned on another access port', self::texts($both));
    }

    public function testOwnerInterfacesAreCollapsedPerDevice(): void
    {
        // Cisco SVIs share one MAC: one note for the device, not one per interface
        $owners = array_map(fn ($vlan) => ['device_id' => 1, 'port' => 'Vl' . $vlan], range(10, 76));
        $result = self::locator()->locate('00ab01000110', [self::row(3, 302)], $owners);

        $this->assertCount(1, array_filter($result['notes'], fn ($note) => str_starts_with($note['text'], 'This is the MAC address')));
        $this->assertStringContainsString('This is the MAC address of core1 Vl10, Vl11, Vl12 and 64 more interfaces.', self::texts($result));
    }

    public function testManagementPortOfASwitchOnAnAccessPort(): void
    {
        // acc-sw7's out-of-band management port is plugged into acc-sw2 Gi1/0/5
        $result = self::locator()->locate('00ab10000001', [self::row(1, 107), self::row(4, 405)], [['device_id' => 10, 'port' => 'mgmt0']]);

        $this->assertSame('owner', $result['status']);
        $this->assertSame(10, $result['device']['device_id']);
        $this->assertSame([4, 405], [$result['edge']['device_id'], $result['edge']['port_id']], 'where its interface is plugged in');
    }

    public function testVrrpAddressIsAnsweredByTheMaster(): void
    {
        // core1 is master: core2, acc-sw1 and acc-sw5 learn the virtual MAC toward it, core1 itself does not list it
        $result = self::locator()->locate('00005e00010a', [self::row(2, 201), self::row(3, 302), self::row(7, 701)]);

        $this->assertSame('gateway', $result['status']);
        $this->assertSame(['device_id' => 1, 'name' => 'core1'], $result['device']);
        $this->assertNull($result['edge']);
        $this->assertSame(['protocol' => 'VRRP', 'group' => 10, 'label' => 'VRRP group 10'], $result['gateway']);
        $this->assertStringContainsString('Virtual router address (VRRP group 10): core1 sends from it as the master / active router of the group.', self::texts($result));
        $this->assertEqualsCanonicalizing(['same-destination', 'same-destination', 'toward-owner'], self::reasons($result));
        $this->assertSame([], array_filter($result['notes'], fn ($note) => $note['level'] === 'warning'), 'no loop or moved host warning: ' . self::texts($result));
    }

    public function testAGatewayTrailEndingAtASwitchWithoutForwardingTableIsBehindIt(): void
    {
        // acc-sw7 is a switch without forwarding table data: the master may be anywhere behind it
        $result = self::locator()->locate('00005e00010a', [self::row(2, 221)]);

        $this->assertSame(['behind', 'acc-sw7'], [$result['status'], $result['device']['name']]);
        $this->assertStringContainsString('Virtual router address (VRRP group 10): the router that is master / active for the group sends from it.', self::texts($result));
        $this->assertStringNotContainsString('acc-sw7 sends from it', self::texts($result));
    }

    public function testAGatewayTrailEndsAtADeviceWithoutForwardingTableOnlyWithLayer3Evidence(): void
    {
        // routes: rtr1 has 10.10.10.2/24, rtr2 10.10.10.3/24; dist-sw1 (an EX switch that LibreNMS classifies as a router
        // by its OS) and the transparent firewall have no interface in the VLAN or subnet
        $routes = fn (int $deviceId, array $vlans, array $ips) => in_array($deviceId, [14, 15], true) && in_array('10.10.10.1', $ips, true);
        $locator = self::locator([], [], [], ['routes' => $routes]);

        $pair = $locator->locate('00005e00010a', [self::row(2, 222)], [], [], ['10.10.10.1']);
        $this->assertSame(['gateway', 'rtr1'], [$pair['status'], $pair['device']['name']], 'a real router pair: the master is named');
        $this->assertSame(['gateway', 'rtr2'], [$locator->locate('00005e00010a', [self::row(2, 225)], [], [], ['10.10.10.1'])['status'], 'rtr2']);

        $ex = $locator->locate('00005e000114', [self::row(2, 223)], [], [], ['10.10.10.1']);
        $this->assertSame(['behind', 'dist-sw1'], [$ex['status'], $ex['device']['name']], 'WAN routers hang off a switch without forwarding table');
        $this->assertStringNotContainsString('dist-sw1 sends from it', self::texts($ex));

        $firewall = $locator->locate('00005e000115', [self::row(2, 224)], [], [], ['10.10.10.1']);
        $this->assertSame(['behind', 'fw-transparent'], [$firewall['status'], $firewall['device']['name']], 'routers behind a transparent firewall');

        $this->assertSame('behind', self::locator()->locate('00005e00010a', [self::row(2, 222)], [], [], ['10.10.10.1'])['status'], 'no evidence without the lookup');
    }

    public function testRouterMacThatIsNoInterfaceMacEndsBehindTheRouter(): void
    {
        // an anycast gateway or a router MAC LibreNMS does not have as ifPhysAddress: the trail ends at core1
        $result = self::locator()->locate('2c3124000001', [self::row(2, 201), self::row(3, 302), self::row(7, 701)]);

        $this->assertSame('behind', $result['status']);
        $this->assertSame(1, $result['device']['device_id']);
        $this->assertSame('not-listed', $result['edge']['via']);
        $this->assertStringContainsString('or the address belongs to core1 itself', self::texts($result));
    }

    public function testTrailEndsAtAMonitoredSwitchWithoutForwardingTable(): void
    {
        // acc-sw7 has no forwarding table data, so the host is somewhere behind it
        $result = self::locator()->locate('0019aa000001', [self::row(2, 221)]);

        $this->assertSame('behind', $result['status']);
        $this->assertSame(['device_id' => 10, 'name' => 'acc-sw7'], $result['device'], 'the map draws the host next to acc-sw7');
        $this->assertSame('acc-sw7', $result['edge']['neighbor']['name']);
        $this->assertSame('warning', $result['notes'][0]['level']);
        $this->assertStringContainsString('The trail ends at acc-sw7: LibreNMS monitors it but has no forwarding table from it', self::texts($result));
    }

    public function testSilentHostBehindASwitchThatKeepsItsTable(): void
    {
        // acc-sw8 reads its forwarding table but does not list the MAC: the host was silent or is gone, it is still behind acc-sw8
        $result = self::locator()->locate('0019aa000002', [self::row(5, 506)]);

        $this->assertSame('behind', $result['status']);
        $this->assertSame(['device_id' => 11, 'name' => 'acc-sw8'], $result['device']);
        $this->assertSame([506, 'not-listed'], [$result['edge']['port_id'], $result['edge']['via']]);
        $this->assertStringContainsString('The trail ends at acc-sw8: its forwarding table does not list the host. The host was silent', self::texts($result));
    }

    public function testHostAgedOutOfTheAccessSwitchButStillInTheCore(): void
    {
        $result = self::locator()->locate('3c2af4000001', [self::row(1, 107)]);

        $this->assertSame('behind', $result['status']);
        $this->assertSame(4, $result['device']['device_id'], 'behind acc-sw2, not "located on core1 Te1/0/3"');
    }

    public function testASwitchThatDoesNotListTheHostOnlyCountsWithoutAnotherNeighbor(): void
    {
        $locator = self::locator([], [506 => [self::monitored(11), self::cdp('SEP00AB11CD3344', 'Cisco IP Phone 7841')]]);
        $result = $locator->locate('d4bed9030405', [self::row(5, 506)]);

        $this->assertSame('located', $result['status']);
        $this->assertSame(['end-device', 'SEP00AB11CD3344'], [$result['edge']['via'], $result['edge']['neighbor']['name']]);
        $this->assertStringContainsString('Several LLDP/CDP neighbors on this port (SEP00AB11CD3344, acc-sw8)', self::texts($result));
    }

    public function testHostBehindAnUnmanagedSwitch(): void
    {
        $result = self::locator()->locate('001122334455', [self::row(1, 108), self::row(5, 505)]);

        $this->assertSame([5, 505, 'unmanaged'], [$result['edge']['device_id'], $result['edge']['port_id'], $result['edge']['via']]);
        $this->assertStringContainsString('Behind desk-switch, an unmanaged switch (not monitored by LibreNMS, or not visible to you).', self::texts($result));
    }

    public function testSharedAccessPortWithoutNeighbor(): void
    {
        $result = self::locator()->locate('00408c000001', [self::row(6, 603, 40)]);

        $this->assertSame('access', $result['edge']['via']);
        $this->assertSame([40], $result['edge']['vlans']);
        $this->assertStringContainsString('7 MACs are learned on this port and it has no LLDP/CDP neighbor', self::texts($result));
    }

    public function testUplinkWithoutLldpCarriesManyMacs(): void
    {
        // acc-sw2 Gi1/0/47 leads to a switch without LLDP; the host is on Gi1/0/5
        $result = self::locator()->locate('001a2b3c4d5e', [self::row(4, 406), self::row(4, 405)]);

        $this->assertSame(405, $result['edge']['port_id']);
        $this->assertSame('uplink', $result['seen'][0]['reason']);
        $this->assertSame('carries 300 MACs: probably an uplink to a switch without LLDP/CDP', $result['seen'][0]['detail']);
        $this->assertStringNotContainsString('Also learned on another access port', self::texts($result));
    }

    public function testUplinkWithoutLldpLeadsToTheSwitchWhoseMacItLearned(): void
    {
        // acc-sw2 Gi1/0/47 learned lab-sw1's own MAC: it leads to lab-sw1, whatever its MAC count
        $toLab = [406 => [100]];
        $result = self::locator([], [], $toLab)->locate('7cd30a000001', [self::row(4, 406, 20), self::row(100, 10011, 20)]);
        $this->assertSame([100, 10011], [$result['edge']['device_id'], $result['edge']['port_id']]);
        $this->assertSame('link toward lab-sw1 (its own MAC address is learned here), which also learned it', $result['seen'][0]['detail']);

        // acc-sw2 was read while lab PCs were silent (20 MACs on the uplink); the host is on a hub with 29 MACs
        $hub = self::locator([100 => self::NOW - 600 - HostLocator::STALE_AFTER], [], $toLab, [], [406 => 20])
            ->locate('7cd30a000002', [self::row(4, 406, 20), self::row(100, 10012, 20, self::NOW - 600)]);
        $this->assertSame([100, 10012], [$hub['edge']['device_id'], $hub['edge']['port_id']]);
        $this->assertSame(['toward-switch'], self::reasons($hub));

        // a small lab switch: the uplink holds 3 MACs (two hosts and lab-sw1), the host is alone on its port
        $small = self::locator([], [], $toLab, [], [406 => 3])->locate('7cd30a000003', [self::row(4, 406, 20), self::row(100, 10011, 20)]);
        $this->assertSame(10011, $small['edge']['port_id']);
        $this->assertStringNotContainsString('Also learned on another access port', self::texts($small));
    }

    public function testServerDualHomedToASwitchPair(): void
    {
        // srv-db-1 bonds a NIC to acc-sw1 Po10 and one to acc-sw2 Po10 (LLDP names it on both)
        $result = self::locator()->locate('b8ca3a000001', [self::row(1, 104), self::row(1, 107), self::row(3, 320), self::row(4, 420)]);

        $this->assertSame('end-device', $result['edge']['via']);
        $this->assertContains($result['edge']['device_id'], [3, 4]);
        $this->assertContains('dual-homed', self::reasons($result));
        $this->assertStringContainsString('Behind server srv-db-1', self::texts($result), 'LLDP: a Linux system description is a server');
        $this->assertStringNotContainsString('Also learned on another access port', self::texts($result));
    }

    public function testTwoAccessPortsAtTheSameTimeAreReported(): void
    {
        $result = self::locator()->locate('001a2b3c4d5f', [self::row(3, 305), self::row(5, 503)]);

        $this->assertSame([3, 305], [$result['edge']['device_id'], $result['edge']['port_id']]);
        $this->assertSame('access', $result['seen'][0]['reason']);
        $this->assertStringContainsString('Also learned on another access port (acc-sw3 Gi1/0/1) at about the same time', self::texts($result));
    }

    public function testRoamingClientIsAtTheAccessPointThatSawItLast(): void
    {
        // acc-sw1 was read 4 hours ago (the client was behind AP-2F-07 then), acc-sw2 10 minutes ago (behind AP-3F-02)
        $locator = self::locator([3 => self::NOW - 4 * 3600 - HostLocator::STALE_AFTER, 4 => self::NOW - 600 - HostLocator::STALE_AFTER]);
        $result = $locator->locate('3c22fb0000aa', [self::row(3, 307, 10, self::NOW - 4 * 3600), self::row(4, 412, 10, self::NOW - 600)]);

        $this->assertSame([4, 412], [$result['edge']['device_id'], $result['edge']['port_id']], 'not the access point with fewer clients');
        $this->assertStringContainsString('Behind access point AP-3F-02', self::texts($result));
        $this->assertSame(['earlier', 'seen here 3.8h before the newest sighting: the host moved or roamed since'], [$result['seen'][0]['reason'], $result['seen'][0]['detail']]);
        $this->assertSame([], array_filter($result['notes'], fn ($note) => $note['level'] === 'warning'));
    }

    public function testMovedHostWhoseOldSwitchWasNotReadSince(): void
    {
        // acc-sw1's forwarding table was last read 3 days ago, when the laptop was on Gi1/0/1; it is on acc-sw3 now
        $result = self::locator([3 => self::NOW - 3 * 86400 - HostLocator::STALE_AFTER])
            ->locate('5c8a38000001', [self::row(3, 305, 10, self::NOW - 3 * 86400), self::row(5, 503)]);

        $this->assertSame([5, 503], [$result['edge']['device_id'], $result['edge']['port_id']]);
        $this->assertSame('earlier', $result['seen'][0]['reason']);
        $this->assertStringNotContainsString('Also learned on another access port', self::texts($result));
    }

    public function testMovedHostKeepsTheOldEntryAsStale(): void
    {
        $result = self::locator()->locate('f0def1000001', [self::row(3, 305, 10, self::NOW - 86400), self::row(1, 108), self::row(5, 503)]);

        $this->assertSame([5, 503], [$result['edge']['device_id'], $result['edge']['port_id']]);
        $this->assertFalse($result['stale']);
        $last = end($result['seen']);
        $this->assertSame(['stale', 'not seen at the last discovery of acc-sw1'], [$last['reason'], $last['detail']]);
    }

    public function testOnlyOldEntries(): void
    {
        $result = self::locator()->locate('f0def1000002', [self::row(3, 305, 10, self::NOW - 86400)]);

        $this->assertSame('located', $result['status']);
        $this->assertTrue($result['stale']);
        $this->assertStringContainsString('Not seen at the last forwarding table discovery', self::texts($result));
    }

    /**
     * An 802.1X / MAB session; current: not historical, on a switch that polls sessions and was polled recently
     * (HostService decides).
     */
    private static function session(int $deviceId, int $portId, int $lastSeen = self::NOW, bool $historical = false, ?int $started = null, ?bool $current = null): array
    {
        return ['device_id' => $deviceId, 'port_id' => $portId, 'vlan' => 10, 'started' => $started ?? $lastSeen - 3600, 'last_seen' => $lastSeen,
            'historical' => $historical, 'current' => $current ?? ! $historical];
    }

    public function testAHostSeenOnlyByItsAuthenticationSession(): void
    {
        // connected after the last forwarding table read (every 6 hours), authenticated on acc-sw3 Gi1/0/1 (polled every 5 minutes)
        $result = self::locator()->locate('d4bed9004001', [], [], [self::session(5, 503, self::NOW - 7200, true), self::session(5, 503)]);
        $this->assertSame(['located', 5, 503, 'nac'], [$result['status'], $result['edge']['device_id'], $result['edge']['port_id'], $result['edge']['via']]);
        $this->assertStringContainsString('Located by its current 802.1X / MAB session on acc-sw3 Gi1/0/1: no forwarding table you can see lists it yet', self::texts($result));
        $this->assertSame([10], $result['edge']['vlans']);

        // behind the IP phone on the session's port
        $phone = self::locator()->locate('d4bed9004002', [], [], [self::session(7, 703)]);
        $this->assertStringContainsString('Behind IP phone SEP00AB11CD22EF', self::texts($phone));

        $this->assertSame('none', self::locator()->locate('d4bed9004003', [], [], [self::session(5, 503, self::NOW, true)])['status'], 'an ended session is no location');
    }

    public function testAnAuthenticationSessionGivesThePortWhereTheTrailEnds(): void
    {
        // the trail ends at acc-sw7 (no forwarding table): the session is on its Gi1/0/5
        $result = self::locator()->locate('0019aa000011', [self::row(2, 221)], [], [self::session(10, 1005)]);

        $this->assertSame(['located', 10, 1005, 'nac'], [$result['status'], $result['edge']['device_id'], $result['edge']['port_id'], $result['edge']['via']]);
        $this->assertNull($result['device']);
        $this->assertSame(['behind-monitored', 'toward acc-sw7, which has no forwarding table data'], [$result['seen'][0]['reason'], $result['seen'][0]['detail']]);
        $this->assertStringContainsString('the forwarding tables lead to acc-sw7, which has no forwarding table data.', self::texts($result));
        $this->assertSame([], array_filter($result['notes'], fn ($note) => $note['level'] === 'warning'));

        // only links between switches learned it: a session on one of those switches
        $uplinks = self::locator()->locate('0019aa000012', [self::row(1, 105), self::row(3, 302)], [], [self::session(3, 305)]);
        $this->assertSame(['located', 305], [$uplinks['status'], $uplinks['edge']['port_id']]);
        $this->assertStringContainsString('the forwarding tables only list it on links between switches', self::texts($uplinks));
        $elsewhere = self::locator()->locate('0019aa000013', [self::row(1, 105), self::row(3, 302)], [], [self::session(5, 503)]);
        $this->assertSame('uplinks', $elsewhere['status'], 'a session on a switch the trail does not lead to');

        // behind acc-sw7: a session on acc-sw9 behind it (the behind lookup) is the location, one on acc-sw1 is not
        $behind = ['behind' => fn (int $deviceId, array $exclude) => $deviceId === 10 && ! in_array(12, $exclude, true) ? [12] : []];
        $chain = self::locator([], [], [], $behind)->locate('0019aa000014', [self::row(2, 221)], [], [self::session(12, 1201)]);
        $this->assertSame(['located', 12, 1201], [$chain['status'], $chain['edge']['device_id'], $chain['edge']['port_id']]);
        $other = self::locator([], [], [], $behind)->locate('0019aa000015', [self::row(2, 221)], [], [self::session(3, 305, self::NOW - 2 * 86400, false, self::NOW - 3 * 86400)]);
        $this->assertSame(['behind', 'acc-sw7'], [$other['status'], $other['device']['name']], 'an old session on acc-sw1 is not where the trail leads');
        $this->assertStringContainsString('An 802.1X / MAB session of this MAC is on acc-sw1 Gi1/0/1, where the forwarding tables do not lead', self::texts($other));
    }

    public function testAnAuthenticationSessionAndTheForwardingTables(): void
    {
        // on the port of an old entry: a session updated since shows the host is still there; an older one does not
        $there = self::locator()->locate('f0def1000003', [self::row(3, 305, 10, self::NOW - 86400)], [], [self::session(3, 305)]);
        $this->assertSame(['located', 'access', false], [$there['status'], $there['edge']['via'], $there['stale']]);
        $this->assertStringNotContainsString('Not seen at the last forwarding table discovery', self::texts($there));
        $old = self::locator()->locate('f0def1000006', [self::row(3, 305, 10, self::NOW - 86400)], [], [self::session(3, 305, self::NOW - 2 * 86400)]);
        $this->assertTrue($old['stale'], 'a session older than the entry does not clear "not seen recently"');

        // acc-sw1 was read 3 hours ago with the laptop on Gi1/0/1; its session on acc-sw3 Gi1/0/1 started 2 minutes ago: it moved
        $moved = self::locator([3 => self::NOW - 3 * 3600 - HostLocator::STALE_AFTER])
            ->locate('f0def1000004', [self::row(3, 305, 10, self::NOW - 3 * 3600)], [], [self::session(5, 503, self::NOW - 60, false, self::NOW - 120)]);
        $this->assertSame([5, 503, 'nac'], [$moved['edge']['device_id'], $moved['edge']['port_id'], $moved['edge']['via']]);
        $this->assertSame('earlier', $moved['seen'][0]['reason']);
        $this->assertStringContainsString('which started after the forwarding tables last saw the host on acc-sw1 Gi1/0/1: the host moved since', self::texts($moved));
    }

    public function testAnOldSessionThatAPhoneKeepsUpDoesNotMoveTheHost(): void
    {
        // the PC left the phone on acc-sw5 Gi2/0/1 yesterday: the phone keeps the link up, so the switch still reports the
        // session (started 2 days ago, its update time refreshed at every poll); the forwarding tables saw the PC 3 hours ago
        $read = self::NOW - 3 * 3600;
        $locator = self::locator(array_fill_keys([1, 5], $read - HostLocator::STALE_AFTER));
        $result = $locator->locate('d4bed9010210', [self::row(1, 108, 10, $read), self::row(5, 503, 10, $read)], [],
            [self::session(7, 703, self::NOW - 120, false, self::NOW - 2 * 86400)]);

        $this->assertSame(['located', 5, 503, 'access'], [$result['status'], $result['edge']['device_id'], $result['edge']['port_id'], $result['edge']['via']]);
        $this->assertStringContainsString('An 802.1X / MAB session of this MAC is on acc-sw5 Gi2/0/1, but it started before the forwarding tables saw the host here', self::texts($result));
        $this->assertStringNotContainsString('moved since', self::texts($result));

        // an hour ago the PC authenticated on acc-sw1 Gi1/0/1: that session started last, although acc-sw5 refreshed the
        // old one more recently
        $result = $locator->locate('d4bed9010210', [self::row(1, 108, 10, $read), self::row(5, 503, 10, $read)], [],
            [self::session(7, 703, self::NOW - 30, false, self::NOW - 2 * 86400), self::session(3, 305, self::NOW - 200, false, self::NOW - 3600)]);

        $this->assertSame(['located', 3, 305, 'nac', false], [$result['status'], $result['edge']['device_id'], $result['edge']['port_id'], $result['edge']['via'], $result['stale']]);
        $this->assertStringContainsString('Located by its current 802.1X / MAB session on acc-sw1 Gi1/0/1, which started after the forwarding tables last saw the host on acc-sw3 Gi1/0/1: the host moved since.', self::texts($result));
    }

    public function testAnOlderSessionDoesNotRefreshAHostThatWasNotSeenRecently(): void
    {
        // last seen yesterday (entries stale); a session elsewhere that started 3 days ago and was last updated 2 days ago
        $result = self::locator()->locate('d4bed9010211', [self::row(5, 503, 10, self::NOW - 86400)], [],
            [self::session(3, 305, self::NOW - 2 * 86400, false, self::NOW - 3 * 86400)]);

        $this->assertSame(['located', 5, 503, true], [$result['status'], $result['edge']['device_id'], $result['edge']['port_id'], $result['stale']]);
        $this->assertStringNotContainsString('newer than', self::texts($result));
        $this->assertStringNotContainsString('Located by its current 802.1X', self::texts($result));
        $this->assertStringContainsString('Not seen at the last forwarding table discovery', self::texts($result));

        // a session the service did not find current (its switch does not poll sessions or was not polled recently) is no location at all
        $this->assertSame('none', self::locator()->locate('d4bed9010212', [], [], [self::session(3, 305, self::NOW - 2 * 86400, false, null, false)])['status']);
    }

    public function testMacsAreCountedOnTrailEndsOnly(): void
    {
        $asked = [];
        $counter = function (array $ids) use (&$asked): array {
            $asked[] = $ids;

            return array_fill_keys($ids, 3);
        };
        $locator = self::locator([], [], [], ['macs' => $counter]);
        $result = $locator->locate('d4bed9010203', [self::row(1, 120), self::row(2, 220), self::row(7, 703)]);
        $locator->locate('d4bed9010204', [self::row(1, 120), self::row(7, 703)]);

        $this->assertSame([[703]], $asked, 'hop ports are not counted, and a port is counted once');
        $this->assertSame(3, $result['edge']['macs']);
    }

    public function testEveryEntryPointsToAnotherSwitch(): void
    {
        $result = self::locator()->locate('0019aa000003', [self::row(1, 105), self::row(3, 302)]);

        $this->assertSame('uplinks', $result['status']);
        $this->assertNull($result['edge']);
        $this->assertCount(2, $result['seen']);
        $this->assertStringContainsString('the network has a loop', self::texts($result));
    }

    public function testUnmappedBridgePortIsTheLastResort(): void
    {
        $result = self::locator()->locate('0019aa000004', [self::row(5, 0), self::row(5, 503)]);

        $this->assertSame(503, $result['edge']['port_id']);
        $this->assertSame('unknown-port', $result['seen'][0]['reason']);

        $only = self::locator()->locate('0019aa000005', [self::row(5, 0)]);
        $this->assertSame('unknown-port', $only['edge']['via']);
    }

    public function testVlansAreMergedPerPort(): void
    {
        $result = self::locator()->locate('0019aa000006', [self::row(5, 503, 30), self::row(5, 503, 10), self::row(5, 503, null)]);

        $this->assertSame([10, 30], $result['edge']['vlans']);
        $this->assertSame([], $result['seen']);
    }

    public function testSeveralNeighborsOnOnePort(): void
    {
        $locator = self::locator([], [505 => [self::cdp('SEP001122334455', 'Cisco IP Phone 7841'), self::lldp('desk-switch', '')]]);
        $result = $locator->locate('0019aa000007', [self::row(5, 505)]);

        $this->assertSame('unmanaged', $result['edge']['via']);
        $this->assertStringContainsString('Several LLDP/CDP neighbors on this port (desk-switch, SEP001122334455): an unmanaged switch or hub joins them.', self::texts($result));
    }

    public function testNotFoundAndRandomizedMac(): void
    {
        $result = self::locator()->locate('da0123456789', []);

        $this->assertSame('none', $result['status']);
        $this->assertStringContainsString('Locally administered address', self::texts($result));
    }

    public function testLldpAndCdpNeighborRoles(): void
    {
        $phone = HostRepository::unmanagedNeighbor(['remote_hostname' => 'SEP00AB11CD22EF', 'remote_port' => 'Port 1', 'remote_platform' => null,
            'remote_version' => "Cisco IP Phone CP-8865, V3, sip8845_65.14-2-1-0001-14\nhardware 1"]);
        $this->assertSame(['phone', '', 'Cisco IP Phone CP-8865, V3, sip8845_65.14-2-1-0001-14'], [$phone['role'], $phone['platform'], $phone['description']]);
        $this->assertSame('wireless', self::lldp('ap-3f-02', 'Cisco AP Software, ap3g3-k9w8 Version: 17.9.4.27')['role']);
        $this->assertSame('phone', self::lldp('T54W', 'Yealink SIP-T54W 96.86.0.70')['role']);
        $this->assertSame('server', self::lldp('srv-db-1', 'Linux srv-db-1 6.1.0-18-amd64 #1 SMP Debian 6.1.76-1 x86_64')['role']);
        $this->assertSame('switch', self::lldp('leaf1', 'SONiC Software Version: SONiC.202305.1 - HwSku: Accton-AS7726-32X - Distribution: Debian 11.7 - Kernel: 5.10.0-23-2-amd64')['role'],
            'a network OS on Linux is a switch');
        $this->assertSame('unknown', self::lldp('lab-box', '')['role'], 'nothing known: counts as an unmanaged switch');
        $this->assertSame('phone', self::cdp('SEP00AB11CD22EF', 'Cisco IP Phone 8841')['role']);
        $this->assertSame('srv-db-1', self::lldp('srv-db-1.example.net', '')['name']);
        $this->assertSame('unknown neighbor', self::lldp('', '')['name']);
    }

    public function testALinkReportedByBothEndsIsOneNeighbor(): void
    {
        $neighbors = HostRepository::uniqueNeighbors([
            ['name' => 'acc-sw1', 'port' => 'TenGigabitEthernet1/1/1', 'device_id' => 3], ['name' => 'acc-sw1', 'port' => 'Te1/1/1', 'device_id' => 3],
            ['name' => 'acc-sw1', 'port' => 'Te1/1/2', 'device_id' => 3],
            self::cdp('SEP00AB11CD22EF', 'Cisco IP Phone 8841', 'Port 1'), self::lldp('sep00ab11cd22ef', '', 'port 1'),
        ]);

        $this->assertSame([['acc-sw1', 'TenGigabitEthernet1/1/1'], ['acc-sw1', 'Te1/1/2'], ['SEP00AB11CD22EF', 'Port 1']],
            array_map(fn ($neighbor) => [$neighbor['name'], $neighbor['port']], $neighbors));
    }

    public function testMacNotations(): void
    {
        foreach (['00:1A:2b:3c:4d:5e', '00-1a-2b-3c-4d-5e', '001a.2b3c.4d5e', '001a2b-3c4d5e', '001A2B3C4D5E', '0x001a2b3c4d5e', '0:1a:2b:3c:4d:5e', ' 00 1a 2b 3c 4d 5e '] as $input) {
            $this->assertSame('001a2b3c4d5e', Mac::normalize($input), $input);
        }
        foreach (['001a2b3c4d', '00:1a:2b:3c:4d:5e:6f', 'zz:1a:2b:3c:4d:5e', '001:a2b:3c4:d5e', '', 'host-name'] as $input) {
            $this->assertNull(Mac::normalize($input), $input);
        }
        $this->assertSame('00:1a:2b:3c:4d:5e', Mac::readable('001a2b3c4d5e'));
        $this->assertTrue(Mac::isLocal('da0123456789'));
        $this->assertFalse(Mac::isLocal('001a2b3c4d5e'));
        $this->assertTrue(Mac::isMulticast('01005e000001'));
    }

    public function testNacMacNotations(): void
    {
        // Aruba CX, ProCurve and PowerConnect sessions store MACs without leading zeros (LibreNMS test data: 0:4:f2:bc:95:2)
        $this->assertSame('0004f2bc9502', Mac::normalize('0:4:f2:bc:95:2'));
        $this->assertSame(['0004f2bc9502', '00:04:f2:bc:95:02', '0:4:f2:bc:95:2'], Mac::variants('0004f2bc9502'));
        $this->assertSame('00:04:f2:bc:95:02', Mac::readable((string) Mac::normalize('0:4:f2:bc:95:2')));
        $this->assertSame(['010203040500', '01:02:03:04:05:00', '1:2:3:4:5:0'], Mac::variants('010203040500'));
    }

    public function testVirtualRouterMacs(): void
    {
        $this->assertSame(['VRRP', 10], self::gateway('00005e00010a'));
        $this->assertSame(['VRRP (IPv6)', 1], self::gateway('00005e000201'));
        $this->assertSame(['HSRP', 1], self::gateway('00000c07ac01'));
        $this->assertSame(['HSRP version 2', 4095], self::gateway('00000c9fffff'));
        $this->assertSame(['HSRP version 2', 300], self::gateway('00000c9ff12c'));
        $this->assertSame(['HSRP (IPv6)', 2], self::gateway('000573a00002'));
        $this->assertSame(['GLBP', 1], self::gateway('0007b4000102'));
        $this->assertSame('GLBP group 1, forwarder 2', Mac::gateway('0007b4000102')['label']);
        foreach (['00005e000301', '00000c07ad01', '00000c9fe001', 'd4bed9010203', '', 'zz005e00010a'] as $mac) {
            $this->assertNull(Mac::gateway($mac), $mac);
        }
    }

    private static function gateway(string $mac): array
    {
        $gateway = Mac::gateway($mac);

        return [$gateway['protocol'] ?? null, $gateway['group'] ?? null];
    }

    public function testVendorLongestPrefixWins(): void
    {
        $ouis = ['70b3d5' => 'IEEE Registration Authority', '70b3d5123' => 'Acme Sensors', '001a2b' => 'Ayecom'];

        $this->assertSame('Acme Sensors', Mac::vendor('70b3d5123456', $ouis));
        $this->assertSame('IEEE Registration Authority', Mac::vendor('70b3d5999999', $ouis));
        $this->assertSame('Ayecom', Mac::vendor('001a2b3c4d5e', $ouis));
        $this->assertNull(Mac::vendor('00ffff000000', $ouis));
    }

    public function testQueryKinds(): void
    {
        $kind = fn (string $input) => array_values(array_intersect_key(HostQuery::parse($input), ['kind' => 1, 'value' => 1]));

        $this->assertSame(['ipv4', '10.10.10.55'], $kind(' 10.10.10.55 '));
        $this->assertSame(['ipv4', '10.10.10.55'], $kind('10.10.10.55.'), 'trailing dot of a DNS answer');
        $this->assertSame(['ipv4', '10.10.10.55'], $kind('::ffff:10.10.10.55'), 'IPv4-mapped IPv6');
        $this->assertSame(['ipv4', '10.10.10.55'], $kind('::ffff:a0a:a37'));
        $this->assertSame(['ipv6', '2001:0db8:0010:0000:0000:0000:0000:0055'], $kind('2001:DB8:10::55'));
        $this->assertSame(['ipv6', 'fe80:0000:0000:0000:0000:0000:0000:0001'], $kind('fe80::1%eth0'));
        $this->assertSame(['ipv6', '2001:0db8:0000:0000:0000:0000:0000:0001'], $kind('[2001:db8::1]'));
        $this->assertSame(['mac', 'd4bed9010203'], $kind('D4:BE:D9:01:02:03'));
        $this->assertSame(['mac', 'd4bed9010203'], $kind('d4be.d901.0203'));
        $this->assertSame(['name', 'pc-101.example.net'], $kind('PC-101.example.net.'));
        $this->assertSame(['name', 'lab_printer'], $kind('lab_printer'));
        $this->assertSame(['invalid', ''], $kind('rm -rf /'));
        $this->assertSame(['invalid', ''], $kind('10.0.0.300/24'));
        $this->assertSame(['invalid', ''], $kind('10.10.10'), 'a mistyped address is not a hostname');
        $this->assertSame(['invalid', ''], $kind(str_repeat('a', 64) . '.example.net'), 'labels are 63 characters at most');
        $this->assertSame(['empty', ''], $kind('   '));
        $this->assertSame('2001:db8::1', HostQuery::ipv6Short('2001:0db8:0000:0000:0000:0000:0000:0001'));
    }

    public function testRouterInterfaceVlans(): void
    {
        foreach (['Vl10' => 10, 'Vlan10' => 10, 'vlan 20' => 20, 'Vlanif30' => 30, 'Vlan-interface40' => 40, 'irb.50' => 50, 'vlan.60' => 60,
            've 70' => 70, 've80' => 80, 'Gi0/0.10' => 10, 'GigabitEthernet0/0/1.100' => 100, 'ethernet1/1.20' => 20, 'xe-0/0/0.30' => 30, 'ae0.40' => 40] as $name => $vlan) {
            $this->assertSame($vlan, HostService::interfaceVlan($name), $name);
        }
        foreach (['BVI1', 'Vlan0', 'Vlan4095', 'Loopback0', 'Vl10a', 'xe-0/0/0.0', 'Gi0/0', '10.10.10.1'] as $name) {
            $this->assertNull(HostService::interfaceVlan($name), $name);
        }
    }

    public function testDnsLookupHelpers(): void
    {
        $this->assertSame(['10.10.10.55', '2001:db8::55'], DnsResolver::parse("10.10.10.55     STREAM pc-101.example.net\n10.10.10.55     DGRAM\n2001:db8::55    STREAM\n"));
        $this->assertSame(['10.10.10.56'], DnsResolver::parse("10.10.10.56  pc-102.example.net pc-102\n"), 'getent hosts');
        $this->assertSame([], DnsResolver::parse(''));
        $this->assertTrue(DnsResolver::plausible('pc-101'));
        $this->assertTrue(DnsResolver::plausible('pc-101.example.net'));
        foreach (['printer.local', 'x.invalid', 'localhost', 'lab_printer', '1.10.10.10.in-addr.arpa'] as $name) {
            $this->assertFalse(DnsResolver::plausible($name), $name);
        }
    }

    public function testGetentRunsFromAFixedPathWithTheNameAfterItsOptions(): void
    {
        // a device hostname can start with "-": getent parses options anywhere before "--"
        $this->assertSame(['/usr/bin/getent', 'ahosts', '--', '-oops.example.net'], DnsResolver::command('/usr/bin/getent', 'ahosts', '-oops.example.net'));
        foreach (DnsResolver::GETENT as $path) {
            $this->assertStringStartsWith('/', $path);
        }

        // neither PATH nor the working directory is searched (php-fpm clears PATH)
        $dir = sys_get_temp_dir() . '/topology-getent-' . getmypid();
        @mkdir($dir);
        file_put_contents("$dir/getent", "#!/bin/sh\necho 192.0.2.66 planted\n");
        chmod("$dir/getent", 0755);
        $cwd = (string) getcwd();
        $path = getenv('PATH');
        try {
            chdir($dir);
            putenv('PATH');
            $getent = DnsResolver::getent();
            $this->assertTrue($getent === null || in_array($getent, DnsResolver::GETENT, true), 'found ' . $getent);
        } finally {
            chdir($cwd);
            putenv($path === false ? 'PATH' : 'PATH=' . $path);
            unlink("$dir/getent");
            rmdir($dir);
        }
    }
}
