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
     * The inventory without some of its hosts (they are left out with why): what the runner gets while their
     * connection is not the one a change's dry run or batch in progress recorded (Changes\InventoryGuard).
     *
     * @param  array<int, array{reason: string, text: string}>  $leave  device_id => why
     */
    public function without(array $leave): self
    {
        $names = array_diff_key($this->names, $leave);
        $gone = array_flip(array_values(array_intersect_key($this->names, $leave)));
        $groups = array_map(fn (array $members) => array_values(array_filter($members, fn ($member) => ! isset($gone[$member]))), $this->groups);
        $leftOut = $this->leftOut;
        foreach ($leave as $deviceId => $why) {
            if (isset($this->names[$deviceId])) {
                $leftOut[$deviceId] = $why;
            }
        }
        ksort($leftOut);

        return new self(array_diff_key($this->hosts, $gone), $names, $groups, $this->groupInfo, $leftOut);
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
