<?php

namespace Agoodley\LibrenmsTopology\Hosts;

/**
 * MAC address notation. LibreNMS stores MACs as 12 lowercase hex digits (LibreNMS\Util\Mac::hex()),
 * the OUI table (vendor_ouis) as lowercase hex prefixes: 6 digits for a /24 block, 7 or 9 for the
 * /28 and /36 blocks the IEEE assigns inside some of them.
 */
class Mac
{
    /**
     * 12 lowercase hex digits from any common notation (aa:bb:cc:dd:ee:ff, a:b:c:d:e:f, aa-bb-...,
     * aabb.ccdd.eeff, aabbcc-ddeeff, bare hex, 0x prefix), null when it is not a MAC address.
     */
    public static function normalize(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/^0x/', '', $value);
        if (preg_match('/^[0-9a-f]{12}$/', $value)) {
            return $value;
        }

        $parts = preg_split('/[:\-. ]/', $value) ?: [];
        $lengths = array_map('strlen', $parts);
        $hex = implode('', $parts);
        if (! preg_match('/^[0-9a-f]*$/', $hex)) {
            return null;
        }

        return match (true) {
            count($parts) === 6 && min($lengths) >= 1 && max($lengths) <= 2 => implode('', array_map(fn ($part) => str_pad($part, 2, '0', STR_PAD_LEFT), $parts)),
            count($parts) === 3 && $lengths === [4, 4, 4], count($parts) === 2 && $lengths === [6, 6] => $hex,
            default => null,
        };
    }

    /**
     * aa:bb:cc:dd:ee:ff
     */
    public static function readable(string $hex): string
    {
        return implode(':', str_split(strtolower($hex), 2));
    }

    /**
     * The notations a MAC is stored in by LibreNMS modules: 12 hex digits, and for 802.1X sessions of
     * some switches (Aruba CX, ProCurve, PowerConnect) colon separated with or without leading zeros.
     *
     * @return string[]
     */
    public static function variants(string $hex): array
    {
        $bytes = str_split(strtolower($hex), 2);

        return array_values(array_unique([strtolower($hex), implode(':', $bytes), implode(':', array_map(fn ($byte) => ltrim($byte, '0') ?: '0', $bytes))]));
    }

    /**
     * Virtual router MAC of a first hop redundancy protocol: VRRP (00:00:5e:00:01:xx, IPv6 00:00:5e:00:02:xx),
     * HSRP (00:00:0c:07:ac:xx, version 2 00:00:0c:9f:fx:xx, IPv6 00:05:73:a0:0x:xx) or GLBP (00:07:b4:xx:xx:yy,
     * one MAC per forwarder of a group). The router that is master / active for the group sends from it.
     * The HSRP and VRRP MACs depend on the group number only, so they repeat in every VLAN using that number.
     *
     * @return array{protocol: string, group: int, label: string}|null
     */
    public static function gateway(string $hex): ?array
    {
        $hex = strtolower($hex);
        if (! preg_match('/^[0-9a-f]{12}$/', $hex)) {
            return null;
        }

        [$protocol, $group, $forwarder] = match (true) {
            str_starts_with($hex, '00005e0001') => ['VRRP', hexdec(substr($hex, 10)), null],
            str_starts_with($hex, '00005e0002') => ['VRRP (IPv6)', hexdec(substr($hex, 10)), null],
            str_starts_with($hex, '00000c07ac') => ['HSRP', hexdec(substr($hex, 10)), null],
            str_starts_with($hex, '00000c9ff') => ['HSRP version 2', hexdec(substr($hex, 9)), null],
            str_starts_with($hex, '000573a00') => ['HSRP (IPv6)', hexdec(substr($hex, 9)), null],
            str_starts_with($hex, '0007b4') => ['GLBP', hexdec(substr($hex, 6, 4)) & 0x3ff, hexdec(substr($hex, 10))],
            default => [null, 0, null],
        };

        return $protocol === null ? null : [
            'protocol' => $protocol,
            'group' => (int) $group,
            'label' => $protocol . ' group ' . $group . ($forwarder === null ? '' : ', forwarder ' . $forwarder),
        ];
    }

    /**
     * Vendor from the OUI table rows of the MAC's prefix; the longest matching prefix wins.
     *
     * @param  array<string, string>  $ouis  oui => vendor
     */
    public static function vendor(string $hex, array $ouis): ?string
    {
        $best = null;
        foreach ($ouis as $oui => $vendor) {
            $oui = strtolower((string) $oui);
            if ($oui !== '' && str_starts_with($hex, $oui) && ($best === null || strlen($oui) > strlen($best))) {
                $best = $oui;
            }
        }

        return $best === null ? null : (string) $ouis[$best];
    }

    /**
     * Locally administered (U/L bit set): randomized "private" addresses of phones and laptops,
     * virtual interfaces. They have no vendor and can change.
     */
    public static function isLocal(string $hex): bool
    {
        return strlen($hex) === 12 && (hexdec(substr($hex, 0, 2)) & 0x02) === 0x02;
    }

    /**
     * Group or broadcast address (I/G bit set): never a host's own address.
     */
    public static function isMulticast(string $hex): bool
    {
        return strlen($hex) === 12 && (hexdec(substr($hex, 0, 2)) & 0x01) === 0x01;
    }
}
