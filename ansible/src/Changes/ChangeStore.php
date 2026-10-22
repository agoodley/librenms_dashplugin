<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * Where the change lifecycle keeps changes (Data\ChangeRepository; an in-memory one in the tests): the change, its
 * targets (with their batch, dry run and apply results and backups), its batches, approvals, maintenance windows, the
 * diffs of its dry run and its timeline. Every change of a change's own row is a compare-and-set on its version, so a
 * slow request never writes over a newer state; the other rows are written only after such a change succeeded.
 */
interface ChangeStore
{
    /**
     * One change, its JSON columns decoded; null when there is none.
     */
    public function find(int $id): ?array;

    /**
     * Change a change that is still at $version, bumping the version. False when it changed meanwhile: nothing was written.
     *
     * @param  array<string, mixed>  $values  column => value (JSON columns as arrays)
     */
    public function updateAt(int $id, int $version, array $values): bool;

    /**
     * The change's targets in their order.
     *
     * @return array<int, array> device_id => row
     */
    public function targets(int $changeId): array;

    /**
     * Write columns of some targets.
     *
     * @param  array<int, array<string, mixed>>  $values  device_id => column => value
     */
    public function updateTargets(int $changeId, array $values): void;

    /**
     * The change's batches.
     *
     * @return array<int, array> number (1 is the first) => row
     */
    public function batches(int $changeId): array;

    /**
     * Write columns of one batch.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateBatch(int $changeId, int $number, array $values): void;

    /**
     * One entry of the change's timeline.
     */
    public function addEvent(int $changeId, string $kind, string $message, ?string $username, int $at): void;

    /**
     * Record an approval or a rejection.
     *
     * @param  array{change_id: int, user_id: int, username: string, decision: string, comment: string, fingerprint: ?string, dry_run_id: ?int, created_at: int}  $row
     */
    public function addApproval(array $row): void;

    /**
     * Void the change's approvals that still count, with the reason.
     */
    public function voidApprovals(int $changeId, string $reason, int $at): void;

    /**
     * The maintenance windows the plugin opened for the change.
     *
     * @return array<int, array> id => row
     */
    public function windows(int $changeId): array;

    /**
     * Record a maintenance window the plugin opened; its id.
     *
     * @param  array{change_id: int, batch: int, schedule_id: int, status: string, opened_at: int, ends_at: int}  $row
     */
    public function addWindow(array $row): int;

    /**
     * Write columns of a window.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateWindow(int $id, array $values): void;

    /**
     * Devices reserved by a change being applied (ChangeStatus::RESERVING: from its first batch to its end, also while
     * it pauses or stops): no other change may take them.
     *
     * @return array<int, int> device_id => change id
     */
    public function reserved(?int $exceptChange = null): array;

    /**
     * The dry runs and batches in progress, whose runner task may fetch the inventory now (InventoryGuard): a change in its
     * dry run, with all its targets; a batch launching or running of a change that has not ended, with its devices.
     *
     * @return array<int, array{change: array, phase: string, batch: ?array, targets: array<int, array>}>  phase: dry_run or batch
     */
    public function inProgress(): array;

    /**
     * Record what a fetch of the inventory served the runner for a target in progress: the hash of its connection, or null
     * when the host was left out (with why). A target left out stays so (and keeps its first reason) until its next dry run or
     * batch resets it; one served another connection than before is marked InventoryGuard::MIXED. True when this fetch left
     * it out for the first time.
     */
    public function served(int $changeId, int $deviceId, ?string $hash, ?string $note, int $at): bool;

    /**
     * Replace the diffs of the change's dry run.
     *
     * @param  array<int, array{device_id: ?int, task: string, item: ?string, kind: string, lines: string[], cut: bool, host: ?string, reason: ?string}>  $rows
     */
    public function replaceDiffs(int $changeId, int $runId, array $rows): void;
}
