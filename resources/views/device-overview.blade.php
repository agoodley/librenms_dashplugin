{{-- Device overview panel (Hooks\DeviceOverview): neighbours, per-VLAN spanning tree state and routing of one device. --}}
@php
    $routing = array_keys(array_filter([
        'OSPF' => $summary['routing']['ospf'] ?? false,
        'BGP' => $summary['routing']['bgp'] ?? false,
        'routed interfaces' => $summary['routing']['routed'] ?? false,
    ]));
@endphp
@if($summary && ($summary['neighbors'] || $summary['stp'] || $routing))
@php
    $stp = $summary['stp'];
    $deviceId = $device->device_id;
    $worst = 'ok';
    foreach ($stp['issues'] ?? [] as $issue) {
        $worst = match (true) {
            $issue['severity'] === 'critical' => 'critical',
            $issue['severity'] === 'warning' && $worst !== 'critical' => 'warning',
            default => $worst,
        };
    }
    $sevColor = ['critical' => '#c0392b', 'warning' => '#b85c00', 'info' => '#1f6fb2', 'ok' => '#23803a'];
@endphp
<div class="panel panel-default panel-condensed topo-device-panel">
    <div class="panel-heading">
        <i class="fa fa-sitemap fa-lg icon-theme" aria-hidden="true"></i>
        <strong>Topology</strong>
        <span class="pull-right">
            <a class="btn btn-xs btn-default" href="{{ route('topology.l2', ['device' => $deviceId]) }}">L2 map</a>
            <a class="btn btn-xs btn-default" href="{{ route('topology.l3', ['device' => $deviceId]) }}">L3 map</a>
            @if($stp)<a class="btn btn-xs btn-default" href="{{ route('topology.stp', ['device' => $deviceId]) }}">STP diagnostics</a>@endif
            @if($summary['neighbors'])<a class="btn btn-xs btn-default" href="{{ route('topology.links', ['device' => $deviceId]) }}">Link checks</a>@endif
            @if($routing)<a class="btn btn-xs btn-default" href="{{ route('topology.routing', ['device' => $deviceId]) }}">Routing &amp; IP</a>@endif
            @if($summary['neighbors'])<a class="btn btn-xs btn-default" href="{{ route('topology.redundancy', ['device' => $deviceId]) }}">Redundancy</a>@endif
            @if($history)<a class="btn btn-xs btn-default" href="{{ route('topology.changes', ['device' => $deviceId]) }}">Changes</a>@endif
        </span>
    </div>
    <table class="table table-condensed table-hover" style="margin-bottom: 0">
        @if($summary['neighbors'])
            <tr>
                <td style="width: 35%">Neighbors</td>
                <td>{{ \Agoodley\LibrenmsTopology\Support\Text::count($summary['neighbors'], 'neighbor') }} on {{ \Agoodley\LibrenmsTopology\Support\Text::count($summary['links'], 'port') }}</td>
            </tr>
        @endif
        @if($routing)
            <tr>
                <td style="width: 35%">Routing</td>
                <td>{{ implode(', ', $routing) }}</td>
            </tr>
        @endif
        @if($stp)
            <tr>
                <td>Spanning tree health</td>
                <td><span style="color: {{ $sevColor[$worst] }}; font-weight: 600; text-transform: uppercase">{{ $worst }}</span></td>
            </tr>
            @foreach(array_slice($stp['instances'], 0, 8) as $instance)
                <tr>
                    <td>{{ $instance['label'] }}</td>
                    <td>
                        @if($instance['is_root'])
                            <strong><i class="fa fa-star" style="color: #f1c40f"></i> Root bridge</strong> (priority {{ $instance['priority'] }})
                        @else
                            Root {{ $instance['root_name'] ?? $instance['root_id'] }} via {{ $instance['root_port'] ?? '?' }}, cost {{ $instance['root_cost'] }}
                        @endif
                        @if($instance['blocked'])
                            &middot; <span style="color: #b85c00">{{ $instance['blocked'] }} blocked</span>
                        @endif
                        @if($instance['last_tc'] !== null)
                            &middot; last TC {{ \Agoodley\LibrenmsTopology\Stp\StpAnalyzer::duration($instance['last_tc']) }} ago
                        @endif
                    </td>
                </tr>
            @endforeach
            @if(count($stp['instances']) > 8)
                <tr><td colspan="2" class="text-muted">&hellip; {{ \Agoodley\LibrenmsTopology\Support\Text::count(count($stp['instances']) - 8, 'more instance') }}</td></tr>
            @endif
            @foreach(array_slice($stp['issues'], 0, 6) as $issue)
                <tr>
                    <td><span style="color: {{ $sevColor[$issue['severity']] }}; font-weight: 600; text-transform: uppercase">{{ $issue['severity'] }}</span></td>
                    <td>{{ $issue['text'] }}</td>
                </tr>
            @endforeach
        @endif
    </table>
</div>
@endif
