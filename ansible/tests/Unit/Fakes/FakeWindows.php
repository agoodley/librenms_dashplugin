<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit\Fakes;

use Agoodley\LibrenmsAnsible\Changes\MaintenanceWindows;

/**
 * LibreNMS maintenance windows in memory.
 */
final class FakeWindows implements MaintenanceWindows
{
    /** @var array<int, array{title: string, notes: string, devices: int[], start: int, end: int, behavior: ?int}> */
    public array $windows = [];

    public ?string $openError = null;

    public function open(string $title, string $notes, array $deviceIds, int $start, int $end, ?int $behavior): int
    {
        if ($this->openError !== null) {
            throw new \RuntimeException($this->openError);
        }
        $id = 100 + count($this->windows);
        $this->windows[$id] = ['title' => $title, 'notes' => $notes, 'devices' => $deviceIds, 'start' => $start, 'end' => $end, 'behavior' => $behavior];

        return $id;
    }

    public function setEnd(int $id, int $end): bool
    {
        if (! isset($this->windows[$id])) {
            return false;
        }
        $this->windows[$id]['end'] = $end;

        return true;
    }
}
