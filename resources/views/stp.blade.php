{{-- Spanning tree diagnostics page (PageController::stp), rendered from TopologyService::stp(). --}}
@extends('layouts.librenmsv1')

@section('title', 'Spanning Tree Diagnostics')

@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $deviceUrl = fn ($id) => url('device/' . $id);
    $portUrl = fn ($deviceId, $portId) => url('device/device=' . $deviceId . '/tab=port/port=' . $portId . '/');
    $duration = fn ($seconds) => $seconds === null ? '-' : \Agoodley\LibrenmsTopology\Stp\StpAnalyzer::duration((int) $seconds);
    $vlanLabel = fn ($key) => \Agoodley\LibrenmsTopology\Stp\StpIndex::vlanLabel((int) $key);
    $mapUrl = fn ($vlanKey, $deviceId = null) => route('topology.l2', array_filter(['vlan' => $vlanKey ?: null, 'device' => $deviceId]));
@endphp

@section('content')
<div class="container-fluid topo-page">
    @include('topology::partials.nav', ['active' => 'stp'])

    <form class="topo-filterbar" method="get" action="{{ route('topology.stp') }}">
        <label>Device
            <select id="topo-stp-device" name="device" class="form-control">
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
        <label>Instance
            <select name="vlan" class="form-control">
                <option value="">All VLANs / instances</option>
                @foreach(($result['vlans'] ?? []) as $key)
                    <option value="{{ $key ?: 1 }}" @selected($params['vlan'] !== null && \Agoodley\LibrenmsTopology\Stp\StpIndex::vlanKey($params['vlan']) === $key)>{{ $vlanLabel($key) }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-stethoscope"></i> Analyze</button>
        <a class="btn btn-sm btn-default" href="{{ route('topology.api.stp', request()->query()) }}" target="_blank"><i class="fa fa-code"></i> JSON</a>
    </form>

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @if($result)
        @if($result['notice'])
            <div class="alert alert-info">{{ $result['notice'] }}</div>
        @endif

        @php($summary = $result['summary'])
        <div class="topo-cards">
            @include('topology::partials.health-card', ['summary' => $summary])
            <div class="topo-card topo-info">
                <h4>Bridges</h4>
                <div class="topo-card-value">{{ $summary['bridges'] }}</div>
                <p>{{ \Agoodley\LibrenmsTopology\Support\Text::count($summary['instances'], 'STP instance') }} in {{ \Agoodley\LibrenmsTopology\Support\Text::count($summary['domains'], 'L2 domain') }}</p>
            </div>
            <div class="topo-card topo-info">
                <h4>Root bridges</h4>
                <div class="topo-card-value">{{ $summary['roots'] }}</div>
                <p>distinct root bridge IDs</p>
            </div>
            <div class="topo-card topo-info">
                <h4>Blocked ports</h4>
                <div class="topo-card-value">{{ $summary['blocked_ports'] }}</div>
                <p>alternate / backup ports</p>
            </div>
            <div class="topo-card {{ $summary['recent_tc'] ? 'topo-warning' : 'topo-ok' }}">
                <h4>Recent topology changes</h4>
                <div class="topo-card-value">{{ $summary['recent_tc'] }}</div>
                <p>instances with a change in the last {{ $duration($summary['recent_tc_window']) }}</p>
            </div>
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
                            <strong>@if($finding['vlan_label']){{ $finding['vlan_label'] }}: @endif{{ ucfirst($finding['title']) }}</strong>
                            @include('topology::partials.finding-age')
                            @if($finding['vlan'] !== null)
                                <a href="{{ $mapUrl($finding['vlan'], $finding['devices'][0] ?? null) }}" title="Show on the L2 map with STP overlay"><i class="fa fa-sitemap"></i></a>
                            @endif
                            <div class="topo-finding-detail">{{ $finding['detail'] }}</div>
                            @if($finding['recommendation'])
                                <div class="topo-finding-rec"><i class="fa fa-lightbulb-o"></i> {{ $finding['recommendation'] }}</div>
                            @endif
                        </td>
                        <td class="topo-wrap">
                            @foreach(array_slice($finding['devices'], 0, 12) as $deviceId)
                                <a href="{{ $deviceUrl($deviceId) }}">{{ $result['device_names'][$deviceId] ?? '#' . $deviceId }}</a>@if(! $loop->last), @endif
                            @endforeach
                            @if(count($finding['devices']) > 12) &hellip; @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3"><span class="topo-sev topo-sev-ok">ok</span> No spanning tree problems detected.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @include('topology::partials.stp-loop-evidence')

        <div class="panel panel-default">
            <div class="panel-heading"><strong>Spanning tree domains</strong> <span class="text-muted">(one row per VLAN instance and connected L2 domain)</span></div>
            <div class="table-responsive">
            <table class="table table-condensed table-hover topo-stp-table">
                <thead><tr><th>Instance</th><th>Root bridge</th><th>Root bridge ID</th><th>Bridges</th><th>Protocols</th><th>Blocked</th><th>Max hops</th><th>Last TC</th><th>TCs</th><th>Root timers<br><small>hello / max age / fwd</small></th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse($result['domains'] as $domain)
                    <tr>
                        <td>{{ $domain['label'] }}</td>
                        <td>
                            @if($domain['root']['device_id'])
                                <a href="{{ $deviceUrl($domain['root']['device_id']) }}">{{ $domain['root']['name'] }}</a>
                            @else
                                <span class="topo-text-warning">{{ $domain['root']['name'] ?? 'unknown' }}</span>
                            @endif
                        </td>
                        <td class="topo-mono">{{ $domain['root']['bridge_id'] ?? $domain['root']['mac'] }}</td>
                        <td>{{ $domain['members'] }}</td>
                        <td>{{ implode(', ', $domain['protocols']) }}</td>
                        <td>{{ $domain['blocked'] }}</td>
                        <td>{{ $domain['max_hops'] ?? '-' }}</td>
                        <td>{{ $duration($domain['last_tc']) }}</td>
                        <td>{{ $domain['tc_total'] }}</td>
                        <td>{{ $domain['timers'] ?? '-' }}</td>
                        <td><span class="topo-sev topo-sev-{{ $domain['status'] }}">{{ $domain['status'] }}</span></td>
                        <td><a href="{{ $mapUrl($domain['vlan'], $domain['root']['device_id']) }}" class="btn btn-xs btn-default"><i class="fa fa-sitemap"></i> Map</a></td>
                    </tr>
                @empty
                    <tr><td colspan="12">No spanning tree data. Make sure the <code>stp</code> discovery and poller modules are enabled.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading"><strong>Blocked / broken ports</strong> <span class="text-muted">({{ count($result['blocked']) }})</span></div>
            <div class="table-responsive">
            <table class="table table-condensed table-hover topo-stp-table">
                <thead><tr><th>Device</th><th>Instance</th><th>Port</th><th>State</th><th>Role</th><th>Cost</th><th>Designated bridge</th><th>Neighbor</th><th>Fwd transitions</th></tr></thead>
                <tbody>
                @forelse(array_slice($result['blocked'], 0, 500) as $port)
                    <tr>
                        <td><a href="{{ $deviceUrl($port['device_id']) }}">{{ $port['name'] }}</a></td>
                        <td>{{ $port['label'] }}</td>
                        <td><a href="{{ $portUrl($port['device_id'], $port['port_id']) }}">{{ $port['port'] }}</a></td>
                        <td><span class="topo-text-warning">{{ $port['state'] }}</span></td>
                        <td>{{ $port['role'] }}</td>
                        <td>{{ $port['cost'] }}</td>
                        <td class="topo-mono">{{ $port['designated_bridge'] }}@if($port['designated_name']) <span class="text-muted">({{ $port['designated_name'] }})</span>@endif</td>
                        <td>
                            @if($port['neighbor'])
                                @if($port['neighbor']['device_id'])<a href="{{ $deviceUrl($port['neighbor']['device_id']) }}">{{ $port['neighbor']['name'] }}</a>@else{{ $port['neighbor']['name'] }}@endif
                                {{ $port['neighbor']['port'] }}
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>{{ $port['transitions'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9">No blocked ports.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <a data-toggle="collapse" href="#topo-bridges"><strong>Bridges</strong></a>
                <span class="text-muted">({{ count($result['bridges']) }} instances, click to expand)</span>
            </div>
            <div id="topo-bridges" class="collapse {{ $params['device'] ? 'in' : '' }}">
            <div class="table-responsive">
            <table class="table table-condensed table-hover topo-stp-table">
                <thead><tr><th>Device</th><th>Instance</th><th>Role</th><th>Bridge ID</th><th>Root ID</th><th>Root port</th><th>Root cost</th><th>Protocol</th><th>Timers</th><th>Ports</th><th>Blocked</th><th>TCs</th><th>Last TC</th><th>TC/day</th></tr></thead>
                <tbody>
                @foreach(array_slice($result['bridges'], 0, 2000) as $bridge)
                    <tr>
                        <td><a href="{{ $deviceUrl($bridge['device_id']) }}">{{ $bridge['name'] }}</a></td>
                        <td>{{ $bridge['label'] }}</td>
                        <td>@if($bridge['is_root'])<strong><i class="fa fa-star" style="color:#f1c40f"></i> Root</strong>@else non-root @endif</td>
                        <td class="topo-mono">{{ $bridge['bridge_id'] }}</td>
                        <td class="topo-mono">{{ $bridge['root_id'] }}@if($bridge['root_name'] && ! $bridge['is_root']) <span class="text-muted">({{ $bridge['root_name'] }})</span>@endif</td>
                        <td>{{ $bridge['root_port'] ?? '-' }}</td>
                        <td>{{ $bridge['is_root'] ? '-' : $bridge['root_cost'] }}</td>
                        <td>{{ $bridge['protocol'] }}</td>
                        <td>{{ $bridge['timers'] }}</td>
                        <td>{{ $bridge['ports'] }}</td>
                        <td>{{ $bridge['blocked'] }}</td>
                        <td>{{ $bridge['top_changes'] }}</td>
                        <td>{{ $duration($bridge['last_tc']) }}</td>
                        <td>{{ $bridge['tc_rate'] ?? '-' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading"><strong>Recent spanning tree events</strong> <span class="text-muted">(LibreNMS eventlog)</span></div>
            <table class="table table-condensed table-hover topo-stp-table">
                <thead><tr><th style="width: 160px">Time</th><th style="width: 22%">Device</th><th>Message</th></tr></thead>
                <tbody>
                @forelse(array_slice($result['events'], 0, 100) as $event)
                    <tr>
                        <td>{{ $event['datetime'] }}</td>
                        <td><a href="{{ $deviceUrl($event['device_id']) }}">{{ $event['name'] }}</a></td>
                        <td class="topo-wrap">{{ $event['message'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">No spanning tree events in the selected period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    (function ($) {
        if (!$ || !$.fn || !$.fn.select2) {
            return;
        }
        $('#topo-stp-device').select2({
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
