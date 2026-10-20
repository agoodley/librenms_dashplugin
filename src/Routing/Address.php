<?php

namespace Agoodley\LibrenmsTopology\Routing;

/**
 * One interface address of the routing analysis. Kept small (an object with typed properties, the
 * address in binary): the analyzer holds every address of the network, tens of thousands on large
 * networks.
 */
final class Address
{
    /** @var array<int, string> netmask cache, bytes * 256 + prefix => mask */
    private static array $masks = [];

    /**
     * @param  string  $port  short interface name
     * @param  string  $packed  the address as inet_pton() returns it
     * @param  string  $vrf  VRF name, '' for the global table
     * @param  bool  $host  a /32 or /128, or an address of a loopback interface: not one end of a subnet
     * @param  bool  $up  the port is up (or its state is unknown)
     */
    public function __construct(
        public readonly int $device,
        public readonly ?int $portId,
        public readonly string $port,
        public readonly string $packed,
        public readonly int $prefix,
        public readonly string $vrf,
        public readonly bool $host,
        public readonly bool $up,
        public readonly ?int $mtu,
    ) {
    }

    public function ip(): string
    {
        return (string) inet_ntop($this->packed);
    }

    /**
     * The subnet as text: "10.0.0.0/30".
     */
    public function network(): string
    {
        return inet_ntop($this->packed & self::mask(strlen($this->packed), $this->prefix)) . '/' . $this->prefix;
    }

    /**
     * Binary key of the subnet at $prefix bits (default: the address's own prefix).
     */
    public function key(?int $prefix = null): string
    {
        return self::networkKey($this->packed, $prefix ?? $this->prefix);
    }

    /**
     * Does the subnet of this address hold the (binary) address?
     */
    public function contains(string $packed): bool
    {
        if (strlen($packed) !== strlen($this->packed)) {
            return false;
        }
        $mask = self::mask(strlen($packed), $this->prefix);

        return ($packed & $mask) === ($this->packed & $mask);
    }

    /**
     * Binary form of an IPv4 / IPv6 address, null when it is not one.
     */
    public static function pack(mixed $address): ?string
    {
        $packed = @inet_pton(trim((string) $address));

        return $packed === false ? null : $packed;
    }

    /**
     * Binary key of the subnet of $prefix bits that holds a binary address (IPv4 and IPv6 keys
     * differ in length, so they never collide).
     */
    public static function networkKey(string $packed, int $prefix): string
    {
        return ($packed & self::mask(strlen($packed), $prefix)) . chr(max(0, min(255, $prefix)));
    }

    /**
     * Binary netmask of $prefix bits for an address of $bytes bytes.
     */
    public static function mask(int $bytes, int $prefix): string
    {
        $prefix = max(0, min($bytes * 8, $prefix));
        $key = $bytes * 256 + $prefix;
        if (! isset(self::$masks[$key])) {
            $mask = str_repeat("\xff", intdiv($prefix, 8));
            if ($prefix % 8) {
                $mask .= chr((0xff << (8 - $prefix % 8)) & 0xff);
            }
            self::$masks[$key] = str_pad($mask, $bytes, "\x00");
        }

        return self::$masks[$key];
    }
}
