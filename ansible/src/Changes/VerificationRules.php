<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Support\Format;

/**
 * What the comparison of a batch's states (before it, and after it once LibreNMS' data is fresh) means for the change:
 * the changes nobody asked for, those the change was made for (a fix), and the rest. Pure: it reads the topology
 * plugin's comparison (its verification API's compare(), in the Changes page's words) and the two states.
 *
 * Unexpected: a link gone, a cable moved, a new unmanaged switch, a port-channel member lost, a root bridge moved or a
 * spanning tree instance gone, a port that started blocking, an OSPF adjacency or BGP session lost, a device that went
 * down, and a finding that is new or worse from the "Count findings from" severity on (findings of the checks the
 * topology plugin keeps out of the eventlog never count: listed only). For a fix: its findings still there, and a root
 * bridge other than the intended one; what the fix does (its findings resolved, the root moving to the intended root and
 * the ports that start or stop blocking with it) is expected. Everything else is listed: links back or new, ports that
 * stopped blocking, adjacencies up, findings below the severity, milder or resolved.
 */
final class VerificationRules
{
    /** The comparison's events that are changes nobody asked for (ChangeReport's types). */
    public const UNEXPECTED_EVENTS = ['cable_moved', 'link_removed', 'unmanaged_new', 'lag_member_removed', 'root_changed', 'root_gone', 'blocking_started',
        'ospf_lost', 'bgp_lost'];

    /**
     * The spanning tree check's findings that a root bridge moving brings (LibreNMS' eventlog of the root and root port
     * changes, the topology changes): what a root priority fix does, while the root is the intended one.
     */
    public const ROOT_MOVE_FINDINGS = ['root_changed', 'root_port_changed', 'tc_recent'];

    /** The severities findings count from (the "Count findings from" setting), lowest first. */
    public const SEVERITIES = ['info' => 1, 'warning' => 2, 'critical' => 3];

    /** Most entries of each list kept with a batch (the counts are kept whole). */
    public const LIST_MAX = 200;

    /**
     * @param  array  $comparison  the verification API's compare() of the two states
     * @param  array  $before  the state before the batch
     * @param  array  $after  the state after it
     * @param  array|null  $expectation  what the batch is meant to do (FixExpectation::forBatch()), null for a change that is no fix
     * @param  string  $minimum  the lowest severity a new or worse finding counts from (SEVERITIES)
     * @return array{result: string, unexpected: list<array>, expected: list<array>, listed: list<array>, counts: array<string, int>, unchecked: ?string}
     *     result: "verified" or "unexpected"; each entry: what (event, finding, device, fix), type, level, text, devices; unchecked: the check a
     *     fix is verified by (links, stp) when it did not run in either state (not enabled, or failed): then nothing says the fix worked
     */
    public static function evaluate(array $comparison, array $before, array $after, ?array $expectation, string $minimum): array
    {
        $names = (array) ($comparison['names'] ?? []) + (array) ($after['names'] ?? []) + (array) ($before['names'] ?? []);
        $rank = self::SEVERITIES[$minimum] ?? self::SEVERITIES['warning'];
        $result = ['unexpected' => [], 'expected' => [], 'listed' => []];
        $resolves = (array) ($expectation['resolves'] ?? []);
        $roots = ($expectation['kind'] ?? null) === FixExpectation::STP_ROOT_PRIORITY ? array_values(array_filter((array) ($expectation['roots'] ?? []), 'is_array')) : [];
        $fixVlans = array_values(array_unique(array_merge([], ...array_map(fn (array $root) => array_map('intval', (array) ($root['vlans'] ?? [])), $roots))));

        // the events: a root bridge that moved to the fix's intended root, in its VLANs, is what the fix does; so are the ports that start
        // or stop blocking in those VLANs while the root is the intended one (the root moving, or the secondary's priority, changes
        // which ports block)
        $events = array_values(array_filter((array) ($comparison['events'] ?? []), 'is_array'));
        $otherRoots = [];
        foreach ($roots as $root) {
            array_push($otherRoots, ...self::otherRoots($before, $after, $root, $names));
        }
        $rootIntended = $roots && ! $otherRoots;
        foreach ($events as $event) {
            $entry = self::entry('event', (string) ($event['type'] ?? ''), (string) ($event['level'] ?? 'info'), (string) ($event['text'] ?? ''), (array) ($event['devices'] ?? []));
            $vlans = array_map('intval', (array) ($event['vlans'] ?? []));
            $inFix = $roots && $vlans && ! array_diff($vlans, $fixVlans);
            if ($entry['type'] === 'root_changed' && self::toIntended($event, $roots)) {
                $result['expected'][] = $entry;
            } elseif ($rootIntended && $inFix && in_array($entry['type'], ['blocking_started', 'blocking_stopped'], true)) {
                $result['expected'][] = $entry;
            } elseif (in_array($entry['type'], self::UNEXPECTED_EVENTS, true)) {
                $result['unexpected'][] = $entry;
            } else {
                $result['listed'][] = $entry;
            }
        }

        // the devices that went down meanwhile
        foreach ((array) ($comparison['devices']['down'] ?? []) as $deviceId) {
            $result['unexpected'][] = self::entry('device', 'device_down', 'critical', self::name((int) $deviceId, $names) . ' went down', [(int) $deviceId]);
        }
        foreach ((array) ($comparison['devices']['up'] ?? []) as $deviceId) {
            $result['listed'][] = self::entry('device', 'device_up', 'info', self::name((int) $deviceId, $names) . ' came back up', [(int) $deviceId]);
        }

        // the findings
        $findings = (array) ($comparison['findings'] ?? []);
        foreach (['new', 'worse'] as $kind) {
            foreach ((array) ($findings[$kind] ?? []) as $finding) {
                $finding = (array) $finding;
                $entry = self::entry('finding', $kind, (string) ($finding['severity'] ?? 'info'), self::findingText($kind, $finding), (array) ($finding['devices'] ?? []));
                if (isset($finding['was']['identity']) && isset($resolves[(string) $finding['was']['identity']])) {
                    continue; // a finding of the fix that is still there, in another form: said below
                }
                if ($rootIntended && ($finding['analysis'] ?? null) === 'stp' && in_array($finding['code'] ?? null, self::ROOT_MOVE_FINDINGS, true)) {
                    $result['expected'][] = $entry; // the root bridge and root ports changing as the fix moves the root

                    continue;
                }
                $counts = (self::SEVERITIES[$finding['severity'] ?? ''] ?? 0) >= $rank && empty($finding['quiet']);
                $result[$counts ? 'unexpected' : 'listed'][] = $entry + ($counts ? [] : ['why' => ! empty($finding['quiet']) ? 'quiet' : 'below']);
            }
        }
        foreach (['changed', 'milder'] as $kind) {
            foreach ((array) ($findings[$kind] ?? []) as $finding) {
                $finding = (array) $finding;
                if (isset($finding['was']['identity']) && isset($resolves[(string) $finding['was']['identity']])) {
                    continue;
                }
                $result['listed'][] = self::entry('finding', $kind, (string) ($finding['severity'] ?? 'info'), self::findingText($kind, $finding), (array) ($finding['devices'] ?? []));
            }
        }
        foreach ((array) ($findings['resolved'] ?? []) as $finding) {
            $finding = (array) $finding;
            $entry = self::entry('finding', 'resolved', (string) ($finding['severity'] ?? 'info'), self::findingText('resolved', $finding), (array) ($finding['devices'] ?? []));
            $result[isset($resolves[(string) ($finding['identity'] ?? '')]) ? 'expected' : 'listed'][] = $entry;
        }

        // what the fix was made for: each of its findings resolved, the intended root bridge in each of its VLANs
        if ($expectation !== null) {
            $now = (array) ($after['findings'] ?? []);
            $successors = [];
            foreach (['worse', 'changed', 'milder'] as $kind) {
                foreach ((array) ($findings[$kind] ?? []) as $finding) {
                    if (isset($finding['was']['identity'])) {
                        $successors[(string) $finding['was']['identity']] = (array) $finding;
                    }
                }
            }
            foreach ($resolves as $identity => $title) {
                $still = $now[$identity] ?? $successors[$identity] ?? null;
                if ($still !== null) {
                    $result['unexpected'][] = self::entry('fix', 'not_resolved', (string) ($still['severity'] ?? 'warning'), 'The fix\'s finding is still there: '
                        . UntrustedText::line((string) ($still['title'] ?? $title), 300), (array) ($still['devices'] ?? []));
                }
            }
            array_push($result['unexpected'], ...$otherRoots);
        }
        // a fix is verified by the check that found its findings: without it in both states nothing says that they were resolved
        $unchecked = null;
        if ($expectation !== null) {
            $check = ($expectation['kind'] ?? null) === FixExpectation::STP_ROOT_PRIORITY ? 'stp' : 'links';
            foreach ([$before, $after] as $state) {
                $checks = (array) ($state['checks'] ?? []);
                if (! in_array($check, array_map('strval', (array) ($checks['enabled'] ?? [])), true) || array_key_exists($check, (array) ($checks['failed'] ?? []))) {
                    $unchecked = $check;
                }
            }
        }

        $counts = ['events' => count($events), 'unexpected' => count($result['unexpected']), 'expected' => count($result['expected']), 'listed' => count($result['listed']),
            'findings_new' => count((array) ($findings['new'] ?? [])), 'findings_worse' => count((array) ($findings['worse'] ?? [])),
            'findings_resolved' => count((array) ($findings['resolved'] ?? [])), 'findings_same' => (int) ($findings['same'] ?? 0),
            'down' => count((array) ($comparison['devices']['down'] ?? [])), 'facts' => count((array) ($after['facts'] ?? [])),
            'findings' => count((array) ($after['findings'] ?? []))];
        foreach (['unexpected', 'expected', 'listed'] as $list) {
            $result[$list] = array_slice($result[$list], 0, self::LIST_MAX);
        }

        return ['result' => $result['unexpected'] ? 'unexpected' : 'verified'] + $result + ['counts' => $counts, 'unchecked' => $unchecked];
    }

    /**
     * Did a root bridge event move the root to one of the fix's intended roots, in its VLANs?
     *
     * @param  list<array>  $roots  the fix's intended roots (FixExpectation)
     */
    private static function toIntended(array $event, array $roots): bool
    {
        $vlans = array_map('intval', (array) ($event['vlans'] ?? []));
        $to = (array) ($event['root_after'] ?? []);
        foreach ($roots as $root) {
            if ($vlans && ! array_diff($vlans, array_map('intval', (array) ($root['vlans'] ?? []))) && self::isIntended($to, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is a root bridge (mac, device) the fix's intended root?
     */
    private static function isIntended(array $bridge, array $root): bool
    {
        if (($bridge['device'] ?? null) !== null) {
            return (int) $bridge['device'] === (int) ($root['primary'] ?? 0);
        }

        return ($root['primary_mac'] ?? '') !== '' && strtolower((string) ($bridge['mac'] ?? $bridge['root'] ?? '')) === strtolower((string) $root['primary_mac']);
    }

    /**
     * The switches of the fix's domain that do not see the intended root after the batch, per VLAN: those that saw the
     * root the fix moves away from (or the intended one) before the batch.
     *
     * @param  array<int, string>  $names
     * @return list<array>
     */
    private static function otherRoots(array $before, array $after, array $root, array $names): array
    {
        $fixVlans = array_map('intval', (array) ($root['vlans'] ?? []));
        $old = array_map('strtolower', array_map('strval', (array) ($root['old_macs'] ?? [])));
        // switch => VLAN => root, before and after the batch
        $seen = function (array $state): array {
            $roots = [];
            foreach ((array) ($state['roots'] ?? []) as $group) {
                foreach ((array) ($group['devices'] ?? []) as $deviceId) {
                    foreach ((array) ($group['vlans'] ?? []) as $vlan) {
                        $roots[(int) $deviceId][(int) $vlan] = ['mac' => strtolower((string) ($group['root'] ?? '')),
                            'device' => isset($group['root_device']) ? (int) $group['root_device'] : null];
                    }
                }
            }

            return $roots;
        };
        $was = $seen($before);
        $now = $seen($after);
        $wrong = [];
        foreach ($was as $deviceId => $vlans) {
            foreach ($vlans as $vlan => $bridge) {
                if (! in_array($vlan, $fixVlans, true) || ! (in_array($bridge['mac'], $old, true) || self::isIntended($bridge, $root))) {
                    continue; // another VLAN, or another domain
                }
                $after_ = $now[$deviceId][$vlan] ?? null;
                if ($after_ === null || ! self::isIntended($after_, $root)) {
                    $wrong[$vlan][$after_ === null ? 'none' : ($after_['device'] !== null ? 'd' . $after_['device'] : $after_['mac'])][] = $deviceId;
                }
            }
        }
        $entries = [];
        ksort($wrong);
        foreach ($wrong as $vlan => $byRoot) {
            foreach ($byRoot as $bridge => $devices) {
                $one = count($devices) === 1;
                $what = $bridge === 'none' ? ($one ? 'reports' : 'report') . ' no root bridge'
                    : ($one ? 'sees ' : 'see ') . (str_starts_with($bridge, 'd') ? self::name((int) substr($bridge, 1), $names) : 'the bridge ' . $bridge) . ' as root bridge';
                $entries[] = self::entry('fix', 'root_not_intended', 'warning', self::vlanLabel($vlan) . ': ' . self::deviceList($devices, $names) . ' ' . $what
                    . ', not ' . self::name((int) ($root['primary'] ?? 0), $names) . ', the root the fix intends', $devices);
            }
        }

        return $entries;
    }

    /**
     * A finding of the comparison in words.
     */
    private static function findingText(string $kind, array $finding): string
    {
        // a spanning tree finding with its VLANs: two VLANs' findings can have the same title
        $vlans = ($finding['analysis'] ?? null) === 'stp' ? array_values(array_filter((array) ($finding['vlans'] ?? []), 'is_int')) : [];
        $title = ($vlans ? FixAnswers::vlanText($vlans) . ': ' : '') . UntrustedText::line((string) ($finding['title'] ?? ''), 300);
        $severity = (string) ($finding['severity'] ?? '');
        $was = (array) ($finding['was'] ?? []);

        return match ($kind) {
            'new' => "New finding ($severity): $title",
            'worse' => "Worse finding ($severity): $title" . (($was['severity'] ?? $severity) !== $severity ? ' (was ' . ($was['severity'] ?? '') . ')'
                : ' (now about ' . count((array) ($finding['devices'] ?? [])) . ' devices, was ' . count((array) ($was['devices'] ?? [])) . ')'),
            'milder' => "Milder finding ($severity): $title (was " . ($was['severity'] ?? '') . ')',
            'changed' => "Finding changed ($severity): $title",
            default => "Resolved ($severity): $title",
        };
    }

    /**
     * One entry of a list.
     *
     * @param  int[]  $devices
     */
    private static function entry(string $what, string $type, string $level, string $text, array $devices): array
    {
        return ['what' => $what, 'type' => $type, 'level' => $level, 'text' => UntrustedText::line($text, 600), 'devices' => array_values(array_map('intval', $devices))];
    }

    /**
     * @param  array<int, string>  $names
     */
    private static function name(int $deviceId, array $names): string
    {
        return UntrustedText::line((string) (($names[$deviceId] ?? '') ?: "device #$deviceId"), 80);
    }

    /**
     * @param  int[]  $devices
     * @param  array<int, string>  $names
     */
    private static function deviceList(array $devices, array $names, int $max = 5): string
    {
        $listed = array_map(fn (int $deviceId) => self::name($deviceId, $names), array_slice($devices, 0, $max));

        return implode(', ', $listed) . (count($devices) > $max ? ' and ' . (count($devices) - $max) . ' more' : '');
    }

    /**
     * Why a comparison is no verification of the batch, for the change page and the timeline: what either state could not
     * read, each with the topology plugin's error (one line, never SQL) and the state it failed in; a check that ran in one
     * of the states only; the check that found a fix's findings that did not run; the devices the state before covered
     * that the state after no longer does (LibreNMS no longer has them as the admin who started the batch sees the
     * network); then the ways out (fix it, switch the check off in the topology plugin's settings, enable the devices
     * again, or only record such a batch). The same for the eventlog without the errors and the names (they may name
     * devices). Null when nothing keeps the comparison from being a verification.
     *
     * @param  array{facts?: string[], checks?: string[], errors?: array{facts?: array<string, array{before?: string, after?: string}>,
     *     checks?: array<string, array{before?: string, after?: string}>}, devices?: int[]}  $skipped  the verification API's skipped
     * @param  ?string  $unchecked  the check that found a fix's findings, when it did not run in both states (evaluate())
     * @param  array<int, string>  $names  device => name, for the devices no longer covered
     * @param  int  $unexpected  the unexpected changes found in what was compared
     * @return array{why: string, event: string}|null
     */
    public static function skippedWhy(array $skipped, ?string $unchecked = null, array $names = [], int $unexpected = 0): ?array
    {
        $errors = (array) ($skipped['errors'] ?? []);
        $why = [];
        $event = [];
        $broken = false;
        $off = [];
        $back = [];
        foreach (self::skippedFacts($skipped) as $kind) {
            $why[] = 'the topology plugin could not read ' . self::factWords($kind) . self::failedIn((array) (((array) ($errors['facts'] ?? []))[$kind] ?? []));
            $event[] = ['the topology plugin could not read ' . self::factWords($kind), 'it could not read ' . self::factWords($kind)];
            $broken = true;
        }
        $checks = self::skippedChecks($skipped);
        if ($unchecked !== null && ! in_array($unchecked, $checks, true)) {
            $checks[] = $unchecked;
        }
        foreach ($checks as $check) {
            $name = 'the topology plugin\'s ' . self::checkWords($check) . ' check' . ($check === $unchecked ? ', which found the fix\'s findings,' : '');
            $failed = (array) (((array) ($errors['checks'] ?? []))[$check] ?? []);
            if ($failed) {
                $why[] = $name . ' failed' . self::failedIn($failed);
                $event[] = self::checkEvent($check, 'failed');
                $broken = true;
                if ($check !== $unchecked) {
                    $off[] = self::checkWords($check);
                }
            } elseif (in_array($check, self::skippedChecks($skipped), true)) {
                $why[] = $name . ' ran in one of the two states only: it was switched on or off in its settings (Background checks) between them';
                $event[] = self::checkEvent($check, 'ran in one of the two states only');
                $back[] = self::checkWords($check);
            } else {
                $why[] = $name . ' is switched off in its settings (Background checks)';
                $event[] = self::checkEvent($check, 'is switched off');
            }
        }
        $eventText = $event ? implode('; ', [$event[0][0], ...array_column(array_slice($event, 1), 1)]) . ' in the state before or after it' : '';
        // a device the state before covered that the state after does not: what the batch changed there is not compared
        $lost = array_slice(array_values(array_unique(array_map('intval', array_filter((array) ($skipped['devices'] ?? []), 'is_scalar')))), 0, 200);
        if ($lost) {
            $why[] = 'the state after it no longer covers ' . self::devicesText($lost, $names) . ': LibreNMS no longer has '
                . (count($lost) === 1 ? 'it' : 'them') . ' as the admin who started the batch sees the network (disabled or deleted, or no longer visible to that admin)';
            $eventText .= ($eventText !== '' ? '; ' : '') . 'the state after it no longer covers ' . Format::count(count($lost), 'device') . ' of its scope';
        }
        if (! $why) {
            return null;
        }
        $ways = [];
        if ($broken) {
            $ways[] = 'fix it (the details are in logs/librenms.log)';
        }
        if ($off) {
            $ways[] = 'switch the ' . self::join($off) . ' check' . (count($off) > 1 ? 's' : '') . ' off in the topology plugin\'s settings (Background checks)';
        }
        if ($back) {
            $ways[] = 'switch the ' . self::join($back) . ' check' . (count($back) > 1 ? 's' : '') . ' back as it was in the topology plugin\'s settings';
        }
        if ($lost) {
            $ways[] = 'enable ' . (count($lost) === 1 ? 'the device' : 'the devices') . ' in LibreNMS again (or let that admin see ' . (count($lost) === 1 ? 'it' : 'them') . ')';
        }
        $ways[] = 'set When a batch cannot be verified to only record it';

        return ['why' => implode('; ', $why) . ($unexpected > 0 ? '; ' . Format::count($unexpected, 'unexpected change') . ' in what was compared (listed with the batch)'
            : '; nothing else that was compared changed') . '. To go on: ' . self::join($ways, 'or'), 'event' => $eventText];
    }

    /**
     * A check in the eventlog's words: as the first clause, and as one after it.
     *
     * @return array{0: string, 1: string}
     */
    private static function checkEvent(string $check, string $what): array
    {
        return ['the topology plugin\'s ' . self::checkWords($check) . " check $what", 'its ' . self::checkWords($check) . " check $what"];
    }

    /**
     * Devices by name, at most 10: "lab-sw3, lab-sw5 and 4 more".
     *
     * @param  int[]  $ids
     * @param  array<int, string>  $names
     */
    public static function devicesText(array $ids, array $names, int $max = 10): string
    {
        $parts = array_map(fn (int $id) => UntrustedText::line((string) (($names[$id] ?? '') ?: "device #$id"), 80), array_slice($ids, 0, $max));

        return count($ids) > $max ? implode(', ', $parts) . ' and ' . (count($ids) - $max) . ' more' : self::join($parts);
    }

    /**
     * " in the state after it (its error)", for each state a check or a kind of facts failed in.
     *
     * @param  array{before?: string, after?: string}  $failed
     */
    private static function failedIn(array $failed): string
    {
        $parts = [];
        foreach (['before', 'after'] as $phase) {
            if (isset($failed[$phase]) && is_string($failed[$phase])) {
                $parts[] = "in the state $phase it (" . UntrustedText::line($failed[$phase], 200) . ')';
            }
        }

        return $parts ? ' ' . implode(' and ', $parts) : ' in the state before or after it';
    }

    /** @return string[] */
    private static function skippedFacts(array $skipped): array
    {
        return array_slice(array_values(array_filter((array) ($skipped['facts'] ?? []), 'is_string')), 0, 10);
    }

    /** @return string[] */
    private static function skippedChecks(array $skipped): array
    {
        return array_slice(array_values(array_filter((array) ($skipped['checks'] ?? []), 'is_string')), 0, 10);
    }

    private static function factWords(string $kind): string
    {
        return ['link' => 'the links', 'lag' => 'the port-channels', 'stp_blocked' => 'the blocked ports', 'stp_root' => 'the root bridges', 'ospf' => 'the OSPF adjacencies',
            'bgp' => 'the BGP sessions'][$kind] ?? 'the ' . UntrustedText::line(str_replace('_', ' ', $kind), 40);
    }

    private static function checkWords(string $check): string
    {
        return $check === 'stp' ? 'spanning tree' : UntrustedText::line($check, 40);
    }

    /**
     * "a", "a and b", "a, b and c" (or "or").
     *
     * @param  string[]  $parts
     */
    private static function join(array $parts, string $and = 'and'): string
    {
        $last = array_pop($parts);

        return ($parts ? implode(', ', $parts) . " $and " : '') . $last;
    }

    /**
     * A VLAN key as the topology plugin says it ("VLAN 1 / CST", "VLAN 10").
     */
    public static function vlanLabel(int $key): string
    {
        return $key <= 1 ? 'VLAN 1 / CST' : "VLAN $key";
    }
}
