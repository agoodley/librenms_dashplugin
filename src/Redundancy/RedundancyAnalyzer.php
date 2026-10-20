<?php

namespace Agoodley\LibrenmsTopology\Redundancy;

use Agoodley\LibrenmsTopology\Stp\Finding;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\Graph;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Topology\L2Builder;

/**
 * Redundancy and failure impact of the Layer 2 topology. Every device must keep a path to the
 * core (configured core devices, else the most central switches, see CoreFinder):
 *  - single points of failure: devices and links whose loss cuts monitored devices off the core;
 *  - fake redundancy: several links that all end on the same switch, or that all leave from the
 *    same stack member or line card;
 *  - redundancy lost now: devices that depend on a single device or link because their other
 *    path is down, and single uplinks that are port-channels running on one link;
 *  - outages: the top-most down devices and the down devices behind them.
 * Pure PHP: all data is passed in as arrays so it can be tested without LibreNMS.
 */
class RedundancyAnalyzer
{
    /** Check codes and their category; the "disabled checks" setting accepts either. */
    public const CHECKS = [
        'outage' => 'outages',
        'redundancy_lost' => 'lost',
        'unit_spof' => 'fake',
        'uplinks_same_device' => 'fake',
        'spof_device' => 'spof',
        'spof_link' => 'spof',
    ];

    /** Order of the categories within one severity: what is broken now first. */
    private const CATEGORY_ORDER = ['outages', 'lost', 'fake', 'spof'];

    /** Roles that make up the network: the findings are about cutting these off. */
    public const NETWORK_ROLES = [DeviceRole::SWITCH, DeviceRole::L3SWITCH, DeviceRole::ROUTER, DeviceRole::FIREWALL];

    /** Roles whose interface names tell the stack member or slot (other devices name ports by type, e.g. NetScaler 10/1). */
    private const NAMED_UNIT_ROLES = [DeviceRole::SWITCH, DeviceRole::L3SWITCH, DeviceRole::ROUTER];

    /** Virtual node joined to every core device, so that "the core" is one node of the graph. */
    public const CORE = '*core';

    /** Rows of the failure impact table. */
    private const IMPACT_ROWS = 300;

    /** Device names listed in a finding before "and N more". */
    private const LIST_NAMES = 12;

    /** @var array<int, true> reported devices (the group / location filter); the whole network is analyzed */
    private array $scope;

    /** @var array<string, array> node id (d:<device_id> or u:<neighbour>) => name, role, managed, network, up */
    private array $nodes = [];

    /** @var array<string, array> edge key => from, to, members (normalized links oriented from -> to), up members */
    private array $edges = [];

    /** Every node and link plus the CORE node. */
    private Graph $design;

    /** Up devices, unmanaged neighbours and links with an up member, plus the CORE node. */
    private Graph $current;

    /** @var array<string, string> core node => 'configured', 'stp' or 'automatic' */
    private array $core = [];

    /** @var array<string, int> node => index of its part of the network (connected component) */
    private array $part = [];

    /** Where configured core devices come from: 'redundancy_core', 'stp_preferred_roots' or null. */
    private ?string $coreSetting = null;

    /** @var array<int, array{key: string, label: string}> port_id => stack member / line card of link ports */
    private array $units = [];

    /** @var array<string, array<string, string>> node => [unit key => label] of devices whose links use several units */
    private array $deviceUnits = [];

    /** @var array<string, int[]> node => local port of each unmanaged neighbour that hangs off it alone (not graph nodes) */
    private array $leaves = [];

    /** @var array<string, true> disabled check codes and categories */
    private array $disabled;

    /** @var Finding[] */
    private array $findings = [];

    /**
     * @param  array<int, array>  $devices  device_id => device row (display, hostname, sysName, os, role, status) of
     *                                       every analyzed device; links to other devices count as unmanaged
     * @param  int[]  $scope  devices to report on (the group / location filter)
     * @param  array<int, array>  $links  rows of the links table of all analyzed devices
     * @param  array<int, array>  $ports  port_id => port row of the link ports (status, names, ifIndex)
     * @param  array<int, int>  $lagParent  member port_id => aggregate port_id
     * @param  array<int, array<int, array{key: string, label: string}>>  $entityUnits  device_id => ifIndex => unit from
     *                                                                                    the inventory, see PortUnits; a device listed here
     *                                                                                    (even without units) does not use interface names
     * @param  array<int, int>  $outageSince  device_id => unix time the current outage started
     * @param  array<int, int>  $stpPriority  device_id => bridge priority of the default spanning tree instance
     */
    public function __construct(
        private array $devices,
        array $scope,
        private array $links,
        private array $ports,
        private array $lagParent,
        private PluginSettings $settings,
        private array $entityUnits = [],
        private array $outageSince = [],
        private array $stpPriority = [],
    ) {
        $this->scope = array_fill_keys(array_map('intval', $scope), true);
        $this->disabled = array_fill_keys(array_map('strtolower', $settings->list('redundancy_disabled_checks')), true);
        $this->build();
    }

    /**
     * Run every check and return the data of the redundancy page: core, summary, findings, the
     * failure impact table and the current outages, limited to the reported devices.
     */
    public function analyze(): array
    {
        $this->findings = [];
        $design = $this->design->cuts(self::CORE);
        $current = $this->current->cuts(self::CORE);

        $losses = $this->vertexLosses($design);
        $impact = $this->checkSinglePoints($design, $losses);
        $this->checkSameDevice($losses);
        $this->checkUnits();
        $this->checkLost($design, $current);
        $outages = $this->checkOutages();

        $findings = [];
        foreach ($this->findings as $finding) {
            if ($this->inScope($finding->devices)) {
                $findings[] = $finding->toArray() + ['category' => self::CHECKS[$finding->code] ?? 'other'];
            }
        }
        // within a severity: what is broken now first, then the biggest impact, devices before links
        $categoryOrder = array_flip(self::CATEGORY_ORDER);
        $codeOrder = array_flip(array_keys(self::CHECKS));
        $key = fn ($f) => [Finding::SEVERITY_ORDER[$f['severity']], $categoryOrder[$f['category']] ?? 99, -count($f['devices']), $codeOrder[$f['code']] ?? 99, $f['title']];
        usort($findings, fn ($a, $b) => $key($a) <=> $key($b));

        $impact = array_values(array_filter($impact, fn ($row) => $this->inScope(array_merge($row['element_devices'], $row['devices']))));
        $kindOrder = ['device' => 0, 'unmanaged' => 1, 'port-channel' => 2, 'link' => 3];
        $rank = fn ($row) => [-$row['network'], -count($row['devices']), -$row['unmanaged'], $kindOrder[$row['kind']] ?? 9, $row['label']];
        usort($impact, fn ($a, $b) => $rank($a) <=> $rank($b));
        $impact = array_slice($impact, 0, self::IMPACT_ROWS);

        $outages = array_values(array_filter($outages, fn ($o) => $this->inScope(array_merge([$o['device_id']], $o['behind'], $o['shared']))));

        $core = [];
        foreach ($this->core as $node => $mode) {
            $core[] = ['device_id' => $this->nodes[$node]['device_id'], 'mode' => $mode];
        }

        $names = [];
        foreach ($findings as $finding) {
            foreach ($finding['devices'] as $deviceId) {
                $names[$deviceId] = $this->deviceName($deviceId);
            }
        }
        foreach ($impact as $row) {
            foreach (array_merge($row['element_devices'], $row['devices']) as $deviceId) {
                $names[$deviceId] = $this->deviceName($deviceId);
            }
        }
        foreach ($outages as $outage) {
            foreach (array_merge([$outage['device_id']], $outage['behind'], $outage['shared'], $outage['shared_with']) as $deviceId) {
                $names[$deviceId] = $this->deviceName($deviceId);
            }
        }
        foreach ($core as $entry) {
            $names[$entry['device_id']] = $this->deviceName($entry['device_id']);
        }

        $devices = count(array_filter($this->nodes, fn ($n) => $n['managed'] && isset($this->scope[$n['device_id']])));

        return [
            'device_names' => $names,
            'core' => ['devices' => $core, 'setting' => $this->coreSetting],
            'summary' => self::summarize($findings, $impact, $outages, $devices),
            'findings' => $findings,
            'impact' => $impact,
            'outages' => $outages,
        ];
    }

    /**
     * Summary cards of a (possibly filtered) result.
     */
    public static function summarize(array $findings, array $impact, array $outages, int $devices): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        $categories = array_fill_keys(array_values(array_unique(self::CHECKS)), 0);
        foreach ($findings as $finding) {
            $counts[$finding['severity']]++;
            $categories[$finding['category']] = ($categories[$finding['category']] ?? 0) + 1;
        }

        $down = [];
        foreach ($outages as $outage) {
            $down += array_fill_keys(array_merge([$outage['device_id']], $outage['behind'], $outage['shared']), true);
        }

        return [
            'devices' => $devices,
            'single_points' => count(array_filter($impact, fn ($row) => $row['network'] > 0)),
            'down' => count($down),
            'findings' => $counts,
            'categories' => $categories,
            'status' => $counts['critical'] ? 'critical' : ($counts['warning'] ? 'warning' : 'ok'),
        ];
    }

    /**
     * Core nodes of the network (configured or automatic), for the map.
     *
     * @return array<string, string> node id => 'configured', 'stp' or 'automatic'
     */
    public function coreNodes(): array
    {
        return $this->core;
    }

    // ---------------------------------------------------------------- graph

    /**
     * Nodes (monitored devices and unmanaged neighbours as on the L2 map), links collapsed per
     * port-channel, the core, the design and current graphs and the units of the link ports.
     */
    private function build(): void
    {
        foreach ($this->devices as $deviceId => $device) {
            $role = (string) ($device['role'] ?? DeviceRole::SWITCH);
            $this->nodes['d:' . $deviceId] = [
                'device_id' => (int) $deviceId,
                'name' => (string) (($device['display'] ?? '') ?: ($device['hostname'] ?? $deviceId)),
                'role' => $role,
                'managed' => true,
                'network' => in_array($role, self::NETWORK_ROLES, true),
                'up' => (bool) ($device['status'] ?? true),
            ];
        }

        // a neighbour that is monitored but not analyzed (disabled) still passes traffic: keep it as unmanaged
        $links = array_map(function (array $link): array {
            if (! empty($link['remote_device_id']) && ! isset($this->devices[(int) $link['remote_device_id']])) {
                $link['remote_device_id'] = 0;
                $link['remote_port_id'] = null;
            }

            return $link;
        }, $this->links);

        // unmanaged neighbours seen on a single port (phones, PCs, APs) can only be cut off with the device
        // they hang off: count them on that device instead of keeping thousands of graph nodes
        $seenOn = [];
        foreach ($links as $index => $link) {
            if (empty($link['remote_device_id']) && isset($this->devices[(int) $link['local_device_id']])) {
                $at = (int) $link['local_device_id'] . ':' . (int) ($link['local_port_id'] ?? 0);
                $seenOn[self::neighborKey($link)][$at][] = $index;
            }
        }
        foreach ($seenOn as $ports) {
            if (count($ports) === 1) {
                [$deviceId, $portId] = array_map('intval', explode(':', (string) array_key_first($ports)));
                $this->leaves['d:' . $deviceId][] = $portId;
                foreach (reset($ports) as $index) {
                    unset($links[$index]);
                }
            }
        }
        unset($seenOn);

        $groups = [];
        foreach (L2Builder::uniqueLinks($links, $this->devices, $this->ports) as $index => $link) {
            $from = 'd:' . $link['a_device'];
            if ($link['b_device']) {
                $to = 'd:' . $link['b_device'];
            } else {
                $to = L2Builder::unmanagedNodeId($link);
                $this->nodes[$to] ??= [
                    'device_id' => null,
                    'name' => $link['b_host'] !== '' ? $link['b_host'] : 'unknown neighbor of ' . $this->nodes[$from]['name'] . ' ' . $this->portName((int) $link['a_port']),
                    'role' => DeviceRole::fromPlatform($link['b_platform'], $link['b_host']),
                    'managed' => false,
                    'network' => false,
                    'up' => true,
                ];
            }
            if ($from === $to) {
                continue; // a device reporting itself (stack ports)
            }

            // the members of a port-channel form one link, as on the map with "Collapse LAGs"
            $aParent = $link['a_port'] ? ($this->lagParent[$link['a_port']] ?? null) : null;
            $bParent = $link['b_port'] ? ($this->lagParent[$link['b_port']] ?? null) : null;
            if (strcmp($from, $to) > 0) {
                $link = L2Builder::swap($link);
                [$from, $to, $aParent, $bParent] = [$to, $from, $bParent, $aParent];
            }
            $key = $aParent || $bParent ? "lag|$from#" . ($aParent ?? '-') . "|$to#" . ($bParent ?? '-') : 'link|' . $index;
            $groups[$key]['from'] = $from;
            $groups[$key]['to'] = $to;
            $groups[$key]['members'][] = $link;
        }

        unset($links);
        $this->links = [];

        // stack member / line card of every link port; only devices whose links use several count
        $seen = [];
        foreach ($this->leaves as $node => $ports) {
            foreach ($ports as $portId) {
                if ($portId && ($unit = $this->portUnit($this->nodes[$node]['device_id'], $portId))) {
                    $this->units[$portId] = $unit;
                    $seen[$node][$unit['key']] = $unit['label'];
                }
            }
        }
        $degree = [];
        foreach ($groups as $group) {
            foreach ($group['members'] as $member) {
                foreach ([[$group['from'], $member['a_port']], [$group['to'], $member['b_port']]] as [$node, $portId]) {
                    if ($portId && $this->nodes[$node]['managed'] && ($unit = $this->portUnit($this->nodes[$node]['device_id'], (int) $portId))) {
                        $this->units[(int) $portId] = $unit;
                        $seen[$node][$unit['key']] = $unit['label'];
                    }
                }
            }
            $degree[$group['to']] = ($degree[$group['to']] ?? 0) + 1;
        }
        $this->deviceUnits = array_filter($seen, fn ($units) => count($units) >= 2);

        // the same for unmanaged neighbours on a single link that two names or protocols reported
        foreach ($groups as $key => $group) {
            if (! $this->nodes[$group['to']]['managed'] && ($degree[$group['to']] ?? 0) === 1) {
                $this->leaves[$group['from']][] = (int) ($group['members'][0]['a_port'] ?? 0);
                unset($this->nodes[$group['to']], $groups[$key]);
            }
        }

        $this->design = new Graph;
        $this->current = new Graph;
        foreach ($this->nodes as $id => $node) {
            $this->design->addNode($id);
            if ($node['up']) {
                $this->current->addNode($id);
            }
        }
        foreach ($groups as $key => $group) {
            $up = 0;
            foreach ($group['members'] as $member) {
                $up += L2Builder::isUp($this->port($member['a_port']), $this->port($member['b_port'])) ? 1 : 0;
            }
            $this->edges[$key] = ['key' => $key, 'from' => $group['from'], 'to' => $group['to'], 'members' => $group['members'], 'up' => $up];
            $this->design->addEdge($group['from'], $group['to'], $key);
            if ($up && $this->nodes[$group['from']]['up'] && $this->nodes[$group['to']]['up']) {
                $this->current->addEdge($group['from'], $group['to'], $key);
            }
        }

        $network = [];
        foreach ($this->nodes as $id => $node) {
            if ($node['managed'] && $node['network']) {
                $network[$id] = true;
            }
        }
        $priorities = [];
        foreach ($this->stpPriority as $deviceId => $priority) {
            $priorities['d:' . $deviceId] = (int) $priority;
        }
        $this->core = CoreFinder::find($this->design, $network, $this->configuredCore(), $priorities);
        foreach ($this->design->components() as $index => $component) {
            foreach ($component as $node) {
                $this->part[$node] = $index;
            }
        }

        $this->design->addNode(self::CORE);
        $this->current->addNode(self::CORE);
        foreach (array_keys($this->core) as $node) {
            $this->design->addEdge(self::CORE, $node, 'core|' . $node);
            if ($this->nodes[$node]['up']) {
                $this->current->addEdge(self::CORE, $node, 'core|' . $node);
            }
        }
    }

    /**
     * Unmanaged neighbour of a links row: its short name, or the local port for missing and
     * factory default names (as L2Builder::unmanagedNodeId() separates them).
     */
    private static function neighborKey(array $link): string
    {
        $host = strtolower(trim((string) ($link['remote_hostname'] ?? '')));
        $short = L2Builder::shortHost($host);
        if ($host === '' || in_array($host, L2Builder::GENERIC_HOSTNAMES, true) || in_array($short, L2Builder::GENERIC_HOSTNAMES, true)) {
            return 'port:' . (int) $link['local_device_id'] . ':' . (int) ($link['local_port_id'] ?? 0) . ':' . InterfaceName::normalize($link['remote_port'] ?? '');
        }

        return 'host:' . $short;
    }

    /**
     * Core devices from the settings: "Core devices", else the designated STP root bridges.
     *
     * @return array<string, true> node ids
     */
    private function configuredCore(): array
    {
        foreach (['redundancy_core', 'stp_preferred_roots'] as $setting) {
            $wanted = array_map('strtolower', $this->settings->list($setting));
            if (! $wanted) {
                continue;
            }

            $result = [];
            foreach ($this->devices as $deviceId => $device) {
                $names = array_map('strtolower', array_filter([
                    (string) $deviceId,
                    (string) ($device['hostname'] ?? ''),
                    (string) ($device['sysName'] ?? ''),
                    (string) ($device['display'] ?? ''),
                ]));
                if (array_intersect($names, $wanted)) {
                    $result['d:' . $deviceId] = true;
                }
            }
            if ($result) {
                $this->coreSetting = $setting;

                return $result;
            }
        }

        return [];
    }

    /**
     * Stack member or line card of a port: from the inventory when the device maps its ports
     * there, else from the interface name (switches and routers only).
     *
     * @return array{key: string, label: string}|null
     */
    private function portUnit(int $deviceId, int $portId): ?array
    {
        $port = $this->ports[$portId] ?? null;
        if (! $port) {
            return null;
        }
        if (isset($this->entityUnits[$deviceId])) {
            return $this->entityUnits[$deviceId][(int) ($port['ifIndex'] ?? 0)] ?? null;
        }
        if (! in_array($this->nodes['d:' . $deviceId]['role'] ?? '', self::NAMED_UNIT_ROLES, true)) {
            return null;
        }

        return InterfaceName::unit(InterfaceName::label($port), (string) ($this->devices[$deviceId]['os'] ?? ''));
    }

    // ---------------------------------------------------------------- single points of failure

    /**
     * Devices and links whose failure cuts monitored devices off the core. Every one of them is a
     * row of the failure impact table; those that cut off switches, routers or firewalls are
     * findings too (a port-channel only when one of its cables is all that is left, see checkLost).
     *
     * @param  array<string, array{0: array<int, string>, 1: int}>  $losses  see vertexLosses()
     * @return array<int, array> impact rows
     */
    private function checkSinglePoints(array $design, array $losses): array
    {
        $rows = [];
        foreach ($losses as $node => [$lost, $pieces]) {
            $onlyCore = $pieces > 0;
            $row = $this->impactRow($this->nodes[$node]['managed'] ? 'device' : 'unmanaged', $this->nodeName($node),
                $this->nodes[$node]['managed'] ? [$this->nodes[$node]['device_id']] : [], $lost, $node);
            if (! $row) {
                continue;
            }
            $row['fail'] = $node;
            $rows[] = $row;
            if ($row['network'] === 0) {
                continue; // only end devices (APs, servers, phones) behind it
            }

            $name = $this->nodeName($node);
            $chosen = $onlyCore && $this->core[$node] !== 'automatic'; // configured or the spanning tree root
            $this->add(new Finding(
                $this->severity($row['network']), 'spof_device',
                'single point of failure: ' . $name . ($this->nodes[$node]['managed'] ? '' : ' (unmanaged neighbor)'),
                ($chosen ? "$name is the only core device of this part of the network. " : '')
                . ($onlyCore
                    ? "If $name fails, the network splits into $pieces parts and " . $this->lostText($lost, $node) . ' are cut off from the largest of them: '
                    : "If $name fails, " . $this->lostText($lost, $node) . ' ' . $this->lose($lost, $node) . ' path to the core: ')
                . $this->nameList($lost) . '.',
                $chosen
                    ? 'Add a second core switch and connect the switches below to both, or set the core devices in the plugin settings if this is not the core.'
                    : "Give these devices a second path that avoids $name, e.g. an uplink from " . $this->nodeName($this->firstNetwork($lost))
                    . ' to another switch, or a link to a neighbor with its own path to the core.',
                null, array_merge($row['element_devices'], $row['devices']),
            ));
        }

        foreach ($design['bridges'] as $edgeKey => $child) {
            if (! isset($this->edges[$edgeKey])) {
                continue; // link of the virtual core node
            }
            $edge = $this->edges[$edgeKey];
            $lost = self::subtrees($design, [$child]);
            $ends = $this->edgeDevices($edge);
            $row = $this->impactRow(count($edge['members']) > 1 ? 'port-channel' : 'link', $this->edgeLabel($edge, (string) $child), $ends, $lost);
            if (! $row) {
                continue;
            }
            $row['fail'] = $this->edgeRef($edge);
            $rows[] = $row;
            if ($row['network'] === 0 || count($edge['members']) > 1) {
                continue; // the members of a port-channel back each other up
            }

            $name = $this->nodeName($child);
            $this->add(new Finding(
                $this->severity($row['network']), 'spof_link',
                'single link: ' . $this->edgeLabel($edge, (string) $child),
                "$name has no other path to the core: if this link fails, " . $this->lostText($lost) . ' ' . $this->lose($lost) . ' path: ' . $this->nameList($lost) . '.',
                "Add a second uplink from $name, ideally to another switch, or bundle a second cable into a port-channel.",
                null, array_merge($ends, $row['devices']), $this->edgePorts($edge),
            ));
        }

        return $rows;
    }

    /**
     * Nodes that lose the core when a cut vertex fails. When the only core device of a part fails,
     * the largest remaining piece is what keeps working.
     *
     * @return array<string, array{0: array<int, string>, 1: int}> node => [cut off nodes, pieces left (only core devices)]
     */
    private function vertexLosses(array $design): array
    {
        $losses = [];
        foreach ($design['vertices'] as $node => $children) {
            $onlyCore = isset($this->core[$node])
                && count(array_filter(array_keys($this->core), fn ($core) => $this->part[$core] === $this->part[$node])) === 1;
            $losses[(string) $node] = $onlyCore ? $this->outsideLargestPiece((string) $node) : [self::subtrees($design, $children), 0];
        }

        return $losses;
    }

    /**
     * Nodes outside the largest piece (most network devices) left when $node fails.
     *
     * @return array{0: array<int, string>, 1: int} cut off nodes, number of pieces
     */
    private function outsideLargestPiece(string $node): array
    {
        $pieces = [];
        $seen = [$node => true, self::CORE => true];
        foreach ($this->design->neighbours($node) as $start) {
            if (isset($seen[$start])) {
                continue;
            }
            $seen[$start] = true;
            $piece = [$start];
            for ($i = 0; $i < count($piece); $i++) {
                foreach ($this->design->neighbours($piece[$i]) as $next) {
                    if (! isset($seen[$next])) {
                        $seen[$next] = true;
                        $piece[] = $next;
                    }
                }
            }
            $pieces[] = $piece;
        }

        $weight = fn (array $piece) => [
            count(array_filter($piece, fn ($n) => $this->nodes[$n]['managed'] && $this->nodes[$n]['network'])),
            count(array_filter($piece, fn ($n) => $this->nodes[$n]['managed'])),
            count($piece),
        ];
        usort($pieces, fn ($a, $b) => $weight($b) <=> $weight($a));

        return [array_map('strval', array_merge(...array_slice($pieces, 1))), count($pieces)];
    }

    /**
     * Devices cut off by a cut vertex that reach it over several separate links: redundant
     * cabling to one switch protects against a cable or port failure, not against the switch.
     *
     * @param  array<string, array{0: array<int, string>, 1: int}>  $losses  see vertexLosses()
     */
    private function checkSameDevice(array $losses): void
    {
        foreach ($losses as $node => [$cutOff]) {
            $parts = [];
            $devices = [];
            $ports = [];
            foreach ($cutOff as $lost) {
                if (! $this->nodes[$lost]['managed'] || ! $this->nodes[$lost]['network']) {
                    continue;
                }
                $local = [];
                foreach ($this->design->edgesOf($lost) as [$next, $edgeKey]) {
                    if ($next === $node && isset($this->edges[$edgeKey])) {
                        $local[] = $this->sidePorts($this->edges[$edgeKey], $lost);
                    }
                }
                if (count($local) < 2) {
                    continue;
                }
                $parts[] = $this->nodeName($lost) . ' (' . implode(', ', array_map(fn ($p) => $this->portList($p), $local)) . ')';
                $devices[] = $this->nodes[$lost]['device_id'];
                $ports = array_merge($ports, ...$local);
            }
            if (! $parts) {
                continue;
            }

            $name = $this->nodeName($node);
            $this->add(new Finding(
                Finding::INFO, 'uplinks_same_device',
                'several links to the same switch: ' . $name,
                implode(', ', array_slice($parts, 0, self::LIST_NAMES)) . (count($parts) > self::LIST_NAMES ? ' ...' : '')
                . (count($parts) > 1 ? ' connect' : ' connects') . " to $name over several separate links but "
                . (count($parts) > 1 ? 'have' : 'has') . " no path that avoids $name: the extra links protect against a cable or port failure, not against the loss of $name.",
                "Move one of the links to another switch to survive the loss of $name, or bundle them into a port-channel to use their bandwidth "
                . '(spanning tree blocks all but one separate link).',
                null, array_merge($this->nodes[$node]['managed'] ? [$this->nodes[$node]['device_id']] : [], $devices), $ports,
            ));
        }
    }

    // ---------------------------------------------------------------- stack members and line cards

    /**
     * Devices whose links use several stack members or line cards: what loses the core when one
     * of them fails. A part that hangs off a single cable is a single link finding already; the
     * findings here are port-channels and sets of links that all use the failing unit.
     */
    private function checkUnits(): void
    {
        foreach ($this->deviceUnits as $node => $units) {
            foreach ($units as $unitKey => $label) {
                $failing = [];
                foreach ($this->design->edgesOf($node) as [, $edgeKey]) {
                    if (isset($this->edges[$edgeKey]) && $this->onUnit($this->edges[$edgeKey], $node, $unitKey)) {
                        $failing[$edgeKey] = true;
                    }
                }
                if (! $failing) {
                    continue;
                }

                $starts = [$node];
                foreach (array_keys($failing) as $edgeKey) {
                    $starts[] = $this->other($this->edges[$edgeKey], $node);
                }
                $explored = [];
                $regions = [];
                foreach ($starts as $start) {
                    if (isset($explored[$start])) {
                        continue;
                    }
                    [$reached, $visited] = $this->reach($this->design, $start, $failing, []);
                    $explored += array_fill_keys($visited, true);
                    if (! $reached) {
                        $regions[] = $visited;
                    }
                }

                $this->reportUnit($node, $unitKey, $label, $failing, $regions);
            }
        }
    }

    /**
     * Do all members of a link leave $node from the given unit? A member whose port is unknown
     * may use another unit.
     */
    private function onUnit(array $edge, string $node, string $unitKey): bool
    {
        $side = $edge['from'] === $node ? 'a_port' : 'b_port';
        foreach ($edge['members'] as $member) {
            if (($this->units[(int) $member[$side]]['key'] ?? null) !== $unitKey) {
                return false;
            }
        }

        return (bool) $edge['members'];
    }

    /**
     * One finding per failing unit with the parts that lose the core.
     *
     * @param  array<string, true>  $failing  links that fail with the unit
     * @param  array<int, array<int, string>>  $regions  node sets that lose the core
     */
    private function reportUnit(string $node, string $unitKey, string $label, array $failing, array $regions): void
    {
        $name = $this->nodeName($node);
        $sentences = [];
        $devices = [$this->nodes[$node]['device_id']];
        $lostAll = [];
        $ports = [];
        foreach ($regions as $region) {
            $in = array_flip($region);
            $boundary = array_values(array_filter(array_keys($failing), fn ($key) => isset($in[$this->edges[$key]['from']]) !== isset($in[$this->edges[$key]['to']])));
            if (! $boundary || (count($boundary) === 1 && count($this->edges[$boundary[0]]['members']) === 1)) {
                continue; // no path to the core anyway, or hangs off one cable: a single link finding already
            }
            $others = array_values(array_filter($region, fn ($n) => $n !== $node));
            if (! isset($in[$node]) && ! array_filter($others, fn ($n) => $this->nodes[$n]['managed'] && $this->nodes[$n]['network'])) {
                continue; // only end devices
            }

            $links = [];
            foreach ($boundary as $edgeKey) {
                $edge = $this->edges[$edgeKey];
                $local = $this->sidePorts($edge, $node);
                $links[] = $this->portList($local) . ' to ' . $this->nodeName($this->other($edge, $node));
                $ports = array_merge($ports, $local);
            }
            if (isset($in[$node])) {
                $own = count(array_filter($this->leaves[$node] ?? [], fn ($port) => ($this->units[$port]['key'] ?? null) !== $unitKey));
                $sentences[] = "All links that connect $name to the core leave from $label (" . implode(', ', $links) . "): if $label fails, the rest of $name"
                    . ' loses its path to the core' . $this->behindText($others, $this->unmanagedCount($others) + $own) . '.';
            } else {
                $first = $this->firstNetwork($others) ?? $others[0];
                $behind = array_values(array_filter($others, fn ($n) => $n !== $first));
                $sentences[] = $this->nodeName($first) . " connects to $name only through $label (" . implode(', ', $links) . '): it loses its path to the core if '
                    . "$label fails" . $this->behindText($behind, $this->unmanagedCount($behind) + count($this->leaves[$first] ?? [])) . '.';
            }
            $lostAll = array_merge($lostAll, $others);
        }
        if (! $sentences) {
            return;
        }

        foreach ($lostAll as $lost) {
            if ($this->nodes[$lost]['managed']) {
                $devices[] = $this->nodes[$lost]['device_id'];
            }
        }
        $this->add(new Finding(
            Finding::WARNING, 'unit_spof',
            "single point of failure inside $name: $label",
            implode(' ', $sentences),
            'Spread the uplinks and the members of each port-channel over at least two stack members or line cards, so that losing one of them keeps a path.',
            null, $devices, $ports,
        ));
    }

    // ---------------------------------------------------------------- redundancy lost now

    /**
     * Devices that have a second path by design but depend on a single device or link now,
     * because a link or device on the other path is down; and single uplinks that are
     * port-channels running on one cable. Findings are grouped by what is down.
     */
    private function checkLost(array $design, array $current): void
    {
        $groups = [];
        foreach ($current['vertices'] as $node => $children) {
            $island = self::subtrees($current, $children);
            $exposed = array_values(array_filter($island, fn ($x) => $this->nodes[$x]['managed'] && ! $this->cutByDesign($design, $node, null, $x)));
            if ($exposed) {
                $this->lostGroup($groups, $island, $exposed, $node, null, $this->nodeName($node));
            }
        }

        foreach ($current['bridges'] as $edgeKey => $child) {
            if (! isset($this->edges[$edgeKey])) {
                continue;
            }
            $edge = $this->edges[$edgeKey];
            $island = self::subtrees($current, [$child]);
            $exposed = array_values(array_filter($island, fn ($x) => $this->nodes[$x]['managed'] && ! $this->cutByDesign($design, null, $edgeKey, $x)));
            if ($exposed) {
                $this->lostGroup($groups, $island, $exposed, null, $edgeKey, 'the link ' . $this->edgeLabel($edge, (string) $child));
            } elseif (count($edge['members']) > 1 && $edge['up'] === 1) {
                $this->lostMembers($edge, $island);
            }
        }

        foreach ($groups as $group) {
            // name the devices whose own second path broke, count the ones behind them
            $exposed = array_keys($group['exposed']);
            $named = array_values(array_filter($exposed, fn ($n) => isset($group['ends'][$n]))) ?: $exposed;
            $behind = array_values(array_diff($exposed, $named));
            $plural = count(array_filter($named, fn ($n) => $this->nodes[$n]['managed'])) > 1;
            $this->add(new Finding(
                Finding::WARNING, 'redundancy_lost',
                'redundancy lost: ' . $this->nameList($named, 3),
                $this->nameList($named) . ($plural ? ' normally have' : ' normally has') . ' a second path to the core, but it is down: '
                . implode('; ', array_slice($group['broken'], 0, 10)) . '. Single points of failure now: ' . implode('; ', array_unique($group['points']))
                . ($behind ? '. Behind ' . ($plural ? 'them' : 'it') . ': ' . $this->nameList($behind) : '') . '.',
                'Restore the link or device that is down. LibreNMS removes links that LLDP/CDP no longer sees at the next discovery, '
                . 'so this finding disappears then even if the link stays down.',
                null, array_merge(array_map(fn ($n) => $this->nodes[$n]['device_id'], $exposed), $group['devices']), $group['ports'],
            ));
        }
    }

    /**
     * Add exposed devices to the group of what is down on their other path: the links that
     * leave their island (the part cut off when the single point fails) toward the core.
     *
     * @param  array<int, string>  $island  nodes that lose the core in the current graph
     * @param  array<int, string>  $exposed  monitored nodes of the island that do not by design
     */
    private function lostGroup(array &$groups, array $island, array $exposed, ?string $node, ?string $edgeKey, string $point): void
    {
        $in = array_flip($island);
        $blocked = $in + ($node !== null ? [$node => true] : []);
        $toCore = []; // nodes outside the island known to reach the core without it
        $causes = []; // what is down: a device at the far end, or the link itself
        $ends = [];
        foreach ($island as $x) {
            foreach ($this->design->edgesOf($x) as [$y, $key]) {
                if (isset($blocked[$y]) || $key === $edgeKey || ! isset($this->edges[$key])) {
                    continue;
                }
                if (! isset($toCore[$y])) {
                    [$reached, $visited] = $this->reach($this->design, $y, $edgeKey !== null ? [$edgeKey => true] : [], $blocked);
                    if (! $reached) {
                        continue; // leads into a dead end, not toward the core
                    }
                    $toCore += array_fill_keys($visited, true);
                }
                $causes[$this->nodes[$y]['up'] ? 'link ' . $key : 'device ' . $y] = [$key, $x];
                $ends[$x] = true;
            }
        }
        ksort($causes);
        $cause = implode(',', array_keys($causes));

        $group = &$groups[$cause];
        $group ??= ['exposed' => [], 'ends' => [], 'points' => [], 'broken' => [], 'devices' => [], 'ports' => []];
        $group['exposed'] += array_fill_keys($exposed, true);
        $group['ends'] += $ends;
        $group['points'][] = $point;
        if (! $group['broken']) {
            foreach ($causes as $what => [$key, $x]) {
                $edge = $this->edges[$key];
                if (str_starts_with($what, 'device ')) {
                    $down = substr($what, 7);
                    $group['broken'][] = $this->nodeName($down) . ' is down';
                    $group['devices'][] = $this->nodes[$down]['device_id'];
                } else {
                    $group['broken'][] = $this->edgeLabel($edge, $x) . ' (' . $this->edgeState($edge) . ')';
                    $group['devices'] = array_merge($group['devices'], $this->edgeDevices($edge));
                    $group['ports'] = array_merge($group['ports'], $this->edgePorts($edge));
                }
            }
            if (! $causes) {
                $group['broken'][] = 'a link or device that is down';
            }
        }
        unset($group);
    }

    /**
     * A port-channel that is the only path of the devices behind it and runs on one cable.
     *
     * @param  array<int, string>  $island  nodes behind it
     */
    private function lostMembers(array $edge, array $island): void
    {
        $managed = array_values(array_filter($island, fn ($n) => $this->nodes[$n]['managed']));
        if (! array_filter($managed, fn ($n) => $this->nodes[$n]['network'])) {
            return;
        }

        $down = [];
        $ports = [];
        foreach ($edge['members'] as $member) {
            if (! L2Builder::isUp($this->port($member['a_port']), $this->port($member['b_port']))) {
                $down[] = $member['a_port']
                    ? $this->nodeName($edge['from']) . ' ' . $this->portName((int) $member['a_port'])
                    : $this->nodeName($edge['to']) . ' ' . $this->portName((int) $member['b_port']);
                $ports = array_merge($ports, array_filter([$member['a_port'], $member['b_port']]));
            }
        }

        $this->add(new Finding(
            Finding::WARNING, 'redundancy_lost',
            'port-channel down to one link: ' . $this->edgeLabel($edge),
            $this->nameList($managed) . (count($managed) > 1 ? ' depend' : ' depends') . ' on this port-channel alone, and it runs on 1 of its '
            . count($edge['members']) . ' links (down: ' . implode(', ', $down) . '): one more cable or port failure cuts '
            . (count($managed) > 1 ? 'them' : 'it') . ' off.',
            'Repair the down members (cabling, transceivers, LACP state).',
            null, array_merge($this->edgeDevices($edge), array_map(fn ($n) => $this->nodes[$n]['device_id'], $managed)), $ports,
        ));
    }

    /**
     * Does the failure of $node (or of the edge $edgeKey) cut $x off the core by design?
     */
    private function cutByDesign(array $design, ?string $node, ?string $edgeKey, string $x): bool
    {
        $tin = $design['tin'][$x] ?? null;
        if ($tin === null) {
            return false;
        }
        $children = $node !== null ? ($design['vertices'][$node] ?? []) : (isset($design['bridges'][$edgeKey]) ? [$design['bridges'][$edgeKey]] : []);
        foreach ($children as $child) {
            if ($design['tin'][$child] <= $tin && $tin <= $design['tout'][$child]) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- outages

    /**
     * Current outages: the top-most down devices (with an up neighbour, possibly through an
     * unmanaged switch, whatever the state of the port: a device that loses power takes its link
     * down too) and the down devices only reachable through them.
     *
     * @return array<int, array> outages, largest first
     */
    private function checkOutages(): array
    {
        $up = [];
        $down = [];
        foreach ($this->nodes as $id => $node) {
            if ($node['managed']) {
                if ($node['up']) {
                    $up[$id] = true;
                } else {
                    $down[$id] = true;
                }
            }
        }
        if (! $down) {
            return [];
        }

        // what the poller still reaches: up devices and the unmanaged neighbours behind their up ports
        $upSide = $up;
        $top = [];
        $queue = array_keys($up);
        for ($i = 0; $i < count($queue); $i++) {
            $node = $queue[$i];
            foreach ($this->design->edgesOf($node) as [$next, $edgeKey]) {
                if (isset($upSide[$next]) || ! isset($this->edges[$edgeKey])) {
                    continue;
                }
                if (isset($down[$next])) {
                    $top[$next] = true;
                } elseif (! $this->nodes[$next]['managed']) {
                    $upSide[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        // devices behind each top-most failure, remembering up to three of them per device
        $sources = [];
        $queue = [];
        foreach (array_keys($top) as $node) {
            $sources[$node] = [$node => true];
            $queue[] = $node;
        }
        for ($i = 0; $i < count($queue); $i++) {
            $node = $queue[$i];
            foreach ($this->design->edgesOf($node) as [$next]) {
                if ($next === self::CORE || isset($upSide[$next]) || isset($top[$next])) {
                    continue;
                }
                $added = false;
                foreach (array_keys($sources[$node]) as $source) {
                    if (! isset($sources[$next][$source]) && count($sources[$next] ?? []) < 3) {
                        $sources[$next][$source] = true;
                        $added = true;
                    }
                }
                if ($added) {
                    $queue[] = $next;
                }
            }
        }

        $outages = [];
        foreach (array_keys($top) as $node) {
            $behind = [];
            $shared = [];
            $sharedWith = [];
            $unmanaged = count($this->leaves[$node] ?? []);
            foreach ($sources as $other => $from) {
                if ($other === $node || ! isset($from[$node])) {
                    continue;
                }
                if (count($from) === 1) {
                    $unmanaged += ($this->nodes[$other]['managed'] ? 0 : 1) + count($this->leaves[$other] ?? []);
                }
                if (! $this->nodes[$other]['managed']) {
                    continue;
                }
                if (count($from) === 1) {
                    $behind[] = $other;
                } else {
                    $shared[] = $other;
                    $sharedWith += array_diff_key($from, [$node => true]);
                }
            }
            $outages[] = $this->outage($node, $behind, $shared, array_keys($sharedWith), $unmanaged, false);
        }

        // down devices without any up device next to them: a whole part of the network is down
        $rest = array_diff_key($down, $top, $sources);
        while ($rest) {
            $group = [];
            $queue = [array_key_first($rest)];
            $seen = [$queue[0] => true];
            for ($i = 0; $i < count($queue); $i++) {
                $node = $queue[$i];
                if (isset($down[$node])) {
                    $group[] = $node;
                    unset($rest[$node]);
                }
                foreach ($this->design->edgesOf($node) as [$next]) {
                    if (! isset($seen[$next]) && $next !== self::CORE && (isset($rest[$next]) || (! $this->nodes[$next]['managed'] && ! isset($upSide[$next])))) {
                        $seen[$next] = true;
                        $queue[] = $next;
                    }
                }
            }
            usort($group, fn ($a, $b) => [isset($this->core[$b]), $this->design->degree($b), $a] <=> [isset($this->core[$a]), $this->design->degree($a), $b]);
            $outages[] = $this->outage($group[0], array_slice($group, 1), [], [], 0, true);
        }

        usort($outages, fn ($a, $b) => [count($b['behind']) + count($b['shared']), $a['name']] <=> [count($a['behind']) + count($a['shared']), $b['name']]);

        return $outages;
    }

    /**
     * One outage row, plus a finding when other devices are down behind the device.
     *
     * @param  array<int, string>  $behind  down devices only reachable through $node
     * @param  array<int, string>  $shared  down devices reachable through $node and other top-most failures
     * @param  array<int, string>  $sharedWith  those other top-most failures
     */
    private function outage(string $node, array $behind, array $shared, array $sharedWith, int $unmanaged, bool $isolated): array
    {
        $name = $this->nodeName($node);
        $deviceId = $this->nodes[$node]['device_id'];
        $ids = fn (array $nodes) => array_map(fn ($n) => $this->nodes[$n]['device_id'], $nodes);

        if ($isolated && $behind) {
            $all = array_merge([$node], $behind);
            $this->add(new Finding(
                Finding::CRITICAL, 'outage',
                'outage: ' . count($all) . ' devices down, none of their neighbors is up: ' . $name,
                $this->nameList($all) . ' are down, and no monitored device connected to them is up, so the failure is further upstream: '
                . 'an unmanaged device, a link between sites or the path to the poller.',
                'Check the connection of this part of the network first; the devices themselves are probably fine.',
                null, $ids($all),
            ));
        } elseif (! $isolated && ($behind || $shared)) {
            $count = count($behind) + count($shared);
            $this->add(new Finding(
                Finding::CRITICAL, 'outage',
                "outage: $name is down, $count device" . ($count === 1 ? '' : 's') . ' behind it',
                trim("$name is down" . (isset($this->outageSince[$deviceId]) ? ' (since ' . gmdate('Y-m-d H:i', $this->outageSince[$deviceId]) . ' UTC)' : '') . '. '
                . ($behind ? 'Only reachable through it, and down too: ' . $this->nameList($behind) . '. ' : '')
                . ($shared ? 'Behind it and ' . $this->nameList($sharedWith) . ', and down too: ' . $this->nameList($shared) . '.' : '')),
                "Start with $name: the devices behind it are probably fine and only unreachable. To suppress their alerts, make $name their parent "
                . 'in the LibreNMS device dependencies.',
                null, array_merge([$deviceId], $ids($behind), $ids($shared)),
            ));
        }

        return [
            'device_id' => $deviceId,
            'name' => $name,
            'since' => $this->outageSince[$deviceId] ?? null,
            'behind' => $ids($behind),
            'shared' => $ids($shared),
            'shared_with' => $ids($sharedWith),
            'unmanaged' => $unmanaged,
            'isolated' => $isolated,
        ];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Breadth first search from $start that stops at the core, skipping the given edges and nodes.
     *
     * @param  array<string, true>  $skipEdges
     * @param  array<string, true>  $skipNodes
     * @return array{0: bool, 1: array<int, string>} reached the core, visited nodes (all of them when it did not)
     */
    private function reach(Graph $graph, string $start, array $skipEdges, array $skipNodes): array
    {
        $visited = [$start => true];
        $queue = [$start];
        for ($i = 0; $i < count($queue); $i++) {
            foreach ($graph->edgesOf($queue[$i]) as [$next, $edgeKey]) {
                if ($next === self::CORE && ! isset($skipEdges[$edgeKey])) {
                    return [true, $queue];
                }
                if (! isset($visited[$next]) && ! isset($skipEdges[$edgeKey]) && ! isset($skipNodes[$next])) {
                    $visited[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return [false, $queue];
    }

    /**
     * Nodes of the subtrees of some children in a Graph::cuts() result.
     *
     * @param  array<int, int|string>  $children
     * @return array<int, string>
     */
    private static function subtrees(array $cuts, array $children): array
    {
        $nodes = [];
        foreach ($children as $child) {
            for ($i = $cuts['tin'][$child]; $i <= $cuts['tout'][$child]; $i++) {
                $nodes[] = (string) $cuts['order'][$i];
            }
        }

        return $nodes;
    }

    /**
     * Failure impact row, null when no monitored device is cut off.
     *
     * @param  int[]  $elementDevices  devices of the failing element
     * @param  array<int, string>  $lost  nodes cut off
     */
    private function impactRow(string $kind, string $label, array $elementDevices, array $lost, ?string $failed = null): ?array
    {
        $devices = [];
        $network = 0;
        foreach ($lost as $node) {
            if ($this->nodes[$node]['managed']) {
                $devices[] = $this->nodes[$node]['device_id'];
                $network += $this->nodes[$node]['network'] ? 1 : 0;
            }
        }
        if (! $devices) {
            return null;
        }

        return [
            'kind' => $kind, 'label' => $label, 'element_devices' => $elementDevices, 'devices' => $devices,
            'network' => $network, 'unmanaged' => $this->unmanagedCount($lost, $failed), 'fail' => null,
        ];
    }

    /**
     * Unmanaged neighbours among the nodes and hanging off them (and off a failed device).
     *
     * @param  array<int, string>  $nodes
     */
    private function unmanagedCount(array $nodes, ?string $failed = null): int
    {
        $count = $failed !== null ? count($this->leaves[$failed] ?? []) : 0;
        foreach ($nodes as $node) {
            $count += ($this->nodes[$node]['managed'] ? 0 : 1) + count($this->leaves[$node] ?? []);
        }

        return $count;
    }

    /**
     * Warning when a failure cuts off at least the configured number of network devices.
     */
    private function severity(int $network): string
    {
        return $network >= $this->settings->int('redundancy_warn_devices', 1, 100000) ? Finding::WARNING : Finding::INFO;
    }

    /**
     * "3 monitored devices and 12 unmanaged neighbors".
     *
     * @param  array<int, string>  $nodes
     */
    private function lostText(array $nodes, ?string $failed = null, ?int $unmanaged = null): string
    {
        $managed = count(array_filter($nodes, fn ($n) => $this->nodes[$n]['managed']));
        $unmanaged ??= $this->unmanagedCount($nodes, $failed);
        $parts = [];
        if ($managed) {
            $parts[] = $managed . ' monitored device' . ($managed === 1 ? '' : 's');
        }
        if ($unmanaged) {
            $parts[] = $unmanaged . ' unmanaged neighbor' . ($unmanaged === 1 ? '' : 's');
        }

        return implode(' and ', $parts);
    }

    /**
     * "loses its" or "lose their", agreeing with lostText().
     *
     * @param  array<int, string>  $nodes
     */
    private function lose(array $nodes, ?string $failed = null): string
    {
        return count($nodes) + $this->unmanagedCount($nodes, $failed) - count(array_filter($nodes, fn ($n) => ! $this->nodes[$n]['managed'])) === 1
            ? 'loses its' : 'lose their';
    }

    /**
     * ", and with it 2 monitored devices behind it: acc3, acc4" (unmanaged neighbours only counted).
     *
     * @param  array<int, string>  $nodes
     */
    private function behindText(array $nodes, int $unmanaged): string
    {
        $managed = array_values(array_filter($nodes, fn ($n) => $this->nodes[$n]['managed']));
        if (! $managed && ! $unmanaged) {
            return '';
        }

        return $managed
            ? ', and with it ' . $this->lostText($nodes, null, $unmanaged) . ' behind it: ' . $this->nameList($nodes)
            : ' (with ' . $this->lostText($nodes, null, $unmanaged) . ' behind it)';
    }

    /**
     * Names of the monitored nodes (network devices first), shortened to the first few.
     *
     * @param  array<int, string>  $nodes
     */
    private function nameList(array $nodes, int $limit = self::LIST_NAMES): string
    {
        $managed = array_values(array_filter($nodes, fn ($n) => $this->nodes[$n]['managed']));
        usort($managed, fn ($a, $b) => [! $this->nodes[$a]['network'], $this->nodes[$a]['name']] <=> [! $this->nodes[$b]['network'], $this->nodes[$b]['name']]);
        $names = array_map(fn ($n) => $this->nodes[$n]['name'], array_slice($managed, 0, $limit));

        return implode(', ', $names) . (count($managed) > $limit ? ' and ' . (count($managed) - $limit) . ' more' : '');
    }

    /**
     * First monitored network device of a node list (closest to the failure: DFS order).
     *
     * @param  array<int, string>  $nodes
     */
    private function firstNetwork(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            if ($this->nodes[$node]['managed'] && $this->nodes[$node]['network']) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Ports of one end of a link: the members' ports on the side of $node.
     *
     * @return int[]
     */
    private function sidePorts(array $edge, string $node): array
    {
        $side = $edge['from'] === $node ? 'a_port' : 'b_port';
        $ports = [];
        foreach ($edge['members'] as $member) {
            if ($member[$side]) {
                $ports[] = (int) $member[$side];
            }
        }

        return $ports;
    }

    private function other(array $edge, string $node): string
    {
        return $edge['from'] === $node ? $edge['to'] : $edge['from'];
    }

    /**
     * @return int[] monitored devices at the ends of a link
     */
    private function edgeDevices(array $edge): array
    {
        return array_values(array_filter([$this->nodes[$edge['from']]['device_id'], $this->nodes[$edge['to']]['device_id']]));
    }

    /**
     * @return int[] all ports of a link
     */
    private function edgePorts(array $edge): array
    {
        $ports = [];
        foreach ($edge['members'] as $member) {
            $ports = array_merge($ports, array_filter([$member['a_port'], $member['b_port']]));
        }

        return array_values(array_unique(array_map('intval', $ports)));
    }

    /**
     * Reference of a link for the map's failure simulation: a port of it (p:<port_id>).
     */
    private function edgeRef(array $edge): string
    {
        $member = $edge['members'][0];
        $port = $member['a_port'] ?: $member['b_port'];

        return $port ? 'p:' . $port : $edge['from'];
    }

    /**
     * "acc1 Gi1/0/49 ↔ dist1 Gi1/0/5" ($first named first); port-channels by their aggregate names and member count.
     */
    private function edgeLabel(array $edge, ?string $first = null): string
    {
        $count = count($edge['members']);
        $sides = $first !== null && $first === $edge['to'] ? ['b', 'a'] : ['a', 'b'];

        return $this->endLabel($edge, $sides[0]) . ' ↔ ' . $this->endLabel($edge, $sides[1]) . ($count > 1 ? " ($count links)" : '');
    }

    private function endLabel(array $edge, string $side): string
    {
        $node = $side === 'a' ? $edge['from'] : $edge['to'];
        $ports = [];
        foreach ($edge['members'] as $member) {
            $portId = (int) $member[$side . '_port'];
            if ($portId && isset($this->lagParent[$portId])) {
                return $this->nodeName($node) . ' ' . $this->portName($this->lagParent[$portId]);
            }
            $ports[] = $portId ? $this->portName($portId) : InterfaceName::short($member[$side . '_name'] ?? '');
        }
        $ports = array_values(array_unique(array_filter($ports)));

        return $this->nodeName($node) . ($ports ? ' ' . implode('+', $ports) : '');
    }

    /**
     * Why a link is not usable now: a device at its end is down, or the link is down / shut down.
     */
    private function edgeState(array $edge): string
    {
        foreach ([$edge['from'], $edge['to']] as $node) {
            if (! $this->nodes[$node]['up']) {
                return $this->nodeName($node) . ' is down';
            }
        }
        foreach ($this->edgePorts($edge) as $portId) {
            if (($this->ports[$portId]['ifAdminStatus'] ?? 'up') === 'down') {
                return 'shut down on ' . $this->nodeName('d:' . (int) $this->ports[$portId]['device_id']) . ' ' . $this->portName($portId);
            }
        }

        return 'link down';
    }

    /**
     * "Po1 (Gi1/0/1, Gi1/0/2)" or "Gi1/0/1" for the ports of one end.
     *
     * @param  int[]  $ports
     */
    private function portList(array $ports): string
    {
        $names = array_map(fn ($p) => $this->portName($p), $ports);
        $parent = $ports && isset($this->lagParent[$ports[0]]) ? $this->portName($this->lagParent[$ports[0]]) : null;

        return $parent && count($ports) > 1 ? $parent . ' (' . implode(', ', $names) . ')' : implode(', ', $names);
    }

    private function nodeName(string $node): string
    {
        return $this->nodes[$node]['name'] ?? $node;
    }

    private function deviceName(int $deviceId): string
    {
        return $this->nodes['d:' . $deviceId]['name'] ?? "device #$deviceId";
    }

    /**
     * Short interface name (Gi1/0/1) of a port, "port #id" when the port row was not loaded.
     */
    private function portName(int $portId): string
    {
        $port = $this->ports[$portId] ?? null;

        return $port ? InterfaceName::short(InterfaceName::label($port)) : "port #$portId";
    }

    private function port(?int $portId): ?array
    {
        return $portId ? ($this->ports[$portId] ?? null) : null;
    }

    /**
     * @param  int[]  $devices
     */
    private function inScope(array $devices): bool
    {
        foreach ($devices as $deviceId) {
            if (isset($this->scope[(int) $deviceId])) {
                return true;
            }
        }

        return false;
    }

    private function enabled(string $code): bool
    {
        return ! isset($this->disabled[$code]) && ! isset($this->disabled[self::CHECKS[$code] ?? '']);
    }

    private function add(Finding $finding): void
    {
        if ($this->enabled($finding->code)) {
            $this->findings[] = $finding;
        }
    }
}
