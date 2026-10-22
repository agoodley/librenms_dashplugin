<?php

namespace Agoodley\LibrenmsAnsible\Data;

use App\Models\User;

/**
 * LibreNMS' users, for the inventory user setting.
 */
final class UserRepository
{
    /**
     * Every user, by username.
     *
     * @return array<int, array{user_id: int, username: string, realname: string, enabled: bool}> user_id => user
     */
    public function all(): array
    {
        $users = [];
        foreach (User::orderBy('username')->get(['user_id', 'username', 'realname', 'enabled']) as $user) {
            $users[(int) $user->user_id] = ['user_id' => (int) $user->user_id, 'username' => (string) $user->username,
                'realname' => (string) $user->realname, 'enabled' => (bool) $user->enabled];
        }

        return $users;
    }

    /**
     * Every user's name.
     *
     * @return array<int, string> user_id => username
     */
    public function names(): array
    {
        return array_map(fn ($user) => $user['username'], $this->all());
    }
}
