<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * Classifies devices into diagram roles (router, L3 switch, switch, firewall...) which
 * select the icon drawn on the map.
 */
class DeviceRole
{
    public const ROUTER = 'router';
    public const L3SWITCH = 'l3switch';
    public const SWITCH = 'switch';
    public const FIREWALL = 'firewall';
    public const WIRELESS = 'wireless';
    public const SERVER = 'server';
    public const PHONE = 'phone';
    public const OTHER = 'other';
    public const UNKNOWN = 'unknown';

    private const ROUTER_OS = ['iosxr', 'junos', 'vrp', 'routeros', 'vyos', 'edgeos', 'timos', 'ironware', 'xos-router', 'iosxe-router'];

    /**
     * @param  array  $device  devices row (type, os, hardware)
     * @param  array{bridge?: bool, l3_interfaces?: int, routing?: bool, vlans?: int}  $facts
     */
    public static function classify(array $device, array $facts = []): string
    {
        $type = strtolower((string) ($device['type'] ?? ''));
        $os = strtolower((string) ($device['os'] ?? ''));
        $hardware = strtolower((string) ($device['hardware'] ?? ''));

        switch ($type) {
            case 'firewall':
                return self::FIREWALL;
            case 'wireless':
                return self::WIRELESS;
            case 'server':
            case 'workstation':
            case 'storage':
            case 'appliance':
            case 'loadbalancer':
                return self::SERVER;
            case 'collaboration':
                return self::PHONE;
            case 'power':
            case 'environment':
            case 'printer':
            case 'timing':
            case 'management':
                return self::OTHER;
        }

        $bridge = ($facts['bridge'] ?? false) || ($facts['vlans'] ?? 0) > 1;
        $l3 = ($facts['l3_interfaces'] ?? 0) >= 2 || ($facts['routing'] ?? false);

        if ($bridge && $l3) {
            return self::L3SWITCH;
        }

        if ($bridge) {
            return self::SWITCH;
        }

        if ($l3 || in_array($os, self::ROUTER_OS, true) || preg_match('/\b(isr|asr|router|mx\d|srx|ptx|ncs)/', $hardware)) {
            return self::ROUTER;
        }

        if (preg_match('/switch|catalyst|ws-c|c9[2-5]\d{2}|nexus|ex\d{4}|qfx/', $hardware)) {
            return self::SWITCH;
        }

        return $type === 'network' || $type === '' ? self::SWITCH : self::OTHER;
    }

    /**
     * Role of an unmanaged LLDP/CDP neighbour as LibreNMS stores it: the platform (CDP / FDP) when
     * there is one, else the system description (LLDP has no platform; LibreNMS keeps the sysDescr
     * in links.remote_version).
     */
    public static function fromNeighbor(?string $platform, ?string $description, ?string $hostname = null): string
    {
        $role = self::fromPlatform($platform, $hostname);
        $description = trim((string) $description);
        if ($role !== self::UNKNOWN || $description === '') {
            return $role;
        }

        $role = self::fromPlatform($description, $hostname);

        // network operating systems built on Linux describe themselves like servers
        return $role === self::SERVER && preg_match('/sonic|cumulus|onie|dell.*os10|vyos|openwrt/i', $description) ? self::SWITCH : $role;
    }

    /**
     * Guess the role of an unmanaged LLDP/CDP neighbour from its platform/description string.
     */
    public static function fromPlatform(?string $platform, ?string $hostname = null): string
    {
        $text = strtolower(trim(($platform ?? '') . ' ' . ($hostname ?? '')));

        return match (true) {
            $text === '' => self::UNKNOWN,
            (bool) preg_match('/phone|^sep[0-9a-f]{6}|polycom|yealink|avaya|mitel|cp-\d{4}|grandstream/', $text) => self::PHONE,
            // Ruckus ICX are switches, not Ruckus access points
            (bool) preg_match('/\bicx\d{4}/', $text) => self::SWITCH,
            (bool) preg_match('/\bair-|access ?point|\bap\b|\buap|aironet|\bmr\d{2}|meraki mr|aruba ap|arubaos \(model: \d{3}\)|\bcap\b|unifi ap|ruckus|\bc9800|\bu[67]-[a-z]/', $text) => self::WIRELESS,
            (bool) preg_match('/firewall|fortigate|palo ?alto|\bpa-\d|asa\d|sonicwall|checkpoint|\bsrx|pfsense|opnsense|firepower|\bftd\b/', $text) => self::FIREWALL,
            // system descriptions (LLDP) name the software: IOS XR, ISR / ASR, Cisco router models (a C2900 is a router, a C2960 a switch)
            (bool) preg_match('/router|\bisr|\basr|mikrotik|routeros|\bmx\d|ios[ -]?xr|vyos|edgerouter|\bc(8\d\d|1100|1900|2900|3900|4[34]\d\d|8[235]\d\d)\b/', $text) => self::ROUTER,
            (bool) preg_match('/switch|\bswch\b|ws-c|catalyst|\bc(2960|3560|3650|3750|3850|9[2-5]00)|nexus|nx-?os|arista|\beos\b|\bdcs-\d|procurve|aruba ?\d{4}|arubaos-cx|\bjl\d{3}a\b|\bex\d{4}|\bqfx|comware|extremexos|\bvsp-?\d|dell.*n\d{4}|\bos10\b|meraki ms|unifi ?switch|\busw/', $text) => self::SWITCH,
            (bool) preg_match('/linux|windows|vmware|esxi|ubuntu|debian|red ?hat|centos|server|hyper-v|proxmox|truenas|synology/', $text) => self::SERVER,
            default => self::UNKNOWN,
        };
    }
}
