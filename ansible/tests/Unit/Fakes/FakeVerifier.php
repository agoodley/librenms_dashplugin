<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit\Fakes;

use Agoodley\LibrenmsAnsible\Changes\TopologyError;
use Agoodley\LibrenmsAnsible\Changes\Verifier;

/**
 * The topology plugin's verification API as the change lifecycle sees it, on a small network: each device's freshness is
 * the test's (one time for every device unless set per device), a scope is the batch's devices with the neighbors the test
 * gives, a state carries the findings the test sets now, and the comparison of two states is the test's. Calls are
 * recorded with the time the test says it is ($now), so that the tests see when states were taken.
 */
final class FakeVerifier implements Verifier
{
    public ?string $unavailable = null;

    public bool $takes = true;

    /** The time the test is at (a state's taken_at). */
    public int $now = 0;

    /** When every device's last discovery and poll started (unless set in $devices). */
    public ?int $discoveredAt = null;

    public ?int $polledAt = null;

    /** @var array<int, array> device => its freshness, over the defaults */
    public array $devices = [];

    /** @var array<int, int[]> device => its neighbors (covered by its states) */
    public array $neighbors = [];

    /** @var int[] the other spanning tree switches of the domain */
    public array $domain = [];

    /** @var array<string, array> identity => finding, in every state taken from now on */
    public array $findings = [];

    /** @var array<string, mixed> what compare() answers (events, findings, devices, names) */
    public array $comparison = [];

    /** @var array<string, \Throwable> method => what it throws */
    public array $failures = [];

    /** @var list<array{0: string, 1: int[], 2: int, 3: int}> method, devices, user, time */
    public array $calls = [];

    public ?\Closure $heartbeat = null;

    public function unavailable(): ?string
    {
        return $this->unavailable;
    }

    public function takesStates(): bool
    {
        return $this->takes;
    }

    public function setHeartbeat(?\Closure $heartbeat): void
    {
        $this->heartbeat = $heartbeat;
    }

    public function freshness(array $deviceIds, int $userId): array
    {
        $this->record('freshness', $deviceIds, $userId);
        $states = [];
        foreach ($deviceIds as $deviceId) {
            $states[(int) $deviceId] = ($this->devices[(int) $deviceId] ?? []) + ['up' => true, 'down_since' => null, 'discovered_at' => $this->discoveredAt,
                'polled_at' => $this->polledAt, 'discovery_pending' => false];
        }

        return $states;
    }

    public function scope(array $deviceIds, int $userId): array
    {
        $this->record('scope', $deviceIds, $userId);

        return $this->scopeOf($deviceIds, []) + ['names' => self::names()];
    }

    public function state(array $deviceIds, int $userId, array $cover = []): array
    {
        $this->record('state', $deviceIds, $userId);
        $scope = $this->scopeOf($deviceIds, (array) ($cover['include'] ?? []));
        $devices = [];
        foreach ([...$scope['subjects'], ...$scope['neighbors'], ...$scope['included'], ...$scope['domain']] as $deviceId) {
            $devices[$deviceId] = ['role' => 'subject', 'up' => true, 'down_since' => null, 'discovered_at' => $this->discoveredAt, 'polled_at' => $this->polledAt,
                'discovery_pending' => false];
        }

        return ['version' => '1.0', 'taken_at' => $this->now, 'scope' => $scope, 'devices' => $devices, 'facts' => ['f1' => ['kind' => 'link', 'key' => 'k', 'a' => 1, 'b' => 2,
            'text' => 'link', 'data' => []]], 'roots' => [], 'findings' => $this->findings, 'names' => self::names(), 'checks' => ['enabled' => [], 'failed' => [],
            'facts_failed' => []], 'role_hidden' => []];
    }

    public function compare(array $before, array $after): array
    {
        $this->record('compare', [], 0);

        return $this->comparison + ['version' => '1.0', 'from' => (int) $before['taken_at'], 'to' => (int) $after['taken_at'], 'events' => [],
            'findings' => ['new' => [], 'worse' => [], 'changed' => [], 'milder' => [], 'resolved' => [], 'same' => 0], 'devices' => ['down' => [], 'up' => []], 'names' => self::names()];
    }

    /**
     * How many times a method was called.
     */
    public function count(string $method): int
    {
        return count(array_filter($this->calls, fn (array $call) => $call[0] === $method));
    }

    /**
     * The times the states were taken.
     *
     * @return int[]
     */
    public function stateTimes(): array
    {
        return array_values(array_map(fn (array $call) => $call[3], array_filter($this->calls, fn (array $call) => $call[0] === 'state')));
    }

    /**
     * @param  int[]  $devices
     * @param  int[]  $include
     */
    private function scopeOf(array $devices, array $include): array
    {
        $devices = array_values(array_map('intval', $devices));
        $neighbors = array_values(array_diff(array_unique(array_merge([], ...array_map(fn (int $id) => $this->neighbors[$id] ?? [], $devices))), $devices));

        return ['subjects' => $devices, 'dependents' => [], 'neighbors' => $neighbors,
            'included' => array_values(array_diff(array_map('intval', $include), $devices, $neighbors)), 'domain' => array_values(array_diff($this->domain, $devices, $neighbors))];
    }

    private function record(string $method, array $devices, int $userId): void
    {
        if (isset($this->failures[$method])) {
            throw $this->failures[$method];
        }
        $this->calls[] = [$method, array_values(array_map('intval', $devices)), $userId, $this->now];
    }

    /**
     * @return array<int, string>
     */
    private static function names(): array
    {
        return [501 => 'lab-sw1', 502 => 'lab-sw2', 503 => 'lab-sw3', 510 => 'lab-sw4', 511 => 'lab-sw5', 512 => 'lab-sw6'];
    }

    /**
     * The verification API refusing (the topology plugin disabled meanwhile).
     */
    public static function refusal(string $why): TopologyError
    {
        return new TopologyError($why);
    }
}
