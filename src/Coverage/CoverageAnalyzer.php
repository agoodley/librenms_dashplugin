<?php

namespace Agoodley\LibrenmsTopology\Coverage;

use Agoodley\LibrenmsTopology\Links\NeighborReports;
use Agoodley\LibrenmsTopology\Stp\Finding;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Topology\L2Builder;

/**
 * How much of the network LibreNMS sees, in three kinds of gaps:
 *  - LLDP/CDP neighbours that look like switches, routers, firewalls or access points but are not
 *    monitored, are monitored under a name LibreNMS did not link, or are disabled;
 *  - monitored devices that report no neighbours, so their links come from the other end only or
 *    are missing from the maps and the checks;
 *  - access ports with many MAC addresses and no neighbour: a likely unmanaged switch or hub.
 * Pure PHP: all data is passed in as arrays so it can be tested without LibreNMS.
 */
class CoverageAnalyzer
{
    /** Check codes and their category; the "disabled checks" setting accepts either. */
    public const CHECKS = [
        'not_monitored' => 'unmonitored',
        'not_linked' => 'unmonitored',
        'neighbor_disabled' => 'unmonitored',
        'silent_device' => 'devices',
        'isolated_device' => 'devices',
        'hub_suspected' => 'ports',
    ];

    /** Order of the categories within one severity. */
    private const CATEGORY_ORDER = ['unmonitored', 'devices', 'ports'];

    /** Neighbour roles in table order: network devices first. */
    private const ROLE_ORDER = [
        DeviceRole::ROUTER, DeviceRole::FIREWALL, DeviceRole::L3SWITCH, DeviceRole::SWITCH, DeviceRole::WIRELESS,
        DeviceRole::UNKNOWN, DeviceRole::SERVER, DeviceRole::PHONE, DeviceRole::OTHER,
    ];

    /** Roles that run LLDP/CDP on a normal network: worth monitoring as neighbours, a gap when they report nothing. */
    public const NETWORK_ROLES = [DeviceRole::ROUTER, DeviceRole::FIREWALL, DeviceRole::L3SWITCH, DeviceRole::SWITCH, DeviceRole::WIRELESS];

    /** Of those, the roles the maps and the redundancy analysis are built on: their gaps are warnings. */
    private const CORE_ROLES = [DeviceRole::ROUTER, DeviceRole::FIREWALL, DeviceRole::L3SWITCH, DeviceRole::SWITCH];

    /** ifTrunk values that are not a trunk (LibreNMS skips Cisco's notApplicable, other sources may not). */
    private const NOT_TRUNK = ['', 'notapplicable', 'access', 'none'];

    /** Interface types that never face a host directly (aggregates and virtual interfaces). */
    private const VIRTUAL_TYPES = ['ieee8023adLag', 'l3ipvlan', 'l2vlan', 'propVirtual', 'softwareLoopback', 'tunnel', 'bridge'];

    /** Rows of the neighbour and device tables; the findings list every problem anyway. */
    public const TABLE_ROWS = 1000;

    /** @var array<int, true> */
    private array $scope;

    /** @var array<string, array<int, int>> exact lower case name or address => monitored devices */
    private array $byName = [];

    /** @var array<string, array<int, int>> short form of a domain name => monitored devices */
    private array $byShort = [];

    /** @var array<string, true> disabled check codes and categories */
    private array $disabled;

    /** @var Finding[] */
    private array $findings = [];

    /**
     * @param  array<int, array>  $devices  device_id => row (display, hostname, role, type, status) of the analyzed devices
     *                                       and of the monitored devices that report them
     * @param  int[]  $scope  analyzed devices (the group / location filter)
     * @param  array<int, array>  $links  links rows reported by the analyzed devices, and rows of other devices that name
     *                                    them; 'hidden' marks a neighbour monitored on a device the user may not see
     * @param  array<int, array>  $ports  port_id => port row (ifName, ifDescr, ifAlias, ifTrunk, ifType, device_id) of the
     *                                    link ports and the busy ports
     * @param  array<int, int>  $lagParent  member port_id => aggregate port_id
     * @param  array<int, array{name: string, names: string[], visible: bool, disabled: bool}>  $known  every monitored
     *     device with its display name and the names (hostname, sysName, display) and addresses that identify it, to
     *     recognize neighbours LibreNMS did not link; devices the user may not see only leave their neighbours out
     * @param  array<int, array{device_id: int, macs: int, vlans: int[], last_seen: ?int}>  $busyPorts  port_id => ports with
     *     many MAC addresses in the forwarding table
     * @param  array<int, array<int, bool>>  $portVlans  port_id => [vlan => untagged] of the busy ports
     * @param  array<int, true>  $moduleOff  devices whose discovery-protocols module is disabled in LibreNMS
     * @param  bool  $autodiscovery  LibreNMS adds LLDP/CDP neighbours as devices by itself (autodiscovery.xdp)
     */
    public function __construct(
        private array $devices,
        array $scope,
        private array $links,
        private array $ports,
        private array $lagParent,
        private array $known,
        private array $busyPorts,
        private array $portVlans,
        private array $moduleOff,
        private bool $autodiscovery,
        private PluginSettings $settings,
    ) {
        $this->scope = array_fill_keys(array_map('intval', $scope), true);
        $this->disabled = array_fill_keys(array_map('strtolower', $settings->list('coverage_disabled_checks')), true);
        foreach ($known as $deviceId => $device) {
            foreach ($device['names'] as $name) {
                $name = strtolower(trim((string) $name));
                if ($name === '') {
                    continue;
                }
                $this->byName[$name][] = (int) $deviceId;
                $short = L2Builder::shortHost($name);
                if ($short !== $name) {
                    $this->byShort[$short][] = (int) $deviceId;
                }
            }
        }
    }

    /**
     * Run every check and return the data of the coverage page: summary, findings and the tables of
     * unmonitored neighbours, devices without neighbour data and busy ports without a neighbour, limited
     * to what involves $device when given.
     */
    public function analyze(?int $device = null): array
    {
        $this->findings = [];
        $reports = new NeighborReports($this->links, $this->devices);

        $neighbors = $this->checkNeighbors();
        $devices = $this->checkDevices($reports);
        $ports = $this->checkPorts();

        if ($device !== null) {
            $neighbors = array_values(array_filter($neighbors, fn ($n) => $n['device_id'] === $device
                || in_array($device, array_column($n['sightings'], 'device_id'), true)));
            $devices = array_values(array_filter($devices, fn ($d) => $d['device_id'] === $device
                || in_array($device, array_column($d['seen_by'], 'device_id'), true)));
            $ports = array_values(array_filter($ports, fn ($p) => $p['device_id'] === $device));
        }

        $findings = [];
        foreach ($this->findings as $finding) {
            if ($device === null || in_array($device, $finding->devices, true)) {
                $findings[] = $finding->toArray() + ['category' => self::CHECKS[$finding->code] ?? 'other'];
            }
        }
        $categoryOrder = array_flip(self::CATEGORY_ORDER);
        $key = fn ($f) => [Finding::SEVERITY_ORDER[$f['severity']], $categoryOrder[$f['category']] ?? 99, $f['title']];
        usort($findings, fn ($a, $b) => $key($a) <=> $key($b));

        $summary = $this->summarize($findings, $neighbors, $devices, $ports);
        $names = [];
        foreach ($findings as $finding) {
            foreach ($finding['devices'] as $deviceId) {
                $names[$deviceId] = $this->deviceName($deviceId);
            }
        }
        foreach ($neighbors as $neighbor) {
            foreach (array_filter(array_merge([$neighbor['device_id']], array_column($neighbor['sightings'], 'device_id'))) as $deviceId) {
                $names[$deviceId] = $this->deviceName($deviceId);
            }
        }
        foreach ($devices as $row) {
            foreach (array_merge([$row['device_id']], array_column($row['seen_by'], 'device_id')) as $deviceId) {
                $names[$deviceId] = $this->deviceName($deviceId);
            }
        }
        foreach ($ports as $row) {
            $names[$row['device_id']] = $this->deviceName($row['device_id']);
        }

        return [
            'device_names' => $names,
            'summary' => $summary,
            'findings' => $findings,
            'neighbors' => array_slice($neighbors, 0, self::TABLE_ROWS),
            'neighbors_total' => count($neighbors),
            'devices' => array_slice($devices, 0, self::TABLE_ROWS),
            'devices_total' => count($devices),
            'ports' => $ports,
        ];
    }

    // ---------------------------------------------------------------- unmonitored neighbours

    /**
     * LLDP/CDP neighbours of the analyzed devices that LibreNMS does not monitor (or monitors under a
     * name it did not link, or has disabled): one row per neighbour, merging every sighting.
     *
     * @return array<int, array> neighbour rows, network devices first
     */
    private function checkNeighbors(): array
    {
        $neighbors = [];
        foreach ($this->links as $link) {
            $ld = (int) $link['local_device_id'];
            $rd = (int) ($link['remote_device_id'] ?? 0);
            if (! isset($this->scope[$ld]) || ! empty($link['hidden']) || ($rd && ! ($this->known[$rd]['disabled'] ?? false))) {
                continue; // reported by another device, or a monitored neighbour (also those the user may not see)
            }

            $host = trim((string) ($link['remote_hostname'] ?? ''));
            $lp = (int) ($link['local_port_id'] ?? 0);
            $id = L2Builder::unmanagedNodeId(['b_host' => $host, 'a_device' => $ld, 'a_port' => $lp, 'b_name' => (string) ($link['remote_port'] ?? '')]);
            $neighbor = &$neighbors[$id];
            $neighbor ??= [
                'id' => $id, 'name' => $host, 'platforms' => [], 'version' => '', 'protocols' => [], 'sightings' => [], 'linked' => null,
            ];
            $platform = trim((string) ($link['remote_platform'] ?? ''));
            if ($platform !== '') {
                $neighbor['platforms'][$platform] = true;
            }
            if ($neighbor['version'] === '') {
                $neighbor['version'] = trim((string) ($link['remote_version'] ?? ''));
            }
            $protocol = strtolower(trim((string) ($link['protocol'] ?? '')));
            if ($protocol !== '') {
                $neighbor['protocols'][$protocol] = true;
            }
            if ($rd && ($this->known[$rd]['disabled'] ?? false)) {
                $neighbor['linked'] = $rd; // linked to a device that is disabled in LibreNMS
            }
            $neighbor['sightings'][$ld . '#' . $lp] ??= [
                'device_id' => $ld,
                'port_id' => $lp ?: null,
                'port' => $lp ? $this->portName($lp) : '',
                'remote_port' => (string) ($link['remote_port'] ?? ''),
            ];
            unset($neighbor);
        }

        $rows = [];
        foreach ($neighbors as $neighbor) {
            $reporters = array_values(array_unique(array_column($neighbor['sightings'], 'device_id')));
            [$status, $match] = $this->identify($neighbor, $reporters);
            if ($status === null) {
                continue; // monitored on a device the user may not see
            }
            $platforms = array_keys($neighbor['platforms']);
            $role = $this->neighborRole($platforms, $neighbor['name']);
            $row = [
                'id' => $neighbor['id'],
                'name' => $neighbor['name'],
                'address' => filter_var($neighbor['name'], FILTER_VALIDATE_IP) ? $neighbor['name'] : null,
                'role' => $role,
                'network' => in_array($role, self::NETWORK_ROLES, true),
                'platform' => $platforms[0] ?? '',
                'version' => mb_substr($neighbor['version'], 0, 200),
                'protocols' => array_keys($neighbor['protocols']),
                'status' => $status,
                'device_id' => $match,
                'sightings' => array_values($neighbor['sightings']),
            ];
            $rows[] = $row;
            $this->neighborFinding($row);
        }

        // sort keys computed once: tens of thousands of phones are common
        $roleOrder = array_flip(self::ROLE_ORDER);
        $statusOrder = ['not_monitored' => 0, 'not_linked' => 1, 'disabled' => 2];
        $keys = [];
        foreach ($rows as $index => $row) {
            $keys[$index] = sprintf('%02d%d', $roleOrder[$row['role']] ?? 99, $statusOrder[$row['status']]) . strtolower($row['name']);
        }
        asort($keys, SORT_STRING);

        return array_values(array_map(fn ($index) => $rows[$index], array_keys($keys)));
    }

    /**
     * Is a neighbour really unmonitored? A name matching a monitored device the user may see means
     * LibreNMS did not link them; a disabled device is not polled; a device the user may not see
     * leaves the neighbour out (null).
     *
     * @param  int[]  $reporters  devices that see the neighbour (a device seeing its own name is not a match)
     * @return array{0: ?string, 1: ?int} status (not_monitored, not_linked, disabled) and the matching device
     */
    private function identify(array $neighbor, array $reporters): array
    {
        if ($neighbor['linked'] !== null) {
            return ['disabled', $neighbor['linked']];
        }

        $matches = array_values(array_diff($this->matches($neighbor['name']), $reporters));
        if (! $matches) {
            return ['not_monitored', null];
        }
        foreach (['not_linked' => false, 'disabled' => true] as $status => $disabled) {
            foreach ($matches as $deviceId) {
                if (($this->known[$deviceId]['visible'] ?? false) && ($this->known[$deviceId]['disabled'] ?? false) === $disabled) {
                    return [$status, $deviceId];
                }
            }
        }

        return [null, null];
    }

    /**
     * Monitored devices whose name or address is the neighbour's name. Domain names are only compared
     * with a name without domain by their first label: "core1" matches "core1.example.net", but
     * "sw1.a.example.net" does not match "sw1.b.example.net".
     *
     * @return int[]
     */
    private function matches(string $name): array
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            return [];
        }
        $short = L2Builder::shortHost($name);
        $found = $this->byName[$name] ?? [];
        if ($short !== $name) {
            $found = array_merge($found, $this->byName[$short] ?? []);
        } else {
            $found = array_merge($found, $this->byShort[$name] ?? []);
        }

        return array_values(array_unique($found));
    }

    /**
     * Role of a neighbour from its platforms and name; the first specific one wins.
     *
     * @param  string[]  $platforms
     */
    private function neighborRole(array $platforms, string $name): string
    {
        $roles = [];
        foreach ($platforms ?: [''] as $platform) {
            $roles[] = DeviceRole::fromPlatform($platform, $name);
        }
        foreach (self::ROLE_ORDER as $role) {
            if ($role !== DeviceRole::UNKNOWN && in_array($role, $roles, true)) {
                return $role;
            }
        }

        return DeviceRole::UNKNOWN;
    }

    /**
     * Finding of an unmonitored neighbour: switches, routers and firewalls are warnings, access
     * points info (a controller often monitors them); phones, servers and unknown devices are only
     * listed. Unlinked and disabled neighbours are info whatever they are.
     */
    private function neighborFinding(array $row): void
    {
        if ($row['status'] === 'not_monitored' && ! $row['network']) {
            return;
        }
        $where = $this->sightingText($row['sightings']);
        $label = $row['name'] . ($row['platform'] !== '' ? ' (' . $row['platform'] . ')' : '');
        $devices = array_values(array_unique(array_filter(array_merge(array_column($row['sightings'], 'device_id'), [$row['device_id']]))));
        $ports = array_values(array_filter(array_column($row['sightings'], 'port_id')));

        if ($row['status'] === 'not_linked') {
            $device = $this->deviceName($row['device_id']);
            $reporter = $this->deviceName($row['sightings'][0]['device_id']);
            $this->add(new Finding(
                Finding::INFO, 'not_linked',
                'neighbor not linked to its device: ' . $row['name'] . " ($device)",
                $where . ' ' . (count($row['sightings']) === 1 ? 'sees ' : 'see ') . $label . ', which is ' . ($row['address'] !== null ? 'an address' : 'the name')
                . " of the monitored device $device, but LibreNMS did not link them, so this link is missing from the maps.",
                "LibreNMS links a neighbor to a device by the name it advertises (sysName), the device's hostname or its management address. "
                . ($row['address'] !== null
                    ? "$device advertises the address " . $row['name'] . ' instead of its name: let it advertise its sysName (or poll it by that address)'
                    : "Make $device's sysName or hostname match '" . $row['name'] . "'")
                . ", then run discovery on $reporter again.",
                null, $devices, $ports,
            ));

            return;
        }

        if ($row['status'] === 'disabled') {
            $device = $this->deviceName($row['device_id']);
            $this->add(new Finding(
                Finding::INFO, 'neighbor_disabled',
                'neighbor disabled in LibreNMS: ' . $device,
                $where . ' ' . (count($row['sightings']) === 1 ? 'sees ' : 'see ') . "$device, which is disabled in LibreNMS: it is not polled, "
                . 'so its links, ports and state are missing or out of date.',
                "Enable $device in its device settings, or delete it if it is gone.",
                null, $devices, $ports,
            ));

            return;
        }

        $what = ['router' => 'router', 'firewall' => 'firewall', 'l3switch' => 'switch', 'switch' => 'switch', 'wireless' => 'access point'][$row['role']];
        // a missing or factory default name ("Switch") does not tell it apart: say where it is
        $generic = $row['id'] !== 'u:' . strtolower($row['name']);
        $first = $row['sightings'][0];
        $recommendation = 'Add it in LibreNMS (Devices → Add Device).';
        $recommendation .= $this->autodiscovery
            ? ' LLDP/CDP autodiscovery is on but did not add it: check that its name resolves in DNS (or turn on discovery_by_ip), '
                . 'that its address is inside the discovery networks (nets) and that it answers SNMP.'
            : ' Or turn on LLDP/CDP autodiscovery (autodiscovery.xdp) so discovery adds neighbors like this one by itself.';
        if ($row['role'] === DeviceRole::WIRELESS) {
            $recommendation .= ' Access points managed by a controller can also be monitored through the controller.';
        }

        $this->add(new Finding(
            $row['role'] === DeviceRole::WIRELESS ? Finding::INFO : Finding::WARNING, 'not_monitored',
            "$what not monitored: " . $row['name'] . ($generic ? ' on ' . $this->deviceName($first['device_id']) . ($first['port'] !== '' ? ' ' . $first['port'] : '') : ''),
            "$label is an LLDP/CDP neighbor of $where, but LibreNMS does not monitor it: "
            . ($row['role'] === DeviceRole::WIRELESS
                ? 'its state and clients are not monitored, and it is drawn as an unmanaged neighbor on the maps.'
                : 'its links, VLANs and spanning tree are missing from the maps and the checks, and when it fails LibreNMS only sees the devices behind it go down.'),
            $recommendation,
            null, $devices, $ports,
        ));
    }

    // ---------------------------------------------------------------- devices without neighbours

    /**
     * Analyzed devices that report no LLDP/CDP neighbour. A network device seen by monitored
     * neighbours is "silent" (its links come from the other end only), one nobody sees is "isolated"
     * (alone on the maps); servers, phones and other devices usually do not run LLDP/CDP and are only
     * listed ("quiet").
     *
     * @return array<int, array> device rows, problems first
     */
    private function checkDevices(NeighborReports $reports): array
    {
        $rows = [];
        foreach (array_keys($this->scope) as $deviceId) {
            if (! isset($this->devices[$deviceId]) || $reports->reportsNeighbors($deviceId)) {
                continue;
            }
            $device = $this->devices[$deviceId];
            $role = (string) ($device['role'] ?? DeviceRole::UNKNOWN);
            $expected = in_array($role, self::NETWORK_ROLES, true);
            $seenBy = [];
            foreach ($reports->seenBy($deviceId) as $sighting) {
                $seenBy[] = $sighting + [
                    'port' => $sighting['port_id'] ? $this->portName($sighting['port_id']) : '',
                    'remote_port' => $sighting['remote_port_id'] ? $this->portName($sighting['remote_port_id']) : '',
                ];
            }
            $row = [
                'device_id' => $deviceId,
                'role' => $role,
                'type' => (string) ($device['type'] ?? ''),
                'up' => ! empty($device['status']),
                'state' => ! $expected ? 'quiet' : ($seenBy ? 'silent' : 'isolated'),
                'expected' => $expected,
                'module_off' => isset($this->moduleOff[$deviceId]),
                'seen_by' => $seenBy,
            ];
            $rows[] = $row;
            if ($expected) {
                $this->deviceFinding($row);
            }
        }

        $stateOrder = ['silent' => 0, 'isolated' => 1, 'quiet' => 2];
        usort($rows, fn ($a, $b) => [$stateOrder[$a['state']], ! $a['up'], strtolower($this->deviceName($a['device_id']))]
            <=> [$stateOrder[$b['state']], ! $b['up'], strtolower($this->deviceName($b['device_id']))]);

        return $rows;
    }

    /**
     * Finding of a network device without neighbour data: a warning for switches, routers and
     * firewalls that are up, info for access points and devices that are down.
     */
    private function deviceFinding(array $row): void
    {
        $deviceId = $row['device_id'];
        $name = $this->deviceName($deviceId);
        $severity = $row['up'] && in_array($row['role'], self::CORE_ROLES, true) ? Finding::WARNING : Finding::INFO;
        $down = $row['up'] ? '' : " $name is down: LibreNMS reads its neighbors again at the first discovery after it is back.";
        $recommendation = $row['module_off']
            ? "The discovery-protocols module is disabled for $name in LibreNMS: enable it in the device's settings (Modules) or in the global discovery modules."
            : "Enable LLDP or CDP on $name (transmit and receive) and keep LibreNMS' discovery-protocols module enabled; its neighbors show up after the next discovery.";
        $ports = [];
        $devices = [$deviceId];

        if ($row['seen_by']) {
            $labels = [];
            foreach ($row['seen_by'] as $sighting) {
                $labels[] = $this->deviceName($sighting['device_id']) . ($sighting['port'] !== '' ? ' ' . $sighting['port'] : '')
                    . ($sighting['remote_port'] !== '' ? ' on its port ' . $sighting['remote_port'] : '');
                $devices[] = $sighting['device_id'];
                $ports = array_merge($ports, array_filter([$sighting['port_id'], $sighting['remote_port_id']]));
            }
            $count = count(array_unique(array_column($row['seen_by'], 'device_id')));
            $this->add(new Finding(
                $severity, 'silent_device',
                "no LLDP/CDP neighbors reported by $name",
                "$count monitored neighbor" . ($count === 1 ? ' sees ' : 's see ') . "$name (" . implode(', ', array_slice($labels, 0, 12))
                . (count($labels) > 12 ? ' ...' : '') . "), but LibreNMS has no LLDP/CDP neighbors from $name: its links only come from the other end, "
                . 'and its links to unmonitored neighbors are missing.' . $down,
                $recommendation,
                null, array_values(array_unique($devices)), array_values(array_unique($ports)),
            ));

            return;
        }

        $this->add(new Finding(
            $severity, 'isolated_device',
            "no neighbor data: $name",
            "$name reports no LLDP/CDP neighbors and no monitored device sees it, so it stands alone on the maps "
            . 'and is left out of the link and redundancy checks.' . $down,
            $recommendation . ' If its neighbors are not monitored either, add them too.',
            null, $devices,
        ));
    }

    // ---------------------------------------------------------------- busy ports without neighbour

    /**
     * Access ports of the analyzed devices with at least coverage_mac_threshold MAC addresses and no
     * LLDP/CDP neighbour on either end: an unmanaged switch or hub, or a host that bridges (a
     * hypervisor, an access point) without announcing itself. Trunks, port-channels and virtual
     * interfaces are left out.
     *
     * @return array<int, array> port rows, most MAC addresses first
     */
    private function checkPorts(): array
    {
        $threshold = $this->settings->int('coverage_mac_threshold', 0, 1000000);
        if (! $threshold || ! $this->busyPorts) {
            return [];
        }

        $withNeighbor = [];
        foreach ($this->links as $link) {
            foreach (['local_port_id', 'remote_port_id'] as $column) {
                if (! empty($link[$column])) {
                    $withNeighbor[(int) $link[$column]] = true;
                }
            }
        }
        $aggregates = array_flip(array_map('intval', $this->lagParent));

        $rows = [];
        foreach ($this->busyPorts as $portId => $busy) {
            $port = $this->ports[$portId] ?? null;
            $deviceId = (int) $busy['device_id'];
            if (! $port || ! empty($port['deleted']) || ! isset($this->scope[$deviceId]) || (int) $busy['macs'] < $threshold
                || isset($withNeighbor[$portId]) || isset($this->lagParent[$portId]) || isset($aggregates[$portId])
                || $this->isTrunk((int) $portId, $port)
                || in_array((string) ($port['ifType'] ?? ''), self::VIRTUAL_TYPES, true)
                || InterfaceName::isLagName((string) ($port['ifName'] ?? $port['ifDescr'] ?? ''))) {
                continue;
            }
            $vlans = array_values(array_unique(array_map('intval', $busy['vlans'] ?? [])));
            sort($vlans);
            $row = [
                'device_id' => $deviceId,
                'port_id' => (int) $portId,
                'port' => $this->portName((int) $portId),
                'alias' => trim((string) ($port['ifAlias'] ?? '')),
                'macs' => (int) $busy['macs'],
                'vlans' => $vlans,
                'last_seen' => $busy['last_seen'] ?? null,
            ];
            $rows[] = $row;

            $name = $this->deviceName($deviceId);
            $vlanText = match (count($vlans)) {
                0 => '',
                1 => ' in VLAN ' . $vlans[0],
                default => ' in VLANs ' . implode(', ', array_slice($vlans, 0, 8)) . (count($vlans) > 8 ? ' ...' : ''),
            };
            $this->add(new Finding(
                Finding::INFO, 'hub_suspected',
                "possible unmanaged switch or hub: $name " . $row['port'],
                "$name " . $row['port'] . ($row['alias'] !== '' ? ' (' . $row['alias'] . ')' : '') . ' has ' . $row['macs'] . " MAC addresses$vlanText "
                . 'and no LLDP/CDP neighbor: an unmanaged switch or hub, or a server, hypervisor or access point that bridges traffic without '
                . 'announcing itself.',
                'Find out what is connected. An unmanaged switch is invisible to the maps and to spanning tree and can close a loop: '
                . 'replace it with a managed switch or connect the devices to the managed one. Enable LLDP/CDP on servers and access points '
                . 'so they show up on the maps.',
                null, [$deviceId], [(int) $portId],
            ));
        }

        usort($rows, fn ($a, $b) => [$b['macs'], $this->deviceName($a['device_id']), $a['port']] <=> [$a['macs'], $this->deviceName($b['device_id']), $b['port']]);

        return $rows;
    }

    /**
     * A trunk: the Cisco trunk flag, or at least two tagged VLANs (an access port can carry one tagged
     * voice VLAN). Other vendors leave ifTrunk empty, their trunks show in the VLAN memberships.
     */
    private function isTrunk(int $portId, array $port): bool
    {
        if (! in_array(strtolower(trim((string) ($port['ifTrunk'] ?? ''))), self::NOT_TRUNK, true)) {
            return true;
        }

        return count(array_filter($this->portVlans[$portId] ?? [], fn (bool $untagged) => ! $untagged)) >= 2;
    }

    // ---------------------------------------------------------------- summary and helpers

    /**
     * Counts for the summary cards: findings by severity, unmonitored neighbours by status and
     * role, devices with gaps and busy ports.
     */
    private function summarize(array $findings, array $neighbors, array $devices, array $ports): array
    {
        $severities = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($findings as $finding) {
            $severities[$finding['severity']]++;
        }
        $roles = [];
        $statuses = ['not_monitored' => 0, 'not_linked' => 0, 'disabled' => 0];
        foreach ($neighbors as $neighbor) {
            $statuses[$neighbor['status']]++;
            if ($neighbor['status'] === 'not_monitored') {
                $roles[$neighbor['role']] = ($roles[$neighbor['role']] ?? 0) + 1;
            }
        }
        $states = ['silent' => 0, 'isolated' => 0, 'quiet' => 0];
        foreach ($devices as $device) {
            $states[$device['state']]++;
        }

        return [
            'status' => Finding::worst(array_column($findings, 'severity')),
            'findings' => $severities,
            'neighbors' => count($neighbors),
            'not_monitored' => count(array_filter($neighbors, fn ($n) => $n['status'] === 'not_monitored' && $n['network'])),
            'statuses' => $statuses,
            'roles' => $roles,
            'devices' => $states['silent'] + $states['isolated'],
            'states' => $states,
            'ports' => count($ports),
        ];
    }

    /**
     * "acc1 Gi1/0/5 (its port Gi0/1) and acc2 Gi1/0/7" for the devices that see a neighbour.
     *
     * @param  array<int, array>  $sightings
     */
    private function sightingText(array $sightings): string
    {
        $parts = [];
        foreach (array_slice($sightings, 0, 8) as $sighting) {
            $parts[] = $this->deviceName($sighting['device_id']) . ($sighting['port'] !== '' ? ' ' . $sighting['port'] : '')
                . ($sighting['remote_port'] !== '' ? ' (its port ' . InterfaceName::short($sighting['remote_port']) . ')' : '');
        }
        $more = count($sightings) - count($parts);
        if ($more > 0) {
            $parts[] = "$more more";
        }
        if (count($parts) === 1) {
            return $parts[0];
        }

        return implode(', ', array_slice($parts, 0, -1)) . ' and ' . end($parts);
    }

    private function add(Finding $finding): void
    {
        if (! isset($this->disabled[$finding->code]) && ! isset($this->disabled[self::CHECKS[$finding->code] ?? ''])) {
            $this->findings[] = $finding;
        }
    }

    private function deviceName(int $deviceId): string
    {
        $device = $this->devices[$deviceId] ?? null;
        if ($device) {
            return (string) (($device['display'] ?? '') ?: ($device['hostname'] ?? $deviceId));
        }

        return ($this->known[$deviceId]['visible'] ?? false) ? (string) ($this->known[$deviceId]['name'] ?? "device #$deviceId") : "device #$deviceId";
    }

    private function portName(int $portId): string
    {
        $port = $this->ports[$portId] ?? null;

        return $port ? InterfaceName::short(InterfaceName::label($port)) : "port #$portId";
    }
}
