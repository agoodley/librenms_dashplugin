<?php

namespace Agoodley\LibrenmsCompliance\Rules;

use Agoodley\LibrenmsCompliance\Config\Config;
use Agoodley\LibrenmsCompliance\Config\Dialect;
use Agoodley\LibrenmsCompliance\Config\InterfaceNames;

/**
 * What a device's check reads: its configuration, its LibreNMS os and the dialect the built-in rules read it in, its
 * switch ports as the configuration has them (Dialect::ports()), each matched to its LibreNMS port, and what each port
 * faces (PortRoles).
 */
final class Context
{
    /** @var array<string, array> name => Dialect::ports() entry */
    public readonly array $ports;

    /** @var array<string, int> port name of the configuration => LibreNMS port id */
    public readonly array $portIds;

    /**
     * @param  array<int, array{ifName?: ?string, ifDescr?: ?string}>  $librenmsPorts  the device's LibreNMS ports by id
     * @param  array<int, array{role: string, neighbors: string[]}>  $roles  PortRoles::compute()
     */
    public function __construct(
        public readonly Config $config,
        public readonly string $os,
        array $librenmsPorts = [],
        public readonly array $roles = [],
    ) {
        $dialect = Dialect::of($os);
        $this->ports = $dialect !== null ? Dialect::ports($dialect, $config) : [];
        $names = new InterfaceNames($librenmsPorts);
        $ids = [];
        foreach (array_keys($this->ports) as $name) {
            $id = $names->port((string) $name);
            if ($id !== null) {
                $ids[(string) $name] = $id;
            }
        }
        $this->portIds = $ids;
    }

    public function dialect(): ?string
    {
        return Dialect::of($this->os);
    }

    /**
     * What a port faces (PortRoles): its LibreNMS port's role; a port-channel LibreNMS does not know faces what its
     * members face; a port LibreNMS does not know, or that has no neighbor, faces a host.
     */
    public function role(string $name): string
    {
        $id = $this->portIds[$name] ?? null;
        if ($id !== null && isset($this->roles[$id])) {
            return $this->roles[$id]['role'];
        }
        $port = $this->ports[$name] ?? null;
        if ($port === null || ! $port['channel']) {
            return PortRoles::HOST;
        }
        $number = preg_replace('~^\D+~', '', $name);
        $roles = [];
        foreach ($this->ports as $member => $facts) {
            if ($facts['member'] === $number) {
                $roles[] = $this->role((string) $member);
            }
        }
        foreach ([PortRoles::UPSTREAM, PortRoles::DOWNSTREAM, PortRoles::SWITCH] as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return PortRoles::HOST;
    }

    /**
     * Who a port faces, as LLDP/CDP names them ("core-sw1 Gi1/0/49"); for a port-channel LibreNMS does not know, its
     * members'.
     *
     * @return string[]
     */
    public function neighbors(string $name): array
    {
        $id = $this->portIds[$name] ?? null;
        if ($id !== null && isset($this->roles[$id])) {
            return $this->roles[$id]['neighbors'];
        }
        $port = $this->ports[$name] ?? null;
        if ($port === null || ! $port['channel']) {
            return [];
        }
        $number = preg_replace('~^\D+~', '', $name);
        $neighbors = [];
        foreach ($this->ports as $member => $facts) {
            if ($facts['member'] === $number) {
                array_push($neighbors, ...$this->neighbors((string) $member));
            }
        }

        return array_values(array_unique($neighbors));
    }

    /**
     * The ports spanning tree runs on as their own: port-channels and the physical ports that are no members of one,
     * not shut down.
     *
     * @return array<string, array>
     */
    public function stpPorts(): array
    {
        return array_filter($this->ports, fn (array $port) => $port['member'] === null && ! $port['shutdown'] && $port['mode'] !== 'routed');
    }
}
