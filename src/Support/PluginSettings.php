<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * Typed access to the plugin settings stored by LibreNMS (always strings) merged with defaults.
 */
class PluginSettings
{
    public const DEFAULTS = [
        'default_depth' => '2',
        'max_nodes' => '400',
        'show_unmanaged' => '1',
        'collapse_lag' => '1',
        'l3_hide_stubs' => '1',
        'l3_p2p_prefix' => '29',
        'l3_exclude' => '',
        'stp_tc_recent_minutes' => '60',
        'stp_tc_rate_per_day' => '50',
        'stp_flap_transitions' => '100',
        'stp_max_diameter' => '7',
        'stp_preferred_roots' => '',
        'stp_event_days' => '7',
        'stp_disabled_checks' => '',
        'stp_storm_pps' => '1000',
        'stp_storm_percent' => '20',
        'stp_syslog_hours' => '24',
        'stp_syslog_loop_detected' => 'loop[ _-]?back[ _-]?detected|loopback error|loops?[ _-]?detect|detected loop',
        'stp_syslog_udld' => 'udld|unidirectional link',
        'stp_syslog_bpdu_guard' => 'bpdu[ _-]?guard|bpdu[ _-]?block|bpdu[ _-]?protection',
        'stp_syslog_loop_guard' => 'loop[ _-]?guard|loop[ _-]protect[ _]in[ _]effect',
        'stp_syslog_root_guard' => 'root[ _-]?guard|root[ _-]protect',
        'stp_syslog_storm_control' => 'storm[ _-]?control|st_ctl|packet storm|broadcast storm|multicast storm|excessive[ _-]?(broad|multi)casts?',
        'stp_syslog_mac_flapping' => 'mac[ _-]?flap|mac[ _-]?move|host[ _-]flapping|flapping between|has moved from',
        'stp_syslog_native_vlan' => 'native[ _-]?vlan[ _-]?mismatch|pvid[ _-]?(err|mismatch|inc)|block[ _-]pvid|inconsistent peer vlan|port vlan id mismatch',
        'stp_syslog_duplex' => 'duplex[ _-]?mismatch',
        'stp_syslog_err_disabled' => 'err[ _-]?disable|error[ _-]?disabled|disabled due to',
        'stp_syslog_ignore' => 'logged command|CFGLOG_LOGGEDCMD|CONFIG_I\b|configured from|\bUI_(CMDLINE|COMMIT|CFG_AUDIT|LOAD|NETCONF)|\bcommand\s*[:=\']|\bcmd='
            . '|_UNBLOCK|UNBLOCK_|ERR_?RECOVER|ERRDIS(ABLE)?_RECOVER|_CLEARED\b|BELOW_THRESHOLD|RE_ENABLE',
        'links_error_threshold' => '10',
        'links_discard_percent' => '1',
        'links_lag_imbalance' => '40',
        'links_rx_margin' => '2',
        'links_ignore_vlans' => '',
        'links_disabled_checks' => '',
        'interfaces_window_hours' => '24',
        'interfaces_flaps' => '3',
        'interfaces_flaps_access' => '10',
        'interfaces_downgrade_days' => '7',
        'interfaces_errors' => '100',
        'interfaces_disabled_checks' => '',
        'capacity_unused_days' => '30',
        'capacity_low_percent' => '10',
        'capacity_exclude_ports' => '',
        'capacity_disabled_checks' => '',
        'redundancy_core' => '',
        'redundancy_warn_devices' => '2',
        'redundancy_disabled_checks' => '',
        'routing_bgp_reset_hours' => '24',
        'routing_prefix_warn_percent' => '80',
        'routing_ignore_addresses' => '',
        'routing_disabled_checks' => '',
        'hosts_dns' => '1',
        'shared_port_macs' => '4',
        'coverage_disabled_checks' => '',
        'history_enabled' => '1',
        'history_interval' => '60',
        'history_retention_days' => '90',
        'history_checks' => 'stp links redundancy routing interfaces capacity',
        'history_eventlog_severity' => 'warning',
        'history_eventlog_skip' => 'link_errors link_discards lag_imbalance optical_rx port_transitioning tc_rate tc_recent multicast_storm bgp_recent_reset ip_duplicate:info',
        'rollout_batch_size' => '0',
        'dependencies_outage_days' => '30',
        'path_util_percent' => '80',
        'path_route_rows' => '500000',
    ];

    /** @var array<string, string> */
    private array $values;

    /**
     * @param  array  $stored  settings saved by LibreNMS for this plugin (strings, may be incomplete)
     */
    public function __construct(array $stored = [])
    {
        $this->values = self::merge($stored);
    }

    /**
     * @return array<string, string>
     */
    public static function merge(array $stored): array
    {
        $values = self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists($key, $stored) && $stored[$key] !== null) {
                // a group of checkboxes (settings[key][]) is stored as a list
                $values[$key] = is_array($stored[$key])
                    ? implode(' ', array_filter(array_map(fn ($v) => trim((string) $v), $stored[$key]), fn ($v) => $v !== ''))
                    : trim((string) $stored[$key]);
            }
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Integer setting clamped to [$min, $max]; non-numeric values fall back to the default.
     */
    public function int(string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $value = $this->values[$key] ?? self::DEFAULTS[$key] ?? '0';
        $int = is_numeric($value) ? (int) $value : (int) (self::DEFAULTS[$key] ?? 0);

        return max($min, min($max, $int));
    }

    /**
     * MAC addresses on an access port without LLDP/CDP neighbor from which the coverage report and the
     * host locator call it a probable hub or unmanaged switch; 0 switches that off, 1 counts as 2.
     */
    public function sharedPortMacs(): int
    {
        $value = $this->int('shared_port_macs', 0, 1000000);

        return $value === 0 ? 0 : max(2, $value);
    }

    /**
     * Checkbox setting ('1', 'true', 'on' or 'yes').
     */
    public function bool(string $key): bool
    {
        return in_array(strtolower($this->values[$key] ?? ''), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Split a comma / whitespace / newline separated setting into a list.
     *
     * @return string[]
     */
    public function list(string $key): array
    {
        $parts = preg_split('/[\s,;]+/', $this->values[$key] ?? '') ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($part) => $part !== ''));
    }

    /**
     * Devices named in a list setting by id, hostname, sysName or display name (any case).
     *
     * @param  array<int, array>  $devices  device_id => device row
     * @return array<int, true> device ids
     */
    public function devicesIn(string $key, array $devices): array
    {
        $wanted = array_map('strtolower', $this->list($key));
        if (! $wanted) {
            return [];
        }

        $result = [];
        foreach ($devices as $deviceId => $device) {
            $names = array_map('strtolower', array_filter([
                (string) $deviceId,
                (string) ($device['hostname'] ?? ''),
                (string) ($device['sysName'] ?? ''),
                (string) ($device['display'] ?? ''),
            ]));
            if (array_intersect($names, $wanted)) {
                $result[(int) $deviceId] = true;
            }
        }

        return $result;
    }

    /**
     * Set of integers from a list setting that may contain ranges ("1002-1005, 999"); values
     * outside [$min, $max] are dropped.
     *
     * @return array<int, true>
     */
    public function intSet(string $key, int $min, int $max): array
    {
        $result = [];
        foreach ($this->list($key) as $part) {
            if (preg_match('/^(\d+)-(\d+)$/', $part, $range)) {
                for ($value = max($min, (int) $range[1]); $value <= min($max, (int) $range[2]); $value++) {
                    $result[$value] = true;
                }
            } elseif (ctype_digit($part) && (int) $part >= $min && (int) $part <= $max) {
                $result[(int) $part] = true;
            }
        }

        return $result;
    }
}
