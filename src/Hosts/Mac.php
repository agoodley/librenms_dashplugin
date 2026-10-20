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
