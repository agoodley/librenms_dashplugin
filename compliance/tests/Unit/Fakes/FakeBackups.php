<?php

namespace Agoodley\LibrenmsCompliance\Tests\Unit\Fakes;

use Agoodley\LibrenmsCompliance\Services\Backup;
use Agoodley\LibrenmsCompliance\Services\Backups;

/**
 * The backups of the devices, as a test sets them; which devices were asked for theirs. A provider set unreachable
 * fails every device it backs up, as LibrenmsBackups does after the first.
 */
final class FakeBackups implements Backups
{
    /** @var array<int, Backup> */
    public array $backups = [];

    /** @var int[] */
    public array $asked = [];

    public ?string $unavailable = null;

    public function unavailable(): ?string
    {
        return $this->unavailable;
    }

    public function latest(int $deviceId): Backup
    {
        $this->asked[] = $deviceId;

        return $this->backups[$deviceId] ?? Backup::missing(null, 'No configuration backup tool of LibreNMS backs this device up.');
    }
}
