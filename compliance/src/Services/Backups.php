<?php

namespace Agoodley\LibrenmsCompliance\Services;

/**
 * Where a check reads the configurations: LibreNMS' configuration backups (LibrenmsBackups); the tests give their own.
 */
interface Backups
{
    /**
     * Why the plugin cannot read backups on this LibreNMS, null when it can.
     */
    public function unavailable(): ?string;

    /**
     * The device's latest backup, or why there is none.
     */
    public function latest(int $deviceId): Backup;
}
