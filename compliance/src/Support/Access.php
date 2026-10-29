<?php

namespace Agoodley\LibrenmsCompliance\Support;

/**
 * Who may do what. The findings show lines of the configurations (their secrets hidden), so they are for the users
 * LibreNMS lets read configuration backups: LibreNMS admins, and users with LibreNMS' config-backup.view permission
 * (device.showConfig on older LibreNMS), each for the devices they may see, as on a device's Config tab. LibreNMS'
 * global read role alone is not enough there, and is not here either. Changing the settings and checking a device now
 * are for admins.
 */
final class Access
{
    /** LibreNMS' permission to read configuration backups, by its current and its former name. */
    public const BACKUP_VIEW = ['config-backup.view', 'device.showConfig'];

    /**
     * May the user open the plugin's pages?
     */
    public static function canView(mixed $user): bool
    {
        return self::isAdmin($user) || self::readsBackups($user);
    }

    /**
     * Is the user a LibreNMS admin (settings, checking now)?
     */
    public static function isAdmin(mixed $user): bool
    {
        try {
            return is_object($user) && method_exists($user, 'can') && $user->can('admin');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The devices whose results the user may see: null for every device (admins, users LibreNMS lets see every
     * device), else their ids.
     *
     * @return int[]|null
     */
    public static function deviceIds(mixed $user): ?array
    {
        if (! self::canView($user)) {
            return [];
        }
        if (self::isAdmin($user) || \Illuminate\Support\Facades\Gate::forUser($user)->allows('viewAll', \App\Models\Device::class)) {
            return null;
        }

        return array_map('intval', \App\Models\Device::hasAccess($user)->pluck('devices.device_id')->all());
    }

    /**
     * May the user see this device's results?
     */
    public static function canSeeDevice(mixed $user, int $deviceId): bool
    {
        $ids = self::deviceIds($user);

        return $ids === null || in_array($deviceId, $ids, true);
    }

    /**
     * Does the user hold LibreNMS' permission to read configuration backups (through a role or directly; not through
     * LibreNMS' global read role, as LibreNMS' own Config tab)?
     */
    private static function readsBackups(mixed $user): bool
    {
        if (! is_object($user) || ! method_exists($user, 'checkPermissionTo')) {
            return false;
        }
        foreach (self::BACKUP_VIEW as $permission) {
            try {
                if ($user->checkPermissionTo($permission)) {
                    return true;
                }
            } catch (\Throwable) {
                // a permission this LibreNMS does not have
            }
        }

        return false;
    }
}
