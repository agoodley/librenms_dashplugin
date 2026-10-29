<?php

namespace Agoodley\LibrenmsCompliance\Data;

use Agoodley\LibrenmsCompliance\Support\Clock;

/**
 * The plugin's own values in compliance_config (name => JSON object): its settings, and the time of its last check.
 */
final class ConfigStore
{
    public function __construct(private readonly Sql $sql = new LaravelSql())
    {
    }

    /**
     * A stored value, null when there is none (or the table is missing: ./lnms migrate not run yet); a value that is no
     * JSON object throws.
     */
    public function get(string $name): ?array
    {
        if (! $this->sql->hasTable('compliance_config')) {
            return null;
        }
        $rows = $this->sql->select('SELECT `value` FROM ' . $this->sql->table('compliance_config') . ' WHERE `name` = ?', [$name]);
        if (! $rows) {
            return null;
        }
        $data = json_decode((string) $rows[0]['value'], true);
        if (! is_array($data)) {
            throw new \UnexpectedValueException("the compliance_config row $name is not a JSON object");
        }

        return $data;
    }

    /**
     * Store a value.
     */
    public function put(string $name, array $value): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = Clock::store(time());
        $this->sql->transaction(function () use ($name, $json, $now): void {
            // MySQL counts the rows an UPDATE changed, not those it found: whether the row exists is asked first
            if ($this->sql->select('SELECT `name` FROM ' . $this->sql->table('compliance_config') . ' WHERE `name` = ?', [$name])) {
                $this->sql->change('UPDATE ' . $this->sql->table('compliance_config') . ' SET `value` = ?, `updated_at` = ? WHERE `name` = ?', [$json, $now, $name]);
            } else {
                $this->sql->change('INSERT INTO ' . $this->sql->table('compliance_config') . ' (`name`, `value`, `updated_at`) VALUES (?, ?, ?)', [$name, $json, $now]);
            }
        });
    }
}
