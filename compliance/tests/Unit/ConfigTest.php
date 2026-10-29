<?php

namespace Agoodley\LibrenmsCompliance\Tests\Unit;

use Agoodley\LibrenmsCompliance\Config\Config;
use Agoodley\LibrenmsCompliance\Config\Dialect;
use Agoodley\LibrenmsCompliance\Config\InterfaceNames;
use Agoodley\LibrenmsCompliance\Config\Secrets;
use PHPUnit\Framework\TestCase;

/**
 * Configurations as Oxidized backs them up, read in blocks: comments, banners and macros left out, the switch ports of
 * each dialect, the LibreNMS port of each interface, and secrets hidden in the lines the plugin shows.
 */
class ConfigTest extends TestCase
{
    public static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/' . $name . '.cfg');
    }

    public function testBlocksWithoutCommentsBannersAndMacros(): void
    {
        $config = Config::parse(self::fixture('ios-access'));

        $this->assertSame('version 15.2', $config->top[0]->text, 'Oxidized\'s header is comments');
        $this->assertSame(7, $config->top[0]->number, 'lines keep their number in the backup');
        $port = $config->first('~^interface GigabitEthernet1/0/1$~');
        $this->assertSame(['description desk 1', 'switchport access vlan 10', 'switchport mode access', 'spanning-tree portfast', 'spanning-tree bpduguard enable'],
            array_map(fn ($line) => $line->text, $port->children));
        $this->assertSame(['ntp server 10.0.0.10', 'ntp server 10.0.0.11 prefer'], array_map(fn ($m) => $m[0]->text, $config->matches('~^ntp server~')),
            'a banner\'s text is no command');
        $this->assertNotNull($config->first('~^banner motd \^C$~'), 'the banner command itself is configuration');
        $this->assertNull($config->first('~^end$~'));

        $eos = Config::parse(self::fixture('eos-leaf'));
        $this->assertSame([], $eos->matches('~^logging~'), 'EOS: a banner\'s text runs up to EOF');
        $this->assertSame(['shutdown'], array_map(fn ($line) => $line->text, Config::parse("banner motd #one line# \nshutdown\n")->sections('~^shutdown$~')),
            'a banner on one line ends there');

        $macro = Config::parse("macro name desk\nspanning-tree portfast\n@\nhostname x\n");
        $this->assertSame(['macro name desk', 'hostname x'], array_map(fn ($line) => $line->text, $macro->top), 'a macro\'s body is no command');

        $nested = Config::parse("router bgp 65000\n address-family ipv4\n  neighbor 10.0.0.1 activate\n exit-address-family\n");
        $this->assertSame(['address-family ipv4', 'neighbor 10.0.0.1 activate', 'exit-address-family'], array_map(fn ($line) => $line->text, $nested->top[0]->descendants()));
    }

    public function testATooLongBackupIsRefused(): void
    {
        $this->expectException(\LengthException::class);
        Config::parse(str_repeat("x\n", Config::MAX_LINES + 1));
    }

    public function testTheSwitchPortsOfEachDialect(): void
    {
        $ios = Dialect::ports(Dialect::IOS, Config::parse(self::fixture('ios-access')));
        $this->assertSame(['Port-channel1', 'GigabitEthernet1/0/1', 'GigabitEthernet1/0/2', 'GigabitEthernet1/0/3', 'GigabitEthernet1/0/4', 'GigabitEthernet1/0/5',
            'GigabitEthernet1/0/6', 'GigabitEthernet1/0/47', 'GigabitEthernet1/0/48'], array_keys($ios), 'no SVI');
        $facts = fn (array $port) => [$port['mode'], $port['shutdown'], $port['member'], $port['portfast'], $port['bpduguard'], $port['rootguard']];
        $this->assertSame(['access', false, null, 'on', 'on', false], $facts($ios['GigabitEthernet1/0/1']));
        $this->assertSame(['access', false, null, 'off', 'off', false], $facts($ios['GigabitEthernet1/0/3']));
        $this->assertSame(['access', true, null, 'off', 'off', false], $facts($ios['GigabitEthernet1/0/4']));
        $this->assertSame(['trunk', false, null, 'on', 'off', false], $facts($ios['GigabitEthernet1/0/5']), 'portfast trunk works on a trunk');
        $this->assertSame(['trunk', false, '1', 'off', 'off', false], $facts($ios['GigabitEthernet1/0/47']));
        $this->assertTrue($ios['Port-channel1']['channel']);

        $defaults = Dialect::ports(Dialect::IOS, Config::parse("spanning-tree portfast edge default\nspanning-tree portfast edge bpduguard default\n"
            . "interface GigabitEthernet1/0/1\n switchport mode access\ninterface GigabitEthernet1/0/2\n switchport mode access\n spanning-tree portfast disable\n"
            . "interface GigabitEthernet1/0/3\n switchport mode trunk\n spanning-tree portfast\ninterface GigabitEthernet1/0/4\n switchport mode access\n"
            . " spanning-tree bpduguard disable\ninterface TenGigabitEthernet1/1/1\ninterface GigabitEthernet1/0/5.100\n"));
        $this->assertSame(['default', 'default'], [$defaults['GigabitEthernet1/0/1']['portfast'], $defaults['GigabitEthernet1/0/1']['bpduguard']], 'the global defaults');
        $this->assertSame(['off', 'off'], [$defaults['GigabitEthernet1/0/2']['portfast'], $defaults['GigabitEthernet1/0/2']['bpduguard']]);
        $this->assertSame('off', $defaults['GigabitEthernet1/0/3']['portfast'], 'portfast without trunk does nothing on a trunk');
        $this->assertSame(['default', 'off'], [$defaults['GigabitEthernet1/0/4']['portfast'], $defaults['GigabitEthernet1/0/4']['bpduguard']]);
        $this->assertSame('unknown', $defaults['TenGigabitEthernet1/1/1']['mode'], 'IOS\' dynamic mode');
        $this->assertArrayNotHasKey('GigabitEthernet1/0/5.100', $defaults, 'no subinterface');

        $nxos = Dialect::ports(Dialect::NXOS, Config::parse(self::fixture('nxos-dist')));
        $this->assertSame(['port-channel10', 'Ethernet1/1', 'Ethernet1/2', 'Ethernet1/3', 'Ethernet1/4'], array_keys($nxos), 'not mgmt0');
        $this->assertSame(['access', false, null, 'default', 'default', false], $facts($nxos['Ethernet1/2']));
        $this->assertSame(['access', false, null, 'default', 'off', false], $facts($nxos['Ethernet1/4']));
        $this->assertTrue($nxos['port-channel10']['rootguard']);

        $eos = Dialect::ports(Dialect::EOS, Config::parse(self::fixture('eos-leaf')));
        $this->assertSame(['Ethernet1', 'Ethernet2', 'Ethernet3', 'Ethernet4'], array_keys($eos), 'not Management1');
        $this->assertSame(['access', false, null, 'auto', 'default', false], $facts($eos['Ethernet1']), 'EOS\' automatic edge, and the BPDU guard default');
        $this->assertSame(['access', false, null, 'off', 'off', false], $facts($eos['Ethernet2']));
        $this->assertSame(['trunk', false, null, 'on', 'default', false], $facts($eos['Ethernet4']));
        $this->assertNull(Dialect::of('junos'));
        $this->assertSame(Dialect::IOS, Dialect::of('IOSXE'));
    }

    public function testEachInterfaceItsLibrenmsPort(): void
    {
        $names = new InterfaceNames([
            101 => ['ifName' => 'Gi1/0/1', 'ifDescr' => 'GigabitEthernet1/0/1'],
            102 => ['ifName' => 'Gi1/0/2', 'ifDescr' => ''],
            200 => ['ifName' => 'Po1', 'ifDescr' => 'Port-channel1'],
            301 => ['ifName' => 'Eth1/1', 'ifDescr' => 'Ethernet1/1'],
            302 => ['ifName' => 'Eth1/2', 'ifDescr' => ''],
            401 => ['ifName' => 'Tw1/0/1', 'ifDescr' => ''],
            402 => ['ifName' => 'Twe1/0/1', 'ifDescr' => ''],
        ]);
        $this->assertSame(101, $names->port('GigabitEthernet1/0/1'));
        $this->assertSame(101, $names->port('gigabitethernet1/0/1'));
        $this->assertSame(102, $names->port('GigabitEthernet1/0/2'), 'its ifName abbreviates it');
        $this->assertSame(200, $names->port('Port-channel1'));
        $this->assertSame(302, $names->port('Ethernet1/2'));
        $this->assertNull($names->port('GigabitEthernet1/0/9'));
        $this->assertNull($names->port('TwentyFiveGigE1/0/1'), 'two ports fit: none is chosen');
        $this->assertSame(['gigabitethernet', '1/0/1'], InterfaceNames::split('GigabitEthernet1/0/1'));
    }

    public function testSecretsAreHidden(): void
    {
        $cases = [
            'snmp-server community s3cr3t RO 10' => 'snmp-server community <hidden> RO 10',
            'snmp-server host 10.0.0.5 version 2c public' => 'snmp-server host 10.0.0.5 version 2c <hidden>',
            'snmp-server host 10.0.0.5 informs version 3 priv monitor' => 'snmp-server host 10.0.0.5 informs version 3 priv <hidden>',
            'snmp-server user u1 G1 v3 auth sha AuthPass1 priv aes 128 PrivPass1' => 'snmp-server user u1 G1 v3 auth sha <hidden> priv aes 128 <hidden>',
            'snmp-server user admin network-admin auth md5 0x12ab priv 0x34cd localizedkey' => 'snmp-server user admin network-admin auth md5 <hidden> priv <hidden> localizedkey',
            'snmp-server group G1 v3 priv read V1' => 'snmp-server group G1 v3 priv read V1',
            'enable secret 9 $9$abc$def' => 'enable secret 9 <hidden>',
            'enable secret level 15 5 $1$abc$def' => 'enable secret level 15 5 <hidden>',
            'enable password cisco' => 'enable password <hidden>',
            'username netops privilege 15 secret 9 $9$x$y' => 'username netops privilege 15 secret 9 <hidden>',
            'password 7 0822455D0A16' => 'password 7 <hidden>',
            'key 7 0822455D0A16' => 'key 7 <hidden>',
            'tacacs-server host 10.0.0.40 key 7 0822455D0A16' => 'tacacs-server host 10.0.0.40 key 7 <hidden>',
            'tacacs-server key 7 "fewhg123"' => 'tacacs-server key 7 <hidden>',
            'radius-server host 10.0.0.41 auth-port 1812 acct-port 1813 key secret1' => 'radius-server host 10.0.0.41 auth-port 1812 acct-port 1813 key <hidden>',
            'ntp authentication-key 1 md5 104D000A0618 7' => 'ntp authentication-key 1 md5 <hidden> 7',
            'ip ospf authentication-key 7 0822455D0A16' => 'ip ospf authentication-key 7 <hidden>',
            'ip ospf message-digest-key 1 md5 7 0822455D0A16' => 'ip ospf message-digest-key 1 md5 7 <hidden>',
            'crypto isakmp key MyKey address 192.0.2.1' => 'crypto isakmp key <hidden> address 192.0.2.1',
            'pre-shared-key address 192.0.2.1 key MyKey' => 'pre-shared-key address 192.0.2.1 key <hidden>',
            'neighbor 10.0.0.1 password 7 0822455D0A16' => 'neighbor 10.0.0.1 password 7 <hidden>',
            'key-string 7 0822455D0A16' => 'key-string 7 <hidden>',
            'username admin privilege 15 role network-admin secret sha512 $6$abc$def' => 'username admin privilege 15 role network-admin secret sha512 <hidden>',
            'service password-encryption' => 'service password-encryption',
            'spanning-tree portfast' => 'spanning-tree portfast',
            '3082023E 308201A7 A0030201 02020101' => '<hidden> (certificate data)',
        ];
        foreach ($cases as $line => $masked) {
            $this->assertSame($masked, Secrets::mask($line), $line);
        }
    }
}
