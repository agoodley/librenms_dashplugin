<?php

namespace Agoodley\LibrenmsTopology\Topology;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Data\L3LayerRepository;
use Agoodley\LibrenmsTopology\Data\LoopEvidenceRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\History\MapHistory;
use Agoodley\LibrenmsTopology\History\PastMap;
use Agoodley\LibrenmsTopology\History\TimeRefused;
use Agoodley\LibrenmsTopology\Links\LinkAnalyzer;
use Agoodley\LibrenmsTopology\Redundancy\LeafNeighbors;
use Agoodley\LibrenmsTopology\Redundancy\PortUnits;
use Agoodley\LibrenmsTopology\Redundancy\RedundancyAnalyzer;
use Agoodley\LibrenmsTopology\Stp\LoopEvidence;
use Agoodley\LibrenmsTopology\Stp\StpAnalyzer;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Stp\SyslogPatterns;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\MemoryLimit;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * Glue between the repository (LibreNMS database) and the pure builders/analyzers.
 */
class TopologyService
{
    /** Above this many ports_stp rows the STP page analyzes one VLAN at a time (it would take too long). */
    private const STP_ALL_VLAN_LIMIT = 150000;

    /**
     * Memory the STP page takes once its links and devices are read, per spanning tree instance and ports_stp row it
     * analyzes, per device (ports, loop evidence, the result) and a margin: it analyzes every VLAN at once when that
     * fits in the memory PHP allows it, else one VLAN (fitsInMemory()). Measured on networks of 157 to 1,242 switches
     * with 25 and 50 PVST VLANs: PHP's 128M default holds about 8,000 instances.
     */
    private const STP_INSTANCE_BYTES = 5632;

    private const STP_PORT_BYTES = 1792;

    private const STP_DEVICE_BYTES = 28672;

    private const STP_MARGIN_BYTES = 8 * 1048576;

    /** Above this many ports_vlans rows on inter-switch ports the link page skips the VLAN checks. */
    private const LINK_VLAN_LIMIT = 1000000;

    private const L3_ROLES = [DeviceRole::ROUTER, DeviceRole::L3SWITCH, DeviceRole::FIREWALL];

    /** Port columns the redundancy analysis needs (it loads every link port of the network). */
    private const REDUNDANCY_PORT_COLUMNS = ['port_id', 'device_id', 'ifIndex', 'ifName', 'ifDescr', 'ifOperStatus', 'ifAdminStatus'];

    private ?MapHistory $mapHistory = null;

    /**
     * @param  HistoryRepository|null  $history  topology history: with a saved baseline, the redundancy analysis also
     *                                          knows the baseline links that LLDP/CDP no longer reports; the maps at
     *                                          another time read it (MapHistory)
     */
    public function __construct(
        private readonly TopologyRepository $repo,
        private readonly PluginSettings $settings,
        private readonly ?HistoryRepository $history = null,
    ) {
    }

    /**
     * The Layer 2 map; as of a time (at: links, port-channel members and spanning tree from the history's snapshot,
     * History\PastMap) or with the changes since a time (since, History\ChangeMarks).
     *
     * @param  array{device: ?int, depth: int, group: ?int, location: ?int, vlan: ?int, unmanaged: bool, lag: bool, isolated: bool,
     *     at?: ?int, since?: int|string|null}  $p
     *
     * @throws TimeRefused
     */
    public function l2(array $p): array
    {
        [$scope, $root] = $this->repo->scope($p);
        $past = ($p['at'] ?? null) !== null ? $this->mapHistory()->past((int) $p['at']) : null;
        $linksWhere = $past !== null ? $past->linksWhere(...) : $this->repo->linksWhere(...);
        // end devices (phones, servers, access points) are only noted at first: when the map has no room for
        // them they are drawn as one group per device and kind, else their rows are read again below
        $ends = [];
        $links = $linksWhere($scope, function (array $link) use (&$ends): bool {
            if (! self::endDeviceNeighbor($link)) {
                return true;
            }
            $group = &$ends[(int) $link['local_device_id']][self::neighborRole($link)];
            $group['rows'] = ($group['rows'] ?? 0) + 1;
            // one neighbour per name, as the map draws them (LLDP and CDP report the same phone)
            $group['neighbors'][RedundancyAnalyzer::neighborKey($link)] ??= trim((string) $link['remote_hostname']);
            $group['ports'][(int) $link['local_port_id']] = true;

            return false;
        });
        $ids = L2Builder::scopeDevices($links, $scope, $root, $root ? $p['depth'] : null);

        $linked = [];
        foreach ($links as $link) {
            $linked[(int) $link['local_device_id']] = ($linked[(int) $link['local_device_id']] ?? 0) + 1;
            if ($link['remote_device_id']) {
                $linked[(int) $link['remote_device_id']] = ($linked[(int) $link['remote_device_id']] ?? 0) + 1;
            }
        }
        foreach ($ends as $deviceId => $groups) {
            $linked[$deviceId] = ($linked[$deviceId] ?? 0) + array_sum(array_column($groups, 'rows'));
        }
        if (! $root && ! $p['isolated']) {
            $ids = array_values(array_filter($ids, fn ($id) => isset($linked[$id])));
        }

        [$ids, $total, $truncated] = $this->limit($ids, $root, $linked);
        $idSet = array_flip($ids);
        $links = array_filter($links, fn ($l) => isset($idSet[(int) $l['local_device_id']]));
        $ends = $p['unmanaged'] ? array_intersect_key($ends, $idSet) : [];
        if ($ends) {
            $nodes = count($ids) + count(array_filter($links, fn ($l) => ! $l['remote_device_id']));
            foreach ($ends as $groups) {
                foreach ($groups as $group) {
                    $nodes += count($group['neighbors']);
                }
            }
            if ($nodes <= $this->maxNodes()) {
                // the same devices' rows again, so that they merge back into the order of the rows (the default layout)
                $links += $linksWhere($scope, fn (array $link) => isset($idSet[(int) $link['local_device_id']]) && self::endDeviceNeighbor($link));
                ksort($links);
                $ends = [];
            } else {
                $ends = $this->endDeviceGroups($ends);
            }
        }
        $links = array_values($links);

        $linkPorts = [];
        foreach ($links as $link) {
            $linkPorts[] = (int) $link['local_port_id'];
            $linkPorts[] = (int) $link['remote_port_id'];
        }
        $lag = $past !== null ? $past->lagParents($ids) : $this->repo->lagParents($ids);
        $aggregates = array_values(array_filter(array_unique(array_map(fn ($p) => $lag[$p] ?? 0, $linkPorts))));
        $linkPorts = array_values(array_filter(array_unique(array_merge($linkPorts, $aggregates))));
        // all members of those aggregates, with or without a link: whether an aggregate is only one edge
        $members = array_keys(array_intersect($lag, $aggregates));

        // the overlay shows one instance per device: the one carrying the selected VLAN
        $stp = $past !== null ? $past->stp($ids, $p['vlan'], $lag) : $this->singleInstanceIndex($this->repo->stpKeys($ids), $p['vlan'], $lag);
        $rootPorts = array_filter(array_map(fn ($id) => $stp->rootPortId($id, $p['vlan']), $ids));
        $portIds = array_merge($linkPorts, $rootPorts, $members);
        $ports = $this->repo->ports($portIds);
        if ($past !== null) {
            // ports LibreNMS no longer has keep the name the history stored
            $ports = $past->withStoredPorts($ports, $portIds);
        }

        $builder = new L2Builder(
            $this->repo->devices($ids),
            $links,
            $ports,
            $this->repo->portVlans($linkPorts),
            $lag,
            $stp,
            $this->repo->deviceVlans($ids),
            $ends,
        );

        $result = $builder->build([
            'vlan' => $p['vlan'],
            'unmanaged' => $p['unmanaged'],
            'collapseLag' => $p['lag'],
            'root' => $root,
        ]);
        $result['scope'] = $this->scopeInfo($p, $root, $total, count($ids), $truncated);

        return $this->atTime('l2', $p, $result, $past);
    }

    /**
     * The Layer 3 map; as of a time (at: OSPF adjacencies and BGP sessions from the history's snapshot, the subnets
     * as they are now, the overlays the history does not record left out) or with the changes since a time (since).
     *
     * @param  array{device: ?int, depth: int, group: ?int, location: ?int, vrf: ?string, stubs: bool, ospf: bool, bgp: bool, ospfv3?: bool,
     *     isis?: bool, ipsec?: bool, mpls?: bool, pw?: bool, ipv6: bool, all_devices: bool, at?: ?int, since?: int|string|null}  $p
     *
     * @throws TimeRefused
     */
    public function l3(array $p): array
    {
        [$scope, $root] = $this->repo->scope($p);
        $past = ($p['at'] ?? null) !== null ? $this->mapHistory()->past((int) $p['at']) : null;
        $addresses = $this->repo->addresses($scope, $p['ipv6']);

        $withAddresses = array_values(array_unique(array_map(fn ($a) => (int) $a['device_id'], $addresses)));
        if ($root && ! in_array($root, $withAddresses, true)) {
            $withAddresses[] = $root;
        }
        $devices = $this->repo->devices($withAddresses);
        if (! $p['all_devices']) {
            $devices = array_filter($devices, fn ($d, $id) => $id === $root || in_array($d['role'], self::L3_ROLES, true), ARRAY_FILTER_USE_BOTH);
        }

        // limit very large maps to the devices with the most interfaces
        $counts = [];
        foreach ($addresses as $address) {
            $counts[(int) $address['device_id']] = ($counts[(int) $address['device_id']] ?? 0) + 1;
        }
        $total = count($devices);
        $truncated = false;
        if (! $root && $total > $this->maxNodes()) {
            $ids = array_keys($devices);
            usort($ids, fn ($a, $b) => ($counts[$b] ?? 0) <=> ($counts[$a] ?? 0));
            $devices = array_intersect_key($devices, array_flip(array_slice($ids, 0, $this->maxNodes())));
            $truncated = true;
        }

        $ids = array_keys($devices);
        $mapAddresses = array_values(array_filter($addresses, fn ($a) => isset($devices[(int) $a['device_id']])));
        $ospf = $p['ospf'] ? ($past !== null ? $past->ospfNeighbors($ids) : $this->repo->ospfNeighbors($ids)) : [];
        $bgp = $p['bgp'] ? ($past !== null ? $past->bgpPeers($ids) : $this->repo->bgpPeers($ids)) : [];

        // overlay layers from modules that are often off: loaded when the scope has rows, offered when the drawn devices do;
        // a map of the past has none (the history does not record them)
        $layerRepo = $this->repo->sibling(L3LayerRepository::class);
        $perDevice = $layerRepo->countsByDevice($ids);
        $loaded = $past !== null ? [] : array_values(array_filter(L3Builder::LAYERS, fn ($layer) => ($p[$layer] ?? MapParams::L3_LAYERS[$layer]) && $perDevice[$layer]));
        $layers = $layerRepo->load($ids, $loaded);

        // far ends among the other devices the user may see become stubs, the rest external nodes
        $builder = new L3Builder($devices, $mapAddresses, $ospf, $bgp, $layers);
        $known = $layerRepo->resolve($builder->lookups());
        $known['devices'] = $this->repo->devices(array_values(array_diff($known['deviceIds'], $ids)), false);

        $result = $builder->withKnown($known)->build([
            'hideStubs' => ! $p['stubs'],
            'p2pPrefix4' => $this->settings->int('l3_p2p_prefix', 8, 32),
            'p2pPrefix6' => 126,
            'exclude' => $this->settings->list('l3_exclude'),
            'vrf' => $p['vrf'],
            'ospf' => $p['ospf'],
            'bgp' => $p['bgp'],
            'root' => $root,
            'depth' => $root ? $p['depth'] : null,
            'maxNodes' => $this->maxNodes(),
        ] + array_fill_keys(L3Builder::LAYERS, true));

        $drawn = [];
        foreach ($result['nodes'] as $node) {
            if ($node['type'] === 'device' && empty($node['outside'])) {
                $drawn[$node['device_id']] = true;
            }
        }
        $result['layers'] = [];
        $today = [];
        foreach (L3Builder::LAYERS as $layer) {
            $available = array_sum(array_intersect_key($perDevice[$layer], $drawn));
            $today[$layer] = $available;
            // a map of the past offers no toggle for them
            $available = $past !== null ? 0 : $available;
            $result['layers'][$layer] = ['available' => $available, 'shown' => $available > 0 && in_array($layer, $loaded, true), 'default' => MapParams::L3_LAYERS[$layer]];
        }

        $shown = count($drawn);
        if ($root && $shown > $this->maxNodes()) {
            $truncated = true;
        }
        $result['scope'] = $this->scopeInfo($p, $root, $total, $shown, $truncated);

        return $this->atTime('l3', $p, $result, $past, $today);
    }

    /**
     * A built map at the time its parameters ask for: as of a time (the past map drawn without today's statuses,
     * with the devices' state then and as_of), with the changes since a time (marks, ghosts and changes), or as it
     * is now (unchanged).
     *
     * @param  array<string, int>  $layers  L3 overlays with data today (what a past map leaves out)
     *
     * @throws TimeRefused
     */
    private function atTime(string $mode, array $p, array $result, ?PastMap $past, array $layers = []): array
    {
        if ($past !== null) {
            $ids = array_values(array_filter(array_map(fn (array $node) => ($node['type'] ?? '') === 'device' ? (int) ($node['device_id'] ?? 0) : 0, $result['nodes'])));
            $result = $past->finish($result, $this->mapHistory()->deviceStates($ids, $past->snapshot['time']));
            $result['as_of'] = $this->mapHistory()->asOf($past, (int) $p['at'], $mode, $layers);
        } elseif (($p['since'] ?? null) !== null) {
            $result = $this->mapHistory()->changes($mode, $p['since'], $result);
        }

        return $result;
    }

    /**
     * The maps at another time (MapHistory); refused without the history.
     *
     * @throws TimeRefused
     */
    private function mapHistory(): MapHistory
    {
        if ($this->history === null) {
            throw new TimeRefused('The topology history is not available here.');
        }

        return $this->mapHistory ??= new MapHistory($this->history, $this->settings);
    }

    /**
     * Role of an LLDP/CDP neighbour: as the history recorded it for a map of the past (it keeps no system
     * description), else from its platform, system description and name.
     */
    private static function neighborRole(array $link): string
    {
        return $link['remote_role'] ?? DeviceRole::fromNeighbor($link['remote_platform'] ?? '', $link['remote_version'] ?? '', $link['remote_hostname'] ?? '');
    }

    /**
     * @param  array{device: ?int, group: ?int, location: ?int, vlan: ?int}  $p
     */
    public function stp(array $p): array
    {
        $scope = $this->repo->deviceIds($p['group'], $p['location']);
        $vlan = $p['vlan'];
        $notice = null;
        $keys = $this->repo->stpKeys($scope);
        $stpDevices = array_keys($keys['keys']);
        $lag = $this->repo->lagParents($stpDevices);

        // the neighbours of ports with spanning tree rows and of port-channel members (STP runs on the aggregate, LLDP
        // on its members), and of the other ports unless they are end devices (phones...)
        $stpPorts = $this->repo->stpPortIds($stpDevices);
        $links = array_values($this->repo->linksWhere($scope, fn (array $link) => ! self::endDeviceNeighbor($link)
            || isset($stpPorts[(int) $link['local_port_id']]) || isset($lag[(int) $link['local_port_id']])));
        unset($stpPorts);
        $devices = $this->repo->devices($scope);

        // every VLAN when their instances fit in the memory left: the network's, or those a device's page needs (its
        // VLANs on every bridge; a single instance bridge takes part in all of them)
        $vlanKeys = null;
        if ($vlan === null) {
            $deviceKeys = $p['device'] ? ($keys['keys'][$p['device']] ?? null) : null;
            if ($deviceKeys !== null) {
                $vlanKeys = $deviceKeys === [0] ? array_values(array_unique(array_merge(...array_values($keys['keys'])))) : $deviceKeys;
            }
            $instances = $vlanKeys === null ? $keys['count'] : array_sum(array_map('count', self::instancesOf($keys['keys'], $vlanKeys)));
            if (! self::fitsInMemory($instances, $this->repo->stpPortCount($scope, $vlanKeys), count($devices))) {
                $vlan = 1;
                $vlanKeys = null;
                $notice = 'Large spanning tree data set: showing VLAN 1 / CST only, select a VLAN to analyze another instance.';
            }
        }

        $index = match (true) {
            $vlan !== null => $this->singleInstanceIndex($keys, $vlan, $lag),
            $vlanKeys !== null => $this->instancesIndex($keys, self::instancesOf($keys['keys'], $vlanKeys), $lag),
            default => new StpIndex($this->repo->stpInstances($scope), $this->repo->stpPorts($stpDevices), $lag),
        };
        unset($keys);

        $portIds = $index->portIds();
        foreach ($links as $link) {
            $portIds[] = (int) $link['local_port_id'];
            $portIds[] = (int) $link['remote_port_id'];
        }
        $portIds = array_merge($portIds, array_keys($lag), array_values($lag));

        $analyzer = new StpAnalyzer(
            $devices,
            $index,
            $links,
            $this->repo->analysisPorts($portIds),
            $lag,
            $this->settings,
            $this->repo->stpEvents($scope, $this->settings->int('stp_event_days', 1, 365)),
        );

        $result = $analyzer->analyze($this->repo->rootLookups($index->unknownRootMacs()), $vlan, $vlanKeys);
        $result['vlans'] = $this->repo->stpVlans($scope);
        $result['notice'] = $notice;
        $result['filter'] = $p + ['effective_vlan' => $vlan];
        $result = LoopEvidence::merge($result, $this->loopEvidence($devices, $links, $p['device']));

        if ($p['device']) {
            $result = self::filterStpForDevice($result, $p['device']);
        }

        return $result;
    }

    /**
     * Spanning tree findings of the devices in scope for the background checks, one instance
     * (VLAN) at a time: a PVST network has an instance per VLAN and ports_stp rows per port and
     * VLAN, too many to analyze at once. The instance rows, links, link ports and root bridge
     * lookups are read once; the page's bridge, port and domain rows are left out. The loop
     * evidence of the page (storms, loop messages in syslog) is part of it.
     *
     * @param  array{group: ?int, location: ?int}  $p
     * @return array{findings: array<int, array>}
     */
    public function stpFindings(array $p): array
    {
        $scope = $this->repo->deviceIds($p['group'] ?? null, $p['location'] ?? null);
        $lag = $this->repo->lagParents($scope);
        $base = new StpIndex($this->repo->stpInstanceRows($scope), [], $lag);
        $links = $this->repo->links($scope);
        $linkPorts = array_merge(array_keys($lag), array_values($lag));
        foreach ($links as $link) {
            $linkPorts[] = (int) $link['local_port_id'];
            $linkPorts[] = (int) $link['remote_port_id'];
        }
        // has_stp of a link port covers every instance (portHasStp() trusts it)
        $linkPortRows = $this->repo->analysisPorts($linkPorts);
        $devices = $this->repo->devices($scope);
        $analyzer = new StpAnalyzer($devices, $base, $links, $linkPortRows, $lag, $this->settings,
            $this->repo->stpEvents($scope, $this->settings->int('stp_event_days', 1, 365)));
        $lookups = $this->repo->rootLookups($base->unknownRootMacs());

        $findings = [];
        $networkWide = true;
        foreach ($this->repo->stpVlans($scope) ?: [0] as $key) {
            $keys = [];
            foreach ($base->deviceIds() as $deviceId) {
                if ($base->instance($deviceId, $key) !== null) {
                    $keys[$deviceId] = $key;
                }
            }
            $index = $base->withPorts($this->repo->stpPorts(array_keys($keys), $keys));
            $ports = $linkPortRows + $this->repo->analysisPorts(array_values(array_diff($index->portIds(), array_keys($linkPortRows))));
            array_push($findings, ...$analyzer->withInstance($index, $ports)->instanceFindings($lookups, $key, $networkWide));
            $networkWide = false;
        }

        return ['findings' => array_merge(StpAnalyzer::mergeAcrossVlans($findings), $this->loopEvidence($devices, $links, null)['findings'])];
    }

    /**
     * Loop evidence of the devices in scope: broadcast / multicast counters of their inter-switch
     * ports and the loop related syslog messages of their switches and routers (when the user may
     * read syslog). For one device: its ports, its neighbors' ports toward it and its own messages.
     */
    private function loopEvidence(array $devices, array $links, ?int $deviceId): array
    {
        if ($deviceId) {
            $links = array_values(array_filter($links, fn ($l) => (int) $l['local_device_id'] === $deviceId || (int) ($l['remote_device_id'] ?? 0) === $deviceId));
        }
        $repo = $this->repo->sibling(LoopEvidenceRepository::class);
        $ports = $repo->portRates(array_keys(LoopEvidence::interSwitchPorts($devices, $links)));

        $senders = array_keys(array_filter($devices, fn ($d) => in_array($d['role'] ?? '', [DeviceRole::SWITCH, DeviceRole::L3SWITCH, DeviceRole::ROUTER], true)));
        if ($deviceId) {
            $senders = array_values(array_intersect($senders, [$deviceId]));
        }
        $patterns = new SyslogPatterns($this->settings);
        $hidden = ! $repo->canViewSyslog();
        $syslog = ['rows' => [], 'senders' => 0, 'scanned' => 0, 'truncated' => false, 'limited' => 0, 'unsearched' => 0, 'stopped' => null];
        if (! $hidden && $patterns->usable()) {
            $since = time() - $this->settings->int('stp_syslog_hours', 1, 720) * 3600;
            $syslog = $repo->syslog($senders, $since, fn (string $text) => $patterns->classify($text));
        }

        return (new LoopEvidence($devices, $links, $ports, $syslog['rows'], $repo->portNames(array_column($syslog['rows'], 'device_id')),
            $this->settings, patterns: $patterns))->analyze([
                'syslog_hidden' => $hidden,
                'syslog_truncated' => $syslog['truncated'],
                'syslog_limited' => $syslog['limited'],
                'syslog_unsearched' => $syslog['unsearched'],
                'syslog_stopped' => $syslog['stopped'],
                'syslog_scanned' => $syslog['scanned'],
                'syslog_devices' => $syslog['senders'],
                'syslog_scope' => count($senders),
            ]);
    }

    /**
     * Link and VLAN diagnostics of the devices in scope. Links to monitored neighbours outside the
     * group/location filter are compared too; the VLAN topology is analyzed inside the scope.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     */
    public function links(array $p): array
    {
        [$scope, $device] = $this->repo->scope($p);
        // end devices (phones, servers, access points) take part in one link check only: the description of their
        // port may still name the monitored device that was there before. Else LinkAnalyzer only needs to know that
        // a device reports neighbours, which one row without a port tells for all of them
        $reporting = [];
        $endPorts = [];
        $links = $this->repo->linksWhere($scope, function (array $link) use (&$reporting, &$endPorts): bool {
            if (! self::endDeviceNeighbor($link)) {
                return true;
            }
            $reporting[(int) $link['local_device_id']] = true;
            $endPorts[(int) $link['local_port_id']] = true;

            return false;
        });
        $remote = [];
        foreach ($links as $link) {
            if ($link['remote_device_id']) {
                $remote[] = (int) $link['remote_device_id'];
            }
        }
        $devices = $this->repo->devices(array_values(array_unique(array_merge($scope, $remote))), roles: false);
        $named = LinkAnalyzer::portsNamingDevices($this->repo->portDescriptions(array_keys($endPorts)), $devices);
        unset($endPorts);
        if ($named) {
            $links += $this->repo->linksWhere($scope, fn (array $link) => isset($named[(int) $link['local_port_id']]) && self::endDeviceNeighbor($link));
            ksort($links);
        }
        $links = array_values($links);
        foreach (array_keys($reporting) as $deviceId) {
            $links[] = ['id' => 0, 'local_device_id' => $deviceId, 'local_port_id' => 0, 'remote_device_id' => 0, 'remote_port_id' => null,
                'remote_hostname' => '', 'remote_port' => '', 'remote_platform' => '', 'remote_version' => '', 'protocol' => ''];
        }
        unset($reporting);

        $remote = [];
        $linkPorts = [];
        $interSwitch = [];
        foreach ($links as $link) {
            $linkPorts[] = (int) $link['local_port_id'];
            if ($link['remote_device_id']) {
                $remote[] = (int) $link['remote_device_id'];
                $linkPorts[] = (int) $link['remote_port_id'];
                $interSwitch[] = (int) $link['local_port_id'];
                $interSwitch[] = (int) $link['remote_port_id'];
            }
        }
        $linkPorts = array_values(array_unique(array_filter($linkPorts)));
        $deviceIds = array_values(array_unique(array_merge($scope, $remote)));

        // every aggregate (tells which devices report port-channels at all) and the members of linked ones
        $lag = $this->repo->lagParents($deviceIds);
        $linkedAggregates = [];
        foreach ($linkPorts as $portId) {
            if (isset($lag[$portId])) {
                $linkedAggregates[$lag[$portId]] = true;
            }
        }
        $members = array_keys(array_filter($lag, fn ($parent) => isset($linkedAggregates[$parent])));

        // ports between monitored devices, with their aggregates and all members, carry no "VLAN in use"
        $interSwitchAggregates = [];
        foreach ($interSwitch as $portId) {
            if (isset($lag[$portId])) {
                $interSwitchAggregates[$lag[$portId]] = true;
            }
        }
        $interSwitch = array_merge($interSwitch, array_keys($interSwitchAggregates),
            array_keys(array_filter($lag, fn ($parent) => isset($interSwitchAggregates[$parent]))));

        $ports = $this->repo->ports(array_merge($linkPorts, array_values(array_unique($lag)), $members));
        $withIp = $this->repo->portsWithIp(array_keys($ports));
        foreach ($ports as $portId => &$port) {
            $port['has_ip'] = isset($withIp[$portId]);
        }
        unset($port);

        $notice = null;
        $vlanPorts = array_values(array_unique(array_merge($linkPorts, array_keys($linkedAggregates), $members)));
        if ($this->repo->portVlanCount($vlanPorts) > self::LINK_VLAN_LIMIT) {
            $portVlans = [];
            $notice = 'Very large VLAN data set: the VLAN checks were skipped.';
        } else {
            $portVlans = $this->repo->portVlans($vlanPorts);
        }

        $physicalPorts = array_values(array_unique(array_merge($linkPorts, $members)));
        $analyzer = new LinkAnalyzer(
            $devices,
            $scope,
            $links,
            $ports,
            $portVlans,
            $lag,
            $this->settings,
            $portVlans ? $this->repo->activeVlans($scope, $interSwitch) : [],
            $this->repo->portStatistics($physicalPorts),
            $this->repo->opticalRx($physicalPorts),
        );

        $result = $analyzer->analyze();
        if ($device) {
            $result = self::filterLinksForDevice($result, $device);
        }
        $result['notice'] = $notice;
        $result['filter'] = $p;
        // with no link to show, whether that is because the far ends are outside the user's permissions
        $result['hidden_links'] = ($result['summary']['links'] ?? 0) === 0 ? $this->repo->hiddenLinkCount($scope) : 0;

        return $result;
    }

    /**
     * Redundancy and failure impact. Paths are followed through the whole network the user may
     * see; the findings are limited to the group / location filter.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     */
    public function redundancy(array $p): array
    {
        [$scope, $device] = $this->repo->scope($p);
        $result = $this->redundancyAnalyzer($scope, true)->analyze($device);
        $result['filter'] = $p;

        return $result;
    }

    /**
     * Failure simulation of the L2 map: what the given devices and links cut off the core, on the
     * whole network the user may see and as it is now.
     *
     * @param  string[]  $refs  see RedundancyAnalyzer::simulate()
     */
    public function simulate(array $refs): array
    {
        return $this->redundancyAnalyzer($this->repo->deviceIds(), false)->simulate($refs);
    }

    /**
     * Redundancy analysis of every device the user may see, reporting on $scope. Also the base of the
     * rollout planner and the device dependencies (PlanningService), so they see the same links.
     *
     * @param  int[]  $scope
     * @param  bool  $details  also read stack members / line cards and outage start times
     */
    public function redundancyAnalyzer(array $scope, bool $details): RedundancyAnalyzer
    {
        $all = $this->repo->deviceIds();
        // baseline links that LLDP/CDP no longer reports are down links of the design
        $missing = $this->history?->missingBaselineLinks($all) ?? [];

        // not the rows of thousands of phones: only the ports of unmanaged neighbours seen on one port
        $leafNeighbors = new LeafNeighbors($missing);
        $links = $this->repo->linksWhere($all, $leafNeighbors->keep(...));
        if ($leafNeighbors->hasSeveral()) {
            $links += $this->repo->linksWhere($all, $leafNeighbors->several(...));
            ksort($links);
        }
        $links = array_values($links);
        $leaves = $leafNeighbors->leaves();
        unset($leafNeighbors);

        $linkPorts = [];
        $perDevice = [];
        foreach ($links as $link) {
            $linkPorts[] = (int) $link['local_port_id'];
            $linkPorts[] = (int) $link['remote_port_id'];
            $perDevice[(int) $link['local_device_id']][(int) $link['local_port_id']] = true;
        }
        foreach ($leaves as $deviceId => $portIds) {
            foreach ($portIds as $portId) {
                $linkPorts[] = $portId;
                $perDevice[$deviceId][$portId] = true;
            }
        }
        $linkPorts = array_values(array_unique(array_filter($linkPorts)));
        $lag = $this->repo->lagParents($all);
        $aggregates = array_values(array_unique(array_intersect_key($lag, array_flip($linkPorts))));
        $ports = $this->repo->ports(array_merge($linkPorts, $aggregates), self::REDUNDANCY_PORT_COLUMNS);

        if ($missing) {
            $links = array_merge($links, $missing);
            $ports += $this->repo->ports(array_diff(array_merge(array_column($missing, 'local_port_id'), array_column($missing, 'remote_port_id')), array_keys($ports)), self::REDUNDANCY_PORT_COLUMNS);
        }

        $devices = $this->repo->devices($all, roles: false);
        $entityUnits = [];
        $outageStarts = [];
        if ($details) {
            // stack member / line card of the link ports of devices with several links
            $several = array_filter($perDevice, fn ($ports) => count($ports) >= 2);
            foreach ($this->repo->portEntities(array_filter($ports, fn ($port) => isset($several[(int) $port['device_id']]))) as $deviceId => $rows) {
                $entityUnits[$deviceId] = PortUnits::fromEntities($rows);
            }
            $outageStarts = $this->repo->outageStarts(array_keys(array_filter($devices, fn ($d) => empty($d['status']))));
        }

        return new RedundancyAnalyzer($devices, $scope, $links, $ports, $lag, $this->settings, $entityUnits, $outageStarts, $this->repo->stpPriorities($all), $leaves);
    }

    /**
     * Narrow a link analysis to one device (link page opened from a device page).
     */
    public static function filterLinksForDevice(array $result, int $deviceId): array
    {
        $result['findings'] = array_values(array_filter($result['findings'], fn ($f) => in_array($deviceId, $f['devices'], true)));
        $result['links'] = array_values(array_filter($result['links'], fn ($l) => $l['a']['device_id'] === $deviceId || $l['b']['device_id'] === $deviceId));
        $result['summary'] = LinkAnalyzer::summarize($result['links'], $result['findings']);

        return $result;
    }

    /**
     * Data for the device overview panel. Runs on every device page view, so it only reads the
     * device's own rows plus a cheap lookup of who owns the root bridges it follows.
     */
    public function deviceSummary(int $deviceId): array
    {
        $links = $this->repo->links([$deviceId]);
        $instances = $this->repo->stpInstances([$deviceId]);

        $neighbors = [];
        foreach ($links as $link) {
            $key = $link['remote_device_id'] ? 'd' . $link['remote_device_id'] : 'u' . strtolower((string) $link['remote_hostname']);
            $neighbors[$key] = true;
        }

        $summary = [
            'neighbors' => count($neighbors),
            'links' => count(array_unique(array_filter(array_map(fn ($l) => (int) $l['local_port_id'], $links)))),
            'stp' => null,
            'routing' => $this->repo->routingPresence($deviceId),
        ];

        if ($instances) {
            $lag = $this->repo->lagParents([$deviceId]);
            $index = new StpIndex($instances, $this->repo->stpPorts([$deviceId]), $lag);
            $remoteIds = array_values(array_unique(array_filter(array_map(fn ($l) => (int) $l['remote_device_id'], $links))));

            $rootMacs = array_values(array_unique(array_filter(array_map(fn ($i) => StpIndex::mac($i['designatedRoot'] ?? ''), $instances))));
            $analyzer = new StpAnalyzer(
                $this->repo->devices(array_merge([$deviceId], $remoteIds)),
                $index,
                $links,
                $this->repo->ports(array_merge($index->portIds(), array_map(fn ($l) => (int) $l['local_port_id'], $links))),
                $lag,
                $this->settings,
            );
            $summary['stp'] = $analyzer->deviceSummary($deviceId, $this->repo->rootLookups($rootMacs, deep: false));
        }

        return $summary;
    }

    /**
     * Narrow a full STP result to one device (STP page opened from a device page).
     */
    public static function filterStpForDevice(array $result, int $deviceId): array
    {
        $result['findings'] = array_values(array_filter($result['findings'], fn ($f) => in_array($deviceId, $f['devices'], true)));
        $result['bridges'] = array_values(array_filter($result['bridges'], fn ($b) => $b['device_id'] === $deviceId));
        $result['blocked'] = array_values(array_filter($result['blocked'], fn ($b) => $b['device_id'] === $deviceId));
        $result['domains'] = array_values(array_filter($result['domains'], fn ($d) => in_array($deviceId, $d['member_ids'], true)));
        $result['events'] = array_values(array_filter($result['events'], fn ($e) => $e['device_id'] === $deviceId));
        $result['summary'] = StpAnalyzer::summarize($result['domains'], $result['bridges'], $result['blocked'], $result['findings'], $result['summary']['recent_tc_window']);

        return $result;
    }

    /**
     * STP index holding, for every bridge, the instance and port rows of the one instance that
     * carries $vlan (null: the default instance); the other instances are known by their VLAN key,
     * which tells PVST bridges from single instance ones.
     *
     * @param  array{keys: array<int, int[]>, bridges: array<string, int>}  $keys  TopologyRepository::stpKeys()
     * @param  array<int, int>  $lag
     */
    private function singleInstanceIndex(array $keys, ?int $vlan, array $lag): StpIndex
    {
        $selected = [];
        foreach ($keys['keys'] as $deviceId => $deviceKeys) {
            $key = StpIndex::selectKey($deviceKeys, $vlan);
            if ($key !== null) {
                $selected[$deviceId] = [$key];
            }
        }

        return $this->instancesIndex($keys, $selected, $lag);
    }

    /**
     * The groups of end devices L2Builder draws: per device and role the neighbours and their names, the device's
     * ports they are seen on and how many of those are not up.
     *
     * @param  array<int, array<string, array{rows: int, neighbors: array<string, string>, ports: array<int, true>}>>  $ends
     * @return array<int, array<string, array{count: int, names: string[], ports: int, down: int}>>
     */
    private function endDeviceGroups(array $ends): array
    {
        $ports = [];
        foreach ($ends as $groups) {
            foreach ($groups as $group) {
                array_push($ports, ...array_keys($group['ports']));
            }
        }
        $down = [];
        foreach ($this->repo->portsNotUp($ports) as $portId) {
            $down[$portId] = true;
        }

        $result = [];
        foreach ($ends as $deviceId => $groups) {
            foreach ($groups as $role => $group) {
                unset($group['ports'][0]); // rows without a local port
                $result[$deviceId][$role] = [
                    'count' => count($group['neighbors']),
                    'names' => array_values(array_unique(array_filter($group['neighbors'], fn (string $name) => $name !== ''))),
                    'ports' => count($group['ports']),
                    'down' => count(array_intersect_key($group['ports'], $down)),
                ];
            }
        }

        return $result;
    }

    /**
     * Can the STP page, its links and devices read, also hold these instances and ports_stp rows in the memory PHP
     * allows it (LibreNMS' php_memory_limit or the plugin's, see RaiseMemoryLimit)?
     */
    private static function fitsInMemory(int $instances, int $portRows, int $devices): bool
    {
        if ($portRows > self::STP_ALL_VLAN_LIMIT) {
            return false;
        }
        $limit = MemoryLimit::bytes((string) ini_get('memory_limit'));
        $needed = $instances * self::STP_INSTANCE_BYTES + $portRows * self::STP_PORT_BYTES + $devices * self::STP_DEVICE_BYTES + self::STP_MARGIN_BYTES;

        return $limit === -1 || $needed <= $limit - memory_get_usage();
    }

    /**
     * The instances of each bridge that control these VLAN keys (StpIndex::selectKey()).
     *
     * @param  array<int, int[]>  $keys  device_id => its VLAN keys
     * @param  int[]  $vlanKeys
     * @return array<int, int[]> device_id => VLAN keys of its instances to load
     */
    private static function instancesOf(array $keys, array $vlanKeys): array
    {
        $selected = [];
        foreach ($keys as $deviceId => $deviceKeys) {
            $mine = [];
            foreach ($vlanKeys as $key) {
                $one = StpIndex::selectKey($deviceKeys, (int) $key);
                if ($one !== null) {
                    $mine[$one] = true;
                }
            }
            if ($mine) {
                $selected[$deviceId] = array_keys($mine);
            }
        }

        return $selected;
    }

    /**
     * STP index holding these instances of the bridges (and their port rows), knowing every VLAN key of each.
     *
     * @param  array{keys: array<int, int[]>, bridges: array<string, int>}  $keys  TopologyRepository::stpKeys()
     * @param  array<int, int[]>  $selected  device_id => VLAN keys (instancesOf())
     * @param  array<int, int>  $lag
     */
    private function instancesIndex(array $keys, array $selected, array $lag): StpIndex
    {
        return StpIndex::partial($keys['keys'], $this->repo->stpInstancesFor($selected), $this->repo->stpPorts(array_keys($selected), $selected),
            $lag, $keys['bridges']);
    }

    /**
     * Is the links row an LLDP/CDP neighbour that is not monitored (or not visible) and an end device
     * (phone, server, access point), by its platform or system description?
     */
    private static function endDeviceNeighbor(array $link): bool
    {
        return empty($link['remote_device_id']) && DeviceRole::isEndDevice(self::neighborRole($link));
    }

    /**
     * @param  int[]  $ids
     * @param  array<int, int>  $degree
     * @return array{0: int[], 1: int, 2: bool}
     */
    private function limit(array $ids, ?int $root, array $degree): array
    {
        $total = count($ids);
        $max = $this->maxNodes();
        if ($total <= $max) {
            return [$ids, $total, false];
        }

        if (! $root) {
            usort($ids, fn ($a, $b) => ($degree[$b] ?? 0) <=> ($degree[$a] ?? 0));
        }

        return [array_slice($ids, 0, $max), $total, true];
    }

    /**
     * Maximum devices drawn on one map (setting, 10-5000).
     */
    private function maxNodes(): int
    {
        return $this->settings->int('max_nodes', 10, 5000);
    }

    /**
     * Description of what the map shows, for the status line (centered device, filters, truncation).
     */
    private function scopeInfo(array $p, ?int $root, int $total, int $shown, bool $truncated): array
    {
        return [
            'root' => $root,
            'root_name' => $root ? $this->repo->deviceName($root) : null,
            'depth' => $p['depth'] ?? null,
            'group' => $p['group'] ?? null,
            'location' => $p['location'] ?? null,
            'total' => $total,
            'shown' => $shown,
            'truncated' => $truncated,
            'max_nodes' => $this->maxNodes(),
        ];
    }
}
