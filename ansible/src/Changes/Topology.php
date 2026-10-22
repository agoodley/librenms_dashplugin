<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * The topology plugin's planning API as the change lifecycle and the new change page use it (Services\TopologyPlanning;
 * a fake in the tests): the rollout plan of a change's targets and the blast radius of a batch, on the network as a
 * LibreNMS user sees it now. The answers are the API's own (version 1.x), read with RolloutPlan and BlastRadius.
 */
interface Topology
{
    /**
     * Why the topology plugin cannot plan now (not installed, disabled in LibreNMS, a planning API this plugin does not
     * speak), null when it can.
     */
    public function unavailable(): ?string;

    /**
     * The rollout plan of the devices in batches of at most $batchSize, as the user sees the network.
     *
     * @param  int[]  $deviceIds
     * @return array<string, mixed> the planning API's rollout() answer
     *
     * @throws TopologyError when it cannot be had now, with why
     */
    public function rollout(array $deviceIds, int $batchSize, int $userId): array;

    /**
     * The blast radius of the devices going down together, as the user sees the network now.
     *
     * @param  int[]  $deviceIds
     * @return array<string, mixed> the planning API's blastRadius() answer
     *
     * @throws TopologyError when it cannot be computed now, with why
     */
    public function blastRadius(array $deviceIds, int $userId): array;
}
