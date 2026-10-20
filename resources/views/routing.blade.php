{{-- Routing & IP diagnostics page (RoutingController::index), rendered from RoutingService::analyze(). --}}
@extends('layouts.librenmsv1')

@section('title', 'Routing & IP Diagnostics')

@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $deviceUrl = fn ($id) => url('device/' . $id);
    $portUrl = fn ($deviceId, $portId) => url('device/device=' . $deviceId . '/tab=port/port=' . $portId . '/');
    $names = $result['device_names'] ?? [];
    $name = fn ($id) => $names[$id] ?? '#' . $id;
    $mapUrl = fn ($deviceId) => route('topology.l3', array_filter(['device' => $deviceId, 'vrf' => $params['vrf'] ?? null]));
    $duration = fn ($seconds) => $seconds === null ? '-' : \Agoodley\LibrenmsTopology\Stp\StpAnalyzer::duration((int) $seconds);
    $vrfLabel = fn ($vrf) => $vrf === '' ? 'global' : $vrf;
    $rowLimit = 500;
    $cards = [
        'OSPF' => ['ospf', fn ($s) => $s['ospf']['full'] . ' / ' . $s['ospf']['neighbors'], fn ($s) => 'adjacencies full on ' . $s['ospf']['routers'] . ' router' . ($s['ospf']['routers'] === 1 ? '' : 's')],
        'BGP' => ['bgp', fn ($s) => $s['bgp']['established'] . ' / ' . ($s['bgp']['sessions'] - $s['bgp']['shut']), fn ($s) => 'sessions established' . ($s['bgp']['shut'] ? ', ' . $s['bgp']['shut'] . ' shut down on purpose' : '')],
        'IP' => ['ip', fn ($s) => $s['categories']['ip'] ?? 0, fn ($s) => 'address problems in ' . number_format($s['addresses']) . ' interface addresses'],
    ];
@endphp

@section('content')
<div class="container-fluid topo-page">
    @include('topology::partials.nav', ['active' => 'routing'])

    <form class="topo-filterbar" method="get" action="{{ route('topology.routing') }}">
        <label>Device
            <select id="topo-routing-device" name="device" class="form-control">
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
        <label>VRF
            <select name="vrf" class="form-control" style="max-width: 200px">
                <option value="">All</option>
                @foreach(array_unique(array_filter(array_merge($result['vrfs'] ?? [], [$params['vrf']]))) as $vrf)
                    <option value="{{ $vrf }}" @selected($params['vrf'] === $vrf)>{{ $vrf }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-stethoscope"></i> Analyze</button>
        <a class="btn btn-sm btn-default" href="{{ route('topology.api.routing', request()->query()) }}" target="_blank"><i class="fa fa-code"></i> JSON</a>
    </form>

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @if($result)
        @php $summary = $result['summary']; @endphp
        <div class="topo-cards">
            <div class="topo-card topo-{{ $summary['status'] }}">
                <h4>Health</h4>
                <div class="topo-card-value"><span class="topo-sev topo-sev-{{ $summary['status'] }}" style="font-size: 16px">{{ $summary['status'] }}</span></div>
                <p>{{ $summary['findings']['critical'] }} critical, {{ $summary['findings']['warning'] }} warnings, {{ $summary['findings']['info'] }} info</p>
            </div>
            @foreach($cards as $title => [$category, $value, $text])
                @php $worst = \Agoodley\LibrenmsTopology\Stp\Finding::worst(array_column(array_filter($result['findings'], fn ($f) => $f['category'] === $category), 'severity')); @endphp
                <div class="topo-card topo-{{ $worst }}">
                    <h4>{{ $title }}</h4>
                    <div class="topo-card-value">{{ $value($summary) }}</div>
                    <p>{{ $text($summary) }}</p>
                </div>
            @endforeach
        </div>

        <div class="panel panel-default">
            <div class="panel-heading"><strong>Findings</strong> <span class="text-muted">({{ count($result['findings']) }})</span></div>
            <table class="table table-condensed table-hover topo-stp-table">
                <thead><tr><th style="width: 90px">Severity</th><th>Finding</th><th style="width: 22%">Devices</th></tr></thead>
                <tbody>
                @forelse($result['findings'] as $finding)
                    <tr>
                        <td><span class="topo-sev topo-sev-{{ $finding['severity'] }}">{{ $finding['severity'] }}</span></td>
                        <td class="topo-wrap">
                            <strong>{{ ucfirst($finding['title']) }}</strong>
                            @if($finding['devices'])
                                <a href="{{ $mapUrl($finding['devices'][0]) }}" title="Show on the L3 map"><i class="fa fa-project-diagram"></i></a>
                            @endif
                            <div class="topo-finding-detail">{{ $finding['detail'] }}</div>
                            @if($finding['recommendation'])
                                <div class="topo-finding-rec"><i class="fa fa-lightbulb-o"></i> {{ $finding['recommendation'] }}</div>
                            @endif
                        </td>
                        <td class="topo-wrap">
                            @foreach(array_slice($finding['devices'], 0, 12) as $deviceId)
                                <a href="{{ $deviceUrl($deviceId) }}">{{ $name($deviceId) }}</a>@if(! $loop->last), @endif
                            @endforeach
                            @if(count($finding['devices']) > 12) &hellip; @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3"><span class="topo-sev topo-sev-ok">ok</span> No OSPF, BGP or IP address problems found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if($result['ospf_routers'])
            <div class="panel panel-default">
                <div class="panel-heading"><strong>OSPF routers</strong> <span class="text-muted">({{ count($result['ospf_routers']) }})</span></div>
                <div class="table-responsive">
                <table class="table table-condensed table-hover topo-stp-table">
                    <thead><tr><th>Router</th><th>VRF</th><th>Router ID</th><th>Role</th><th>Areas</th><th>Interfaces</th><th>Adjacencies</th><th></th></tr></thead>
                    <tbody>
                    @foreach(array_slice($result['ospf_routers'], 0, $rowLimit) as $row)
                        <tr>
                            <td><a href="{{ $deviceUrl($row['device_id']) }}">{{ $name($row['device_id']) }}</a></td>
                            <td>{{ $vrfLabel($row['vrf']) }}</td>
                            <td class="topo-mono">{{ $row['router_id'] }}</td>
                            <td>{{ implode(', ', array_keys(array_filter(['ABR' => $row['abr'], 'ASBR' => $row['asbr']]))) ?: '-' }}</td>
                            <td class="topo-wrap">{{ implode(', ', $row['areas']) ?: '-' }}</td>
                            <td>{{ $row['interfaces'] }}</td>
                            <td><span class="{{ $row['full'] === $row['neighbors'] ? '' : 'topo-status-degraded' }}">{{ $row['full'] }} / {{ $row['neighbors'] }} full</span></td>
                            <td><a href="{{ $mapUrl($row['device_id']) }}" title="Show on the L3 map"><i class="fa fa-project-diagram"></i></a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endif

        @if($result['ospf_neighbors'])
            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>OSPF adjacencies</strong>
                    <span class="text-muted">({{ count($result['ospf_neighbors']) }} as each router reports them, problems first{{ count($result['ospf_neighbors']) > $rowLimit ? ', showing the first ' . $rowLimit : '' }})</span>
                </div>
                <div class="table-responsive">
                <table class="table table-condensed table-hover topo-stp-table">
                    <thead><tr><th>Router</th><th>Interface</th><th>Area</th><th>Neighbor</th><th>Router ID</th><th>Neighbor device</th><th>State</th></tr></thead>
                    <tbody>
                    @foreach(array_slice($result['ospf_neighbors'], 0, $rowLimit) as $row)
                        <tr>
                            <td><a href="{{ $deviceUrl($row['device_id']) }}">{{ $name($row['device_id']) }}</a>@if($row['vrf'] !== '') <small class="text-muted">{{ $row['vrf'] }}</small>@endif</td>
                            <td>@if($row['port_id'])<a href="{{ $portUrl($row['device_id'], $row['port_id']) }}">{{ $row['port'] }}</a>@else{{ $row['port'] ?? '-' }}@endif</td>
                            <td>{{ $row['area'] ?? '-' }}</td>
                            <td class="topo-mono">{{ $row['ip'] }}</td>
                            <td class="topo-mono">{{ $row['router_id'] }}</td>
                            <td>
                                @if($row['remote_device'])
                                    <a href="{{ $deviceUrl($row['remote_device']) }}">{{ $name($row['remote_device']) }}</a>
                                @else
                                    <span class="text-muted">not monitored</span>
                                @endif
                            </td>
                            <td><span class="{{ $row['ok'] ? 'topo-status-up' : 'topo-status-down' }}">{{ $row['state'] }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endif

        @if($result['bgp_sessions'])
            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>BGP sessions</strong>
                    <span class="text-muted">({{ count($result['bgp_sessions']) }}, sessions that are not up first{{ count($result['bgp_sessions']) > $rowLimit ? ', showing the first ' . $rowLimit : '' }})</span>
                </div>
                <div class="table-responsive">
                <table class="table table-condensed table-hover topo-stp-table">
                    <thead><tr><th>Router</th><th>Peer</th><th>Remote AS</th><th>Type</th><th>State</th><th>Up / last up</th><th>Last error</th><th>Prefixes</th></tr></thead>
                    <tbody>
                    @foreach(array_slice($result['bgp_sessions'], 0, $rowLimit) as $row)
                        <tr>
                            <td><a href="{{ $deviceUrl($row['device_id']) }}">{{ $name($row['device_id']) }}</a>@if($row['vrf'] !== '') <small class="text-muted">{{ $row['vrf'] }}</small>@endif</td>
                            <td class="topo-wrap">
                                <span class="topo-mono">{{ $row['ip'] }}</span>
                                @if($row['remote_device'])
                                    <a href="{{ $deviceUrl($row['remote_device']) }}">{{ $name($row['remote_device']) }}</a>
                                @elseif($row['description'] !== '')
                                    <small class="text-muted">{{ $row['description'] }}</small>
                                @endif
                            </td>
                            <td>{{ $row['remote_as'] ?? '-' }}</td>
                            <td>{{ $row['type'] ?? '-' }}</td>
                            <td><span class="{{ $row['established'] ? 'topo-status-up' : ($row['shut'] ? 'topo-status-disabled' : 'topo-status-down') }}">{{ $row['state'] }}</span></td>
                            <td>{{ $row['time'] === null ? '-' : ($row['established'] ? 'up ' : '') . $duration($row['time']) . ($row['established'] ? '' : ' ago') }}</td>
                            <td class="topo-wrap">{{ $row['error'] ?? '-' }}@if($row['error_text'])<br><small class="text-muted">{{ $row['error_text'] }}</small>@endif</td>
                            <td>
                                @if($row['accepted'] !== null)
                                    {{ number_format($row['accepted']) }}@if($row['limit']) <small class="text-muted">/ {{ number_format($row['limit']) }}</small>@endif
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endif

        <p class="text-muted" style="font-size: 12px">
            Built from what LibreNMS polls: OSPF (ospf module) and BGP (bgp-peers module) every poll, interface addresses and
            VRFs at discovery. Prefix counters need bgpPeers_cbgp (Cisco). Adjacency states are a snapshot of the last poll,
            so a state seen during a change can show up once.
        </p>
    @endif
</div>
@endsection

@push('scripts')
<script>
    (function ($) {
        if (!$ || !$.fn || !$.fn.select2) {
            return;
        }
        $('#topo-routing-device').select2({
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
