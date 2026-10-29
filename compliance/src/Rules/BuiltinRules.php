<?php

namespace Agoodley\LibrenmsCompliance\Rules;

use Agoodley\LibrenmsCompliance\Config\Config;
use Agoodley\LibrenmsCompliance\Config\Dialect;
use Agoodley\LibrenmsCompliance\Config\Line;

/**
 * The plugin's rules, for the configurations of Cisco IOS and IOS-XE, NX-OS and Arista EOS (Config\Dialect): the
 * spanning tree of the switch ports (edge ports with portfast and BPDU guard, no portfast toward a switch, root guard
 * toward the switches below), and the baselines of management (AAA with a server group, NTP, syslog, no SNMPv1/v2c
 * community, SNMPv3 with encryption, SSH only). What a port faces comes from LibreNMS (PortRoles). The servers NTP,
 * syslog and AAA must use come from the settings; without them, at least one is enough.
 */
final class BuiltinRules
{
    /** id => [title, severity, what it checks, how to fix a failure] */
    public const RULES = [
        'stp-edge-portfast' => ['Edge ports have portfast', Rule::WARNING,
            'Access ports that face a host (no switch LibreNMS polls as their LLDP/CDP neighbor) start forwarding at once, without a topology change '
                . 'each time a host connects: portfast on the port, or the global default for access ports (on EOS, its automatic edge detection).',
            'IOS: spanning-tree portfast (IOS-XE: spanning-tree portfast edge) on these ports, or spanning-tree portfast [edge] default. NX-OS: spanning-tree '
                . 'port type edge, or spanning-tree port type edge default. EOS: spanning-tree portfast, or remove no spanning-tree portfast auto.'],
        'stp-edge-bpduguard' => ['Edge ports have BPDU guard', Rule::WARNING,
            'Access ports that face a host shut down when a switch is plugged into them: BPDU guard on the port, or the global default for edge ports.',
            'IOS: spanning-tree bpduguard enable on these ports, or spanning-tree portfast [edge] bpduguard default. NX-OS: spanning-tree bpduguard '
                . 'enable, or spanning-tree port type edge bpduguard default. EOS: spanning-tree bpduguard enable, or spanning-tree edge-port bpduguard default.'],
        'stp-portfast-switch' => ['No portfast toward a switch', Rule::CRITICAL,
            'A port that faces a switch (its LLDP/CDP neighbor) must not be an edge port: with portfast it forwards before spanning tree has run, and a '
                . 'second path to that switch loops until it has.',
            'Remove portfast from these ports (IOS: no spanning-tree portfast, or spanning-tree portfast disable where a global default applies; NX-OS: '
                . 'spanning-tree port type normal; EOS: no spanning-tree portfast).'],
        'stp-root-guard' => ['Root guard toward the switches below', Rule::WARNING,
            'A port toward a switch that reaches the root bridge through this device (its root port faces the port) keeps that switch from becoming the '
                . 'root: root guard blocks the port while it offers a better root.',
            'spanning-tree guard root on these ports (on the port-channel of a bundle).'],
        'aaa' => ['AAA through servers', Rule::WARNING,
            'Logins are authenticated by a TACACS+ or RADIUS server group (aaa authentication login default group ...; IOS: with aaa new-model), and the '
                . 'servers the settings name are configured.',
            'aaa new-model (IOS), aaa authentication login default group <tacacs+|radius|group> local, and the servers (tacacs server / radius server, or '
                . 'tacacs-server host / radius-server host).'],
        'ntp' => ['NTP servers', Rule::WARNING,
            'The device keeps its clock from NTP servers: at least one, and each one the settings name.',
            'ntp server <address> for each server.'],
        'syslog' => ['Syslog servers', Rule::INFO,
            'The device sends its log to syslog servers: at least one, and each one the settings name.',
            'IOS: logging host <address>. NX-OS: logging server <address>. EOS: logging host <address>.'],
        'snmp-community' => ['No SNMPv1/v2c community', Rule::WARNING,
            'SNMPv1 and v2c send their community in clear: the device has none (LibreNMS polls it with SNMPv3).',
            'Poll the device with SNMPv3 in LibreNMS, then remove each snmp-server community.'],
        'snmp-v3-priv' => ['SNMPv3 with encryption', Rule::WARNING,
            'Every SNMPv3 group (NX-OS: user) authenticates and encrypts (priv): with noauth or auth only, what SNMP reads and writes goes in clear.',
            'IOS and EOS: snmp-server group <group> v3 priv. NX-OS: snmp-server user <user> <role> auth sha <secret> priv aes-128 <secret>.'],
        'ssh-only' => ['SSH only, no telnet', Rule::WARNING,
            'Remote logins use SSH: IOS: every line vty says which protocols it takes (transport input), none of them telnet; NX-OS: no feature telnet; '
                . 'EOS: management telnet stays shut down.',
            'IOS: transport input ssh on each line vty. NX-OS: no feature telnet. EOS: management telnet, shutdown.'],
    ];

    /** The spanning tree rules: they read the switch ports and what LibreNMS says they face. */
    public const STP = ['stp-edge-portfast', 'stp-edge-bpduguard', 'stp-portfast-switch', 'stp-root-guard'];

    /** Most ports a failure names in its message (all are in its list of ports). */
    private const NAMED = 8;

    /** The servers in the configuration: dialect => [pattern, ...], each with the address as its first group. */
    private const NTP = [
        Dialect::IOS => ['~^ntp server (?:vrf \S+ )?(?:ip |ipv6 )?(\S+)~i'],
        Dialect::NXOS => ['~^ntp server (\S+)~i'],
        Dialect::EOS => ['~^ntp server (?:vrf \S+ )?(\S+)~i'],
    ];

    private const SYSLOG = [
        Dialect::IOS => ['~^logging host (?:vrf \S+ )?(?:ipv6 )?(\S+)~i', '~^logging (\d{1,3}(?:\.\d{1,3}){3})(?:\s|$)~'],
        Dialect::NXOS => ['~^logging server (\S+)~i'],
        Dialect::EOS => ['~^logging (?:vrf \S+ )?host (\S+)~i'],
    ];

    private const AAA_HOSTS = ['~^(?:tacacs|radius)-server host (\S+)~i'];

    /**
     * @param  array{ntp_servers?: string[], syslog_servers?: string[], aaa_servers?: string[]}  $parameters  what the settings name
     */
    public function __construct(private readonly array $parameters = [])
    {
    }

    /**
     * The plugin's rules.
     *
     * @return array<string, Rule>
     */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::RULES as $id => [$title, $severity, $description, $fix]) {
            $rules[$id] = new Rule($id, $title, $severity, $description, $fix);
        }

        return $rules;
    }

    /**
     * How one of the plugin's rules ends on a device.
     */
    public function evaluate(string $id, Context $context): Outcome
    {
        $dialect = $context->dialect();
        if ($dialect === null) {
            return Outcome::na('the plugin\'s rules read ' . implode(', ', Dialect::NAMES) . ' configurations, not those of ' . ($context->os ?: 'an unknown os'));
        }

        return match ($id) {
            'stp-edge-portfast' => $this->edge($context, 'portfast', 'without portfast'),
            'stp-edge-bpduguard' => $this->edge($context, 'bpduguard', 'without BPDU guard'),
            'stp-portfast-switch' => $this->portfastToSwitch($context),
            'stp-root-guard' => $this->rootGuard($context),
            'aaa' => $this->aaa($context, $dialect),
            'ntp' => $this->servers($context->config, self::NTP[$dialect], $this->parameters['ntp_servers'] ?? [], 'NTP server'),
            'syslog' => $this->servers($context->config, self::SYSLOG[$dialect], $this->parameters['syslog_servers'] ?? [], 'syslog server'),
            'snmp-community' => $this->community($context->config),
            'snmp-v3-priv' => $this->snmpPriv($context->config, $dialect),
            'ssh-only' => $this->sshOnly($context->config, $dialect),
            default => Outcome::error("no such rule: $id"),
        };
    }

    /**
     * The access ports facing a host: portfast, or BPDU guard, from the port or a default ("auto" on EOS for portfast).
     */
    private function edge(Context $context, string $setting, string $without): Outcome
    {
        $edge = array_filter($context->stpPorts(), fn (array $port, string $name) => $port['mode'] === 'access' && $context->role($name) === PortRoles::HOST,
            ARRAY_FILTER_USE_BOTH);
        if (! $edge) {
            return Outcome::na('no access port that faces a host');
        }
        $missing = array_filter($edge, fn (array $port) => $port[$setting] === 'off');
        if (! $missing) {
            return Outcome::pass();
        }

        return Outcome::fail(Outcome::count(count($missing), 'access port') . ' facing a host ' . $without . ' (of ' . count($edge) . '): '
            . Outcome::list(array_keys($missing), self::NAMED), array_column($missing, 'line'), array_keys($missing), true);
    }

    private function portfastToSwitch(Context $context): Outcome
    {
        $toSwitch = array_filter($context->stpPorts(), fn (array $port, string $name) => in_array($context->role($name), PortRoles::SWITCHES, true),
            ARRAY_FILTER_USE_BOTH);
        if (! $toSwitch) {
            return Outcome::na('no port that faces a switch');
        }
        $edge = array_filter($toSwitch, fn (array $port) => in_array($port['portfast'], ['on', 'default'], true));
        if (! $edge) {
            return Outcome::pass();
        }
        $named = array_map(fn (string $name) => $name . self::to($context->neighbors($name)) . ($edge[$name]['portfast'] === 'default' ? ' (by the global default)' : ''),
            array_keys($edge));

        return Outcome::fail(Outcome::count(count($edge), 'port') . ' facing a switch with portfast: ' . Outcome::list($named, self::NAMED),
            array_column($edge, 'line'), array_keys($edge), true);
    }

    private function rootGuard(Context $context): Outcome
    {
        $below = array_filter($context->stpPorts(), fn (array $port, string $name) => $context->role($name) === PortRoles::DOWNSTREAM, ARRAY_FILTER_USE_BOTH);
        if (! $below) {
            return Outcome::na('no port toward a switch that reaches the root bridge through this device');
        }
        $missing = array_filter($below, fn (array $port) => ! $port['rootguard']);
        if (! $missing) {
            return Outcome::pass();
        }
        $named = array_map(fn (string $name) => $name . self::to($context->neighbors($name)), array_keys($missing));

        return Outcome::fail(Outcome::count(count($missing), 'port') . ' toward a switch below without root guard: ' . Outcome::list($named, self::NAMED),
            array_column($missing, 'line'), array_keys($missing), true);
    }

    private function aaa(Context $context, string $dialect): Outcome
    {
        $config = $context->config;
        $problems = [];
        $lines = [];
        if ($dialect === Dialect::IOS && $config->first('~^aaa new-model$~i') === null) {
            $problems[] = 'no aaa new-model';
        }
        $login = $config->first('~^aaa authentication login default\b~i');
        if ($login === null) {
            $problems[] = 'no aaa authentication login default';
        } elseif (preg_match('~\bgroup\s+\S+~i', $login->text) !== 1) {
            $problems[] = 'logins are not authenticated by a server group';
            $lines[] = $login;
        }
        $servers = [];
        foreach ($config->matches(self::AAA_HOSTS[0]) as [$line, $m]) {
            $servers[strtolower($m[1])] = $line;
        }
        // IOS: tacacs server NAME / radius server NAME, address ipv4 <address>
        foreach ($config->sections('~^(?:tacacs|radius) server \S+~i') as $section) {
            foreach ($section->children as $child) {
                if (preg_match('~^address ipv[46] (\S+)~i', $child->text, $m) === 1) {
                    $servers[strtolower($m[1])] = $child;
                }
            }
        }
        $missing = array_values(array_filter($this->parameters['aaa_servers'] ?? [], fn (string $server) => ! isset($servers[strtolower($server)])));
        if ($missing) {
            $problems[] = 'not configured: ' . Outcome::list($missing);
        }

        return $problems ? Outcome::fail('AAA: ' . implode('; ', $problems), $lines) : Outcome::pass();
    }

    /**
     * Servers of a kind (NTP, syslog): at least one, and each the settings name.
     *
     * @param  string[]  $patterns
     * @param  string[]  $expected
     */
    private function servers(Config $config, array $patterns, array $expected, string $what): Outcome
    {
        $found = [];
        foreach ($patterns as $pattern) {
            foreach ($config->matches($pattern) as [$line, $m]) {
                $found[strtolower($m[1])] = $line;
            }
        }
        if (! $found) {
            return Outcome::fail('no ' . $what . ($expected ? ' (the settings name ' . Outcome::list($expected) . ')' : ''));
        }
        $missing = array_values(array_filter($expected, fn (string $server) => ! isset($found[strtolower($server)])));
        if ($missing) {
            return Outcome::fail(ucfirst($what) . ($missing ? (count($missing) === 1 ? ' ' : 's ') : ' ') . 'not configured: ' . Outcome::list($missing)
                . ' (configured: ' . Outcome::list(array_keys($found)) . ')', array_values($found));
        }

        return Outcome::pass();
    }

    private function community(Config $config): Outcome
    {
        $lines = array_column($config->matches('~^snmp-server community \S+~i'), 0);

        return $lines ? Outcome::fail(Outcome::count(count($lines), 'SNMPv1/v2c community', 'SNMPv1/v2c communities') . ' configured', $lines) : Outcome::pass();
    }

    private function snmpPriv(Config $config, string $dialect): Outcome
    {
        if ($dialect === Dialect::NXOS) {
            $users = array_filter(array_column($config->matches('~^snmp-server user \S+ .*\bauth\b~i'), 0));
            if (! $users) {
                return Outcome::na('no SNMPv3 user');
            }
            $plain = array_values(array_filter($users, fn (Line $line) => preg_match('~\bpriv\b~i', $line->text) !== 1));

            return $plain ? Outcome::fail(Outcome::count(count($plain), 'SNMPv3 user') . ' without encryption (priv)', $plain) : Outcome::pass();
        }
        $groups = $config->matches('~^snmp-server group \S+ v3 (noauth|auth|priv)\b~i');
        if (! $groups) {
            return Outcome::na('no SNMPv3 group');
        }
        $plain = array_values(array_map(fn (array $match) => $match[0], array_filter($groups, fn (array $match) => strtolower($match[1][1]) !== 'priv')));

        return $plain ? Outcome::fail(Outcome::count(count($plain), 'SNMPv3 group') . ' without encryption (noauth or auth only)', $plain) : Outcome::pass();
    }

    private function sshOnly(Config $config, string $dialect): Outcome
    {
        if ($dialect === Dialect::NXOS) {
            $telnet = $config->first('~^feature telnet$~i');

            return $telnet !== null ? Outcome::fail('telnet is on (feature telnet)', [$telnet]) : Outcome::pass();
        }
        if ($dialect === Dialect::EOS) {
            $section = $config->first('~^management telnet$~i');
            $on = $section?->find('~^no shutdown$~i');

            return $on !== null ? Outcome::fail('telnet is on (management telnet, no shutdown)', [$section, $on]) : Outcome::pass();
        }
        $lines = $config->sections('~^line vty\b~i');
        if (! $lines) {
            return Outcome::na('no line vty');
        }
        $open = [];
        foreach ($lines as $line) {
            $transport = $line->find('~^transport input\b~i');
            $protocols = $transport !== null ? preg_split('~\s+~', strtolower(trim(substr($transport->text, strlen('transport input'))))) : [];
            if ($transport === null) {
                $open[] = [$line, $line->text . ' (no transport input: the IOS default may take telnet)'];
            } elseif (array_intersect($protocols, ['telnet', 'all'])) {
                $open[] = [$line, $line->text . ' (transport input ' . implode(' ', $protocols) . ')'];
            }
        }

        return $open ? Outcome::fail('telnet may log in on ' . Outcome::list(array_column($open, 1)), array_column($open, 0), [], true) : Outcome::pass();
    }

    /**
     * " (to core-sw1 Gi1/0/49)", or nothing.
     *
     * @param  string[]  $neighbors
     */
    private static function to(array $neighbors): string
    {
        return $neighbors ? ' (to ' . Outcome::list($neighbors, 2) . ')' : '';
    }
}
