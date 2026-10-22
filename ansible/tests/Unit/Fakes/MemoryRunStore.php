<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit\Fakes;

use Agoodley\LibrenmsAnsible\Runs\RunStore;

/**
 * Runs in memory, with the repository's compare-and-set: the run lifecycle's tests. $beforeUpdate runs once before
 * the next change, to let another process change the run in between (a race).
 */
final class MemoryRunStore implements RunStore
{
    /** @var array<int, array> */
    public array $runs = [];

    /** @var array<int, array<int, array>> */
    public array $targets = [];

    public ?\Closure $beforeUpdate = null;

    /** Changes refused because the run had changed meanwhile. */
    public int $conflicts = 0;

    /**
     * A run as the launcher records it, with its targets (device_id => inventory name).
     *
     * @param  array<int, string>  $targets
     */
    public function add(array $run, array $targets, string $createdAt = '2026-10-22 10:00:00'): int
    {
        $id = count($this->runs) + 1;
        $this->runs[$id] = $run + ['id' => $id, 'username' => 'alice', 'template_id' => '11', 'template_name' => 'Show version', 'status' => 'pending',
            'version' => 0, 'runner_task_id' => null, 'runner_status' => null, 'output' => null, 'output_tail' => null, 'output_lines' => 0,
            'output_truncated' => false, 'output_omitted' => 0, 'summary' => null, 'unexpected_hosts' => null, 'error' => null, 'poll_error' => null,
            'stopped_by' => null, 'stop_requested_at' => null, 'stop_reason' => null, 'released_by' => null, 'started_at' => null, 'finished_at' => null,
            'polled_at' => null, 'runner_final_at' => null, 'runner_seen_at' => null, 'missing_since' => null, 'missing_checks' => 0, 'watch_until' => null,
            'launch_nonce' => 'abcdef123456', 'created_at' => $createdAt];
        foreach ($targets as $deviceId => $name) {
            $this->targets[$id][$deviceId] = ['device_id' => $deviceId, 'inventory_name' => $name, 'hostname' => $name, 'display' => $name,
                'result' => null, 'counts' => null, 'unexpected' => false];
        }

        return $id;
    }

    public function find(int $id): ?array
    {
        return $this->runs[$id] ?? null;
    }

    public function targets(int $runId): array
    {
        return $this->targets[$runId] ?? [];
    }

    public function updateAt(int $id, int $version, array $values, string $appendOutput = ''): bool
    {
        if ($this->beforeUpdate !== null) {
            $hook = $this->beforeUpdate;
            $this->beforeUpdate = null;
            $hook();
        }
        if (! isset($this->runs[$id]) || (int) $this->runs[$id]['version'] !== $version) {
            $this->conflicts++;

            return false;
        }
        $run = array_merge($this->runs[$id], $values);
        if ($appendOutput !== '') {
            $run['output'] = (string) $run['output'] . $appendOutput;
        }
        $run['version'] = $version + 1;
        $this->runs[$id] = $run;

        return true;
    }

    public function setResults(int $runId, array $results): void
    {
        foreach ($results as $deviceId => $result) {
            $this->targets[$runId][$deviceId]['result'] = $result['result'];
            $this->targets[$runId][$deviceId]['counts'] = $result['counts'];
        }
    }

    public function addUnexpected(int $runId, array $rows): void
    {
        foreach ($rows as $deviceId => $row) {
            $this->targets[$runId][$deviceId] ??= ['device_id' => $deviceId, 'inventory_name' => $row['inventory_name'], 'hostname' => $row['hostname'],
                'display' => $row['display'], 'result' => $row['result'], 'counts' => $row['counts'], 'unexpected' => true];
        }
    }
}
