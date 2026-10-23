<?php

namespace Agoodley\LibrenmsAnsible\Data;

use Agoodley\LibrenmsAnsible\Changes\BackupState;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Changes\ChangeStore;
use Agoodley\LibrenmsAnsible\Changes\InventoryGuard;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Changes (ansible_changes) with their targets, batches, approvals, maintenance windows, dry-run diffs and timeline.
 * Only LibreNMS admins see them, and every admin sees every change, so queries are not scoped by device. Rows come as
 * arrays with their JSON columns decoded. The change lifecycle writes a change's row only through updateAt(), a
 * compare-and-set on its version. Statements go through an Sql executor (LibreNMS' connection; SQLite in the tests);
 * the changes list and the device panel use Laravel's query builder.
 */
final class ChangeRepository implements ChangeStore
{
    /** JSON columns per table. */
    private const JSON = [
        'ansible_changes' => ['variables', 'plan', 'dry_run_findings', 'fingerprint_parts', 'fix'],
        'ansible_change_targets' => ['dry_counts', 'counts'],
        'ansible_change_diffs' => ['lines'],
        'ansible_change_batches' => ['blast', 'blast_accepted', 'blast_override', 'verify'],
        'ansible_change_windows' => ['cut_off'],
    ];

    /** Boolean columns per table. */
    private const BOOLEANS = ['ansible_changes' => ['has_secrets'], 'ansible_change_windows' => ['closing'], 'ansible_change_diffs' => ['cut']];

    /** Statuses of a batch whose run may fetch the inventory now (InventoryGuard). */
    private const IN_PROGRESS = ['launching', 'running'];

    /** The tables of changes (phase 2, and phase 4's states). */
    public const TABLES = ['ansible_changes', 'ansible_change_targets', 'ansible_change_batches', 'ansible_change_approvals', 'ansible_change_windows',
        'ansible_change_diffs', 'ansible_change_events', 'ansible_change_states'];

    /**
     * Most bytes of a state's JSON the plugin keeps (it keeps it compressed: about a tenth). A state of the large test
     * network (4,922 devices in one spanning tree domain, 10 VLANs) is 2.2 MB of JSON, 0.26 MB compressed.
     */
    public const STATE_MAX = 32 * 1024 * 1024;

    public function __construct(private readonly Sql $sql = new LaravelSql())
    {
    }

    /**
     * Do the tables of changes exist (./lnms migrate ran after the update)?
     */
    public function available(): bool
    {
        try {
            // a table whose columns are checked below is not looked up twice: its columns tell that it exists too
            foreach (array_diff(self::TABLES, ['ansible_changes', 'ansible_change_targets', 'ansible_change_batches', 'ansible_change_windows']) as $table) {
                if (! SchemaCheck::hasTable($table)) {
                    return false;
                }
            }

            return SchemaCheck::hasColumns('ansible_runs', ['change_id', 'kind', 'batch', 'context', 'commit_hash', 'proof'])
                && SchemaCheck::hasColumns('ansible_change_targets', ['backup_check', 'served_hash', 'served_at', 'served_note'])
                && SchemaCheck::hasColumns('ansible_change_batches', ['blast', 'blast_accepted', 'blast_override', 'verify'])
                && SchemaCheck::hasColumns('ansible_change_windows', ['cut_off'])
                && SchemaCheck::hasColumns('ansible_changes', ['fix', 'verification', 'verification_note']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Record a change (a draft), its targets and its batches in one transaction.
     *
     * @param  array<string, mixed>  $change  column => value (JSON columns as arrays)
     * @param  array<int, array{inventory_name: string, hostname: string, display: string, batch: int}>  $targets  device_id => target, in their order
     */
    public function create(array $change, array $targets, int $batches): int
    {
        return $this->sql->transaction(function () use ($change, $targets, $batches): int {
            $now = Clock::store(time());
            $id = $this->insert('ansible_changes', $change + ['created_at' => $now, 'updated_at' => $now]);
            $position = 0;
            foreach ($targets as $deviceId => $target) {
                $this->insert('ansible_change_targets', ['change_id' => $id, 'device_id' => (int) $deviceId, 'inventory_name' => $target['inventory_name'],
                    'hostname' => mb_substr($target['hostname'], 0, 255), 'display' => mb_substr($target['display'], 0, 255), 'batch' => $target['batch'], 'position' => $position++]);
            }
            for ($number = 1; $number <= $batches; $number++) {
                $this->insert('ansible_change_batches', ['change_id' => $id, 'number' => $number, 'status' => 'pending']);
            }

            return $id;
        });
    }

    /**
     * The change a creation form already made, by its one-time token.
     */
    public function byRequestToken(string $token): ?int
    {
        $rows = $this->sql->select('SELECT `id` FROM ' . $this->sql->table('ansible_changes') . ' WHERE `request_token` = ?', [$token]);

        return $rows ? (int) $rows[0]['id'] : null;
    }

    public function find(int $id): ?array
    {
        $rows = $this->sql->select('SELECT * FROM ' . $this->sql->table('ansible_changes') . ' WHERE `id` = ?', [$id]);

        return $rows ? self::decode('ansible_changes', $rows[0]) : null;
    }

    /**
     * {@inheritdoc}
     *
     * One UPDATE ... WHERE id = ? AND version = ? that bumps the version.
     */
    public function updateAt(int $id, int $version, array $values): bool
    {
        [$sets, $bindings] = self::sets('ansible_changes', $values);
        $sets[] = '`version` = `version` + 1';
        $sets[] = '`updated_at` = ?';
        $bindings[] = Clock::store(time());

        return $this->sql->change('UPDATE ' . $this->sql->table('ansible_changes') . ' SET ' . implode(', ', $sets) . ' WHERE `id` = ? AND `version` = ?',
            array_merge($bindings, [$id, $version])) > 0;
    }

    public function targets(int $changeId): array
    {
        $targets = [];
        foreach ($this->sql->select('SELECT * FROM ' . $this->sql->table('ansible_change_targets') . ' WHERE `change_id` = ? ORDER BY `position`, `id`', [$changeId]) as $row) {
            $targets[(int) $row['device_id']] = self::decode('ansible_change_targets', $row);
        }

        return $targets;
    }

    public function updateTargets(int $changeId, array $values): void
    {
        $this->sql->transaction(function () use ($changeId, $values): void {
            foreach ($values as $deviceId => $columns) {
                if (! $columns) {
                    continue;
                }
                [$sets, $bindings] = self::sets('ansible_change_targets', $columns);
                $this->sql->change('UPDATE ' . $this->sql->table('ansible_change_targets') . ' SET ' . implode(', ', $sets) . ' WHERE `change_id` = ? AND `device_id` = ?',
                    array_merge($bindings, [$changeId, (int) $deviceId]));
            }
        });
    }

    public function batches(int $changeId): array
    {
        $batches = [];
        foreach ($this->sql->select('SELECT * FROM ' . $this->sql->table('ansible_change_batches') . ' WHERE `change_id` = ? ORDER BY `number`', [$changeId]) as $row) {
            $batches[(int) $row['number']] = self::decode('ansible_change_batches', $row);
        }

        return $batches;
    }

    public function updateBatch(int $changeId, int $number, array $values): void
    {
        [$sets, $bindings] = self::sets('ansible_change_batches', $values);
        $this->sql->change('UPDATE ' . $this->sql->table('ansible_change_batches') . ' SET ' . implode(', ', $sets) . ' WHERE `change_id` = ? AND `number` = ?',
            array_merge($bindings, [$changeId, $number]));
    }

    public function addEvent(int $changeId, string $kind, string $message, ?string $username, int $at): void
    {
        $this->insert('ansible_change_events', ['change_id' => $changeId, 'at' => Clock::store($at), 'username' => $username !== null ? mb_substr($username, 0, 255) : null,
            'kind' => mb_substr($kind, 0, 24), 'message' => $message]);
    }

    /**
     * The change's timeline, oldest first: all of it, or its latest $latest entries.
     *
     * @return array<int, array{at: string, username: ?string, kind: string, message: string}>
     */
    public function events(int $changeId, ?int $latest = null): array
    {
        if ($latest === null) {
            return $this->sql->select('SELECT `at`, `username`, `kind`, `message` FROM ' . $this->sql->table('ansible_change_events') . ' WHERE `change_id` = ? ORDER BY `id`',
                [$changeId]);
        }

        return array_reverse($this->sql->select('SELECT `at`, `username`, `kind`, `message` FROM ' . $this->sql->table('ansible_change_events')
            . ' WHERE `change_id` = ? ORDER BY `id` DESC LIMIT ' . max(1, $latest), [$changeId]));
    }

    /**
     * How many entries the change's timeline has.
     */
    public function eventCount(int $changeId): int
    {
        return (int) ($this->sql->select('SELECT COUNT(*) AS `n` FROM ' . $this->sql->table('ansible_change_events') . ' WHERE `change_id` = ?', [$changeId])[0]['n'] ?? 0);
    }

    public function addApproval(array $row): void
    {
        $this->insert('ansible_change_approvals', ['change_id' => $row['change_id'], 'user_id' => $row['user_id'], 'username' => mb_substr($row['username'], 0, 255),
            'decision' => $row['decision'], 'comment' => $row['comment'], 'fingerprint' => $row['fingerprint'], 'dry_run_id' => $row['dry_run_id'],
            'created_at' => Clock::store($row['created_at'])]);
    }

    /**
     * The change's approvals and rejections, oldest first.
     *
     * @return array<int, array>
     */
    public function approvals(int $changeId): array
    {
        return $this->sql->select('SELECT * FROM ' . $this->sql->table('ansible_change_approvals') . ' WHERE `change_id` = ? ORDER BY `id`', [$changeId]);
    }

    public function voidApprovals(int $changeId, string $reason, int $at): void
    {
        $this->sql->change('UPDATE ' . $this->sql->table('ansible_change_approvals') . " SET `voided_at` = ?, `void_reason` = ? WHERE `change_id` = ? AND `decision` = 'approved' AND `voided_at` IS NULL",
            [Clock::store($at), mb_substr($reason, 0, 500), $changeId]);
    }

    public function windows(int $changeId): array
    {
        $windows = [];
        foreach ($this->sql->select('SELECT * FROM ' . $this->sql->table('ansible_change_windows') . ' WHERE `change_id` = ? ORDER BY `id`', [$changeId]) as $row) {
            $windows[(int) $row['id']] = self::decode('ansible_change_windows', $row);
        }

        return $windows;
    }

    public function addWindow(array $row): int
    {
        return $this->insert('ansible_change_windows', ['change_id' => $row['change_id'], 'batch' => $row['batch'], 'schedule_id' => $row['schedule_id'],
            'status' => $row['status'], 'opened_at' => Clock::store($row['opened_at']), 'ends_at' => Clock::store($row['ends_at']),
            'cut_off' => ($row['cut_off'] ?? []) ? array_values(array_map('intval', $row['cut_off'])) : null]);
    }

    public function updateWindow(int $id, array $values): void
    {
        [$sets, $bindings] = self::sets('ansible_change_windows', $values);
        $this->sql->change('UPDATE ' . $this->sql->table('ansible_change_windows') . ' SET ' . implode(', ', $sets) . ' WHERE `id` = ?', array_merge($bindings, [$id]));
    }

    public function saveScope(int $changeId, int $batch, string $phase, array $covered, array $domain, array $names = []): void
    {
        $table = $this->sql->table('ansible_change_states');
        $kept = [];
        foreach ($names as $deviceId => $name) {
            $kept[(string) (int) $deviceId] = mb_substr((string) $name, 0, 120);
        }
        $values = [self::ids($covered), self::ids($domain), (string) json_encode((object) $kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)];
        if ($this->sql->change('UPDATE ' . $table . ' SET `covered` = ?, `domain` = ?, `names` = ? WHERE `change_id` = ? AND `batch` = ? AND `phase` = ?',
            array_merge($values, [$changeId, $batch, $phase])) === 0 && $this->scopeOf($changeId, $batch, $phase) === null) {
            $this->insert('ansible_change_states', ['change_id' => $changeId, 'batch' => $batch, 'phase' => $phase, 'covered' => $values[0], 'domain' => $values[1],
                'names' => $values[2]]);
        }
    }

    public function scopeOf(int $changeId, int $batch, string $phase): ?array
    {
        $rows = $this->sql->select('SELECT `covered`, `domain`, `names`, `taken_at` FROM ' . $this->sql->table('ansible_change_states')
            . ' WHERE `change_id` = ? AND `batch` = ? AND `phase` = ?', [$changeId, $batch, $phase]);
        if (! $rows) {
            return null;
        }
        $names = [];
        foreach ((array) (is_string($rows[0]['names']) ? json_decode($rows[0]['names'], true) : []) as $deviceId => $name) {
            if (is_string($name) && ctype_digit((string) $deviceId)) {
                $names[(int) $deviceId] = $name;
            }
        }

        return ['covered' => self::idList($rows[0]['covered']), 'domain' => self::idList($rows[0]['domain']), 'names' => $names, 'taken_at' => $rows[0]['taken_at']];
    }

    /**
     * {@inheritdoc}
     *
     * The state is kept as gzip in base64 ("gz:..."), its scope beside it (its covered devices and domain, for the state after it).
     */
    public function saveState(int $changeId, int $batch, string $phase, array $state, ?array $result = null): void
    {
        $json = (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($json) > self::STATE_MAX) {
            throw new \LengthException('the state is ' . round(strlen($json) / 1048576, 1) . ' MB, more than the ' . intdiv(self::STATE_MAX, 1048576) . ' MB the plugin keeps');
        }
        $scope = (array) ($state['scope'] ?? []);
        $covered = array_merge(...array_map(fn ($role) => array_map('intval', (array) ($scope[$role] ?? [])), ['subjects', 'dependents', 'neighbors', 'included']));
        $this->saveScope($changeId, $batch, $phase, $covered, array_map('intval', (array) ($scope['domain'] ?? [])),
            array_intersect_key((array) ($state['names'] ?? []), array_flip($covered)));
        $this->sql->change('UPDATE ' . $this->sql->table('ansible_change_states') . ' SET `taken_at` = ?, `bytes` = ?, `data` = ?, `result` = ? WHERE `change_id` = ? AND `batch` = ? AND `phase` = ?',
            [Clock::store((int) ($state['taken_at'] ?? time())), strlen($json), 'gz:' . base64_encode((string) gzencode($json, 6)),
                $result !== null ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null, $changeId, $batch, $phase]);
    }

    public function stateOf(int $changeId, int $batch, string $phase): ?array
    {
        $rows = $this->sql->select('SELECT `data` FROM ' . $this->sql->table('ansible_change_states') . ' WHERE `change_id` = ? AND `batch` = ? AND `phase` = ?',
            [$changeId, $batch, $phase]);
        $data = (string) ($rows[0]['data'] ?? '');
        if (! str_starts_with($data, 'gz:')) {
            return null;
        }
        $json = @gzdecode((string) base64_decode(substr($data, 3), true), self::STATE_MAX + 1);
        $state = is_string($json) ? json_decode($json, true) : null;

        return is_array($state) ? $state : null;
    }

    public function resultOf(int $changeId, int $batch): ?array
    {
        $rows = $this->sql->select('SELECT `result` FROM ' . $this->sql->table('ansible_change_states') . " WHERE `change_id` = ? AND `batch` = ? AND `phase` = 'after'",
            [$changeId, $batch]);
        $result = isset($rows[0]['result']) ? json_decode((string) $rows[0]['result'], true) : null;

        return is_array($result) ? $result : null;
    }

    /**
     * Device ids as kept (a JSON list).
     *
     * @param  array<int, mixed>  $ids
     */
    private static function ids(array $ids): string
    {
        return (string) json_encode(array_values(array_unique(array_map('intval', $ids))));
    }

    /**
     * Device ids kept as a JSON list.
     *
     * @return int[]
     */
    private static function idList(mixed $json): array
    {
        $ids = is_string($json) ? json_decode($json, true) : null;

        return is_array($ids) ? array_values(array_map('intval', array_filter($ids, 'is_int'))) : [];
    }

    public function replaceDiffs(int $changeId, int $runId, array $rows): void
    {
        $this->sql->transaction(function () use ($changeId, $runId, $rows): void {
            $this->sql->change('DELETE FROM ' . $this->sql->table('ansible_change_diffs') . ' WHERE `change_id` = ?', [$changeId]);
            foreach (array_values($rows) as $position => $row) {
                $this->insert('ansible_change_diffs', ['change_id' => $changeId, 'run_id' => $runId, 'device_id' => $row['device_id'], 'position' => $position,
                    'task' => mb_substr((string) $row['task'], 0, 255), 'item' => $row['item'] !== null ? mb_substr((string) $row['item'], 0, 255) : null,
                    'kind' => $row['kind'], 'lines' => $row['lines'], 'cut' => (bool) $row['cut'], 'host' => $row['host'] !== null ? mb_substr((string) $row['host'], 0, 255) : null,
                    'reason' => $row['reason'] !== null ? mb_substr((string) $row['reason'], 0, 500) : null]);
            }
        });
    }

    /**
     * The diffs of the change's latest dry run, in their order.
     *
     * @return array<int, array>
     */
    public function diffs(int $changeId): array
    {
        return array_map(fn ($row) => self::decode('ansible_change_diffs', $row),
            $this->sql->select('SELECT * FROM ' . $this->sql->table('ansible_change_diffs') . ' WHERE `change_id` = ? ORDER BY `position`', [$changeId]));
    }

    public function reserved(?int $exceptChange = null): array
    {
        $reserved = [];
        foreach ($this->sql->select('SELECT t.`device_id`, c.`id` FROM ' . $this->sql->table('ansible_change_targets') . ' t JOIN ' . $this->sql->table('ansible_changes')
            . ' c ON c.`id` = t.`change_id` WHERE c.`status` IN (' . self::marks(ChangeStatus::RESERVING) . ') ORDER BY c.`id`', ChangeStatus::RESERVING) as $row) {
            if ((int) $row['id'] !== $exceptChange) {
                $reserved[(int) $row['device_id']] = (int) $row['id'];
            }
        }

        return $reserved;
    }

    /**
     * {@inheritdoc}
     *
     * The changes in their dry run, and those of the unended changes with a batch launching or running.
     */
    public function inProgress(): array
    {
        $found = [];
        $rows = $this->sql->select('SELECT * FROM ' . $this->sql->table('ansible_changes') . ' WHERE `status` = ? OR (`status` NOT IN (' . self::marks(ChangeStatus::FINAL)
            . ') AND `id` IN (SELECT `change_id` FROM ' . $this->sql->table('ansible_change_batches') . ' WHERE `status` IN (?, ?))) ORDER BY `id`',
            array_merge([ChangeStatus::DRY_RUN], ChangeStatus::FINAL, self::IN_PROGRESS));
        foreach ($rows as $row) {
            $change = self::decode('ansible_changes', $row);
            $targets = $this->targets((int) $change['id']);
            if ($change['status'] === ChangeStatus::DRY_RUN) {
                $found[] = ['change' => $change, 'phase' => 'dry_run', 'batch' => null, 'targets' => $targets];

                continue;
            }
            foreach ($this->batches((int) $change['id']) as $number => $batch) {
                if (in_array($batch['status'], self::IN_PROGRESS, true)) {
                    $found[] = ['change' => $change, 'phase' => 'batch', 'batch' => $batch,
                        'targets' => array_filter($targets, fn (array $target) => (int) $target['batch'] === $number)];
                }
            }
        }

        return $found;
    }

    /**
     * {@inheritdoc}
     *
     * One UPDATE each: a target left out is marked so only when it was not yet (that answers whether this fetch was the
     * first); a served one keeps a mark it has and becomes "mixed" when another connection was served before. served_at is
     * set first: MySQL evaluates the assignments of an UPDATE in order, with the values assigned before.
     */
    public function served(int $changeId, int $deviceId, ?string $hash, ?string $note, int $at): bool
    {
        $table = $this->sql->table('ansible_change_targets');
        if ($hash === null) {
            return $this->sql->change('UPDATE ' . $table . ' SET `served_hash` = ?, `served_note` = ?, `served_at` = ? WHERE `change_id` = ? AND `device_id` = ? '
                . 'AND (`served_hash` IS NULL OR `served_hash` <> ?)', [InventoryGuard::LEFT_OUT, mb_substr((string) $note, 0, 500), Clock::store($at), $changeId, $deviceId,
                    InventoryGuard::LEFT_OUT]) > 0;
        }
        $this->sql->change('UPDATE ' . $table . ' SET `served_at` = CASE WHEN `served_hash` IN (?, ?) THEN `served_at` ELSE ? END, '
            . '`served_hash` = CASE WHEN `served_hash` IN (?, ?) THEN `served_hash` WHEN `served_hash` IS NULL OR `served_hash` = ? THEN ? ELSE ? END '
            . 'WHERE `change_id` = ? AND `device_id` = ?', [InventoryGuard::LEFT_OUT, InventoryGuard::MIXED, Clock::store($at), InventoryGuard::LEFT_OUT,
                InventoryGuard::MIXED, $hash, $hash, InventoryGuard::MIXED, $changeId, $deviceId]);

        return false;
    }

    /**
     * Ids of the changes the sync command follows: unfinished, or with a maintenance window still open or a backup
     * still awaited.
     *
     * @return int[]
     */
    public function pendingIds(): array
    {
        $ids = [];
        foreach ($this->sql->select('SELECT `id` FROM ' . $this->sql->table('ansible_changes') . ' WHERE `status` NOT IN (' . self::marks(ChangeStatus::FINAL) . ')',
            ChangeStatus::FINAL) as $row) {
            $ids[(int) $row['id']] = true;
        }
        foreach ($this->sql->select('SELECT DISTINCT `change_id` FROM ' . $this->sql->table('ansible_change_windows') . " WHERE `status` = 'open'") as $row) {
            $ids[(int) $row['change_id']] = true;
        }
        foreach ($this->sql->select('SELECT DISTINCT `change_id` FROM ' . $this->sql->table('ansible_change_targets') . ' WHERE `before_status` = ? OR `after_status` = ?',
            [BackupState::REQUESTED, BackupState::REQUESTED]) as $row) {
            $ids[(int) $row['change_id']] = true;
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * A page of changes, newest first, with the names of their first three targets.
     *
     * @param  array{device?: ?int, user?: ?string, template?: ?string, status?: ?string, from?: ?string, to?: ?string}  $filters
     * @return array{rows: array<int, array>, total: int}
     */
    public function page(array $filters, int $page, int $perPage): array
    {
        $query = DB::table('ansible_changes as c');
        if (! empty($filters['device'])) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('ansible_change_targets as t')->whereColumn('t.change_id', 'c.id')
                ->where('t.device_id', (int) $filters['device']));
        }
        if (($filters['user'] ?? '') !== '') {
            $query->where('c.username', $filters['user']);
        }
        if (($filters['template'] ?? '') !== '') {
            $query->where('c.template_id', $filters['template']);
        }
        if (($filters['status'] ?? '') === 'open') {
            $query->whereNotIn('c.status', ChangeStatus::FINAL);
        } elseif (($filters['status'] ?? '') !== '') {
            $query->where('c.status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->where('c.created_at', '>=', Clock::store((new \DateTimeImmutable($filters['from'] . ' 00:00:00', Clock::zone()))->getTimestamp()));
        }
        if (! empty($filters['to'])) {
            $query->where('c.created_at', '<=', Clock::store((new \DateTimeImmutable($filters['to'] . ' 23:59:59', Clock::zone()))->getTimestamp()));
        }
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('c.id')->forPage(max(1, $page), $perPage)
            ->get(['c.id', 'c.username', 'c.template_id', 'c.template_name', 'c.reference', 'c.status', 'c.target_count', 'c.batch_count', 'c.current_batch',
                'c.dry_run_state', 'c.waiting', 'c.created_at', 'c.finished_at', 'c.verification'])
            ->map(fn ($r) => (array) $r)->all();
        $names = [];
        if ($rows) {
            foreach (DB::table('ansible_change_targets')->whereIn('change_id', array_column($rows, 'id'))->orderBy('position')
                ->get(['change_id', 'display', 'hostname']) as $target) {
                $names[(int) $target->change_id][] = (string) ($target->display ?: $target->hostname);
            }
        }
        foreach ($rows as &$row) {
            $row['target_names'] = array_slice($names[(int) $row['id']] ?? [], 0, 3);
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Users, templates and devices that appear in the changes, for the filters.
     *
     * @return array{users: string[], templates: array<string, string>, devices: array<int, string>}
     */
    public function filterOptions(): array
    {
        $templates = [];
        foreach (DB::table('ansible_changes as c')->joinSub(DB::table('ansible_changes')->selectRaw('max(id) as latest')->groupBy('template_id'), 'l', 'l.latest', '=', 'c.id')
            ->get(['c.template_id', 'c.template_name']) as $row) {
            $templates[(string) $row->template_id] = (string) $row->template_name;
        }
        asort($templates);
        $devices = [];
        foreach (DB::table('ansible_change_targets as t')->joinSub(DB::table('ansible_change_targets')->selectRaw('max(id) as latest')->groupBy('device_id'), 'l', 'l.latest', '=', 't.id')
            ->get(['t.device_id', 't.display', 't.hostname']) as $row) {
            $devices[(int) $row->device_id] = (string) ($row->display ?: $row->hostname);
        }
        asort($devices);

        return ['users' => DB::table('ansible_changes')->distinct()->orderBy('username')->pluck('username')->map(fn ($u) => (string) $u)->all(),
            'templates' => $templates, 'devices' => $devices];
    }

    /**
     * The latest changes that had a device as a target, with its dry-run and apply results there.
     *
     * @return array<int, array>
     */
    public function deviceChanges(int $deviceId, int $limit = 5): array
    {
        return DB::table('ansible_change_targets as t')->join('ansible_changes as c', 'c.id', '=', 't.change_id')
            ->leftJoin('ansible_change_batches as b', fn ($join) => $join->on('b.change_id', '=', 't.change_id')->on('b.number', '=', 't.batch'))
            ->where('t.device_id', $deviceId)->orderByDesc('c.id')->limit($limit)
            ->get(['c.id', 'c.username', 'c.template_name', 'c.reference', 'c.status', 'c.created_at', 'c.dry_run_state', 't.batch', 't.dry_result', 't.result',
                't.served_hash', 't.served_note', 'b.status as batch_status'])
            ->map(fn ($r) => (array) $r)->all();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Insert one row: its id.
     *
     * @param  array<string, mixed>  $values
     */
    private function insert(string $table, array $values): int
    {
        $values = self::encode($table, $values);

        return $this->sql->insert('INSERT INTO ' . $this->sql->table($table) . ' (' . implode(', ', array_map(fn ($c) => self::column((string) $c), array_keys($values)))
            . ') VALUES (' . self::marks($values) . ')', array_values($values));
    }

    /**
     * The SET parts and bindings of an UPDATE.
     *
     * @param  array<string, mixed>  $values
     * @return array{0: string[], 1: array}
     */
    private static function sets(string $table, array $values): array
    {
        $sets = [];
        $bindings = [];
        foreach (self::encode($table, $values) as $column => $value) {
            $sets[] = self::column((string) $column) . ' = ?';
            $bindings[] = $value;
        }

        return [$sets, $bindings];
    }

    /**
     * JSON columns as JSON, booleans as 0 and 1.
     */
    private static function encode(string $table, array $values): array
    {
        foreach (self::JSON[$table] ?? [] as $column) {
            if (array_key_exists($column, $values) && $values[$column] !== null) {
                $values[$column] = json_encode($values[$column], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            }
        }
        foreach ($values as $column => $value) {
            if (is_bool($value)) {
                $values[$column] = (int) $value;
            }
        }

        return $values;
    }

    /**
     * JSON columns decoded, booleans as booleans.
     */
    private static function decode(string $table, array $row): array
    {
        foreach (self::JSON[$table] ?? [] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = $row[$column] !== null ? json_decode((string) $row[$column], true) : null;
            }
        }
        foreach (self::BOOLEANS[$table] ?? [] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = (bool) $row[$column];
            }
        }

        return $row;
    }

    /**
     * A column name, backquoted (only lowercase names with underscores: the names come from the code).
     */
    private static function column(string $name): string
    {
        if (! preg_match('/^[a-z_]+$/D', $name)) {
            throw new \InvalidArgumentException("not a column of the plugin's tables: $name");
        }

        return "`$name`";
    }

    /**
     * As many placeholders as values.
     */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
