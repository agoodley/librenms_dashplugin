<?php

namespace Agoodley\LibrenmsCompliance\Config;

/**
 * The LibreNMS port of an interface a configuration names: by the port's ifDescr (Cisco's and Arista's full name,
 * "GigabitEthernet1/0/5"), else its ifName, else an abbreviation of one and the other with the same numbers ("Gi1/0/5",
 * "Eth1/1" for "Ethernet1/1", "Po2" for "Port-channel2"), when only one port of the device fits.
 */
final class InterfaceNames
{
    /** @var array<string, int> lower-cased ifDescr and ifName => port id (ambiguous names left out) */
    private array $exact = [];

    /** @var array<string, array<int, array{0: string, 1: int}>> number part => [lower-cased letters, port id] */
    private array $byNumber = [];

    /**
     * @param  array<int, array{ifName?: ?string, ifDescr?: ?string}>  $ports  port id => the port's names
     */
    public function __construct(array $ports)
    {
        $seen = [];
        foreach ($ports as $portId => $port) {
            foreach ([(string) ($port['ifDescr'] ?? ''), (string) ($port['ifName'] ?? '')] as $name) {
                $key = strtolower(trim($name));
                if ($key === '') {
                    continue;
                }
                if (isset($seen[$key]) && $seen[$key] !== (int) $portId) {
                    $this->exact[$key] = -1; // two ports answer to this name
                } else {
                    $seen[$key] = (int) $portId;
                    $this->exact[$key] ??= (int) $portId;
                }
                [$letters, $number] = self::split($key);
                if ($letters !== '' && $number !== '') {
                    $this->byNumber[$number][] = [$letters, (int) $portId];
                }
            }
        }
    }

    /**
     * The port id of a name of the configuration ("GigabitEthernet1/0/5"); null when no port, or more than one, fits.
     */
    public function port(string $name): ?int
    {
        $key = strtolower(trim($name));
        if (isset($this->exact[$key])) {
            return $this->exact[$key] > 0 ? $this->exact[$key] : null;
        }
        [$letters, $number] = self::split($key);
        if ($letters === '' || $number === '') {
            return null;
        }
        $found = [];
        foreach ($this->byNumber[$number] ?? [] as [$other, $portId]) {
            // one is the abbreviation of the other: "gi" and "gigabitethernet", "eth" and "ethernet", "po" and "port-channel"
            if (str_starts_with($letters, $other) || str_starts_with($other, $letters)) {
                $found[$portId] = true;
            }
        }

        return count($found) === 1 ? (int) array_key_first($found) : null;
    }

    /**
     * A name's letters (without "-" and spaces) and its numbers: ["gigabitethernet", "1/0/5"].
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $name): array
    {
        if (preg_match('~^([a-z][a-z -]*?)\s*(\d[\d/.:]*)$~i', trim($name), $m) !== 1) {
            return ['', ''];
        }

        return [str_replace(['-', ' '], '', strtolower($m[1])), $m[2]];
    }
}
