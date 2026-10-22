<?php

namespace Agoodley\LibrenmsAnsible\Data;

use Agoodley\LibrenmsAnsible\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * When the inventory was last fetched with each LibreNMS user's API token, how many hosts it got and which version
 * of the inventory script asked: the settings page proposes those users as the inventory user, and the Inventory
 * page shows the inventory user's last fetch.
 */
final class FetchRepository
{
    /**
     * Record a fetch of the inventory by a user (one row per user, the latest fetch).
     *
     * @param  string|null  $scriptVersion  the inventory script's version (Support\InventoryScript), null when another client fetched it
     */
    public function record(int $userId, string $username, int $hosts, ?string $address, ?string $scriptVersion = null): void
    {
        try {
            // in UTC, like the times of the runs (Support\Clock)
            $values = ['username' => $username, 'hosts' => $hosts, 'address' => $address !== null ? substr($address, 0, 45) : null, 'fetched_at' => Clock::store(time())];
            if (Schema::hasColumn('ansible_inventory_fetches', 'script_version')) {
                $values['script_version'] = $scriptVersion;
            }
            DB::table('ansible_inventory_fetches')->updateOrInsert(['user_id' => $userId], $values);
        } catch (\Throwable $e) {
            Log::error('ansible plugin: cannot record the inventory fetch: ' . \Agoodley\LibrenmsAnsible\Support\ErrorText::forLog($e));
        }
    }

    /**
     * The fetches, newest first.
     *
     * @return array<int, array{user_id: int, username: string, hosts: int, address: ?string, fetched_at: ?string, script_version: ?string}>
     */
    public function all(): array
    {
        try {
            return DB::table('ansible_inventory_fetches')->orderByDesc('fetched_at')->get()
                ->map(fn ($r) => ['user_id' => (int) $r->user_id, 'username' => (string) $r->username, 'hosts' => (int) $r->hosts,
                    'address' => $r->address, 'fetched_at' => $r->fetched_at, 'script_version' => $r->script_version ?? null])->all();
        } catch (\Throwable $e) {
            Log::debug('ansible plugin: cannot read the inventory fetches: ' . \Agoodley\LibrenmsAnsible\Support\ErrorText::forLog($e));

            return [];
        }
    }

    /**
     * The latest fetch with a user's token, null when there was none.
     *
     * @return array{user_id: int, username: string, hosts: int, address: ?string, fetched_at: ?string, script_version: ?string}|null
     */
    public function forUser(int $userId): ?array
    {
        foreach ($this->all() as $fetch) {
            if ($fetch['user_id'] === $userId) {
                return $fetch;
            }
        }

        return null;
    }
}
