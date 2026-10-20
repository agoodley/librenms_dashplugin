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
