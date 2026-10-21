<?php

namespace Agoodley\LibrenmsAnsible\Data;

use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit log: runs (ansible_runs) and their targets (ansible_run_targets). Only LibreNMS admins see it, and
 * every admin sees every run, so queries are not scoped by device. Rows are returned as arrays with their JSON
 * columns decoded.
 */
final class RunRepository
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
     * Devices that are targets of an unfinished run.
     *
     * @return array<int, int> device_id => run id (the newest when several)
     */
    public function busy(): array
    {
        $busy = [];
        foreach (DB::table('ansible_run_targets as t')->join('ansible_runs as r', 'r.id', '=', 't.run_id')
            ->whereIn('r.status', RunStatus::UNFINISHED)->orderBy('r.id')->get(['t.device_id', 't.run_id']) as $row) {
            $busy[(int) $row->device_id] = (int) $row->run_id;
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
        foreach (DB::table('ansible_run_targets')->where('run_id', $runId)->orderBy('inventory_name')->get() as $row) {
            $row = (array) $row;
            $row['counts'] = $row['counts'] !== null ? json_decode((string) $row['counts'], true) : null;
            $targets[(int) $row['device_id']] = $row;
        }

        return $targets;
    }

    /**
     * Change columns of a run.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(int $id, array $values): void
    {
        DB::table('ansible_runs')->where('id', $id)->update(self::encode($values) + ['updated_at' => now()]);
    }

    /**
     * Change columns of a run that has not finished yet; false when it already had (another process finished it).
     *
     * @param  array<string, mixed>  $values
     */
    public function updateUnfinished(int $id, array $values): bool
    {
        return DB::table('ansible_runs')->where('id', $id)->whereIn('status', RunStatus::UNFINISHED)
            ->update(self::encode($values) + ['updated_at' => now()]) > 0;
    }

    /**
     * Store the result of each target.
     *
     * @param  array<int, array{result: string, counts: array<string, int>|null}>  $results  device_id => result
     */
    public function setResults(int $runId, array $results): void
    {
        foreach ($results as $deviceId => $result) {
            DB::table('ansible_run_targets')->where('run_id', $runId)->where('device_id', $deviceId)
                ->update(['result' => $result['result'], 'counts' => $result['counts'] !== null ? json_encode($result['counts']) : null]);
        }
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
     * A page of runs, newest first, with the number of targets.
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
        $counts = [];
        if ($rows) {
            foreach (DB::table('ansible_run_targets')->whereIn('run_id', array_column($rows, 'id'))->groupBy('run_id')
                ->get(['run_id', DB::raw('count(*) as targets')]) as $row) {
                $counts[(int) $row->run_id] = (int) $row->targets;
            }
        }
        foreach ($rows as &$row) {
            $row['targets'] = $counts[(int) $row['id']] ?? 0;
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Users, templates and devices that appear in the runs, for the filters (the latest names).
     *
     * @return array{users: string[], templates: array<string, string>, devices: array<int, string>}
     */
    public function filterOptions(): array
    {
        $templates = [];
        foreach (DB::table('ansible_runs')->orderBy('id')->get(['template_id', 'template_name']) as $row) {
            $templates[(string) $row->template_id] = (string) $row->template_name;
        }
        asort($templates);
        $devices = [];
        foreach (DB::table('ansible_run_targets')->orderBy('id')->get(['device_id', 'display', 'hostname']) as $row) {
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
            ->get(['r.id', 'r.username', 'r.template_name', 'r.status', 'r.created_at', 'r.finished_at', 't.result'])
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
