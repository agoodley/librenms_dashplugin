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
    $disabled = array_flip($result['disabled'] ?? []);
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
    $coreModes = ['configured' => 'configured', 'stp' => 'spanning tree root', 'automatic' => 'automatic'];
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
        <div class="alert alert-{{ $applied['error'] ? 'danger' : (($applied['stale'] ?? []) || ($applied['refused'] ?? []) ? 'warning' : 'success') }} topo-applied">
            <i class="fa fa-{{ $applied['error'] ? 'exclamation-triangle' : 'check' }}"></i> {{ $applied['message'] }}
        </div>
    @endif
    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @if($result)
        @php
            $summary = $result['summary'];
            $changing = $summary['new'] + $summary['changed'];
            $filterFields = array_filter(['device' => $params['device'], 'group' => $params['group'], 'location' => $params['location']]);
        @endphp
        @foreach($result['advice'] as $advice)
            @if($advice['lookalike'])
                @php
                    $adviceParents = array_map($name, $advice['parents']);
                    $parentList = count($adviceParents) > 1 ? implode(', ', array_slice($adviceParents, 0, -1)) . ' and ' . end($adviceParents) : implode('', $adviceParents);
                @endphp
                <div class="alert alert-warning topo-core-advice">
                    <i class="fa fa-exclamation-triangle"></i> <strong>{{ $name($advice['lookalike']) }}</strong> is cabled like a core switch
                    ({{ $advice['shared'] }} switches have links to both it and <strong>{{ $name($advice['core']) }}</strong>, the only core device of its part of the network):
                    add it to <i>Core devices</i> in the plugin settings, so it gets no parents.
                    @if(count($adviceParents) === 1)
                        Until then it depends on {{ $parentList }} alone, and LibreNMS suppresses its alerts whenever {{ $parentList }} is down.
                    @elseif($adviceParents)
                        Until then it depends on {{ $parentList }}, and LibreNMS suppresses its alerts while all of them are down.
                    @endif
                </div>
            @endif
        @endforeach

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
            <div class="topo-card topo-info">
                <h4>Core</h4>
                <div class="topo-card-value" style="font-size: 16px; line-height: 31px">
                    @forelse(array_slice($result['core'], 0, 4) as $core)
                        <a href="{{ $deviceUrl($core['device_id']) }}">{{ $name($core['device_id']) }}</a>@if(! $loop->last), @endif
                    @empty
                        <span class="text-muted">none</span>
                    @endforelse
                    @if(count($result['core']) > 4) <small>+{{ count($result['core']) - 4 }}</small> @endif
                </div>
                <p>parents point toward it ({{ implode(', ', array_unique(array_map(fn ($core) => $coreModes[$core['mode']] ?? $core['mode'], $result['core']))) ?: 'no switches with neighbors found' }})</p>
            </div>
            <div class="topo-card topo-{{ $summary['explained_after'] > $summary['explained_now'] ? 'info' : 'ok' }}">
                <h4>Outages ({{ $result['days'] }} days)</h4>
                <div class="topo-card-value">{{ $summary['outages'] }}</div>
                <p>{{ $summary['explained_now'] }} happened while all current parents were down, {{ $summary['explained_after'] }} with the derived ones: alerts LibreNMS suppresses</p>
            </div>
        </div>

        @if($result['loops'])
            <div class="alert alert-warning topo-dependency-loops">
                <i class="fa fa-exclamation-triangle"></i> Applying every change of this view would make a loop of dependencies with parents that stay as they are:
                @foreach($result['loops'] as $cycle)
                    <strong>{{ implode(' → ', array_map($name, [...$cycle, $cycle[0]])) }}</strong>@if(! $loop->last); @endif
                @endforeach
                . The changes of the devices on a loop are refused, the others are applied; change the current dependencies of these devices in LibreNMS first.
            </div>
        @endif

        @if($canApply && $changing)
            {{-- every change of this view: the filter and the fingerprint of the changes shown, so the checkboxes need not all be posted --}}
            <form id="topo-dependencies-every" method="post" action="{{ route('topology.dependencies.apply') }}">
                @csrf
                <input type="hidden" name="all" value="1">
                <input type="hidden" name="fingerprint" value="{{ $result['fingerprint'] }}">
                @foreach($filterFields as $key => $value)
                    <input type="hidden" name="filter[{{ $key }}]" value="{{ $value }}">
                @endforeach
            </form>
        @endif

        <form id="topo-dependencies-form" method="post" action="{{ route('topology.dependencies.apply') }}">
            @csrf
            {{-- before the checkboxes: PHP drops the inputs beyond max_input_vars, this tells how many were sent --}}
            <input type="hidden" name="selected" id="topo-dependencies-selected" value="">
            @foreach($filterFields as $key => $value)
                <input type="hidden" name="filter[{{ $key }}]" value="{{ $value }}">
            @endforeach
            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>Parents</strong>
                    <span class="text-muted">(LibreNMS suppresses a device's alerts while all its parents are down)</span>
                    @if($canApply && $changing)
                        <span class="pull-right">
                            <button type="submit" class="btn btn-xs btn-primary" onclick="return confirm('Set the derived parents of the selected devices?')"><i class="fa fa-check"></i> Apply selected</button>
                            <button type="submit" form="topo-dependencies-every" class="btn btn-xs btn-default" id="topo-dependencies-apply-every"
                                    onclick="return confirm('Set the derived parents of all {{ $changing }} devices that change in this view?')"><i class="fa fa-check-double"></i> Apply every change of this view ({{ $changing }})</button>
                        </span>
                    @endif
                </div>
                <div class="table-responsive">
                <table class="table table-condensed table-hover topo-stp-table">
                    <thead>
                    <tr>
                        @if($canApply)<th style="width: 28px"><input type="checkbox" id="topo-dependencies-all" title="Select every change shown" aria-label="Select every change shown" @disabled(! $changing)></th>@endif
                        <th>Device</th><th title="hops from the core devices">Hops</th><th>Current parents</th><th>Derived parents</th><th>Change</th><th>Outages</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($result['rows'] as $row)
                        @php [$class, $label, $title] = $changes[$row['change']]; @endphp
                        <tr data-device="{{ $row['device_id'] }}">
                            @if($canApply)
                                <td>
                                    @if($row['change'] === 'new' || $row['change'] === 'changed')
                                        <input type="checkbox" name="devices[]" value="{{ $row['device_id'] }}:{{ implode(',', $row['derived']) }}" class="topo-dependencies-pick" aria-label="Apply the derived parents of {{ $name($row['device_id']) }}">
                                    @endif
                                </td>
                            @endif
                            <td><a href="{{ $deviceUrl($row['device_id']) }}">{{ $name($row['device_id']) }}</a></td>
                            <td>{{ $row['hops'] ?? '-' }}</td>
                            <td class="topo-wrap">
                                @foreach($row['current'] as $parent)
                                    <a href="{{ $deviceUrl($parent) }}">{{ $name($parent) }}</a>@if(isset($disabled[$parent])) <small class="text-muted" title="a disabled device never counts as down: it never suppresses alerts">(disabled)</small>@endif{{ $loop->last ? '' : ',' }}
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

        @php $single = array_column(array_filter($result['advice'], fn ($advice) => ! $advice['lookalike']), 'core'); @endphp
        <p class="text-muted" style="font-size: 12px">
            Parents are the neighbors one hop closer to the core, over every LLDP/CDP link (also links that are down now): dual-homed devices get both
            uplinks, and unmonitored switches in between are skipped to the next monitored device. A switch also gets the switches next to it at the
            same distance from the core that rank before it (lower spanning tree bridge priority, then a switch cabled like a second core switch, then
            lower device id). Parents always come first in that order, so they never make a loop.
            Outages: started while all parents were down (within {{ \Agoodley\LibrenmsTopology\Redundancy\DependencyPlanner::OUTAGE_TOLERANCE / 60 }}
            minutes), now / with the derived parents. A disabled parent never counts as down; applying a change removes it.
            @if($single)
                {{ implode(', ', array_map($name, array_slice($single, 0, 6))) }} {{ count($single) === 1 ? 'is the only core device of its' : 'are the only core devices of their' }} part of the network.
            @endif
            Change the core with <i>Core devices</i> in the plugin settings.
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
        var form = document.getElementById('topo-dependencies-form');
        var selected = document.getElementById('topo-dependencies-selected');
        if (form && selected) {
            form.addEventListener('submit', function () {
                selected.value = document.querySelectorAll('.topo-dependencies-pick:checked').length;
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
