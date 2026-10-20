{{-- Rollout planner (PlanningController::rollout), rendered from PlanningService::rollout(). --}}
@extends('layouts.librenmsv1')

@section('title', 'Rollout Planner')

@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $info = $result['devices'] ?? [];
    $name = fn ($id) => $info[$id]['name'] ?? '#' . $id;
    $deviceUrl = fn ($id) => url('device/' . $id);
    $where = fn ($tier) => match (true) {
        $tier === \Agoodley\LibrenmsTopology\Redundancy\RolloutPlanner::CORE => 'core',
        $tier === \Agoodley\LibrenmsTopology\Redundancy\RolloutPlanner::UNCONNECTED => 'no path to the core now',
        default => $tier . ' hops from the core',
    };
    $reasons = ['down' => 'down now', 'unknown' => 'not in the topology (disabled, or no LLDP/CDP data)'];
    $listed = 30;
@endphp

@section('content')
<div class="container-fluid topo-page">
    @include('topology::partials.nav', ['active' => 'rollout'])

    <form class="topo-filterbar" method="get" action="{{ route('topology.rollout') }}">
        <label title="Pick devices, or leave it empty to plan every device of the group / location">Devices
            <select id="topo-rollout-devices" name="devices[]" class="form-control" multiple>
                @foreach($chosen as $id => $chosenName)
                    <option value="{{ $id }}" selected>{{ $chosenName }}</option>
                @endforeach
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
        <label title="With a group or location (no devices picked): leave out access points, servers, phones and other end devices">
            <input type="hidden" name="network" value="0">
            <input type="checkbox" name="network" value="1" @checked($networkOnly)> Switches, routers and firewalls only
        </label>
        <label title="Most devices in one batch, 0 for no limit">Batch size
            <input type="number" name="size" min="0" max="10000" class="form-control" style="width: 80px" value="{{ $batchSize }}">
        </label>
        <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-layer-group"></i> Plan</button>
        <a class="btn btn-sm btn-default" href="{{ route('topology.api.rollout', request()->query()) }}" target="_blank"><i class="fa fa-code"></i> JSON</a>
    </form>

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @if($result)
        @php $summary = $result['summary']; @endphp
        <div class="topo-cards">
            <div class="topo-card topo-info">
                <h4>Devices</h4>
                <div class="topo-card-value">{{ $summary['devices'] }}</div>
                <p>{{ $result['selection']['chosen'] ? 'picked' : ($result['selection']['network_only'] ? 'switches, routers and firewalls' : 'devices') }} in the plan</p>
            </div>
            <div class="topo-card topo-info">
                <h4>Batches</h4>
                <div class="topo-card-value">{{ $summary['batches'] }}</div>
                <p>run one after the other{{ $result['selection']['batch_size'] ? ', at most ' . $result['selection']['batch_size'] . ' devices each' : '' }}</p>
            </div>
            <div class="topo-card topo-info">
                <h4>Largest batch</h4>
                <div class="topo-card-value">{{ $summary['largest'] }}</div>
                <p>devices at the same time</p>
            </div>
            <div class="topo-card topo-{{ $summary['most_down'] ? 'warning' : 'ok' }}">
                <h4>Cut off</h4>
                <div class="topo-card-value">{{ $summary['most_down'] }}</div>
                <p>monitored devices at most lose their path to the core during one batch</p>
            </div>
            <div class="topo-card topo-{{ $summary['skipped'] ? 'warning' : 'ok' }}">
                <h4>Left out</h4>
                <div class="topo-card-value">{{ $summary['skipped'] }}</div>
                <p>devices that are down or not in the topology</p>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <strong>Batches</strong>
                <span class="text-muted">(in this order; start a batch when the previous one is back and working)</span>
            </div>
            <div class="table-responsive">
            <table class="table table-condensed table-hover topo-stp-table">
                <thead><tr><th style="width: 40px">#</th><th>Devices</th><th>Where</th><th>Down while it runs</th><th></th></tr></thead>
                <tbody>
                @forelse($result['batches'] as $batch)
                    <tr>
                        <td><strong>{{ $batch['number'] }}</strong></td>
                        <td class="topo-wrap">
                            @foreach(array_slice($batch['devices'], 0, $listed) as $deviceId)
                                <a href="{{ $deviceUrl($deviceId) }}">{{ $name($deviceId) }}</a>@if(! $loop->last), @endif
                            @endforeach
                            @if(count($batch['devices']) > $listed) &hellip; and {{ count($batch['devices']) - $listed }} more @endif
                            <details class="topo-rollout-hosts">
                                <summary>{{ count($batch['devices']) }} hostname{{ count($batch['devices']) === 1 ? '' : 's' }}</summary>
                                <textarea class="form-control input-sm" rows="3" readonly>{{ implode("\n", $batch['hostnames']) }}</textarea>
                            </details>
                        </td>
                        <td>{{ $where($batch['tier']) }}</td>
                        <td class="topo-wrap">
                            @if($batch['lost'])
                                {{ count($batch['lost']) }} monitored device{{ count($batch['lost']) === 1 ? '' : 's' }}@if($batch['network']) ({{ $batch['network'] }} switch{{ $batch['network'] === 1 ? '' : 'es' }} / router{{ $batch['network'] === 1 ? '' : 's' }})@endif:
                                @foreach(array_slice($batch['lost'], 0, $listed) as $deviceId)
                                    <a href="{{ $deviceUrl($deviceId) }}">{{ $name($deviceId) }}</a>@if(! $loop->last), @endif
                                @endforeach
                                @if(count($batch['lost']) > $listed) &hellip; @endif
                            @else
                                <span class="text-muted">no other monitored device</span>
                            @endif
                            @if($batch['unmanaged'])<br><small class="text-muted">and {{ $batch['unmanaged'] }} unmanaged neighbor{{ $batch['unmanaged'] === 1 ? '' : 's' }} (phones, access points...)</small>@endif
                        </td>
                        <td>
                            @if($batch['simulate'])
                                <a class="btn btn-xs btn-default" href="{{ route('topology.l2', ['fail' => implode(',', $batch['refs'])]) }}" title="Simulate this batch on the L2 map"><i class="fa fa-bolt"></i> Simulate</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">No devices to plan: pick devices, or a group or location with devices in the topology.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>

        @if($result['skipped'])
            <div class="panel panel-default">
                <div class="panel-heading"><strong>Left out</strong> <span class="text-muted">({{ count($result['skipped']) }})</span></div>
                <table class="table table-condensed topo-stp-table">
                    <tbody>
                    @foreach($result['skipped'] as $skipped)
                        <tr>
                            <td style="width: 30%">
                                @if(isset($info[$skipped['device_id']]))
                                    <a href="{{ $deviceUrl($skipped['device_id']) }}">{{ $name($skipped['device_id']) }}</a>
                                @else
                                    #{{ $skipped['device_id'] }}
                                @endif
                            </td>
                            <td class="text-muted">{{ $reasons[$skipped['reason']] ?? $skipped['reason'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <p class="text-muted" style="font-size: 12px">
            Planned on the network as it is now, with the core of the redundancy page
            ({{ $result['core'] ? implode(', ', array_map($name, array_slice($result['core'], 0, 6))) : 'none found' }}):
            devices farthest from the core first and the core last, so a change that breaks a device never cuts off devices that still wait for it,
            and never two devices that back each other up in one batch (a batch going down together cuts off no more than its devices one at a time).
            "Down while it runs" is what to put in the maintenance window besides the batch itself. With every core device of a part in one batch, that whole part
            has no core: core devices go one batch at a time unless they serve separate parts.
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
        $('#topo-rollout-devices').select2({
            theme: 'bootstrap',
            width: '320px',
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
