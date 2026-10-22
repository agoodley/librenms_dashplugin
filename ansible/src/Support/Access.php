<?php

namespace Agoodley\LibrenmsAnsible\Support;

/**
 * Who may do what. Viewing (changes, runs, the inventory, the device panel) is for LibreNMS admins; preparing,
 * dry-running, applying and stopping changes also need the plugin's run permission, approving and rejecting them its
 * approve permission. Both are direct permissions of the user that an admin grants on the settings page. Admins do not
 * get them automatically: LibreNMS lets admins pass every Gate check, so the permissions are read from the user's own
 * permissions, not through can().
 */
final class Access
{
    /** Prepare, dry-run, apply and stop changes (and release the devices of runs). */
    public const PERMISSION = 'ansible.run';

    /** Approve and reject changes. */
    public const APPROVE = 'ansible.approve';

    /** The plugin's permissions and what each allows, for the settings page and messages. */
    public const PERMISSIONS = [self::PERMISSION => 'run', self::APPROVE => 'approve'];

    /**
     * May the user view the plugin's pages (a LibreNMS admin)?
     */
    public static function canView(mixed $user): bool
    {
        try {
            return is_object($user) && method_exists($user, 'can') && $user->can('admin');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * May the user prepare, dry-run, apply and stop changes (an admin holding the run permission)?
     */
    public static function canRun(mixed $user): bool
    {
        return self::canView($user) && self::holds($user);
    }

    /**
     * May the user approve and reject changes (an admin holding the approve permission)?
     */
    public static function canApprove(mixed $user): bool
    {
        return self::canView($user) && self::holds($user, self::APPROVE);
    }

    /**
     * Does the user hold a permission of the plugin itself (not through a role, never because they are an admin)?
     */
    public static function holds(mixed $user, string $permission = self::PERMISSION): bool
    {
        try {
            return is_object($user) && method_exists($user, 'hasDirectPermission') && $user->hasDirectPermission($permission);
        } catch (\Throwable) {
            return false; // the permission does not exist yet (migration not run)
        }
    }
}
