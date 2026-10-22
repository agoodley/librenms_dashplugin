<?php

namespace Agoodley\LibrenmsAnsible\Support;

/**
 * Who may do what. Viewing (runs, a run, the inventory, the device panel) is for LibreNMS admins; launching and
 * stopping also need the plugin's run permission, a direct permission of the user that an admin grants on the
 * settings page. Admins do not get it automatically: LibreNMS lets admins pass every Gate check, so the
 * permission is read from the user's own permissions, not through can().
 */
final class Access
{
    public const PERMISSION = 'ansible.run';

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
     * May the user launch and stop runs (an admin holding the run permission)?
     */
    public static function canRun(mixed $user): bool
    {
        return self::canView($user) && self::holds($user);
    }

    /**
     * Does the user hold the run permission itself (not through a role, never because they are an admin)?
     */
    public static function holds(mixed $user): bool
    {
        try {
            return is_object($user) && method_exists($user, 'hasDirectPermission') && $user->hasDirectPermission(self::PERMISSION);
        } catch (\Throwable) {
            return false; // the permission does not exist yet (migration not run)
        }
    }
}
