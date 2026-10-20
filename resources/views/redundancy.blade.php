{{-- Redundancy & failure impact page (PageController::redundancy), rendered from TopologyService::redundancy(). --}}
@extends('layouts.librenmsv1')

@section('title', 'Redundancy & Failure Impact')

@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $deviceUrl = fn ($id) => url('device/' . $id);
    $names = $result['device_names'] ?? [];
    $name = fn ($id) => $names[$id] ?? '#' . $id;
    $mapUrl = fn ($deviceId, $fail = null) => route('topology.l2', array_filter(['device' => $deviceId, 'fail' => $fail]));
    $ago = fn ($since) => $since ? \Agoodley\LibrenmsTopology\Stp\StpAnalyzer::duration(max(0, time() - (int) $since)) : null;
    $kinds = ['device' => 'device', 'unmanaged' => 'unmanaged device', 'link' => 'link', 'port-channel' => 'port-channel'];
    $coreModes = [
        'redundancy_core' => 'from the Core devices setting',
        'stp_preferred_roots' => 'the designated root bridges of the spanning tree settings',
        'stp' => 'the spanning tree root bridges (lowest bridge priority)',
        'automatic' => 'the most central switches (automatic)',
    ];
    $cards = [
        'Redundancy lost' => [['lost'], 'devices that depend on a single path now because the other one is down'],
        'Fake redundancy' => [['fake'], 'links that all end on one switch or leave from one stack member / line card'],
        'Single points of failure' => [['spof'], 'devices and links whose loss cuts switches off the core'],
    ];
@endphp

@section('content')
<div class="container-fluid topo-page">
    @include('topology::partials.nav', ['active' => 'redundancy'])

    <form class="topo-filterbar" method="get" action="{{ route('topology.redundancy') }}">
        <label>Device
            <select id="topo-redundancy-device" name="device" class="form-control">
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
        <a class="btn btn-sm btn-default" href="{{ route('topology.api.redundancy', request()->query()) }}" target="_blank"><i class="fa fa-code"></i> JSON</a>
    </form>

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @if($result)
        @php
            $summary = $result['summary'];
            $core = $result['core'];
            // the chosen core first; parts of the network without one use their most central switch
            $chosen = array_values(array_filter($core['devices'], fn ($c) => $c['mode'] !== 'automatic'));
            $automatic = array_values(array_filter($core['devices'], fn ($c) => $c['mode'] === 'automatic'));
            $coreIds = array_column($chosen ?: $automatic, 'device_id');
            $mode = $chosen ? $chosen[0]['mode'] : 'automatic';
            $coreText = $core['devices'] ? $coreModes[$mode === 'configured' ? ($core['setting'] ?? 'redundancy_core') : $mode] ?? $mode : '';
            if ($chosen && $automatic) {
                $coreText .= '; ' . count($automatic) . ' switch' . (count($automatic) === 1 ? '' : 'es') . ' in other parts of the network (automatic)';
            }
        @endphp
        <div class="topo-cards">
            <div class="topo-card topo-{{ $summary['status'] }}">
                <h4>Health</h4>
                <div class="topo-card-value"><span class="topo-sev topo-sev-{{ $summary['status'] }}" style="font-size: 16px">{{ $summary['status'] }}</span></div>
                <p>{{ $summary['findings']['critical'] }} critical, {{ $summary['findings']['warning'] }} warnings, {{ $summary['findings']['info'] }} info</p>
            </div>
            <div class="topo-card topo-info">
                <h4>Core</h4>
                <div class="topo-card-value" style="font-size: 16px; line-height: 31px">
                    @forelse(array_slice($coreIds, 0, 4) as $deviceId)
                        <a href="{{ $deviceUrl($deviceId) }}">{{ $name($deviceId) }}</a>@if(! $loop->last), @endif
                    @empty
                        <span class="text-muted">none</span>
                    @endforelse
                    @if(count($coreIds) > 4) <small>+{{ count($coreIds) - 4 }}</small> @endif
                </div>
                <p>{{ $coreText ?: 'no switches with neighbors found' }}</p>
            </div>
            <div class="topo-card topo-{{ $summary['down'] ? 'critical' : 'ok' }}">
                <h4>Outages</h4>
                <div class="topo-card-value">{{ $summary['down'] }}</div>
                <p>devices down{{ $result['outages'] ? ', ' . count($result['outages']) . ' where it starts' : '' }}</p>
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

        @if($result['outages'])
            <div class="panel panel-default">
                <div class="panel-heading"><strong>Current outages</strong> <span class="text-muted">(the top-most down device of each, with the down devices behind it)</span></div>
                <table class="table table-condensed table-hover topo-stp-table">
                    <thead><tr><th>Down device</th><th>Down for</th><th>Down behind it</th><th></th></tr></thead>
                    <tbody>
                    @foreach($result['outages'] as $outage)
                        <tr>
                            <td><span class="topo-status-down"><i class="fa fa-arrow-down"></i></span> <a href="{{ $deviceUrl($outage['device_id']) }}">{{ $name($outage['device_id']) }}</a></td>
                            <td>{{ $ago($outage['since']) ?? '-' }}</td>
                            <td class="topo-wrap">
                                @if($outage['isolated'])
                                    <span class="text-muted">none of its monitored neighbors is up: the failure is further upstream</span>@if($outage['behind'])<br>@endif
                                @endif
                                @foreach(array_slice($outage['behind'], 0, 20) as $deviceId)
                                    <a href="{{ $deviceUrl($deviceId) }}">{{ $name($deviceId) }}</a>@if(! $loop->last), @endif
                                @endforeach
                                @if(count($outage['behind']) > 20) &hellip; ({{ count($outage['behind']) }}) @endif
                                @if($outage['shared'])
                                    <br><small class="text-muted">also behind
                                        @foreach($outage['shared_with'] as $deviceId){{ $name($deviceId) }}@if(! $loop->last), @endif @endforeach:</small>
                                    @foreach(array_slice($outage['shared'], 0, 20) as $deviceId)
                                        <a href="{{ $deviceUrl($deviceId) }}">{{ $name($deviceId) }}</a>@if(! $loop->last), @endif
                                    @endforeach
                                @endif
                                @if($outage['unmanaged'])<br><small class="text-muted">and {{ $outage['unmanaged'] }} unmanaged neighbor(s)</small>@endif
                                @if(! $outage['behind'] && ! $outage['shared'] && ! $outage['isolated'] && ! $outage['unmanaged'])<span class="text-muted">-</span>@endif
                            </td>
                            <td><a href="{{ $mapUrl($outage['device_id']) }}" title="Show on the L2 map"><i class="fa fa-sitemap"></i></a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

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
                                <a href="{{ $mapUrl($finding['devices'][0]) }}" title="Show on the L2 map"><i class="fa fa-sitemap"></i></a>
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
                    <tr><td colspan="3"><span class="topo-sev topo-sev-ok">ok</span> No single points of failure or redundancy problems found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <strong>Failure impact</strong>
                <span class="text-muted">({{ $result['impact_total'] }} devices and links whose failure cuts monitored devices off the core, largest first{{ $result['impact_total'] > count($result['impact']) ? ', showing the first ' . count($result['impact']) : '' }})</span>
            </div>
            <div class="table-responsive">
            <table class="table table-condensed table-hover topo-stp-table">
                <thead><tr><th>If this fails</th><th>Type</th><th>Cut off</th><th>Monitored devices cut off</th><th></th></tr></thead>
                <tbody>
                @forelse($result['impact'] as $row)
                    @php $center = $row['element_devices'][0] ?? $row['devices'][0]; @endphp
                    <tr>
                        <td class="topo-wrap">
                            @if($row['kind'] === 'device')
                                <a href="{{ $deviceUrl($row['element_devices'][0]) }}">{{ $row['label'] }}</a>
                            @else
                                {{ $row['label'] }}
                            @endif
                        </td>
                        <td>{{ $kinds[$row['kind']] ?? $row['kind'] }}</td>
                        <td>
                            {{ count($row['devices']) }} device{{ count($row['devices']) === 1 ? '' : 's' }}
                            @if($row['network'])<br><small>{{ $row['network'] }} switch{{ $row['network'] === 1 ? '' : 'es' }} / router{{ $row['network'] === 1 ? '' : 's' }}</small>@endif
                            @if($row['unmanaged'])<br><small class="text-muted">+{{ $row['unmanaged'] }} unmanaged</small>@endif
                        </td>
                        <td class="topo-wrap">
                            @foreach(array_slice($row['devices'], 0, 15) as $deviceId)
                                <a href="{{ $deviceUrl($deviceId) }}">{{ $name($deviceId) }}</a>@if(! $loop->last), @endif
                            @endforeach
                            @if(count($row['devices']) > 15) &hellip; @endif
                        </td>
                        <td><a class="btn btn-xs btn-default" href="{{ $mapUrl($center, $row['fail']) }}" title="Simulate this failure on the L2 map"><i class="fa fa-bolt"></i> Simulate</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5">No single device or link failure cuts a monitored device off the core.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>

        <p class="text-muted" style="font-size: 12px">
            Every device should keep a path to the core: {{ $coreText ?: 'none found' }}. Change it with <i>Core devices</i> in the plugin settings.
            Paths are followed through all devices you can see, across LLDP/CDP links (port-channels count as one link).
            LibreNMS forgets links that LLDP/CDP no longer sees at the next discovery, so links that are down for longer are missing here.
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
        $('#topo-redundancy-device').select2({
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
