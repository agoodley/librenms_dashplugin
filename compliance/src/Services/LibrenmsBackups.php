<?php

namespace Agoodley\LibrenmsCompliance\Services;

use Agoodley\LibrenmsCompliance\Support\ErrorText;
use Agoodley\LibrenmsCompliance\Support\Log;

/**
 * The backups LibreNMS shows on a device's Config tab: the provider its ConfigBackupManager chooses for the device
 * (Unimus, Oxidized or RANCID, as LibreNMS' settings configure them, the first that backs the device up), and that
 * provider's latest backup. Oxidized is asked with a shorter timeout than LibreNMS' own page uses (90 seconds), and a
 * provider that cannot be reached is not asked again in the same run. On a LibreNMS without the manager, Oxidized is
 * asked directly through LibreNMS' client (its node as LibreNMS finds it, then that node's configuration).
 */
final class LibrenmsBackups implements Backups
{
    /** Seconds Oxidized has to answer each request. */
    public const TIMEOUT = 20;

    private const MANAGER = 'App\\ConfigBackup\\ConfigBackupManager';

    private const OXIDIZED_PROVIDER = 'App\\ConfigBackup\\Providers\\OxidizedProvider';

    private const OXIDIZED = 'App\\ApiClients\\Oxidized';

    /** What LibreNMS' providers say when they have no backup (LibreNMS\Interfaces\ConfigBackupProvider::ERROR_*). */
    private const ERRORS = [
        'unreachable' => 'cannot be reached',
        'error' => 'answered with an error',
        'device_not_found' => 'has no node for this device',
        'no_backups' => 'has no backup of this device yet',
        'backup_not_found' => 'has no backup of this device yet',
    ];

    /** @var array<string, string> providers that could not be reached in this run: name => why */
    private array $unreachable = [];

    private ?object $client = null;

    public function unavailable(): ?string
    {
        if (class_exists(self::MANAGER) || (class_exists(self::OXIDIZED) && method_exists(self::OXIDIZED, 'findNode') && method_exists(self::OXIDIZED, 'getNodeConfig'))) {
            return null;
        }

        return 'This LibreNMS has no configuration backup interface the plugin can read (its Config tab\'s backup providers): update LibreNMS.';
    }

    public function latest(int $deviceId): Backup
    {
        try {
            $device = \App\Models\Device::find($deviceId);
            if ($device === null) {
                return Backup::failed(null, 'LibreNMS no longer has this device.');
            }

            return class_exists(self::MANAGER) ? $this->fromManager($device) : $this->fromOxidized($device);
        } catch (\Throwable $e) {
            Log::error('reading the backup of device ' . $deviceId . ' failed: ' . ErrorText::forLog($e));

            return Backup::failed(null, 'Reading the backup failed: ' . ErrorText::line($e->getMessage(), 200));
        }
    }

    private function fromManager(\App\Models\Device $device): Backup
    {
        $provider = app(self::MANAGER)->providerFor($device);
        if ($provider === null) {
            return Backup::missing(null, 'No configuration backup tool of LibreNMS backs this device up (Oxidized, RANCID or Unimus, in LibreNMS\' settings).');
        }
        if (is_a($provider, self::OXIDIZED_PROVIDER)) {
            // the same provider, with LibreNMS' Oxidized client on a shorter timeout (a parameter LibreNMS no longer has is ignored)
            $provider = app()->make(self::OXIDIZED_PROVIDER, ['api' => $this->oxidized()]);
        }
        $name = (string) $provider->name();
        if (isset($this->unreachable[$name])) {
            return Backup::failed($name, $this->unreachable[$name], true);
        }
        $latest = $provider->latest($device);
        if (! is_array($latest) || ! is_string($latest['content'] ?? null)) {
            $error = (string) ($provider->lastError() ?? 'no_backups');

            return $this->failure($name, $error);
        }
        $date = $latest['date'] ?? null;

        return self::backup($name, $latest['content'], is_int($date) ? $date : (is_numeric($date) ? (int) $date : null));
    }

    private function fromOxidized(\App\Models\Device $device): Backup
    {
        $class = self::OXIDIZED;
        if (! $class::isConfigured()) {
            return Backup::missing(null, 'LibreNMS does not use Oxidized (its oxidized.enabled and oxidized.url settings).');
        }
        if (isset($this->unreachable['Oxidized'])) {
            return Backup::failed('Oxidized', $this->unreachable['Oxidized'], true);
        }
        $client = $this->oxidized();
        $node = $client->findNode($device);
        if (! is_array($node)) {
            return $this->failure('Oxidized', $client->lastError() ?? 'device_not_found');
        }
        $content = $client->getNodeConfig((string) $node['name'], isset($node['group']) && is_string($node['group']) && $node['group'] !== '' ? $node['group'] : null);
        if (! is_string($content)) {
            return $this->failure('Oxidized', $client->lastError() ?? 'no_backups');
        }
        $mtime = is_string($node['mtime'] ?? null) ? strtotime($node['mtime']) : false;

        return self::backup('Oxidized', $content, $mtime === false ? null : $mtime);
    }

    /**
     * A backup's text, unless it is none: Oxidized answers a node it never backed up with "node not found" (HTTP 200),
     * which LibreNMS passes on as the configuration.
     */
    private static function backup(string $provider, string $content, ?int $date): Backup
    {
        if (trim($content) === '' || preg_match('~^\s*node not found\s*$~i', $content) === 1) {
            return Backup::missing($provider, $provider . ' has no backup of this device yet.');
        }

        return Backup::found($provider, $content, $date);
    }

    private function failure(string $provider, string $error): Backup
    {
        $why = $provider . ' ' . (self::ERRORS[$error] ?? 'answered: ' . ErrorText::line($error, 120)) . '.';
        if ($error === 'unreachable') {
            $this->unreachable[$provider] = $why;

            return Backup::failed($provider, $why, true);
        }

        return in_array($error, ['device_not_found', 'no_backups', 'backup_not_found'], true) ? Backup::missing($provider, $why) : Backup::failed($provider, $why);
    }

    /**
     * LibreNMS' Oxidized client with the plugin's timeout.
     */
    private function oxidized(): object
    {
        return $this->client ??= new class extends \App\ApiClients\Oxidized {
            public function __construct()
            {
                parent::__construct();
                $this->timeout = LibrenmsBackups::TIMEOUT;
            }
        };
    }
}
