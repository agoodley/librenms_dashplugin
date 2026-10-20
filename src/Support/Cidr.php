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

    public static function family(string $address): int
    {
        return str_contains($address, ':') ? 6 : 4;
    }

    /**
     * Does $cidr (a.b.c.d/nn) contain the given address or network?
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

        $len = $len === null ? strlen($netPacked) * 8 : (int) $len;
        $mask = self::mask(strlen($netPacked), $len);

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
     * Addresses that never describe topology (loopback, link-local, unspecified...).
     */
    public static function isIgnorable(string $address): bool
    {
        if (self::family($address) === 4) {
            return self::containedInAny(['127.0.0.0/8', '169.254.0.0/16', '0.0.0.0/8', '224.0.0.0/4'], $address);
        }

        return self::containedInAny(['fe80::/10', '::1/128', '::/128', 'ff00::/8'], $address);
    }

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
