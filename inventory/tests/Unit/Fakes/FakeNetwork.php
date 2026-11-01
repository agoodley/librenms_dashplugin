<?php

namespace Agoodley\LibrenmsInventory\Tests\Unit\Fakes;

use Agoodley\LibrenmsInventory\Data\Network;

/**
 * The devices of a test: id => name, os, hardware, version, models, and optionally groups and disabled.
 */
final class FakeNetwork implements Network
{
    /** @var array<int, array> */
    public array $devices = [];

    /** @var string[] */
    public array $groups = [];

    /** @var int[] devices LibreNMS has besides those of $devices */
    public array $others = [];

    public function devices(string $group = ''): array
    {
        if ($group !== '' && ! in_array($group, $this->groups, true)) {
            throw new \InvalidArgumentException("LibreNMS has no device group $group");
        }
        $devices = [];
        foreach ($this->devices as $id => $device) {
            if (! empty($device['disabled']) || ($group !== '' && ! in_array($group, $device['groups'] ?? [], true))) {
                continue;
            }
            $hardware = (string) ($device['hardware'] ?? '');
            $devices[$id] = ['name' => (string) $device['name'], 'os' => (string) $device['os'], 'hardware' => $hardware, 'version' => (string) ($device['version'] ?? ''),
                'models' => $device['models'] ?? ($hardware !== '' ? [$hardware] : [])];
        }

        return $devices;
    }

    public function deviceIds(): array
    {
        return array_merge(array_keys($this->devices), $this->others);
    }

    public function groups(): array
    {
        return $this->groups;
    }
}
