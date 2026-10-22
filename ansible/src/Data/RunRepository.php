<?php

namespace Agoodley\LibrenmsAnsible\Data;

use Agoodley\LibrenmsAnsible\Runs\OutputBuffer;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Runs\RunStore;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit log: runs (ansible_runs) and their targets (ansible_run_targets). Only LibreNMS admins see it, and
 * every admin sees every run, so queries are not scoped by device. Rows are returned as arrays with their JSON
 * columns decoded. The run lifecycle changes runs only through updateAt(), a compare-and-set on their version. Its
 * statements go through an Sql executor (LibreNMS' connection; SQLite in the tests); the runs list and the device
 * panel use Laravel's query builder.
 */
final class RunRepository implements RunStore
{
    /** JSON columns of a run. */
    private const JSON = ['variables', 'summary', 'unexpected_hosts'];

    public function __construct(private readonly Sql $sql = new LaravelSql())
    {
    }

    /** Columns of the later migrations that the runs need: without one of them the plugin asks for ./lnms migrate. */
    private const LATER_COLUMNS = ['version', 'launch_nonce', 'missing_since', 'missing_checks', 'stop_requested_at', 'stop_reason', 'released_by',
        'runner_seen_at', 'watch_until', 'output_tail', 'output_omitted', 'held_until', 'change_id', 'kind', 'batch', 'context', 'commit_hash', 'proof'];

    /**
     * Do the plugin's tables exist with all their columns (./lnms migrate ran, also after an update)?
     */
    public function available(): bool
    {
        try {
            return Schema::hasTable('ansible_runs') && Schema::hasTable('ansible_run_targets') && Schema::hasColumns('ansible_runs', self::LATER_COLUMNS)
                && Schema::hasColumn('ansible_run_targets', 'unexpected');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Devices that are targets of an unfinished run, or of a stopped run that still holds them (the step in progress
     * at the stop may still run on the runner). A host the runner ran on that was not a target does not count.
     *
     * @param  int|null  $now  the time to compare holds with (now)
     * @return array<int, array{run: int, held_until: ?string}> device_id => the run (the newest when several), and until when a stopped one holds it
     */
    public function busy(?int $now = null): array
    {
        $busy = [];
        foreach ($this->sql->select('SELECT t.`device_id`, t.`run_id`, r.`status`, r.`held_until` FROM ' . $this->sql->table('ansible_run_targets') . ' t JOIN '
            . $this->sql->table('ansible_runs') . ' r ON r.`id` = t.`run_id` WHERE t.`unexpected` = 0 AND (r.`status` IN (' . self::marks(RunStatus::UNFINISHED)
            . ') OR (r.`status` = ? AND r.`held_until` > ?)) ORDER BY r.`id`',
            array_merge(RunStatus::UNFINISHED, [RunStatus::STOPPED, Clock::store($now ?? time())])) as $row) {
            $busy[(int) $row['device_id']] = ['run' => (int) $row['run_id'], 'held_until' => $row['status'] === RunStatus::STOPPED ? (string) $row['held_until'] : null];
        }

        return $busy;
    }

    /**
     * The run a launch form already started, by its one-time token.
     */
    public function byRequestToken(string $token): ?int
    {
        $rows = $this->sql->select('SELECT `id` FROM ' . $this->sql->table('ansible_runs') . ' WHERE `request_token` = ?', [$token]);

        return $rows ? (int) $rows[0]['id'] : null;
    }

    /**
     * Insert a run and its targets in one transaction.
     *
     * @param  array<string, mixed>  $run  column => value (JSON columns as arrays)
     * @param  array<int, array{inventory_name: string, hostname: string, display: string}>  $targets  device_id => target
     */
    public function create(array $run, array $targets): int
    {
        return $this->sql->transaction(function () use ($run, $targets): int {
            $now = Clock::store(time());
            $values = self::encode($run) + ['created_at' => $now, 'updated_at' => $now];
            $id = $this->sql->insert('INSERT INTO ' . $this->sql->table('ansible_runs') . ' (' . self::columns(array_keys($values)) . ') VALUES ('
                . self::marks($values) . ')', self::bindings($values));
            $rows = [];
            foreach ($targets as $deviceId => $target) {
                $rows[] = [$id, (int) $deviceId, $target['inventory_name'], mb_substr($target['hostname'], 0, 255), mb_substr($target['display'], 0, 255)];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                $this->sql->change('INSERT INTO ' . $this->sql->table('ansible_run_targets') . ' (`run_id`, `device_id`, `inventory_name`, `hostname`, `display`) VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)')), array_merge(...$chunk));
            }

            return $id;
        });
    }

    /**
     * One run, null when there is none.
     */
    public function find(int $id): ?array
    {
        $rows = $this->sql->select('SELECT * FROM ' . $this->sql->table('ansible_runs') . ' WHERE `id` = ?', [$id]);

        return $rows ? self::decode($rows[0]) : null;
    }

    /**
     * The targets of a run.
     *
     * @return array<int, array> device_id => row (counts decoded)
     */
    public function targets(int $runId): array
    {
        $targets = [];
        foreach ($this->sql->select('SELECT * FROM ' . $this->sql->table('ansible_run_targets') . ' WHERE `run_id` = ? ORDER BY `unexpected`, `inventory_name`, `id`',
            [$runId]) as $row) {
            $row['counts'] = $row['counts'] !== null ? json_decode((string) $row['counts'], true) : null;
            $row['unexpected'] = (bool) ($row['unexpected'] ?? false);
            $targets[(int) $row['device_id']] = $row;
        }

        return $targets;
    }

    /**
     * {@inheritdoc}
     *
     * One UPDATE ... WHERE id = ? AND version = ? that bumps the version. A log value or text bigger than
     * OutputBuffer::CHUNK is written in pieces (the first with the change, the others appended to it), so no
     * statement exceeds the database's packet size; all in one transaction, so another change of the run (a user's
     * Stop) cannot come between the pieces and no reader sees half a change.
     */
    public function updateAt(int $id, int $version, array $values, array $append = []): bool
    {
        $values = self::encode($values);
        $later = [];
        foreach (['output', 'output_tail'] as $column) {
            if (isset($values[$column]) && is_string($values[$column]) && strlen($values[$column]) > OutputBuffer::CHUNK) {
                $pieces = OutputBuffer::pieces($values[$column]);
                $values[$column] = array_shift($pieces);
                $later[$column] = $pieces;
            }
        }
        $sets = [];
        $bindings = [];
        foreach ($values as $column => $value) {
            $sets[] = self::column((string) $column) . ' = ?';
            $bindings[] = self::bindable($value);
        }
        foreach ($append as $column => $text) {
            if ($text === '') {
                continue;
            }
            $pieces = OutputBuffer::pieces($text);
            if (array_key_exists($column, $values)) {
                $later[$column] = array_merge($later[$column] ?? [], $pieces);
            } else {
                $sets[] = self::column($column) . ' = CONCAT(COALESCE(' . self::column($column) . ", ''), ?)";
                $bindings[] = array_shift($pieces);
                $later[$column] = $pieces;
            }
        }
        $sets[] = '`version` = `version` + 1';
        $sets[] = '`updated_at` = ?';
        $bindings[] = Clock::store(time());
        $table = $this->sql->table('ansible_runs');

        return $this->sql->transaction(function () use ($table, $sets, $bindings, $later, $id, $version): bool {
            if ($this->sql->change("UPDATE $table SET " . implode(', ', $sets) . ' WHERE `id` = ? AND `version` = ?', array_merge($bindings, [$id, $version])) < 1) {
                return false;
            }
            foreach ($later as $column => $pieces) {
                foreach ($pieces as $piece) {
                    $name = self::column((string) $column);
                    $this->sql->change("UPDATE $table SET $name = CONCAT(COALESCE($name, ''), ?) WHERE `id` = ? AND `version` = ?", [$piece, $id, $version + 1]);
                }
            }

            return true;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function setResults(int $runId, array $results): void
    {
        $this->sql->transaction(function () use ($runId, $results): void {
            foreach ($results as $deviceId => $result) {
                $this->sql->change('UPDATE ' . $this->sql->table('ansible_run_targets') . ' SET `result` = ?, `counts` = ? WHERE `run_id` = ? AND `device_id` = ?',
                    [$result['result'], $result['counts'] !== null ? json_encode($result['counts']) : null, $runId, (int) $deviceId]);
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function addUnexpected(int $runId, array $rows): void
    {
        $table = $this->sql->table('ansible_run_targets');
        $known = array_map(fn ($row) => (int) $row['device_id'], $this->sql->select("SELECT `device_id` FROM $table WHERE `run_id` = ?", [$runId]));
        $insert = [];
        foreach ($rows as $deviceId => $row) {
            if (! in_array((int) $deviceId, $known, true)) {
                $insert[] = [$runId, (int) $deviceId, mb_substr($row['inventory_name'], 0, 255), mb_substr($row['hostname'], 0, 255), mb_substr($row['display'], 0, 255),
                    $row['result'], $row['counts'] !== null ? json_encode($row['counts']) : null, 1];
            }
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            $this->sql->change("INSERT INTO $table (`run_id`, `device_id`, `inventory_name`, `hostname`, `display`, `result`, `counts`, `unexpected`) VALUES "
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)')), array_merge(...$chunk));
        }
    }

    /**
     * The latest stopped run of each device that ended in the last $seconds: a stop leaves the task in progress
     * running on the runner, so the next launch says so.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array{run_id: int, runner_task_id: ?string, finished_at: string}> device_id => run
     */
    public function recentStops(array $deviceIds, int $seconds = 3600): array
    {
        if (! $deviceIds) {
            return [];
        }
        $stops = [];
        foreach ($this->sql->select('SELECT t.`device_id`, r.`id`, r.`runner_task_id`, r.`finished_at` FROM ' . $this->sql->table('ansible_run_targets') . ' t JOIN '
            . $this->sql->table('ansible_runs') . ' r ON r.`id` = t.`run_id` WHERE t.`device_id` IN (' . self::marks($deviceIds) . ') AND r.`status` = ? AND r.`finished_at` >= ? ORDER BY r.`id`',
            array_merge(array_map('intval', $deviceIds), [RunStatus::STOPPED, Clock::store(time() - $seconds)])) as $row) {
            $stops[(int) $row['device_id']] = ['run_id' => (int) $row['id'], 'runner_task_id' => $row['runner_task_id'], 'finished_at' => (string) $row['finished_at']];
        }

        return $stops;
    }

    /**
     * The devices among $deviceIds that a run may have changed since $since (launched then or later, and not refused
     * by the runner), not counting the runs of change $changeId: a dry run from before such a run no longer tells what
     * an apply would do. Every run counts, also on a device it ran on without it being a target, except a dry run
     * proven to be one, and a dry run that has not ended (it holds its devices: it is judged when it ends).
     *
     * @param  int[]  $deviceIds
     * @return array<int, int> device_id => the latest such run
     */
    public function changedSince(array $deviceIds, int $since, int $changeId): array
    {
        if (! $deviceIds) {
            return [];
        }
        $changed = [];
        foreach ($this->sql->select('SELECT t.`device_id`, r.`id` FROM ' . $this->sql->table('ansible_run_targets') . ' t JOIN ' . $this->sql->table('ansible_runs')
            . ' r ON r.`id` = t.`run_id` WHERE t.`device_id` IN (' . self::marks($deviceIds) . ') AND r.`created_at` >= ? AND r.`status` <> ?'
            . " AND NOT (COALESCE(r.`kind`, '') = 'dry_run' AND (COALESCE(r.`proof`, '') = 'verified' OR r.`status` IN (" . self::marks(RunStatus::UNFINISHED) . ')))'
            . ' AND (r.`change_id` IS NULL OR r.`change_id` <> ?) ORDER BY r.`id`',
            array_merge(array_map('intval', $deviceIds), [Clock::store($since), RunStatus::NOT_LAUNCHED], RunStatus::UNFINISHED, [$changeId])) as $row) {
            $changed[(int) $row['device_id']] = (int) $row['id'];
        }

        return $changed;
    }

    /**
     * Record whether a dry run is proven to be one ('verified' or 'unverified').
     */
    public function setProof(int $runId, string $proof): void
    {
        $this->sql->change('UPDATE ' . $this->sql->table('ansible_runs') . ' SET `proof` = ? WHERE `id` = ?', [$proof, $runId]);
    }

    /**
     * Ids of the runs that have not finished, oldest first.
     *
     * @return int[]
     */
    public function unfinished(): array
    {
        return array_map(fn ($row) => (int) $row['id'], $this->sql->select('SELECT `id` FROM ' . $this->sql->table('ansible_runs') . ' WHERE `status` IN ('
            . self::marks(RunStatus::UNFINISHED) . ') ORDER BY `id`', RunStatus::UNFINISHED));
    }

    /**
     * Ids of the lost and released runs still watched for a late end.
     *
     * @return int[]
     */
    public function watched(?int $now = null): array
    {
        return array_map(fn ($row) => (int) $row['id'], $this->sql->select('SELECT `id` FROM ' . $this->sql->table('ansible_runs') . ' WHERE `status` IN ('
            . self::marks(RunStatus::WATCHED) . ') AND `watch_until` IS NOT NULL AND `watch_until` > ? ORDER BY `id`',
            array_merge(RunStatus::WATCHED, [Clock::store($now ?? time())])));
    }

    /**
     * A page of runs, newest first, with the number of targets and the names of the first three.
     *
     * @param  array{device?: ?int, user?: ?string, template?: ?string, status?: ?string, from?: ?string, to?: ?string}  $filters
     * @return array{rows: array<int, array>, total: int}
     */
    public function page(array $filters, int $page, int $perPage): array
    {
        $query = DB::table('ansible_runs as r');
        if (! empty($filters['device'])) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('ansible_run_targets as t')->whereColumn('t.run_id', 'r.id')
                ->where('t.device_id', (int) $filters['device']));
        }
        if (($filters['user'] ?? '') !== '') {
            $query->where('r.username', $filters['user']);
        }
        if (($filters['template'] ?? '') !== '') {
            $query->where('r.template_id', $filters['template']);
        }
        if (($filters['status'] ?? '') === 'unfinished') {
            $query->whereIn('r.status', RunStatus::UNFINISHED);
        } elseif (($filters['status'] ?? '') !== '') {
            $query->where('r.status', $filters['status']);
        }
        // days in the time zone the pages show times in (the user's), the times kept in UTC
        if (! empty($filters['from'])) {
            $query->where('r.created_at', '>=', Clock::store((new \DateTimeImmutable($filters['from'] . ' 00:00:00', Clock::zone()))->getTimestamp()));
        }
        if (! empty($filters['to'])) {
            $query->where('r.created_at', '<=', Clock::store((new \DateTimeImmutable($filters['to'] . ' 23:59:59', Clock::zone()))->getTimestamp()));
        }
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('r.id')->forPage(max(1, $page), $perPage)
            ->get(['r.id', 'r.username', 'r.template_id', 'r.template_name', 'r.status', 'r.runner_status', 'r.summary', 'r.unexpected_hosts',
                'r.created_at', 'r.started_at', 'r.finished_at', 'r.change_id', 'r.kind', 'r.batch'])
            ->map(fn ($r) => self::decode((array) $r))->all();
        $names = [];
        if ($rows) {
            foreach (DB::table('ansible_run_targets')->whereIn('run_id', array_column($rows, 'id'))->orderBy('id')
                ->get(['run_id', 'display', 'hostname']) as $target) {
                $names[(int) $target->run_id][] = (string) ($target->display ?: $target->hostname);
            }
        }
        foreach ($rows as &$row) {
            $row['targets'] = count($names[(int) $row['id']] ?? []);
            $row['target_names'] = array_slice($names[(int) $row['id']] ?? [], 0, 3);
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Users, templates and devices that appear in the runs, for the filters, with the latest name of each template
     * and device: one row each from the database (grouped there), whatever the number of runs.
     *
     * @return array{users: string[], templates: array<string, string>, devices: array<int, string>}
     */
    public function filterOptions(): array
    {
        $latestRuns = DB::table('ansible_runs')->selectRaw('max(id) as latest')->groupBy('template_id');
        $templates = [];
        foreach (DB::table('ansible_runs as r')->joinSub($latestRuns, 'l', 'l.latest', '=', 'r.id')->get(['r.template_id', 'r.template_name']) as $row) {
            $templates[(string) $row->template_id] = (string) $row->template_name;
        }
        asort($templates);
        $latestTargets = DB::table('ansible_run_targets')->selectRaw('max(id) as latest')->groupBy('device_id');
        $devices = [];
        foreach (DB::table('ansible_run_targets as t')->joinSub($latestTargets, 'l', 'l.latest', '=', 't.id')->get(['t.device_id', 't.display', 't.hostname']) as $row) {
            $devices[(int) $row->device_id] = (string) ($row->display ?: $row->hostname);
        }
        asort($devices);

        return ['users' => DB::table('ansible_runs')->distinct()->orderBy('username')->pluck('username')->map(fn ($u) => (string) $u)->all(),
            'templates' => $templates, 'devices' => $devices];
    }

    /**
     * The latest runs that had a device as a target, with its result there.
     *
     * @return array<int, array>
     */
    public function deviceRuns(int $deviceId, int $limit = 5): array
    {
        return DB::table('ansible_run_targets as t')->join('ansible_runs as r', 'r.id', '=', 't.run_id')->where('t.device_id', $deviceId)
            ->orderByDesc('r.id')->limit($limit)
            ->get(['r.id', 'r.username', 'r.template_name', 'r.status', 'r.created_at', 'r.finished_at', 't.result', 't.unexpected', 'r.change_id', 'r.kind', 'r.batch'])
            ->map(fn ($r) => (array) $r)->all();
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
     * @param  string[]  $names
     */
    private static function columns(array $names): string
    {
        return implode(', ', array_map(fn ($name) => self::column((string) $name), $names));
    }

    /**
     * As many placeholders as values.
     */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * A value as a statement binds it: booleans as 0 and 1.
     */
    private static function bindable(mixed $value): mixed
    {
        return is_bool($value) ? (int) $value : $value;
    }

    /**
     * @return array<int, mixed>
     */
    private static function bindings(array $values): array
    {
        return array_map(fn ($value) => self::bindable($value), array_values($values));
    }

    /**
     * JSON columns as JSON.
     */
    private static function encode(array $values): array
    {
        foreach (self::JSON as $column) {
            if (array_key_exists($column, $values) && $values[$column] !== null) {
                $values[$column] = json_encode($values[$column], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
        }

        return $values;
    }

    /**
     * JSON columns decoded.
     */
    private static function decode(array $row): array
    {
        foreach (self::JSON as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = $row[$column] !== null ? json_decode((string) $row[$column], true) : null;
            }
        }

        return $row;
    }
}
