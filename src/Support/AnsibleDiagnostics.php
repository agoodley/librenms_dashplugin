<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * The link of the maps to the Ansible plugin's diagnostics page, which runs a template in check mode (a diagnostic run:
 * show commands, a compliance check) on the devices it is given: from a device's panel, or from the devices selected
 * on the map (ctrl-click, or a long press on a touch screen). Only when that plugin is enabled and has the page, for a
 * LibreNMS admin who holds its run permission (AnsibleFixes::routeUrl()); the topology plugin knows its route only.
 */
final class AnsibleDiagnostics
{
    /** The route of the Ansible plugin's diagnostics page. */
    public const ROUTE = 'ansible.diagnostics';

    /**
     * The URL of the diagnostics page for this user, null when there is none for them. The maps add ?devices=ID,ID.
     */
    public static function pageUrl(mixed $user): ?string
    {
        return AnsibleFixes::routeUrl($user, self::ROUTE);
    }
}
