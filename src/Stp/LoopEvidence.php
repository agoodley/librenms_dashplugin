<?php

namespace Agoodley\LibrenmsTopology\Stp;

use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * Evidence of bridging loops that the spanning tree data does not show: broadcast and multicast
 * storms on inter-switch links (the port counters LibreNMS polls) and the messages switches log
 * when they detect a loop or protect against one (LibreNMS' syslog). Pure PHP like StpAnalyzer;
 * its findings join the spanning tree page.
 */
class LoopEvidence
{
    /** Check codes and their category; the STP page's "disabled checks" setting accepts either. */
    public const CHECKS = [
        'broadcast_storm' => 'storms',
        'multicast_storm' => 'storms',
        'loop_detected' => 'syslog',
        'udld' => 'syslog',
        'bpdu_guard' => 'syslog',
        'loop_guard' => 'syslog',
        'root_guard' => 'syslog',
        'storm_control' => 'syslog',
        'mac_flapping' => 'syslog',
        'syslog_native_vlan' => 'syslog',
        'syslog_duplex_mismatch' => 'syslog',
        'err_disabled' => 'syslog',
    ];

    /**
     * Syslog checks in matching order: a message counts for the first check whose pattern (a setting,
     * see SyslogPatterns) matches its program and text, so a port err-disabled by BPDU guard is BPDU
     * guard, not err-disable.
     */
    public const SYSLOG = [
        'loop_detected' => [
            'setting' => 'stp_syslog_loop_detected', 'severity' => Finding::CRITICAL,
            'title' => 'switches detected a loop', 'messages' => 'loop detection messages',
            'fix' => 'A switch saw its own frames come back (loop protection or keepalive loopback) and usually disabled the port. '
                . 'Find the cable or the small unmanaged switch that closes the loop behind that port, remove it, then re-enable the port.',
        ],
        'udld' => [
            'setting' => 'stp_syslog_udld', 'severity' => Finding::WARNING,
            'title' => 'unidirectional links (UDLD)', 'messages' => 'UDLD messages',
            'fix' => 'One direction of these links is lost, which lets spanning tree unblock a port and form a loop. Check both fibres, '
                . 'the transceivers and the patching, then re-enable the port.',
        ],
        'bpdu_guard' => [
            'setting' => 'stp_syslog_bpdu_guard', 'severity' => Finding::WARNING,
            'title' => 'BPDU guard disabled ports', 'messages' => 'BPDU guard messages',
            'fix' => 'A switch or bridge was plugged into an edge port, and BPDU guard shut the port. Find what was connected, move switches '
                . 'to uplink ports, then re-enable the port (or configure errdisable recovery).',
        ],
        'loop_guard' => [
            'setting' => 'stp_syslog_loop_guard', 'severity' => Finding::WARNING,
            'title' => 'loop guard blocked ports', 'messages' => 'loop guard messages',
            'fix' => 'BPDUs stopped arriving on a blocking port, usually a unidirectional link or a neighbor that stopped sending BPDUs; '
                . 'without loop guard the port would have started forwarding into a loop. Check the link in both directions.',
        ],
        'root_guard' => [
            'setting' => 'stp_syslog_root_guard', 'severity' => Finding::WARNING,
            'title' => 'root guard blocked ports', 'messages' => 'root guard messages',
            'fix' => 'A switch behind these ports claimed to be a better root bridge. Raise its bridge priority (or find the switch that '
                . 'was added) so the root stays in the core.',
        ],
        'storm_control' => [
            'setting' => 'stp_syslog_storm_control', 'severity' => Finding::WARNING,
            'title' => 'storm control triggered or excessive broadcasts', 'messages' => 'storm messages',
            'fix' => 'These ports saw a broadcast or multicast storm: storm control dropped or shut down traffic, or the switch reported '
                . 'excessive broadcasts. Look for a loop or a misbehaving host behind them; storm control limits the damage, it does not '
                . 'remove the cause.',
        ],
        'mac_flapping' => [
            'setting' => 'stp_syslog_mac_flapping', 'severity' => Finding::WARNING,
            'title' => 'MAC address flapping', 'messages' => 'MAC move messages',
            'fix' => 'The same MAC addresses are learned on two ports in turn. Many addresses at once means a loop between the listed ports; '
                . 'a single address is usually a server with misconfigured NIC teaming or a virtual machine moving.',
        ],
        'syslog_native_vlan' => [
            'setting' => 'stp_syslog_native_vlan', 'severity' => Finding::WARNING,
            'title' => 'native VLAN mismatch reported by the switches', 'messages' => 'native VLAN messages',
            'fix' => 'Configure the same native VLAN at both ends of these trunks. A mismatch leaks frames between VLANs and makes PVST+ '
                . 'block the VLAN (inconsistent peer VLAN).',
        ],
        'syslog_duplex_mismatch' => [
            'setting' => 'stp_syslog_duplex', 'severity' => Finding::WARNING,
            'title' => 'duplex mismatch reported by CDP', 'messages' => 'duplex mismatch messages',
            'fix' => 'Set both ends of these links to the same speed and duplex, preferably auto-negotiation on both.',
        ],
        'err_disabled' => [
            'setting' => 'stp_syslog_err_disabled', 'severity' => Finding::WARNING,
            'title' => 'err-disabled ports', 'messages' => 'err-disable messages',
            'fix' => 'These ports were shut down by the switch; the message names the reason. Fix the cause, then re-enable the port with '
                . 'shutdown / no shutdown or configure errdisable recovery.',
        ],
    ];

    /** Bits a minimum size frame takes on the wire: 64 bytes plus 20 bytes of preamble and gap. */
    private const BITS_PER_MIN_FRAME = 672;

    /** Counters not refreshed for this long (seconds) are ignored: nobody polls the port any more. */
    private const STALE_COUNTERS = 86400;

    /** Counters older than this (seconds, and than two poll periods) are shown with their age. */
    private const OLD_COUNTERS = 900;

    /** Inter-switch ports listed in the evidence table besides the storms (the busiest ones). */
    private const TABLE_PORTS = 25;

    /** Syslog evidence rows kept per check, and ports, MAC addresses and VLANs kept per row. */
    private const ROW_LIMIT = 50;

    private const LIST_LIMIT = 10;

    /** Distinct MAC addresses flapping (on one switch or several) from which MAC flapping is a loop. */
    private const LOOP_MACS = 10;

    /** @var array<string, true> disabled check codes and categories */
    private array $disabled;

    private SyslogPatterns $patterns;

    /** @var Finding[] */
    private array $findings = [];

    /** @var string[] problems with the settings, shown on the page */
    private array $notices = [];

    /** @var array<int, array<string, int>>|null device_id => normalized port name => port_id (built on demand) */
    private ?array $portIndex = null;

    /**
     * @param  array<int, array>  $devices  device_id => device row (display, hostname, role, status)
     * @param  array<int, array>  $links  rows of the links table of these devices
     * @param  array<int, array>  $ports  port_id => port row with its counters and rates (LoopEvidenceRepository::portRates())
     * @param  array<int, array>  $syslog  syslog rows (device_id, timestamp, program, msg; code when the reader classified them already)
     * @param  array<int, array>  $syslogPorts  port_id => port row (device_id, ifName, ifDescr) of the devices in $syslog
     * @param  SyslogPatterns|null  $patterns  the patterns the syslog was read with, to report their problems once
     */
    public function __construct(
        private array $devices,
        private array $links,
        private array $ports,
        private array $syslog,
        private array $syslogPorts,
        private PluginSettings $settings,
        private ?int $now = null,
        ?SyslogPatterns $patterns = null,
    ) {
        $this->now ??= time();
        $this->disabled = array_fill_keys(array_map('strtolower', $settings->list('stp_disabled_checks')), true);
        $this->patterns = $patterns ?? new SyslogPatterns($settings);
    }

    /**
     * Run the storm and syslog checks.
     *
     * @param  array{syslog_hidden?: bool, syslog_truncated?: bool, syslog_limited?: int, syslog_unsearched?: int, syslog_stopped?: ?string, syslog_scanned?: int, syslog_devices?: int, syslog_scope?: int}  $context
     *     what the caller could read (SyslogTurns): syslog hidden from this user, more matching messages than were kept,
     *     devices whose older messages of the window were not searched, devices not searched at all, the limit that
     *     stopped the reading, the messages searched, devices that sent syslog in the window and devices asked for
     * @return array{findings: array, device_names: array<int, string>, evidence: array}
     */
    public function analyze(array $context = []): array
    {
        $this->findings = [];
        $this->notices = [];
        $hours = $this->settings->int('stp_syslog_hours', 1, 720);
        $hidden = ! empty($context['syslog_hidden']);

        $storms = $this->checkStorms();
        $syslog = $hidden ? [] : $this->checkSyslog($hours);
        if (! $hidden) {
            $this->notices = array_merge($this->notices, $this->patterns->notices());
        }
        $limited = (int) ($context['syslog_limited'] ?? 0);
        $unsearched = (int) ($context['syslog_unsearched'] ?? 0);
        if ($unsearched) {
            $limit = match ($context['syslog_stopped'] ?? null) {
                'matches' => 'keeps up to ' . number_format(SyslogTurns::MATCHES) . ' matching messages',
                'reads' => 'reads the syslog at most ' . SyslogTurns::READS . ' times',
                default => 'searches up to ' . number_format(SyslogTurns::SCAN) . ' messages',
            };
            $this->notices[] = 'The syslog of ' . number_format($unsearched) . ' of ' . number_format((int) ($context['syslog_devices'] ?? 0)) . " devices that sent messages in the last $hours hours "
                . "was not searched: a page view $limit, the most recently active devices first. Filter by device group or location to see the others.";
        }
        if (! empty($context['syslog_truncated'])) {
            $this->notices[] = "More syslog messages of the last $hours hours match the checks than are analyzed at once: only the newest "
                . number_format(count($this->syslog)) . ', taken in turns from each device, were.';
        } elseif ($limited) {
            $this->notices[] = number_format((int) ($context['syslog_scanned'] ?? 0)) . " syslog messages of the last $hours hours were searched, "
                . 'which did not reach back to the start of the window for ' . number_format($limited) . ($limited === 1 ? ' device' : ' devices')
                . '; shorten the syslog window in the settings to search all of it.';
        }

        $findings = array_map(fn (Finding $f) => $f->toArray() + ['category' => self::CHECKS[$f->code] ?? 'other'], $this->findings);
        $names = [];
        foreach ($findings as $finding) {
            foreach ($finding['devices'] as $deviceId) {
                $names[$deviceId] = $this->deviceName($deviceId);
            }
        }

        return [
            'findings' => $findings,
            'device_names' => $names,
            'evidence' => [
                'storms' => $storms['rows'],
                'inter_switch_ports' => $storms['ports'],
                'ports_with_counters' => $storms['counted'],
                'storm_threshold' => $this->settings->int('stp_storm_pps', 0),
                'storms_off' => $storms['off'],
                'syslog' => $syslog,
                'syslog_hours' => $hours,
                'syslog_hidden' => $hidden,
                'syslog_off' => ! $this->patterns->usable(),
                'syslog_devices' => (int) ($context['syslog_devices'] ?? 0),
                'syslog_scope' => (int) ($context['syslog_scope'] ?? 0),
                'notices' => $this->notices,
            ],
        ];
    }

    /**
     * Add the loop evidence to a spanning tree analysis (StpAnalyzer::analyze()): findings sorted
     * like the page, names, the evidence tables and the summary counted again.
     */
    public static function merge(array $result, array $evidence): array
    {
        $result['findings'] = array_merge($result['findings'], $evidence['findings']);
        usort($result['findings'], fn ($a, $b) => [Finding::SEVERITY_ORDER[$a['severity']], $a['vlan'] ?? -1, $a['title']]
            <=> [Finding::SEVERITY_ORDER[$b['severity']], $b['vlan'] ?? -1, $b['title']]);
        $result['device_names'] += $evidence['device_names'];
        $result['loop_evidence'] = $evidence['evidence'];
        $result['summary'] = StpAnalyzer::summarize($result['domains'], $result['bridges'], $result['blocked'], $result['findings'],
            $result['summary']['recent_tc_window']);

        return $result;
    }

    /**
     * Local ports of links between two switches: the local device is a switch, and the far end is a
     * network device or an unmanaged neighbor that is not an end device (phones, access points and
     * servers do not carry a loop on). A neighbor without a known role counts as a switch.
     *
     * @param  array<int, array>  $devices  device_id => device row with role
     * @return array<int, array{device_id: int, neighbor: array{device_id: ?int, name: string, port: string}}> port_id => link
     */
    public static function interSwitchPorts(array $devices, array $links): array
    {
        $bridging = [DeviceRole::SWITCH, DeviceRole::L3SWITCH];
        $ends = [DeviceRole::PHONE, DeviceRole::WIRELESS, DeviceRole::SERVER, DeviceRole::OTHER];
        $ports = [];
        foreach ($links as $link) {
            $deviceId = (int) $link['local_device_id'];
            $portId = (int) $link['local_port_id'];
            if (! $portId || isset($ports[$portId]) || ! in_array($devices[$deviceId]['role'] ?? '', $bridging, true)) {
                continue;
            }
            $remote = (int) ($link['remote_device_id'] ?? 0);
            $role = isset($devices[$remote])
                ? ($devices[$remote]['role'] ?? DeviceRole::UNKNOWN)
                : DeviceRole::fromPlatform($link['remote_platform'] ?? '', $link['remote_hostname'] ?? '');
            if (in_array($role, $ends, true)) {
                continue;
            }
            $ports[$portId] = [
                'device_id' => $deviceId,
                'neighbor' => [
                    'device_id' => $remote ?: null,
                    'name' => isset($devices[$remote]) ? self::nameOf($devices[$remote]) : (string) ($link['remote_hostname'] ?? ''),
                    'port' => InterfaceName::short((string) ($link['remote_port'] ?? '')),
                ],
            ];
        }

        return $ports;
    }

    // ---------------------------------------------------------------- storms

    /**
     * Broadcast and multicast rates of the inter-switch ports at the last poll. A port is a storm
     * when the rate reaches the threshold and either dominates its traffic or takes a noticeable part
     * of the link: a loop sends the same broadcasts around until links or switch CPUs saturate.
     *
     * @return array{rows: array, ports: int, counted: int, off: bool}
     */
    private function checkStorms(): array
    {
        $threshold = $this->settings->int('stp_storm_pps', 0);
        $share = $this->settings->int('stp_storm_percent', 1, 100) / 100;
        $links = self::interSwitchPorts($this->devices, $this->links);
        $checked = array_filter(['broadcast', 'multicast'], fn ($kind) => $threshold > 0 && $this->enabled($kind . '_storm'));

        $rows = [];
        foreach ($links as $portId => $link) {
            $row = $this->stormRow($portId, $link);
            if ($row === null) {
                continue;
            }
            foreach (['broadcast', 'multicast'] as $kind) {
                $row[$kind . '_level'] = in_array($kind, $checked, true) ? self::stormLevel($kind, $row[$kind], $threshold, $share) : null;
            }
            $rows[] = $row;
        }

        foreach (['broadcast', 'multicast'] as $kind) {
            $this->stormFinding($kind, array_values(array_filter($rows, fn ($row) => $row[$kind . '_level'] !== null)));
        }

        // the table: storms first, then the busiest other inter-switch ports for comparison
        $peak = fn ($row) => max($row['broadcast']['pps'] ?? 0, $row['multicast']['pps'] ?? 0);
        usort($rows, fn ($a, $b) => [$a['broadcast_level'] === null && $a['multicast_level'] === null, -$peak($a), $a['name'], $a['port']]
            <=> [$b['broadcast_level'] === null && $b['multicast_level'] === null, -$peak($b), $b['name'], $b['port']]);
        $storms = array_filter($rows, fn ($row) => $row['broadcast_level'] !== null || $row['multicast_level'] !== null);

        return [
            'rows' => array_slice($rows, 0, count($storms) + self::TABLE_PORTS),
            'ports' => count($links),
            'counted' => count($rows),
            'off' => ! $checked,
        ];
    }

    /**
     * Rates of one inter-switch port, the busier direction of each kind; null when the counters are
     * missing or out of date (device or port down, port no longer polled). Counters older than a few
     * polls carry their age.
     */
    private function stormRow(int $portId, array $link): ?array
    {
        $port = $this->ports[$portId] ?? null;
        $device = $this->devices[$link['device_id']] ?? null;
        if (! $port || ! $device || empty($device['status']) || strtolower((string) ($port['ifOperStatus'] ?? '')) !== 'up') {
            return null;
        }
        $polled = (int) ($port['poll_time'] ?? 0);
        $age = $polled > 0 ? max(0, $this->now - $polled) : null;
        if ($age !== null && $age > self::STALE_COUNTERS) {
            return null;
        }

        $speed = (int) ($port['ifSpeed'] ?? 0);
        $capacity = $speed > 0 ? $speed / self::BITS_PER_MIN_FRAME : null; // packets per second at minimum frame size
        $row = [
            'device_id' => $link['device_id'],
            'name' => self::nameOf($device),
            'port_id' => $portId,
            'port' => InterfaceName::short(InterfaceName::label($port)),
            'neighbor' => $link['neighbor'],
            'speed' => $speed,
            'age' => $age !== null && $age > max(self::OLD_COUNTERS, 2 * (int) ($port['poll_period'] ?? 0)) ? $age : null,
        ];
        foreach (['broadcast' => 'Broadcast', 'multicast' => 'Multicast'] as $kind => $column) {
            $row[$kind] = null;
            // LibreNMS stores 0 for counters a device does not report; a link between switches always carries some
            // broadcast (ARP) and multicast (BPDUs, LLDP) traffic, so both directions at 0 mean there are none
            $counters = array_filter([$port["ifIn{$column}Pkts"] ?? null, $port["ifOut{$column}Pkts"] ?? null], fn ($value) => $value !== null);
            if ($counters && ! array_filter($counters, fn ($value) => (int) $value > 0)) {
                continue;
            }
            foreach (['In' => 'in', 'Out' => 'out'] as $direction => $label) {
                if (! isset($port["if{$direction}{$column}Pkts_rate"])) {
                    continue;
                }
                $pps = (int) $port["if{$direction}{$column}Pkts_rate"];
                $unicast = $port["if{$direction}UcastPkts_rate"] ?? null;
                $total = $unicast === null ? null
                    : (int) $unicast + (int) ($port["if{$direction}BroadcastPkts_rate"] ?? 0) + (int) ($port["if{$direction}MulticastPkts_rate"] ?? 0);
                if ($row[$kind] === null || $pps > $row[$kind]['pps']) {
                    $row[$kind] = [
                        'pps' => $pps,
                        'direction' => $label,
                        'share' => $total ? $pps / $total : null,
                        'load' => $capacity ? $pps / $capacity : null,
                    ];
                }
            }
        }

        return $row['broadcast'] === null && $row['multicast'] === null ? null : $row;
    }

    /**
     * Storm level of a rate: at least the threshold, and at least the share of the packets or 1% of
     * the link; a broadcast storm is critical from ten times the threshold or 10% of the link.
     * Multicast stays a warning, as video and imaging traffic can be that heavy on purpose.
     */
    private static function stormLevel(string $kind, ?array $rate, int $threshold, float $share): ?string
    {
        if ($rate === null || $rate['pps'] < $threshold) {
            return null;
        }
        $dominant = $rate['share'] !== null && $rate['share'] >= $share;
        $heavy = $rate['load'] !== null && $rate['load'] >= 0.01;
        if (! $dominant && ! $heavy) {
            return null;
        }

        return $kind === 'broadcast' && ($rate['pps'] >= 10 * $threshold || ($rate['load'] ?? 0) >= 0.1) ? Finding::CRITICAL : Finding::WARNING;
    }

    /**
     * One finding per kind listing the ports, busiest first.
     */
    private function stormFinding(string $kind, array $rows): void
    {
        if (! $rows) {
            return;
        }
        usort($rows, fn ($a, $b) => $b[$kind]['pps'] <=> $a[$kind]['pps']);
        $severity = Finding::worst(array_column($rows, $kind . '_level'));
        $parts = array_map(fn ($row) => $this->stormText($kind, $row), array_slice($rows, 0, 8));
        $more = count($rows) > 8 ? ' and ' . (count($rows) - 8) . ' more' : '';
        $devices = [];
        foreach ($rows as $row) {
            $devices[] = $row['device_id'];
            if ($row['neighbor']['device_id'] && isset($this->devices[$row['neighbor']['device_id']])) {
                $devices[] = $row['neighbor']['device_id'];
            }
        }

        $this->add(new Finding(
            $severity, $kind . '_storm',
            $kind === 'broadcast' ? 'broadcast storm on inter-switch links' : 'heavy multicast on inter-switch links',
            count($rows) . ' inter-switch ' . (count($rows) === 1 ? 'port' : 'ports') . " carried $kind traffic at storm level at the last poll: "
            . implode('; ', $parts) . $more . '.',
            $kind === 'broadcast'
                ? 'A broadcast storm between switches is almost always a loop: a cable or an unmanaged switch closing a path that spanning '
                    . 'tree does not see or does not block. Check the spanning tree state of these ports and the MAC flapping messages, then '
                    . 'remove the loop. Storm control on edge ports limits the damage meanwhile.'
                : 'Multicast this heavy can be intended (video, IPTV, imaging), but a loop multiplies it like broadcast. If it is new or on '
                    . 'several links at once, look for a loop; IGMP snooping keeps multicast off links without receivers. Add multicast_storm '
                    . 'to the disabled checks where it is expected.',
            null, $devices, array_column($rows, 'port_id'),
        ));
    }

    /**
     * "acc1 Gi1/0/49 (to dist1 Gi1/0/1): 250,000 broadcast packets/s in, 96% of its packets, at least 17% of the link"
     */
    private function stormText(string $kind, array $row): string
    {
        $rate = $row[$kind];
        $neighbor = trim($row['neighbor']['name'] . ' ' . $row['neighbor']['port']);
        $notes = array_filter([$neighbor !== '' ? "to $neighbor" : '', $row['age'] !== null ? 'polled ' . StpAnalyzer::duration($row['age']) . ' ago' : '']);

        return $row['name'] . ' ' . $row['port'] . ($notes ? ' (' . implode(', ', $notes) . ')' : '') . ': '
            . number_format($rate['pps']) . " $kind packets/s {$rate['direction']}"
            . ($rate['share'] !== null ? ', ' . round($rate['share'] * 100) . '% of its packets' : '')
            . ($rate['load'] !== null && $rate['load'] >= 0.01 ? ', at least ' . round($rate['load'] * 100) . '% of the link' : '');
    }

    // ---------------------------------------------------------------- syslog

    /**
     * Group the matching syslog messages of the window by check and device; one finding per check
     * lists the devices, busiest first.
     *
     * @return array<int, array> evidence rows (device and check)
     */
    private function checkSyslog(int $hours): array
    {
        if (! $this->patterns->usable()) {
            return [];
        }

        $since = $this->now - $hours * 3600;
        $groups = [];
        foreach ($this->syslog as $message) {
            $time = strtotime((string) ($message['timestamp'] ?? '')) ?: 0;
            if ($time < $since) {
                continue;
            }
            $text = trim(((string) ($message['program'] ?? '')) . ' ' . ((string) ($message['msg'] ?? '')));
            $code = array_key_exists('code', $message) ? $message['code'] : $this->patterns->classify($text);
            if ($code === null || ! isset(self::SYSLOG[$code]) || ! $this->enabled($code)) {
                continue;
            }
            $deviceId = (int) $message['device_id'];
            $groups[$code][$deviceId] = $this->collect($groups[$code][$deviceId] ?? null, $deviceId, $time, $text, (string) ($message['program'] ?? ''));
        }

        $rows = [];
        foreach (self::SYSLOG as $code => $check) {
            if (empty($groups[$code])) {
                continue;
            }
            $group = array_values($groups[$code]);
            usort($group, fn ($a, $b) => [-$a['count'], $a['name']] <=> [-$b['count'], $b['name']]);
            $severity = $this->syslogFinding($code, $check, $group, $hours);
            foreach (array_slice($group, 0, self::ROW_LIMIT) as $row) {
                $rows[] = $this->evidenceRow($code, $check, $severity, $row);
            }
        }
        // critical first; within a severity the matching order and the busiest devices (stable sort)
        usort($rows, fn ($a, $b) => Finding::SEVERITY_ORDER[$a['severity']] <=> Finding::SEVERITY_ORDER[$b['severity']]);

        return $rows;
    }

    /**
     * Add one message to the group of its device: count, time range, the ports, MAC addresses and
     * VLANs it names, its program, and the newest message as an example.
     */
    private function collect(?array $group, int $deviceId, int $time, string $text, string $program): array
    {
        $group ??= [
            'device_id' => $deviceId, 'name' => $this->deviceName($deviceId), 'count' => 0, 'first' => $time, 'last' => $time,
            'ports' => [], 'macs' => [], 'vlans' => [], 'programs' => [], 'message' => $text,
        ];
        $group['count']++;
        if ($time > $group['last']) {
            [$group['last'], $group['message']] = [$time, $text];
        }
        $group['first'] = min($group['first'], $time);
        $group['ports'] += $this->portsIn($deviceId, $text);
        foreach (self::macsIn($text) as $mac) {
            $group['macs'][$mac] = true;
        }
        foreach (self::vlansIn($text) as $vlan) {
            $group['vlans'][$vlan] = true;
        }
        $group['programs'][$program] = true;

        return $group;
    }

    /**
     * One finding for a syslog check. Many MAC addresses flapping (on one switch or across several)
     * and storm control on several switches point at a loop rather than at one host: critical. One
     * address flapping, even on two switches, is usually a dual-homed host.
     *
     * @return string the severity
     */
    private function syslogFinding(string $code, array $check, array $group, int $hours): string
    {
        $severity = $check['severity'];
        $macs = [];
        foreach ($group as $row) {
            $macs += $row['macs'];
        }
        if (($code === 'mac_flapping' && count($macs) >= self::LOOP_MACS) || ($code === 'storm_control' && count($group) >= 2)) {
            $severity = Finding::CRITICAL;
        }

        $parts = array_map(fn ($row) => $this->groupText($row), array_slice($group, 0, 8));
        $more = count($group) > 8 ? ' and ' . (count($group) - 8) . ' more devices' : '';
        $total = array_sum(array_column($group, 'count'));
        $ports = [];
        foreach ($group as $row) {
            $ports = array_merge($ports, array_keys($row['ports']));
        }

        $this->add(new Finding(
            $severity, $code, $check['title'],
            number_format($total) . ' ' . ($total === 1 ? substr($check['messages'], 0, -1) : $check['messages'])
            . " in the last $hours hours: " . implode('; ', $parts) . $more
            . '. The messages are listed under Loop evidence.',
            $check['fix'], null, array_column($group, 'device_id'), $ports,
        ));

        return $severity;
    }

    /**
     * "loop-sw1 (412 messages, 37 MAC addresses, VLAN 10, 20, ports Gi1/0/49, Gi1/0/50, latest 5m ago)"
     */
    private function groupText(array $row): string
    {
        $details = [$row['count'] . ($row['count'] === 1 ? ' message' : ' messages')];
        if ($row['macs']) {
            $details[] = count($row['macs']) . (count($row['macs']) === 1 ? ' MAC address' : ' MAC addresses');
        }
        if ($row['vlans']) {
            $vlans = array_keys($row['vlans']);
            sort($vlans);
            $details[] = 'VLAN ' . implode(', ', array_slice($vlans, 0, self::LIST_LIMIT));
        }
        if ($row['ports']) {
            $details[] = (count($row['ports']) === 1 ? 'port ' : 'ports ') . implode(', ', array_slice($row['ports'], 0, self::LIST_LIMIT));
        }
        $details[] = 'latest ' . StpAnalyzer::duration(max(0, $this->now - $row['last'])) . ' ago';

        return $row['name'] . ' (' . implode(', ', $details) . ')';
    }

    /**
     * Row of the syslog evidence table, with what the page needs to link to the messages.
     */
    private function evidenceRow(string $code, array $check, string $severity, array $row): array
    {
        $vlans = array_keys($row['vlans']);
        sort($vlans);
        $ports = [];
        foreach (array_slice($row['ports'], 0, self::LIST_LIMIT, true) as $portId => $name) {
            $ports[] = ['port_id' => $portId, 'name' => $name];
        }

        return [
            'device_id' => $row['device_id'],
            'name' => $row['name'],
            'code' => $code,
            'check' => $check['title'],
            'severity' => $severity,
            'count' => $row['count'],
            'first' => date('Y-m-d H:i:s', $row['first']),
            'last' => date('Y-m-d H:i:s', $row['last']),
            'first_ts' => $row['first'],
            'last_ts' => $row['last'],
            'ports' => $ports,
            'macs' => count($row['macs']),
            'mac_examples' => array_map(fn ($mac) => implode(':', str_split($mac, 2)), array_slice(array_keys($row['macs']), 0, 3)),
            'vlans' => array_slice($vlans, 0, self::LIST_LIMIT),
            // the link to the messages filters on the program when they all share one
            'program' => count($row['programs']) === 1 ? (string) array_key_first($row['programs']) : '',
            'message' => mb_substr($row['message'], 0, 300),
        ];
    }

    /**
     * Ports of the device named in a message: typed names (Gi1/0/49, Ethernet1/1, ge-0/0/1.0) and
     * bare ones after "port" or "interface" (ProCurve "port 5", "port A1"). CDP names the neighbor
     * and its port after ", with": those are not ports of this device.
     *
     * @return array<int, string> port_id => short name
     */
    private function portsIn(int $deviceId, string $text): array
    {
        $index = $this->portIndex($deviceId);
        if (! $index) {
            return [];
        }
        $text = SyslogPatterns::local($text);

        preg_match_all('~(?<![\w/.:-])([A-Za-z][A-Za-z-]*?\d+(?:[/:]\d+)*(?:\.\d+)?)~', $text, $typed);
        preg_match_all('~\b(?:ports?|interface)\s+([A-Za-z]?\d+(?:/[A-Za-z]?\d+)*)~i', $text, $bare);

        $found = [];
        foreach (array_merge($typed[1], $bare[1]) as $candidate) {
            $name = InterfaceName::normalize($candidate);
            $portId = $index[$name] ?? $index[(string) preg_replace('~\.\d+$~', '', $name)] ?? null;
            if ($portId !== null) {
                $found[$portId] = InterfaceName::short(InterfaceName::label($this->syslogPorts[$portId] ?? $this->ports[$portId] ?? []));
            }
        }

        return $found;
    }

    /**
     * Normalized ifName / ifDescr => port_id of a device's ports (every device with messages, in one
     * pass); deleted ports are left out, so a renumbered port is found under its current id.
     *
     * @return array<string, int>
     */
    private function portIndex(int $deviceId): array
    {
        if ($this->portIndex === null) {
            $this->portIndex = [];
            $logging = array_flip(array_map('intval', array_column($this->syslog, 'device_id')));
            foreach ([$this->syslogPorts, $this->ports] as $ports) {
                foreach ($ports as $portId => $port) {
                    if (! isset($logging[(int) ($port['device_id'] ?? 0)]) || ! empty($port['deleted'])) {
                        continue;
                    }
                    foreach (['ifName', 'ifDescr'] as $field) {
                        $name = InterfaceName::normalize((string) ($port[$field] ?? ''));
                        if ($name !== '') {
                            $this->portIndex[(int) ($port['device_id'] ?? 0)][$name] ??= (int) $portId;
                        }
                    }
                }
            }
        }

        return $this->portIndex[$deviceId] ?? [];
    }

    /**
     * MAC addresses in a message (Cisco dotted, colon and dash notation) as 12 lowercase hex digits.
     *
     * @return string[]
     */
    private static function macsIn(string $text): array
    {
        preg_match_all('~(?<![0-9a-f])([0-9a-f]{4}\.[0-9a-f]{4}\.[0-9a-f]{4}|[0-9a-f]{2}(?:[:-][0-9a-f]{2}){5})(?![0-9a-f])~i', $text, $matches);

        return array_map(fn ($mac) => strtolower((string) preg_replace('~[^0-9a-f]~i', '', $mac)), $matches[1]);
    }

    /**
     * VLAN numbers in a message ("vlan 10", "VLAN0010", "in VLAN 10").
     *
     * @return int[]
     */
    private static function vlansIn(string $text): array
    {
        preg_match_all('~\bvlan\s*0*(\d{1,4})\b~i', $text, $matches);

        return array_values(array_filter(array_map('intval', $matches[1]), fn ($vlan) => $vlan >= 1 && $vlan <= 4094));
    }

    // ---------------------------------------------------------------- helpers

    private function deviceName(int $deviceId): string
    {
        return isset($this->devices[$deviceId]) ? self::nameOf($this->devices[$deviceId]) : "device #$deviceId";
    }

    /**
     * Display name like the rest of the STP page (display name, else hostname).
     */
    private static function nameOf(array $device): string
    {
        return (string) (($device['display'] ?? '') ?: ($device['hostname'] ?? '') ?: ('device #' . ($device['device_id'] ?? '?')));
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
