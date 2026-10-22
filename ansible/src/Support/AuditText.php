<?php

namespace Agoodley\LibrenmsAnsible\Support;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;

/**
 * Eventlog entries of the plugin's configuration: who changed which setting, the runner connection (never the
 * token itself) or the run permission. LibreNMS escapes the eventlog when it shows it.
 */
final class AuditText
{
    /** Eventlog reference of these entries: the settings page. */
    public const REFERENCE = 'plugin/settings/ansible';

    /**
     * @param  string[]  $changes
     */
    public static function settingsChanged(string $user, array $changes): string
    {
        return 'Ansible plugin settings changed by ' . UntrustedText::line($user, 60) . ': ' . UntrustedText::line(implode('; ', $changes), 900);
    }

    /**
     * @param  string[]  $changes
     */
    public static function connectionChanged(string $user, array $changes): string
    {
        return 'Ansible plugin runner connection changed by ' . UntrustedText::line($user, 60) . ': ' . UntrustedText::line(implode('; ', $changes), 900);
    }

    public static function permissionGranted(string $by, string $to): string
    {
        return 'Ansible plugin run permission (' . Access::PERMISSION . ') granted to ' . UntrustedText::line($to, 60) . ' by ' . UntrustedText::line($by, 60);
    }

    public static function permissionRevoked(string $by, string $from): string
    {
        return 'Ansible plugin run permission (' . Access::PERMISSION . ') revoked from ' . UntrustedText::line($from, 60) . ' by ' . UntrustedText::line($by, 60);
    }
}
