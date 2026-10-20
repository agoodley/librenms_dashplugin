<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * Minimal IPv4/IPv6 prefix arithmetic (no dependency on LibreNMS internals so it is unit-testable).
 */
class Cidr
{
    /**
     * Network address in CIDR notation for an address/prefix, or null when invalid.
     */
    public static function network(string $address, int $prefix): ?string
    {
        $address = trim($address);
        $packed = @inet_pton($address);
        if ($packed === false) {
            return null;
        }

        $bits = strlen($packed) * 8;
        if ($prefix < 0 || $prefix > $bits) {
            return null;
        }

        $network = $packed & self::mask(strlen($packed), $prefix);

        return inet_ntop($network) . '/' . $prefix;
    }

    /**
     * IP version of an address: 6 when it contains a colon, otherwise 4.
     */
    public static function family(string $address): int
    {
        return str_contains($address, ':') ? 6 : 4;
    }

    /**
     * Does $cidr (a.b.c.d/nn, or a bare address) contain the given address or network?
     * A malformed $cidr (e.g. "10.0.0.0/" or "10.0.0.0/x") contains nothing.
     */
    public static function contains(string $cidr, string $addressOrNetwork): bool
    {
        [$net, $len] = array_pad(explode('/', trim($cidr), 2), 2, null);
        [$addr] = explode('/', trim($addressOrNetwork), 2);

        $netPacked = @inet_pton((string) $net);
        $addrPacked = @inet_pton($addr);
        if ($netPacked === false || $addrPacked === false || strlen($netPacked) !== strlen($addrPacked)) {
            return false;
        }

        $bits = strlen($netPacked) * 8;
        if ($len === null) {
            $len = $bits;
        } elseif (! ctype_digit($len) || (int) $len > $bits) {
            return false;
        }
        $mask = self::mask(strlen($netPacked), (int) $len);

        return ($netPacked & $mask) === ($addrPacked & $mask);
    }

    /**
     * @param  string[]  $cidrs
     */
    public static function containedInAny(array $cidrs, string $addressOrNetwork): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::contains($cidr, $addressOrNetwork)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An IP address in the form LibreNMS stores it (IPv6 compressed, lower case; a zone index such
     * as %eth0 dropped), null when it is not an address.
     */
    public static function canonical(string $address): ?string
    {
        $packed = @inet_pton(trim(explode('%', $address)[0]));

        return $packed === false ? null : inet_ntop($packed);
    }

    /**
     * Addresses that never describe topology (loopback, link-local, unspecified...).
     */
    public static function isIgnorable(string $address): bool
    {
        if (self::family($address) === 4) {
            return self::containedInAny(['127.0.0.0/8', '169.254.0.0/16', '0.0.0.0/8', '224.0.0.0/4'], $address);
        }

        return self::containedInAny(['fe80::/10', '::1/128', '::/128', 'ff00::/8'], $address);
    }

    /**
     * Binary netmask of $prefix bits for an address of $bytes bytes.
     */
    private static function mask(int $bytes, int $prefix): string
    {
        $prefix = max(0, min($bytes * 8, $prefix));
        $mask = str_repeat("\xff", intdiv($prefix, 8));
        if ($prefix % 8) {
            $mask .= chr((0xff << (8 - $prefix % 8)) & 0xff);
        }

        return str_pad($mask, $bytes, "\x00");
    }
}
