{{-- Plugin settings form (Hooks\Settings). LibreNMS stores every settings[...] field posted back to the same URL. --}}
@php
    $fields = [
        'Maps' => [
            ['default_depth', 'number', 'Default hops around a centered device', '1-10'],
            ['max_nodes', 'number', 'Maximum devices drawn on one map', '10-5000; larger maps are trimmed to the best connected devices'],
            ['show_unmanaged', 'bool', 'Show unmanaged LLDP/CDP neighbors by default', ''],
            ['collapse_lag', 'bool', 'Draw LAG / port-channel members as one link by default', ''],
            ['l3_hide_stubs', 'bool', 'Hide stub networks (subnets with a single device) on the L3 map by default', ''],
            ['l3_p2p_prefix', 'number', 'IPv4 prefix length from which a two-device subnet is drawn as a direct link', 'default 29 (/29, /30, /31)'],
            ['l3_exclude', 'text', 'Subnets excluded from the L3 map', 'comma or space separated CIDRs, e.g. 10.255.0.0/16 192.168.100.0/24'],
        ],
        'Spanning tree diagnostics' => [
            ['stp_preferred_roots', 'text', 'Designated root bridges', 'hostnames, sysNames or device ids; warn when another bridge is root'],
            ['stp_tc_recent_minutes', 'number', 'Report topology changes newer than (minutes)', ''],
            ['stp_tc_rate_per_day', 'number', 'Warn above this many topology changes per day of uptime', ''],
            ['stp_flap_transitions', 'number', 'Warn when a port went to forwarding this many times', ''],
            ['stp_max_diameter', 'number', 'Maximum bridge hops from the root', '802.1D default timers assume 7'],
            ['stp_event_days', 'number', 'Days of STP eventlog history to analyze', ''],
            ['stp_storm_pps', 'number', 'Report broadcast / multicast storms from this many packets per second', 'on an inter-switch port, when they are also the share of its packets below or 1% of the link; 0 disables the storm checks'],
            ['stp_storm_percent', 'number', 'Storm share of the packets on the port (%)', ''],
            ['stp_syslog_hours', 'number', 'Hours of syslog to search for loop messages', 'only devices that send syslog to LibreNMS'],
            ['stp_disabled_checks', 'text', 'Checks to skip', 'check codes (the code of each finding in the STP JSON) or categories (root, config, changes, ports, loops, storms, syslog), e.g. timers_non_default multicast_storm'],
        ],
        'Loop evidence: syslog patterns' => [
            ['stp_syslog_ignore', 'pattern', 'Messages to ignore', 'configuration commands echoed to syslog and recoveries named by their message (ROOTGUARD_UNBLOCK, ERR_RECOVER); checked first, (?!) ignores nothing. Patterns are regular expressions matched against the program and text of each message up to a CDP neighbor (", with ..."), case-insensitive (or written as /pattern/flags); empty fields use the default shown in them'],
            ['stp_syslog_loop_detected', 'pattern', 'Loop detected', 'checked in this order, the first match counts; to switch a check off, add its check code to Checks to skip above. Check code: loop_detected'],
            ['stp_syslog_udld', 'pattern', 'Unidirectional link (UDLD)', 'check code: udld'],
            ['stp_syslog_bpdu_guard', 'pattern', 'BPDU guard', 'check code: bpdu_guard'],
            ['stp_syslog_loop_guard', 'pattern', 'Loop guard', 'check code: loop_guard'],
            ['stp_syslog_root_guard', 'pattern', 'Root guard', 'check code: root_guard'],
            ['stp_syslog_storm_control', 'pattern', 'Storm control / excessive broadcasts', 'check code: storm_control'],
            ['stp_syslog_mac_flapping', 'pattern', 'MAC flapping', 'check code: mac_flapping'],
            ['stp_syslog_native_vlan', 'pattern', 'Native VLAN mismatch', 'check code: syslog_native_vlan'],
            ['stp_syslog_duplex', 'pattern', 'Duplex mismatch', 'check code: syslog_duplex_mismatch'],
            ['stp_syslog_err_disabled', 'pattern', 'Err-disabled port', 'any other err-disable message; check code: err_disabled'],
        ],
        'Link & VLAN diagnostics' => [
            ['links_error_threshold', 'number', 'Report interface errors from this many per poll on an inter-switch port', '0 disables the check'],
            ['links_discard_percent', 'number', 'Report packet discards above this share of the packets (%)', '0 disables the check'],
            ['links_lag_imbalance', 'number', 'Report uneven port-channel load when a member is above this utilization (%)', 'and carries 3x the traffic of another member; 0 disables the check'],
            ['links_rx_margin', 'number', 'Report optical receive power within this many dB of the alarm threshold', 'below the warning / alarm thresholds is always reported; 0 disables the margin check'],
            ['links_ignore_vlans', 'text', 'VLANs ignored by the VLAN checks', 'e.g. 999 1002-1005; VLANs that are local to each switch by design'],
            ['links_disabled_checks', 'text', 'Checks to skip', 'check codes or categories (mismatch, vlans, lags, counters, optics, neighbors, descriptions), e.g. mtu_mismatch description_missing'],
        ],
        'Redundancy & failure impact' => [
            ['redundancy_core', 'text', 'Core devices', 'hostnames, sysNames or device ids that every device should keep a path to (e.g. the core switches next to the servers and the LibreNMS poller); empty: the designated root bridges above, else the spanning tree root or the most central switches'],
            ['redundancy_warn_devices', 'number', 'Warn when one failure cuts off at least this many switches, routers or firewalls', 'smaller single points of failure are info'],
            ['redundancy_disabled_checks', 'text', 'Checks to skip', 'check codes or categories (outages, lost, fake, spof), e.g. uplinks_same_device spof_link'],
        ],
        'Monitoring coverage' => [
            ['coverage_mac_threshold', 'number', 'Report access ports without LLDP/CDP neighbor from this many MAC addresses', 'in the last discovery: a likely unmanaged switch or hub (a phone on the port does not count as a neighbor); 0 disables the count'],
            ['coverage_disabled_checks', 'text', 'Checks to skip', 'check codes or categories (unmonitored, devices, ports), e.g. not_linked hub_suspected'],
        ],
        'Rollout planner & device dependencies' => [
            ['rollout_batch_size', 'number', 'Default number of devices per rollout batch', '0: no limit; the planner page can change it per plan'],
            ['dependencies_outage_days', 'number', 'Days of outage history to check the derived dependencies against', 'outages of devices and their parents in this window (1-3650)'],
        ],
    ];
@endphp

<div style="margin: 15px; max-width: 900px;">
    <h4>Topology plugin settings</h4>
    <form method="post">
        @csrf
        @foreach($fields as $section => $items)
            <fieldset style="margin-bottom: 18px;">
                <legend style="font-size: 15px;">{{ $section }}</legend>
                @foreach($items as [$name, $type, $label, $help])
                    <div class="form-group row">
                        <label class="col-sm-5 control-label" for="topo-setting-{{ $name }}" style="font-weight: normal">{{ $label }}</label>
                        <div class="col-sm-7">
                            @if($type === 'bool')
                                <input type="hidden" name="settings[{{ $name }}]" value="0">
                                <input id="topo-setting-{{ $name }}" type="checkbox" name="settings[{{ $name }}]" value="1" @checked(($settings[$name] ?? '0') === '1')>
                            @elseif($type === 'pattern')
                                {{-- empty unless changed, so an improved default of a later version applies --}}
                                @php($default = \Agoodley\LibrenmsTopology\Support\PluginSettings::DEFAULTS[$name] ?? '')
                                <input id="topo-setting-{{ $name }}" type="text" class="form-control input-sm" name="settings[{{ $name }}]" value="{{ ($settings[$name] ?? '') === $default ? '' : $settings[$name] }}" placeholder="{{ $default }}" title="Default: {{ $default }}">
                            @elseif($type === 'number')
                                <input id="topo-setting-{{ $name }}" type="number" min="0" class="form-control input-sm" style="max-width: 140px" name="settings[{{ $name }}]" value="{{ $settings[$name] ?? '' }}">
                            @else
                                <input id="topo-setting-{{ $name }}" type="text" class="form-control input-sm" name="settings[{{ $name }}]" value="{{ $settings[$name] ?? '' }}">
                            @endif
                            @if($help)<span class="help-block" style="margin: 2px 0 0; font-size: 12px">{{ $help }}</span>@endif
                        </div>
                    </div>
                @endforeach
            </fieldset>
        @endforeach
        <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save</button>
    </form>
</div>
