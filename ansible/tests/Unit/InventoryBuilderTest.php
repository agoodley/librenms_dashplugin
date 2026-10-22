<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Inventory\Inventory;
use Agoodley\LibrenmsAnsible\Inventory\InventoryBuilder;
use Agoodley\LibrenmsAnsible\Inventory\PlatformMap;
use PHPUnit\Framework\TestCase;

class InventoryBuilderTest extends TestCase
{
    private function build(?array $visible = null, ?int $allowedGroup = null, ?string $map = null): Inventory
    {
        return (new InventoryBuilder(PlatformMap::parse($map), $allowedGroup))
            ->build(InventoryFixture::devices(), InventoryFixture::memberships(), InventoryFixture::groups(), InventoryFixture::locations(), $visible);
    }

    public function testHostNamesAreHostnamesOrDeviceIds(): void
    {
        $names = $this->build()->names();

        $this->assertSame('core1.example.net', $names[1]);
        $this->assertSame('acc-sw1', $names[3]);
        $this->assertSame('device4', $names[4], 'an IPv6 hostname is not a safe Ansible name');
        $this->assertSame('hostile.lab', $names[5]);
        $this->assertSame('device6', $names[6], 'the same hostname in another case goes to the lowest id');
        $this->assertSame('device9', $names[9], '"all" is a group of Ansible');
        $this->assertSame('device10', $names[10], 'device<number> is reserved for the fallbacks');
        $this->assertSame('device14', $names[14], 'a host may not have the name of a group');
        $this->assertSame('rtr12.example.net', $names[12]);
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_][A-Za-z0-9._-]*$/', $name);
        }
    }

    public function testConnectionVariables(): void
    {
        $hosts = $this->build()->hosts();

        $this->assertSame('core1.example.net', $hosts['core1.example.net']['ansible_host']);
        $this->assertSame('cisco.ios.ios', $hosts['core1.example.net']['ansible_network_os']);
        $this->assertSame('ansible.netcommon.network_cli', $hosts['core1.example.net']['ansible_connection']);
        $this->assertTrue($hosts['core1.example.net']['ansible_become']);
        $this->assertSame('enable', $hosts['core1.example.net']['ansible_become_method']);
        $this->assertSame('2001:db8::10', $hosts['device4']['ansible_host']);
        $this->assertSame('10.0.0.12', $hosts['rtr12.example.net']['ansible_host'], 'the overwrite IP wins');
        $this->assertSame('junipernetworks.junos.junos', $hosts['rtr12.example.net']['ansible_network_os']);
        $this->assertArrayNotHasKey('ansible_become', $hosts['rtr12.example.net']);
        $this->assertSame(12, $hosts['rtr12.example.net']['librenms_device_id']);
        $this->assertSame('15.2(7)E3', $hosts['acc-sw1']['librenms_version']);
        $this->assertSame('Building B', $hosts['acc-sw1']['librenms_location']);
        $this->assertSame('', $hosts['fw1.example.net']['librenms_location']);
    }

    public function testHostileNetworkDataIsCleaned(): void
    {
        $vars = $this->build()->hosts()['hostile.lab'];
        $values = implode(' ', array_map('strval', $vars));

        foreach (['{', '}', '%', '#', '<', '>', "\n", "\r", "'", "\x07", "\u{202E}"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $values);
        }
        $this->assertSame('evil lookup(pipe,id)', $vars['librenms_sysname']);
        $this->assertSame('scriptalert(1)/script Floor if 1 2 endif 3', $vars['librenms_location']);
        $this->assertSame('C9300 raw', $vars['librenms_hardware']);
        $this->assertSame('17.3 x', $vars['librenms_version']);
    }

    public function testDevicesLeftOutWithReasons(): void
    {
        $inventory = $this->build();
        $leftOut = $inventory->leftOut();

        $this->assertSame('disabled', $leftOut[7]['reason']);
        $this->assertSame('no_mapping', $leftOut[8]['reason']);
        $this->assertStringContainsString('linux', $leftOut[8]['text']);
        $this->assertSame('no_address', $leftOut[11]['reason'], 'an address starting with "-" would be an SSH option');
        $this->assertSame('no_address', $leftOut[13]['reason']);
        $this->assertNull($inventory->nameOf(7));
        $this->assertCount(11, $inventory->hosts());
    }

    public function testGroupsByKindWithCollisionsNumbered(): void
    {
        $inventory = $this->build();
        $groups = $inventory->groups();

        $this->assertSame(['core1.example.net', 'core2.example.net', 'device6'], $groups['os_iosxe']);
        $this->assertContains('acc-sw1', $groups['os_ios']);
        $this->assertContains('core1.example.net', $groups['platform_cisco_ios_ios']);
        $this->assertSame(['core1.example.net', 'core2.example.net'], $groups['group_core']);
        $this->assertSame(['core2.example.net'], $groups['group_core_2'], 'a second "core" group gets a number');
        $this->assertSame(['acc-sw1', 'rtr12.example.net'], $groups['group_unicode_sites']);
        $this->assertSame(['acc-sw1', 'core2.example.net'], $groups['location_building_b']);
        $this->assertSame(['rtr12.example.net'], $groups['location_lab_a_b_west']);
        $this->assertSame(['rtr12.example.net'], $groups['type_firewall']);
        $this->assertArrayNotHasKey('os_linux', $groups, 'groups without hosts are left out');
        foreach (array_keys($groups) as $group) {
            $this->assertMatchesRegularExpression('/^(os|platform|group|location|type)_[a-z0-9_]+$/', $group);
        }
        $this->assertSame(['kind' => 'group', 'label' => 'core'], $inventory->groupInfo()['group_core_2']);
    }

    public function testAllowedGroupKeepsOnlyItsMembers(): void
    {
        $inventory = $this->build(null, 3);

        $this->assertSame([3 => 'acc-sw1', 12 => 'rtr12.example.net'], $inventory->names());
        $this->assertSame('outside_group', $inventory->leftOut()[1]['reason']);
        $this->assertStringContainsString('Ünïcödé Sites', $inventory->leftOut()[1]['text']);
        $this->assertSame('disabled', $inventory->leftOut()[7]['reason'], 'disabled is said first');
    }

    public function testAMissingAllowedGroupLeavesEveryDeviceOut(): void
    {
        $inventory = $this->build(null, 99);

        $this->assertSame([], $inventory->names());
        $this->assertStringContainsString('#99 (it no longer exists)', $inventory->leftOut()[1]['text']);
    }

    public function testAScopeKeepsTheNames(): void
    {
        $inventory = $this->build([6 => true, 7 => true, 3 => true]);

        $this->assertSame([3 => 'acc-sw1', 6 => 'device6'], $inventory->names(), 'device6 keeps its name though device 1 is not visible');
        $this->assertSame([7], array_keys($inventory->leftOut()), 'only visible devices are listed as left out');
    }

    public function testAnsibleJson(): void
    {
        $json = $this->build([3 => true, 12 => true])->toAnsible();

        $this->assertSame(['acc-sw1', 'rtr12.example.net'], array_keys($json['_meta']['hostvars']));
        $this->assertSame(['hosts' => ['acc-sw1']], $json['os_ios']);
        $this->assertContains('location_building_b', $json['all']['children']);
        $this->assertSame(array_keys(array_diff_key($json, ['_meta' => 1, 'all' => 1])), $json['all']['children']);
    }

    public function testEmptyInventoryHasObjectsWhereAnsibleWantsThem(): void
    {
        $inventory = $this->build([]);

        $this->assertSame('{"_meta":{"hostvars":{}},"all":{"children":[]}}', json_encode($inventory->toAnsible()));
        $this->assertSame('{}', json_encode($inventory->hostVars('nope')));
    }

    public function testCustomMappingAndUnmappedOs(): void
    {
        $inventory = $this->build(null, null, "linux community.general.none ssh\nios cisco.ios.ios\niosxe -");

        $this->assertSame('srv1.example.net', $inventory->nameOf(8));
        $this->assertSame('ansible.builtin.ssh', $inventory->hosts()['srv1.example.net']['ansible_connection']);
        $this->assertSame('no_mapping', $inventory->leftOut()[1]['reason'], 'iosxe is switched off in this mapping');
        $this->assertArrayNotHasKey('ansible_become', $inventory->hosts()['acc-sw1']);
    }

    public function testAddress(): void
    {
        $this->assertSame('10.1.1.1', InventoryBuilder::address(['hostname' => 'x', 'overwrite_ip' => ' 10.1.1.1 ']));
        $this->assertSame('fe80::1', InventoryBuilder::address(['hostname' => 'fe80::1']));
        $this->assertSame('::1', InventoryBuilder::address(['hostname' => '::1']));
        $this->assertNull(InventoryBuilder::address(['hostname' => 'fe80::1%eth0']));
        $this->assertNull(InventoryBuilder::address(['hostname' => '-oProxyCommand=x']));
        $this->assertNull(InventoryBuilder::address(['hostname' => '']));
    }
}
