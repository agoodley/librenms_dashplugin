<?php

namespace Agoodley\LibrenmsTopology\Verification;

use Agoodley\LibrenmsTopology\History\FactText;
use Agoodley\LibrenmsTopology\History\FindingHistory;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\InterfaceName;

/**
 * The fixes of the verification API (Api\Verification::fixes()): the findings whose configuration is clear, as data a
 * template can apply, never as configuration text. Pure.
 *
 * - Port descriptions (description_wrong, description_missing): for each port, the description the link check built
 *   from its LLDP/CDP neighbor (LinkAnalyzer::cleanDescription(): letters, digits and . _ / : + ( ) # @ , - and spaces,
 *   at most 80), with the port's names (ifName, ifDescr) and its description now.
 * - Spanning tree root priority (root_default_priority, root_not_preferred): per L2 domain, the VLANs whose root is
 *   wrong, the intended root (the first designated root bridge of the domain, in the setting's order, else its first
 *   configured core device) with priority 4096 and the next one as secondary with 8192, each with its spanning tree
 *   mode (pvst: a priority per VLAN; mst: the CIST, instance 0; stp: one instance). A domain without designated root
 *   bridges or configured core devices has no fix: the core the topology plugin would guess is no clear configuration.
 *
 * Each fix names the findings it resolves by their identity (FindingHistory::hash()).
 */
final class FixBuilder
{
    public const PORT_DESCRIPTIONS = 'port_descriptions';

    public const STP_ROOT_PRIORITY = 'stp_root_priority';

    public const KINDS = [self::PORT_DESCRIPTIONS, self::STP_ROOT_PRIORITY];

    /** The checks each kind fixes. */
    public const CODES = [
        self::PORT_DESCRIPTIONS => ['description_wrong', 'description_missing'],
        self::STP_ROOT_PRIORITY => ['root_default_priority', 'root_not_preferred'],
    ];

    /** The bridge priority of the intended root and of its secondary. */
    public const PRIMARY_PRIORITY = 4096;

    public const SECONDARY_PRIORITY = 8192;

    /** Where an admin sets what the root fix starts from. */
    public const ROOT_SETTINGS = 'set Designated root bridges (or Core devices) in the topology plugin\'s settings (Overview, Plugins, Plugin Admin, topology, '
        . 'Settings)';

    /**
     * The port description fixes of the link check's findings, one per finding (a device).
     *
     * @param  list<array>  $findings  findings of the link check, each with its identity
     * @param  array<int, array{device_id: int, ifName: string, ifDescr: string, ifAlias: string}>  $ports  the ports the user may see
     * @param  array<int, string>  $names
     * @return list<array>
     */
    public static function descriptions(array $findings, array $ports, array $names): array
    {
        $fixes = [];
        foreach ($findings as $finding) {
            if (! in_array($finding['code'] ?? null, self::CODES[self::PORT_DESCRIPTIONS], true) || ! is_array($finding['fix']['ports'] ?? null)) {
                continue;
            }
            $deviceId = (int) ($finding['devices'][0] ?? 0);
            $rows = [];
            foreach ($finding['fix']['ports'] as $portId => $description) {
                $port = $ports[(int) $portId] ?? null;
                if ($port === null || $port['device_id'] !== $deviceId || (string) $description === '') {
                    continue; // a port gone meanwhile, or one the user may not see
                }
                $rows[] = ['device_id' => $deviceId, 'port_id' => (int) $portId, 'ifName' => $port['ifName'], 'ifDescr' => $port['ifDescr'], 'current' => $port['ifAlias'],
                    'description' => (string) $description];
            }
            if (! $rows) {
                continue;
            }
            usort($rows, fn (array $a, array $b) => strnatcasecmp(InterfaceName::normalize($a['ifName']), InterfaceName::normalize($b['ifName'])) ?: $a['port_id'] <=> $b['port_id']);
            $fixes[] = self::fix(self::PORT_DESCRIPTIONS, [$finding], [$deviceId], 'Port descriptions of ' . ($names[$deviceId] ?? "device #$deviceId"))
                + ['ports' => $rows];
        }

        return $fixes;
    }

    /**
     * The root priority fixes of the spanning tree check's root placement findings: per domain and intended root, the
     * VLANs it fixes; the findings that cannot be fixed, with why.
     *
     * @param  list<array>  $findings  findings of the spanning tree check, each with its identity
     * @param  array<int, list<int[]>>  $domains  VLAN key => the member ids of each of its domains
     * @param  int[]  $designated  the designated root bridges, in the setting's order
     * @param  int[]  $core  the configured core devices, in the setting's order
     * @param  array<int, array{keys: int[], instances: array<int, array{mac: string, priority: int, protocol: string}>}>  $bridges  the
     *                                                                                                                              spanning tree of the devices named
     * @param  array<int, string>  $names
     * @param  ?string  $refusal  why no root fix can be made at all (a setting names a device ambiguously): each finding then has it
     * @param  string  $hint  said after "no designated root bridge or core device": the settings' names that name no device (devicesNamed())
     * @return array{fixes: list<array>, not_fixable: list<array{identity: string, code: string, title: string, why: string}>}
     */
    public static function rootPriorities(array $findings, array $domains, array $designated, array $core, array $bridges, array $names, ?string $refusal = null,
        string $hint = ''): array
    {
        $groups = [];
        $notFixable = [];
        foreach ($findings as $finding) {
            if (! in_array($finding['code'] ?? null, self::CODES[self::STP_ROOT_PRIORITY], true) || ! isset($finding['fix']['root'])) {
                continue;
            }
            if ($refusal !== null) {
                // the settings do not say which devices they mean: no priority is pushed on a guess
                $notFixable[] = ['identity' => (string) $finding['identity'], 'code' => (string) $finding['code'], 'title' => FindingHistory::title($finding),
                    'devices' => array_map('intval', (array) $finding['devices']), 'why' => $refusal];

                continue;
            }
            $root = (int) $finding['fix']['root'];
            $why = null;
            foreach (self::keys($finding) as $key) {
                $members = self::domainOf($domains[$key] ?? [], $root);
                [$candidates, $from] = ($picked = array_values(array_intersect($designated, $members))) ? [$picked, 'designated'] : [array_values(array_intersect($core, $members)), 'core'];
                if (! $candidates) {
                    $why = 'no designated root bridge or core device in its part of the network: ' . self::ROOT_SETTINGS . $hint;
                    continue;
                }
                $primary = $candidates[0];
                if (! isset($bridges[$primary]['instances'][$key])) {
                    $why = ($names[$primary] ?? "device #$primary") . ', its intended root, has no spanning tree instance in ' . StpIndex::vlanLabel($key);
                    continue;
                }
                $secondary = $candidates[1] ?? null;
                if ($secondary !== null && ! isset($bridges[$secondary]['instances'][$key])) {
                    $secondary = null;
                }
                sort($members);
                $group = implode('|', [$primary, $secondary ?? '', $from, sha1(implode(',', $members))]);
                $groups[$group] ??= ['primary' => $primary, 'secondary' => $secondary, 'from' => $from, 'members' => count($members), 'keys' => [], 'findings' => [],
                    'roots' => []];
                $groups[$group]['keys'][$key] = true;
                $groups[$group]['findings'][$finding['identity']] = $finding;
                $groups[$group]['roots'][$key] = ['device_id' => $root, 'mac' => (string) ($finding['fix']['root_mac'] ?? '')];
            }
            if ($why !== null && ! array_filter($groups, fn (array $group) => isset($group['findings'][$finding['identity']]))) {
                $notFixable[] = ['identity' => (string) $finding['identity'], 'code' => (string) $finding['code'], 'title' => FindingHistory::title($finding),
                    'devices' => array_map('intval', (array) $finding['devices']), 'why' => $why];
            }
        }

        $fixes = [];
        foreach ($groups as $group) {
            $keys = array_keys($group['keys']);
            sort($keys);
            $primary = self::bridge($group['primary'], self::PRIMARY_PRIORITY, $keys, $bridges);
            $secondary = $group['secondary'] !== null ? self::bridge($group['secondary'], self::SECONDARY_PRIORITY, $keys, $bridges) : null;
            $roots = $group['roots'];
            ksort($roots);
            $devices = array_values(array_filter([$group['primary'], $group['secondary']], fn ($id) => $id !== null));
            $title = 'Root bridge of ' . FactText::vlanList($keys) . ': ' . ($names[$group['primary']] ?? '#' . $group['primary'])
                . ($group['secondary'] !== null ? ', secondary ' . ($names[$group['secondary']] ?? '#' . $group['secondary']) : '');
            $fixes[] = self::fix(self::STP_ROOT_PRIORITY, array_values($group['findings']), $devices, $title) + [
                'vlans' => $keys,
                'chosen_from' => $group['from'] === 'designated' ? 'the designated root bridges' : 'the core devices',
                'domain_size' => $group['members'],
                'primary' => $primary,
                'secondary' => $secondary,
                'roots_now' => $roots,
            ];
        }

        return ['fixes' => $fixes, 'not_fixable' => $notFixable];
    }

    /**
     * The devices the names of a setting mean, for a fix that pushes a priority on them (designated root bridges, core
     * devices): a device id, or a LibreNMS hostname (case aside), never a sysName or a display name, which a device
     * reports itself or anyone may set; in the setting's order. A name that means several devices, and the names that mean
     * none, are said.
     *
     * @param  string[]  $wanted  the setting's names
     * @param  array<int, string>  $hostnames  device_id => LibreNMS hostname, of the devices the user sees
     * @return array{devices: int[], ambiguous: array<string, int[]>, unmatched: string[]}
     */
    public static function devicesNamed(array $wanted, array $hostnames): array
    {
        $byName = [];
        foreach ($hostnames as $deviceId => $hostname) {
            $byName[(string) (int) $deviceId][(int) $deviceId] = true;
            if (trim((string) $hostname) !== '') {
                $byName[strtolower(trim((string) $hostname))][(int) $deviceId] = true;
            }
        }
        $devices = [];
        $ambiguous = [];
        $unmatched = [];
        foreach ($wanted as $name) {
            $name = strtolower(trim((string) $name));
            if ($name === '') {
                continue;
            }
            $matches = array_keys($byName[$name] ?? []);
            if (count($matches) > 1) {
                sort($matches);
                $ambiguous[$name] = $matches;
            } elseif ($matches) {
                $devices[$matches[0]] = true;
            } else {
                $unmatched[] = $name;
            }
        }

        return ['devices' => array_keys($devices), 'ambiguous' => $ambiguous, 'unmatched' => array_values(array_unique($unmatched))];
    }

    /**
     * The spanning tree VLAN keys of a finding.
     *
     * @return int[]
     */
    private static function keys(array $finding): array
    {
        $keys = array_map('intval', (array) ($finding['vlans'] ?? []));
        if (! $keys && isset($finding['vlan'])) {
            $keys = [(int) $finding['vlan']];
        }

        return $keys ?: [0];
    }

    /**
     * The members of the domain that holds $deviceId (the device alone when no domain does).
     *
     * @param  list<int[]>  $domains
     * @return int[]
     */
    private static function domainOf(array $domains, int $deviceId): array
    {
        foreach ($domains as $members) {
            if (in_array($deviceId, $members, true)) {
                return array_map('intval', $members);
            }
        }

        return [$deviceId];
    }

    /**
     * One switch of a root fix: the priority it gets, its spanning tree mode (pvst: per VLAN; mst: the CIST; stp: a
     * single instance), the instances the priority goes on (VLAN numbers for pvst, 0 for mst, none for stp) and what it
     * has now in each VLAN.
     *
     * @param  int[]  $keys
     * @return array{device_id: int, priority: int, mode: string, instances: int[], mac: string, now: array<int, int>}
     */
    private static function bridge(int $deviceId, int $priority, array $keys, array $bridges): array
    {
        $info = $bridges[$deviceId] ?? ['keys' => [], 'instances' => []];
        $single = array_values($info['keys']) === [0];
        $protocol = strtolower((string) (reset($info['instances'])['protocol'] ?? ''));
        $mode = ! $single ? 'pvst' : (str_contains($protocol, 'mst') ? 'mst' : 'stp');
        $mine = array_values(array_filter($keys, fn (int $key) => isset($info['instances'][$key])));
        $now = [];
        $mac = '';
        foreach ($mine as $key) {
            $now[$key] = (int) $info['instances'][$key]['priority'];
            $mac = $mac ?: (string) $info['instances'][$key]['mac'];
        }

        return ['device_id' => $deviceId, 'priority' => $priority, 'mode' => $mode,
            'instances' => match ($mode) {
                'pvst' => array_values(array_unique(array_map(fn (int $key) => max(1, $key), $mine))),
                'mst' => [0],
                default => [],
            }, 'mac' => $mac, 'now' => $now];
    }

    /**
     * The parts every fix has: its kind, its identity (the kind and the findings it resolves), its title, the findings,
     * the devices it changes.
     *
     * @param  list<array>  $findings
     * @param  int[]  $devices
     */
    private static function fix(string $kind, array $findings, array $devices, string $title): array
    {
        $identities = array_values(array_unique(array_map(fn (array $finding) => (string) $finding['identity'], $findings)));
        sort($identities);

        return [
            'id' => sha1($kind . '|' . implode(',', $identities)),
            'kind' => $kind,
            'title' => $title,
            'devices' => array_values(array_map('intval', $devices)),
            'resolves' => $identities,
            'findings' => array_map(fn (array $finding) => ['identity' => (string) $finding['identity'], 'analysis' => (string) ($finding['analysis'] ?? ''),
                'code' => (string) $finding['code'], 'severity' => (string) $finding['severity'], 'title' => FindingHistory::title($finding),
                'devices' => array_map('intval', (array) $finding['devices']), 'vlans' => array_map('intval', (array) ($finding['vlans'] ?? []))], $findings),
        ];
    }
}
