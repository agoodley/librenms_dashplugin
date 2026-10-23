<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;

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
        // or stop blocking in those VLANs as the root moves
        $events = array_values(array_filter((array) ($comparison['events'] ?? []), 'is_array'));
        $rootMoved = false;
        foreach ($events as $event) {
            if (($event['type'] ?? null) === 'root_changed' && self::toIntended($event, $roots)) {
                $rootMoved = true;
            }
        }
        foreach ($events as $event) {
            $entry = self::entry('event', (string) ($event['type'] ?? ''), (string) ($event['level'] ?? 'info'), (string) ($event['text'] ?? ''), (array) ($event['devices'] ?? []));
            $vlans = array_map('intval', (array) ($event['vlans'] ?? []));
            $inFix = $roots && $vlans && ! array_diff($vlans, $fixVlans);
            if ($entry['type'] === 'root_changed' && self::toIntended($event, $roots)) {
                $result['expected'][] = $entry;
            } elseif ($rootMoved && $inFix && in_array($entry['type'], ['blocking_started', 'blocking_stopped'], true)) {
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
            foreach ($roots as $root) {
                array_push($result['unexpected'], ...self::otherRoots($before, $after, $root, $names));
            }
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
                $what = $bridge === 'none' ? 'reports no root bridge' : 'sees ' . (str_starts_with($bridge, 'd') ? self::name((int) substr($bridge, 1), $names) : 'the bridge ' . $bridge);
                $entries[] = self::entry('fix', 'root_not_intended', 'warning', self::vlanLabel($vlan) . ': ' . self::deviceList($devices, $names) . ' ' . $what
                    . ' as root bridge, not ' . self::name((int) ($root['primary'] ?? 0), $names) . ', the root the fix intends', $devices);
            }
        }

        return $entries;
    }

    /**
     * A finding of the comparison in words.
     */
    private static function findingText(string $kind, array $finding): string
    {
        $title = UntrustedText::line((string) ($finding['title'] ?? ''), 300);
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
     * What a comparison could not compare, in words ("the links of the network and the redundancy check"), null when it
     * compared everything (the verification API's skipped: the kinds of facts and the checks either state could not read).
     *
     * @param  array{facts?: string[], checks?: string[]}  $skipped
     */
    public static function skippedText(array $skipped): ?string
    {
        $words = ['link' => 'the links', 'lag' => 'the port-channels', 'stp_blocked' => 'the blocked ports', 'stp_root' => 'the root bridges', 'ospf' => 'the OSPF adjacencies',
            'bgp' => 'the BGP sessions'];
        $parts = [];
        foreach (array_slice(array_values(array_filter((array) ($skipped['facts'] ?? []), 'is_string')), 0, 10) as $kind) {
            $parts[] = $words[$kind] ?? 'the ' . UntrustedText::line(str_replace('_', ' ', $kind), 40);
        }
        foreach (array_slice(array_values(array_filter((array) ($skipped['checks'] ?? []), 'is_string')), 0, 10) as $check) {
            $parts[] = 'the ' . UntrustedText::line($check === 'stp' ? 'spanning tree' : $check, 40) . ' check';
        }
        if (! $parts) {
            return null;
        }
        $last = array_pop($parts);

        return ($parts ? implode(', ', $parts) . ' and ' : '') . $last;
    }

    /**
     * A VLAN key as the topology plugin says it ("VLAN 1 / CST", "VLAN 10").
     */
    public static function vlanLabel(int $key): string
    {
        return $key <= 1 ? 'VLAN 1 / CST' : "VLAN $key";
    }
}
