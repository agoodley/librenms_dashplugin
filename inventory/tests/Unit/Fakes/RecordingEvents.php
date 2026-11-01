<?php

namespace Agoodley\LibrenmsInventory\Tests\Unit\Fakes;

use Agoodley\LibrenmsInventory\Services\EventSink;

/**
 * The eventlog entries a test's checks write.
 */
final class RecordingEvents implements EventSink
{
    /** @var array<int, array{message: string, device: ?int, severity: string}> */
    public array $entries = [];

    public function write(string $message, ?int $deviceId, string $severity): void
    {
        $this->entries[] = ['message' => $message, 'device' => $deviceId, 'severity' => $severity];
    }
}
