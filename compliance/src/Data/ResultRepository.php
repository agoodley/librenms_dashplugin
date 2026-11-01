<?php

namespace Agoodley\LibrenmsCompliance\Data;

use Agoodley\LibrenmsCompliance\Rules\Rule;
use Agoodley\LibrenmsCompliance\Support\Clock;

/**
 * The checks of the devices (compliance_devices) and the findings of the rules that failed (compliance_findings: one per
 * device and rule, with since when). A device's check replaces its findings at once: a rule that failed again keeps its
 * first time, one that no longer fails is gone (resolved).
 */
final class ResultRepository
{
    /** The status of a device whose last check read its backup (else: Services\Backup::NO_BACKUP or ERROR, why in its error). */
    public const CHECKED = 'checked';

    /** The columns of a device's row without its ports. */
    private const SUMMARY = '`device_id`, `status`, `provider`, `backup_at`, `checked_at`, `config_hash`, `os`, `error`, `outcomes`';

    /** Most device ids a query names (a database refuses more placeholders than 65,535). */
    private const MAX_IDS = 60000;

    public function __construct(private readonly Sql $sql = new LaravelSql())
    {
    }

    /**
     * Do the plugin's tables exist (./lnms migrate ran)?
     */
    public function available(): bool
    {
        try {
            return $this->sql->hasTable('compliance_devices') && $this->sql->hasTable('compliance_findings');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Store a device's check of a backup and its findings; what changed: the findings that are new and those resolved
     * (rule => finding), and those that failed before and now with another severity (a rule of the admin's changed).
     *
     * @param  array{status: string, provider: ?string, backup_at: ?int, config_hash: ?string, os: string, outcomes: array<string, array{0: string, 1: string}>,
     *               ports: array}  $check
     * @param  array<string, array{severity: string, message: string, evidence: array, ports: string[]}>  $findings  rule => its finding, of the rules that failed
     * @return array{new: array<string, array>, resolved: array<string, array>}
     */
    public function save(int $deviceId, array $check, array $findings, int $now): array
    {
        return $this->sql->transaction(function () use ($deviceId, $check, $findings, $now): array {
            $stored = $this->findings($deviceId);
            $at = Clock::store($now);
            $this->deviceRow($deviceId, ['status' => $check['status'], 'provider' => $check['provider'], 'backup_at' => $check['backup_at'] !== null
                ? Clock::store($check['backup_at']) : null, 'checked_at' => $at, 'config_hash' => $check['config_hash'], 'os' => mb_substr($check['os'], 0, 32),
                'error' => null, 'outcomes' => self::json($check['outcomes']), 'ports' => self::json($check['ports'])]);
            $new = [];
            foreach ($findings as $rule => $finding) {
                $values = [mb_substr((string) $finding['severity'], 0, 16), (string) $finding['message'], self::json($finding['evidence']), self::json($finding['ports']), $at];
                if (isset($stored[$rule])) {
                    $this->sql->change('UPDATE ' . $this->sql->table('compliance_findings') . ' SET `severity` = ?, `message` = ?, `evidence` = ?, `ports` = ?, `last_seen` = ?'
                        . ' WHERE `device_id` = ? AND `rule` = ?', array_merge($values, [$deviceId, $rule]));
                } else {
                    $this->sql->change('INSERT INTO ' . $this->sql->table('compliance_findings') . ' (`severity`, `message`, `evidence`, `ports`, `last_seen`, `device_id`,'
                        . ' `rule`, `first_seen`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', array_merge($values, [$deviceId, $rule, $at]));
                    $new[$rule] = $finding;
                }
            }
            $resolved = array_diff_key($stored, $findings);
            foreach (array_keys($resolved) as $rule) {
                $this->sql->change('DELETE FROM ' . $this->sql->table('compliance_findings') . ' WHERE `device_id` = ? AND `rule` = ?', [$deviceId, (string) $rule]);
            }

            return ['new' => $new, 'resolved' => $resolved];
        });
    }

    /**
     * A check that read no backup: the device's row says why. With $keep (the backup could not be read: error), its
     * last check's outcomes and findings stay, as of the backup they were checked on; without (the device has no backup:
     * no_backup), they go. The findings that went (rule => finding).
     *
     * @return array<string, array>
     */
    public function saveFailure(int $deviceId, string $status, ?string $provider, string $error, string $os, int $now, bool $keep = true): array
    {
        return $this->sql->transaction(function () use ($deviceId, $status, $provider, $error, $os, $now, $keep): array {
            $values = ['status' => $status, 'provider' => $provider, 'checked_at' => Clock::store($now), 'error' => mb_substr($error, 0, 2000), 'os' => mb_substr($os, 0, 32)];
            $gone = [];
            if (! $keep) {
                $values += ['backup_at' => null, 'config_hash' => null, 'outcomes' => null, 'ports' => null];
                $gone = $this->findings($deviceId);
                $this->sql->change('DELETE FROM ' . $this->sql->table('compliance_findings') . ' WHERE `device_id` = ?', [$deviceId]);
            }
            $this->deviceRow($deviceId, $values);

            return $gone;
        });
    }

    /**
     * A device's row, its JSON decoded; null when it was never checked.
     */
    public function device(int $deviceId): ?array
    {
        $rows = $this->sql->select('SELECT * FROM ' . $this->sql->table('compliance_devices') . ' WHERE `device_id` = ?', [$deviceId]);

        return $rows ? self::decodeDevice($rows[0]) : null;
    }

    /**
     * A device's findings by rule.
     *
     * @return array<string, array>
     */
    public function findings(int $deviceId): array
    {
        $findings = [];
        foreach ($this->sql->select('SELECT * FROM ' . $this->sql->table('compliance_findings') . ' WHERE `device_id` = ? ORDER BY `rule`', [$deviceId]) as $row) {
            $findings[(string) $row['rule']] = self::decodeFinding($row);
        }

        return $findings;
    }

    /**
     * The rows of these devices (null: of every device), by device id.
     *
     * @param  int[]|null  $deviceIds
     * @return array<int, array>
     */
    public function devices(?array $deviceIds): array
    {
        $devices = [];
        foreach ($this->scoped('SELECT * FROM ' . $this->sql->table('compliance_devices'), $deviceIds) as $row) {
            $devices[(int) $row['device_id']] = self::decodeDevice($row);
        }

        return $devices;
    }

    /**
     * The rows of these devices (null: of every device) without their ports, by device id.
     *
     * @param  int[]|null  $deviceIds
     * @return array<int, array>
     */
    public function summaries(?array $deviceIds): array
    {
        $devices = [];
        foreach ($this->scoped('SELECT ' . self::SUMMARY . ' FROM ' . $this->sql->table('compliance_devices'), $deviceIds) as $row) {
            $devices[(int) $row['device_id']] = self::decodeDevice($row);
        }

        return $devices;
    }

    /**
     * How many findings of each severity these devices (null: every device) have, by device id.
     *
     * @param  int[]|null  $deviceIds
     * @return array<int, array<string, int>>
     */
    public function counts(?array $deviceIds): array
    {
        $counts = [];
        foreach ($this->scoped('SELECT `device_id`, `severity`, `rule` FROM ' . $this->sql->table('compliance_findings'), $deviceIds) as $row) {
            $severity = (string) $row['severity'];
            $counts[(int) $row['device_id']][$severity] = ($counts[(int) $row['device_id']][$severity] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The findings of these devices (null: of every device), the worst first, then by device and rule; filtered by rule,
     * severity and text (in the message), a page of them and how many there are.
     *
     * @param  int[]|null  $deviceIds
     * @param  array{rule?: string, severity?: string, text?: string, device?: ?int}  $filters
     * @return array{rows: array<int, array>, total: int}
     */
    public function page(?array $deviceIds, array $filters, int $page, int $perPage): array
    {
        $where = [];
        $bindings = [];
        foreach (['rule', 'severity'] as $key) {
            if (($filters[$key] ?? '') !== '') {
                $where[] = "`$key` = ?";
                $bindings[] = (string) $filters[$key];
            }
        }
        if (($filters['device'] ?? null) !== null) {
            $where[] = '`device_id` = ?';
            $bindings[] = (int) $filters['device'];
        }
        if (($filters['text'] ?? '') !== '') {
            // ! escapes the wildcards: a backslash is no escape character on every database (SQLite, MySQL with NO_BACKSLASH_ESCAPES)
            $where[] = "`message` LIKE ? ESCAPE '!'";
            $bindings[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['text']) . '%';
        }
        $rows = $this->scoped('SELECT * FROM ' . $this->sql->table('compliance_findings') . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $deviceIds, $bindings);
        usort($rows, fn (array $a, array $b) => [Rule::rank((string) $a['severity']), (int) $a['device_id'], (string) $a['rule']]
            <=> [Rule::rank((string) $b['severity']), (int) $b['device_id'], (string) $b['rule']]);

        return ['rows' => array_map(fn (array $row) => self::decodeFinding($row), array_slice($rows, max(0, ($page - 1) * $perPage), $perPage)), 'total' => count($rows)];
    }

    /**
     * Forget the devices LibreNMS no longer has (or no longer checks): their rows and findings. How many it forgot.
     *
     * @param  int[]  $keep
     */
    public function forgetOthers(array $keep): int
    {
        $keep = array_fill_keys(array_map('intval', $keep), true);
        $gone = array_values(array_filter(array_map(fn (array $row) => (int) $row['device_id'],
            $this->sql->select('SELECT `device_id` FROM ' . $this->sql->table('compliance_devices'))), fn (int $id) => ! isset($keep[$id])));
        foreach (array_chunk($gone, 500) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            $this->sql->transaction(function () use ($chunk, $marks): void {
                $this->sql->change('DELETE FROM ' . $this->sql->table('compliance_findings') . " WHERE `device_id` IN ($marks)", $chunk);
                $this->sql->change('DELETE FROM ' . $this->sql->table('compliance_devices') . " WHERE `device_id` IN ($marks)", $chunk);
            });
        }

        return count($gone);
    }

    /**
     * Insert or update a device's row with these columns.
     */
    private function deviceRow(int $deviceId, array $values): void
    {
        $table = $this->sql->table('compliance_devices');
        if ($this->sql->select("SELECT `device_id` FROM $table WHERE `device_id` = ?", [$deviceId])) {
            $sets = implode(', ', array_map(fn (string $column) => "`$column` = ?", array_keys($values)));
            $this->sql->change("UPDATE $table SET $sets WHERE `device_id` = ?", array_merge(array_values($values), [$deviceId]));

            return;
        }
        $columns = array_merge(['device_id'], array_keys($values));
        $this->sql->change("INSERT INTO $table (" . implode(', ', array_map(fn (string $column) => "`$column`", $columns)) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')', array_merge([$deviceId], array_values($values)));
    }

    /**
     * A query's rows, limited to these devices (null: every device).
     *
     * @param  int[]|null  $deviceIds
     * @return array<int, array<string, mixed>>
     */
    private function scoped(string $query, ?array $deviceIds, array $bindings = []): array
    {
        if ($deviceIds === null) {
            return $this->sql->select($query, $bindings);
        }
        $rows = [];
        $glue = str_contains($query, ' WHERE ') ? ' AND ' : ' WHERE ';
        foreach (array_chunk(array_values(array_unique(array_map('intval', $deviceIds))), self::MAX_IDS - count($bindings)) as $chunk) {
            array_push($rows, ...$this->sql->select($query . $glue . '`device_id` IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                array_merge($bindings, $chunk)));
        }

        return $rows;
    }

    private static function decodeDevice(array $row): array
    {
        $row['device_id'] = (int) $row['device_id'];
        $row['outcomes'] = self::decode($row['outcomes'] ?? null);
        if (array_key_exists('ports', $row)) {
            $row['ports'] = self::decode($row['ports']);
        }

        return $row;
    }

    private static function decodeFinding(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['device_id'] = (int) $row['device_id'];
        $row['evidence'] = self::decode($row['evidence'] ?? null);
        $row['ports'] = self::decode($row['ports'] ?? null);

        return $row;
    }

    private static function decode(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : null;

        return is_array($value) ? $value : [];
    }

    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
