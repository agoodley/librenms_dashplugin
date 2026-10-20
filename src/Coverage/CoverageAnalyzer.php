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
 *    monitored, are monitored under a name LibreNMS did not link (or that several devices share),
 *    or are disabled;
 *  - monitored devices that report no neighbours, so their links come from the other end only or
 *    are missing from the maps and the checks;
 *  - ports that look like an unmanaged switch or hub: several neighbours on one port, or an access
 *    port with many MAC addresses and no neighbour that explains them.
 * Pure PHP: all data is passed in as arrays (the LLDP/CDP rows as any iterable, which is read twice)
 * so it can be tested without LibreNMS. Phones, servers and other end devices are only counted: a
 * network has tens of thousands of them and they never make a finding.
 */
class CoverageAnalyzer
{
    /** Check codes and their category; the "disabled checks" setting accepts either. */
    public const CHECKS = [
        'not_monitored' => 'unmonitored',
        'not_linked' => 'unmonitored',
        'ambiguous_name' => 'unmonitored',
        'neighbor_disabled' => 'unmonitored',
        'silent_device' => 'devices',
        'isolated_device' => 'devices',
        'several_neighbors' => 'ports',
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

    /** Of those, the roles the maps and the redundancy analysis are built on: a device of them alone on the map is a warning. */
    private const CORE_ROLES = [DeviceRole::ROUTER, DeviceRole::L3SWITCH, DeviceRole::SWITCH];

    /** LibreNMS OS of devices that support neither LLDP nor CDP (Cisco ASA and Firepower Threat Defense). */
    private const NO_XDP_OS = ['asa', 'ftd'];

    /** ifTrunk values that are not a trunk (LibreNMS skips Cisco's notApplicable, other sources may not). */
    public const NOT_TRUNK = ['', 'notapplicable', 'access', 'none'];

    /** Interface types of Ethernet ports, the only ones an unmanaged switch can hang off (empty: not reported). */
    public const ETHERNET_TYPES = ['ethernetCsmacd', 'iso88023Csmacd', 'fastEther', 'fastEtherFX', 'gigabitEthernet', ''];

    /** MAC addresses of an access port spread over this many VLANs come from an access point, a hypervisor or a trunk. */
    private const MANY_VLANS = 3;

    /** Rows of each list (findings, neighbours, devices, ports); the totals and the summary count everything. */
    public const ROWS = 500;

    /** @var array<int, true> */
    private array $scope;

    /** @var array<string, array<int, int>> exact lower case name or address => monitored devices */
    private array $byName = [];

    /** @var array<string, array<int, int>> first label of a domain name => monitored devices */
    private array $byShort = [];

    /** @var array<string, true> disabled check codes and categories */
    private array $disabled;

    /** @var Finding[] */
    private array $findings = [];

    /** @var array<int, array> port rows of the ports the report names */
    private array $portRows = [];

    /** @var array<string, int> role => unmonitored neighbours that are only counted (phones, servers, ...) */
    private array $counted = [];

    /** @var array<string, int> the same, of the neighbours of the centered device */
    private array $countedOf = [];

    /**
     * @param  array<int, array>  $devices  device_id => row (display, hostname, role, type, os, status) of the analyzed devices
     * @param  int[]  $scope  analyzed devices (the group / location filter)
     * @param  iterable<array>  $links  links rows reported by the analyzed devices and rows of other devices that name them,
     *     read twice (an array, or a lazy collection that streams them); a remote device the user may not see is already
     *     anonymized (id 0)
     * @param  array<int, array>|\Closure  $ports  port_id => port row (ifName, ifDescr, ifAlias, ifTrunk, ifType, deleted,
     *     device_id), or a function that returns them for a list of port ids: called once with the ports the report names
     * @param  array<int, int>  $lagParent  member port_id => aggregate port_id
     * @param  array<int, array{name: string, hostname: string, sysname: string, names: string[], disabled: bool}>  $known
     *     the monitored devices the user may see (also disabled ones): display name, lower case hostname and sysName,
     *     and every lower case name (hostname, sysName, display name) and address that identifies them, to recognize
     *     neighbours LibreNMS did not link
     * @param  array<int, array{device_id: int, macs: int, vlans: int[], vlan_count: int, last_seen: ?int}>  $busyPorts
     *     port_id => MAC addresses of the last forwarding table discovery on candidate access ports (see currentMacCounts())
     * @param  array<int, array<int, bool>>  $portVlans  port_id => [vlan => untagged] of the busy ports
     * @param  array<int, true>  $moduleOff  devices whose discovery-protocols module is disabled in LibreNMS
     * @param  string  $autodiscovery  LibreNMS adding LLDP/CDP neighbours by itself: 'on', 'off', or 'no_nets'
     *                                 (autodiscovery.xdp on, but no discovery networks configured, so nothing is added)
     */
    public function __construct(
        private array $devices,
        array $scope,
        private iterable $links,
        private array|\Closure $ports,
        private array $lagParent,
        private array $known,
        private array $busyPorts,
        private array $portVlans,
        private array $moduleOff,
        private string $autodiscovery,
        private PluginSettings $settings,
    ) {
        $this->scope = array_fill_keys(array_map('intval', $scope), true);
        $this->disabled = array_fill_keys(array_map('strtolower', $settings->list('coverage_disabled_checks')), true);
        foreach ($known as $deviceId => $device) {
            foreach ($device['names'] as $name) {
                $name = strtolower(trim((string) $name));
                if ($name === '' || self::isGeneric($name)) {
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
     * unmonitored neighbours, devices without neighbour data and ports that look like an unmanaged switch,
     * limited to what involves $device when given. Each list is cut at ROWS rows (findings by severity,
     * neighbours with the network devices first); the *_total counts and the summary count everything.
     */
    public function analyze(?int $device = null): array
    {
        $this->findings = [];
        $this->counted = [];
        $this->countedOf = [];

        $seen = $this->readLinks($device);
        $merged = $this->readNeighbors($seen['listed'], $seen['linked']);
        $this->loadPorts($merged, $seen);

        $neighbors = $this->checkNeighbors($merged, $device);
        unset($merged);
        $devices = $this->checkDevices($seen['reports']);
        $ports = $this->checkPorts($seen['shared'], $seen['phones'], $seen['explained']);

        if ($device !== null) {
            $neighbors = array_values(array_filter($neighbors, fn ($n) => in_array($device, $n['candidates'], true)
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

        $summary = $this->summarize($findings, $neighbors, $devices, $ports, $device === null ? $this->counted : $this->countedOf);
        $result = [
            'summary' => $summary,
            'findings' => array_slice($findings, 0, self::ROWS),
            'findings_total' => count($findings),
            'neighbors' => array_slice($neighbors, 0, self::ROWS),
            'neighbors_total' => count($neighbors),
            'devices' => array_slice($devices, 0, self::ROWS),
            'devices_total' => count($devices),
            'ports' => array_slice($ports, 0, self::ROWS),
            'ports_total' => count($ports),
            'rows' => self::ROWS,
        ];

        $names = [];
        foreach ($result['findings'] as $finding) {
            foreach ($finding['devices'] as $deviceId) {
                $names[$deviceId] = $this->deviceName($deviceId);
            }
        }
        foreach ($result['neighbors'] as $neighbor) {
            foreach (array_merge($neighbor['candidates'], array_column($neighbor['sightings'], 'device_id')) as $deviceId) {
                $names[$deviceId] = $this->deviceName($deviceId);
            }
        }
        foreach ($result['devices'] as $row) {
            foreach (array_merge([$row['device_id']], array_column($row['seen_by'], 'device_id')) as $deviceId) {
                $names[$deviceId] = $this->deviceName($deviceId);
            }
        }
        foreach ($result['ports'] as $row) {
            $names[$row['device_id']] = $this->deviceName($row['device_id']);
        }

        return ['device_names' => $names] + $result;
    }

    /**
     * Ports facing an LLDP/CDP neighbour that explains many MAC addresses: any neighbour but an IP phone
     * (a phone passes on the PC behind it; an unmanaged switch behind a phone is what the check is for),
     * on either end of the link. Used before the MAC addresses are counted, which only happens elsewhere.
     *
     * @param  iterable<array>  $links  links rows
     * @return array<int, true> port ids
     */
    public static function explainedPorts(iterable $links): array
    {
        $explained = [];
        foreach ($links as $link) {
            if (! ($link['remote_device_id'] ?? 0) && self::rowRole($link) === DeviceRole::PHONE) {
                continue;
            }
            foreach (['local_port_id', 'remote_port_id'] as $column) {
                if (! empty($link[$column])) {
                    $explained[(int) $link[$column]] = true;
                }
            }
        }

        return $explained;
    }

    /**
     * Ports with at least $min MAC addresses in the last forwarding table discovery of their device. Every
     * discovery stamps the addresses it finds with one time and keeps the others until ports_fdb_purge
     * days have passed, so only the latest stamp of a device counts, and only when it is from the device's
     * last discovery: when that discovery found no address on these ports, all they have is older.
     *
     * @param  iterable<array{port_id: int, device_id: int, seen: int, macs: int}>  $groups  address count per port and
     *     update time (unix time) of the candidate ports, in any order
     * @param  array<int, array{0: ?int, 1: float}>  $discovered  device_id => [end of the last discovery (unix time,
     *     null when unknown), its duration in seconds]
     * @return array<int, array{device_id: int, macs: int, last_seen: int}> port_id => count and time of the last discovery
     */
    public static function currentMacCounts(iterable $groups, array $discovered, int $min): array
    {
        $latest = [];   // device => its latest update time
        $portSeen = []; // port => its latest update time
        $busy = [];     // port => the count of its latest update time, when it reaches $min
        foreach ($groups as $group) {
            $portId = (int) $group['port_id'];
            $deviceId = (int) $group['device_id'];
            $seen = (int) $group['seen'];
            $latest[$deviceId] = max($latest[$deviceId] ?? 0, $seen);
            if ($seen <= ($portSeen[$portId] ?? -1)) {
                continue;
            }
            $portSeen[$portId] = $seen;
            if ((int) $group['macs'] >= $min) {
                $busy[$portId] = ['device_id' => $deviceId, 'macs' => (int) $group['macs'], 'last_seen' => $seen];
            } else {
                unset($busy[$portId]);
            }
        }

        foreach ($busy as $portId => $port) {
            [$end, $took] = $discovered[$port['device_id']] ?? [null, 0];
            // the forwarding table is read during the discovery: a minute of slack for the rounding to seconds
            if ($port['last_seen'] !== $latest[$port['device_id']] || ($end !== null && $port['last_seen'] < $end - (int) ceil((float) $took) - 60)) {
                unset($busy[$portId]);
            }
        }

        return $busy;
    }

    // ---------------------------------------------------------------- reading the LLDP/CDP rows

    /**
     * First pass over the LLDP/CDP rows, keeping what the checks need instead of the rows: who reports
     * whom, the unmonitored neighbours that get a row (network devices, names of monitored devices and
     * disabled devices) and the number of the others by type, the ports facing a neighbour that explains
     * their MAC addresses, the ports with several neighbours and the phones on the busy ports.
     *
     * @return array{reports: NeighborReports, listed: array<string, int>, linked: array<string, int>, explained: array<int, true>,
     *     shared: array<int, string[]>, phones: array<int, string[]>}
     */
    private function readLinks(?int $device): array
    {
        $reports = new NeighborReports([], $this->known + $this->devices);
        $bits = array_flip(self::ROLE_ORDER);
        $network = 0;
        foreach (self::NETWORK_ROLES as $role) {
            $network |= 1 << $bits[$role];
        }

        $roles = [];     // unmonitored neighbour => bits of the roles its rows suggest
        $listed = [];    // unmonitored neighbour that gets a row => true
        $linked = [];    // unmonitored neighbour => the disabled device it is linked to
        $ofDevice = [];  // unmonitored neighbours of the centered device => true
        $explained = [];
        $first = [];     // port => phone flag, identity, "\0" and name of its first neighbour
        $more = [];      // port => identity => [name, phone] of its other neighbours
        $phones = [];    // busy port => identity => phone name
        foreach ($this->links as $link) {
            $reports->add($link);
            $ld = (int) $link['local_device_id'];
            $lp = (int) ($link['local_port_id'] ?? 0);
            $rd = (int) ($link['remote_device_id'] ?? 0);
            $disabled = $rd && ($this->known[$rd]['disabled'] ?? false);
            $role = ! $rd || $disabled ? self::rowRole($link) : null;
            $phone = ! $rd && $role === DeviceRole::PHONE;
            if (! $phone) {
                if ($lp) {
                    $explained[$lp] = true;
                }
                if (! empty($link['remote_port_id'])) {
                    $explained[(int) $link['remote_port_id']] = true;
                }
            }
            if (! isset($this->scope[$ld])) {
                continue;
            }

            $host = trim((string) ($link['remote_hostname'] ?? ''));
            if ($lp) {
                // "SW1.example.net", "sw1" and CDP's "SW1(FOC1234X5Y6)" are one neighbour
                $name = $host !== '' ? $host : ($rd ? $this->deviceName($rd) : '');
                $identity = L2Builder::shortHost(strtolower((string) preg_replace('/\(.*\)$/', '', $name)));
                if ($identity !== '') {
                    if (! isset($first[$lp])) {
                        $first[$lp] = ($phone ? '1' : '0') . $identity . "\0" . $name;
                    } elseif (substr($first[$lp], 1, (int) strpos($first[$lp], "\0") - 1) !== $identity) {
                        $more[$lp][$identity] ??= [$name, $phone];
                    }
                    if ($phone && isset($this->busyPorts[$lp])) {
                        $phones[$lp][$identity] ??= $name;
                    }
                }
            }

            if ($rd && ! $disabled) {
                continue; // a monitored neighbour
            }
            $id = L2Builder::unmanagedNodeId(['b_host' => $host, 'a_device' => $ld, 'a_port' => $lp, 'b_name' => (string) ($link['remote_port'] ?? '')]);
            if (! isset($roles[$id])) {
                $roles[$id] = 0;
                if ($this->matches($host)) {
                    $listed[$id] = true; // may be a monitored device LibreNMS did not link
                }
            }
            $roles[$id] |= 1 << $bits[$role];
            if ($disabled) {
                $listed[$id] = true;
                $linked[$id] = $rd;
            }
            if ($ld === $device) {
                $ofDevice[$id] = true;
            }
        }

        // a neighbour is a network device when any of its rows says so; the others are only counted
        foreach ($roles as $id => $roleBits) {
            if ($roleBits & $network) {
                $listed[$id] = true;
            }
            if (isset($listed[$id])) {
                $listed[$id] = $roleBits;
                continue;
            }
            $this->countEndDevice(self::roleOf($roleBits), isset($ofDevice[$id]));
        }
        unset($roles);

        $shared = [];
        foreach ($more as $portId => $others) {
            $names = [substr($first[$portId], (int) strpos($first[$portId], "\0") + 1)];
            $phoneCount = (int) ($first[$portId][0] === '1');
            foreach ($others as [$name, $phone]) {
                $names[] = $name;
                $phoneCount += (int) $phone;
            }
            // two neighbours that are not phones, or two phones (a phone passes on one PC, not another phone)
            if (count($names) - $phoneCount >= 2 || $phoneCount >= 2) {
                $shared[$portId] = $names;
            }
        }

        return [
            'reports' => $reports,
            'listed' => $listed,
            'linked' => $linked,
            'explained' => $explained,
            'shared' => $shared,
            'phones' => array_map('array_values', $phones),
        ];
    }

    /**
     * Second pass: the rows of the unmonitored neighbours that get a row, merged into one per neighbour
     * with every port it is seen on.
     *
     * @param  array<string, int>  $listed  neighbour id => bits of its roles
     * @param  array<string, int>  $linked  neighbour id => disabled device
     * @return array<string, array> neighbour id => name, platform, description, protocols, sightings, role bits, linked device
     */
    private function readNeighbors(array $listed, array $linked): array
    {
        if (! $listed) {
            return [];
        }

        $neighbors = [];
        foreach ($this->links as $link) {
            $ld = (int) $link['local_device_id'];
            $rd = (int) ($link['remote_device_id'] ?? 0);
            if (! isset($this->scope[$ld]) || ($rd && ! ($this->known[$rd]['disabled'] ?? false))) {
                continue;
            }
            $host = trim((string) ($link['remote_hostname'] ?? ''));
            $lp = (int) ($link['local_port_id'] ?? 0);
            $id = L2Builder::unmanagedNodeId(['b_host' => $host, 'a_device' => $ld, 'a_port' => $lp, 'b_name' => (string) ($link['remote_port'] ?? '')]);
            if (! isset($listed[$id])) {
                continue;
            }

            $neighbor = &$neighbors[$id];
            $neighbor ??= [
                'id' => $id, 'name' => $host, 'platform' => '', 'description' => '', 'protocols' => [], 'sightings' => [],
                'roles' => $listed[$id], 'linked' => $linked[$id] ?? null,
            ];
            // CDP gives a platform; LLDP has none and LibreNMS keeps its system description in remote_version
            if ($neighbor['platform'] === '') {
                $neighbor['platform'] = trim((string) ($link['remote_platform'] ?? ''));
            }
            if ($neighbor['description'] === '') {
                $neighbor['description'] = mb_substr(trim((string) ($link['remote_version'] ?? '')), 0, 200);
            }
            $protocol = strtolower(trim((string) ($link['protocol'] ?? '')));
            if ($protocol !== '') {
                $neighbor['protocols'][$protocol] = true;
            }
            $neighbor['sightings'][$ld . '#' . $lp] ??= ['device_id' => $ld, 'port_id' => $lp ?: null, 'remote_port' => (string) ($link['remote_port'] ?? '')];
            unset($neighbor);
        }

        return $neighbors;
    }

    /**
     * The rows of the ports the report names: where the listed neighbours are seen, the ports of the devices
     * without neighbours that others see, the ports with several neighbours and the busy ports.
     */
    private function loadPorts(array $merged, array $seen): void
    {
        if (is_array($this->ports)) {
            $this->portRows = $this->ports;

            return;
        }

        $ids = $seen['shared'] + $this->busyPorts;
        foreach ($merged as $neighbor) {
            foreach ($neighbor['sightings'] as $sighting) {
                if ($sighting['port_id']) {
                    $ids[$sighting['port_id']] = true;
                }
            }
        }
        foreach (array_keys($this->scope) as $deviceId) {
            if (! $seen['reports']->reportsNeighbors($deviceId)) {
                foreach ($seen['reports']->seenBy($deviceId) as $sighting) {
                    $ids += array_fill_keys(array_filter([$sighting['port_id'], $sighting['remote_port_id']]), true);
                }
            }
        }
        $this->portRows = $ids ? ($this->ports)(array_keys($ids)) : [];
    }

    // ---------------------------------------------------------------- unmonitored neighbours

    /**
     * Rows of the unmonitored neighbours that are network devices or named like a monitored device (or
     * linked to a disabled one), with their findings.
     *
     * @param  array<string, array>  $merged  see readNeighbors()
     * @return array<int, array> neighbour rows, network devices first
     */
    private function checkNeighbors(array $merged, ?int $device): array
    {
        // sort keys computed once, rows built in place
        $roleOrder = array_flip(self::ROLE_ORDER);
        $statusOrder = ['not_monitored' => 0, 'not_linked' => 1, 'ambiguous' => 2, 'disabled' => 3];
        $keys = [];
        foreach ($merged as $id => &$neighbor) {
            $reporters = array_values(array_unique(array_column($neighbor['sightings'], 'device_id')));
            [$status, $candidates] = $this->identify($neighbor, $reporters);
            $role = self::roleOf($neighbor['roles']);
            $network = in_array($role, self::NETWORK_ROLES, true);
            if ($status === 'not_monitored' && ! $network) {
                // named like the device that sees it: an end device after all
                $this->countEndDevice($role, in_array($device, $reporters, true));
                unset($merged[$id]);
                continue;
            }
            $neighbor = [
                'id' => $neighbor['id'],
                'name' => $neighbor['name'],
                'address' => filter_var($neighbor['name'], FILTER_VALIDATE_IP) ? $neighbor['name'] : null,
                'role' => $role,
                'network' => $network,
                'platform' => $neighbor['platform'],
                'description' => $neighbor['description'],
                'protocols' => array_keys($neighbor['protocols']),
                'status' => $status,
                'device_id' => in_array($status, ['not_linked', 'disabled'], true) ? $candidates[0] : null,
                'candidates' => $candidates,
                'sightings' => array_map(fn ($s) => $s + ['port' => $s['port_id'] ? $this->portName($s['port_id']) : ''], array_values($neighbor['sightings'])),
            ];
            $this->neighborFinding($neighbor);
            $keys[$id] = sprintf('%02d%d', $roleOrder[$role] ?? 99, $statusOrder[$status]) . strtolower($neighbor['name']);
        }
        unset($neighbor);
        asort($keys, SORT_STRING);

        $rows = [];
        foreach ($keys as $id => $key) {
            $rows[] = $merged[$id];
        }

        return $rows;
    }

    /**
     * Is a neighbour really unmonitored? LibreNMS links a neighbour when its name, address or MAC address
     * belongs to exactly one device; a name of one device the user may see means LibreNMS did not link
     * them (yet), a name several devices share cannot be linked, and a disabled device is not polled.
     *
     * @param  int[]  $reporters  devices that see the neighbour (a device seeing its own name is not a match)
     * @return array{0: string, 1: int[]} status (not_monitored, not_linked, ambiguous, disabled) and the matching devices
     */
    private function identify(array $neighbor, array $reporters): array
    {
        if ($neighbor['linked'] !== null) {
            return ['disabled', [$neighbor['linked']]];
        }

        $candidates = array_values(array_diff($this->matches($neighbor['name']), $reporters));
        if (count($candidates) > 1) {
            // LibreNMS tries the hostname first: the name of several devices is the hostname of at most one
            $name = strtolower(trim($neighbor['name']));
            $byHostname = array_values(array_filter($candidates, fn (int $id) => ($this->known[$id]['hostname'] ?? '') === $name));
            if (count($byHostname) === 1) {
                $candidates = $byHostname;
            }
        }

        return match (true) {
            ! $candidates => ['not_monitored', []],
            count($candidates) > 1 => ['ambiguous', $candidates],
            default => [($this->known[$candidates[0]]['disabled'] ?? false) ? 'disabled' : 'not_linked', $candidates],
        };
    }

    /**
     * Monitored devices whose name or address is the neighbour's name. Domain names are only compared with
     * a name without domain by their first label: "core1" matches "core1.example.net", "sw1.a.example.net"
     * does not match "sw1.b.example.net". Factory default names ("Switch", "MikroTik") match nothing.
     *
     * @return int[]
     */
    private function matches(string $name): array
    {
        $name = strtolower(trim($name));
        if ($name === '' || self::isGeneric($name)) {
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
     * Finding of an unmonitored neighbour: switches, routers and firewalls are warnings, access points
     * info (a controller often monitors them). Unlinked, ambiguous and disabled neighbours are info
     * whatever they are.
     */
    private function neighborFinding(array $row): void
    {
        $where = $this->sightingText($row['sightings']);
        $sees = count($row['sightings']) === 1 ? 'sees' : 'see';
        $about = $row['platform'] !== '' ? $row['platform'] : mb_strimwidth($row['description'], 0, 70, '…');
        $label = $row['name'] . ($about !== '' ? " ($about)" : '');
        $reporter = $this->deviceName($row['sightings'][0]['device_id']);
        $devices = array_values(array_unique(array_merge(array_column($row['sightings'], 'device_id'), $row['candidates'])));
        $ports = array_values(array_filter(array_column($row['sightings'], 'port_id')));

        if ($row['status'] === 'not_linked') {
            $device = $this->deviceName($row['device_id']);
            $name = strtolower($row['name']);
            $linkNames = array_values(array_filter([$this->known[$row['device_id']]['hostname'] ?? '', $this->known[$row['device_id']]['sysname'] ?? '']));
            $this->add(new Finding(
                Finding::INFO, 'not_linked',
                'neighbor not linked to its device: ' . $row['name'] . " ($device)",
                "$where $sees $label, which is " . ($row['address'] !== null ? 'an address' : 'the name')
                . " of the monitored device $device, but LibreNMS did not link them, so this link is missing from the maps.",
                'LibreNMS links a neighbor to a device when the name it advertises (sysName) is the hostname or sysName of exactly one '
                . 'device (also with or without the domain set in mydomain), or when its management address or MAC address belongs to one. '
                . match (true) {
                    $row['address'] !== null => "$device advertises the address " . $row['name'] . ' instead of its name: let it advertise its sysName, or poll it by that address',
                    in_array($name, $linkNames, true) => "The names already match, so the link is probably older than $device in LibreNMS",
                    in_array(L2Builder::shortHost($name), array_map([L2Builder::class, 'shortHost'], $linkNames), true)
                        => "The names only differ by the domain: set mydomain to that domain, or make $device's sysName or hostname '" . $row['name'] . "'",
                    default => "Make $device's sysName or hostname '" . $row['name'] . "'",
                }
                . ", then run discovery on $reporter again.",
                null, $devices, $ports,
            ));

            return;
        }

        if ($row['status'] === 'ambiguous') {
            $candidates = implode(', ', array_map(fn ($id) => $this->deviceName($id), array_slice($row['candidates'], 0, 8)))
                . (count($row['candidates']) > 8 ? ' ...' : '');
            $this->add(new Finding(
                Finding::INFO, 'ambiguous_name',
                'neighbor name of several devices: ' . $row['name'],
                "$where $sees $label, but LibreNMS monitors several devices by that name ($candidates), so it cannot tell which one it is: "
                . 'the link is missing from the maps.',
                'Give the devices unique names (hostname and sysName), or let the neighbor advertise a unique one: LibreNMS only links a '
                . "neighbor whose name, address or MAC address belongs to exactly one device. Then run discovery on $reporter again.",
                null, $devices, $ports,
            ));

            return;
        }

        if ($row['status'] === 'disabled') {
            $device = $this->deviceName($row['device_id']);
            $this->add(new Finding(
                Finding::INFO, 'neighbor_disabled',
                'neighbor disabled in LibreNMS: ' . $device,
                "$where $sees $device, which is disabled in LibreNMS: it is not polled, so its links, ports and state are missing or out of date.",
                "Enable $device in its device settings, or delete it if it is gone.",
                null, $devices, $ports,
            ));

            return;
        }

        $what = ['router' => 'router', 'firewall' => 'firewall', 'l3switch' => 'switch', 'switch' => 'switch', 'wireless' => 'access point'][$row['role']];
        // a missing or factory default name ("Switch") does not tell it apart: say where it is
        $generic = $row['id'] !== 'u:' . strtolower($row['name']);
        $first = $row['sightings'][0];
        $recommendation = 'Add it in LibreNMS (Devices → Add Device).' . match ($this->autodiscovery) {
            'on' => " LLDP/CDP autodiscovery is on but did not add it; when adding fails, LibreNMS says why in the eventlog of $reporter "
                . '("... discovery of ' . $row['name'] . ' failed"). Check that its name resolves in DNS (or turn on discovery_by_ip), that its '
                . 'address is inside the discovery networks (nets) and not excluded (autodiscovery.nets-exclude, the autodiscovery.xdp_exclude '
                . 'and cdp_exclude patterns), and that it answers ping and SNMP.',
            'no_nets' => ' LLDP/CDP autodiscovery is on, but the discovery networks (nets) are empty, so LibreNMS adds no neighbor: list the '
                . 'networks of your devices in nets.',
            default => ' Or let discovery add neighbors like this one: turn on LLDP/CDP autodiscovery (autodiscovery.xdp) and list the '
                . 'networks of your devices in nets.',
        };
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
     * Analyzed devices that report no LLDP/CDP neighbour. A network device seen by monitored neighbours
     * is "silent" (its links come from the other end only), one nobody sees is "isolated" (alone on the
     * maps); servers, phones and other devices usually run no LLDP/CDP and are only listed ("quiet"), like
     * firewalls whose OS supports neither.
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
            $noXdp = in_array(strtolower((string) ($device['os'] ?? '')), self::NO_XDP_OS, true);
            $expected = in_array($role, self::NETWORK_ROLES, true) && ! $noXdp;
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
                'no_xdp' => $noXdp,
                'module_off' => isset($this->moduleOff[$deviceId]),
                'seen_by' => $seenBy,
            ];
            $rows[] = $row;
            if ($expected) {
                $this->deviceFinding($row);
            }
        }

        $stateOrder = ['silent' => 1, 'isolated' => 0, 'quiet' => 2];
        usort($rows, fn ($a, $b) => [$stateOrder[$a['state']], ! $a['up'], strtolower($this->deviceName($a['device_id']))]
            <=> [$stateOrder[$b['state']], ! $b['up'], strtolower($this->deviceName($b['device_id']))]);

        return $rows;
    }

    /**
     * Finding of a network device without neighbour data. A device others see is info, like the same
     * finding on the link page; a switch or router that is up and alone on the maps is a warning,
     * firewalls, access points and devices that are down are info.
     */
    private function deviceFinding(array $row): void
    {
        $deviceId = $row['device_id'];
        $name = $this->deviceName($deviceId);
        $down = $row['up'] ? '' : " $name is down: LibreNMS reads its neighbors again at the first discovery after it is back.";
        $recommendation = $row['module_off']
            ? "The discovery-protocols module is disabled for $name in LibreNMS: enable it in the device's settings (Modules) or in the global discovery modules."
            : "Enable LLDP or CDP on $name" . ($row['role'] === DeviceRole::FIREWALL ? ' if its OS supports them' : '')
                . ' and the discovery-protocols module in LibreNMS, so its links can be checked from both ends.';

        if ($row['seen_by']) {
            $labels = [];
            $devices = [$deviceId];
            $ports = [];
            foreach ($row['seen_by'] as $sighting) {
                $labels[] = $this->deviceName($sighting['device_id']) . ($sighting['port'] !== '' ? ' ' . $sighting['port'] : '')
                    . ($sighting['remote_port'] !== '' ? ' on its port ' . $sighting['remote_port'] : '');
                $devices[] = $sighting['device_id'];
                $ports = array_merge($ports, array_filter([$sighting['port_id'], $sighting['remote_port_id']]));
            }
            $count = count(array_unique(array_column($row['seen_by'], 'device_id')));
            $this->add(new Finding(
                Finding::INFO, 'silent_device',
                "no LLDP/CDP neighbors reported by $name",
                "$count monitored neighbor" . ($count === 1 ? ' sees ' : 's see ') . "$name (" . implode(', ', array_slice($labels, 0, 12))
                . (count($labels) > 12 ? ' ...' : '') . "), but LibreNMS has no LLDP/CDP neighbors from $name: its links only come from the other end "
                . '(the Link & VLAN page reports the same), and its links to unmonitored neighbors are missing.' . $down,
                $recommendation,
                null, array_values(array_unique($devices)), array_values(array_unique($ports)),
            ));

            return;
        }

        $this->add(new Finding(
            $row['up'] && in_array($row['role'], self::CORE_ROLES, true) ? Finding::WARNING : Finding::INFO, 'isolated_device',
            "no neighbor data: $name",
            "$name reports no LLDP/CDP neighbors and no monitored device sees it, so it stands alone on the maps "
            . 'and is left out of the link and redundancy checks.' . $down,
            $recommendation . ' If its neighbors are not monitored either, add them too.',
            null, [$deviceId],
        ));
    }

    // ---------------------------------------------------------------- ports that look like an unmanaged switch

    /**
     * Ports of the analyzed devices that look like an unmanaged switch or hub:
     *  - several LLDP/CDP neighbours on one port (two or more that are not phones, or two phones): something
     *    between them passes their announcements;
     *  - access ports with at least coverage_mac_threshold MAC addresses in the last forwarding table
     *    discovery and no neighbour that explains them (a phone does not). Trunks, port-channels, ports
     *    that are not Ethernet and ports whose addresses spread over three or more VLANs (an access point,
     *    a hypervisor or a trunk) are left out.
     *
     * @param  array<int, string[]>  $shared  port_id => names of its neighbours, see readLinks()
     * @param  array<int, string[]>  $phonesOn  busy port_id => names of the phones on it
     * @param  array<int, true>  $explained  ports facing a neighbour that explains their MAC addresses
     * @return array<int, array> port rows, most MAC addresses first
     */
    private function checkPorts(array $shared, array $phonesOn, array $explained): array
    {
        $rows = [];
        foreach ($shared as $portId => $neighbors) {
            $port = $this->portRows[$portId] ?? ['device_id' => 0];
            $deviceId = (int) $port['device_id'];
            $busy = $this->busyPorts[$portId] ?? null;
            $row = $this->portRow($portId, $deviceId, $busy) + ['neighbors' => $neighbors];
            $rows[] = $row;
            $name = $this->deviceName($deviceId);
            $this->add(new Finding(
                Finding::INFO, 'several_neighbors',
                "several neighbors on one port: $name " . $row['port'],
                "$name " . $row['port'] . ($row['alias'] !== '' ? ' (' . $row['alias'] . ')' : '') . ' sees ' . count($neighbors) . ' LLDP/CDP neighbors ('
                . implode(', ', array_slice($neighbors, 0, 8)) . (count($neighbors) > 8 ? ' ...' : '') . ')'
                . ($busy ? ' and ' . $busy['macs'] . ' MAC addresses' : '') . ': something LibreNMS does not see connects them to this port, '
                . "an unmanaged switch or hub (or a hypervisor's virtual switch).",
                'Find out what is connected. An unmanaged switch is invisible to the maps and to spanning tree and can close a loop: '
                . 'replace it with a managed switch or connect the devices to the managed one.',
                null, [$deviceId], [(int) $portId],
            ));
        }

        $threshold = $this->settings->int('coverage_mac_threshold', 0, 1000000);
        if ($threshold && $this->busyPorts) {
            $aggregates = array_flip(array_map('intval', $this->lagParent));
            foreach ($this->busyPorts as $portId => $busy) {
                $port = $this->portRows[$portId] ?? null;
                $deviceId = (int) $busy['device_id'];
                if (! $port || ! empty($port['deleted']) || ! isset($this->scope[$deviceId]) || isset($shared[$portId])
                    || (int) $busy['macs'] < $threshold || (int) ($busy['vlan_count'] ?? 0) >= self::MANY_VLANS
                    || isset($explained[$portId]) || isset($this->lagParent[$portId]) || isset($aggregates[$portId])
                    || ! in_array((string) ($port['ifType'] ?? ''), self::ETHERNET_TYPES, true)
                    || InterfaceName::isLagName((string) ($port['ifName'] ?? $port['ifDescr'] ?? ''))
                    || $this->isTrunk((int) $portId, $port)) {
                    continue;
                }
                $row = $this->portRow((int) $portId, $deviceId, $busy) + ['neighbors' => []];
                $rows[] = $row;

                $name = $this->deviceName($deviceId);
                $vlans = $row['vlans'];
                $vlanText = match (count($vlans)) {
                    0 => '',
                    1 => ' in VLAN ' . $vlans[0],
                    default => ' in VLANs ' . implode(', ', $vlans),
                };
                $phones = $phonesOn[$portId] ?? [];
                $this->add(new Finding(
                    Finding::INFO, 'hub_suspected',
                    "possible unmanaged switch or hub: $name " . $row['port'],
                    "$name " . $row['port'] . ($row['alias'] !== '' ? ' (' . $row['alias'] . ')' : '') . ' has ' . $row['macs'] . " MAC addresses$vlanText "
                    . ($phones ? 'and no LLDP/CDP neighbor but the phone ' . implode(', ', $phones) : 'and no LLDP/CDP neighbor')
                    . ': an unmanaged switch or hub, or a server or access point that bridges traffic without announcing itself.',
                    'Find out what is connected. An unmanaged switch is invisible to the maps and to spanning tree and can close a loop: '
                    . 'replace it with a managed switch or connect the devices to the managed one. Enable LLDP/CDP on servers and access points '
                    . 'so they show up on the maps.',
                    null, [$deviceId], [(int) $portId],
                ));
            }
        }

        usort($rows, fn ($a, $b) => [! $a['neighbors'], -($a['macs'] ?? 0), $this->deviceName($a['device_id']), $a['port']]
            <=> [! $b['neighbors'], -($b['macs'] ?? 0), $this->deviceName($b['device_id']), $b['port']]);

        return $rows;
    }

    /**
     * Table row of a port: name, description and, when its forwarding table was counted, MAC addresses
     * and their VLANs.
     */
    private function portRow(int $portId, int $deviceId, ?array $busy): array
    {
        $vlans = array_values(array_unique(array_map('intval', $busy['vlans'] ?? [])));
        sort($vlans);

        return [
            'device_id' => $deviceId,
            'port_id' => $portId,
            'port' => $this->portName($portId),
            'alias' => trim((string) ($this->portRows[$portId]['ifAlias'] ?? '')),
            'macs' => $busy ? (int) $busy['macs'] : null,
            'vlans' => $vlans,
            'last_seen' => $busy['last_seen'] ?? null,
        ];
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
     * Counts for the summary cards: findings by severity, unmonitored neighbours by status and role
     * (also the end devices that are only counted), devices with gaps and ports that look like an
     * unmanaged switch.
     *
     * @param  array<string, int>  $counted  role => end devices that are only counted
     */
    private function summarize(array $findings, array $neighbors, array $devices, array $ports, array $counted): array
    {
        $severities = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($findings as $finding) {
            $severities[$finding['severity']]++;
        }
        $roles = $counted;
        $statuses = ['not_monitored' => array_sum($counted), 'not_linked' => 0, 'ambiguous' => 0, 'disabled' => 0];
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
        $byRole = fn (array $counts) => array_filter(array_map(fn ($role) => $counts[$role] ?? 0, array_combine(self::ROLE_ORDER, self::ROLE_ORDER)));

        return [
            'status' => Finding::worst(array_column($findings, 'severity')),
            'findings' => $severities,
            'neighbors' => count($neighbors) + array_sum($counted),
            'not_monitored' => count(array_filter($neighbors, fn ($n) => $n['status'] === 'not_monitored' && $n['network'])),
            'statuses' => $statuses,
            'roles' => $byRole($roles),
            'other_neighbors' => $byRole($counted),
            'devices' => $states['silent'] + $states['isolated'],
            'states' => $states,
            'ports' => count($ports),
        ];
    }

    /**
     * Count an unmonitored end device (phone, server, ...) that gets no row.
     */
    private function countEndDevice(string $role, bool $ofDevice): void
    {
        $this->counted[$role] = ($this->counted[$role] ?? 0) + 1;
        if ($ofDevice) {
            $this->countedOf[$role] = ($this->countedOf[$role] ?? 0) + 1;
        }
    }

    /**
     * The role of a neighbour from the bits of the roles its rows suggest: the first specific one in
     * ROLE_ORDER, so a CDP platform beats an LLDP description that tells nothing.
     */
    private static function roleOf(int $bits): string
    {
        foreach (self::ROLE_ORDER as $bit => $role) {
            if ($role !== DeviceRole::UNKNOWN && $bits & (1 << $bit)) {
                return $role;
            }
        }

        return DeviceRole::UNKNOWN;
    }

    /**
     * Role of one links row: from the CDP platform, else the LLDP system description and the name.
     */
    private static function rowRole(array $link): string
    {
        return DeviceRole::fromNeighbor($link['remote_platform'] ?? null, $link['remote_version'] ?? null, $link['remote_hostname'] ?? null);
    }

    /**
     * Is a name a factory default that many devices share ("Switch", "router.local")?
     */
    private static function isGeneric(string $name): bool
    {
        return in_array($name, L2Builder::GENERIC_HOSTNAMES, true) || in_array(L2Builder::shortHost($name), L2Builder::GENERIC_HOSTNAMES, true);
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

        return isset($this->known[$deviceId]) ? (string) $this->known[$deviceId]['name'] : "device #$deviceId";
    }

    private function portName(int $portId): string
    {
        $port = $this->portRows[$portId] ?? null;

        return $port ? InterfaceName::short(InterfaceName::label($port)) : "port #$portId";
    }
}
