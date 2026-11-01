<?php

namespace Agoodley\LibrenmsCompliance\Data;

/**
 * What a check reads of LibreNMS besides the backup: the devices it covers, a device's ports, and what they face (its
 * LLDP/CDP links, which neighbors run spanning tree, the root ports, the port-channels). LibrenmsNetwork reads LibreNMS'
 * tables; the tests give their own.
 */
interface Network
{
    /**
     * The devices a check covers: enabled, of the device group (every device for ''), by id.
     *
     * @return array<int, array{name: string, os: string}>
     *
     * @throws \InvalidArgumentException a device group LibreNMS does not have
     */
    public function devices(string $group = ''): array;

    /**
     * One device (enabled or not), null when LibreNMS does not have it.
     *
     * @return array{name: string, os: string, disabled: bool}|null
     */
    public function device(int $deviceId): ?array;

    /**
     * A device's ports by id, with their names.
     *
     * @return array<int, array{ifName: ?string, ifDescr: ?string}>
     */
    public function ports(int $deviceId): array;

    /**
     * What a device's ports face (Rules\PortRoles::compute()'s data): its LLDP/CDP links, the devices whose spanning
     * tree LibreNMS polls, the root ports and the port-channels of the device and its neighbors, their names.
     *
     * @return array{links: array<int, array>, bridges: array<int, true>, rootPorts: array<int, int[]>, lagParent: array<int, int>, names: array<int, string>}
     */
    public function neighborhood(int $deviceId): array;
}
