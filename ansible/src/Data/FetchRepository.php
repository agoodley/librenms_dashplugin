<?php

namespace Agoodley\LibrenmsAnsible\Data;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * When the inventory was last fetched with each LibreNMS user's API token, and how many hosts it got: the settings
 * page proposes those users as the inventory user, and the Inventory page shows the inventory user's last fetch.
 */
final class FetchRepository
{
    /**
     * Record a fetch of the inventory by a user (one row per user, the latest fetch).
     */
    public function record(int $userId, string $username, int $hosts, ?string $address): void
    {
        try {
            DB::table('ansible_inventory_fetches')->updateOrInsert(['user_id' => $userId],
                ['username' => $username, 'hosts' => $hosts, 'address' => $address !== null ? substr($address, 0, 45) : null, 'fetched_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('ansible plugin: cannot record the inventory fetch: ' . \Agoodley\LibrenmsAnsible\Support\ErrorText::forLog($e));
        }
    }

    /**
     * The fetches, newest first.
     *
     * @return array<int, array{user_id: int, username: string, hosts: int, address: ?string, fetched_at: ?string}>
     */
    public function all(): array
    {
        try {
            return DB::table('ansible_inventory_fetches')->orderByDesc('fetched_at')->get()
                ->map(fn ($r) => ['user_id' => (int) $r->user_id, 'username' => (string) $r->username, 'hosts' => (int) $r->hosts,
                    'address' => $r->address, 'fetched_at' => $r->fetched_at])->all();
        } catch (\Throwable $e) {
            Log::debug('ansible plugin: cannot read the inventory fetches: ' . \Agoodley\LibrenmsAnsible\Support\ErrorText::forLog($e));

            return [];
        }
    }

    /**
     * The latest fetch with a user's token, null when there was none.
     *
     * @return array{user_id: int, username: string, hosts: int, address: ?string, fetched_at: ?string}|null
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
