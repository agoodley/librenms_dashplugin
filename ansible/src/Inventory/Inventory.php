<?php

namespace Agoodley\LibrenmsAnsible\Inventory;

/**
 * The Ansible inventory built from LibreNMS (InventoryBuilder): its hosts with their variables, its groups,
 * and the devices left out with why. The same object answers the runner (toAnsible()), shows the Inventory
 * page and decides which devices can be a run's targets and by which name.
 */
final class Inventory
{
    /**
     * @param  array<string, array<string, mixed>>  $hosts  inventory name => host variables
     * @param  array<int, string>  $names  device_id => inventory name, for the hosts
     * @param  array<string, string[]>  $groups  group name => inventory names
     * @param  array<string, array{kind: string, label: string}>  $groupInfo  group name => what it stands for
     * @param  array<int, array{reason: string, text: string}>  $leftOut  device_id => why it is not a host
     */
    public function __construct(
        private readonly array $hosts,
        private readonly array $names,
        private readonly array $groups,
        private readonly array $groupInfo,
        private readonly array $leftOut,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>> inventory name => host variables
     */
    public function hosts(): array
    {
        return $this->hosts;
    }

    /**
     * @return array<int, string> device_id => inventory name
     */
    public function names(): array
    {
        return $this->names;
    }

    /**
     * The inventory name of a device, null when it is not a host.
     */
    public function nameOf(int $deviceId): ?string
    {
        return $this->names[$deviceId] ?? null;
    }

    /**
     * @return array<string, string[]> group name => inventory names
     */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * @return array<string, array{kind: string, label: string}> group name => kind (os, platform, group, location, type) and label
     */
    public function groupInfo(): array
    {
        return $this->groupInfo;
    }

    /**
     * @return array<int, array{reason: string, text: string}> device_id => why it was left out
     */
    public function leftOut(): array
    {
        return $this->leftOut;
    }

    /**
     * The inventory as Ansible's dynamic inventory JSON (the script inventory's --list): every group with its
     * hosts, "all" with the groups as children, and every host's variables in _meta.hostvars, so Ansible never
     * needs to ask per host.
     *
     * @return array<string, mixed>
     */
    public function toAnsible(): array
    {
        $result = ['_meta' => ['hostvars' => $this->hosts ?: new \stdClass()]];
        $result['all'] = ['children' => array_keys($this->groups)];
        foreach ($this->groups as $group => $members) {
            $result[$group] = ['hosts' => $members];
        }

        return $result;
    }

    /**
     * Variables of one host (the script inventory's --host), an empty object for an unknown name.
     */
    public function hostVars(string $name): array|\stdClass
    {
        return $this->hosts[$name] ?? new \stdClass();
    }
}
