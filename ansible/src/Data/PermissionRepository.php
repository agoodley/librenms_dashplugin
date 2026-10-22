<?php

namespace Agoodley\LibrenmsAnsible\Data;

use Agoodley\LibrenmsAnsible\Support\Access;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * The plugin's permissions (run and approve: Support\Access). Each is a direct permission of a user: LibreNMS' role
 * editor saves only the permissions of its own list (resources/definitions/permissions.yaml), so a plugin permission
 * given to a role would be dropped the next time someone saves that role, and its user editor only sets roles.
 */
final class PermissionRepository
{
    /**
     * The users who hold a permission.
     *
     * @return array<int, array{user_id: int, username: string, realname: string, admin: bool, enabled: bool, holds: bool}>
     */
    public function holders(string $permission = Access::PERMISSION): array
    {
        return User::whereHas('permissions', fn ($q) => $q->where('name', $permission))->orderBy('username')->get()
            ->map(fn (User $u) => self::row($u, $permission))->all();
    }

    /**
     * LibreNMS admins who do not hold it (the only users who can use it).
     *
     * @return array<int, array{user_id: int, username: string, realname: string, admin: bool, enabled: bool, holds: bool}>
     */
    public function candidates(string $permission = Access::PERMISSION): array
    {
        return User::role('admin')->whereDoesntHave('permissions', fn ($q) => $q->where('name', $permission))->orderBy('username')->get()
            ->map(fn (User $u) => self::row($u, $permission))->all();
    }

    /**
     * A user as the settings page lists it, null when there is no such user.
     *
     * @return array{user_id: int, username: string, realname: string, admin: bool, enabled: bool, holds: bool}|null
     */
    public function user(int $userId, string $permission = Access::PERMISSION): ?array
    {
        $user = User::find($userId);

        return $user === null ? null : self::row($user, $permission);
    }

    /**
     * Give a permission to a user; the user's name, null when there is no such user.
     */
    public function grant(int $userId, string $permission = Access::PERMISSION): ?string
    {
        $user = User::find($userId);
        if ($user === null) {
            return null;
        }
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);

        return (string) $user->username;
    }

    /**
     * Take a permission from a user; the user's name, null when there is no such user.
     */
    public function revoke(int $userId, string $permission = Access::PERMISSION): ?string
    {
        $user = User::find($userId);
        if ($user === null) {
            return null;
        }
        if (Access::holds($user, $permission)) {
            $user->revokePermissionTo($permission);
        }

        return (string) $user->username;
    }

    /**
     * Why a user may not (or no longer) do what a permission allows, null when they may: the change lifecycle checks the
     * admin who applied a change before each batch, and the approver of an approval.
     */
    public function problem(int $userId, string $permission): ?string
    {
        $user = User::find($userId);
        if ($user === null) {
            return 'no longer exists in LibreNMS';
        }
        if (! $user->enabled) {
            return 'is disabled in LibreNMS';
        }
        if (! Access::canView($user)) {
            return 'is no longer a LibreNMS admin';
        }
        if (! Access::holds($user, $permission)) {
            return 'no longer holds the ' . (Access::PERMISSIONS[$permission] ?? $permission) . ' permission (' . $permission . ')';
        }

        return null;
    }

    /**
     * A user as the settings page lists it.
     *
     * @return array{user_id: int, username: string, realname: string, admin: bool, enabled: bool, holds: bool}
     */
    private static function row(User $user, string $permission): array
    {
        return ['user_id' => (int) $user->user_id, 'username' => (string) $user->username, 'realname' => (string) $user->realname,
            'admin' => $user->hasRole('admin'), 'enabled' => (bool) $user->enabled, 'holds' => Access::holds($user, $permission)];
    }
}
