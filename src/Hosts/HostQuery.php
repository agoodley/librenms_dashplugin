<?php

namespace Agoodley\LibrenmsTopology\Hosts;

/**
 * What the user typed into the host locator: an IPv4 or IPv6 address, a MAC address in any
 * common notation, or a hostname.
 */
class HostQuery
{
    public const MAC = 'mac';
    public const IPV4 = 'ipv4';
    public const IPV6 = 'ipv6';
    public const NAME = 'name';
    public const EMPTY = 'empty';
    public const INVALID = 'invalid';

    /**
     * @return array{kind: string, value: string, input: string} value: 12 hex digits for a MAC, the
     *                                                           uncompressed form LibreNMS stores for IPv6
     */
    public static function parse(string $input): array
    {
        $input = mb_substr(trim($input), 0, 255);
        $result = fn (string $kind, string $value = '') => ['kind' => $kind, 'value' => $value, 'input' => $input];

        if ($input === '') {
            return $result(self::EMPTY);
        }
        $ipv4 = rtrim($input, '.'); // a trailing dot, as in a DNS answer
        if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $result(self::IPV4, $ipv4);
        }

        $ipv6 = (string) preg_replace('/%.*$/', '', trim($input, '[]')); // zone index (fe80::1%eth0)
        if (filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // an IPv4-mapped address (::ffff:10.10.10.55) is the IPv4 address
            $binary = (string) inet_pton($ipv6);

            return str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")
                ? $result(self::IPV4, (string) inet_ntop(substr($binary, 12)))
                : $result(self::IPV6, self::ipv6Full($ipv6));
        }

        $mac = Mac::normalize($input);
        if ($mac !== null) {
            return $result(self::MAC, $mac);
        }

        // a hostname; its last label is never all digits (10.10.10 is a mistyped address)
        $name = rtrim(strtolower($input), '.');
        $labels = explode('.', $name);
        if (strlen($name) <= 253 && max(array_map('strlen', $labels)) <= 63 && ! ctype_digit((string) end($labels))
            && preg_match('/^[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?)*$/', $name)) {
            return $result(self::NAME, $name);
        }

        return $result(self::INVALID);
    }

    /**
     * IPv6 as LibreNMS stores it in ipv6_nd: eight groups of four lowercase digits.
     */
    public static function ipv6Full(string $ip): string
    {
        $binary = @inet_pton($ip);

        return $binary === false || strlen($binary) !== 16 ? strtolower($ip) : implode(':', str_split(bin2hex($binary), 4));
    }

    /**
     * Short form of an IPv6 address for display (2001:db8::1).
     */
    public static function ipv6Short(string $ip): string
    {
        $binary = @inet_pton($ip);

        return $binary === false ? $ip : (string) inet_ntop($binary);
    }
}
