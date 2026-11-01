<?php

namespace Agoodley\LibrenmsInventory\Support;

/**
 * Whose optic a transceiver is, as far as its data tells: genuine when its vendor names the maker of the device it sits
 * in (Cisco optics report "CISCO-FINISAR", Arista ones "Arista Networks") or its part number is that maker's (Juniper's
 * 740-xxxxxx, HPE's J9150A), third-party when it names another vendor, unknown (null) when it names none (LibreNMS often
 * has no vendor for Cisco optics) or the device's maker is not one of these. Optics coded for the switch they sit in
 * report that switch's maker whoever made them: this tells the coding, not the factory. Pure PHP.
 */
final class Origin
{
    public const GENUINE = 'genuine';

    public const THIRD_PARTY = 'third-party';

    /**
     * Makers: the LibreNMS os names of their devices (a pattern), the vendor names their optics report and, where they
     * have one, the form of their own part numbers.
     */
    private const MAKERS = [
        'Cisco' => ['os' => '/^(ios|iosxe|iosxr|nxos|asa|cisco|cat\d|ciscosb|ciscowlc)/', 'vendor' => '/cisco/i', 'part' => null],
        'Juniper' => ['os' => '/^junos/', 'vendor' => '/juniper/i', 'part' => '/^740-\d{5,6}/'],
        'Arista' => ['os' => '/^arista/', 'vendor' => '/arista/i', 'part' => null],
        'HPE Aruba' => ['os' => '/^(procurve|aruba|arubaos|comware|hpe|hp-)/', 'vendor' => '/\b(hpe?|hewlett|aruba)\b/i', 'part' => '/^(J[A-Z]?\d{3,4}[A-Z]|R\d[A-Z]\d{2}[A-Z])$/'],
        'Dell' => ['os' => '/^(dnos|dell|ftos|powerconnect|os10)/', 'vendor' => '/dell|force10/i', 'part' => null],
        'Extreme' => ['os' => '/^(xos|exos|voss|boss|extreme|enterasys)/', 'vendor' => '/extreme|enterasys/i', 'part' => null],
        'Huawei' => ['os' => '/^(vrp|huawei)/', 'vendor' => '/huawei/i', 'part' => null],
        'MikroTik' => ['os' => '/^routeros/', 'vendor' => '/mikrotik/i', 'part' => null],
        'Ubiquiti' => ['os' => '/^(edgeswitch|unifi|edgeos)/', 'vendor' => '/ubiquiti|ubnt/i', 'part' => null],
        'Fortinet' => ['os' => '/^forti/', 'vendor' => '/fortinet/i', 'part' => null],
        'Palo Alto Networks' => ['os' => '/^panos/', 'vendor' => '/palo ?alto/i', 'part' => null],
    ];

    /**
     * The maker of a device by its LibreNMS os name, null for one not listed.
     */
    public static function maker(?string $os): ?string
    {
        $os = strtolower(trim((string) $os));
        if ($os === '') {
            return null;
        }
        foreach (self::MAKERS as $maker => $rule) {
            if (preg_match($rule['os'], $os) === 1) {
                return $maker;
            }
        }

        return null;
    }

    /**
     * GENUINE, THIRD_PARTY or null (unknown) for an optic in a device of this os.
     */
    public static function of(?string $os, ?string $vendor, ?string $model): ?string
    {
        $maker = self::maker($os);
        if ($maker === null) {
            return null;
        }
        $rule = self::MAKERS[$maker];
        $vendor = trim((string) $vendor);
        $model = trim((string) $model);
        if ($rule['part'] !== null && $model !== '' && preg_match($rule['part'], $model) === 1) {
            return self::GENUINE;
        }
        if ($vendor === '') {
            return null;
        }

        return preg_match($rule['vendor'], $vendor) === 1 ? self::GENUINE : self::THIRD_PARTY;
    }
}
