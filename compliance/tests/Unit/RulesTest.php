<?php

namespace Agoodley\LibrenmsCompliance\Tests\Unit;

use Agoodley\LibrenmsCompliance\Config\Config;
use Agoodley\LibrenmsCompliance\Rules\BuiltinRules;
use Agoodley\LibrenmsCompliance\Rules\Checker;
use Agoodley\LibrenmsCompliance\Rules\Context;
use Agoodley\LibrenmsCompliance\Rules\CustomRules;
use Agoodley\LibrenmsCompliance\Rules\Outcome;
use Agoodley\LibrenmsCompliance\Rules\PortRoles;
use Agoodley\LibrenmsCompliance\Rules\Rule;
use PHPUnit\Framework\TestCase;

/**
 * The plugin's rules and the admin's on the configurations of an IOS access switch, an NX-OS distribution switch and an
 * EOS leaf, with what LibreNMS says their ports face: a host, a switch above (the root port), a switch below (its root
 * port faces this one), a peer.
 */
class RulesTest extends TestCase
{
    /**
     * acc-sw1 (device 2): Po1 (Gi1/0/47-48) up to core-sw1 (1), Gi1/0/5 down to acc-sw2 (3), Gi1/0/6 to a server LibreNMS
     * does not monitor, Gi1/0/1 to a phone.
     */
    private static function ios(array $parameters = []): array
    {
        $ports = [100 => ['ifName' => 'Po1', 'ifDescr' => 'Port-channel1'], 101 => ['ifName' => 'Gi1/0/1', 'ifDescr' => 'GigabitEthernet1/0/1'],
            102 => ['ifName' => 'Gi1/0/2', 'ifDescr' => 'GigabitEthernet1/0/2'], 103 => ['ifName' => 'Gi1/0/3', 'ifDescr' => 'GigabitEthernet1/0/3'],
            104 => ['ifName' => 'Gi1/0/4', 'ifDescr' => 'GigabitEthernet1/0/4'], 105 => ['ifName' => 'Gi1/0/5', 'ifDescr' => 'GigabitEthernet1/0/5'],
            106 => ['ifName' => 'Gi1/0/6', 'ifDescr' => 'GigabitEthernet1/0/6'], 147 => ['ifName' => 'Gi1/0/47', 'ifDescr' => 'GigabitEthernet1/0/47'],
            148 => ['ifName' => 'Gi1/0/48', 'ifDescr' => 'GigabitEthernet1/0/48']];
        $links = [
            ['local_port_id' => 147, 'remote_device_id' => 1, 'remote_port_id' => 11, 'remote_hostname' => 'core-sw1', 'remote_port' => 'Te1/1/1'],
            ['local_port_id' => 148, 'remote_device_id' => 1, 'remote_port_id' => 12, 'remote_hostname' => 'core-sw1', 'remote_port' => 'Te1/1/2'],
            ['local_port_id' => 105, 'remote_device_id' => 3, 'remote_port_id' => 349, 'remote_hostname' => 'acc-sw2', 'remote_port' => 'Gi1/0/49'],
            ['local_port_id' => 106, 'remote_device_id' => null, 'remote_port_id' => null, 'remote_hostname' => 'esx1', 'remote_port' => 'vmnic0'],
            ['local_port_id' => 101, 'remote_device_id' => null, 'remote_port_id' => null, 'remote_hostname' => 'SEP001122334455', 'remote_port' => 'Port 1'],
        ];
        $roles = PortRoles::compute(2, $links, [1 => true, 2 => true, 3 => true], [2 => [100], 3 => [349], 1 => []], [147 => 100, 148 => 100],
            [1 => 'core-sw1', 3 => 'acc-sw2']);

        return [new Context(Config::parse(ConfigTest::fixture('ios-access')), 'ios', $ports, $roles), new BuiltinRules($parameters)];
    }

    public function testWhatEachPortFaces(): void
    {
        [$context] = self::ios();

        $this->assertSame(PortRoles::UPSTREAM, $context->role('Port-channel1'), 'the root port, a port-channel');
        $this->assertSame(PortRoles::UPSTREAM, $context->role('GigabitEthernet1/0/47'), 'a member of the root port');
        $this->assertSame(PortRoles::DOWNSTREAM, $context->role('GigabitEthernet1/0/5'), 'acc-sw2 reaches the root through it');
        $this->assertSame(PortRoles::HOST, $context->role('GigabitEthernet1/0/6'), 'a neighbor LibreNMS does not poll');
        $this->assertSame(PortRoles::HOST, $context->role('GigabitEthernet1/0/3'), 'no neighbor');
        $this->assertSame(['acc-sw2 Gi1/0/49'], $context->neighbors('GigabitEthernet1/0/5'));
        $this->assertSame(['core-sw1 Te1/1/1', 'core-sw1 Te1/1/2'], $context->neighbors('Port-channel1'));

        $peer = PortRoles::compute(5, [['local_port_id' => 501, 'remote_device_id' => 6, 'remote_port_id' => 601, 'remote_hostname' => 'leaf2', 'remote_port' => 'Et4']],
            [5 => true, 6 => true], [5 => [503], 6 => [603]], [], [6 => 'leaf2']);
        $this->assertSame(PortRoles::SWITCH, $peer[501]['role'], 'neither side\'s root port: a peer link');
    }

    public function testTheRulesOnAnIosAccessSwitch(): void
    {
        [$context, $builtin] = self::ios();
        $outcome = fn (string $id) => $builtin->evaluate($id, $context);

        $portfast = $outcome('stp-edge-portfast');
        $this->assertSame([Rule::FAIL, '1 access port facing a host without portfast (of 3): GigabitEthernet1/0/3'], [$portfast->status, $portfast->message],
            'a shut port, a trunk and the port to the server are no edge ports');
        $this->assertSame(['GigabitEthernet1/0/3'], $portfast->ports);
        $this->assertSame([[60, 'interface GigabitEthernet1/0/3'], [61, ' description printer'], [62, ' switchport access vlan 10'], [63, ' switchport mode access']],
            $portfast->evidence, 'the port\'s lines');

        $this->assertSame('2 access ports facing a host without BPDU guard (of 3): GigabitEthernet1/0/2 and GigabitEthernet1/0/3', $outcome('stp-edge-bpduguard')->message);
        $this->assertSame([Rule::FAIL, '1 port facing a switch with portfast: GigabitEthernet1/0/5 (to acc-sw2 Gi1/0/49)'],
            [$outcome('stp-portfast-switch')->status, $outcome('stp-portfast-switch')->message]);
        $this->assertSame('1 port toward a switch below without root guard: GigabitEthernet1/0/5 (to acc-sw2 Gi1/0/49)', $outcome('stp-root-guard')->message,
            'not the uplink');
        $this->assertSame(Rule::PASS, $outcome('aaa')->status);
        $this->assertSame(Rule::PASS, $outcome('ntp')->status);
        $this->assertSame(Rule::PASS, $outcome('syslog')->status);
        $community = $outcome('snmp-community');
        $this->assertSame([Rule::FAIL, '1 SNMPv1/v2c community configured', [[103, 'snmp-server community <hidden> RO 10']]],
            [$community->status, $community->message, $community->evidence], 'never the community itself');
        $this->assertSame([Rule::FAIL, '1 SNMPv3 group without encryption (noauth or auth only)', [[105, 'snmp-server group LEGACY v3 auth']]],
            [$outcome('snmp-v3-priv')->status, $outcome('snmp-v3-priv')->message, $outcome('snmp-v3-priv')->evidence]);
        $ssh = $outcome('ssh-only');
        $this->assertSame([Rule::FAIL, 'telnet may log in on line vty 5 15 (transport input telnet ssh)'], [$ssh->status, $ssh->message]);
        $this->assertSame([[121, 'line vty 5 15'], [122, ' transport input telnet ssh']], $ssh->evidence, 'the line and its lines');
    }

    public function testTheServersTheSettingsName(): void
    {
        [$context, $builtin] = self::ios(['ntp_servers' => ['10.0.0.10', '192.0.2.1'], 'syslog_servers' => ['10.0.0.50'], 'aaa_servers' => ['10.0.0.40', '10.0.0.41']]);

        $ntp = $builtin->evaluate('ntp', $context);
        $this->assertSame([Rule::FAIL, 'NTP server not configured: 192.0.2.1 (configured: 10.0.0.10 and 10.0.0.11)'], [$ntp->status, $ntp->message],
            'the banner\'s line does not count');
        $this->assertSame(Rule::PASS, $builtin->evaluate('syslog', $context)->status);
        $this->assertSame('AAA: not configured: 10.0.0.41', $builtin->evaluate('aaa', $context)->message, 'tacacs server TAC1 has 10.0.0.40');
    }

    public function testTheRulesOnAnNxosDistributionSwitch(): void
    {
        $ports = [1010 => ['ifName' => 'Po10', 'ifDescr' => 'port-channel10'], 1001 => ['ifName' => 'Eth1/1', 'ifDescr' => 'Ethernet1/1'],
            1002 => ['ifName' => 'Eth1/2', 'ifDescr' => 'Ethernet1/2'], 1003 => ['ifName' => 'Eth1/3', 'ifDescr' => 'Ethernet1/3'],
            1004 => ['ifName' => 'Eth1/4', 'ifDescr' => 'Ethernet1/4']];
        $links = [['local_port_id' => 1001, 'remote_device_id' => 2, 'remote_port_id' => 147, 'remote_hostname' => 'acc-sw1', 'remote_port' => 'Gi1/0/47'],
            ['local_port_id' => 1003, 'remote_device_id' => 4, 'remote_port_id' => 449, 'remote_hostname' => 'acc-sw4', 'remote_port' => 'Gi1/0/49']];
        $roles = PortRoles::compute(10, $links, [2 => true, 4 => true, 10 => true], [10 => [], 2 => [100], 4 => [449]], [1001 => 1010, 147 => 100], []);
        $context = new Context(Config::parse(ConfigTest::fixture('nxos-dist')), 'nxos', $ports, $roles);
        $builtin = new BuiltinRules();

        $this->assertSame(PortRoles::DOWNSTREAM, $context->role('port-channel10'), 'acc-sw1\'s root port is its port-channel, whose member faces this one');
        $this->assertSame([Rule::FAIL, '1 port toward a switch below without root guard: Ethernet1/3 (to acc-sw4 Gi1/0/49)'],
            [$builtin->evaluate('stp-root-guard', $context)->status, $builtin->evaluate('stp-root-guard', $context)->message], 'port-channel10 has it');
        $this->assertSame(Rule::PASS, $builtin->evaluate('stp-edge-portfast', $context)->status, 'the global default');
        $this->assertSame('1 access port facing a host without BPDU guard (of 2): Ethernet1/4', $builtin->evaluate('stp-edge-bpduguard', $context)->message);
        $this->assertSame(Rule::PASS, $builtin->evaluate('stp-portfast-switch', $context)->status);
        $this->assertSame(Rule::PASS, $builtin->evaluate('aaa', $context)->status, 'no aaa new-model on NX-OS');
        $this->assertSame(Rule::PASS, $builtin->evaluate('syslog', $context)->status);
        $this->assertSame(Rule::PASS, $builtin->evaluate('snmp-community', $context)->status);
        $priv = $builtin->evaluate('snmp-v3-priv', $context);
        $this->assertSame([Rule::FAIL, '1 SNMPv3 user without encryption (priv)'], [$priv->status, $priv->message]);
        $this->assertSame('snmp-server user monitor network-operator auth sha <hidden> localizedkey', $priv->evidence[0][1]);
        $this->assertSame(Rule::PASS, $builtin->evaluate('ssh-only', $context)->status);
    }

    public function testTheRulesOnAnEosLeaf(): void
    {
        $ports = [701 => ['ifName' => 'Ethernet1'], 702 => ['ifName' => 'Ethernet2'], 703 => ['ifName' => 'Ethernet3'], 704 => ['ifName' => 'Ethernet4']];
        $links = [['local_port_id' => 703, 'remote_device_id' => 8, 'remote_port_id' => 801, 'remote_hostname' => 'spine1', 'remote_port' => 'Ethernet1'],
            ['local_port_id' => 704, 'remote_device_id' => 9, 'remote_port_id' => 904, 'remote_hostname' => 'leaf2', 'remote_port' => 'Ethernet4']];
        $roles = PortRoles::compute(7, $links, [7 => true, 8 => true, 9 => true], [7 => [703], 8 => [], 9 => [903]], [], []);
        $context = new Context(Config::parse(ConfigTest::fixture('eos-leaf')), 'arista_eos', $ports, $roles);
        $builtin = new BuiltinRules();

        $this->assertSame('1 access port facing a host without portfast (of 2): Ethernet2', $builtin->evaluate('stp-edge-portfast', $context)->message,
            'Ethernet1 is an edge port by EOS\' automatic detection');
        $this->assertSame('1 access port facing a host without BPDU guard (of 2): Ethernet2', $builtin->evaluate('stp-edge-bpduguard', $context)->message);
        $this->assertSame('1 port facing a switch with portfast: Ethernet4 (to leaf2 Ethernet4)', $builtin->evaluate('stp-portfast-switch', $context)->message);
        $this->assertSame(Rule::NA, $builtin->evaluate('stp-root-guard', $context)->status);
        $this->assertSame('AAA: logins are not authenticated by a server group', $builtin->evaluate('aaa', $context)->message);
        $this->assertSame('no NTP server', $builtin->evaluate('ntp', $context)->message);
        $this->assertSame('no syslog server', $builtin->evaluate('syslog', $context)->message, 'not the banner\'s line');
        $this->assertSame(Rule::NA, $builtin->evaluate('snmp-v3-priv', $context)->status);
        $this->assertSame('telnet is on (management telnet, no shutdown)', $builtin->evaluate('ssh-only', $context)->message);

        $junos = new Context(Config::parse("set system host-name r1\n"), 'junos');
        $this->assertSame(Rule::NA, $builtin->evaluate('ntp', $junos)->status, 'the plugin\'s rules do not read Junos');
    }

    public function testRulesOfTheAdmins(): void
    {
        $yaml = <<<'YAML'
            - id: http-server-off
              title: HTTP server off
              os: [ios, iosxe]
              must_not: '^ip http server$'
            - id: no-http
              title: HTTP servers off
              must: ['^no ip http server$', '^no ip http secure-server$']
            - id: storm-control
              title: Storm control on access ports
              severity: critical
              section: '^interface '
              when: '^switchport mode access$'
              unless: '^shutdown$'
              ports: host
              must: '^storm-control broadcast level'
              fix: storm-control broadcast level 1.00
            - id: vty-acl
              title: VTY lines have an access class
              section: '/^line vty/'
              must: '^access-class \S+ in'
            YAML;
        $parsed = CustomRules::parse($yaml);
        $this->assertSame([], $parsed['errors']);
        $this->assertSame(['http-server-off', 'no-http', 'storm-control', 'vty-acl'], array_keys($parsed['rules']));
        $this->assertSame([Rule::CRITICAL, 'storm-control broadcast level 1.00', true], [$parsed['rules']['storm-control']->severity, $parsed['rules']['storm-control']->fix,
            $parsed['rules']['storm-control']->custom]);

        [$context] = self::ios();
        $outcomes = (new Checker($parsed['rules']))->check($context);
        $this->assertSame(Rule::PASS, $outcomes['http-server-off']->status);
        $this->assertSame(Rule::PASS, $outcomes['no-http']->status);
        $this->assertSame([Rule::FAIL, '3 sections of 3 fail, no line matches ^storm-control broadcast level: interface GigabitEthernet1/0/1, '
            . 'interface GigabitEthernet1/0/2 and interface GigabitEthernet1/0/3'],
            [$outcomes['storm-control']->status, $outcomes['storm-control']->message], 'the shut port is left out');
        $this->assertSame(['GigabitEthernet1/0/1', 'GigabitEthernet1/0/2', 'GigabitEthernet1/0/3'], $outcomes['storm-control']->ports);
        $this->assertSame([Rule::FAIL, Rule::NA], [$outcomes['vty-acl']->status, (new Checker($parsed['rules']))->check(new Context(Config::parse("hostname x\n"), 'ios'))['vty-acl']->status]);

        $telnet = CustomRules::parse("- id: t\n  title: no telnet\n  section: '^line vty'\n  must_not: 'transport input .*telnet'\n")['rules'];
        $outcome = (new Checker($telnet))->check($context)['t'];
        $this->assertSame([Rule::FAIL, '1 section of 2 fails, a line matches transport input .*telnet: line vty 5 15'], [$outcome->status, $outcome->message]);

        $nxos = new Context(Config::parse(ConfigTest::fixture('nxos-dist')), 'nxos');
        $this->assertSame(Rule::NA, (new Checker($parsed['rules']))->check($nxos)['http-server-off']->status, 'for ios and iosxe only');
    }

    public function testRulesWithMistakesAreRefusedWithWhy(): void
    {
        $errors = fn (string $yaml) => CustomRules::parse($yaml)['errors'];

        $this->assertSame(['The rules must be a YAML list: each rule starts with "- id: ...".'], $errors("id: x\n"));
        $this->assertStringStartsWith('The rules are not valid YAML', $errors("- id: [x\n")[0]);
        $this->assertSame(['Rule 1 (ntp): ntp is one of the plugin\'s rules: choose another id.'], $errors("- id: ntp\n  title: t\n  must: x\n"));
        $this->assertSame(['Rule 1 (x): unknown key mustnot (the keys are id, title, severity, description, os, section, ports, when, unless, must, must_not, fix).',
            'Rule 1 (x): a rule needs must or must_not.'], $errors("- id: x\n  title: t\n  mustnot: y\n"));
        $this->assertSame(['Rule 1 (x): must: the pattern ( does not compile.'], $errors("- id: x\n  title: t\n  must: '('\n"));
        $this->assertSame(['Rule 1 (x): ports needs a section (of interfaces: section: \'^interface \').'], $errors("- id: x\n  title: t\n  ports: host\n  must: y\n"));
        $this->assertSame(['Rule 1 (x): severity must be critical, warning, info.'], $errors("- id: x\n  title: t\n  severity: high\n  must: y\n"));
        $this->assertSame(['Rule 2 (x): another rule has the id x.'], $errors("- id: x\n  title: t\n  must: y\n- id: x\n  title: u\n  must: z\n"));
        $this->assertSame([], CustomRules::parse('')['rules']);
    }

    public function testARuleWhosePatternFailsEndsAsAnError(): void
    {
        $rules = CustomRules::parse("- id: slow\n  title: slow\n  must: '/(a+)+\$/'\n")['rules'];
        $config = Config::parse(str_repeat('a', 5000) . "b\n");
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000');
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        try {
            $outcome = (new Checker($rules))->check(new Context($config, 'ios'))['slow'];
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
            ini_set('pcre.jit', (string) $jit);
        }
        $this->assertSame(Rule::ERROR, $outcome->status);
        $this->assertStringContainsString('failed on a line of the configuration (Backtrack limit exhausted)', $outcome->message);
    }

    public function testTheFactsOfEachPort(): void
    {
        [$context] = self::ios();
        $ports = Checker::ports($context);

        $this->assertSame(['name' => 'GigabitEthernet1/0/5', 'port_id' => 105, 'mode' => 'trunk', 'shutdown' => false, 'member' => null, 'portfast' => 'on',
            'bpduguard' => 'off', 'rootguard' => false, 'role' => PortRoles::DOWNSTREAM, 'neighbors' => ['acc-sw2 Gi1/0/49']], $ports[5]);
        $this->assertSame('Port-channel1', $ports[0]['name']);
        $this->assertSame('A, B and C', Outcome::list(['A', 'B', 'C']));
        $this->assertSame('A, B and 1 more', Outcome::list(['A', 'B', 'C'], 2));
    }
}
