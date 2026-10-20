{{-- Loop evidence on the spanning tree page (Stp\LoopEvidence): broadcast / multicast rates of the inter-switch ports
     and loop related syslog messages. Uses $result, $deviceUrl and $portUrl of stp.blade.php. --}}
@php
    $evidence = $result['loop_evidence'] ?? null;
    $speed = fn ($bps) => $bps ? \Agoodley\LibrenmsTopology\Links\LinkAnalyzer::speed((int) $bps) : '-';
    $syslogUrl = fn (array $row) => url('device/' . $row['device_id'] . '/logs/syslog') . '?' . http_build_query(array_filter([
        'program' => $row['program'],
        'from' => date('Y-m-d H:i', $row['first_ts'] - 60),
        'to' => date('Y-m-d H:i', $row['last_ts'] + 60),
    ]));
@endphp
@if($evidence)
<div class="panel panel-default" id="topo-loop-evidence">
    <div class="panel-heading">
        <strong>Loop evidence</strong>
        <span class="text-muted">(broadcast and multicast on inter-switch links at the last poll, loop messages in syslog; all VLANs)</span>
    </div>
    @foreach($evidence['notices'] as $notice)
        <div class="alert alert-info" style="margin: 8px 10px">{{ $notice }}</div>
    @endforeach

    <div class="topo-evidence-heading">
        Inter-switch ports, storms first
        <span class="text-muted">({{ $evidence['ports_with_counters'] }} of {{ $evidence['inter_switch_ports'] }} ports with current packet counters{{ $evidence['storm_threshold'] > 0 ? ', storm from ' . number_format($evidence['storm_threshold']) . ' packets/s' : ', storm checks off' }})</span>
    </div>
    <div class="table-responsive">
    <table class="table table-condensed table-hover topo-stp-table" id="topo-storm-table">
        <thead><tr><th>Device</th><th>Port</th><th>Neighbor</th><th>Speed</th><th>Broadcast</th><th>Multicast</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($evidence['storms'] as $row)
            @php($level = \Agoodley\LibrenmsTopology\Stp\Finding::worst(array_filter([$row['broadcast_level'], $row['multicast_level']])))
            <tr>
                <td><a href="{{ $deviceUrl($row['device_id']) }}">{{ $row['name'] }}</a></td>
                <td><a href="{{ $portUrl($row['device_id'], $row['port_id']) }}">{{ $row['port'] }}</a></td>
                <td>
                    @if($row['neighbor']['device_id'])<a href="{{ $deviceUrl($row['neighbor']['device_id']) }}">{{ $row['neighbor']['name'] }}</a>@else{{ $row['neighbor']['name'] ?: '-' }}@endif
                    {{ $row['neighbor']['port'] }}
                </td>
                <td>{{ $speed($row['speed']) }}</td>
                @foreach(['broadcast', 'multicast'] as $kind)
                    @php($rate = $row[$kind])
                    <td class="{{ $row[$kind . '_level'] ? 'topo-text-warning' : '' }}">
                        @if($rate)
                            {{ number_format($rate['pps']) }} pkt/s {{ $rate['direction'] }}
                            <span class="text-muted">@if($rate['share'] !== null)&middot; {{ round($rate['share'] * 100) }}% of packets @endif @if($rate['load'] !== null && $rate['load'] >= 0.01)&middot; &ge;{{ round($rate['load'] * 100) }}% of link @endif</span>
                        @else
                            <span class="text-muted">-</span>
                        @endif
                    </td>
                @endforeach
                <td><span class="topo-sev topo-sev-{{ $level }}">{{ $level === 'ok' ? 'ok' : 'storm' }}</span></td>
            </tr>
        @empty
            <tr><td colspan="7">No inter-switch port has current broadcast / multicast counters. LibreNMS' ports module collects them from devices that report the IF-MIB packet counters.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>

    <div class="topo-evidence-heading">
        Syslog, last {{ $evidence['syslog_hours'] }} hours
        @unless($evidence['syslog_hidden'])
            <span class="text-muted">({{ $evidence['syslog_devices'] }} of {{ $evidence['syslog_scope'] }} switches and routers sent syslog to LibreNMS)</span>
        @endunless
    </div>
    @if($evidence['syslog_hidden'])
        <p class="topo-evidence-note">Your LibreNMS role may not view syslog, so syslog evidence is not shown.</p>
    @else
        <div class="table-responsive">
        <table class="table table-condensed table-hover topo-stp-table" id="topo-syslog-table">
            <thead><tr><th style="width: 90px">Severity</th><th>Check</th><th>Device</th><th>Messages</th><th>Latest</th><th>Ports</th><th>Addresses / VLANs</th><th>Latest message</th><th></th></tr></thead>
            <tbody>
            @forelse($evidence['syslog'] as $row)
                <tr data-code="{{ $row['code'] }}" data-device="{{ $row['device_id'] }}">
                    <td><span class="topo-sev topo-sev-{{ $row['severity'] }}">{{ $row['severity'] }}</span></td>
                    <td>{{ ucfirst($row['check']) }}</td>
                    <td><a href="{{ $deviceUrl($row['device_id']) }}">{{ $row['name'] }}</a></td>
                    <td>{{ number_format($row['count']) }}</td>
                    <td title="first {{ $row['first'] }}">{{ $row['last'] }}</td>
                    <td class="topo-wrap">
                        @foreach($row['ports'] as $port)
                            <a href="{{ $portUrl($row['device_id'], $port['port_id']) }}">{{ $port['name'] }}</a>@if(! $loop->last), @endif
                        @endforeach
                    </td>
                    <td class="topo-wrap">
                        @if($row['macs']){{ $row['macs'] }} MAC {{ $row['macs'] === 1 ? 'address' : 'addresses' }} <span class="topo-mono">{{ implode(' ', $row['mac_examples']) }}</span>@endif
                        @if($row['vlans'])<div>VLAN {{ implode(', ', $row['vlans']) }}</div>@endif
                    </td>
                    <td class="topo-wrap topo-mono">{{ $row['message'] }}</td>
                    <td><a href="{{ $syslogUrl($row) }}" class="btn btn-xs btn-default" title="These messages in the device's syslog"><i class="fa fa-file-text-o"></i> Syslog</a></td>
                </tr>
            @empty
                <tr><td colspan="9">
                    @if($evidence['syslog_devices'] === 0)
                        None of these switches and routers sent syslog to LibreNMS in this period. Point their syslog at LibreNMS to use this evidence.
                    @else
                        No loop related messages.
                    @endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    @endif
</div>
@endif
