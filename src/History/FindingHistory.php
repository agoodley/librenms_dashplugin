<?php

namespace Agoodley\LibrenmsTopology\History;

use Agoodley\LibrenmsTopology\Stp\Finding;

/**
 * Findings history of the background checks. A finding is the same finding while its code,
 * devices, ports and VLAN stay the same; its wording and severity may change. A finding that goes
 * away is resolved, and if it comes back later it starts a new period.
 */
class FindingHistory
{
    /** Eventlog severity ranks: a finding is written when it is at least as severe as the setting. */
    private const RANKS = [Finding::INFO => 1, Finding::WARNING => 2, Finding::CRITICAL => 3];

    /** Devices whose eventlog gets the entry of one finding (the first ones of the finding). */
    public const EVENTLOG_DEVICES = 10;

    /**
     * Identity of a finding of an analysis.
     */
    public static function hash(string $analysis, array $finding): string
    {
        $devices = array_map('intval', $finding['devices'] ?? []);
        $ports = array_map('intval', $finding['ports'] ?? []);
        sort($devices);
        sort($ports);

        return sha1(implode('|', [
            $analysis,
            $finding['code'],
            $finding['vlan'] ?? '',
            implode(',', array_unique($devices)),
            implode(',', array_unique($ports)),
        ]));
    }

    /**
     * Compare the open findings of an analysis with the findings of this run.
     *
     * @param  array<int, array{id: int, hash: string, severity: string, title: string}>  $open  findings reported by the
     *                                                                                          previous run, oldest first
     * @param  array<int, array>  $findings  findings of this run
     * @return array{new: array<string, array>, resolved: array<int, array>, update: array<int, array>} new findings by hash,
     *                                                                                                  resolved rows, id => changed finding
     */
    public static function diff(string $analysis, array $open, array $findings): array
    {
        $current = [];
        foreach ($findings as $finding) {
            $current[self::hash($analysis, $finding)] ??= $finding;
        }

        $new = $current;
        $resolved = [];
        $update = [];
        $kept = [];
        foreach ($open as $row) {
            $hash = $row['hash'];
            if (! isset($current[$hash]) || isset($kept[$hash])) {
                $resolved[] = $row;
                continue;
            }
            $kept[$hash] = true;
            unset($new[$hash]);
            if ($row['severity'] !== $current[$hash]['severity'] || $row['title'] !== self::title($current[$hash])) {
                $update[(int) $row['id']] = $current[$hash];
            }
        }

        return ['new' => $new, 'resolved' => $resolved, 'update' => $update];
    }

    /**
     * Eventlog entries for new and resolved findings at or above $minimum severity ('none' writes
     * nothing): one entry per device of the finding, at most EVENTLOG_DEVICES. A finding that only
     * changed its extent (a port or device more or less, so a new identity with the same code and
     * title) goes on: the devices that have it before and after get no entry, like a finding whose
     * identity did not change.
     *
     * @param  array<int, array>  $new  new findings
     * @param  array<int, array>  $resolved  resolved rows (code, severity, title, devices as int[])
     * @return array<int, array{device_id: int, message: string, severity: string, reference: string}> severity: critical,
     *                                                                                                  warning, info or ok
     */
    public static function eventlog(string $analysis, array $new, array $resolved, string $minimum): array
    {
        $rank = self::RANKS[$minimum] ?? null;
        if ($rank === null) {
            return [];
        }

        $after = [];
        foreach ($new as $finding) {
            foreach ($finding['devices'] ?? [] as $device) {
                $after[self::onDevice($device, $finding['code'], self::title($finding))] = true;
            }
        }
        $before = [];
        foreach ($resolved as $row) {
            foreach ($row['devices'] ?? [] as $device) {
                $before[self::onDevice($device, $row['code'], $row['title'])] = true;
            }
        }

        $entries = [];
        foreach ($new as $finding) {
            if ((self::RANKS[$finding['severity']] ?? 0) < $rank) {
                continue;
            }
            $title = self::title($finding);
            $devices = array_filter($finding['devices'] ?? [], fn ($device) => ! isset($before[self::onDevice($device, $finding['code'], $title)]));
            $message = 'Topology ' . $finding['severity'] . ': ' . $title . '. ' . trim((string) ($finding['detail'] ?? ''));
            foreach (self::eventlogDevices($devices, $message) as [$device, $text]) {
                $entries[] = ['device_id' => $device, 'message' => $text, 'severity' => $finding['severity'], 'reference' => self::reference($analysis, $finding['code'])];
            }
        }
        foreach ($resolved as $row) {
            if ((self::RANKS[$row['severity']] ?? 0) < $rank) {
                continue;
            }
            $devices = array_filter($row['devices'] ?? [], fn ($device) => ! isset($after[self::onDevice($device, $row['code'], $row['title'])]));
            $message = 'Topology resolved: ' . $row['title'];
            foreach (self::eventlogDevices($devices, $message) as [$device, $text]) {
                $entries[] = ['device_id' => $device, 'message' => $text, 'severity' => 'ok', 'reference' => self::reference($analysis, $row['code'])];
            }
        }

        return $entries;
    }

    /**
     * Findings that came or went in a window, each with its status: new, resolved, or changed for a
     * finding resolved and reported again with another extent by the same run (same code and title).
     *
     * @param  array<int, array>  $rows  rows of the findings history (analysis, code, title, first_seen, resolved_at)
     * @return array<int, array> the rows with `status`
     */
    public static function window(array $rows, int $from, int $to): array
    {
        $started = []; // analysis|code|title|time => rows of findings reported from that run on
        foreach ($rows as $i => $row) {
            $resolved = $row['resolved_at'] !== null ? strtotime($row['resolved_at']) : null;
            $rows[$i]['status'] = $resolved !== null && $resolved >= $from && $resolved <= $to ? 'resolved' : 'new';
            if ($rows[$i]['status'] === 'new') {
                $started[$row['analysis'] . '|' . $row['code'] . '|' . $row['title'] . '|' . $row['first_seen']][] = $i;
            }
        }
        foreach ($rows as $i => $row) {
            $key = $row['analysis'] . '|' . $row['code'] . '|' . $row['title'] . '|' . $row['resolved_at'];
            if ($row['status'] === 'resolved' && ! empty($started[$key])) {
                $rows[array_shift($started[$key])]['status'] = 'changed';
                unset($rows[$i]);
            }
        }

        return array_values($rows);
    }

    /**
     * Title as stored and logged ("Single point of failure: dist1").
     */
    public static function title(array $finding): string
    {
        return mb_substr(ucfirst((string) $finding['title']), 0, 512);
    }

    /**
     * The devices that get the entry, and its text (with a count of the devices left out).
     *
     * @param  int[]  $devices
     * @return array<int, array{0: int, 1: string}>
     */
    private static function eventlogDevices(array $devices, string $message): array
    {
        $devices = array_values(array_unique(array_map('intval', $devices)));
        $more = count($devices) - self::EVENTLOG_DEVICES;
        $text = mb_substr($message, 0, 900) . ($more > 0 ? " (also on $more more devices)" : '');

        return array_map(fn ($device) => [$device, $text], array_slice($devices, 0, self::EVENTLOG_DEVICES));
    }

    private static function onDevice(int|string $device, string $code, string $title): string
    {
        return (int) $device . '|' . $code . '|' . $title;
    }

    private static function reference(string $analysis, string $code): string
    {
        return mb_substr("topology:$analysis:$code", 0, 64);
    }
}
