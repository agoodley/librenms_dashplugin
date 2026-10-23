<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * The topology plugin's verification API (Agoodley\LibrenmsTopology\Api\Verification, version 1.x) as the change
 * lifecycle uses it (Services\TopologyVerification; a fake in the tests): how fresh LibreNMS' data is, the scope and the
 * state of a batch's devices, the comparison of two states. Each call sees the network as a LibreNMS user sees it (the
 * admin who starts the batch). A scope and a state are large analyses on a large network: they are taken in a process
 * of their own, by the sync command only (takesStates()), never in a page's request.
 */
interface Verifier
{
    /**
     * Why the verification API cannot be used now (the topology plugin not installed, disabled, too old), null when it can.
     */
    public function unavailable(): ?string;

    /**
     * May this request take scopes and states (the sync command: true; a page's request: false, the sync command takes
     * them within a minute)?
     */
    public function takesStates(): bool;

    /**
     * What to call while a scope or a state is taken (the change lifecycle's heartbeat: the follower renews the change's
     * lock with it, and it throws LockLost when another request took the change over).
     */
    public function setHeartbeat(?\Closure $heartbeat): void;

    /**
     * How fresh LibreNMS' data of the devices is now (those the user may see): device => up, down_since, discovered_at,
     * polled_at, discovery_pending.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array{up: bool, down_since: ?int, discovered_at: ?int, polled_at: ?int, discovery_pending: bool}>
     *
     * @throws TopologyError
     */
    public function freshness(array $deviceIds, int $userId): array;

    /**
     * The scope a state of the devices covers now: subjects, dependents, neighbors, included, domain.
     *
     * @param  int[]  $deviceIds
     * @return array{subjects: int[], dependents: int[], neighbors: int[], included: int[], domain: int[]}
     *
     * @throws TopologyError
     */
    public function scope(array $deviceIds, int $userId): array;

    /**
     * The state of the devices now, covering what an earlier state covered too ($cover: its cover()).
     *
     * @param  int[]  $deviceIds
     * @param  array{include?: int[], domain?: int[]}  $cover
     * @return array<string, mixed>
     *
     * @throws TopologyError
     */
    public function state(array $deviceIds, int $userId, array $cover = []): array;

    /**
     * The difference of two states.
     *
     * @return array<string, mixed>
     *
     * @throws TopologyError
     */
    public function compare(array $before, array $after): array;
}
