<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\History\FindingHistory;
use Agoodley\LibrenmsTopology\Support\AnsibleDiagnostics;
use Agoodley\LibrenmsTopology\Support\AnsibleFixes;
use PHPUnit\Framework\TestCase;

/**
 * A finding whose fix is clear links to the Ansible plugin's Fixes page, which marks the fix of that finding; other
 * findings, users who are no admins, and a LibreNMS without the Ansible plugin get no link. So do the maps' links to its
 * diagnostics page.
 */
class AnsibleFixesTest extends TestCase
{
    public function testOnlyTheFindingsTheFixesPageFixesAreLinked(): void
    {
        $wrong = ['code' => 'description_wrong', 'devices' => [511], 'ports' => [95110], 'vlan' => null];
        $root = ['code' => 'root_not_preferred', 'devices' => [503], 'ports' => [], 'vlan' => 10];

        $this->assertSame('https://nms/plugin/ansible/fixes?device=511&finding=' . FindingHistory::hash('links', $wrong),
            AnsibleFixes::link('https://nms/plugin/ansible/fixes', 'links', $wrong), 'the finding\'s identity, as the fixes carry it, and its device (the page filtered to it)');
        $this->assertSame('https://nms/plugin/ansible/fixes?device=503&finding=' . FindingHistory::hash('stp', $root), AnsibleFixes::link('https://nms/plugin/ansible/fixes', 'stp', $root));
        $this->assertSame('https://nms/plugin/ansible/fixes?finding=' . FindingHistory::hash('stp', ['devices' => []] + $root),
            AnsibleFixes::link('https://nms/plugin/ansible/fixes', 'stp', ['devices' => []] + $root), 'a finding without a device: the page of every device');
        $this->assertTrue(AnsibleFixes::fixable('links', ['code' => 'description_missing']));
        $this->assertTrue(AnsibleFixes::fixable('stp', ['code' => 'root_default_priority']));
        $this->assertFalse(AnsibleFixes::fixable('links', ['code' => 'vlan_mismatch']), 'no clear fix');
        $this->assertFalse(AnsibleFixes::fixable('stp', $wrong), 'a code of another check');
        $this->assertFalse(AnsibleFixes::fixable('redundancy', ['code' => 'description_wrong']));
        $this->assertNull(AnsibleFixes::link(null, 'links', $wrong), 'no Fixes page for the user: no link');
    }

    public function testOnlyAnAdminWithTheRunPermissionHasTheFixesPage(): void
    {
        $user = new class {
            public function can(string $ability): bool
            {
                return false;
            }
        };

        $this->assertNull(AnsibleFixes::pageUrl($user));
        $this->assertNull(AnsibleFixes::pageUrl(null));

        // an admin without the Ansible plugin's run permission: the page is for making changes, theirs it is not
        $admin = new class {
            public function can(string $ability): bool
            {
                return $ability === 'admin';
            }

            public function hasDirectPermission(string $permission): bool
            {
                return false;
            }
        };
        $this->assertNull(AnsibleFixes::pageUrl($admin));

        // the maps' link to its diagnostics page (diagnostic runs) needs the same: neither has it
        $this->assertNull(AnsibleDiagnostics::pageUrl($user));
        $this->assertNull(AnsibleDiagnostics::pageUrl($admin));
        $this->assertNull(AnsibleDiagnostics::pageUrl(null));
        $this->assertSame('ansible.diagnostics', AnsibleDiagnostics::ROUTE);
    }
}
