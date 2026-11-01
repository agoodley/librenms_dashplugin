<?php

namespace Agoodley\LibrenmsInventory\Data;

use Agoodley\LibrenmsInventory\Support\Clock;

/**
 * The plugin's tables of the software checks: each device's software as the last check saw it, with its status against
 * the policies and since when (inventory_software), and the version changes the checks saw (inventory_upgrades). Times
 * are stored UTC ('Y-m-d H:i:s'); the rows come back with them as stored.
 */
final class SoftwareRepository
{
    /** The columns of a device's row the check writes. */
    public const COLUMNS = ['os', 'model', 'version', 'status', 'policy', 'message', 'status_since', 'version_since', 'updated_at'];

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
            return $this->sql->hasTable('inventory_software') && $this->sql->hasTable('inventory_upgrades') && $this->sql->hasTable('inventory_config');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The rows of these devices (null: of every device), by device id.
     *
     * @param  int[]|null  $deviceIds
     * @return array<int, array>
     */
    public function rows(?array $deviceIds): array
    {
        $rows = [];
        foreach ($this->scoped('SELECT * FROM ' . $this->sql->table('inventory_software'), $deviceIds) as $row) {
            $row['device_id'] = (int) $row['device_id'];
            $rows[$row['device_id']] = $row;
        }

        return $rows;
    }

    /**
     * Insert or update a device's row (Unix times for the times).
     *
     * @param  array{os: string, model: string, version: string, status: string, policy: ?string, message: string, status_since: int, version_since: int,
     *               updated_at: int}  $row
     */
    public function put(int $deviceId, array $row, bool $exists): void
    {
        $values = [
            mb_substr((string) $row['os'], 0, 64), mb_substr((string) $row['model'], 0, 255), mb_substr((string) $row['version'], 0, 255), (string) $row['status'],
            $row['policy'] !== null ? mb_substr((string) $row['policy'], 0, 100) : null, mb_substr((string) $row['message'], 0, 2000),
            Clock::store((int) $row['status_since']), Clock::store((int) $row['version_since']), Clock::store((int) $row['updated_at']),
        ];
        $table = $this->sql->table('inventory_software');
        if ($exists) {
            $this->sql->change("UPDATE $table SET " . implode(', ', array_map(fn (string $column) => "`$column` = ?", self::COLUMNS)) . ' WHERE `device_id` = ?',
                [...$values, $deviceId]);

            return;
        }
        $this->sql->change("INSERT INTO $table (`device_id`, " . implode(', ', array_map(fn (string $column) => "`$column`", self::COLUMNS)) . ') VALUES ('
            . implode(', ', array_fill(0, count(self::COLUMNS) + 1, '?')) . ')', [$deviceId, ...$values]);
    }

    /**
     * Record a version change a check saw.
     */
    public function addUpgrade(int $deviceId, string $os, string $model, string $from, string $to, string $direction, ?int $fromSince, int $seenAt): void
    {
        $this->sql->change('INSERT INTO ' . $this->sql->table('inventory_upgrades') . ' (`device_id`, `os`, `model`, `from_version`, `to_version`, `direction`, `from_since`, `seen_at`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$deviceId, mb_substr($os, 0, 64), mb_substr($model, 0, 255), mb_substr($from, 0, 255), mb_substr($to, 0, 255),
                $direction, $fromSince !== null ? Clock::store($fromSince) : null, Clock::store($seenAt)]);
    }

    /**
     * The version changes of these devices (null: of every device), the newest first: a page of them and how many there
     * are.
     *
     * @param  int[]|null  $deviceIds
     * @return array{rows: list<array>, total: int}
     */
    public function upgrades(?array $deviceIds, ?int $limit = null, int $offset = 0): array
    {
        $rows = $this->scoped('SELECT * FROM ' . $this->sql->table('inventory_upgrades'), $deviceIds);
        usort($rows, fn (array $a, array $b) => [(string) $b['seen_at'], (int) $b['id']] <=> [(string) $a['seen_at'], (int) $a['id']]);
        $total = count($rows);
        $rows = $limit === null ? $rows : array_slice($rows, max(0, $offset), $limit);

        return ['rows' => array_map(function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['device_id'] = (int) $row['device_id'];

            return $row;
        }, $rows), 'total' => $total];
    }

    /**
     * Forget the rows of the devices the check no longer covers (their version changes stay while LibreNMS has the
     * device). How many it forgot.
     *
     * @param  int[]  $keep
     */
    public function forgetOthers(array $keep): int
    {
        $keep = array_fill_keys(array_map('intval', $keep), true);
        $gone = array_values(array_filter(array_map(fn (array $row) => (int) $row['device_id'],
            $this->sql->select('SELECT `device_id` FROM ' . $this->sql->table('inventory_software'))), fn (int $id) => ! isset($keep[$id])));
        foreach (array_chunk($gone, 500) as $chunk) {
            $this->sql->change('DELETE FROM ' . $this->sql->table('inventory_software') . ' WHERE `device_id` IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk);
        }

        return count($gone);
    }

    /**
     * Forget the version changes of the devices LibreNMS no longer has. How many it forgot.
     *
     * @param  int[]  $existing  every device LibreNMS has
     */
    public function pruneUpgrades(array $existing): int
    {
        $existing = array_fill_keys(array_map('intval', $existing), true);
        $gone = array_values(array_unique(array_filter(array_map(fn (array $row) => (int) $row['device_id'],
            $this->sql->select('SELECT DISTINCT `device_id` FROM ' . $this->sql->table('inventory_upgrades'))), fn (int $id) => ! isset($existing[$id]))));
        $forgotten = 0;
        foreach (array_chunk($gone, 500) as $chunk) {
            $forgotten += $this->sql->change('DELETE FROM ' . $this->sql->table('inventory_upgrades') . ' WHERE `device_id` IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk);
        }

        return $forgotten;
    }

    /**
     * Run $callback in one transaction.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function transaction(\Closure $callback): mixed
    {
        return $this->sql->transaction($callback);
    }

    /**
     * A query's rows, limited to these devices (null: every device).
     *
     * @param  int[]|null  $deviceIds
     * @return array<int, array<string, mixed>>
     */
    private function scoped(string $query, ?array $deviceIds): array
    {
        if ($deviceIds === null) {
            return $this->sql->select($query);
        }
        $rows = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $deviceIds))), self::MAX_IDS) as $chunk) {
            array_push($rows, ...$this->sql->select($query . ' WHERE `device_id` IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk));
        }

        return $rows;
    }
}
