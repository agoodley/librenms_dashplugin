<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Hosts\HostLocator;
use Agoodley\LibrenmsTopology\Hosts\HostQuery;
use Agoodley\LibrenmsTopology\Hosts\Mac;
use PHPUnit\Framework\TestCase;

/**
 * The host locator on a campus like the e2e test data: core1/core2, access switches, an access
 * point, an IP phone, a VM host and an unmanaged desk switch.
 */
class HostLocatorTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const NAMES = [1 => 'core1', 2 => 'core2', 3 => 'acc-sw1', 4 => 'acc-sw2', 5 => 'acc-sw3', 6 => 'acc-sw4', 7 => 'acc-sw5',
        8 => 'acc-sw6', 10 => 'acc-sw7', 11 => 'acc-sw8', 13 => 'esx-1'];

    /**
     * port_id => device, name, MACs learned on it, aggregate
     */
    private static function ports(): array
    {
        $ports = [];
        foreach ([
            104 => [1, 'Po11', 40, true], 105 => [1, 'Te1/0/1', 0], 106 => [1, 'Te1/0/2', 0], 107 => [1, 'Te1/0/3', 30], 108 => [1, 'Te1/0/4', 20],
            110 => [1, 'Vl10', 0], 120 => [1, 'Te1/0/6', 5],
            204 => [2, 'Te1/0/1', 40], 205 => [2, 'Te1/0/3', 30], 220 => [2, 'Te1/0/6', 5], 221 => [2, 'Te1/0/7', 8],
            302 => [3, 'Te1/1/1', 300], 305 => [3, 'Gi1/0/1', 1], 307 => [3, 'Gi1/0/10', 25],
            404 => [4, 'Gi1/0/48', 12], 405 => [4, 'Gi1/0/5', 2], 406 => [4, 'Gi1/0/47', 300],
            503 => [5, 'Gi1/0/1', 1], 505 => [5, 'Gi1/0/24', 6], 506 => [5, 'Gi1/0/48', 3],
            603 => [6, 'ge-0/0/1', 7], 701 => [7, 'Gi1/1/1', 200], 703 => [7, 'Gi2/0/1', 2], 803 => [8, 'Gi1/0/2', 12],
        ] as $portId => $port) {
            $ports[$portId] = ['device_id' => $port[0], 'name' => $port[1], 'macs' => $port[2], 'aggregate' => $port[3] ?? false];
        }

        return $ports;
    }

    private static function neighbor(string $name, ?int $deviceId, string $role, string $port = ''): array
    {
        return ['name' => $name, 'port' => $port, 'device_id' => $deviceId, 'role' => $role];
    }

    private static function locator(array $fresh = [], array $extraNeighbors = []): HostLocator
    {
        $neighbors = [
            105 => [self::neighbor('acc-sw1', 3, 'switch', 'Te1/1/1')], 106 => [self::neighbor('acc-sw1', 3, 'switch', 'Te1/1/2')],
            107 => [self::neighbor('acc-sw2', 4, 'switch')], 108 => [self::neighbor('acc-sw3', 5, 'switch')],
            120 => [self::neighbor('acc-sw5', 7, 'switch')],
            204 => [self::neighbor('acc-sw1', 3, 'switch')], 205 => [self::neighbor('acc-sw2', 4, 'switch')], 220 => [self::neighbor('acc-sw5', 7, 'switch')],
            221 => [self::neighbor('acc-sw7', 10, 'switch')],
            302 => [self::neighbor('core1', 1, 'switch')],
            307 => [self::neighbor('AP-2F-07', null, 'wireless', 'eth0')],
            404 => [self::neighbor('acc-sw6', 8, 'switch')],
            505 => [self::neighbor('desk-switch', null, 'unknown', 'port 1')],
            506 => [self::neighbor('acc-sw8', 11, 'switch')],
            701 => [self::neighbor('core1', 1, 'switch', 'Te1/0/6')],
            703 => [self::neighbor('SEP00AB11CD22EF', null, 'phone', 'Port 1')],
            803 => [self::neighbor('esx-1', 13, 'server', 'vmnic0')],
        ];
        foreach ($extraNeighbors as $portId => $list) {
            $neighbors[$portId] = $list;
        }
        $fresh += [1 => self::NOW, 2 => self::NOW, 3 => self::NOW, 4 => self::NOW, 5 => self::NOW, 6 => self::NOW, 7 => self::NOW, 8 => self::NOW, 11 => self::NOW];

        return new HostLocator(self::ports(), $neighbors, [105 => 104, 106 => 104], $fresh, self::NAMES);
    }

    private static function row(int $deviceId, int $portId, ?int $vlan = 10, int $lastSeen = self::NOW): array
    {
        return ['device_id' => $deviceId, 'port_id' => $portId, 'vlan' => $vlan, 'first_seen' => $lastSeen - 3600, 'last_seen' => $lastSeen];
    }

    private static function texts(array $result): string
    {
        return implode(' | ', array_column($result['notes'], 'text'));
    }

    public function testComputerBehindAnIpPhone(): void
    {
        $result = self::locator()->locate('d4bed9010203', [self::row(1, 120), self::row(2, 220), self::row(7, 703)]);

        $this->assertSame('located', $result['status']);
        $this->assertSame([7, 703, 'end-device'], [$result['edge']['device_id'], $result['edge']['port_id'], $result['edge']['via']]);
        $this->assertStringContainsString("Behind IP phone SEP00AB11CD22EF: a computer on the phone's PC port", self::texts($result));
        $this->assertSame(['toward-switch', 'toward-switch'], array_column($result['seen'], 'reason'));
        $this->assertSame('link toward acc-sw5, which also learned it', $result['seen'][0]['detail']);
    }

    public function testTheIpPhoneItself(): void
    {
        $result = self::locator()->locate('00ab11cd22ef', [self::row(7, 703)]);

        $this->assertStringContainsString('This is the IP phone SEP00AB11CD22EF itself.', self::texts($result));
    }

    public function testWirelessClientBehindAnAccessPointThroughPortChannels(): void
    {
        $result = self::locator()->locate('3c22fb000001', [self::row(1, 104), self::row(2, 204), self::row(3, 307)]);

        $this->assertSame([3, 307], [$result['edge']['device_id'], $result['edge']['port_id']]);
        $this->assertSame(25, $result['edge']['macs']);
        $this->assertStringContainsString('Behind access point AP-2F-07: a wireless client', self::texts($result));
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

        $this->assertSame('owner', $result['edge']['via']);
        $this->assertSame(803, $result['edge']['port_id']);
        $this->assertStringContainsString('This is the MAC address of esx-1 vmnic0.', self::texts($result));
    }

    public function testRouterInterfaceSeenOnAnUplink(): void
    {
        // core1 keeps its forwarding table but does not list its own interface MAC
        $result = self::locator()->locate('00ab01000110', [self::row(3, 302)], [['device_id' => 1, 'port' => 'Vl10']]);

        $this->assertSame([3, 302, 'owner'], [$result['edge']['device_id'], $result['edge']['port_id'], $result['edge']['via']]);
        $this->assertStringContainsString('This is the MAC address of core1 Vl10.', self::texts($result));

        // learned on two uplinks toward core1: two paths to the same device, no conflict
        $both = self::locator()->locate('00ab01000110', [self::row(3, 302), self::row(7, 701)], [['device_id' => 1, 'port' => 'Vl10']]);
        $this->assertSame(['same-destination', 'another path to core1'], [$both['seen'][0]['reason'], $both['seen'][0]['detail']]);
        $this->assertStringNotContainsString('Also learned on another access port', self::texts($both));
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

    public function testTwoAccessPortsAreReported(): void
    {
        $result = self::locator()->locate('001a2b3c4d5f', [self::row(3, 305), self::row(5, 503)]);

        $this->assertSame([3, 305], [$result['edge']['device_id'], $result['edge']['port_id']]);
        $this->assertSame('access', $result['seen'][0]['reason']);
        $this->assertStringContainsString('Also learned on another access port (acc-sw3 Gi1/0/1): the host may have moved', self::texts($result));
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

    public function testTrailEndsAtAMonitoredSwitchWithoutForwardingTable(): void
    {
        // acc-sw7 has no forwarding table data, so the host is somewhere behind it
        $result = self::locator()->locate('0019aa000001', [self::row(2, 221)]);

        $this->assertSame('behind', $result['status']);
        $this->assertSame('acc-sw7', $result['edge']['neighbor']['name']);
        $this->assertSame('warning', $result['notes'][0]['level']);
        $this->assertStringContainsString('The trail ends at acc-sw7', self::texts($result));
    }

    public function testMonitoredSwitchThatDoesNotListTheHostIsNotWhereItIs(): void
    {
        // acc-sw8 keeps its forwarding table up to date and does not list the MAC
        $result = self::locator()->locate('0019aa000002', [self::row(5, 506)]);

        $this->assertSame('located', $result['status']);
        $this->assertSame('access', $result['edge']['via']);
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
        $locator = self::locator([], [505 => [self::neighbor('SEP001122334455', null, 'phone'), self::neighbor('desk-switch', null, 'unknown')]]);
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
        $this->assertSame(['ipv6', '2001:0db8:0010:0000:0000:0000:0000:0055'], $kind('2001:DB8:10::55'));
        $this->assertSame(['ipv6', 'fe80:0000:0000:0000:0000:0000:0000:0001'], $kind('fe80::1%eth0'));
        $this->assertSame(['ipv6', '2001:0db8:0000:0000:0000:0000:0000:0001'], $kind('[2001:db8::1]'));
        $this->assertSame(['mac', 'd4bed9010203'], $kind('D4:BE:D9:01:02:03'));
        $this->assertSame(['mac', 'd4bed9010203'], $kind('d4be.d901.0203'));
        $this->assertSame(['name', 'pc-101.example.net'], $kind('PC-101.example.net.'));
        $this->assertSame(['name', 'lab_printer'], $kind('lab_printer'));
        $this->assertSame(['invalid', ''], $kind('rm -rf /'));
        $this->assertSame(['invalid', ''], $kind('10.0.0.300/24'));
        $this->assertSame(['empty', ''], $kind('   '));
        $this->assertSame('2001:db8::1', HostQuery::ipv6Short('2001:0db8:0000:0000:0000:0000:0000:0001'));
    }
}
