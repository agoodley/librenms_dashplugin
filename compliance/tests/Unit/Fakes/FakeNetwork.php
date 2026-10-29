<?php

namespace Agoodley\LibrenmsCompliance\Tests\Unit\Fakes;

use Agoodley\LibrenmsCompliance\Data\Network;

/**
 * LibreNMS' devices, ports and what they face, as a test sets them.
 */
final class FakeNetwork implements Network
{
    /** @var array<int, array{name: string, os: string, disabled?: bool, groups?: string[]}> */
    public array $devices = [];

    /** @var array<int, array<int, array{ifName: ?string, ifDescr: ?string}>> */
    public array $ports = [];

    /** @var array<int, array> */
    public array $neighborhoods = [];

    /** @var string[] */
    public array $groups = [];

    public function devices(string $group = ''): array
    {
        if ($group !== '' && ! in_array($group, $this->groups, true)) {
            throw new \InvalidArgumentException("LibreNMS has no device group $group (the Device group setting)");
        }
        $devices = [];
        foreach ($this->devices as $id => $device) {
            if (empty($device['disabled']) && ($group === '' || in_array($group, $device['groups'] ?? [], true))) {
                $devices[$id] = ['name' => $device['name'], 'os' => $device['os']];
            }
        }

        return $devices;
    }

    public function device(int $deviceId): ?array
    {
        $device = $this->devices[$deviceId] ?? null;

        return $device === null ? null : ['name' => $device['name'], 'os' => $device['os'], 'disabled' => ! empty($device['disabled'])];
    }

    public function ports(int $deviceId): array
    {
        return $this->ports[$deviceId] ?? [];
    }

    public function neighborhood(int $deviceId): array
    {
        return $this->neighborhoods[$deviceId] ?? ['links' => [], 'bridges' => [], 'rootPorts' => [], 'lagParent' => [], 'names' => []];
    }
}
