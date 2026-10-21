<?php

namespace Agoodley\LibrenmsAnsible\Data;

use Agoodley\LibrenmsAnsible\Support\Access;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * The plugin's run permission. It is a direct permission of a user: LibreNMS' role editor saves only the
 * permissions of its own list (resources/definitions/permissions.yaml), so a plugin permission given to a role
 * would be dropped the next time someone saves that role, and its user editor only sets roles.
 */
final class PermissionRepository
{
    /**
     * The users who hold the permission.
     *
     * @return array<int, array{user_id: int, username: string, realname: string, admin: bool, enabled: bool}>
     */
    public function holders(): array
    {
        return User::whereHas('permissions', fn ($q) => $q->where('name', Access::PERMISSION))->orderBy('username')->get()
            ->map(fn (User $u) => self::row($u))->all();
    }

    /**
     * LibreNMS admins who do not hold it (the only users who can use it).
     *
     * @return array<int, array{user_id: int, username: string, realname: string, admin: bool, enabled: bool}>
     */
    public function candidates(): array
    {
        return User::role('admin')->whereDoesntHave('permissions', fn ($q) => $q->where('name', Access::PERMISSION))->orderBy('username')->get()
            ->map(fn (User $u) => self::row($u))->all();
    }

    /**
     * A user as the settings page lists it, null when there is no such user.
     *
     * @return array{user_id: int, username: string, realname: string, admin: bool, enabled: bool}|null
     */
    public function user(int $userId): ?array
    {
        $user = User::find($userId);

        return $user === null ? null : self::row($user);
    }

    /**
     * Give the permission to a user; the user's name, null when there is no such user.
     */
    public function grant(int $userId): ?string
    {
        $user = User::find($userId);
        if ($user === null) {
            return null;
        }
        Permission::findOrCreate(Access::PERMISSION, 'web');
        $user->givePermissionTo(Access::PERMISSION);

        return (string) $user->username;
    }

    /**
     * Take the permission from a user; the user's name, null when there is no such user.
     */
    public function revoke(int $userId): ?string
    {
        $user = User::find($userId);
        if ($user === null) {
            return null;
        }
        if (Access::holds($user)) {
            $user->revokePermissionTo(Access::PERMISSION);
        }

        return (string) $user->username;
    }

    /**
     * A user as the settings page lists it.
     *
     * @return array{user_id: int, username: string, realname: string, admin: bool, enabled: bool}
     */
    private static function row(User $user): array
    {
        return ['user_id' => (int) $user->user_id, 'username' => (string) $user->username, 'realname' => (string) $user->realname,
            'admin' => $user->hasRole('admin'), 'enabled' => (bool) $user->enabled];
    }
}
