<?php

namespace Agoodley\LibrenmsTopology\Path;

/**
 * What L3Tracer asks of the network while it walks, a part at a time: the interfaces of the routers it reaches, the
 * interfaces around an address (its owners and the subnets that hold it), the devices on the same subnets, OSPF / BGP
 * neighbors, roles, LLDP/CDP neighbors on a port, and routes. PathNetwork answers from the database (indexed lookups by
 * address and network); the tests answer from arrays.
 *
 * An interface is an array: device_id, port_id, address (IPv4 or IPv6, link-local ones included), prefixlen, vrf (''
 * for the global table), up (bool), network_id (LibreNMS' ipv4_networks / ipv6_networks id, 0 when it has none).
 */
interface L3Data
{
    /**
     * All interfaces of these devices (the user may see), link-local addresses included.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array>
     */
    public function interfaces(array $deviceIds): array;

    /**
     * Interfaces of any device the user may see that have one of the addresses (not link-local).
     *
     * @param  string[]  $addresses
     * @return array<int, array>
     */
    public function owners(array $addresses): array;

    /**
     * Interfaces of any device the user may see on a subnet that holds the address.
     *
     * @return array<int, array>
     */
    public function subnets(string $address): array;

    /**
     * Interfaces of any device the user may see on the same subnets as these interfaces.
     *
     * @param  array<int, array>  $interfaces
     * @return array<int, array>
     */
    public function peers(array $interfaces): array;

    /**
     * OSPF neighbors (full) and BGP peers (established) of the devices: device_id, port_id (local, may be null),
     * local_ip, neighbor_ip, kind (ospf / bgp), vrf.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array>
     */
    public function adjacencies(array $deviceIds): array;

    /**
     * Roles of the devices the user may see (DeviceRole).
     *
     * @param  int[]  $deviceIds
     * @return array<int, string>
     */
    public function roles(array $deviceIds): array;

    public function name(int $deviceId): string;

    /**
     * Devices the user may see joined by LLDP/CDP on a port of a device: device_id => their port (null when not known).
     *
     * @return array<int, ?int>
     */
    public function linked(int $deviceId, int $portId): array;

    /**
     * Routes that cover an address, per device: context, dest, pfxlen, next_hop, port_id, type, proto, metric, updated
     * (unix time); null when they are not read (too many addresses routed toward).
     *
     * @return array<int, array<int, array>>|null
     */
    public function routes(string $address): ?array;

    /**
     * Devices with routes in LibreNMS: count, newest (time of the newest route), contexts (routes per VRF), built (when
     * this summary was read), discovered and finished (start and end of the device's last discovery).
     *
     * @return array<int, array>
     */
    public function routeData(): array;

    /**
     * Was the route table left unread (larger than a trace reads)?
     */
    public function skipped(): bool;

    /**
     * May the user's LibreNMS role not view routes (no route data then)?
     */
    public function routesHidden(): bool;
}
