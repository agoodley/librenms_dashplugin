{{-- Interface health page (InterfaceController::index), rendered from Interfaces\InterfaceService::analyze(). --}}
@extends('layouts.librenmsv1')

@section('title', 'Interface Health')

@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $deviceUrl = fn ($id) => url('device/' . $id);
    $speed = fn ($bps) => $bps ? \Agoodley\LibrenmsTopology\Links\LinkAnalyzer::speed((int) $bps) : '-';
    $issueLabels = [
        'link_flapping' => 'link flapping', 'speed_flapping' => 'speed flapping', 'speed_downgraded' => 'slower than before',
        'port_half_duplex' => 'half duplex', 'late_collisions' => 'late collisions', 'port_errors' => 'errors',
    ];
    $cards = [
        'Link flaps' => [['flaps'], 'ports whose link keeps going down'],
        'Speed' => [['speed'], 'speeds flapping (1G / 100M) or lower than before'],
        'Duplex' => [['duplex'], 'half duplex and late collisions: duplex mismatches'],
        'Errors' => [['errors'], 'ports with input or output errors'],
    ];
    $classLabels = [
        \Agoodley\LibrenmsTopology\Interfaces\InterfaceAnalyzer::INTER_SWITCH => 'link to a monitored device',
        \Agoodley\LibrenmsTopology\Interfaces\InterfaceAnalyzer::NEIGHBOR => 'LLDP/CDP neighbor',
        \Agoodley\LibrenmsTopology\Interfaces\InterfaceAnalyzer::ACCESS => 'desk port: its own threshold',
    ];
@endphp

@section('content')
<div class="container-fluid topo-page">
    @include('topology::partials.nav', ['active' => 'interfaces'])

    <form class="topo-filterbar" method="get" action="{{ route('topology.interfaces') }}">
        <label>Device
            <select id="topo-interfaces-device" name="device" class="form-control">
                <option value="">All devices</option>
                @if($params['device'] && $deviceName)
                    <option value="{{ $params['device'] }}" selected>{{ $deviceName }}</option>
                @endif
            </select>
        </label>
        <label>Group
            <select name="group" class="form-control">
                <option value="">All</option>
                @foreach($filters['groups'] as $group)
                    <option value="{{ $group['id'] }}" @selected($params['group'] == $group['id'])>{{ $group['name'] }}</option>
                @endforeach
            </select>
        </label>
        <label>Location
            <select name="location" class="form-control" style="max-width: 220px">
                <option value="">All</option>
                @foreach($filters['locations'] as $location)
                    <option value="{{ $location['id'] }}" @selected($params['location'] == $location['id'])>{{ $location['name'] }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-stethoscope"></i> Analyze</button>
        <a class="btn btn-sm btn-default" href="{{ route('topology.api.interfaces', request()->query()) }}" target="_blank"><i class="fa fa-code"></i> JSON</a>
    </form>

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @if($result)
        @php
            $summary = $result['summary'];
            $window = $result['window'];
            $hours = \Agoodley\LibrenmsTopology\Support\Text::count($window['hours'], 'hour');
            $portUrls = array_column($result['ports'], 'url', 'port_id');
            $problemPorts = count(array_filter($result['ports'], fn ($p) => in_array($p['severity'], ['critical', 'warning'], true)));
        @endphp
        @if($result['notice'])
            <div class="alert alert-info">{{ $result['notice'] }}</div>
        @endif

        <div class="topo-cards">
            @include('topology::partials.health-card', ['summary' => $summary])
            <div class="topo-card topo-info">
                <h4>Ports</h4>
                <div class="topo-card-value">{{ number_format($summary['ports'] ?? 0) }}</div>
                <p>Ethernet ports checked, {{ number_format($summary['rows']) }} with changes or findings in the last {{ $hours }}</p>
            </div>
            @foreach($cards as $title => [$categories, $text])
                @php
                    $severities = array_column(array_filter($result['findings'], fn ($f) => in_array($f['category'], $categories, true)), 'severity');
                    $worst = \Agoodley\LibrenmsTopology\Stp\Finding::worst($severities);
                @endphp
                <div class="topo-card topo-{{ $worst }}">
                    <h4>{{ $title }}</h4>
                    <div class="topo-card-value">{{ count($severities) }}</div>
                    <p>{{ $text }}</p>
                </div>
            @endforeach
        </div>

        <div class="panel panel-default">
            <div class="panel-heading"><strong>Findings</strong> <span class="text-muted">({{ count($result['findings']) }}; {{ $window['counters'] === 'rrd'
                ? 'of the last ' . $hours : 'link downs and speed changes of the last ' . $hours . ', errors of the last poll' }})</span></div>
            <table class="table table-condensed table-hover topo-stp-table">
                <thead><tr><th style="width: 90px">Severity</th><th>Finding</th><th style="width: 22%">Devices</th></tr></thead>
                <tbody>
                @forelse($result['findings'] as $finding)
                    <tr>
                        <td><span class="topo-sev topo-sev-{{ $finding['severity'] }}">{{ $finding['severity'] }}</span></td>
                        <td class="topo-wrap">
                            <strong>{{ ucfirst($finding['title']) }}</strong>
                            @include('topology::partials.finding-age')
                            @if(isset($portUrls[$finding['ports'][0] ?? 0]))
                                <a href="{{ $portUrls[$finding['ports'][0]] }}" title="The port in LibreNMS (its graphs and eventlog)"><i class="fa fa-plug"></i></a>
                            @endif
                            <div class="topo-finding-detail">{{ $finding['detail'] }}</div>
                            @if($finding['recommendation'])
                                <div class="topo-finding-rec"><i class="fa fa-lightbulb-o"></i> {{ $finding['recommendation'] }}</div>
                            @endif
                        </td>
                        <td class="topo-wrap">
                            @foreach($finding['devices'] as $deviceId)
                                <a href="{{ $deviceUrl($deviceId) }}">{{ $result['device_names'][$deviceId] ?? '#' . $deviceId }}</a>@if(! $loop->last), @endif
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3"><span class="topo-sev topo-sev-ok">ok</span> No interface problems detected.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <strong>Ports with changes or findings</strong>
                <span class="text-muted">({{ count($result['ports']) }}, {{ $problemPorts }} with warnings)</span>
                @if($problemPorts && $problemPorts < count($result['ports']))
                    <label class="pull-right" style="font-weight: normal; margin: 0">
                        <input type="checkbox" id="topo-interfaces-problems"> Only ports with warnings
                    </label>
                @endif
            </div>
            <div class="table-responsive">
            <table class="table table-condensed table-hover topo-stp-table" id="topo-interfaces-table">
                <thead><tr>
                    <th></th><th>Device</th><th>Port</th><th>Neighbor</th><th>Status</th><th>Speed</th><th>Duplex</th>
                    <th>Link downs<br><small>{{ $window['hours'] }}h</small></th><th>Speed changes<br><small>{{ $window['hours'] }}h</small></th><th>Last change</th>
                    <th>Errors in / out<br><small>{{ $window['counters'] === 'rrd' ? $window['hours'] . 'h' : 'last poll' }}</small></th><th>Issues</th>
                </tr></thead>
                <tbody>
                @forelse(array_slice($result['ports'], 0, 3000) as $port)
                    <tr data-problem="{{ in_array($port['severity'], ['critical', 'warning'], true) ? 1 : 0 }}">
                        <td><span class="topo-sev topo-sev-{{ $port['severity'] }}">{{ $port['severity'] }}</span></td>
                        <td><a href="{{ $deviceUrl($port['device_id']) }}">{{ $port['device'] }}</a></td>
                        <td>@if($port['url'])<a href="{{ $port['url'] }}" title="{{ $port['alias'] }}">{{ $port['port'] }}</a>@else{{ $port['port'] }}@endif</td>
                        <td class="topo-wrap" title="{{ $classLabels[$port['class']] ?? '' }}">{{ $port['neighbor'] ?: '-' }}</td>
                        <td><span class="topo-status-{{ $port['status'] }}">{{ $port['status'] }}</span></td>
                        <td class="{{ isset($port['issues']['speed_flapping']) || isset($port['issues']['speed_downgraded']) ? 'topo-mismatch' : '' }}">{{ $speed($port['speed']) }}</td>
                        <td class="{{ isset($port['issues']['port_half_duplex']) || isset($port['issues']['late_collisions']) ? 'topo-mismatch' : '' }}">{{ $port['duplex'] ?? '-' }}</td>
                        <td class="{{ isset($port['issues']['link_flapping']) ? 'topo-mismatch' : '' }}">{{ $port['downs'] }}</td>
                        <td class="{{ isset($port['issues']['speed_flapping']) ? 'topo-mismatch' : '' }}">
                            {{ $port['speed_changes'] }}
                            @if($port['speeds'])<br><small class="text-muted">{{ implode(' / ', array_map($speed, $port['speeds'])) }}</small>@endif
                        </td>
                        <td>{{ $port['last_change'] ? \Agoodley\LibrenmsTopology\Interfaces\InterfaceAnalyzer::time($port['last_change']) : '-' }}</td>
                        <td class="{{ isset($port['issues']['port_errors']) ? 'topo-mismatch' : '' }}">
                            @if($port['errors'])
                                {{ number_format($port['errors']['in']) }} / {{ number_format($port['errors']['out']) }}
                                @if($port['errors']['fcs'])<br><small class="text-muted">{{ number_format($port['errors']['fcs']) }} CRC</small>@endif
                                @if($port['errors']['late_collisions'])<br><small class="text-muted">{{ number_format($port['errors']['late_collisions']) }} late collisions</small>@endif
                            @else
                                -
                            @endif
                        </td>
                        <td class="topo-wrap">
                            @foreach($port['issues'] as $code => $severity)
                                <span class="topo-issue topo-issue-{{ $severity }}">{{ $issueLabels[$code] ?? $code }}</span>
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="12">No port went down, changed its speed or counted errors in the last {{ $hours }}, and none runs half duplex.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
            @if(count($result['ports']) > 3000)
                <div class="panel-footer text-muted">The first 3,000 of {{ number_format(count($result['ports'])) }} ports are shown; the JSON has all of them.</div>
            @endif
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    (function ($) {
        var toggle = document.getElementById('topo-interfaces-problems');
        var filter = function () {
            document.querySelectorAll('#topo-interfaces-table tbody tr[data-problem]').forEach(function (row) {
                row.style.display = toggle && toggle.checked && row.getAttribute('data-problem') !== '1' ? 'none' : '';
            });
        };
        if (toggle) {
            toggle.addEventListener('change', filter);
            filter();
        }

        if (!$ || !$.fn || !$.fn.select2) {
            return;
        }
        $('#topo-interfaces-device').select2({
            theme: 'bootstrap',
            width: '240px',
            allowClear: true,
            placeholder: 'All devices',
            ajax: {
                url: @json(url('ajax/select/device')),
                delay: 200,
                data: function (params) {
                    return {term: params.term, page: params.page || 1};
                }
            }
        });
    })(window.jQuery);
</script>
@endpush
