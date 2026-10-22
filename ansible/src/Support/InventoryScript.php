<?php

namespace Agoodley\LibrenmsAnsible\Support;

/**
 * The plugin's inventory script (contrib/librenms_inventory.py), which admins copy into the playbook repository: the
 * version this plugin ships, and the version a fetch came from (its User-Agent, librenms-ansible-inventory/VERSION),
 * so the Inventory page can say when the runner still uses an older copy.
 */
final class InventoryScript
{
    /** The VERSION of contrib/librenms_inventory.py (a test keeps them equal). */
    public const VERSION = '3';

    /**
     * The script version a User-Agent names; null when it is not the plugin's script.
     */
    public static function versionFrom(?string $userAgent): ?string
    {
        return $userAgent !== null && preg_match('#^librenms-ansible-inventory/(\d{1,6})(?:\s|$)#', $userAgent, $m) ? $m[1] : null;
    }

    /**
     * Is a version older than the one this plugin ships?
     */
    public static function outdated(?string $version): bool
    {
        return $version !== null && (int) $version < (int) self::VERSION;
    }
}
