{{-- Device dependencies (PlanningController::dependencies), rendered from PlanningService::dependencies(). --}}
@extends('layouts.librenmsv1')

@section('title', 'Device Dependencies')

@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $names = $result['device_names'] ?? [];
    $name = fn ($id) => $names[$id] ?? '#' . $id;
    $deviceUrl = fn ($id) => url('device/' . $id);
    $changes = [
        'changed' => ['warning', 'changed', 'the derived parents differ from the current ones'],
        'new' => ['info', 'new', 'no parents now'],
        'same' => ['ok', 'same', 'the current parents are the derived ones'],
        'kept' => ['muted', 'kept', 'core device or no path to a core in the topology: the current parents stay'],
    ];
    $statusText = [
        \Agoodley\LibrenmsTopology\Redundancy\DependencyPlanner::CORE => 'core device',
        \Agoodley\LibrenmsTopology\Redundancy\DependencyPlanner::NONE => 'no path to a core',
    ];
@endphp

@section('content')
<div class="container-fluid topo-page">
    @include('topology::partials.nav', ['active' => 'dependencies'])

    <form class="topo-filterbar" method="get" action="{{ route('topology.dependencies') }}">
        <label>Device
            <select id="topo-dependencies-device" name="device" class="form-control">
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
        <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-sort-amount-down"></i> Derive</button>
        <a class="btn btn-sm btn-default" href="{{ route('topology.api.dependencies', request()->query()) }}" target="_blank"><i class="fa fa-code"></i> JSON</a>
    </form>

    @if($applied)
        <div class="alert alert-{{ $applied['error'] ? 'danger' : 'success' }}"><i class="fa fa-{{ $applied['error'] ? 'exclamation-triangle' : 'check' }}"></i> {{ $applied['message'] }}</div>
    @endif
    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @if($result)
        @php
            $summary = $result['summary'];
            $changing = $summary['new'] + $summary['changed'];
        @endphp
        <div class="topo-cards">
            <div class="topo-card topo-info">
                <h4>Devices</h4>
                <div class="topo-card-value">{{ $summary['devices'] }}</div>
                <p>{{ $summary['derived'] }} with parents derived from the topology</p>
            </div>
            <div class="topo-card topo-{{ $changing ? 'warning' : 'ok' }}">
                <h4>Changes</h4>
                <div class="topo-card-value">{{ $changing }}</div>
                <p>{{ $summary['new'] }} without parents now, {{ $summary['changed'] }} with other parents; {{ $summary['same'] }} already right</p>
            </div>
            <div class="topo-card topo-info">
                <h4>Kept</h4>
                <div class="topo-card-value">{{ $summary['kept'] }}</div>
                <p>core devices and devices without a path to a core keep their parents</p>
            </div>
            <div class="topo-card topo-{{ $summary['explained_after'] > $summary['explained_now'] ? 'info' : 'ok' }}">
                <h4>Outages ({{ $result['days'] }} days)</h4>
                <div class="topo-card-value">{{ $summary['outages'] }}</div>
                <p>{{ $summary['explained_now'] }} happened while all current parents were down, {{ $summary['explained_after'] }} with the derived ones: alerts LibreNMS suppresses</p>
            </div>
        </div>

        @if($result['loops'])
            <div class="alert alert-warning">
                <i class="fa fa-exclamation-triangle"></i> Applying every change would make a loop of dependencies, because a device kept its
                current parents:
                @foreach($result['loops'] as $cycle)
                    <strong>{{ implode(' → ', array_map($name, [...$cycle, $cycle[0]])) }}</strong>@if(! $loop->last); @endif
                @endforeach
                . The changes in a loop are refused; fix the current dependencies of these devices in LibreNMS first.
            </div>
        @endif

        <form method="post" action="{{ route('topology.dependencies.apply') }}">
            @csrf
            @foreach(['device', 'group', 'location'] as $key)
                @if($params[$key])<input type="hidden" name="filter[{{ $key }}]" value="{{ $params[$key] }}">@endif
            @endforeach
            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>Parents</strong>
                    <span class="text-muted">(LibreNMS suppresses a device's alerts while all its parents are down)</span>
                    @if($canApply && $changing)
                        <button type="submit" class="btn btn-xs btn-primary pull-right" onclick="return confirm('Set the derived parents of the selected devices?')"><i class="fa fa-check"></i> Apply selected</button>
                    @endif
                </div>
                <div class="table-responsive">
                <table class="table table-condensed table-hover topo-stp-table">
                    <thead>
                    <tr>
                        @if($canApply)<th style="width: 28px"><input type="checkbox" id="topo-dependencies-all" title="Select every change" @disabled(! $changing)></th>@endif
                        <th>Device</th><th>Hops</th><th>Current parents</th><th>Derived parents</th><th>Change</th><th>Outages</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($result['rows'] as $row)
                        @php [$class, $label, $title] = $changes[$row['change']]; @endphp
                        <tr data-device="{{ $row['device_id'] }}">
                            @if($canApply)
                                <td>
                                    @if($row['change'] === 'new' || $row['change'] === 'changed')
                                        <input type="checkbox" name="devices[]" value="{{ $row['device_id'] }}" class="topo-dependencies-pick">
                                    @endif
                                </td>
                            @endif
                            <td><a href="{{ $deviceUrl($row['device_id']) }}">{{ $name($row['device_id']) }}</a></td>
                            <td>{{ $row['hops'] ?? '-' }}</td>
                            <td class="topo-wrap">
                                @foreach($row['current'] as $parent)
                                    <a href="{{ $deviceUrl($parent) }}">{{ $name($parent) }}</a>@if(! $loop->last), @endif
                                @endforeach
                                @if($row['hidden'])<small class="text-muted">{{ $row['current'] ? '+' : '' }}{{ $row['hidden'] }} you cannot see</small>@endif
                                @if(! $row['current'] && ! $row['hidden'])<span class="text-muted">none</span>@endif
                            </td>
                            <td class="topo-wrap">
                                @forelse($row['derived'] as $parent)
                                    <a href="{{ $deviceUrl($parent) }}">{{ $name($parent) }}</a>@if(! $loop->last), @endif
                                @empty
                                    <span class="text-muted">{{ $statusText[$row['status']] ?? 'none' }}</span>
                                @endforelse
                            </td>
                            <td><span class="topo-sev topo-sev-{{ $class }}" title="{{ $title }}">{{ $label }}</span></td>
                            <td>
                                @if($row['outages'])
                                    {{ $row['outages'] }}
                                    <small class="text-muted" title="outages that started while all parents were down: now / with the derived parents">({{ $row['explained_now'] }} / {{ $row['explained_after'] }} with all parents down)</small>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canApply ? 7 : 6 }}">No devices.</td></tr>
                    @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </form>

        <p class="text-muted" style="font-size: 12px">
            Parents are the neighbors one hop closer to the core of the redundancy page, over every LLDP/CDP link (also links that are down now):
            dual-homed devices get both uplinks, unmonitored switches in between are skipped to the next monitored device, and parents are always closer
            to the core so they never make a loop. Outages: started while all parents were down (within {{ \Agoodley\LibrenmsTopology\Redundancy\DependencyPlanner::OUTAGE_TOLERANCE / 60 }}
            minutes), now / with the derived parents.
            @unless($canApply) Only LibreNMS admins can apply the changes. @endunless
        </p>
    @endif
</div>
@endsection

@push('scripts')
<script>
    (function ($) {
        var all = document.getElementById('topo-dependencies-all');
        if (all) {
            all.addEventListener('change', function () {
                document.querySelectorAll('.topo-dependencies-pick').forEach(function (box) {
                    box.checked = all.checked;
                });
            });
        }
        if (!$ || !$.fn || !$.fn.select2) {
            return;
        }
        $('#topo-dependencies-device').select2({
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
