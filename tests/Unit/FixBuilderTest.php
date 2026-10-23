<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Verification\FixBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The verification API's fixes (Verification\FixBuilder): the findings whose configuration is clear, as data a
 * template applies: the port descriptions the link check builds, and the spanning tree root priority of the intended
 * root (designated root bridges first, else the configured core) and its secondary, per domain and VLANs.
 */
class FixBuilderTest extends TestCase
{
    private const NAMES = [1 => 'core1', 2 => 'core2', 3 => 'dist1', 4 => 'acc1', 5 => 'acc2', 6 => 'island1', 7 => 'island2'];

    private static function ports(): array
    {
        $ports = [];
        foreach ([[41, 4, 'GigabitEthernet1/0/49', 'old uplink'], [42, 4, 'GigabitEthernet1/0/50', ''], [43, 4, 'GigabitEthernet1/0/9', ''],
            [51, 5, 'GigabitEthernet1/0/49', '']] as [$id, $device, $name, $alias]) {
            $ports[$id] = ['device_id' => $device, 'ifName' => $name, 'ifDescr' => $name, 'ifAlias' => $alias];
        }

        return $ports;
    }

    private static function finding(string $identity, string $code, array $devices, array $fix, array $vlans = []): array
    {
        return ['identity' => $identity, 'analysis' => in_array($code, ['description_wrong', 'description_missing'], true) ? 'links' : 'stp', 'code' => $code,
            'severity' => 'warning', 'title' => "finding $identity", 'devices' => $devices, 'vlans' => $vlans, 'fix' => $fix];
    }

    public function testADescriptionFixIsTheLinkChecksDescriptionsOfThePortsOfItsDevice(): void
    {
        $fixes = FixBuilder::descriptions([
            self::finding('h1', 'description_wrong', [4], ['ports' => [42 => 'core2 Te1/0/4', 41 => 'dist1 Te1/0/1', 43 => 'acc9 Gi0/1', 51 => 'dist1 Te1/0/2',
                99 => 'gone Gi0/1', 40 => '']]),
            self::finding('h2', 'description_missing', [5], ['ports' => [51 => 'dist1 Te1/0/2']]),
            self::finding('h3', 'description_missing', [5], []),                       // no fix: nothing clear
            self::finding('h4', 'mtu_mismatch', [4], ['ports' => [41 => 'x']]),        // not a check this kind fixes
        ], self::ports(), self::NAMES);

        $this->assertCount(2, $fixes);
        $this->assertSame('Port descriptions of acc1', $fixes[0]['title']);
        $this->assertSame('port_descriptions', $fixes[0]['kind']);
        $this->assertSame([4], $fixes[0]['devices']);
        $this->assertSame(['h1'], $fixes[0]['resolves']);
        $this->assertSame([
            ['device_id' => 4, 'port_id' => 43, 'ifName' => 'GigabitEthernet1/0/9', 'ifDescr' => 'GigabitEthernet1/0/9', 'current' => '', 'description' => 'acc9 Gi0/1'],
            ['device_id' => 4, 'port_id' => 41, 'ifName' => 'GigabitEthernet1/0/49', 'ifDescr' => 'GigabitEthernet1/0/49', 'current' => 'old uplink', 'description' => 'dist1 Te1/0/1'],
            ['device_id' => 4, 'port_id' => 42, 'ifName' => 'GigabitEthernet1/0/50', 'ifDescr' => 'GigabitEthernet1/0/50', 'current' => '', 'description' => 'core2 Te1/0/4'],
        ], $fixes[0]['ports'], 'in interface order; a port of another device, a port gone and an empty description are left out');
        $this->assertSame([['identity' => 'h1', 'analysis' => 'links', 'code' => 'description_wrong', 'severity' => 'warning', 'title' => 'Finding h1', 'devices' => [4], 'vlans' => []]],
            $fixes[0]['findings']);
        $this->assertSame([51], array_column($fixes[1]['ports'], 'port_id'));
    }

    public function testAFixIsKnownByItsKindAndTheFindingsItResolves(): void
    {
        $finding = self::finding('h1', 'description_wrong', [4], ['ports' => [41 => 'dist1 Te1/0/1']]);
        $id = FixBuilder::descriptions([$finding], self::ports(), self::NAMES)[0]['id'];

        $this->assertSame(sha1('port_descriptions|h1'), $id);
        $this->assertSame($id, FixBuilder::descriptions([['fix' => ['ports' => [41 => 'other Gi0/1']]] + $finding], self::ports(), [])[0]['id'],
            'the same findings are the same fix, whatever the descriptions now: the change recomputes them');
    }

    /**
     * Bridges of a PVST network (VLAN 1 is key 0, VLAN 10 key 10): core1, core2, dist1 and acc1 in one domain, island1 and
     * island2 apart; island2 runs MST.
     */
    private static function bridges(): array
    {
        $bridges = [];
        foreach ([1, 2, 3, 4, 6] as $id) {
            $bridges[$id] = ['keys' => [0, 10], 'instances' => [0 => ['mac' => sprintf('00ab%02d000000', $id), 'priority' => 32768, 'protocol' => 'ieee8021d'],
                10 => ['mac' => sprintf('00ab%02d000000', $id), 'priority' => 32778, 'protocol' => 'ieee8021d']]];
        }
        $bridges[7] = ['keys' => [0], 'instances' => [0 => ['mac' => '00ab07000000', 'priority' => 32768, 'protocol' => 'mstp']]];

        return $bridges;
    }

    private const DOMAINS = [0 => [[1, 2, 3, 4], [6, 7]], 10 => [[1, 2, 3, 4], [6]]];

    public function testTheIntendedRootIsTheFirstDesignatedRootOfTheDomainAndTheNextOneItsSecondary(): void
    {
        $built = FixBuilder::rootPriorities([
            self::finding('h0', 'root_default_priority', [3], ['root' => 3, 'root_mac' => '00ab03000000'], [0]),
            self::finding('h10', 'root_not_preferred', [3], ['root' => 3, 'root_mac' => '00ab03000000'], [10]),
        ], self::DOMAINS, designated: [6, 2, 1], core: [1], bridges: self::bridges(), names: self::NAMES);

        $this->assertSame([], $built['not_fixable']);
        $this->assertCount(1, $built['fixes'], 'both VLANs of the domain, one fix');
        $fix = $built['fixes'][0];
        $this->assertSame('stp_root_priority', $fix['kind']);
        $this->assertSame('Root bridge of VLAN 1 / CST, VLAN 10: core2, secondary core1', $fix['title'], 'island1 is designated, but not in this domain');
        $this->assertSame([2, 1], $fix['devices']);
        $this->assertSame([0, 10], $fix['vlans']);
        $this->assertSame(['h0', 'h10'], $fix['resolves']);
        $this->assertSame('the designated root bridges', $fix['chosen_from']);
        $this->assertSame(4, $fix['domain_size']);
        $this->assertSame(['device_id' => 2, 'priority' => 4096, 'mode' => 'pvst', 'instances' => [1, 10], 'mac' => '00ab02000000', 'now' => [0 => 32768, 10 => 32778]],
            $fix['primary']);
        $this->assertSame(['device_id' => 1, 'priority' => 8192, 'mode' => 'pvst', 'instances' => [1, 10]], array_intersect_key($fix['secondary'], array_flip(['device_id',
            'priority', 'mode', 'instances'])));
        $this->assertSame([0 => ['device_id' => 3, 'mac' => '00ab03000000'], 10 => ['device_id' => 3, 'mac' => '00ab03000000']], $fix['roots_now']);
    }

    public function testWithoutDesignatedRootsTheConfiguredCoreDecidesAndWithoutEitherThereIsNoFix(): void
    {
        $findings = [self::finding('h0', 'root_default_priority', [3], ['root' => 3, 'root_mac' => '00ab03000000'], [0])];

        $core = FixBuilder::rootPriorities($findings, self::DOMAINS, [], [1, 2], self::bridges(), self::NAMES)['fixes'][0];
        $this->assertSame('the core devices', $core['chosen_from']);
        $this->assertSame([1, 2], $core['devices']);

        $none = FixBuilder::rootPriorities($findings, self::DOMAINS, [6], [7], self::bridges(), self::NAMES);
        $this->assertSame([], $none['fixes'], 'a guessed core is no clear configuration');
        $this->assertSame([['identity' => 'h0', 'code' => 'root_default_priority', 'title' => 'Finding h0', 'devices' => [3],
            'why' => 'no designated root bridge or core device in its part of the network: ' . FixBuilder::ROOT_SETTINGS]], $none['not_fixable']);
        $this->assertStringContainsString('Plugin Admin', FixBuilder::ROOT_SETTINGS);
    }

    public function testTheSettingsNameTheBridgesOfAFixByDeviceIdOrHostnameOnly(): void
    {
        // device 724 reports the sysName "sec-sw1": a sysName or a display name never names a device a fix pushes a priority on
        $hostnames = [1 => 'core1.example.net', 2 => 'CORE2.example.net', 7 => 'far1.example.net', 724 => 'imp-sw.example.net', 9 => '3', 3 => 'dist1.example.net'];
        $named = FixBuilder::devicesNamed(['sec-sw1', 'core2.example.net', '7', 'Core1.Example.Net', 'core1', '3', ' '], $hostnames);
        $this->assertSame([2, 7, 1], $named['devices'], 'by LibreNMS hostname (case aside) or device id, in the setting\'s order');
        $this->assertSame(['3' => [3, 9]], $named['ambiguous'], 'the id of one device and the hostname of another');
        $this->assertSame(['sec-sw1', 'core1'], $named['unmatched'], 'a sysName, a short name: no device');

        $findings = [self::finding('h0', 'root_default_priority', [3], ['root' => 3, 'root_mac' => '00ab03000000'], [0])];
        $refused = FixBuilder::rootPriorities($findings, self::DOMAINS, [2, 1], [], self::bridges(), self::NAMES, 'a setting names several devices');
        $this->assertSame([], $refused['fixes'], 'no root fix while a setting names a device ambiguously');
        $this->assertSame('a setting names several devices', $refused['not_fixable'][0]['why']);
        $hinted = FixBuilder::rootPriorities($findings, self::DOMAINS, [], [], self::bridges(), self::NAMES, null, ' (hint)');
        $this->assertStringEndsWith(FixBuilder::ROOT_SETTINGS . ' (hint)', $hinted['not_fixable'][0]['why']);
    }

    public function testAnIntendedRootWithoutTheInstanceIsNoFixAndASecondaryWithoutItIsNone(): void
    {
        $bridges = self::bridges();
        unset($bridges[2]['instances'][10]);
        $finding = self::finding('h10', 'root_not_preferred', [3], ['root' => 3, 'root_mac' => '00ab03000000'], [10]);

        $built = FixBuilder::rootPriorities([$finding], self::DOMAINS, [2, 1], [], $bridges, self::NAMES);
        $this->assertSame([], $built['fixes']);
        $this->assertSame('core2, its intended root, has no spanning tree instance in VLAN 10', $built['not_fixable'][0]['why']);

        $fix = FixBuilder::rootPriorities([$finding], self::DOMAINS, [1, 2], [], $bridges, self::NAMES)['fixes'][0];
        $this->assertSame([1], $fix['devices']);
        $this->assertNull($fix['secondary']);
        $this->assertSame('Root bridge of VLAN 10: core1', $fix['title']);
    }

    public function testEachDomainHasItsOwnFixAndAnMstBridgeSetsItsCist(): void
    {
        $built = FixBuilder::rootPriorities([
            self::finding('h0', 'root_default_priority', [3], ['root' => 3, 'root_mac' => '00ab03000000'], [0]),
            self::finding('i0', 'root_default_priority', [6], ['root' => 6, 'root_mac' => '00ab06000000'], [0]),
        ], self::DOMAINS, [7, 2], [], self::bridges(), self::NAMES);

        $this->assertSame(['Root bridge of VLAN 1 / CST: core2', 'Root bridge of VLAN 1 / CST: island2'], array_column($built['fixes'], 'title'));
        $this->assertSame(['device_id' => 7, 'priority' => 4096, 'mode' => 'mst', 'instances' => [0], 'mac' => '00ab07000000', 'now' => [0 => 32768]],
            $built['fixes'][1]['primary']);
    }

    public function testAFindingFixableInOneOfItsVlansIsNotUnfixable(): void
    {
        $built = FixBuilder::rootPriorities([self::finding('h', 'root_default_priority', [3], ['root' => 3, 'root_mac' => '00ab03000000'], [0, 10])],
            [0 => [[1, 2, 3, 4]], 10 => [[3, 4]]], [1], [], self::bridges(), self::NAMES);

        $this->assertSame([0], $built['fixes'][0]['vlans'], 'VLAN 10\'s domain has no designated root');
        $this->assertSame([], $built['not_fixable']);
    }
}
