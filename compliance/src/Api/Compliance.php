<?php

namespace Agoodley\LibrenmsCompliance\Api;

use Agoodley\LibrenmsCompliance\ComplianceServiceProvider;
use Agoodley\LibrenmsCompliance\Data\ResultRepository;
use Agoodley\LibrenmsCompliance\Support\Access;

/**
 * The compliance API, for other LibreNMS plugins (the topology plugin's spanning tree page confirms with it which ports
 * have no portfast): what the last checked backups of devices configure on their switch ports, and their findings, as
 * data. Never a configuration's text: only the facts of each port and the findings (their evidence lines have their
 * secrets hidden).
 *
 * Scoped as the plugin's pages: forUser() sees the devices that LibreNMS user may see, and nothing for a user who may
 * not read configuration backups; system() sees every device and is for console commands only (anywhere else:
 * LogicException). Both throw \RuntimeException while the plugin is disabled or its tables are missing. VERSION is
 * major.minor: the major number changes only when a method or a field changes incompatibly; a caller checks that the
 * class exists and its major number.
 */
final class Compliance
{
    /** Version of this API ("major.minor"). */
    public const VERSION = '1.0';

    /** Most devices one call takes (more: \InvalidArgumentException). */
    public const MAX_DEVICES = 5000;

    /**
     * @param  int[]|null  $scope  the devices it may see (null: every device)
     */
    private function __construct(private readonly ?array $scope, private readonly ResultRepository $results)
    {
    }

    /**
     * The API as a LibreNMS user sees it.
     *
     * @throws \RuntimeException the plugin is disabled, or its tables are missing
     */
    public static function forUser(mixed $user): self
    {
        $results = self::results();

        return new self(Access::canView($user) ? Access::deviceIds($user) : [], $results);
    }

    /**
     * The API for a console command: every device.
     *
     * @throws \LogicException outside the console
     * @throws \RuntimeException the plugin is disabled, or its tables are missing
     */
    public static function system(): self
    {
        if (! app()->runningInConsole()) {
            throw new \LogicException('Compliance::system() is for console commands only.');
        }

        return new self(null, self::results());
    }

    /**
     * The switch ports of the devices' last checked backups, by device id, then by LibreNMS port id where the
     * configuration's interface is a LibreNMS port: name (as the configuration names it), mode (access, trunk,
     * unknown), shutdown, member (its port-channel's number, or null), portfast (on, default: by the global default,
     * auto: EOS' automatic edge detection, off), bpduguard (on, default, off), rootguard, role (what LibreNMS says it
     * faces: host, switch, upstream: its root port, downstream: a switch whose root port faces it); with when the
     * backup was taken and checked. A device the user may not see, or never checked, is left out.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array{backup_at: ?string, checked_at: string, ports: array<int, array>, unmatched: array<int, array>}>
     */
    public function ports(array $deviceIds): array
    {
        $devices = [];
        foreach ($this->results->devices($this->visible($deviceIds)) as $id => $row) {
            $byPort = [];
            $unmatched = [];
            foreach ((array) $row['ports'] as $port) {
                $facts = ['name' => (string) $port['name'], 'mode' => (string) $port['mode'], 'shutdown' => (bool) $port['shutdown'],
                    'member' => $port['member'] !== null ? (string) $port['member'] : null, 'portfast' => (string) $port['portfast'],
                    'bpduguard' => (string) $port['bpduguard'], 'rootguard' => (bool) $port['rootguard'], 'role' => (string) $port['role']];
                if (isset($port['port_id']) && $port['port_id'] !== null) {
                    $byPort[(int) $port['port_id']] = $facts;
                } else {
                    $unmatched[] = $facts;
                }
            }
            $devices[$id] = ['backup_at' => $row['backup_at'] === null ? null : str_replace(' ', 'T', (string) $row['backup_at']) . 'Z',
                'checked_at' => str_replace(' ', 'T', (string) $row['checked_at']) . 'Z', 'ports' => $byPort, 'unmatched' => $unmatched];
        }

        return $devices;
    }

    /**
     * The findings of the devices, by device id then rule id: severity, message, the ports it names, its evidence
     * ([line number, line with secrets hidden]), since when (UTC, ISO 8601).
     *
     * @param  int[]  $deviceIds
     * @return array<int, array<string, array{severity: string, message: string, ports: string[], evidence: array, first_seen: string}>>
     */
    public function findings(array $deviceIds): array
    {
        $findings = [];
        foreach ($this->visible($deviceIds) as $id) {
            foreach ($this->results->findings($id) as $rule => $row) {
                $findings[$id][$rule] = ['severity' => (string) $row['severity'], 'message' => (string) $row['message'], 'ports' => (array) $row['ports'],
                    'evidence' => (array) $row['evidence'], 'first_seen' => str_replace(' ', 'T', (string) $row['first_seen']) . 'Z'];
            }
        }

        return $findings;
    }

    /**
     * The devices of the call the API may see.
     *
     * @param  int[]  $deviceIds
     * @return int[]
     */
    private function visible(array $deviceIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $deviceIds)));
        if (count($ids) > self::MAX_DEVICES) {
            throw new \InvalidArgumentException('At most ' . self::MAX_DEVICES . ' devices per call.');
        }

        return $this->scope === null ? $ids : array_values(array_intersect($ids, $this->scope));
    }

    private static function results(): ResultRepository
    {
        $enabled = false;
        try {
            $enabled = app(\LibreNMS\Interfaces\Plugins\PluginManagerInterface::class)->pluginEnabled(ComplianceServiceProvider::PLUGIN_NAME);
        } catch (\Throwable) {
            // no plugin manager
        }
        $results = new ResultRepository();
        if (! $enabled || ! $results->available()) {
            throw new \RuntimeException('The compliance plugin is disabled in LibreNMS, or its tables are missing (./lnms migrate).');
        }

        return $results;
    }
}
