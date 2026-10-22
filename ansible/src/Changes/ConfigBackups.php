<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * Configuration backups through LibreNMS' Oxidized integration (Services\OxidizedBackups over LibreNMS' own
 * App\ApiClients\Oxidized; a fake in the tests). Devices are matched to Oxidized nodes exactly as LibreNMS does on a
 * device's config page.
 */
interface ConfigBackups
{
    /**
     * Does LibreNMS use Oxidized (oxidized.enabled, with a URL)?
     */
    public function enabled(): bool;

    /**
     * The device's Oxidized node: its name, full name (group/name) and last attempt ("last": start, end, status; null
     * while a backup runs); ['excluded' => true] when LibreNMS leaves the device out of Oxidized; null when Oxidized has
     * no node for it.
     *
     * @return array{name?: string, full_name?: string, last?: ?array, excluded?: bool}|null
     *
     * @throws BackupError when Oxidized cannot be asked
     */
    public function node(int $deviceId): ?array;

    /**
     * Ask Oxidized to back the node up now (PUT node/next); false when it refused or cannot be reached.
     */
    public function request(string $nodeName, string $message, string $username): bool;

    /**
     * The node's newest version (its Git output): oid and time; null when it has none or cannot be asked.
     *
     * @return array{oid: string, time: ?int}|null
     */
    public function latestVersion(string $nodeFull): ?array;
}
