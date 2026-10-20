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
     * Does the name look like an aggregate (Port-channel, ae, bond, Bundle-Ether, Eth-Trunk...)?
     */
    public static function isLagName(?string $name): bool
    {
        return (bool) preg_match('/^(port-?channel|po\d|ae\d|bond|lag|trk|bundle-ether|be\d|bagg|eth-trunk|agg)/i', trim((string) $name));
    }
}
