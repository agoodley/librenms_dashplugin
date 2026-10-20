{{-- Link & VLAN diagnostics page (PageController::links), rendered from TopologyService::links(). --}}
@extends('layouts.librenmsv1')

@section('title', 'Link & VLAN Diagnostics')

@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $deviceUrl = fn ($id) => url('device/' . $id);
    $mapUrl = fn ($deviceId, $vlan = null) => route('topology.l2', array_filter(['device' => $deviceId, 'vlan' => $vlan]));
    $speed = fn ($bps) => $bps ? \Agoodley\LibrenmsTopology\Links\LinkAnalyzer::speed((int) $bps) : '-';
    $pair = fn ($a, $b) => ($a ?? '-') . ' / ' . ($b ?? '-');
    $mode = fn ($end) => match ($end['mode']) {
        'trunk' => 'trunk' . ($end['native'] ? ' (' . $end['native'] . ')' : ''),
        'access' => 'access ' . $end['native'],
        default => $end['mode'],
    };
    $issueLabels = [
        'speed_mismatch' => 'speed', 'duplex_mismatch' => 'duplex', 'half_duplex' => 'half duplex', 'mtu_mismatch' => 'MTU',
        'mode_mismatch' => 'trunk / access', 'native_vlan_mismatch' => 'native VLAN', 'access_vlan_mismatch' => 'access VLAN',
        'trunk_vlan_mismatch' => 'allowed VLANs', 'vlan_split' => 'split VLAN',
        'lag_unbundled' => 'LAG on one end', 'lag_split' => 'LAG split', 'lag_degraded' => 'LAG degraded', 'lag_imbalance' => 'LAG load',
        'link_errors' => 'errors', 'link_discards' => 'discards', 'optical_rx' => 'optics',
        'neighbor_not_reported' => 'one-sided', 'no_neighbor_data' => 'no LLDP/CDP', 'description_wrong' => 'description', 'description_missing' => 'no description',
    ];
    $cards = [
        'Mismatches' => [['mismatch', 'vlans'], 'speed, duplex, MTU, port mode and VLANs'],
        'Port-channels' => [['lags'], 'one-sided, split, degraded or unevenly loaded'],
        'Errors & optics' => [['counters', 'optics'], 'interface errors, discards and optical levels'],
        'Neighbors & descriptions' => [['neighbors', 'descriptions'], 'one-sided LLDP/CDP and port descriptions'],
    ];
@endphp

@section('content')
<div class="container-fluid topo-page">
    @include('topology::partials.nav', ['active' => 'links'])

    <form class="topo-filterbar" method="get" action="{{ route('topology.links') }}">
        <label>Device
            <select id="topo-links-device" name="device" class="form-control">
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
        <a class="btn btn-sm btn-default" href="{{ route('topology.api.links', request()->query()) }}" target="_blank"><i class="fa fa-code"></i> JSON</a>
    </form>

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @if($result)
        @if($result['notice'])
            <div class="alert alert-info">{{ $result['notice'] }}</div>
        @endif

        @php
            $summary = $result['summary'];
            $problemLinks = count(array_filter($result['links'], fn ($l) => in_array($l['severity'], ['critical', 'warning'], true)));
        @endphp
        <div class="topo-cards">
            <div class="topo-card topo-{{ $summary['status'] }}">
                <h4>Health</h4>
                <div class="topo-card-value"><span class="topo-sev topo-sev-{{ $summary['status'] }}" style="font-size: 16px">{{ $summary['status'] }}</span></div>
                <p>{{ $summary['findings']['critical'] }} critical, {{ $summary['findings']['warning'] }} warnings, {{ $summary['findings']['info'] }} info</p>
            </div>
            <div class="topo-card topo-info">
                <h4>Links</h4>
                <div class="topo-card-value">{{ $summary['links'] }}</div>
                <p>{{ $summary['physical'] }} cables, {{ $summary['lags'] }} port-channel(s), {{ $summary['devices'] }} devices</p>
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
            <div class="panel-heading"><strong>Findings</strong> <span class="text-muted">({{ count($result['findings']) }})</span></div>
            <table class="table table-condensed table-hover topo-stp-table">
                <thead><tr><th style="width: 90px">Severity</th><th>Finding</th><th style="width: 22%">Devices</th></tr></thead>
                <tbody>
                @forelse($result['findings'] as $finding)
                    <tr>
                        <td><span class="topo-sev topo-sev-{{ $finding['severity'] }}">{{ $finding['severity'] }}</span></td>
                        <td class="topo-wrap">
                            <strong>{{ ucfirst($finding['title']) }}</strong>
                            @include('topology::partials.finding-age')
                            @if($finding['devices'])
                                <a href="{{ $mapUrl($finding['devices'][0], $finding['vlan']) }}" title="Show on the L2 map"><i class="fa fa-sitemap"></i></a>
                            @endif
                            <div class="topo-finding-detail">{{ $finding['detail'] }}</div>
                            @if($finding['recommendation'])
                                <div class="topo-finding-rec"><i class="fa fa-lightbulb-o"></i> {{ $finding['recommendation'] }}</div>
                            @endif
                            @if($finding['config'] !== '')
                                <details class="topo-config"><summary>Suggested configuration</summary><pre>{{ $finding['config'] }}</pre></details>
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
                    <tr><td colspan="3"><span class="topo-sev topo-sev-ok">ok</span> No link or VLAN problems detected.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <strong>Links between monitored devices</strong>
                <span class="text-muted">({{ count($result['links']) }}, {{ $problemLinks }} with warnings; port-channels as one row)</span>
                @if($problemLinks)
                    <label class="pull-right" style="font-weight: normal; margin: 0">
                        <input type="checkbox" id="topo-links-problems" checked> Only links with warnings
                    </label>
                @endif
            </div>
            <div class="table-responsive">
            <table class="table table-condensed table-hover topo-stp-table" id="topo-links-table">
                <thead><tr>
                    <th></th><th>Device</th><th>Port</th><th>Neighbor</th><th>Port</th><th>Speed</th><th>Duplex</th><th>MTU</th>
                    <th>Mode (native VLAN)</th><th>VLANs</th><th>Errors / discards<br><small>last poll</small></th><th>Rx dBm</th><th>Issues</th>
                </tr></thead>
                <tbody>
                @forelse(array_slice($result['links'], 0, 3000) as $link)
                    @php
                        $a = $link['a'];
                        $b = $link['b'];
                    @endphp
                    <tr data-problem="{{ in_array($link['severity'], ['critical', 'warning'], true) ? 1 : 0 }}">
                        <td>
                            <span class="topo-sev topo-sev-{{ $link['severity'] }}">{{ $link['severity'] }}</span>
                            @if($link['status'] !== 'up')<br><span class="topo-status-{{ $link['status'] }}">link {{ $link['status'] }}</span>@endif
                        </td>
                        <td><a href="{{ $deviceUrl($a['device_id']) }}">{{ $a['name'] }}</a></td>
                        <td>
                            @if($a['url'])<a href="{{ $a['url'] }}" title="{{ $a['alias'] }}">{{ $a['port'] }}</a>@else{{ $a['port'] }}@endif
                            @if($a['members'])<br><small class="text-muted">{{ implode(', ', $a['members']) }}</small>@endif
                        </td>
                        <td><a href="{{ $deviceUrl($b['device_id']) }}">{{ $b['name'] }}</a></td>
                        <td>
                            @if($b['url'])<a href="{{ $b['url'] }}" title="{{ $b['alias'] }}">{{ $b['port'] }}</a>@else{{ $b['port'] }}@endif
                            @if($b['members'])<br><small class="text-muted">{{ implode(', ', $b['members']) }}</small>@endif
                        </td>
                        <td class="{{ $link['mismatch']['speed'] ? 'topo-mismatch' : '' }}">
                            @if($link['lag']){{ $link['lag']['members'] }} &times; @endif{{ $link['mismatch']['speed'] ? $pair($speed($a['speed']), $speed($b['speed'])) : $speed($a['speed'] ?: $b['speed']) }}
                            @if($link['lag'] && $link['lag']['up'] < $link['lag']['members'])<br><small class="topo-status-degraded">{{ $link['lag']['up'] }} up</small>@endif
                        </td>
                        <td class="{{ $link['mismatch']['duplex'] ? 'topo-mismatch' : '' }}">{{ $pair($a['duplex'], $b['duplex']) }}</td>
                        <td class="{{ $link['mismatch']['mtu'] ? 'topo-mismatch' : '' }}">{{ $pair($a['mtu'], $b['mtu']) }}</td>
                        <td class="{{ $link['mismatch']['mode'] ? 'topo-mismatch' : '' }}">{{ $pair($mode($a), $mode($b)) }}</td>
                        <td class="{{ $link['mismatch']['vlans'] ? 'topo-mismatch' : '' }}">
                            <span title="{{ $a['name'] }}: {{ $a['vlans'] ?? 'unknown' }} | {{ $b['name'] }}: {{ $b['vlans'] ?? 'unknown' }}">{{ $pair($a['vlan_count'], $b['vlan_count']) }}</span>
                        </td>
                        <td class="{{ isset($link['issues']['link_errors']) || isset($link['issues']['link_discards']) ? 'topo-mismatch' : '' }}">
                            {{ number_format($a['errors']) }} / {{ number_format($b['errors']) }}
                            @if($a['discards'] || $b['discards'])<br><small class="text-muted">{{ number_format($a['discards']) }} / {{ number_format($b['discards']) }} discards</small>@endif
                        </td>
                        <td class="{{ isset($link['issues']['optical_rx']) ? 'topo-mismatch' : '' }}">
                            {{ $pair($a['rx'] === null ? null : number_format($a['rx'], 1), $b['rx'] === null ? null : number_format($b['rx'], 1)) }}
                        </td>
                        <td class="topo-wrap">
                            @foreach($link['issues'] as $code => $severity)
                                <span class="topo-issue topo-issue-{{ $severity }}">{{ $issueLabels[$code] ?? $code }}</span>
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="13">No links between monitored devices. Make sure the <code>discovery-protocols</code> module (LLDP/CDP) is enabled.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    (function ($) {
        var toggle = document.getElementById('topo-links-problems');
        var filter = function () {
            document.querySelectorAll('#topo-links-table tbody tr[data-problem]').forEach(function (row) {
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
        $('#topo-links-device').select2({
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
