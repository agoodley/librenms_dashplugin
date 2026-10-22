<?php

namespace Agoodley\LibrenmsAnsible\Data;

use Agoodley\LibrenmsAnsible\Runs\OutputBuffer;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Runs\RunStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit log: runs (ansible_runs) and their targets (ansible_run_targets). Only LibreNMS admins see it, and
 * every admin sees every run, so queries are not scoped by device. Rows are returned as arrays with their JSON
 * columns decoded. The run lifecycle changes runs only through updateAt(), a compare-and-set on their version.
 */
final class RunRepository implements RunStore
{
    /** JSON columns of a run. */
    private const JSON = ['variables', 'summary', 'unexpected_hosts'];

    /**
     * Do the plugin's tables exist (./lnms migrate ran)?
     */
    public function available(): bool
    {
        try {
            return Schema::hasTable('ansible_runs') && Schema::hasTable('ansible_run_targets');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Devices that are targets of an unfinished run, or of a stopped run that still holds them (the step in progress
     * at the stop may still run on the runner).
     *
     * @return array<int, array{run: int, held_until: ?string}> device_id => the run (the newest when several), and until when a stopped one holds it
     */
    public function busy(): array
    {
        $busy = [];
        foreach (DB::table('ansible_run_targets as t')->join('ansible_runs as r', 'r.id', '=', 't.run_id')->where('t.unexpected', false)
            ->where(fn ($q) => $q->whereIn('r.status', RunStatus::UNFINISHED)
                ->orWhere(fn ($q) => $q->where('r.status', RunStatus::STOPPED)->where('r.held_until', '>', date('Y-m-d H:i:s'))))
            ->orderBy('r.id')->get(['t.device_id', 't.run_id', 'r.status', 'r.held_until']) as $row) {
            $busy[(int) $row->device_id] = ['run' => (int) $row->run_id, 'held_until' => $row->status === RunStatus::STOPPED ? (string) $row->held_until : null];
        }

        return $busy;
    }

    /**
     * The run a launch form already started, by its one-time token.
     */
    public function byRequestToken(string $token): ?int
    {
        $id = DB::table('ansible_runs')->where('request_token', $token)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Insert a run and its targets in one transaction.
     *
     * @param  array<string, mixed>  $run  column => value (JSON columns as arrays)
     * @param  array<int, array{inventory_name: string, hostname: string, display: string}>  $targets  device_id => target
     */
    public function create(array $run, array $targets): int
    {
        return DB::transaction(function () use ($run, $targets): int {
            $now = now();
            $id = (int) DB::table('ansible_runs')->insertGetId(self::encode($run) + ['created_at' => $now, 'updated_at' => $now]);
            $rows = [];
            foreach ($targets as $deviceId => $target) {
                $rows[] = ['run_id' => $id, 'device_id' => $deviceId, 'inventory_name' => $target['inventory_name'],
                    'hostname' => mb_substr($target['hostname'], 0, 255), 'display' => mb_substr($target['display'], 0, 255)];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('ansible_run_targets')->insert($chunk);
            }

            return $id;
        });
    }

    /**
     * One run, null when there is none.
     */
    public function find(int $id): ?array
    {
        $row = DB::table('ansible_runs')->where('id', $id)->first();

        return $row === null ? null : self::decode((array) $row);
    }

    /**
     * The targets of a run.
     *
     * @return array<int, array> device_id => row (counts decoded)
     */
    public function targets(int $runId): array
    {
        $targets = [];
        foreach (DB::table('ansible_run_targets')->where('run_id', $runId)->orderBy('unexpected')->orderBy('inventory_name')->get() as $row) {
            $row = (array) $row;
            $row['counts'] = $row['counts'] !== null ? json_decode((string) $row['counts'], true) : null;
            $row['unexpected'] = (bool) ($row['unexpected'] ?? false);
            $targets[(int) $row['device_id']] = $row;
        }

        return $targets;
    }

    /**
     * {@inheritdoc}
     *
     * One UPDATE ... WHERE id = ? AND version = ? that bumps the version. A log value bigger than
     * OutputBuffer::CHUNK is written in pieces (the first with the change, the others appended to it), so no
     * statement exceeds the database's packet size.
     */
    public function updateAt(int $id, int $version, array $values, string $appendOutput = ''): bool
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
            if (! preg_match('/^[a-z_]+$/D', (string) $column)) {
                throw new \InvalidArgumentException("not a column of ansible_runs: $column");
            }
            $sets[] = "`$column` = ?";
            $bindings[] = $value;
        }
        if ($appendOutput !== '') {
            $pieces = OutputBuffer::pieces($appendOutput);
            if (array_key_exists('output', $values)) {
                $later['output'] = array_merge($later['output'] ?? [], $pieces);
            } else {
                $sets[] = "`output` = CONCAT(COALESCE(`output`, ''), ?)";
                $bindings[] = array_shift($pieces);
                $later['output'] = $pieces;
            }
        }
        $sets[] = '`version` = `version` + 1';
        $sets[] = '`updated_at` = ?';
        $bindings[] = now()->format('Y-m-d H:i:s');
        $table = DB::getTablePrefix() . 'ansible_runs';
        if (DB::update("UPDATE `$table` SET " . implode(', ', $sets) . ' WHERE `id` = ? AND `version` = ?', array_merge($bindings, [$id, $version])) < 1) {
            return false;
        }
        foreach ($later as $column => $pieces) {
            foreach ($pieces as $piece) {
                DB::update("UPDATE `$table` SET `$column` = CONCAT(COALESCE(`$column`, ''), ?) WHERE `id` = ? AND `version` = ?", [$piece, $id, $version + 1]);
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function setResults(int $runId, array $results): void
    {
        foreach ($results as $deviceId => $result) {
            DB::table('ansible_run_targets')->where('run_id', $runId)->where('device_id', $deviceId)
                ->update(['result' => $result['result'], 'counts' => $result['counts'] !== null ? json_encode($result['counts']) : null]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addUnexpected(int $runId, array $rows): void
    {
        $known = DB::table('ansible_run_targets')->where('run_id', $runId)->pluck('device_id')->map(fn ($id) => (int) $id)->all();
        $insert = [];
        foreach ($rows as $deviceId => $row) {
            if (! in_array((int) $deviceId, $known, true)) {
                $insert[] = ['run_id' => $runId, 'device_id' => (int) $deviceId, 'inventory_name' => mb_substr($row['inventory_name'], 0, 255),
                    'hostname' => mb_substr($row['hostname'], 0, 255), 'display' => mb_substr($row['display'], 0, 255), 'result' => $row['result'],
                    'counts' => $row['counts'] !== null ? json_encode($row['counts']) : null, 'unexpected' => true];
            }
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('ansible_run_targets')->insert($chunk);
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
        foreach (DB::table('ansible_run_targets as t')->join('ansible_runs as r', 'r.id', '=', 't.run_id')->whereIn('t.device_id', $deviceIds)
            ->where('r.status', RunStatus::STOPPED)->where('r.finished_at', '>=', now()->subSeconds($seconds))->orderBy('r.id')
            ->get(['t.device_id', 'r.id', 'r.runner_task_id', 'r.finished_at']) as $row) {
            $stops[(int) $row->device_id] = ['run_id' => (int) $row->id, 'runner_task_id' => $row->runner_task_id, 'finished_at' => (string) $row->finished_at];
        }

        return $stops;
    }

    /**
     * Ids of the runs that have not finished, oldest first.
     *
     * @return int[]
     */
    public function unfinished(): array
    {
        return array_map('intval', DB::table('ansible_runs')->whereIn('status', RunStatus::UNFINISHED)->orderBy('id')->pluck('id')->all());
    }

    /**
     * Ids of the lost and released runs still watched for a late end.
     *
     * @return int[]
     */
    public function watched(): array
    {
        return array_map('intval', DB::table('ansible_runs')->whereIn('status', RunStatus::WATCHED)->whereNotNull('watch_until')
            ->where('watch_until', '>', now())->orderBy('id')->pluck('id')->all());
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
        if (! empty($filters['from'])) {
            $query->where('r.created_at', '>=', $filters['from'] . ' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('r.created_at', '<=', $filters['to'] . ' 23:59:59');
        }
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('r.id')->forPage(max(1, $page), $perPage)
            ->get(['r.id', 'r.username', 'r.template_id', 'r.template_name', 'r.status', 'r.runner_status', 'r.summary', 'r.unexpected_hosts',
                'r.created_at', 'r.started_at', 'r.finished_at'])
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
            ->get(['r.id', 'r.username', 'r.template_name', 'r.status', 'r.created_at', 'r.finished_at', 't.result', 't.unexpected'])
            ->map(fn ($r) => (array) $r)->all();
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
