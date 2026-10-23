<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit\Fakes;

use Agoodley\LibrenmsAnsible\Changes\DataRefresh;

/**
 * LibreNMS asked for fresh data: the requests are recorded; an error makes it fail.
 */
final class FakeRefresh implements DataRefresh
{
    /** @var list<int[]> */
    public array $requests = [];

    public ?string $error = null;

    public function request(array $deviceIds): array
    {
        if ($this->error !== null) {
            throw new \RuntimeException($this->error);
        }
        $this->requests[] = array_values(array_map('intval', $deviceIds));

        return ['how' => 'dispatch', 'devices' => array_values(array_map('intval', $deviceIds))];
    }
}
