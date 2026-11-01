<?php

namespace Agoodley\LibrenmsCompliance\Config;

/**
 * The configuration languages the built-in rules read, by LibreNMS os: Cisco IOS and IOS-XE (ios), Cisco NX-OS (nxos)
 * and Arista EOS (eos). What a switch port is, from its configuration and the global defaults:
 *
 * * its mode: access, trunk, routed, or unknown (a switch port whose configuration says neither: IOS' dynamic modes,
 *   an NX-OS port that may be routed by default);
 * * portfast (an edge port): on the port (IOS "spanning-tree portfast [edge]", NX-OS "spanning-tree port type edge",
 *   EOS "spanning-tree portfast"), on an access port by a global default (IOS "spanning-tree portfast [edge] default",
 *   NX-OS "spanning-tree port type edge default") unless the port turns it off, or EOS' automatic edge detection,
 *   which an EOS port has unless it turns it off ("no spanning-tree portfast auto", or a network port type);
 * * BPDU guard: on the port ("spanning-tree bpduguard enable"), or by a global default for the ports with portfast
 *   (IOS "spanning-tree portfast [edge] bpduguard default", NX-OS "spanning-tree port type edge bpduguard default", EOS
 *   "spanning-tree edge-port bpduguard default") unless the port turns it off ("spanning-tree bpduguard disable");
 * * root guard ("spanning-tree guard root"), whether it is shut down, and the port-channel it is a member of.
 */
final class Dialect
{
    public const IOS = 'ios';

    public const NXOS = 'nxos';

    public const EOS = 'eos';

    /** LibreNMS os => dialect. */
    public const OS = ['ios' => self::IOS, 'iosxe' => self::IOS, 'nxos' => self::NXOS, 'arista_eos' => self::EOS];

    public const NAMES = [self::IOS => 'Cisco IOS / IOS-XE', self::NXOS => 'Cisco NX-OS', self::EOS => 'Arista EOS'];

    /** A physical Ethernet port's section ("interface GigabitEthernet1/0/5"); never a subinterface. */
    private const PHYSICAL = [
        self::IOS => '~^interface ((?:[A-Za-z]*?)(?:Ethernet|GigE))(\d+(?:/\d+)*)$~i',
        self::NXOS => '~^interface (Ethernet)(\d+(?:/\d+)+)$~i',
        self::EOS => '~^interface (Ethernet)(\d+(?:/\d+)*)$~i',
    ];

    /** A port-channel's section. */
    private const CHANNEL = '~^interface (Port-?channel)(\d+)$~i';

    /**
     * The dialect of a LibreNMS os, null for one the built-in rules do not read.
     */
    public static function of(string $os): ?string
    {
        return self::OS[strtolower(trim($os))] ?? null;
    }

    /**
     * The switch ports of a configuration (physical ports and port-channels; routed ports too, with their mode), by
     * the name of their section, with what their configuration says.
     *
     * @return array<string, array{name: string, line: Line, channel: bool, mode: string, shutdown: bool, member: ?string,
     *                portfast: string, bpduguard: string, rootguard: bool}>
     *               portfast: on (the port), default (a global default), auto (EOS), off; bpduguard: on, default, off
     */
    public static function ports(string $dialect, Config $config): array
    {
        $defaults = self::defaults($dialect, $config);
        $ports = [];
        foreach ($config->top as $line) {
            $channel = preg_match(self::CHANNEL, $line->text, $m) === 1;
            if (! $channel && preg_match(self::PHYSICAL[$dialect] ?? '~^$~', $line->text, $m) !== 1) {
                continue;
            }
            $name = trim(substr($line->text, strlen('interface ')));
            $mode = self::mode($dialect, $line);
            $portfast = self::portfast($dialect, $line, $mode, $defaults);
            $guardOff = $line->has('~^spanning-tree bpduguard disable~i');
            $bpduguard = $line->has('~^spanning-tree bpduguard enable~i') ? 'on'
                : (! $guardOff && $defaults['bpduguard'] && in_array($portfast, ['on', 'default', 'auto'], true) ? 'default' : 'off');
            $member = $line->find('~^channel-group (\d+)\b~i');
            $ports[$name] = ['name' => $name, 'line' => $line, 'channel' => $channel, 'mode' => $mode, 'shutdown' => $line->has('~^shutdown$~i'),
                'member' => $member !== null && preg_match('~^channel-group (\d+)~i', $member->text, $g) === 1 ? $g[1] : null,
                'portfast' => $portfast, 'bpduguard' => $bpduguard, 'rootguard' => $line->has('~^spanning-tree guard root~i')];
        }

        return $ports;
    }

    /**
     * The global defaults: portfast on access ports, BPDU guard on edge ports.
     *
     * @return array{portfast: bool, bpduguard: bool}
     */
    private static function defaults(string $dialect, Config $config): array
    {
        return match ($dialect) {
            self::IOS => ['portfast' => $config->first('~^spanning-tree portfast (?:edge )?default$~i') !== null,
                'bpduguard' => $config->first('~^spanning-tree portfast (?:edge )?bpduguard default$~i') !== null],
            self::NXOS => ['portfast' => $config->first('~^spanning-tree port type edge default$~i') !== null,
                'bpduguard' => $config->first('~^spanning-tree port type edge bpduguard default$~i') !== null],
            self::EOS => ['portfast' => false, 'bpduguard' => $config->first('~^spanning-tree edge-port bpduguard default$~i') !== null],
            default => ['portfast' => false, 'bpduguard' => false],
        };
    }

    /**
     * access, trunk, routed or unknown.
     */
    private static function mode(string $dialect, Line $port): string
    {
        if ($port->has('~^no switchport$~i')) {
            return 'routed';
        }
        if ($port->has('~^switchport mode trunk\b~i')) {
            return 'trunk';
        }
        if ($port->has('~^switchport mode (?:access|private-vlan host)\b~i')) {
            return 'access';
        }
        if ($port->has('~^switchport mode \S~i')) {
            return 'unknown'; // dynamic, dot1q-tunnel, a private VLAN promiscuous port...
        }
        if ($port->has('~^switchport access vlan \d~i')) {
            return 'access';
        }

        return match ($dialect) {
            // an EOS Ethernet port is an access port unless it says otherwise
            self::EOS => 'access',
            // NX-OS writes "switchport" on a layer 2 port, whose default mode is access
            self::NXOS => $port->has('~^switchport$~i') ? 'access' : 'unknown',
            default => 'unknown',
        };
    }

    /**
     * on, default, auto or off.
     *
     * @param  array{portfast: bool, bpduguard: bool}  $defaults
     */
    private static function portfast(string $dialect, Line $port, string $mode, array $defaults): string
    {
        // IOS and NX-OS: portfast without "trunk" does nothing on a trunk
        $edge = fn (string $trunk, string $plain) => $port->has($trunk) || ($mode !== 'trunk' && $port->has($plain));

        return match ($dialect) {
            self::IOS => $port->has('~^spanning-tree portfast disable$~i') ? 'off'
                : ($edge('~^spanning-tree portfast (?:edge )?trunk$~i', '~^spanning-tree portfast(?: edge)?$~i') ? 'on'
                    : ($defaults['portfast'] && $mode === 'access' ? 'default' : 'off')),
            self::NXOS => $port->has('~^spanning-tree port type (?:network|normal)$~i') ? 'off'
                : ($edge('~^spanning-tree port type edge trunk$~i', '~^spanning-tree port type edge$~i') ? 'on'
                    : ($defaults['portfast'] && $mode === 'access' ? 'default' : 'off')),
            self::EOS => $port->has('~^spanning-tree portfast$~i') ? 'on'
                : ($port->has('~^(?:no spanning-tree portfast auto|spanning-tree portfast network)$~i') ? 'off' : 'auto'),
            default => 'off',
        };
    }
}
