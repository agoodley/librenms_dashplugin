<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit\Fakes;

use Agoodley\LibrenmsAnsible\Changes\Topology;
use Agoodley\LibrenmsAnsible\Changes\TopologyError;
use Agoodley\LibrenmsAnsible\Tests\Unit\RolloutPlanTest;

/**
 * The topology plugin's planning API on the lab (RolloutPlanTest::planner() and blast()): what each device alone cuts off
 * now is the test's ($cuts: a link taken down makes lab-sw2 cut off lab-sw5 too), an error makes it unable to answer.
 * Calls are recorded with the user they were made for.
 */
final class FakeTopology implements Topology
{
    /** @var array<int, int[]> device => what it alone cuts off now */
    public array $cuts = RolloutPlanTest::CUTS;

    /** @var array<int, string> device => why the topology can tell nothing about it now (down, isolated, unconnected, unknown) */
    public array $skips = [];

    /** @var int[] the last core devices of their part now (the other core devices are down): a batch with one takes every core device down */
    public array $lastCore = [];

    public ?string $unavailable = null;

    /** blastRadius() fails with this error (the topology plugin disabled, an error). */
    public ?string $error = null;

    /** @var array<int, array{devices: int[], user: int}> */
    public array $calls = [];

    /** @var \Closure|null called at each blastRadius() (something happening at that moment) */
    public ?\Closure $onBlast = null;

    public function unavailable(): ?string
    {
        return $this->unavailable;
    }

    public function rollout(array $deviceIds, int $batchSize, int $userId): array
    {
        return RolloutPlanTest::planner($deviceIds, $batchSize);
    }

    public function blastRadius(array $deviceIds, int $userId): array
    {
        $this->calls[] = ['devices' => $deviceIds, 'user' => $userId];
        if ($this->onBlast !== null) {
            ($this->onBlast)($deviceIds);
        }
        if ($this->error !== null) {
            throw new TopologyError($this->error);
        }

        $answer = RolloutPlanTest::blast($deviceIds, $this->cuts, $this->skips);
        if (array_intersect(array_map('intval', $deviceIds), $this->lastCore)) {
            $answer['reference'] = 'largest part'; // what it cuts off is measured from the largest piece left
        }

        return $answer;
    }
}
