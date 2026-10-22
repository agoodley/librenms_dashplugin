<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

/**
 * A small LibreNMS network with the shapes the inventory has to cope with: two vendors, an IPv6 hostname, a
 * hostname used twice, reserved and unsafe names, a device whose network data is hostile, a disabled and an
 * unmapped device, device groups and locations whose names collide once reduced to group names.
 */
final class InventoryFixture
{
    public const HOSTILE_SYSNAME = "evil\n{{ lookup('pipe','id') }}";

    public const HOSTILE_LOCATION = "<script>alert(1)</script>\r\nFloor {% if 1 %}2{% endif %} #3";

    /**
     * @return array<int, array> device_id => row
     */
    public static function devices(): array
    {
        $device = fn (string $hostname, string $os, array $more = []) => $more + [
            'hostname' => $hostname, 'sysName' => explode('.', $hostname)[0], 'os' => $os, 'type' => 'network',
            'hardware' => 'C9300-48P', 'version' => '17.9.4', 'location_id' => 1, 'overwrite_ip' => null, 'disabled' => 0,
        ];

        return [
            1 => $device('core1.example.net', 'iosxe'),
            2 => $device('core2.example.net', 'iosxe', ['location_id' => 2]),
            3 => $device('acc-sw1', 'ios', ['location_id' => 2, 'hardware' => 'WS-C2960X-48FPS-L', 'version' => '15.2(7)E3']),
            4 => $device('2001:db8::10', 'ios', ['sysName' => 'v6-sw']),
            5 => $device('hostile.lab', 'ios', ['sysName' => self::HOSTILE_SYSNAME, 'location_id' => 4,
                'hardware' => 'C9300 {% raw %}', 'version' => "17.3\x07 \u{202E}#x"]),
            6 => $device('CORE1.example.net', 'iosxe'),
            7 => $device('old-sw.example.net', 'ios', ['disabled' => 1]),
            8 => $device('srv1.example.net', 'linux', ['type' => 'server']),
            9 => $device('all', 'ios'),
            10 => $device('device3', 'ios'),
            11 => $device('-oProxyCommand=evil', 'ios'),
            12 => $device('rtr12.example.net', 'junos', ['overwrite_ip' => '10.0.0.12', 'location_id' => 3, 'type' => 'firewall']),
            13 => $device('my switch', 'ios'),
            14 => $device('os_ios', 'ios'),
            15 => $device('fw1.example.net', 'arista_eos', ['location_id' => null, 'type' => '']),
        ];
    }

    /**
     * @return array<int, int[]> device_id => device group ids
     */
    public static function memberships(): array
    {
        return [1 => [1], 2 => [1, 2], 3 => [3], 12 => [3]];
    }

    /**
     * @return array<int, string>
     */
    public static function groups(): array
    {
        return [1 => 'Core', 2 => 'core', 3 => 'Ünïcödé Sites'];
    }

    /**
     * @return array<int, string>
     */
    public static function locations(): array
    {
        return [1 => 'HQ', 2 => 'Building B', 3 => 'Lab "A&B" <west>', 4 => self::HOSTILE_LOCATION];
    }
}
