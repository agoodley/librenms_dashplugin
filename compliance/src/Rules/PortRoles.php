<?php

namespace Agoodley\LibrenmsCompliance\Rules;

/**
 * What each port of a device faces, from LibreNMS' data: its LLDP/CDP neighbors, which of them are switches (devices
 * whose spanning tree LibreNMS polls) and the spanning tree root ports (of every VLAN or instance). A port faces a
 * host when no neighbor of it is such a switch (no neighbor, an end device, a phone, an access point, a switch
 * LibreNMS does not poll); one facing a switch is upstream when it is the device's root port (its way to the root
 * bridge), downstream when the neighbor's port on the link is the neighbor's root port (the neighbor reaches the root
 * through this device), and a switch port otherwise (a peer link, a blocked or a backup link). A port-channel faces what
 * its members face; a member is what its port-channel is.
 */
final class PortRoles
{
    public const HOST = 'host';

    public const SWITCH = 'switch';

    public const UPSTREAM = 'upstream';

    public const DOWNSTREAM = 'downstream';

    /** The roles of a port that faces a switch. */
    public const SWITCHES = [self::SWITCH, self::UPSTREAM, self::DOWNSTREAM];

    /**
     * The roles of the device's ports that have LLDP/CDP neighbors (and of the port-channels of those ports); a port
     * missing from the answer faces nothing LibreNMS knows of: a host.
     *
     * @param  array<int, array{local_port_id: int, remote_device_id: ?int, remote_port_id: ?int, remote_hostname: ?string, remote_port: ?string}>  $links
     *                                                                                                                                                  the device's LLDP/CDP links
     * @param  array<int, true>  $bridges  the devices whose spanning tree LibreNMS polls (switches)
     * @param  array<int, int[]>  $rootPorts  device id => its root ports' ids (this device's and its neighbors')
     * @param  array<int, int>  $lagParent  member port id => its port-channel's port id (this device's and its neighbors')
     * @param  array<int, string>  $names  device id => name (the neighbors')
     * @return array<int, array{role: string, neighbors: string[]}>
     */
    public static function compute(int $deviceId, array $links, array $bridges, array $rootPorts, array $lagParent, array $names): array
    {
        $own = array_fill_keys(array_map('intval', $rootPorts[$deviceId] ?? []), true);
        $byPort = [];
        foreach ($links as $link) {
            $local = (int) $link['local_port_id'];
            $remoteDevice = isset($link['remote_device_id']) && (int) $link['remote_device_id'] > 0 ? (int) $link['remote_device_id'] : null;
            $remotePort = isset($link['remote_port_id']) && (int) $link['remote_port_id'] > 0 ? (int) $link['remote_port_id'] : null;
            $switch = $remoteDevice !== null && $remoteDevice !== $deviceId && isset($bridges[$remoteDevice]);
            $theirs = $remoteDevice !== null ? array_fill_keys(array_map('intval', $rootPorts[$remoteDevice] ?? []), true) : [];
            $info = [
                'switch' => $switch,
                // the neighbor reaches its root bridge through this link: its port on it (or that port's port-channel) is its root port
                'downstream' => $switch && $remotePort !== null && (isset($theirs[$remotePort]) || isset($theirs[(int) ($lagParent[$remotePort] ?? 0)])),
                'name' => trim(($remoteDevice !== null ? (string) ($names[$remoteDevice] ?? '') : '') ?: (string) ($link['remote_hostname'] ?? ''))
                    . (($link['remote_port'] ?? '') !== '' ? ' ' . $link['remote_port'] : ''),
            ];
            $byPort[$local][] = $info;
            if (isset($lagParent[$local])) {
                $byPort[$lagParent[$local]][] = $info;
            }
        }
        $roles = [];
        foreach ($byPort as $portId => $infos) {
            $switches = array_filter($infos, fn (array $info) => $info['switch']);
            $upstream = isset($own[$portId]) || isset($own[$lagParent[$portId] ?? 0]);
            $role = match (true) {
                ! $switches => self::HOST,
                $upstream => self::UPSTREAM,
                (bool) array_filter($switches, fn (array $info) => $info['downstream']) => self::DOWNSTREAM,
                default => self::SWITCH,
            };
            $roles[(int) $portId] = ['role' => $role, 'neighbors' => array_values(array_unique(array_filter(array_column($infos, 'name'))))];
        }

        return $roles;
    }
}
