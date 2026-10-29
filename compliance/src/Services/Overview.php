<?php

namespace Agoodley\LibrenmsCompliance\Services;

use Agoodley\LibrenmsCompliance\Data\ResultRepository;
use Agoodley\LibrenmsCompliance\Rules\Rule;

/**
 * What the overview shows of the results: how many devices are compliant, have findings (of which severity), have no
 * backup, could not be checked, or have no rule that applies to them; and each rule's outcomes over the devices.
 * Devices whose last check failed count with their last results, as their findings stay.
 */
final class Overview
{
    /**
     * @param  array<int, array>  $devices  ResultRepository::summaries()
     * @param  array<int, array<string, int>>  $counts  ResultRepository::counts()
     * @return array{devices: int, checked: int, compliant: int, with_findings: int, critical: int, warning: int, info: int, no_backup: int, errors: int,
     *               not_applicable: int}
     */
    public static function cards(array $devices, array $counts): array
    {
        $cards = ['devices' => count($devices), 'checked' => 0, 'compliant' => 0, 'with_findings' => 0, 'critical' => 0, 'warning' => 0, 'info' => 0, 'no_backup' => 0,
            'errors' => 0, 'not_applicable' => 0];
        foreach ($devices as $id => $device) {
            $cards[match ($device['status']) {
                ResultRepository::CHECKED => 'checked',
                Backup::NO_BACKUP => 'no_backup',
                default => 'errors',
            }]++;
            $mine = $counts[$id] ?? [];
            if ($mine) {
                $cards['with_findings']++;
                foreach ($mine as $severity => $count) {
                    if (isset($cards[$severity]) && in_array($severity, Rule::SEVERITIES, true)) {
                        $cards[$severity] += $count;
                    }
                }
            } elseif ($device['status'] === ResultRepository::CHECKED) {
                $cards[self::applied($device) ? 'compliant' : 'not_applicable']++;
            }
        }

        return $cards;
    }

    /**
     * Each rule of the settings, the worst first, with how many devices it failed, passed, did not apply to, or failed
     * to run on (in their last check).
     *
     * @param  array<int, array>  $devices  ResultRepository::summaries()
     * @param  array<string, Rule>  $rules
     * @return array<string, array{rule: Rule, fail: int, pass: int, na: int, error: int}>
     */
    public static function rules(array $devices, array $rules): array
    {
        $stats = [];
        foreach ($rules as $id => $rule) {
            $stats[$id] = ['rule' => $rule, Rule::FAIL => 0, Rule::PASS => 0, Rule::NA => 0, Rule::ERROR => 0];
        }
        foreach ($devices as $device) {
            foreach ($device['outcomes'] as $id => $outcome) {
                $status = is_array($outcome) ? (string) ($outcome[0] ?? '') : '';
                if (in_array($status, Rule::STATUSES, true) && isset($stats[$id])) {
                    $stats[$id][$status]++;
                }
            }
        }
        // the rules that fail somewhere first, the worst of them first, then by how many devices they fail
        $key = fn (array $stat) => [$stat[Rule::FAIL] > 0 ? 0 : 1, Rule::rank($stat['rule']->severity), -$stat[Rule::FAIL]];
        uasort($stats, fn (array $a, array $b) => $key($a) <=> $key($b));

        return $stats;
    }

    /**
     * Did a rule apply to the device in its last check (passed or failed)?
     */
    public static function applied(array $device): bool
    {
        foreach ($device['outcomes'] as $outcome) {
            if (is_array($outcome) && in_array($outcome[0] ?? null, [Rule::PASS, Rule::FAIL], true)) {
                return true;
            }
        }

        return false;
    }

    /** The device list's filters of status. */
    public const STATES = [
        '' => 'every device',
        'findings' => 'with findings',
        'critical' => 'with critical findings',
        'compliant' => 'compliant',
        'not_applicable' => 'no rule applies',
        'no_backup' => 'no backup',
        'error' => 'not checked (error)',
    ];

    /**
     * The device list: each device LibreNMS still has, with its state and findings, of a state and whose name or
     * hostname has the text; the worst first (most critical, then warning, then info findings; then those not checked,
     * without a backup), then by name.
     *
     * @param  array<int, array>  $devices  ResultRepository::summaries()
     * @param  array<int, array<string, int>>  $counts  ResultRepository::counts()
     * @param  array<int, array{name: string, hostname: string}>  $names  Data\LibrenmsNetwork::names()
     * @return array<int, array{device_id: int, name: string, hostname: string, state: string, counts: array<string, int>, row: array}>
     */
    public static function devices(array $devices, array $counts, array $names, string $state = '', string $text = ''): array
    {
        $rows = [];
        foreach ($devices as $id => $device) {
            if (! isset($names[$id])) {
                continue;
            }
            $mine = $counts[$id] ?? [];
            $row = ['device_id' => (int) $id, 'name' => $names[$id]['name'], 'hostname' => $names[$id]['hostname'], 'state' => self::state($device, $mine),
                'counts' => $mine, 'row' => $device];
            $match = match ($state) {
                '' => true,
                'findings' => $mine !== [],
                'critical' => ($mine[Rule::CRITICAL] ?? 0) > 0,
                'no_backup' => $device['status'] === Backup::NO_BACKUP,
                'error' => $device['status'] === Backup::ERROR,
                default => $row['state'] === $state,
            };
            if ($match && ($text === '' || mb_stripos($row['name'], $text) !== false || mb_stripos($row['hostname'], $text) !== false)) {
                $rows[] = $row;
            }
        }
        $order = [Backup::ERROR => 0, Backup::NO_BACKUP => 1];
        $key = fn (array $row) => [-($row['counts'][Rule::CRITICAL] ?? 0), -($row['counts'][Rule::WARNING] ?? 0), -($row['counts'][Rule::INFO] ?? 0),
            $order[$row['row']['status']] ?? 2, mb_strtolower($row['name']), $row['device_id']];
        usort($rows, fn (array $a, array $b) => $key($a) <=> $key($b));

        return $rows;
    }

    /**
     * A device's state: findings, compliant (a rule applied, none failed), not_applicable (no rule applied), or its
     * check's failure (no_backup, error) when it has no findings from an earlier check.
     *
     * @param  array<string, int>  $counts
     */
    public static function state(array $device, array $counts): string
    {
        if ($counts) {
            return 'findings';
        }
        if ($device['status'] !== ResultRepository::CHECKED) {
            return $device['status'] === Backup::NO_BACKUP ? 'no_backup' : 'error';
        }

        return self::applied($device) ? 'compliant' : 'not_applicable';
    }
}
