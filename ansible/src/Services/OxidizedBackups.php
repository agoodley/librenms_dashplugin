<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Changes\BackupError;
use Agoodley\LibrenmsAnsible\Changes\ConfigBackups;
use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use App\Models\Device;

/**
 * Oxidized backups through LibreNMS' own Oxidized client (App\ApiClients\Oxidized, with LibreNMS' oxidized.enabled and
 * oxidized.url), devices matched to nodes exactly as LibreNMS' config page does (App\ConfigBackup\Providers\
 * OxidizedProvider): a device LibreNMS leaves out of Oxidized (its own setting, oxidized.ignore_types,
 * oxidized.ignore_os) is excluded; a node is found by the device's hostname, short hostname, hostname with
 * LibreNMS' domain, then its address; a backup is asked for with PUT node/next/<node name>. Only the node's name, full
 * name and last attempt are kept, never its variables. The client's calls time out after a few seconds here (LibreNMS'
 * own page waits up to 90): the plugin asks again at the next refresh.
 */
final class OxidizedBackups implements ConfigBackups
{
    /** Seconds per call to Oxidized. */
    public const TIMEOUT = 10;

    private ?\App\ApiClients\Oxidized $client = null;

    public function enabled(): bool
    {
        try {
            return class_exists(\App\ApiClients\Oxidized::class) && \App\ApiClients\Oxidized::isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    public function node(int $deviceId): ?array
    {
        $device = Device::find($deviceId);
        if ($device === null) {
            return null;
        }
        if (! $this->backedUp($device)) {
            return ['excluded' => true];
        }
        $client = $this->client();
        try {
            $node = $client->findNode($device);
        } catch (\Throwable $e) {
            throw new BackupError('Oxidized cannot be asked: ' . UntrustedText::line($e->getMessage(), 160));
        }
        if ($node === null) {
            if ($client->lastError() !== null) {
                throw new BackupError('Oxidized cannot be reached (' . UntrustedText::line($client->lastError(), 40) . ')');
            }

            return null;
        }
        $last = is_array($node['last'] ?? null) ? $node['last'] : null;

        return ['name' => (string) $node['name'], 'full_name' => (string) (($node['full_name'] ?? '') ?: $node['name']),
            'last' => $last === null ? null : ['start' => is_string($last['start'] ?? null) ? $last['start'] : null, 'end' => is_string($last['end'] ?? null) ? $last['end'] : null,
                'status' => is_string($last['status'] ?? null) ? $last['status'] : null]];
    }

    public function request(string $nodeName, string $message, string $username): bool
    {
        try {
            return $this->client()->updateNode($nodeName, $message, $username);
        } catch (\Throwable) {
            return false;
        }
    }

    public function latestVersion(string $nodeFull): ?array
    {
        try {
            $versions = $this->client()->getVersions($nodeFull);
        } catch (\Throwable) {
            return null;
        }
        $newest = is_array($versions) && $versions ? $versions[0] : null;
        if (! is_array($newest) || ! is_string($newest['oid'] ?? null) || ! ctype_xdigit($newest['oid'])) {
            return null;
        }
        $time = is_string($newest['time'] ?? null) ? strtotime($newest['time']) : false;

        return ['oid' => substr($newest['oid'], 0, 64), 'time' => $time === false ? null : $time];
    }

    /**
     * Does LibreNMS back the device up with Oxidized (OxidizedProvider::supportsDevice())?
     */
    private function backedUp(Device $device): bool
    {
        $config = fn (string $key) => class_exists(\App\Facades\LibrenmsConfig::class) ? (\App\Facades\LibrenmsConfig::get($key, []) ?: []) : [];

        return $device->getAttrib('override_Oxidized_disable') !== 'true'
            && ! in_array($device->type, (array) $config('oxidized.ignore_types'), true)
            && ! in_array($device->os, (array) $config('oxidized.ignore_os'), true);
    }

    /**
     * LibreNMS' client, with a short timeout per call.
     */
    private function client(): \App\ApiClients\Oxidized
    {
        return $this->client ??= new class extends \App\ApiClients\Oxidized {
            public function __construct()
            {
                parent::__construct();
                $this->timeout = OxidizedBackups::TIMEOUT;
            }
        };
    }
}
