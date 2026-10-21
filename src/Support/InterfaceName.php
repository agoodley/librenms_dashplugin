<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * Interface name helpers: produce the short names network engineers expect on a diagram
 * (GigabitEthernet1/0/1 -> Gi1/0/1) and a normalized form for matching names reported by
 * neighbours (CDP reports long names, LLDP often short ones).
 */
class InterfaceName
{
    /**
     * Ordered longest-first so that e.g. TenGigabitEthernet is matched before GigabitEthernet.
     */
    private const ABBREVIATIONS = [
        'HundredGigabitEthernet' => 'Hu',
        'FourHundredGigE' => 'FH',
        'TwoHundredGigE' => 'TH',
        'TwentyFiveGigabitEthernet' => 'Twe',
        'TwentyFiveGigE' => 'Twe',
        'FortyGigabitEthernet' => 'Fo',
        'TenGigabitEthernet' => 'Te',
        'FiveGigabitEthernet' => 'Fi',
        'TwoGigabitEthernet' => 'Tw',
        'AppGigabitEthernet' => 'Ap',
        'GigabitEthernet' => 'Gi',
        'HundredGigE' => 'Hu',
        'FortyGigE' => 'Fo',
        'TenGigE' => 'Te',
        'FastEthernet' => 'Fa',
        'Port-channel' => 'Po',
        'Port-Channel' => 'Po',
        'port-channel' => 'Po',
        'Bundle-Ether' => 'BE',
        'Ethernet' => 'Eth',
        'Loopback' => 'Lo',
        'Tunnel' => 'Tu',
        'Serial' => 'Se',
        'Vlan' => 'Vl',
        'vlan' => 'Vl',
        'Management' => 'Mgmt',
    ];

    /**
     * Abbreviated interface name as shown on diagrams: GigabitEthernet1/0/1 => Gi1/0/1, Port-channel10 => Po10.
     */
    public static function short(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }

        foreach (self::ABBREVIATIONS as $long => $short) {
            if (str_starts_with($name, $long)) {
                $rest = substr($name, strlen($long));
                // only abbreviate when followed by a number/space/slash (avoid "Ethernet-Switch")
                if ($rest === '' || preg_match('#^[\s\d/:.]#', $rest)) {
                    return $short . ltrim($rest);
                }
            }
        }

        // Juniper/Arista/Linux style names are already short
        return $name;
    }

    /**
     * Lowercase short form without spaces, used to compare names from different sources.
     */
    public static function normalize(?string $name): string
    {
        return strtolower(str_replace(' ', '', self::short($name)));
    }

    /**
     * Best label for a port row (ifName preferred, ifDescr fallback).
     */
    public static function label(array $port): string
    {
        foreach (['ifName', 'ifDescr', 'portName'] as $field) {
            if (! empty($port[$field])) {
                return (string) $port[$field];
            }
        }

        return isset($port['ifIndex']) ? 'ifIndex ' . $port['ifIndex'] : '?';
    }

    /**
     * Stack member, line card or module of a port taken from its name, for devices whose
     * inventory (ENTITY-MIB) does not map ports to modules:
     *  - Juniper xe-1/0/2: FPC 1 (virtual chassis member or line card);
     *  - three or more numbers (Gi2/0/1, 1/1/1, Ten-GigabitEthernet2/0/1): the first one;
     *  - two numbers without a type prefix (2/1, 2:1): the first one (ProCurve stacks, VOSS, EXOS);
     *  - ProCurve 1/A1 (member 1) and A1 (module A).
     * Two numbers with a type prefix (Gi0/1, Ethernet49/1) are ambiguous and give null.
     *
     * @param  string  $os  LibreNMS os of the device
     * @return array{key: string, label: string}|null
     */
    public static function unit(?string $name, string $os = ''): ?array
    {
        $name = trim((string) $name);
        if (preg_match('#^(?:ge|xe|et|mge|fe|xle|fte)-(\d+)/\d+/\d+(?::\d+)?$#i', $name, $match)) {
            return ['key' => 'fpc' . (int) $match[1], 'label' => 'FPC ' . (int) $match[1]];
        }
        if (preg_match('#^(?:[a-z][a-z -]*?)?(\d+)/\d+/\d+(?:/\d+)*(?::\d+)?$#i', $name, $match)
            || preg_match('#^(\d+)[/:]\d+$#', $name, $match)) {
            return ['key' => 'unit' . (int) $match[1], 'label' => 'member/slot ' . (int) $match[1]];
        }
        if ($os === 'procurve' && preg_match('#^(?:(\d+)/)?([A-Z])\d+$#', $name, $match)) {
            return $match[1] !== ''
                ? ['key' => 'unit' . (int) $match[1], 'label' => 'member ' . (int) $match[1]]
                : ['key' => 'module' . $match[2], 'label' => 'module ' . $match[2]];
        }

        return null;
    }

    /**
     * Does the name look like an aggregate (Port-channel, ae, bond, Bundle-Ether, Eth-Trunk...)?
     * Sub-interfaces of one (Po1.100, ae0.0) match too: see PortStack::isAggregate().
     */
    public static function isLagName(?string $name): bool
    {
        return (bool) preg_match('/^(port-?channel|po\d|ae\d|bond|lag|trk|bundle-ether|be\d|bagg|eth-trunk|agg)/i', trim((string) $name));
    }

    /**
     * Is it a sub-interface or logical unit (Gi0/0.100, ae0.0, Po1.100), named by its parent and a number?
     */
    public static function isSubInterface(?string $name): bool
    {
        return (bool) preg_match('/\.\d+$/', trim((string) $name));
    }

    /**
     * Name of the interface a sub-interface sits on (Gi0/0.100 => Gi0/0), null for other names.
     */
    public static function parentName(?string $name): ?string
    {
        return preg_match('/^(.+)\.\d+$/', trim((string) $name), $match) ? $match[1] : null;
    }

    /**
     * VLAN of a VLAN interface: Vlan30, Vl30, Vlanif30 (Huawei), Vlan-interface30 (Comware), ve 30
     * (Ruckus, Brocade), irb.30 and vlan.30 (Junos). Null for other names.
     */
    public static function vlanInterface(?string $name): ?int
    {
        if (! preg_match('/^(?:vl|vlan|vlanif|vlan-interface|ve|irb\.|vlan\.)\s*(\d{1,4})$/i', trim((string) $name), $match)) {
            return null;
        }

        return self::vlan((int) $match[1]);
    }

    /**
     * Unit number of a sub-interface (Gi0/0.20 => 20, ae0.40 => 40), which is its VLAN by the usual
     * convention, but not necessarily (encapsulation and vlan-id are configured separately).
     */
    public static function subInterfaceUnit(?string $name): ?int
    {
        if (! preg_match('#^[a-z][\w/:-]*\.(\d{1,4})$#i', trim((string) $name), $match)) {
            return null;
        }

        return self::vlan((int) $match[1]);
    }

    private static function vlan(int $vlan): ?int
    {
        return $vlan >= 1 && $vlan <= 4094 ? $vlan : null;
    }
}
