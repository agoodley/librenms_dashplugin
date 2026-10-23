<?php

namespace Agoodley\LibrenmsTopology\Api;

use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\History\FactCollector;
use Agoodley\LibrenmsTopology\History\FactText;
use Agoodley\LibrenmsTopology\History\FindingHistory;
use Agoodley\LibrenmsTopology\Links\LinkAnalyzer;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Verification\FixBuilder;
use Agoodley\LibrenmsTopology\Verification\LibrenmsSources;
use Agoodley\LibrenmsTopology\Verification\Sources;
use Agoodley\LibrenmsTopology\Verification\StateCompare;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * The verification API of the topology plugin, for other LibreNMS plugins (the Ansible plugin verifies its changes with
 * it): what the network around some devices is now, the difference of two such states in the history's words, and
 * the findings whose fix is clear, as data. It reuses the topology history and the background checks: the facts are
 * the ones a snapshot records (FactCollector), the findings the ones the background checks find, with the findings
 * history's identities (FindingHistory::hash()) and its rule for a finding that takes the place of another.
 *
 *  - state(): the state of a set of devices now. Its scope: the devices (subjects); the devices whose path to the core
 *    depends on them (dependents: their blast radius, as the planning API computes it); their monitored LLDP/CDP
 *    neighbors; devices a caller includes (an earlier state's, so that two states compare: cover()); and the spanning
 *    tree switches of their L2 domains (a priority change on one switch moves the root of them all). It records the
 *    facts of the covered devices (all but the domain: links with unmanaged neighbors, port-channel members, blocked
 *    ports, OSPF adjacencies, BGP sessions), the root bridge each switch of the domains sees, the findings of the
 *    enabled background checks about any device of the scope, and how fresh LibreNMS' data of each device is.
 *    Compact: identities, texts, severities and the facts' few details the comparison needs; never raw rows.
 *  - compare(): the difference of two states: facts gone, new and changed as the Changes page words them ("Cable
 *    moved", "Link gone", "Root bridge moved", "OSPF adjacency lost", ...), findings new, worse and resolved, devices
 *    that went down. Pure: it reads only the two states.
 *  - fixes(): the findings with a clear configuration, as data, never configuration text: port descriptions (the
 *    description the link check builds from the LLDP/CDP neighbor, LinkAnalyzer::cleanDescription()) and the
 *    spanning tree root priority (the intended root from the designated root bridges or the configured core, its
 *    priority and the secondary's, per VLAN and mode). Each fix names the findings it resolves.
 *  - scope() and freshness(): the scope of a state, and how fresh the data of devices is, without a state.
 *
 * Scoped as the plugin's pages: forUser() sees what that LibreNMS user may see (a device outside it is never in a
 * scope, a fact, a finding or a name; a link to it is an unmanaged neighbor named as LLDP/CDP reports it); system()
 * sees every enabled device and is for console commands only (anywhere else: LogicException). The topology plugin must
 * be enabled in LibreNMS: forUser() and system() throw PlanningUnavailable when it is not. VERSION is major.minor: the
 * major number changes only when a method or a field changes incompatibly; a caller checks that the class exists and
 * its major number. One instance builds its analyses once: take one state per instance.
 */
final class Verification
{
    /** Version of this API ("major.minor"). */
    public const VERSION = '1.0';

    /** Most devices one call takes (more: \InvalidArgumentException). */
    public const MAX_DEVICES = 5000;

    /** The kinds of fixes, and the checks each fixes. */
    public const PORT_DESCRIPTIONS = FixBuilder::PORT_DESCRIPTIONS;

    public const STP_ROOT_PRIORITY = FixBuilder::STP_ROOT_PRIORITY;

    public const FIX_CODES = FixBuilder::CODES;

    /** The bridge priority a root fix gives the intended root, and its secondary. */
    public const PRIMARY_PRIORITY = FixBuilder::PRIMARY_PRIORITY;

    public const SECONDARY_PRIORITY = FixBuilder::SECONDARY_PRIORITY;

    /** What a description of a fix may hold: these characters (a regular expression character class body), at most DESCRIPTION_MAX of them. */
    public const DESCRIPTION_CHARS = LinkAnalyzer::DESCRIPTION_CHARS;

    public const DESCRIPTION_MAX = LinkAnalyzer::DESCRIPTION_MAX;

    /** The roles of the devices of a state's scope. */
    public const ROLES = ['subject', 'dependent', 'neighbor', 'included', 'domain'];

    private const PLUGIN = 'topology';

    private const DISABLED = 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)';

    private function __construct(private readonly Sources $sources)
    {
    }

    /**
     * The API as a LibreNMS user sees the network (as the plugin's pages show it to that user).
     *
     * @param  object  $user  the LibreNMS user (App\Models\User) the caller acts for
     *
     * @throws PlanningUnavailable when the topology plugin is disabled
     */
    public static function forUser(object $user): self
    {
        return self::open(new TopologyRepository($user));
    }

    /**
     * The API on every enabled device, for console commands that act for nobody.
     *
     * @throws \LogicException outside the console
     * @throws PlanningUnavailable when the topology plugin is disabled
     */
    public static function system(): self
    {
        return self::open(TopologyRepository::system());
    }

    /**
     * The API on sources of the caller's own, with no check that the plugin is enabled.
     *
     * @internal for the plugin's tests
     */
    public static function withSources(Sources $sources): self
    {
        return new self($sources);
    }

    /**
     * Why the API cannot be used now (the topology plugin is disabled in LibreNMS), null when it can.
     */
    public static function unavailable(): ?string
    {
        try {
            return self::manager()->pluginEnabled(self::PLUGIN) ? null : self::DISABLED;
        } catch (PlanningUnavailable $e) {
            return $e->getMessage();
        }
    }

    /**
     * The scope a state of these devices covers now, without the state.
     *
     * @param  int[]  $deviceIds  the subjects (those the scope may not see are left out)
     * @param  array{include?: int[], domain?: int[], dependents?: bool}  $options  as state()'s
     * @return array{version: string, subjects: int[], dependents: int[], neighbors: int[], included: int[], domain: int[], names: array<int, string>}
     *     names: of every device of the scope
     */
    public function scope(array $deviceIds, array $options = []): array
    {
        $scope = $this->expand(self::ids($deviceIds), $options);

        return ['version' => self::VERSION] + $scope + ['names' => $this->sources->names([...$scope['subjects'], ...$scope['dependents'], ...$scope['neighbors'],
            ...$scope['included'], ...$scope['domain']])];
    }

    /**
     * What a later state must cover too, to compare with this one: pass it as state()'s options.
     *
     * @return array{include: int[], domain: int[]} include: the devices whose facts the state recorded; domain: its spanning tree domain
     */
    public static function cover(array $state): array
    {
        return ['include' => StateCompare::covered($state), 'domain' => array_values(array_map('intval', (array) (($state['scope'] ?? [])['domain'] ?? [])))];
    }

    /**
     * The state of the devices and of what is around them now.
     *
     * @param  int[]  $deviceIds  the subjects (the devices of a batch, say); those the scope may not see are left out
     * @param  array{include?: int[], domain?: int[], dependents?: bool}  $options  include: devices whose facts the state records too, domain:
     *                                                                               spanning tree switches it covers too (to compare it with an
     *                                                                               earlier state, pass cover() of that state); dependents: add
     *                                                                               the devices whose path to the core depends on the subjects
     *                                                                               (default true)
     * @return array{version: string, taken_at: int, scope: array{subjects: int[], dependents: int[], neighbors: int[], included: int[], domain: int[]},
     *     devices: array<int, array{role: string, up: bool, down_since: ?int, discovered_at: ?int, polled_at: ?int, discovery_pending: bool}>,
     *     facts: array<string, array{kind: string, key: string, a: ?int, b: ?int, text: string, data: array}>,
     *     roots: list<array{root: string, root_device: ?int, vlans: int[], devices: int[]}>,
     *     findings: array<string, array{analysis: string, code: string, label: string, severity: string, title: string, devices: int[], subjects: int[],
     *     vlans: int[], quiet: bool}>, names: array<int, string>, checks: array{enabled: string[], failed: array<string, string>, facts_failed: string[],
     *     facts_errors: array<string, string>}, role_hidden: string[]}
     *     devices: discovered_at / polled_at are when the device's last discovery and poll started; facts: by identity, the root bridges in roots
     *     (grouped by root bridge and VLANs); findings: by identity, quiet for the checks the topology plugin keeps out of the eventlog; checks:
     *     the enabled background checks, those that failed (check => its error in one line, never SQL: the details are in LibreNMS' log), the
     *     kinds of facts that could not be read and why (facts_errors: kind => its error)
     */
    public function state(array $deviceIds, array $options = []): array
    {
        $scope = $this->expand(self::ids($deviceIds), $options);
        $covered = [...$scope['subjects'], ...$scope['dependents'], ...$scope['neighbors'], ...$scope['included']];
        $switches = [...$covered, ...$scope['domain']];
        $read = $covered ? $this->sources->facts($covered, $switches, null) : ['facts' => [], 'failed' => [], 'errors' => []];
        $found = $covered ? $this->sources->findings($switches, $switches) : ['findings' => [], 'failed' => [], 'enabled' => []];

        // the facts, a root bridge fact grouped with those of the other switches that see the same root in the same VLANs
        $mentioned = $switches;
        $facts = [];
        $roots = [];
        foreach ($read['facts'] as $hash => $fact) {
            $ends = array_values(array_filter([$fact['device_a'] ?? null, $fact['device_b'] ?? null], fn ($id) => $id !== null));
            if ($this->sources->visible($ends) !== array_values(array_unique(array_map('intval', $ends)))) {
                continue; // never a fact about a device the scope may not see
            }
            if ($fact['kind'] === FactCollector::STP_ROOT) {
                $vlans = array_map('intval', $fact['data']['vlans'] ?? []);
                $owner = isset($fact['data']['root_device']) && $this->sources->visible([(int) $fact['data']['root_device']]) ? (int) $fact['data']['root_device'] : null;
                $group = $fact['data']['root'] . '|' . $owner . '|' . implode(',', $vlans);
                $roots[$group] ??= ['root' => (string) $fact['data']['root'], 'root_device' => $owner, 'vlans' => $vlans, 'devices' => []];
                $roots[$group]['devices'][] = (int) $fact['data']['device'];
                if ($owner !== null) {
                    $mentioned[] = $owner;
                }

                continue;
            }
            array_push($mentioned, ...$ends);
            $facts[(string) $hash] = ['kind' => $fact['kind'], 'key' => (string) $fact['key'], 'a' => isset($fact['device_a']) ? (int) $fact['device_a'] : null,
                'b' => isset($fact['device_b']) ? (int) $fact['device_b'] : null, 'text' => '', 'data' => $fact['data']];
        }

        // the findings about a device of the scope, by identity
        $switchSet = array_fill_keys($switches, true);
        $quiet = array_fill_keys($this->sources->quietChecks(), true);
        $findings = [];
        foreach ($found['findings'] as $analysis => $list) {
            foreach ($list as $finding) {
                $devices = array_values(array_unique(array_map('intval', $finding['devices'] ?? [])));
                if (! $devices || $this->sources->visible($devices) !== $devices) {
                    continue; // never a finding about a device the scope may not see
                }
                if (! array_intersect_key(array_flip($devices), $switchSet)) {
                    continue;
                }
                array_push($mentioned, ...$devices);
                $findings[FindingHistory::hash($analysis, $finding)] = ['analysis' => (string) $analysis, 'code' => (string) $finding['code'],
                    'label' => FindingHistory::label((string) $finding['code']), 'severity' => (string) $finding['severity'], 'title' => FindingHistory::title($finding),
                    'devices' => $devices, 'subjects' => [], 'vlans' => self::vlansOf($finding),
                    'quiet' => isset($quiet[$finding['code']]) || isset($quiet[$finding['code'] . ':' . $finding['severity']])];
                $findings[FindingHistory::hash($analysis, $finding)]['_finding'] = $finding;
            }
        }
        $names = $this->sources->names(array_values(array_unique($mentioned)));
        $text = new FactText($names);
        foreach ($facts as &$fact) {
            $fact['text'] = $text->describe(['kind' => $fact['kind'], 'key' => $fact['key'], 'data' => $fact['data']]);
        }
        unset($fact);
        foreach ($findings as &$finding) {
            // the devices a finding is about, as the findings history records them: those its title names, else all of them
            $finding['subjects'] = FindingHistory::subjects($finding['_finding'], $names)['devices'];
            unset($finding['_finding']);
        }
        unset($finding);

        $states = $this->sources->deviceStates($switches);
        $devices = [];
        foreach (['subject' => $scope['subjects'], 'dependent' => $scope['dependents'], 'neighbor' => $scope['neighbors'], 'included' => $scope['included'],
            'domain' => $scope['domain']] as $role => $ids) {
            foreach ($ids as $deviceId) {
                $devices[$deviceId] = ['role' => $role] + ($states[$deviceId] ?? ['up' => false, 'down_since' => null, 'discovered_at' => null, 'polled_at' => null,
                    'discovery_pending' => false]);
            }
        }

        return [
            'version' => self::VERSION,
            'taken_at' => time(),
            'scope' => $scope,
            'devices' => $devices,
            'facts' => $facts,
            'roots' => array_values($roots),
            'findings' => $findings,
            'names' => $names,
            'checks' => ['enabled' => array_values($found['enabled']), 'failed' => array_map('strval', (array) $found['failed']), 'facts_failed' => array_values($read['failed']),
                'facts_errors' => array_map('strval', array_intersect_key((array) ($read['errors'] ?? []), array_flip(array_map('strval', $read['failed']))))],
            'role_hidden' => $this->sources->roleHidden(),
        ];
    }

    /**
     * How fresh LibreNMS' data of these devices is now (the devices the scope may see): whether each is up, since when it
     * is down, when its last discovery and its last poll started, whether a discovery is pending.
     *
     * @param  int[]  $deviceIds
     * @return array{version: string, devices: array<int, array{up: bool, down_since: ?int, discovered_at: ?int, polled_at: ?int, discovery_pending: bool}>}
     */
    public function freshness(array $deviceIds): array
    {
        return ['version' => self::VERSION, 'devices' => $this->sources->deviceStates($this->sources->visible(self::ids($deviceIds)))];
    }

    /**
     * The difference of two states of this API (the later one taken with the earlier one's cover()), over what both
     * cover: the facts of the devices both recorded, the root bridges of the switches of both domains, the findings about
     * a device of both scopes.
     *
     * @return array{version: string, from: int, to: int, events: list<array{type: string, category: string, level: string, text: string, devices: int[]}>,
     *     findings: array{new: list<array>, worse: list<array>, changed: list<array>, milder: list<array>, resolved: list<array>, same: int},
     *     devices: array{down: int[], up: int[]}, names: array<int, string>, skipped: array{facts: string[], checks: string[],
     *     errors: array{facts: array<string, array{before?: string, after?: string}>, checks: array<string, array{before?: string, after?: string}>}}}
     *     events: in the Changes page's words (ChangeReport::TYPES); worse, changed, milder: the finding now with "was"; skipped: the kinds of
     *     facts and the checks not compared, as either state could not read them (a caller must not take the rest for all of it), with the
     *     error of each in the state it failed in (a check without one was enabled in one of the states only)
     *
     * @throws \InvalidArgumentException when a state is not one of this API's major version
     */
    public function compare(array $before, array $after): array
    {
        foreach ([$before, $after] as $state) {
            if (! is_string($state['version'] ?? null) || (int) $state['version'] !== (int) self::VERSION || ! is_int($state['taken_at'] ?? null)) {
                throw new \InvalidArgumentException('not a state of the verification API ' . (int) self::VERSION . '.x');
            }
        }

        return ['version' => self::VERSION] + StateCompare::compare($before, $after);
    }

    /**
     * The findings whose fix is clear, as data: of these devices (their port descriptions, the root bridges of their L2
     * domains), every device the scope sees when none are given.
     *
     * @param  int[]  $deviceIds
     * @return array{version: string, fixes: list<array>, not_fixable: list<array{identity: string, code: string, title: string, devices: int[], why: string}>,
     *     names: array<int, string>, role_hidden: string[]}
     *     each fix: id (its kind and the findings it resolves), kind (PORT_DESCRIPTIONS, STP_ROOT_PRIORITY), title, devices (those it changes),
     *     resolves and findings; a description fix: ports (device_id, port_id, ifName, ifDescr, current, description); a root fix: vlans (VLAN keys),
     *     chosen_from, domain_size, primary and secondary (device_id, priority, mode pvst|mst|stp, instances, mac, now: the priority in each VLAN)
     *     and roots_now (VLAN key => the root bridge now)
     */
    public function fixes(array $deviceIds = []): array
    {
        $ids = $deviceIds ? $this->sources->visible(self::ids($deviceIds)) : null;
        if ($ids === []) {
            return ['version' => self::VERSION, 'fixes' => [], 'not_fixable' => [], 'names' => [], 'role_hidden' => $this->sources->roleHidden()];
        }
        // the link check runs over every device the user sees, as on its page, and its fixes are kept for these devices at the
        // end: a description is wrong when it names another monitored device, which may be anywhere in the network
        $links = array_map(fn (array $finding) => $finding + ['identity' => FindingHistory::hash('links', $finding), 'analysis' => 'links'],
            array_values(array_filter($this->sources->linkFindings(null), fn (array $finding) => in_array($finding['code'], FixBuilder::CODES[FixBuilder::PORT_DESCRIPTIONS], true)
                && $this->visibleFinding($finding))));
        if ($ids !== null) {
            $wanted = array_fill_keys($ids, true);
            $links = array_values(array_filter($links, fn (array $finding) => array_intersect_key(array_flip(array_map('intval', $finding['devices'])), $wanted)));
        }
        $ports = [];
        foreach ($links as $finding) {
            array_push($ports, ...array_keys((array) ($finding['fix']['ports'] ?? [])));
        }
        $stp = $this->sources->stpAnalysis($ids === null ? null : $this->sources->stpDomain($ids));
        $roots = array_map(fn (array $finding) => $finding + ['identity' => FindingHistory::hash('stp', $finding), 'analysis' => 'stp'],
            array_values(array_filter($stp['findings'], fn (array $finding) => in_array($finding['code'], FixBuilder::CODES[FixBuilder::STP_ROOT_PRIORITY], true)
                && $this->visibleFinding($finding))));
        $designated = $this->sources->devicesOfSetting('stp_preferred_roots');
        $core = $this->sources->devicesOfSetting('redundancy_core');
        $keys = [];
        foreach ($roots as $finding) {
            foreach ([...$designated, ...$core] as $deviceId) {
                foreach (self::vlansOf($finding) ?: [0] as $key) {
                    $keys[$deviceId][$key] = $key;
                }
            }
        }
        $mentioned = array_merge(array_merge([], ...array_column($links, 'devices')), array_merge([], ...array_column($roots, 'devices')), $designated, $core);
        $names = $this->sources->names(array_values(array_unique(array_map('intval', $mentioned))));
        $built = FixBuilder::rootPriorities($roots, $stp['domains'], $designated, $core, $this->sources->bridges(array_map('array_values', $keys)), $names);
        $fixes = [...FixBuilder::descriptions($links, $this->sources->portNames($ports), $names), ...$built['fixes']];
        if ($ids !== null) {
            // of these devices: a fix of one of them, or of a finding about one
            $wanted = array_fill_keys($ids, true);
            $fixes = array_values(array_filter($fixes, fn (array $fix) => array_intersect_key(array_flip($fix['devices']), $wanted)
                || array_filter($fix['findings'], fn (array $finding) => array_intersect_key(array_flip($finding['devices']), $wanted))));
        }

        return ['version' => self::VERSION, 'fixes' => $fixes, 'not_fixable' => $built['not_fixable'], 'names' => $names, 'role_hidden' => $this->sources->roleHidden()];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The scope of a state: the subjects, the devices whose path to the core depends on them, their monitored neighbors,
     * the devices included, and the other spanning tree switches of their L2 domains (and those asked); each device in
     * one role, the first that applies.
     *
     * @param  int[]  $subjects
     * @param  array{include?: int[], domain?: int[], dependents?: bool}  $options
     * @return array{subjects: int[], dependents: int[], neighbors: int[], included: int[], domain: int[]}
     */
    private function expand(array $subjects, array $options): array
    {
        $subjects = $this->sources->visible($subjects);
        $dependents = $subjects && ($options['dependents'] ?? true) ? array_values(array_diff($this->sources->visible($this->sources->dependents($subjects)), $subjects)) : [];
        $core = [...$subjects, ...$dependents];
        $neighbors = $core ? array_values(array_diff($this->sources->visible($this->sources->neighbors($core)), $core)) : [];
        $included = array_values(array_diff($this->sources->visible(self::ids($options['include'] ?? [])), $core, $neighbors));
        $covered = [...$core, ...$neighbors, ...$included];
        $domain = $covered ? array_values(array_diff($this->sources->visible(array_values(array_unique([...$this->sources->stpDomain($covered),
            ...self::ids($options['domain'] ?? [])]))), $covered)) : [];

        return ['subjects' => $subjects, 'dependents' => $dependents, 'neighbors' => $neighbors, 'included' => $included, 'domain' => $domain];
    }

    /**
     * Is a finding about devices the scope may see only?
     */
    private function visibleFinding(array $finding): bool
    {
        $devices = array_values(array_unique(array_map('intval', $finding['devices'] ?? [])));

        return $devices && $this->sources->visible($devices) === $devices;
    }

    /**
     * The VLAN keys of a finding (its VLANs merged across instances, else its VLAN).
     *
     * @return int[]
     */
    private static function vlansOf(array $finding): array
    {
        $vlans = array_map('intval', (array) ($finding['vlans'] ?? []));
        if (! $vlans && isset($finding['vlan'])) {
            $vlans = [(int) $finding['vlan']];
        }
        sort($vlans);

        return array_values(array_unique($vlans));
    }

    /**
     * The API on a repository, once the plugin is known to be enabled, with its stored settings.
     */
    private static function open(TopologyRepository $repo): self
    {
        $manager = self::manager();
        if (! $manager->pluginEnabled(self::PLUGIN)) {
            throw new PlanningUnavailable(self::DISABLED);
        }
        try {
            $settings = new PluginSettings($manager->getSettings(self::PLUGIN));
        } catch (\Throwable) {
            $settings = new PluginSettings();
        }

        return new self(new LibrenmsSources($repo, $settings));
    }

    /**
     * LibreNMS' plugin manager.
     */
    private static function manager(): PluginManagerInterface
    {
        try {
            return app(PluginManagerInterface::class);
        } catch (\Throwable $e) {
            throw new PlanningUnavailable('LibreNMS cannot tell whether the topology plugin is enabled: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Positive device ids, each once, in the given order; at most MAX_DEVICES.
     *
     * @param  array<int, mixed>  $ids
     * @return int[]
     *
     * @throws \InvalidArgumentException for more than MAX_DEVICES
     */
    private static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($ids, 'is_scalar')), fn (int $id) => $id > 0)));
        if (count($ids) > self::MAX_DEVICES) {
            throw new \InvalidArgumentException('at most ' . self::MAX_DEVICES . ' devices per call');
        }

        return $ids;
    }
}
