<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * The settings the change lifecycle works with (from Support\PluginSettings): approvals, how long a dry run and an
 * approval count, backups and maintenance windows, what a batch planned by the topology plugin does when its blast
 * radius grew since the approval (an approver accepts it, or the batch is refused) or cannot be computed (an admin
 * continues without it, or the batch is refused), and how each batch is verified (phase 4).
 */
final class ChangeSettings
{
    /**
     * @param  string  $approval  ApprovalRules::MODES
     * @param  bool  $refuseWithoutBackup  refuse a batch without a fresh backup of each of its devices (else continue with a warning)
     * @param  int|null  $maintenanceBehavior  LibreNMS\Enum\MaintenanceBehavior value, null: LibreNMS' default
     * @param  string  $changeUrl  the address of a change's page, with %d for its id (for the windows' notes)
     * @param  bool  $refuseGrownBlast  refuse a batch whose blast radius grew since the approval (else it waits for an approver to accept the new one)
     * @param  bool  $refuseUnknownBlast  refuse a batch whose blast radius cannot be computed (else it waits for an admin to continue without it)
     * @param  bool  $verify  verify each batch with the topology plugin (the states before and after it compared); the plugin's settings
     *                         switch it on by default (Support\PluginSettings), a lifecycle made without them does not verify
     * @param  string  $verifyFresh  ask (LibreNMS is asked for a discovery of the devices) or wait (for LibreNMS' own discovery and polling)
     * @param  int  $verifyWaitSeconds  how long a verification waits for fresh data (and the state before the first batch, for its refresh)
     * @param  string  $verifyUnexpected  when a batch made unexpected changes: pause (until an admin accepts them), stop (the change), record (and go on)
     * @param  string  $verifyUnknown  when a batch cannot be verified: pause, stop or record
     * @param  string  $verifyMinimum  the lowest severity a new or worse finding counts from (critical, warning, info); lower ones are listed only
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
        public readonly bool $refuseGrownBlast = false,
        public readonly bool $refuseUnknownBlast = false,
        public readonly bool $verify = false,
        public readonly string $verifyFresh = 'ask',
        public readonly int $verifyWaitSeconds = 1800,
        public readonly string $verifyUnexpected = 'pause',
        public readonly string $verifyUnknown = 'pause',
        public readonly string $verifyMinimum = 'warning',
    ) {
    }
}
