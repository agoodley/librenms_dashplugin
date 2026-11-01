<?php

namespace Agoodley\LibrenmsInventory\Support;

/**
 * Who may see what. Every LibreNMS user may open the plugin's pages, each for the devices LibreNMS lets them see, as on
 * LibreNMS' own device and inventory pages. The hardware inventory (entPhysical), the optics (transceivers) and the
 * ports have permissions of their own in LibreNMS (its policies, which custom roles can leave out): what a user's role
 * may not view, the pages leave out and say so.
 */
final class Access
{
    /**
     * May the user open the plugin's pages (logged in)?
     */
    public static function canView(mixed $user): bool
    {
        return is_object($user);
    }

    /**
     * Is the user a LibreNMS admin?
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
     * The devices the user may see: null for every device (admins, users LibreNMS lets see every device), else their ids.
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
     * May the user view this kind of LibreNMS data (its policy's viewAny)? True on a LibreNMS without such a model or
     * policy, which shows it to everyone who may see the device.
     *
     * @param  class-string  $model  e.g. \App\Models\EntPhysical::class
     */
    public static function allows(mixed $user, string $model): bool
    {
        try {
            if (! class_exists($model) || \Illuminate\Support\Facades\Gate::getPolicyFor($model) === null) {
                return true;
            }

            return \Illuminate\Support\Facades\Gate::forUser($user)->allows('viewAny', $model);
        } catch (\Throwable) {
            return false;
        }
    }
}
