<?php

namespace Agoodley\LibrenmsAnsible\Data;

use Illuminate\Support\Facades\DB;

/**
 * The plugin's own configuration rows in ansible_config (name => JSON), for what it keeps in clear: the settings
 * and the last connection check. The runner connection has its own, encrypted row (ConnectionRepository).
 */
final class ConfigStore
{
    /**
     * A stored value, null when there is none (or the table is missing: ./lnms migrate not run yet); a value that
     * is no JSON object throws.
     */
    public function get(string $name): ?array
    {
        if (! SchemaCheck::hasTable('ansible_config')) {
            return null;
        }
        $value = DB::table('ansible_config')->where('name', $name)->value('value');
        if ($value === null) {
            return null;
        }
        $data = json_decode((string) $value, true);
        if (! is_array($data)) {
            throw new \UnexpectedValueException("the ansible_config row $name is not a JSON object");
        }

        return $data;
    }

    /**
     * Store a value.
     */
    public function put(string $name, array $value): void
    {
        DB::table('ansible_config')->updateOrInsert(['name' => $name],
            ['value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
    }

    /**
     * Remove a value.
     */
    public function forget(string $name): void
    {
        if (SchemaCheck::hasTable('ansible_config')) {
            DB::table('ansible_config')->where('name', $name)->delete();
        }
    }
}
