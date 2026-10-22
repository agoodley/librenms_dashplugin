<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit\Fakes;

use Agoodley\LibrenmsAnsible\Runs\EventSink;

/**
 * The eventlog entries the run lifecycle writes, for its tests.
 */
final class RecordingEvents implements EventSink
{
    /** @var array<int, array{message: string, device: ?int, severity: string, reference: string, user: ?string}> */
    public array $entries = [];

    public function write(string $message, ?int $deviceId, string $severity, string $reference, ?string $username = null): void
    {
        $this->entries[] = ['message' => $message, 'device' => $deviceId, 'severity' => $severity, 'reference' => $reference, 'user' => $username];
    }

    /**
     * The entries of a device.
     *
     * @return array<int, array{message: string, device: ?int, severity: string, reference: string, user: ?string}>
     */
    public function of(?int $deviceId): array
    {
        return array_values(array_filter($this->entries, fn ($e) => $e['device'] === $deviceId));
    }
}
