<?php

namespace Agoodley\LibrenmsTopology\Capacity;

use Agoodley\LibrenmsTopology\Interfaces\InterfaceAnalyzer;
use Agoodley\LibrenmsTopology\Stp\Finding;
use Agoodley\LibrenmsTopology\Stp\LoopEvidence;
use Agoodley\LibrenmsTopology\Stp\SyslogPatterns;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Support\Text;

/**
 * Switch port capacity: how many access ports each switch and each location (wiring closet) has, how many
 * are in use, free, shut down or err-disabled, how many of those have been unused for N days and how many
 * free ones have PoE.
 *
 * An access port is a physical Ethernet port of a switch that LibreNMS polls, except its uplinks (an
 * LLDP/CDP neighbor that is a switch, router or firewall, or a port-channel with such a member), trunks
 * without an end device on them (trunking now, or without an access VLAN on a switch whose other ports
 * have one: Cisco's spare uplinks and routed ports; LibreNMS gives other vendors' ports their PVID), ports
 * without hardware (ifOperStatus notPresent: an empty transceiver slot, a missing stack member) and those
 * the settings leave out by name or description. A port is in use while it is up; a port that is not is
 * err-disabled when the switch reported shutting it down after it was last up (ErrDisable), shut down
 * when its admin status is down, else free. Free and shut down ports are available.
 *
 * How long a port has been down: from the latest time it is known to have been up, which is its last
 * change (ifLastChange and the uptime: exact, PortClock), the plugin's record of it (PortUsage, kept with
 * every topology snapshot), the last ifOperStatus change of the eventlog that shows it up, or its latest
 * err-disable message (a switch err-disables a port for what came over its link) (sampled).
 * Without any, the port has been down at least since the switch started, since the record began
 * without ever seeing it up, or since the eventlog began without a change of it (at least): whether a
 * port down since a switch rebooted last week had been used before is unknown when no source reaches
 * back that far. Pure PHP: all data is passed in as arrays so it can be tested without LibreNMS.
 */
class CapacityAnalyzer
{
    /** Check codes and their category; the "checks to skip" setting accepts either. */
    public const CHECKS = [
        'capacity_low' => 'capacity',
        'poe_low' => 'capacity',
        'ports_err_disabled' => 'ports',
    ];

    /** Order of the categories within one severity. */
    private const CATEGORY_ORDER = ['capacity', 'ports'];

    /** ifOperStatus of a port in use: up, or dormant (a link waiting for authentication). */
    public const IN_USE_STATES = ['up', 'dormant'];

    /** States of a port: the counted (access) ports... */
    public const IN_USE = 'in_use';

    public const FREE = 'free';

    public const SHUTDOWN = 'shutdown';

    public const ERR_DISABLED = 'err_disabled';

    /** ...and the ports left out of the count. */
    public const UPLINK = 'uplink';

    public const TRUNK = 'trunk';

    public const NOT_PRESENT = 'not_present';

    public const EXCLUDED = 'excluded';

    /** States of the access ports. */
    public const COUNTED = [self::IN_USE, self::FREE, self::SHUTDOWN, self::ERR_DISABLED];

    /** How a port's down time is known: its last change, a sample of the record or the eventlog, a lower bound. */
    public const EXACT = 'exact';

    public const SAMPLED = 'sampled';

    public const AT_LEAST = 'at_least';

    /** An err-disable message and the change it caused lie this close (seconds): both are taken from the poller's clock. */
    private const SLACK = 900;

    /** Ports of one err-disabled finding listed in its detail. */
    private const LISTED = 5;

    /** Most ports a finding links to. */
    private const FINDING_PORTS = 50;

    /**
     * Fewest access ports of a location or switch the capacity checks look at: a device LibreNMS takes for a switch
     * with one or two Ethernet ports besides its uplinks is no access switch.
     */
    public const MIN_PORTS = 4;

    /** @var array<string, true> */
    private array $disabled;

    private int $days;

    private ?string $exclude;

    /** Is the exclude setting no valid regular expression? */
    private bool $excludeInvalid = false;

    /** @var Finding[] */
    private array $findings = [];

    /** @var array<int, true>|null port-channels with a member (or themselves) toward a switch, router or firewall */
    private ?array $uplinkLags = null;

    /** @var array<int, true> devices with an access VLAN on some of their ports (LibreNMS' ifVlan) */
    private array $vlanDevices = [];

    /**
     * @param  array<int, array>  $devices  device_id => name, location_id, location, uptime, last_polled, inserted (timestamps), url
     * @param  array<int, array>  $ports  port_id => device_id, ifName, ifDescr, ifAlias, ifType, ifSpeed, ifOperStatus,
     *                                    ifAdminStatus, ifLastChange, ifVlan, poll_time, url: the Ethernet ports of the devices
     * @param  array<int, array{uplink: bool, name: string}>  $neighbors  port_id => its LLDP/CDP neighbor: uplink when it is a
     *                                                                    switch, router or firewall
     * @param  array<int, int>  $lags  member port_id => aggregate port_id
     * @param  array<int, array>  $records  port_id => first_seen, last_up, err_disabled_at, err_disabled_message (PortUsage)
     * @param  array<int, int>  $eventUps  port_id => the latest time an ifOperStatus change of the eventlog shows it up
     * @param  array<int, int>  $eventSince  device_id => since when the eventlog holds the device's port changes
     * @param  array<int, array>  $errors  port_id => its latest err-disable message and recovery newer than the record
     *                                    (ErrDisable::latest())
     * @param  array<int, bool>  $poe  port_id => whether it is a PoE port, for the ports of switches LibreNMS has PoE data of
     *                                (a port with its PoE RRD file is one); the others are unknown
     * @param  int|null  $days  days without use that make a port unused (null: the setting)
     */
    public function __construct(
        private readonly array $devices,
        private readonly array $ports,
        private readonly array $neighbors,
        private readonly array $lags,
        private readonly array $records,
        private readonly array $eventUps,
        private readonly array $eventSince,
        private readonly array $errors,
        private readonly array $poe,
        private readonly PluginSettings $settings,
        private readonly int $now,
        ?int $days = null,
    ) {
        $this->disabled = array_fill_keys(array_map('strtolower', $settings->list('capacity_disabled_checks')), true);
        $this->days = $days !== null ? max(1, min(3650, $days)) : self::days($settings);
        $pattern = trim((string) ($settings->all()['capacity_exclude_ports'] ?? ''));
        $this->exclude = $pattern === '' ? null : SyslogPatterns::compile($pattern);
        $this->excludeInvalid = $pattern !== '' && $this->exclude === null;
        foreach ($ports as $port) {
            if (self::vlan($port) !== null) {
                $this->vlanDevices[(int) ($port['device_id'] ?? 0)] = true;
            }
        }
    }

    /**
     * Days without use from which an available port counts as unused (the setting, 1-3650).
     */
    public static function days(PluginSettings $settings): int
    {
        return $settings->int('capacity_unused_days', 1, 3650);
    }

    /**
     * The analysis: every port with its state, the counts of each switch, location and in all, and the findings.
     */
    public function analyze(): array
    {
        $rows = [];
        foreach ($this->ports as $portId => $port) {
            $deviceId = (int) ($port['device_id'] ?? 0);
            if (isset($this->devices[$deviceId])) {
                $rows[] = $this->portRow((int) $portId, $port, $this->devices[$deviceId]);
            }
        }
        $rows = self::sortRows($rows);

        $byDevice = [];
        foreach ($this->devices as $deviceId => $device) {
            $byDevice[(int) $deviceId] = self::emptyCounts();
        }
        foreach ($rows as $row) {
            self::add($byDevice[$row['device_id']], $row);
        }

        $devices = [];
        $byLocation = [];
        foreach ($this->devices as $deviceId => $device) {
            $deviceId = (int) $deviceId;
            $locationId = (int) ($device['location_id'] ?? 0);
            $counts = self::finish($byDevice[$deviceId]);
            $devices[] = [
                'device_id' => $deviceId,
                'name' => self::name($device),
                'location_id' => $locationId ?: null,
                'location' => $locationId ? (string) ($device['location'] ?? '') : '',
                'uptime' => self::int($device['uptime'] ?? null),
                'boot' => PortClock::boot(self::int($device['uptime'] ?? null), self::int($device['last_polled'] ?? null)),
                'last_polled' => self::int($device['last_polled'] ?? null),
                'url' => (string) ($device['url'] ?? ''),
            ] + $counts;
            $byLocation[$locationId]['devices'][] = $deviceId;
            $byLocation[$locationId]['name'] ??= $locationId ? (string) ($device['location'] ?? '') : '';
            $byLocation[$locationId]['counts'] ??= self::emptyCounts();
            self::merge($byLocation[$locationId]['counts'], $byDevice[$deviceId]);
        }

        $total = self::emptyCounts();
        $locations = [];
        foreach ($byLocation as $locationId => $location) {
            self::merge($total, $location['counts']);
            $locations[] = ['location_id' => $locationId ?: null, 'name' => $location['name'], 'devices' => $location['devices']]
                + self::finish($location['counts']);
        }
        $locations = self::sortLocations($locations);
        $order = [];
        foreach ($locations as $i => $location) {
            $order[(int) $location['location_id']] = $i;
        }
        usort($devices, fn ($a, $b) => [$order[(int) $a['location_id']] ?? 0, strtolower($a['name']), $a['device_id']]
            <=> [$order[(int) $b['location_id']] ?? 0, strtolower($b['name']), $b['device_id']]);

        $this->checkCapacity($locations, $devices);
        $this->checkErrDisabled($rows);
        $findings = self::sortFindings(array_map(fn (Finding $f) => $f->toArray() + ['category' => self::CHECKS[$f->code] ?? 'other'], $this->findings));

        return [
            'days' => $this->days,
            'summary' => self::finish($total) + ['devices' => count($devices), 'locations' => count($locations)] + self::health($findings),
            'locations' => $locations,
            'devices' => $devices,
            'ports' => $rows,
            'findings' => $findings,
            'device_names' => array_map(fn (array $device) => self::name($device), $this->devices),
            'notices' => $this->excludeInvalid ? ['The ports not counted (capacity_exclude_ports) are no valid regular expression: every port is counted.'] : [],
        ];
    }

    /**
     * One port: its state, how long it has been down and how that is known, whether that makes it unused,
     * PoE, its err-disable message and its neighbor.
     */
    private function portRow(int $portId, array $port, array $device): array
    {
        $name = InterfaceName::label($port);
        $oper = strtolower((string) ($port['ifOperStatus'] ?? ''));
        $admin = strtolower((string) ($port['ifAdminStatus'] ?? ''));
        $neighbor = $this->neighbors[$portId] ?? null;
        $row = [
            'port_id' => $portId,
            'device_id' => (int) $port['device_id'],
            'device' => self::name($device),
            'location' => (int) ($device['location_id'] ?? 0) ? (string) ($device['location'] ?? '') : '',
            'port' => InterfaceName::short($name),
            'name' => $name,
            'alias' => trim((string) ($port['ifAlias'] ?? '')),
            'vlan' => self::vlan($port),
            'speed' => self::int($port['ifSpeed'] ?? null),
            'oper' => $oper,
            'admin' => $admin,
            'state' => null,
            'down_since' => null,
            'age_kind' => null,
            'age_days' => null,
            'unused' => null,
            'poe' => $this->poe[$portId] ?? null,
            'err' => null,
            'neighbor' => $neighbor['name'] ?? '',
            'url' => (string) ($port['url'] ?? ''),
        ];

        $row['state'] = match (true) {
            $oper === 'notpresent' => self::NOT_PRESENT,
            $this->excluded($port, $name) => self::EXCLUDED,
            $this->isUplink($portId) => self::UPLINK,
            $this->isTrunk($portId, $port) => self::TRUNK,
            in_array($oper, self::IN_USE_STATES, true) => self::IN_USE,
            default => null,
        };
        if ($row['state'] !== null) {
            return $row;
        }

        // a port that is not in use: since when, and why
        [$downSince, $kind, $lastUp] = $this->downSince($portId, $port, $device);
        $row['down_since'] = $downSince;
        $row['age_kind'] = $kind;
        $row['age_days'] = $downSince === null ? null : intdiv(max(0, $this->now - $downSince), 86400);
        $error = $admin === 'down' ? null : $this->errDisabled($portId, $device, $lastUp);
        $row['err'] = $error;
        $row['state'] = match (true) {
            $error !== null => self::ERR_DISABLED,
            $admin === 'down' => self::SHUTDOWN,
            default => self::FREE,
        };
        if ($downSince !== null && $row['state'] !== self::ERR_DISABLED) {
            $long = $this->now - $downSince >= 86400 * $this->days;
            $row['unused'] = $kind === self::AT_LEAST && ! $long ? null : $long;
        }

        return $row;
    }

    /**
     * When the port went down and how that is known, and the latest time it is known up: [down_since, kind, last_up].
     *
     * @return array{0: ?int, 1: ?string, 2: ?int}
     */
    private function downSince(int $portId, array $port, array $device): array
    {
        $uptime = self::int($device['uptime'] ?? null);
        $polled = self::int($device['last_polled'] ?? null);
        $change = PortClock::lastChange($uptime, $polled, $port['ifLastChange'] ?? null);
        $boot = PortClock::boot($uptime, $polled);

        $record = $this->records[$portId] ?? null;
        // a switch err-disables a port for what came over its link: the port was in use then
        $sampled = max(self::int($record['last_up'] ?? null) ?? PHP_INT_MIN, $this->eventUps[$portId] ?? PHP_INT_MIN,
            self::int($record['err_disabled_at'] ?? null) ?? PHP_INT_MIN, self::int($this->errors[$portId]['time'] ?? null) ?? PHP_INT_MIN);
        $sampled = $sampled === PHP_INT_MIN ? null : $sampled;
        $exact = $change['time'] !== null && ! $change['at_boot'] ? $change['time'] : null;

        // the eventlog logs a change at the poll after it: an exact change that close to a sample is the same one
        if ($exact !== null && ($sampled === null || $exact >= $sampled - self::SLACK)) {
            // the counter may have started over since: the change can be earlier, never later
            return [$exact, $change['wrapped'] ? self::AT_LEAST : self::EXACT, $exact];
        }
        if ($sampled !== null) {
            return [min($sampled, $this->now), self::SAMPLED, $sampled];
        }

        // never known up: down at least since the switch started, the record began or the eventlog holds its changes
        $bounds = array_filter([
            $boot,
            $record !== null ? self::int($record['first_seen'] ?? null) : null,
            $this->eventSince[(int) $port['device_id']] ?? null,
        ], fn ($time) => $time !== null && $time <= $this->now);

        return [$bounds ? min($bounds) : null, $bounds ? self::AT_LEAST : null, null];
    }

    /**
     * The err-disable message that still holds for a port that is not in use and not shut down: the latest one
     * (ErrDisable::holding(): not followed by a recovery), from after the switch started and not followed by the
     * port being up again; null when there is none.
     *
     * @return array{time: int, message: string}|null
     */
    private function errDisabled(int $portId, array $device, ?int $lastUp): ?array
    {
        $record = $this->records[$portId] ?? null;
        $latest = ErrDisable::holding($this->errors[$portId] ?? null, isset($record['err_disabled_at']) && $record['err_disabled_at'] !== null
            ? ['time' => (int) $record['err_disabled_at'], 'message' => (string) ($record['err_disabled_message'] ?? '')] : null);
        if ($latest === null) {
            return null;
        }
        $boot = PortClock::boot(self::int($device['uptime'] ?? null), self::int($device['last_polled'] ?? null));
        if (($boot !== null && $latest['time'] < $boot) || ($lastUp !== null && $lastUp > $latest['time'] + self::SLACK)) {
            return null;
        }

        return $latest;
    }

    /**
     * Is the port left out by the setting (its name, short name or description matches)?
     */
    private function excluded(array $port, string $name): bool
    {
        if ($this->exclude === null) {
            return false;
        }
        foreach (array_unique([$name, (string) ($port['ifDescr'] ?? ''), InterfaceName::short($name), trim((string) ($port['ifAlias'] ?? ''))]) as $text) {
            if ($text !== '' && @preg_match($this->exclude, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the port lead to a switch, router or firewall, itself or through its port-channel?
     */
    private function isUplink(int $portId): bool
    {
        if ($this->neighbors[$portId]['uplink'] ?? false) {
            return true;
        }
        $aggregate = $this->lags[$portId] ?? null;
        if ($aggregate === null) {
            return false;
        }
        if ($this->uplinkLags === null) {
            $this->uplinkLags = [];
            foreach ($this->lags as $member => $parent) {
                if (($this->neighbors[$member]['uplink'] ?? false) || ($this->neighbors[$parent]['uplink'] ?? false)) {
                    $this->uplinkLags[(int) $parent] = true;
                }
            }
        }

        return isset($this->uplinkLags[$aggregate]);
    }

    /**
     * Is the port a trunk without an end device on it: trunking now (ifTrunk), or without an access VLAN on a switch whose
     * other ports have one (a Cisco port in trunk mode that is down, or a routed port)? An LLDP/CDP neighbor that is no
     * uplink (an access point, a phone, a server) makes it an access port in use.
     */
    private function isTrunk(int $portId, array $port): bool
    {
        if (isset($this->neighbors[$portId])) {
            return false;
        }

        return trim((string) ($port['ifTrunk'] ?? '')) !== ''
            || (self::vlan($port) === null && isset($this->vlanDevices[(int) ($port['device_id'] ?? 0)]));
    }

    /**
     * The port's access (or native) VLAN as LibreNMS has it, null for none.
     */
    private static function vlan(array $port): ?int
    {
        $vlan = trim((string) ($port['ifVlan'] ?? ''));

        return ctype_digit($vlan) && (int) $vlan > 0 ? (int) $vlan : null;
    }

    /**
     * capacity_low and poe_low: a location (a switch without one on its own) of MIN_PORTS access ports or more whose
     * available access ports, or PoE ports, are fewer than the setting's share of them.
     *
     * @param  array<int, array>  $locations
     * @param  array<int, array>  $devices
     */
    private function checkCapacity(array $locations, array $devices): void
    {
        $percent = $this->settings->int('capacity_low_percent', 0, 100);
        if ($percent === 0) {
            return;
        }
        $units = [];
        foreach ($locations as $location) {
            if ($location['location_id'] !== null) {
                $units[] = ['in ' . $location['name'], $location['devices'], $location];
            }
        }
        foreach ($devices as $device) {
            if ($device['location_id'] === null) {
                $units[] = ['on ' . $device['name'], [$device['device_id']], $device];
            }
        }
        foreach ($units as [$where, $deviceIds, $counts]) {
            if ($this->enabled('capacity_low') && $counts['ports'] >= self::MIN_PORTS && $counts['available'] * 100 < $percent * $counts['ports']) {
                $this->findings[] = new Finding(Finding::WARNING, 'capacity_low',
                    ($counts['available'] ? 'few free access ports ' : 'no free access port ') . $where,
                    sprintf('%s of %s available (%d free, %d shut down)%s; %s in use (%s%%)%s.',
                        number_format($counts['available']), Text::count($counts['ports'], 'access port'), $counts['free'], $counts['shutdown'],
                        $counts['available'] ? ', ' . number_format($counts['unused']) . ' of them unused for ' . Text::count($this->days, 'day') . ' or more' : '',
                        number_format($counts['in_use']), self::percentText($counts['use_percent']),
                        $counts['err_disabled'] ? '; ' . Text::count($counts['err_disabled'], 'more port is', 'more ports are') . ' err-disabled' : ''),
                    'Reclaim ports unused for long (the ports list), re-enable err-disabled ports once their cause is fixed, or plan another switch there.',
                    devices: $deviceIds);
            }
            if ($this->enabled('poe_low') && ($counts['poe'] ?? 0) >= self::MIN_PORTS && $counts['poe_available'] * 100 < $percent * $counts['poe']) {
                $this->findings[] = new Finding(Finding::WARNING, 'poe_low',
                    ($counts['poe_available'] ? 'few free PoE ports ' : 'no free PoE port ') . $where,
                    sprintf('%s of %s available.', number_format($counts['poe_available']), Text::count($counts['poe'], 'PoE access port')),
                    'Phones, access points and cameras need PoE ports: move devices that need no power to ports without PoE, or plan a PoE switch there.',
                    devices: $deviceIds);
            }
        }
    }

    /**
     * ports_err_disabled: the access ports of a switch that are err-disabled now.
     *
     * @param  array<int, array>  $rows
     */
    private function checkErrDisabled(array $rows): void
    {
        if (! $this->enabled('ports_err_disabled')) {
            return;
        }
        $byDevice = [];
        foreach ($rows as $row) {
            if ($row['state'] === self::ERR_DISABLED) {
                $byDevice[$row['device_id']][] = $row;
            }
        }
        foreach ($byDevice as $deviceId => $list) {
            usort($list, fn ($a, $b) => $b['err']['time'] <=> $a['err']['time']);
            $listed = array_map(fn ($row) => $row['port'] . ' since ' . InterfaceAnalyzer::time($row['err']['time']) . ' (' . $row['err']['message'] . ')',
                array_slice($list, 0, self::LISTED));
            $this->findings[] = new Finding(Finding::INFO, 'ports_err_disabled', 'err-disabled ports on ' . $list[0]['device'],
                Text::count(count($list), 'port') . ' shut down by the switch and not up since: ' . implode('; ', $listed)
                . (count($list) > self::LISTED ? '; and ' . (count($list) - self::LISTED) . ' more.' : '.'),
                LoopEvidence::SYSLOG['err_disabled']['fix'],
                devices: [(int) $deviceId], ports: array_slice(array_column($list, 'port_id'), 0, self::FINDING_PORTS));
        }
    }

    private function enabled(string $code): bool
    {
        return ! isset($this->disabled[$code]) && ! isset($this->disabled[self::CHECKS[$code]]);
    }

    /**
     * Counts of no port: the access ports by state, the available ones by age, the PoE ones (null while no port has PoE
     * data) and the ports left out.
     */
    private static function emptyCounts(): array
    {
        return ['ports' => 0, 'in_use' => 0, 'available' => 0, 'free' => 0, 'shutdown' => 0, 'err_disabled' => 0,
            'unused' => 0, 'recent' => 0, 'unknown_age' => 0, 'poe' => null, 'poe_available' => null,
            'uplinks' => 0, 'trunks' => 0, 'not_present' => 0, 'excluded' => 0];
    }

    private static function add(array &$counts, array $row): void
    {
        switch ($row['state']) {
            case self::UPLINK:
                $counts['uplinks']++;

                return;
            case self::TRUNK:
                $counts['trunks']++;

                return;
            case self::NOT_PRESENT:
                $counts['not_present']++;

                return;
            case self::EXCLUDED:
                $counts['excluded']++;

                return;
        }
        $counts['ports']++;
        $counts[$row['state']]++;
        $available = in_array($row['state'], [self::FREE, self::SHUTDOWN], true);
        if ($available) {
            $counts['available']++;
            $counts[match ($row['unused']) {
                true => 'unused',
                false => 'recent',
                default => 'unknown_age',
            }]++;
        }
        if ($row['poe'] !== null) {
            $counts['poe'] = ($counts['poe'] ?? 0) + ($row['poe'] ? 1 : 0);
            $counts['poe_available'] = ($counts['poe_available'] ?? 0) + ($row['poe'] && $available ? 1 : 0);
        }
    }

    private static function merge(array &$counts, array $more): void
    {
        foreach ($more as $key => $value) {
            if ($value !== null && $key !== 'use_percent') {
                $counts[$key] = ($counts[$key] ?? 0) + $value;
            }
        }
    }

    /**
     * The counts with the share of the access ports in use (null without access ports).
     */
    private static function finish(array $counts): array
    {
        return $counts + ['use_percent' => $counts['ports'] > 0 ? round($counts['in_use'] / $counts['ports'] * 100, 1) : null];
    }

    /**
     * The worst severity and the findings per severity (the health card).
     */
    private static function health(array $findings): array
    {
        $counts = [Finding::CRITICAL => 0, Finding::WARNING => 0, Finding::INFO => 0];
        foreach ($findings as $finding) {
            $counts[$finding['severity']]++;
        }

        return ['status' => Finding::worst(array_column($findings, 'severity')), 'findings' => $counts];
    }

    /**
     * Findings in page order: the worst first, then by category and title.
     */
    public static function sortFindings(array $findings): array
    {
        $categoryOrder = array_flip(self::CATEGORY_ORDER);
        usort($findings, fn ($a, $b) => [Finding::SEVERITY_ORDER[$a['severity']], $categoryOrder[$a['category']] ?? 99, strtolower($a['title'])]
            <=> [Finding::SEVERITY_ORDER[$b['severity']], $categoryOrder[$b['category']] ?? 99, strtolower($b['title'])]);

        return $findings;
    }

    /**
     * Ports by device, then in the order of their names (Gi1/0/2 before Gi1/0/10).
     */
    public static function sortRows(array $rows): array
    {
        usort($rows, fn ($a, $b) => strcasecmp($a['device'], $b['device']) ?: ($a['device_id'] <=> $b['device_id'])
            ?: strnatcasecmp($a['name'], $b['name']) ?: ($a['port_id'] <=> $b['port_id']));

        return $rows;
    }

    /**
     * The fullest locations first, then by name; the switches without a location last.
     */
    private static function sortLocations(array $locations): array
    {
        usort($locations, fn ($a, $b) => [$a['location_id'] === null, -($a['use_percent'] ?? -1), strtolower($a['name'])]
            <=> [$b['location_id'] === null, -($b['use_percent'] ?? -1), strtolower($b['name'])]);

        return $locations;
    }

    /**
     * The ports a list of the page shows: not_in_use (free, shut down or err-disabled: the default), unused (available
     * and unused for the days), poe (available PoE ports), err_disabled, shutdown, in_use, uplinks (and the other
     * ports left out), all.
     */
    public static function filter(array $rows, string $list): array
    {
        return array_values(array_filter($rows, fn (array $row) => match ($list) {
            'all' => true,
            'unused' => $row['unused'] === true && in_array($row['state'], [self::FREE, self::SHUTDOWN], true),
            'poe' => $row['poe'] === true && in_array($row['state'], [self::FREE, self::SHUTDOWN], true),
            'err_disabled' => $row['state'] === self::ERR_DISABLED,
            'shutdown' => $row['state'] === self::SHUTDOWN,
            'in_use' => $row['state'] === self::IN_USE,
            'uplinks' => in_array($row['state'], [self::UPLINK, self::TRUNK, self::NOT_PRESENT, self::EXCLUDED], true),
            default => in_array($row['state'], [self::FREE, self::SHUTDOWN, self::ERR_DISABLED], true),
        }));
    }

    /** Lists filter() knows, with their label. */
    public const LISTS = [
        'not_in_use' => 'Ports not in use',
        'unused' => 'Unused for the days',
        'poe' => 'Available PoE ports',
        'err_disabled' => 'Err-disabled',
        'shutdown' => 'Shut down',
        'in_use' => 'In use',
        'uplinks' => 'Not counted (uplinks ...)',
        'all' => 'All ports',
    ];

    /**
     * A share of the ports as the pages show it ("97.5", "100", "-").
     */
    public static function percentText(?float $percent): string
    {
        return $percent === null ? '-' : rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.');
    }

    private static function name(array $device): string
    {
        foreach (['name', 'display', 'hostname'] as $field) {
            if (trim((string) ($device[$field] ?? '')) !== '') {
                return (string) $device[$field];
            }
        }

        return '';
    }

    private static function int(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
