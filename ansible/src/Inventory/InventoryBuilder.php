<?php

namespace Agoodley\LibrenmsAnsible\Inventory;

/**
 * Builds the Ansible inventory from LibreNMS devices, as plain arrays (no LibreNMS dependency). Hosts are the
 * devices that are enabled, in the allowed device group when one is set, have a platform mapping for their os
 * and a usable address. Their variables are the connection (ansible_host, ansible_network_os,
 * ansible_connection, become) and a few librenms_* facts; groups are made by LibreNMS os, platform, device
 * group, location and device type. Every value that comes from the network is cleaned (UntrustedText).
 *
 * Names are computed over every device and group LibreNMS has, so a device and a group keep their names
 * whichever devices the inventory user may see: the names the plugin puts in a run's limit are the ones the
 * runner gets.
 */
final class InventoryBuilder
{
    /** Kinds of groups and the prefix of their names. */
    public const GROUP_KINDS = ['os' => 'os', 'platform' => 'platform', 'group' => 'group', 'location' => 'location', 'type' => 'type'];

    /**
     * @param  int|null  $allowedGroup  LibreNMS device group whose members alone may be hosts (null: every device)
     */
    public function __construct(
        private readonly PlatformMap $map,
        private readonly ?int $allowedGroup = null,
    ) {
    }

    /**
     * The inventory of the devices in $visible (every device when null).
     *
     * @param  array<int, array>  $devices  device_id => row of every LibreNMS device: hostname, sysName, os, type, hardware,
     *                                      version, location_id, overwrite_ip, disabled
     * @param  array<int, int[]>  $memberships  device_id => ids of its device groups
     * @param  array<int, string>  $deviceGroups  device group id => name, every group
     * @param  array<int, string>  $locations  location id => name, every location
     * @param  array<int, true>|null  $visible  device ids the inventory user may see
     */
    public function build(array $devices, array $memberships, array $deviceGroups, array $locations, ?array $visible = null): Inventory
    {
        ksort($devices);
        [$groupNames, $groupInfo] = $this->groupNames($devices, $deviceGroups, $locations);
        $names = HostNames::assign(array_map(fn ($d) => (string) ($d['hostname'] ?? ''), $devices), array_merge([], ...array_values(array_map('array_values', $groupNames))));

        $hosts = [];
        $hostNames = [];
        $members = [];
        $leftOut = [];
        foreach ($devices as $deviceId => $device) {
            if ($visible !== null && ! isset($visible[$deviceId])) {
                continue;
            }
            $platform = $this->map->for((string) ($device['os'] ?? ''));
            $reason = $this->leftOutReason($device, $memberships[$deviceId] ?? [], $platform, $deviceGroups);
            if ($reason !== null) {
                $leftOut[$deviceId] = $reason;
                continue;
            }
            $name = $names[$deviceId];
            $hosts[$name] = $this->hostVars((int) $deviceId, $device, $platform, $locations);
            $hostNames[$deviceId] = $name;
            foreach ($this->groupsOf($device, $memberships[$deviceId] ?? [], $platform, $groupNames) as $group) {
                $members[$group][] = $name;
            }
        }
        ksort($members);
        foreach ($members as &$list) {
            sort($list);
        }
        unset($list);

        return new Inventory($hosts, $hostNames, $members, array_intersect_key($groupInfo, $members), $leftOut);
    }

    /**
     * Why a device is not a host, null when it is one.
     *
     * @param  int[]  $groupIds
     * @param  array{network_os: string, connection: string, become: bool}|null  $platform
     * @return array{reason: string, text: string}|null
     */
    private function leftOutReason(array $device, array $groupIds, ?array $platform, array $deviceGroups): ?array
    {
        if (! empty($device['disabled'])) {
            return ['reason' => 'disabled', 'text' => 'disabled in LibreNMS'];
        }
        if ($this->allowedGroup !== null && ! in_array($this->allowedGroup, array_map('intval', $groupIds), true)) {
            $group = isset($deviceGroups[$this->allowedGroup]) ? UntrustedText::line($deviceGroups[$this->allowedGroup], 80) : "#{$this->allowedGroup} (it no longer exists)";

            return ['reason' => 'outside_group', 'text' => "not in the device group $group, the only one the plugin may change (Allowed device group setting)"];
        }
        if ($platform === null) {
            $os = UntrustedText::clean($device['os'] ?? '', 32) ?: 'unknown';

            return ['reason' => 'no_mapping', 'text' => "no platform mapping for its os $os (add a line for it to the Platform mapping setting)"];
        }
        if (self::address($device) === null) {
            return ['reason' => 'no_address', 'text' => 'its address (overwrite IP, else hostname) has characters Ansible cannot connect to: only letters, digits, ".", "_", ":" and "-", not starting with "-"'];
        }

        return null;
    }

    /**
     * The address Ansible connects to: the device's overwrite IP when set, else its hostname; null when it
     * could be read as something else (an option starting with "-", spaces, a scope "%eth0", ...).
     */
    public static function address(array $device): ?string
    {
        $address = trim((string) ($device['overwrite_ip'] ?? ''));
        if ($address === '') {
            $address = trim((string) ($device['hostname'] ?? ''));
        }

        return strlen($address) <= 253 && preg_match('/^[A-Za-z0-9_:][A-Za-z0-9._:-]*$/D', $address) ? $address : null;
    }

    /**
     * Variables of one host: the connection, then the librenms_* facts (cleaned: they come from the network).
     *
     * @param  array{network_os: string, connection: string, become: bool}  $platform
     * @return array<string, mixed>
     */
    private function hostVars(int $deviceId, array $device, array $platform, array $locations): array
    {
        $location = isset($device['location_id'], $locations[(int) $device['location_id']]) ? $locations[(int) $device['location_id']] : '';

        return ['ansible_host' => self::address($device)] + PlatformMap::hostVars($platform) + [
            'librenms_device_id' => $deviceId,
            'librenms_hostname' => UntrustedText::clean($device['hostname'] ?? '', 253),
            'librenms_os' => UntrustedText::clean($device['os'] ?? '', 32),
            'librenms_sysname' => UntrustedText::clean($device['sysName'] ?? ''),
            'librenms_location' => UntrustedText::clean($location),
            'librenms_hardware' => UntrustedText::clean($device['hardware'] ?? ''),
            'librenms_version' => UntrustedText::clean($device['version'] ?? ''),
            'librenms_type' => UntrustedText::clean($device['type'] ?? '', 20),
        ];
    }

    /**
     * Names of every group LibreNMS could give a host, by kind and id, with what each stands for.
     *
     * @return array{0: array<string, array<int|string, string>>, 1: array<string, array{kind: string, label: string}>}
     */
    private function groupNames(array $devices, array $deviceGroups, array $locations): array
    {
        $values = fn (string $column) => array_values(array_unique(array_filter(array_map(fn ($d) => strtolower(trim((string) ($d[$column] ?? ''))), $devices), fn ($v) => $v !== '')));
        $os = $values('os');
        $types = $values('type');
        $platforms = array_values(array_unique(array_column($this->map->all(), 'network_os')));
        $labels = [
            'os' => array_combine($os, $os),
            'platform' => array_combine($platforms, $platforms),
            'group' => $deviceGroups,
            'location' => $locations,
            'type' => array_combine($types, $types),
        ];

        $taken = [];
        $names = [];
        $info = [];
        foreach (self::GROUP_KINDS as $kind => $prefix) {
            $names[$kind] = GroupNames::assign($prefix, $labels[$kind], $taken);
            foreach ($names[$kind] as $id => $name) {
                $info[$name] = ['kind' => $kind, 'label' => UntrustedText::line($labels[$kind][$id], 120)];
            }
        }

        return [$names, $info];
    }

    /**
     * The groups of one host.
     *
     * @param  int[]  $groupIds
     * @param  array{network_os: string, connection: string, become: bool}  $platform
     * @param  array<string, array<int|string, string>>  $groupNames
     * @return string[]
     */
    private function groupsOf(array $device, array $groupIds, array $platform, array $groupNames): array
    {
        $groups = [
            $groupNames['os'][strtolower(trim((string) ($device['os'] ?? '')))] ?? null,
            $groupNames['platform'][$platform['network_os']] ?? null,
            isset($device['location_id']) ? $groupNames['location'][(int) $device['location_id']] ?? null : null,
            $groupNames['type'][strtolower(trim((string) ($device['type'] ?? '')))] ?? null,
        ];
        foreach ($groupIds as $groupId) {
            $groups[] = $groupNames['group'][(int) $groupId] ?? null;
        }

        return array_values(array_unique(array_filter($groups)));
    }
}
