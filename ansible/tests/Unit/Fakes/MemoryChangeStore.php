<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit\Fakes;

use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Changes\ChangeStore;
use Agoodley\LibrenmsAnsible\Changes\InventoryGuard;
use Agoodley\LibrenmsAnsible\Support\Clock;

/**
 * Changes in memory, with the repository's compare-and-set: the change lifecycle's tests. $beforeUpdate runs once
 * before the next change of a change's row (another request writing in between).
 */
final class MemoryChangeStore implements ChangeStore
{
    /** @var array<int, array> */
    public array $changes = [];

    /** @var array<int, array<int, array>> */
    public array $targets = [];

    /** @var array<int, array<int, array>> */
    public array $batches = [];

    /** @var array<int, array<int, array>> change id => events */
    public array $events = [];

    /** @var array<int, array> */
    public array $approvals = [];

    /** @var array<int, array> */
    public array $windows = [];

    /** @var array<int, array<int, array>> change id => diff rows */
    public array $diffs = [];

    /** @var array<string, array{covered: int[], domain: int[], taken_at: ?string, state: ?array, result: ?array}> "change|batch|phase" => the state kept */
    public array $states = [];

    public ?\Closure $beforeUpdate = null;

    /** @var \Closure|null runs once right after the next change of a change's row that succeeded (another request writing right after it) */
    public ?\Closure $afterUpdate = null;

    public int $conflicts = 0;

    /**
     * A change as the change service records it (a draft), with its targets in batches by the plan.
     *
     * @param  array<int, string>  $targets  device_id => inventory name
     */
    public function add(array $targets, BatchPlan $plan, array $change = []): int
    {
        $id = count($this->changes) + 1;
        $this->changes[$id] = $change + ['id' => $id, 'user_id' => 1, 'username' => 'alice', 'template_id' => '77', 'template_name' => 'Port description',
            'playbook' => 'desc.yml', 'reference' => 'CHG-1', 'status' => 'draft', 'version' => 0,
            'variables' => [['name' => 'interface', 'label' => 'Interface', 'type' => 'enum', 'value' => 'Fa0/1', 'secret' => false]],
            'has_secrets' => false, 'plan' => $plan->toArray(), 'target_count' => count($targets), 'batch_count' => $plan->count(), 'current_batch' => 0,
            'dry_run_id' => null, 'dry_runs' => 0, 'dry_run_state' => null, 'dry_run_at' => null, 'dry_run_ended_at' => null, 'dry_run_commit' => null,
            'dry_run_findings' => null, 'plan_fingerprint' => null, 'fingerprint_parts' => null, 'approval_fingerprint' => null, 'approved_by' => null,
            'approved_user_id' => null, 'approved_at' => null, 'applied_by' => null, 'applied_user_id' => null, 'apply_started_at' => null, 'waiting' => null,
            'resume_at' => null, 'stop_requested_by' => null, 'stop_requested_at' => null, 'error' => null, 'finished_at' => null,
            'created_at' => '2026-10-22 10:00:00', 'fix' => null, 'verification' => null, 'verification_note' => null, 'approval_mode' => null];
        $position = 0;
        foreach ($targets as $deviceId => $name) {
            $this->targets[$id][$deviceId] = ['change_id' => $id, 'device_id' => $deviceId, 'inventory_name' => $name, 'hostname' => $name, 'display' => strtok($name, '.'),
                'batch' => (int) $plan->batchOf($deviceId), 'position' => $position++, 'dry_result' => null, 'dry_counts' => null, 'result' => null, 'counts' => null,
                'backup_node' => null, 'backup_check' => null, 'before_status' => null, 'before_requested_at' => null, 'before_at' => null, 'before_version' => null, 'before_error' => null,
                'after_status' => null, 'after_requested_at' => null, 'after_at' => null, 'after_version' => null, 'after_error' => null,
                'served_hash' => null, 'served_at' => null, 'served_note' => null];
        }
        foreach ($plan->batches as $index => $devices) {
            $this->batches[$id][$index + 1] = ['change_id' => $id, 'number' => $index + 1, 'status' => 'pending', 'run_id' => null, 'failures' => 0,
                'started_at' => null, 'ended_at' => null, 'note' => null, 'user_id' => null, 'username' => null, 'blast' => null, 'blast_accepted' => null,
                'blast_override' => null, 'verify' => null];
        }

        return $id;
    }

    public function find(int $id): ?array
    {
        return $this->changes[$id] ?? null;
    }

    public function updateAt(int $id, int $version, array $values): bool
    {
        if ($this->beforeUpdate !== null) {
            $hook = $this->beforeUpdate;
            $this->beforeUpdate = null;
            $hook();
        }
        if (! isset($this->changes[$id]) || (int) $this->changes[$id]['version'] !== $version) {
            $this->conflicts++;

            return false;
        }
        $this->changes[$id] = array_merge($this->changes[$id], $values, ['version' => $version + 1]);
        if ($this->afterUpdate !== null) {
            $hook = $this->afterUpdate;
            $this->afterUpdate = null;
            $hook();
        }

        return true;
    }

    public function targets(int $changeId): array
    {
        return $this->targets[$changeId] ?? [];
    }

    public function updateTargets(int $changeId, array $values): void
    {
        foreach ($values as $deviceId => $columns) {
            $this->targets[$changeId][$deviceId] = array_merge($this->targets[$changeId][$deviceId], $columns);
        }
    }

    public function batches(int $changeId): array
    {
        return $this->batches[$changeId] ?? [];
    }

    public function updateBatch(int $changeId, int $number, array $values): void
    {
        $this->batches[$changeId][$number] = array_merge($this->batches[$changeId][$number], $values);
    }

    public function addEvent(int $changeId, string $kind, string $message, ?string $username, int $at): void
    {
        $this->events[$changeId][] = ['kind' => $kind, 'message' => $message, 'username' => $username, 'at' => Clock::store($at)];
    }

    public function addApproval(array $row): void
    {
        $this->approvals[] = $row + ['voided_at' => null, 'void_reason' => null];
    }

    public function voidApprovals(int $changeId, string $reason, int $at): void
    {
        foreach ($this->approvals as &$approval) {
            if ($approval['change_id'] === $changeId && $approval['voided_at'] === null && $approval['decision'] === 'approved') {
                [$approval['voided_at'], $approval['void_reason']] = [$at, $reason];
            }
        }
    }

    public function windows(int $changeId): array
    {
        return array_filter($this->windows, fn ($window) => $window['change_id'] === $changeId);
    }

    public function addWindow(array $row): int
    {
        $id = count($this->windows) + 1;
        $this->windows[$id] = ['opened_at' => Clock::store($row['opened_at']), 'ends_at' => Clock::store($row['ends_at']), 'closed_at' => null, 'closing' => false,
            'error' => null, 'cut_off' => ($row['cut_off'] ?? []) ? array_values($row['cut_off']) : null] + $row;

        return $id;
    }

    public function updateWindow(int $id, array $values): void
    {
        $this->windows[$id] = array_merge($this->windows[$id], $values);
    }

    public function reserved(?int $exceptChange = null): array
    {
        $reserved = [];
        foreach ($this->changes as $id => $change) {
            if ($id !== $exceptChange && in_array($change['status'], ChangeStatus::RESERVING, true)) {
                foreach (array_keys($this->targets[$id] ?? []) as $deviceId) {
                    $reserved[$deviceId] = $id;
                }
            }
        }

        return $reserved;
    }

    public function inProgress(): array
    {
        $found = [];
        foreach ($this->changes as $id => $change) {
            if ($change['status'] === ChangeStatus::DRY_RUN) {
                $found[] = ['change' => $change, 'phase' => 'dry_run', 'batch' => null, 'targets' => $this->targets[$id] ?? []];

                continue;
            }
            if (ChangeStatus::isFinal($change['status'])) {
                continue;
            }
            foreach ($this->batches[$id] ?? [] as $number => $batch) {
                if (in_array($batch['status'], ['launching', 'running'], true)) {
                    $found[] = ['change' => $change, 'phase' => 'batch', 'batch' => $batch,
                        'targets' => array_filter($this->targets[$id] ?? [], fn (array $target) => (int) $target['batch'] === $number)];
                }
            }
        }

        return $found;
    }

    public function served(int $changeId, int $deviceId, ?string $hash, ?string $note, int $at): bool
    {
        $target = &$this->targets[$changeId][$deviceId];
        if ($hash === null) {
            if ($target['served_hash'] === InventoryGuard::LEFT_OUT) {
                return false;
            }
            [$target['served_hash'], $target['served_note'], $target['served_at']] = [InventoryGuard::LEFT_OUT, mb_substr((string) $note, 0, 500), Clock::store($at)];

            return true;
        }
        if (! in_array($target['served_hash'], [InventoryGuard::LEFT_OUT, InventoryGuard::MIXED], true)) {
            $target['served_at'] = Clock::store($at);
            $target['served_hash'] = $target['served_hash'] === null || $target['served_hash'] === $hash ? $hash : InventoryGuard::MIXED;
        }

        return false;
    }

    public function saveScope(int $changeId, int $batch, string $phase, array $covered, array $domain, array $names = []): void
    {
        $key = "$changeId|$batch|$phase";
        $this->states[$key] = ['covered' => array_values(array_map('intval', $covered)), 'domain' => array_values(array_map('intval', $domain)), 'names' => $names]
            + ($this->states[$key] ?? ['taken_at' => null, 'state' => null, 'result' => null]);
    }

    public function scopeOf(int $changeId, int $batch, string $phase): ?array
    {
        $kept = $this->states["$changeId|$batch|$phase"] ?? null;

        return $kept === null ? null : ['covered' => $kept['covered'], 'domain' => $kept['domain'], 'names' => $kept['names'], 'taken_at' => $kept['taken_at']];
    }

    public function saveState(int $changeId, int $batch, string $phase, array $state, ?array $result = null): void
    {
        if (strlen((string) json_encode($state)) > \Agoodley\LibrenmsAnsible\Data\ChangeRepository::STATE_MAX) {
            throw new \LengthException('the state is larger than the plugin keeps');
        }
        $scope = (array) ($state['scope'] ?? []);
        $covered = array_merge(...array_map(fn ($role) => array_map('intval', (array) ($scope[$role] ?? [])), ['subjects', 'dependents', 'neighbors', 'included']));
        $this->saveScope($changeId, $batch, $phase, $covered, array_map('intval', (array) ($scope['domain'] ?? [])),
            array_intersect_key((array) ($state['names'] ?? []), array_flip($covered)));
        $key = "$changeId|$batch|$phase";
        $this->states[$key]['taken_at'] = Clock::store((int) ($state['taken_at'] ?? 0));
        $this->states[$key]['state'] = json_decode((string) json_encode($state), true); // as read back: JSON
        $this->states[$key]['result'] = $result !== null ? json_decode((string) json_encode($result), true) : null;
    }

    public function stateOf(int $changeId, int $batch, string $phase): ?array
    {
        return $this->states["$changeId|$batch|$phase"]['state'] ?? null;
    }

    public function resultOf(int $changeId, int $batch): ?array
    {
        return $this->states["$changeId|$batch|after"]['result'] ?? null;
    }

    public function replaceDiffs(int $changeId, int $runId, array $rows): void
    {
        $this->diffs[$changeId] = array_map(fn ($row) => $row + ['run_id' => $runId], $rows);
    }

    /**
     * The timeline messages of a change.
     *
     * @return string[]
     */
    public function messages(int $changeId): array
    {
        return array_column($this->events[$changeId] ?? [], 'message');
    }
}
