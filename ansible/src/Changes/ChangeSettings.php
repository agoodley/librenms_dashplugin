<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * The settings the change lifecycle works with (from Support\PluginSettings): approvals, how long a dry run and an
 * approval count, backups and maintenance windows.
 */
final class ChangeSettings
{
    /**
     * @param  string  $approval  ApprovalRules::MODES
     * @param  bool  $refuseWithoutBackup  refuse a batch without a fresh backup of each of its devices (else continue with a warning)
     * @param  int|null  $maintenanceBehavior  LibreNMS\Enum\MaintenanceBehavior value, null: LibreNMS' default
     * @param  string  $changeUrl  the address of a change's page, with %d for its id (for the windows' notes)
     */
    public function __construct(
        public readonly string $approval = ApprovalRules::REQUIRED,
        public readonly int $approvalSeconds = 86400,
        public readonly int $dryRunSeconds = 86400,
        public readonly bool $backups = true,
        public readonly bool $refuseWithoutBackup = true,
        public readonly int $backupTimeoutSeconds = 600,
        public readonly bool $maintenance = true,
        public readonly ?int $maintenanceBehavior = null,
        public readonly int $expectedSeconds = 3600,
        public readonly int $settleSeconds = 600,
        public readonly string $changeUrl = 'plugin/ansible/changes/%d',
    ) {
    }
}
