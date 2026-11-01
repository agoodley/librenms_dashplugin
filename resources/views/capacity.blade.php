{{-- Switch port capacity page (CapacityController::index), rendered from Capacity\CapacityService::analyze(). --}}
@extends('layouts.librenmsv1')

@section('title', 'Switch Port Capacity')

@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    use Agoodley\LibrenmsTopology\Capacity\CapacityAnalyzer as Capacity;
    use Agoodley\LibrenmsTopology\Support\Text;
    $query = array_filter(['device' => $params['device'], 'group' => $params['group'], 'location' => $params['location'], 'days' => $days],
        fn ($value) => $value !== null && $value !== '');
    $speed = fn ($bps) => $bps ? \Agoodley\LibrenmsTopology\Links\LinkAnalyzer::speed((int) $bps) : '-';
    $when = fn (?int $time) => $time === null ? '-' : date('Y-m-d H:i', $time);
    $stateLabels = [
        Capacity::IN_USE => ['in use', 'success'], Capacity::FREE => ['free', 'info'], Capacity::SHUTDOWN => ['shut down', 'default'],
        Capacity::ERR_DISABLED => ['err-disabled', 'danger'], Capacity::UPLINK => ['uplink', 'default'], Capacity::TRUNK => ['trunk', 'default'],
        Capacity::NOT_PRESENT => ['not present', 'default'],
        Capacity::EXCLUDED => ['not counted', 'default'],
    ];
    // the bar of a share in use: red when fewer ports than the warning share are available, amber below twice that share
    $bar = function (?float $use) use ($lowPercent): string {
        $available = $use === null ? 100 : 100 - $use;

        return match (true) {
            $lowPercent > 0 && $available < $lowPercent => 'danger',
            $lowPercent > 0 && $available < 2 * $lowPercent => 'warning',
            default => 'success',
        };
    };
@endphp

@section('content')
<div class="container-fluid topo-page">
    @include('topology::partials.nav', ['active' => 'capacity'])

    <form class="topo-filterbar" method="get" action="{{ route('topology.capacity') }}">
        <label>Device
            <select id="topo-capacity-device" name="device" class="form-control">
                <option value="">All switches</option>
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
        <label title="A free or shut down port counts as unused after this many days down">Unused after
            <input type="number" min="1" max="3650" name="days" class="form-control" style="width: 80px" value="{{ $days }}" placeholder="{{ $result['days'] ?? '' }}"> days
        </label>
        @if($list !== 'not_in_use')
            <input type="hidden" name="list" value="{{ $list }}">
        @endif
        <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-calculator"></i> Analyze</button>
        <a class="btn btn-sm btn-default" href="{{ route('topology.api.capacity', $query) }}" target="_blank"><i class="fa fa-code"></i> JSON</a>
    </form>

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @if($result)
        @php
            $summary = $result['summary'];
            $dayText = Text::count($result['days'], 'day');
            $notCounted = $summary['uplinks'] + $summary['trunks'] + $summary['not_present'] + $summary['excluded'];
            $lists = [];
            foreach (Capacity::LISTS as $key => $label) {
                $lists[$key] = [$label, count(Capacity::filter($result['ports'], $key))];
            }
            $shown = Capacity::filter($result['ports'], $list);
            $sources = $result['sources'];
        @endphp
        @foreach($result['notices'] as $notice)
            <div class="alert alert-info topo-capacity-notice">{{ $notice }}</div>
        @endforeach

        <div class="topo-cards" id="topo-capacity-cards">
            @include('topology::partials.health-card', ['summary' => $summary])
            <div class="topo-card topo-info">
                <h4>Access ports</h4>
                <div class="topo-card-value">{{ number_format($summary['ports']) }}</div>
                @php($locationCount = count(array_filter($result['locations'], fn ($l) => $l['location_id'] !== null)))
                <p>on {{ Text::count($summary['devices'], 'switch', 'switches') }}{{ $locationCount ? ' in ' . Text::count($locationCount, 'location') : '' }};
                    {{ number_format($notCounted) }} more not counted (uplinks, trunks, empty slots)</p>
            </div>
            <div class="topo-card topo-info">
                <h4>In use</h4>
                <div class="topo-card-value">{{ number_format($summary['in_use']) }}</div>
                <p>{{ Capacity::percentText($summary['use_percent']) }}% of the access ports are up</p>
            </div>
            <a class="topo-card topo-{{ $bar($summary['use_percent']) === 'success' ? 'ok' : ($bar($summary['use_percent']) === 'danger' ? 'critical' : 'warning') }}"
               href="{{ route('topology.capacity', $query + ['list' => 'unused']) }}#topo-capacity-ports">
                <h4>Available</h4>
                <div class="topo-card-value">{{ number_format($summary['available']) }}</div>
                <p>free or shut down; {{ number_format($summary['unused']) }} unused for {{ $dayText }} or more</p>
            </a>
            <a class="topo-card {{ $summary['err_disabled'] ? 'topo-warning' : 'topo-ok' }}" href="{{ route('topology.capacity', $query + ['list' => 'err_disabled']) }}#topo-capacity-ports">
                <h4>Err-disabled</h4>
                <div class="topo-card-value">{{ number_format($summary['err_disabled']) }}</div>
                <p>shut down by the switch (syslog, SNMP traps)</p>
            </a>
            <a class="topo-card topo-info" href="{{ route('topology.capacity', $query + ['list' => 'poe']) }}#topo-capacity-ports">
                <h4>PoE available</h4>
                <div class="topo-card-value">{{ $summary['poe'] === null ? '?' : number_format($summary['poe_available']) }}</div>
                <p>{{ $summary['poe'] === null ? 'no PoE data in LibreNMS' : 'of ' . Text::count($summary['poe'], 'PoE access port') }}</p>
            </a>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading"><strong>Findings</strong> <span class="text-muted">({{ count($result['findings']) }})</span></div>
            <table class="table table-condensed table-hover topo-stp-table" id="topo-capacity-findings">
                <thead><tr><th style="width: 90px">Severity</th><th>Finding</th><th style="width: 22%">Devices</th></tr></thead>
                <tbody>
                @forelse($result['findings'] as $finding)
                    <tr data-code="{{ $finding['code'] }}">
                        <td><span class="topo-sev topo-sev-{{ $finding['severity'] }}">{{ $finding['severity'] }}</span></td>
                        <td class="topo-wrap">
                            <strong>{{ ucfirst($finding['title']) }}</strong>
                            @include('topology::partials.finding-age')
                            <div class="topo-finding-detail">{{ $finding['detail'] }}</div>
                            @if($finding['recommendation'])
                                <div class="topo-finding-rec"><i class="fa fa-lightbulb-o"></i> {{ $finding['recommendation'] }}</div>
                            @endif
                        </td>
                        <td class="topo-wrap">
                            @foreach(array_slice($finding['devices'], 0, 12) as $deviceId)
                                <a href="{{ url('device/' . $deviceId) }}">{{ $result['device_names'][$deviceId] ?? '#' . $deviceId }}</a>@if(! $loop->last), @endif
                            @endforeach
                            @if(count($finding['devices']) > 12) <span class="text-muted">and {{ count($finding['devices']) - 12 }} more</span>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3"><span class="topo-sev topo-sev-ok">ok</span> Every location has free access ports, and no port is err-disabled.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <strong>Locations</strong> <span class="text-muted">(wiring closets: LibreNMS locations, fullest first)</span>
                <a class="btn btn-xs btn-default pull-right" href="{{ route('topology.capacity.export', $query + ['what' => 'locations']) }}"><i class="fa fa-download"></i> CSV</a>
            </div>
            <div class="table-responsive">
            <table class="table table-condensed table-hover topo-stp-table" id="topo-capacity-locations">
                <thead><tr>
                    <th>Location</th><th>Switches</th><th>Access ports</th><th>In use</th><th style="min-width: 130px">Use</th><th>Available</th>
                    <th>Unused<br><small>{{ $result['days'] }}+ days</small></th><th>Down<br><small>&lt; {{ $result['days'] }} days</small></th>
                    <th>Down time<br><small>unknown</small></th><th>Shut down</th><th>Err-disabled</th><th>PoE<br><small>available</small></th>
                </tr></thead>
                <tbody>
                @forelse($result['locations'] as $location)
                    <tr data-location="{{ $location['location_id'] ?? '' }}">
                        <td class="topo-wrap">
                            @if($location['location_id'] !== null)
                                <a href="{{ route('topology.capacity', array_diff_key($query, ['device' => 1]) + ['location' => $location['location_id']]) }}">{{ $location['name'] }}</a>
                            @else
                                <span class="text-muted">No location</span>
                            @endif
                        </td>
                        <td>{{ count($location['devices']) }}</td>
                        <td>{{ number_format($location['ports']) }}</td>
                        <td>{{ number_format($location['in_use']) }}</td>
                        <td>@include('topology::partials.capacity-bar', ['use' => $location['use_percent'], 'class' => $bar($location['use_percent'])])</td>
                        <td>{{ number_format($location['available']) }}</td>
                        <td>{{ number_format($location['unused']) }}</td>
                        <td>{{ number_format($location['recent']) }}</td>
                        <td>{{ number_format($location['unknown_age']) }}</td>
                        <td>{{ number_format($location['shutdown']) }}</td>
                        <td class="{{ $location['err_disabled'] ? 'topo-mismatch' : '' }}">{{ number_format($location['err_disabled']) }}</td>
                        <td>{{ $location['poe'] === null ? '-' : number_format($location['poe_available']) . ' / ' . number_format($location['poe']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="12">No switch in this scope.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <strong>Switches</strong> <span class="text-muted">({{ count($result['devices']) }})</span>
                <a class="btn btn-xs btn-default pull-right" href="{{ route('topology.capacity.export', $query + ['what' => 'switches']) }}"><i class="fa fa-download"></i> CSV</a>
            </div>
            <div class="table-responsive">
            <table class="table table-condensed table-hover topo-stp-table" id="topo-capacity-switches">
                <thead><tr>
                    <th>Switch</th><th>Location</th><th>Up since</th><th>Access ports</th><th>In use</th><th style="min-width: 130px">Use</th><th>Available</th>
                    <th>Unused<br><small>{{ $result['days'] }}+ days</small></th><th>Shut down</th><th>Err-disabled</th><th>PoE<br><small>available</small></th><th>Not counted</th>
                </tr></thead>
                <tbody>
                @foreach(array_slice($result['devices'], 0, 2000) as $device)
                    <tr data-device="{{ $device['device_id'] }}">
                        <td class="topo-wrap"><a href="{{ $device['url'] }}">{{ $device['name'] }}</a>
                            <a href="{{ route('topology.capacity', $query + ['device' => $device['device_id']]) }}#topo-capacity-ports" title="Only this switch"><i class="fa fa-filter"></i></a></td>
                        <td class="topo-wrap">{{ $device['location'] ?: '-' }}</td>
                        <td title="{{ $device['uptime'] !== null ? 'uptime ' . Text::count(intdiv($device['uptime'], 86400), 'day') : '' }}">{{ $device['boot'] !== null ? date('Y-m-d', $device['boot']) : '-' }}</td>
                        <td>{{ number_format($device['ports']) }}</td>
                        <td>{{ number_format($device['in_use']) }}</td>
                        <td>@include('topology::partials.capacity-bar', ['use' => $device['use_percent'], 'class' => $bar($device['use_percent'])])</td>
                        <td>{{ number_format($device['available']) }}</td>
                        <td>{{ number_format($device['unused']) }}</td>
                        <td>{{ number_format($device['shutdown']) }}</td>
                        <td class="{{ $device['err_disabled'] ? 'topo-mismatch' : '' }}">{{ number_format($device['err_disabled']) }}</td>
                        <td>{{ $device['poe'] === null ? '-' : number_format($device['poe_available']) . ' / ' . number_format($device['poe']) }}</td>
                        <td title="uplinks, trunks, ports without hardware, ports left out by the settings">{{ number_format($device['uplinks'] + $device['trunks'] + $device['not_present'] + $device['excluded']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            @if(count($result['devices']) > 2000)
                <div class="panel-footer text-muted">The first 2,000 of {{ number_format(count($result['devices'])) }} switches are shown; the CSV has all of them.</div>
            @endif
        </div>

        <div class="panel panel-default" id="topo-capacity-ports">
            <div class="panel-heading">
                <strong>Ports</strong>
                <ul class="nav nav-pills topo-capacity-lists" style="display: inline-flex; flex-wrap: wrap; margin-left: 10px; vertical-align: middle">
                    @foreach($lists as $key => [$label, $count])
                        <li class="{{ $key === $list ? 'active' : '' }}" style="margin: 0 2px">
                            <a style="padding: 2px 8px" href="{{ route('topology.capacity', $query + ($key === 'not_in_use' ? [] : ['list' => $key])) }}#topo-capacity-ports">{{ $key === 'unused' ? 'Unused ' . $result['days'] . '+ days' : $label }}
                                <span class="badge">{{ number_format($count) }}</span></a>
                        </li>
                    @endforeach
                </ul>
                <a class="btn btn-xs btn-default pull-right" href="{{ route('topology.capacity.export', $query + ['what' => 'ports', 'list' => $list]) }}"><i class="fa fa-download"></i> CSV</a>
            </div>
            <div class="table-responsive">
            <table class="table table-condensed table-hover topo-stp-table" id="topo-capacity-port-table">
                <thead><tr>
                    <th>Switch</th><th>Port</th><th>Description</th><th>VLAN</th><th>Speed</th><th>State</th><th>Down since</th><th>Days down</th><th>PoE</th><th>Note</th>
                </tr></thead>
                <tbody>
                @forelse(array_slice($shown, 0, 3000) as $port)
                    @php([$stateLabel, $stateClass] = $stateLabels[$port['state']] ?? [$port['state'], 'default'])
                    <tr data-port="{{ $port['port_id'] }}" data-state="{{ $port['state'] }}">
                        <td><a href="{{ url('device/' . $port['device_id']) }}">{{ $port['device'] }}</a></td>
                        <td>@if($port['url'])<a href="{{ $port['url'] }}">{{ $port['port'] }}</a>@else{{ $port['port'] }}@endif</td>
                        <td class="topo-wrap">{{ $port['alias'] }}</td>
                        <td>{{ $port['vlan'] ?? '-' }}</td>
                        <td>{{ $speed($port['speed']) }}</td>
                        <td><span class="label label-{{ $stateClass }}">{{ $stateLabel }}</span>
                            @if($port['unused'] === true)<span class="label label-primary" title="down for {{ $dayText }} or more">unused</span>@endif</td>
                        <td>@if($port['down_since'] !== null){{ $when($port['down_since']) }}@if($port['age_kind'] === Capacity::AT_LEAST)<small class="text-muted"> or earlier</small>@endif @else - @endif</td>
                        <td title="{{ match ($port['age_kind']) { Capacity::EXACT => 'from the port\'s last change (ifLastChange) and the uptime', Capacity::SAMPLED => 'the last time the plugin\'s record or the eventlog saw the port up', Capacity::AT_LEAST => 'at least: down since the switch started, the record began or the eventlog begins', default => '' } }}">
                            {{ $port['age_days'] === null ? '-' : ($port['age_kind'] === Capacity::AT_LEAST ? '≥ ' : '') . number_format($port['age_days']) }}</td>
                        <td>{{ $port['poe'] === null ? '-' : ($port['poe'] ? 'yes' : 'no') }}</td>
                        <td class="topo-wrap">
                            @if($port['err'])
                                <span class="text-danger">since {{ $when($port['err']['time']) }}:</span> <small>{{ $port['err']['message'] }}</small>
                            @elseif($port['neighbor'] !== '')
                                {{ $port['neighbor'] }}
                            @elseif($port['state'] === Capacity::TRUNK)
                                <span class="text-muted">a trunk or routed port without an end device on it (a spare uplink)</span>
                            @elseif($port['state'] === Capacity::NOT_PRESENT)
                                <span class="text-muted">no hardware (an empty transceiver slot, a missing stack member)</span>
                            @elseif($port['state'] === Capacity::EXCLUDED)
                                <span class="text-muted">left out by the settings (Ports not counted)</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10">No port in this list.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
            @if(count($shown) > 3000)
                <div class="panel-footer text-muted">The first 3,000 of {{ number_format(count($shown)) }} ports are shown; the CSV and the JSON have all of them.</div>
            @endif
        </div>

        <details class="topo-capacity-help" id="topo-capacity-help" style="margin-bottom: 20px">
            <summary class="text-muted">How ports are counted</summary>
            <p class="text-muted" style="margin-top: 6px">
                <strong>Access ports</strong> are the physical Ethernet ports of the switches LibreNMS polls (ignored ports too), except uplinks (an LLDP/CDP neighbor
                that is a switch, router or firewall, or a port-channel with such a member), trunks without an access point, phone or server on them (trunking now,
                or without an access VLAN on a switch whose other ports have one: Cisco's spare uplinks and routed ports), ports without hardware (notPresent) and
                those the settings leave out.
                A port is <strong>in use</strong> while it is up; one that is not is <strong>err-disabled</strong> when the switch reported shutting it down (syslog or an
                SNMP trap) and it was not up since, <strong>shut down</strong> when its admin status is down, else <strong>free</strong>. Free and shut down ports are
                <strong>available</strong>, and <strong>unused</strong> once down for {{ $dayText }}.
            </p>
            <p class="text-muted" id="topo-capacity-sources">
                How long a port has been down comes from its last change (ifLastChange and the switch's uptime), the eventlog's port changes
                @if($sources['eventlog_since'] !== null)(since {{ date('Y-m-d', $sources['eventlog_since']) }})@endif
                and the plugin's record of when each port was last up,
                @if($sources['record'])
                    kept with every topology snapshot{{ $sources['record_since'] !== null ? ' since ' . date('Y-m-d', $sources['record_since']) : '' }}{{ $sources['record_run'] !== null ? ', last ' . date('Y-m-d H:i', $sources['record_run']) : '' }}.
                @else
                    which is not kept (the topology history is off or its tables do not exist).
                @endif
                A switch that restarted starts its ports' last change over: a port down since then counts as unused only when the record or the eventlog
                reaches back far enough, else its down time is unknown. Err-disable messages since {{ $when($sources['errors_since']) }} are read with every page,
                older ones from the record.
            </p>
        </details>
    @endif
</div>
@endsection

@push('scripts')
<script>
    (function ($) {
        if (!$ || !$.fn || !$.fn.select2) {
            return;
        }
        $('#topo-capacity-device').select2({
            theme: 'bootstrap',
            width: '240px',
            allowClear: true,
            placeholder: 'All switches',
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
