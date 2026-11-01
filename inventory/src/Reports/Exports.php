<?php

namespace Agoodley\LibrenmsInventory\Reports;

use Agoodley\LibrenmsInventory\Software\Status;
use Agoodley\LibrenmsInventory\Support\Clock;
use Agoodley\LibrenmsInventory\Support\Origin;

/**
 * The rows of the CSV exports (Support\Csv), a header first: the devices, the modules (serial numbers), the optics, the
 * models, the software versions, the software compliance of the devices and their upgrades. Pure PHP.
 */
final class Exports
{
    /** What can be exported. */
    public const KINDS = ['devices', 'modules', 'optics', 'models', 'versions', 'software', 'upgrades'];

    /**
     * @param  array<int, array>  $devices  InventoryRepository::devices()
     * @param  array<int, array{serials: int, optics: int}>  $counts
     * @param  array<string, string>  $osNames
     * @param  array<int, list<array>>  $chassis  device_id => its chassis (stack members)
     * @return list<array>
     */
    public static function devices(array $devices, array $counts, array $osNames, array $chassis): array
    {
        $rows = [['device', 'hostname', 'location', 'OS', 'model', 'software version', 'features', 'serial number', 'chassis (model serial number)',
            'serial numbers in the hardware inventory', 'optics', 'last discovered']];
        foreach ($devices as $id => $device) {
            $rows[] = [
                $device['name'], $device['hostname'], $device['location'] ?? '', $osNames[$device['os']] ?? $device['os'], $device['hardware'] ?? '',
                $device['version'] ?? '', $device['features'] ?? '', $device['serial'] ?? '',
                implode('; ', array_map(fn (array $unit) => trim($unit['model'] . ' ' . $unit['serial']), $chassis[$id] ?? [])),
                $counts[$id]['serials'] ?? 0, $counts[$id]['optics'] ?? 0, (string) ($device['last_discovered'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array>  $modules  InventoryRepository::modules() rows
     * @param  array<int, array{name: string, location: string}>  $names
     * @return list<array>
     */
    public static function modules(array $modules, array $names): array
    {
        $rows = [['device', 'location', 'class', 'name', 'description', 'model', 'serial number', 'hardware revision', 'firmware revision',
            'software revision', 'field replaceable', 'manufacturer']];
        foreach ($modules as $module) {
            $rows[] = [
                $names[$module['device_id']]['name'] ?? '#' . $module['device_id'], $names[$module['device_id']]['location'] ?? '', $module['class'],
                $module['name'], $module['description'], $module['model'], $module['serial'], $module['hardware_rev'], $module['firmware_rev'],
                $module['software_rev'], $module['fru'], $module['manufacturer'],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array>  $optics  InventoryRepository::optics() rows
     * @param  array<int, array{name: string, location: string}>  $names
     * @return list<array>
     */
    public static function optics(array $optics, array $names): array
    {
        $rows = [['device', 'location', 'port', 'port description', 'type', 'vendor', 'model', 'serial number', 'revision', 'wavelength (nm)',
            'distance (m)', 'connector', 'DDM', 'origin', 'manufacturing date']];
        foreach ($optics as $optic) {
            $rows[] = [
                $names[$optic['device_id']]['name'] ?? '#' . $optic['device_id'], $names[$optic['device_id']]['location'] ?? '', $optic['port'],
                $optic['port_alias'], $optic['type'], $optic['vendor'], $optic['model'], $optic['serial'], $optic['revision'], $optic['wavelength'],
                $optic['distance'], $optic['connector'], $optic['ddm'], self::origin($optic['origin']), $optic['date'],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array>  $models  Summaries::models()
     * @param  array<string, string>  $osNames
     * @return list<array>
     */
    public static function models(array $models, array $osNames): array
    {
        $rows = [['model', 'units', 'devices', 'OS', 'software versions (devices)']];
        foreach ($models as $model) {
            $rows[] = [
                $model['model'], $model['units'], count($model['devices']), implode('; ', array_map(fn ($os) => $osNames[$os] ?? $os, $model['os'])),
                implode('; ', array_map(fn ($version, $count) => "$version ($count)", array_keys($model['versions']), $model['versions'])),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array>  $versions  Summaries::versions()
     * @return list<array>
     */
    public static function versions(array $versions): array
    {
        $rows = [['OS', 'software version', 'devices', 'models (devices)']];
        foreach ($versions as $version) {
            $rows[] = [
                $version['os_name'], $version['version'], count($version['devices']),
                implode('; ', array_map(fn ($model, $count) => "$model ($count)", array_keys($version['models']), $version['models'])),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array>  $rows  the software page's rows: a device's row of inventory_software with its name, location
     * @param  array<string, string>  $osNames
     * @return list<array>
     */
    public static function software(array $rows, array $osNames): array
    {
        $out = [['device', 'location', 'OS', 'model', 'software version', 'status', 'policy', 'why', 'status since (UTC)', 'version since (UTC)']];
        foreach ($rows as $row) {
            $out[] = [$row['name'], $row['location'], $osNames[(string) $row['os']] ?? $row['os'], $row['model'], $row['version'], Status::label((string) $row['status']),
                $row['policy'] ?? '', $row['message'] ?? '', $row['status_since'], $row['version_since']];
        }

        return $out;
    }

    /**
     * @param  list<array>  $upgrades  SoftwareRepository::upgrades() rows
     * @param  array<int, array{name: string, location: string}>  $names
     * @param  array<string, string>  $osNames
     * @return list<array>
     */
    public static function upgrades(array $upgrades, array $names, array $osNames): array
    {
        $out = [['seen (UTC)', 'device', 'location', 'OS', 'model', 'from version', 'to version', 'change', 'from version since (UTC)', 'days on the from version']];
        foreach ($upgrades as $row) {
            $from = Clock::unix($row['from_since'] ?? null);
            $seen = Clock::unix($row['seen_at']);
            $out[] = [$row['seen_at'], $names[$row['device_id']]['name'] ?? '#' . $row['device_id'], $names[$row['device_id']]['location'] ?? '',
                $osNames[(string) $row['os']] ?? (string) $row['os'], (string) $row['model'], $row['from_version'], $row['to_version'], $row['direction'], (string) ($row['from_since'] ?? ''),
                $from !== null && $seen !== null ? (int) floor(($seen - $from) / 86400) : ''];
        }

        return $out;
    }

    /**
     * An origin as the pages and exports write it.
     */
    public static function origin(?string $origin): string
    {
        return match ($origin) {
            Origin::GENUINE => 'genuine',
            Origin::THIRD_PARTY => 'third-party',
            default => 'unknown',
        };
    }
}
