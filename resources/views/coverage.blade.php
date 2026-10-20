{{-- Monitoring coverage page (CoverageController::index), rendered from CoverageService::report(). --}}
@extends('layouts.librenmsv1')

@section('title', 'Monitoring Coverage')

@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $deviceUrl = fn ($id) => url('device/' . $id);
    $portUrl = fn ($deviceId, $portId) => url('device/device=' . $deviceId . '/tab=port/port=' . $portId . '/');
    $mapUrl = fn ($deviceId) => route('topology.l2', ['device' => $deviceId]);
    $names = $result['device_names'] ?? [];
    $name = fn ($id) => $names[$id] ?? '#' . $id;
    $ago = fn ($time) => $time ? \Agoodley\LibrenmsTopology\Stp\StpAnalyzer::duration(max(0, time() - (int) $time)) . ' ago' : '-';
    $roles = [
        'router' => ['router', 'fa-random'], 'firewall' => ['firewall', 'fa-shield-alt'], 'l3switch' => ['L3 switch', 'fa-sitemap'],
        'switch' => ['switch', 'fa-sitemap'], 'wireless' => ['access point', 'fa-wifi'], 'server' => ['server', 'fa-server'],
        'phone' => ['phone', 'fa-phone'], 'other' => ['other', 'fa-cube'], 'unknown' => ['unknown', 'fa-question-circle'],
    ];
    $role = fn ($key) => '<i class="fa ' . ($roles[$key][1] ?? 'fa-question-circle') . ' fa-fw"></i> ' . e($roles[$key][0] ?? $key);
    $plural = fn ($count, $word, $words = null) => $count . ' ' . ($count === 1 ? $word : ($words ?? $word . 's'));
    // each list is cut at the same number of rows (CoverageAnalyzer::ROWS), the JSON too
    $cut = fn (string $list, string $what) => ($result[$list . '_total'] ?? 0) > count($result[$list] ?? [])
        ? 'Showing the first ' . count($result[$list]) . ' of ' . $result[$list . '_total'] . " $what: narrow the report with the device, group or location filter."
        : null;
@endphp

@section('content')
<div class="container-fluid topo-page">
    @include('topology::partials.nav', ['active' => 'coverage'])

    <form class="topo-filterbar" method="get" action="{{ route('topology.coverage') }}">
        <label>Device
            <select id="topo-coverage-device" name="device" class="form-control">
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
        <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-binoculars"></i> Analyze</button>
        <a class="btn btn-sm btn-default" href="{{ route('topology.api.coverage', request()->query()) }}" target="_blank"><i class="fa fa-code"></i> JSON</a>
    </form>

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @if($result)
        @php
            $summary = $result['summary'];
            $worst = fn (array $categories) => \Agoodley\LibrenmsTopology\Stp\Finding::worst(array_column(array_filter($result['findings'], fn ($f) => in_array($f['category'], $categories, true)), 'severity'));
            $roleCounts = [];
            foreach (['router', 'firewall', 'switch', 'wireless'] as $key) {
                if (! empty($summary['roles'][$key])) {
                    $roleCounts[] = $plural($summary['roles'][$key], $roles[$key][0], $key === 'switch' ? 'switches' : null);
                }
            }
            // phones and servers are only counted; neighbors of unknown type and those monitored on devices out of the
            // user's sight are folded away
            $endDevices = ['server' => ['server', 'servers'], 'phone' => ['phone', 'phones']];
            $otherCounts = [];
            foreach ($summary['other_neighbors'] as $key => $count) {
                $otherCounts[] = number_format($count) . ' ' . ($endDevices[$key] ?? [$key, $key . 's'])[$count === 1 ? 0 : 1];
            }
            $mainNeighbors = array_values(array_filter($result['neighbors'], fn ($n) => $n['status'] !== 'hidden' && ($n['network'] || $n['status'] !== 'not_monitored')));
            $unknownNeighbors = array_values(array_filter($result['neighbors'], fn ($n) => ! $n['network'] && $n['status'] === 'not_monitored'));
            $hiddenNeighbors = array_values(array_filter($result['neighbors'], fn ($n) => $n['status'] === 'hidden'));
            $gapDevices = array_values(array_filter($result['devices'], fn ($d) => $d['state'] !== 'quiet'));
            $quietDevices = array_values(array_filter($result['devices'], fn ($d) => $d['state'] === 'quiet'));
        @endphp
        <div class="topo-cards">
            <div class="topo-card topo-{{ $summary['status'] }}">
                <h4>Health</h4>
                <div class="topo-card-value"><span class="topo-sev topo-sev-{{ $summary['status'] }}" style="font-size: 16px">{{ $summary['status'] }}</span></div>
                <p>{{ $summary['findings']['critical'] }} critical, {{ $summary['findings']['warning'] }} warnings, {{ $summary['findings']['info'] }} info</p>
            </div>
            <div class="topo-card topo-{{ $worst(['unmonitored']) }}">
                <h4>Not monitored</h4>
                <div class="topo-card-value">{{ $summary['not_monitored'] }}</div>
                <p>{{ $roleCounts ? implode(', ', $roleCounts) : 'switches, routers, firewalls and access points' }} seen only as LLDP/CDP neighbors
                    @if($summary['statuses']['not_linked'] || $summary['statuses']['ambiguous'] || $summary['statuses']['disabled'])
                        <br>+ {{ $summary['statuses']['not_linked'] }} not linked to their device, {{ $summary['statuses']['ambiguous'] }} named like several devices,
                        {{ $summary['statuses']['disabled'] }} disabled
                    @endif
                </p>
            </div>
            <div class="topo-card topo-{{ $worst(['devices']) }}">
                <h4>Missing from the maps</h4>
                <div class="topo-card-value">{{ $summary['devices'] }}</div>
                <p>monitored network devices that report no LLDP/CDP neighbors</p>
            </div>
            <div class="topo-card topo-{{ $worst(['ports']) }}">
                <h4>Possible unmanaged switches</h4>
                <div class="topo-card-value">{{ $summary['ports'] }}</div>
                <p>ports with several LLDP/CDP neighbors{{ $result['mac_threshold'] ? ', or access ports with ' . $result['mac_threshold'] . ' or more MAC addresses and no neighbor that explains them' : ' (counting MAC addresses is off in the settings)' }}</p>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading"><strong>Findings</strong> <span class="text-muted">({{ $result['findings_total'] }})</span></div>
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
                    <tr><td colspan="3"><span class="topo-sev topo-sev-ok">ok</span> Every LLDP/CDP neighbor that looks like a network device is monitored, and every monitored network device reports its neighbors.</td></tr>
                @endforelse
                </tbody>
            </table>
            @if($cut('findings', 'findings, most severe first'))
                <div class="panel-footer text-muted">{{ $cut('findings', 'findings, most severe first') }}</div>
            @endif
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <strong>Network devices LibreNMS does not monitor</strong>
                <span class="text-muted">(switches, routers, firewalls and access points seen as LLDP/CDP neighbors, and neighbors LibreNMS did not link to their device: named like a monitored device, like several, or disabled)</span>
            </div>
            <div class="table-responsive">
                @include('topology::partials.coverage-neighbors', ['rows' => $mainNeighbors])
            </div>
            @if($unknownNeighbors)
                <details class="topo-details">
                    <summary>Neighbors of unknown type that are not monitored ({{ $summary['roles']['unknown'] ?? count($unknownNeighbors) }}): their platform or description does not tell what they are</summary>
                    <div class="table-responsive">@include('topology::partials.coverage-neighbors', ['rows' => $unknownNeighbors])</div>
                </details>
            @endif
            @if($hiddenNeighbors)
                <details class="topo-details">
                    <summary>Neighbors monitored on devices you may not see ({{ count($hiddenNeighbors) }})</summary>
                    <div class="table-responsive">@include('topology::partials.coverage-neighbors', ['rows' => $hiddenNeighbors])</div>
                </details>
            @endif
            @if($otherCounts)
                <div class="panel-footer text-muted">Also seen as LLDP/CDP neighbors and not monitored, counted but not listed: {{ implode(', ', $otherCounts) }}.</div>
            @endif
            @if($cut('neighbors', 'neighbors, network devices first'))
                <div class="panel-footer text-muted">{{ $cut('neighbors', 'neighbors, network devices first') }}</div>
            @endif
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <strong>Monitored devices without LLDP/CDP neighbors</strong>
                <span class="text-muted">(their links come from the other end only, or are missing from the maps)</span>
            </div>
            <div class="table-responsive">
                @include('topology::partials.coverage-devices', ['rows' => $gapDevices])
            </div>
            @if($quietDevices)
                <details class="topo-details">
                    <summary>Servers, phones, other devices and firewalls without LLDP/CDP support that report no neighbors ({{ count($quietDevices) }}): usual for them</summary>
                    <div class="table-responsive">@include('topology::partials.coverage-devices', ['rows' => $quietDevices])</div>
                </details>
            @endif
            @if($cut('devices', 'devices, problems first'))
                <div class="panel-footer text-muted">{{ $cut('devices', 'devices, problems first') }}</div>
            @endif
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <strong>Ports that look like an unmanaged switch or hub</strong>
                <span class="text-muted">(several LLDP/CDP neighbors on one port{{ $result['mac_threshold'] ? ', or ' . $result['mac_threshold'] . ' or more MAC addresses in the last discovery on an Ethernet access port without a neighbor that explains them (a phone does not); trunks, port-channels and ports whose addresses are in three or more VLANs are left out' : '' }})</span>
            </div>
            <table class="table table-condensed table-hover topo-stp-table">
                <thead><tr><th>Device</th><th>Port</th><th>Description</th><th>Evidence</th><th>VLANs</th><th>Last seen</th></tr></thead>
                <tbody>
                @forelse($result['ports'] as $port)
                    <tr>
                        <td><a href="{{ $deviceUrl($port['device_id']) }}">{{ $name($port['device_id']) }}</a></td>
                        <td><a href="{{ $portUrl($port['device_id'], $port['port_id']) }}">{{ $port['port'] }}</a></td>
                        <td class="topo-wrap">{{ $port['alias'] ?: '-' }}</td>
                        <td class="topo-wrap">
                            @if($port['neighbors'])
                                {{ count($port['neighbors']) }} LLDP/CDP neighbors: {{ \Illuminate\Support\Str::limit(implode(', ', $port['neighbors']), 120) }}
                                @if($port['macs'] !== null)<br>@endif
                            @endif
                            @if($port['macs'] !== null){{ $plural($port['macs'], 'MAC address', 'MAC addresses') }}@endif
                        </td>
                        <td class="topo-wrap">{{ $port['vlans'] ? implode(', ', $port['vlans']) : '-' }}</td>
                        <td>{{ $ago($port['last_seen']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">No port has several LLDP/CDP neighbors{{ $result['mac_threshold'] ? ', and no access port without a neighbor that explains them has ' . $result['mac_threshold'] . ' or more MAC addresses' : '' }}.</td></tr>
                @endforelse
                </tbody>
            </table>
            @if($cut('ports', 'ports, most neighbors and MAC addresses first'))
                <div class="panel-footer text-muted">{{ $cut('ports', 'ports, most neighbors and MAC addresses first') }}</div>
            @endif
        </div>

        <p class="text-muted" style="font-size: 12px">
            Built from the LLDP/CDP neighbor tables and forwarding tables LibreNMS reads at discovery (every 6 hours by default), so a change
            shows up after the next discovery; MAC addresses are those of each device's last discovery. LibreNMS does not keep the management
            address a neighbor advertises: add a device by the name shown here, or by its address when the name is one. Single links that only one end reports are on the
            <a href="{{ route('topology.links', array_filter(['device' => $params['device'], 'group' => $params['group'], 'location' => $params['location']])) }}">Link &amp; VLAN</a> page.
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
        $('#topo-coverage-device').select2({
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
