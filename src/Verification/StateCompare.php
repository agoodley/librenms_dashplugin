<?php

namespace Agoodley\LibrenmsTopology\Verification;

use Agoodley\LibrenmsTopology\History\ChangeReport;
use Agoodley\LibrenmsTopology\History\FactCollector;
use Agoodley\LibrenmsTopology\History\FactText;
use Agoodley\LibrenmsTopology\History\FindingHistory;
use Agoodley\LibrenmsTopology\Stp\Finding;

/**
 * The difference of two states of the verification API (Api\Verification::state()), in the history's words: the facts
 * that are gone, new or changed between them become the events the Changes page reports (ChangeReport: "Cable moved",
 * "Link gone", "Root bridge moved", "OSPF adjacency lost", ...), and the findings are new, worse, changed, milder or
 * resolved, matched by FindingHistory's identity and, for a finding whose identity changed, by its successor rule (the
 * same check and VLAN about the same devices, or about all of them: "single point of failure: dist1" now cutting off
 * one more switch is the same finding, worse). Pure: the states carry everything it needs, names included.
 *
 * Only what both states cover is compared (the second is taken with the first's cover(): it covers all the first does,
 * unless a device left the scope meanwhile): the facts of the devices both recorded the facts of, the root bridges of
 * the switches of both domains, and the findings about a device of both scopes. A finding about a device only one of
 * them covers is not compared: the other state did not look.
 */
final class StateCompare
{
    /** Severity ranks: a finding is worse when its severity is higher. */
    private const RANKS = [Finding::INFO => 1, Finding::WARNING => 2, Finding::CRITICAL => 3];

    /**
     * @return array{from: int, to: int, events: list<array{type: string, category: string, level: string, text: string, devices: int[]}>,
     *     findings: array{new: list<array>, worse: list<array>, changed: list<array>, milder: list<array>, resolved: list<array>, same: int},
     *     devices: array{down: int[], up: int[]}, names: array<int, string>}
     *     worse, changed and milder: the finding now with "was" (the one before)
     */
    public static function compare(array $before, array $after): array
    {
        $from = (int) ($before['taken_at'] ?? 0);
        $to = max($from, (int) ($after['taken_at'] ?? 0));
        $names = (array) ($after['names'] ?? []) + (array) ($before['names'] ?? []);
        $covered = array_fill_keys(array_intersect(self::covered($before), self::covered($after)), true);
        $domain = array_fill_keys(array_intersect(self::domain($before), self::domain($after)), true);

        // the facts both cover, as periods that ended (gone) or started (new) between the two states; a spanning tree fact
        // whose VLANs changed is both (its data are its VLANs), as the snapshot records it
        $facts = [self::facts($before, $names, $covered, $domain), self::facts($after, $names, $covered, $domain)];
        $rows = [];
        $id = 0;
        $nodes = [];
        foreach ($facts[0] as $fact) {
            if (isset($fact['data']['b']['node'])) {
                $nodes[(string) $fact['data']['b']['node']] = true;
            }
        }
        foreach ($facts[0] + $facts[1] as $hash => $fact) {
            $was = $facts[0][$hash] ?? null;
            $now = $facts[1][$hash] ?? null;
            if ($was !== null && $now !== null && ($was['data'] == $now['data']
                || ! in_array($fact['kind'], [FactCollector::STP_ROOT, FactCollector::STP_BLOCKED], true))) {
                continue; // the same fact (a link whose details changed is still that link)
            }
            if ($was !== null) {
                $rows[] = self::row(++$id, $was, $from - 1, $to);
            }
            if ($now !== null) {
                $rows[] = self::row(++$id, $now, $to, null);
            }
        }
        $text = new FactText($names);
        $report = ChangeReport::build($rows, $from, $to, $text, [], $nodes);
        $events = array_map(fn (array $event) => ['type' => $event['type'], 'category' => $event['category'], 'level' => $event['level'], 'text' => $event['text'],
            'devices' => $event['devices']], $report['events']);

        $findings = self::findings(self::about($before, $domain), self::about($after, $domain));
        $devices = self::devices($before, $after);

        return ['from' => $from, 'to' => $to, 'events' => $events, 'findings' => $findings, 'devices' => $devices,
            'names' => self::mentioned($names, $events, $findings, $devices)];
    }

    /**
     * The devices a state's facts and findings cover: its subjects, the devices whose path to the core depends on them,
     * their neighbors, and those it was asked to include.
     *
     * @return int[]
     */
    public static function covered(array $state): array
    {
        $scope = (array) ($state['scope'] ?? []);

        return array_values(array_unique(array_map('intval', [...(array) ($scope['subjects'] ?? []), ...(array) ($scope['dependents'] ?? []),
            ...(array) ($scope['neighbors'] ?? []), ...(array) ($scope['included'] ?? [])])));
    }

    /**
     * The devices of a state's whole scope (the spanning tree switches whose root bridges it records, among them): its
     * domain and the devices it covers.
     *
     * @return int[]
     */
    public static function domain(array $state): array
    {
        return array_values(array_unique(array_map('intval', [...(array) (($state['scope'] ?? [])['domain'] ?? []), ...self::covered($state)])));
    }

    /**
     * A state's facts that the comparison covers, by identity, in FactCollector's form: those of the covered devices,
     * and the root bridges of the switches of both domains (a state keeps those grouped by root bridge and VLANs).
     *
     * @param  array<int, string>  $names
     * @param  array<int, true>  $covered
     * @param  array<int, true>  $domain
     * @return array<string, array{kind: string, key: string, device_a: ?int, device_b: ?int, data: array}>
     */
    private static function facts(array $state, array $names, array $covered, array $domain): array
    {
        $facts = [];
        foreach ((array) ($state['facts'] ?? []) as $hash => $fact) {
            $a = isset($fact['a']) ? (int) $fact['a'] : null;
            $b = isset($fact['b']) ? (int) $fact['b'] : null;
            if (($a !== null && isset($covered[$a])) || ($b !== null && isset($covered[$b]))) {
                $facts[(string) $hash] = ['kind' => (string) $fact['kind'], 'key' => (string) $fact['key'], 'device_a' => $a, 'device_b' => $b,
                    'data' => (array) $fact['data']];
            }
        }
        foreach ((array) ($state['roots'] ?? []) as $group) {
            $root = (string) $group['root'];
            $owner = isset($group['root_device']) ? (int) $group['root_device'] : null;
            foreach ((array) $group['devices'] as $deviceId) {
                $deviceId = (int) $deviceId;
                if (! isset($domain[$deviceId])) {
                    continue;
                }
                $key = "d$deviceId|$root";
                $facts[FactCollector::hash(FactCollector::STP_ROOT, $key)] = ['kind' => FactCollector::STP_ROOT, 'key' => $key, 'device_a' => $deviceId, 'device_b' => null,
                    'data' => ['device' => $deviceId, 'device_name' => (string) ($names[$deviceId] ?? "device #$deviceId"), 'root' => $root, 'root_device' => $owner,
                        'root_name' => $owner !== null ? ($names[$owner] ?? null) : null, 'vlans' => array_map('intval', (array) $group['vlans'])]];
            }
        }

        return $facts;
    }

    /**
     * A state's findings about one of these devices, each with its identity.
     *
     * @param  array<int, true>  $devices
     * @return array<string, array>
     */
    private static function about(array $state, array $devices): array
    {
        return array_filter((array) ($state['findings'] ?? []), fn ($finding) => (bool) array_intersect_key(array_flip(array_map('intval', (array) ($finding['devices'] ?? []))),
            $devices));
    }

    /**
     * A fact as a stored period of the history, for ChangeReport.
     */
    private static function row(int $id, array $fact, int $firstSeen, ?int $removedAt): array
    {
        return ['id' => $id, 'kind' => $fact['kind'], 'fact_key' => $fact['key'], 'device_a' => $fact['device_a'], 'device_b' => $fact['device_b'], 'data' => $fact['data'],
            'initial' => false, 'first_seen' => $firstSeen, 'removed_at' => $removedAt];
    }

    /**
     * Two states' findings, compared.
     *
     * @param  array<string, array>  $was  identity => finding
     * @param  array<string, array>  $now
     * @return array{new: list<array>, worse: list<array>, changed: list<array>, milder: list<array>, resolved: list<array>, same: int}
     */
    private static function findings(array $was, array $now): array
    {
        $result = ['new' => [], 'worse' => [], 'changed' => [], 'milder' => [], 'resolved' => [], 'same' => 0];
        $gone = array_diff_key($was, $now);
        foreach ($now as $identity => $finding) {
            $finding = ['identity' => (string) $identity] + (array) $finding;
            if (isset($was[$identity])) {
                $old = ['identity' => (string) $identity] + (array) $was[$identity];
                $rank = (self::RANKS[$finding['severity']] ?? 0) <=> (self::RANKS[$old['severity']] ?? 0);
                if ($rank > 0) {
                    $result['worse'][] = $finding + ['was' => $old];
                } elseif ($rank < 0) {
                    $result['milder'][] = $finding + ['was' => $old];
                } else {
                    $result['same']++;
                }

                continue;
            }
            $predecessor = self::predecessor($finding, $gone);
            if ($predecessor === null) {
                $result['new'][] = $finding;

                continue;
            }
            $old = ['identity' => $predecessor] + (array) $gone[$predecessor];
            unset($gone[$predecessor]);
            $rank = (self::RANKS[$finding['severity']] ?? 0) <=> (self::RANKS[$old['severity']] ?? 0);
            $more = (bool) array_diff(array_map('intval', (array) $finding['devices']), array_map('intval', (array) $old['devices']));
            $result[$rank > 0 || ($rank === 0 && $more) ? 'worse' : ($rank < 0 ? 'milder' : 'changed')][] = $finding + ['was' => $old];
        }
        foreach ($gone as $identity => $finding) {
            $result['resolved'][] = ['identity' => (string) $identity] + (array) $finding;
        }

        return $result;
    }

    /**
     * The finding before that a new one takes the place of, as the findings history's successor rule says: the same check
     * (analysis, code) and VLAN, about the same devices or about all the devices of the other (FindingHistory::subjects(),
     * recorded with each finding); null when none.
     *
     * @param  array<string, array>  $gone  identity => a finding before that is not there now
     */
    private static function predecessor(array $finding, array $gone): ?string
    {
        $subjects = array_map('intval', (array) ($finding['subjects'] ?? []));
        foreach ($gone as $identity => $old) {
            if (($old['analysis'] ?? null) !== ($finding['analysis'] ?? null) || ($old['code'] ?? null) !== ($finding['code'] ?? null)
                || self::vlan($old) !== self::vlan($finding)) {
                continue;
            }
            $others = array_map('intval', (array) ($old['subjects'] ?? []));
            if ($subjects && $others && (! array_diff($subjects, $others) || ! array_diff($others, $subjects))) {
                return (string) $identity;
            }
        }

        return null;
    }

    /**
     * The VLAN of a finding (its first, as its identity takes it), null for none.
     */
    private static function vlan(array $finding): ?int
    {
        $vlans = (array) ($finding['vlans'] ?? []);

        return $vlans ? (int) min($vlans) : null;
    }

    /**
     * The devices that went down between the two states (up before, down after) and those that came back.
     *
     * @return array{down: int[], up: int[]}
     */
    private static function devices(array $before, array $after): array
    {
        $result = ['down' => [], 'up' => []];
        foreach ((array) ($after['devices'] ?? []) as $deviceId => $now) {
            $was = $before['devices'][$deviceId] ?? null;
            if ($was === null) {
                // a device the state before did not cover: down since after the first state was taken, it went down between them
                if (empty($now['up']) && ($now['down_since'] ?? null) !== null && (int) $now['down_since'] >= (int) ($before['taken_at'] ?? 0)) {
                    $result['down'][] = (int) $deviceId;
                }

                continue;
            }
            if (! empty($was['up']) && empty($now['up'])) {
                $result['down'][] = (int) $deviceId;
            } elseif (empty($was['up']) && ! empty($now['up'])) {
                $result['up'][] = (int) $deviceId;
            }
        }
        sort($result['down']);
        sort($result['up']);

        return $result;
    }

    /**
     * The names of the devices the comparison mentions: those of its events, of the findings it lists and of the devices
     * that went down or came back.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private static function mentioned(array $names, array $events, array $findings, array $devices): array
    {
        $ids = [...$devices['down'], ...$devices['up']];
        foreach ($events as $event) {
            array_push($ids, ...$event['devices']);
        }
        foreach (['new', 'worse', 'changed', 'milder', 'resolved'] as $kind) {
            foreach ($findings[$kind] as $finding) {
                array_push($ids, ...array_map('intval', (array) ($finding['devices'] ?? [])));
                array_push($ids, ...array_map('intval', (array) ($finding['was']['devices'] ?? [])));
            }
        }

        return array_intersect_key($names, array_flip(array_unique($ids)));
    }
}
