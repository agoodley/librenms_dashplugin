<?php

namespace Agoodley\LibrenmsTopology\Support;

use Agoodley\LibrenmsTopology\History\FindingHistory;
use Agoodley\LibrenmsTopology\Verification\FixBuilder;
use Illuminate\Support\Facades\Route;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * The link of a finding whose fix is clear (FixBuilder: port descriptions, the spanning tree root priority) to the
 * Ansible plugin's Fixes page, which makes a change of it: only when that plugin is enabled and has the page, and for a
 * LibreNMS admin who holds the permission the page needs (it is for making changes). Without the Ansible plugin the
 * topology plugin's pages are as they were: no link. The topology plugin never uses the Ansible plugin's classes: it
 * knows its plugin name, the page's route and the permission's name only.
 */
final class AnsibleFixes
{
    /** The Ansible plugin's name in LibreNMS. */
    public const PLUGIN = 'ansible';

    /** The route of its Fixes page (since its phase 4). */
    public const ROUTE = 'ansible.fixes';

    /** The permission its Fixes page needs (a direct permission of the user, as the Ansible plugin reads it). */
    public const PERMISSION = 'ansible.run';

    /**
     * The URL of the Fixes page for this user, null when there is none for them.
     */
    public static function pageUrl(mixed $user): ?string
    {
        try {
            if (! is_object($user) || ! method_exists($user, 'can') || ! $user->can('admin') || ! method_exists($user, 'hasDirectPermission')
                || ! $user->hasDirectPermission(self::PERMISSION) || ! Route::has(self::ROUTE)
                || ! app(PluginManagerInterface::class)->pluginEnabled(self::PLUGIN)) {
                return null;
            }

            return route(self::ROUTE);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The link of one finding to the Fixes page (which marks the fix of that finding), null when the page is none or the
     * finding is not one it fixes. Filtered to the finding's first device: the page then lists that device's fixes and
     * those of the findings about it, which the topology plugin computes for it alone (fast on a large network).
     */
    public static function link(?string $page, string $analysis, array $finding): ?string
    {
        if ($page === null || ! self::fixable($analysis, $finding)) {
            return null;
        }
        $device = (int) (array_values((array) ($finding['devices'] ?? []))[0] ?? 0);

        return $page . '?' . ($device > 0 ? 'device=' . $device . '&' : '') . 'finding=' . FindingHistory::hash($analysis, $finding);
    }

    /**
     * Is the finding one whose fix the verification API's fixes() builds?
     */
    public static function fixable(string $analysis, array $finding): bool
    {
        $codes = match ($analysis) {
            'links' => FixBuilder::CODES[FixBuilder::PORT_DESCRIPTIONS],
            'stp' => FixBuilder::CODES[FixBuilder::STP_ROOT_PRIORITY],
            default => [],
        };

        return in_array($finding['code'] ?? null, $codes, true);
    }
}
