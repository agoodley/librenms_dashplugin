{{-- Topology changes page (HistoryController::changes), rendered from HistoryService::changes(). --}}
@extends('layouts.librenmsv1')

@section('title', 'Topology Changes')

@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $deviceUrl = fn ($id) => url('device/' . $id);
    $names = $result['device_names'] ?? [];
    $ago = fn ($date) => $date ? \Agoodley\LibrenmsTopology\Stp\StpAnalyzer::duration(max(0, time() - strtotime($date))) . ' ago' : null;
    $windows = \Agoodley\LibrenmsTopology\History\Window::WINDOWS;
    $types = [
        'device_added' => 'new device', 'cable_moved' => 'cable moved', 'link_removed' => 'link gone', 'link_back' => 'link back',
        'link_added' => 'new link', 'unmanaged_new' => 'new switch', 'neighbor_new' => 'new neighbor', 'lag_member_removed' => 'member left', 'lag_member_added' => 'member joined',
        'root_changed' => 'root moved', 'root_new' => 'new instance', 'root_gone' => 'instance gone', 'blocking_started' => 'blocking',
        'blocking_stopped' => 'forwarding', 'ospf_lost' => 'adjacency lost', 'ospf_up' => 'adjacency up', 'bgp_lost' => 'session down',
        'bgp_up' => 'session up',
    ];
    $kinds = ['link' => 'link', 'lag' => 'port-channel member', 'stp_root' => 'root bridge', 'stp_blocked' => 'blocked port', 'ospf' => 'OSPF adjacency', 'bgp' => 'BGP session'];
    $kindsPlural = ['link' => 'links', 'lag' => 'port-channel members', 'stp_root' => 'root bridges', 'ospf' => 'OSPF adjacencies', 'bgp' => 'BGP sessions'];
    $scope = array_filter(['device' => $params['device'], 'group' => $params['group'], 'location' => $params['location']]);
    // the page's scope and window, kept by the baseline links and forms
    $keep = $scope + ($window === 'custom' ? array_filter(['from' => request()->query('from'), 'to' => request()->query('to')], 'is_string') : ['window' => $window]);
    $count = fn (array $types) => array_sum(array_intersect_key($result['counts'] ?? [], array_flip($types)));
    $number = fn (int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);
    // "6,000 root bridge facts, 2 link facts" for the facts per kind of snapshots a cut window does not list
    $factCounts = fn (array $byKind) => implode(', ', array_map(fn ($kind, $n) => number_format($n) . ' ' . ($kinds[$kind] ?? $kind) . ($n === 1 ? ' fact' : ' facts'),
        array_keys($byKind), $byKind));
@endphp

@section('content')
<div class="container-fluid topo-page">
    @include('topology::partials.nav', ['active' => 'changes'])

    <form class="topo-filterbar" method="get" action="{{ route('topology.changes') }}">
        <label>Device
            <select id="topo-changes-device" name="device" class="form-control">
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
        <label>Changes in the
            <select name="window" class="form-control">
                @foreach($windows as $key => [$label])
                    <option value="{{ $key }}" @selected($window === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label title="Instead of the window: changes between two dates">or from
            <input type="date" name="from" class="form-control" value="{{ $window === 'custom' ? $from->format('Y-m-d') : '' }}">
        </label>
        <label>to
            <input type="date" name="to" class="form-control" value="{{ $window === 'custom' ? $to->format('Y-m-d') : '' }}">
        </label>
        <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-history"></i> Show</button>
        <a class="btn btn-sm btn-default" href="{{ route('topology.api.changes', request()->query()) }}" target="_blank"><i class="fa fa-code"></i> JSON</a>
    </form>

    @if(session('topology_status'))
        <div class="alert alert-success"><i class="fa fa-check"></i> {{ session('topology_status') }}</div>
    @endif
    @if(session('topology_error'))
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ session('topology_error') }}</div>
    @endif
    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @if($result && ! $result['available'])
        <div class="alert alert-warning">
            <i class="fa fa-database"></i> The topology history tables do not exist yet. Run <code>./lnms migrate</code> in the LibreNMS
            directory (LibreNMS updates do this too), then <code>./lnms topology:snapshot</code> or wait for the scheduler.
        </div>
    @elseif($result)
        @php
            $snapshot = $result['runs']['snapshot'] ?? null;
            $checks = $result['runs']['checks'] ?? null;
            $baseline = $result['baseline'];
        @endphp
        @if(! $snapshot)
            <div class="alert alert-info">
                <i class="fa fa-info-circle"></i> No snapshot of the topology yet. The LibreNMS scheduler records one every
                <i>History interval</i> minutes (plugin settings); <code>./lnms topology:snapshot</code> records one now.
            </div>
        @endif

        <div class="topo-cards">
            <div class="topo-card topo-{{ count($result['events']) || ! empty($result['truncated']) ? 'warning' : 'ok' }}">
                <h4>Changes</h4>
                <div class="topo-card-value">{{ count($result['events']) }}{{ ! empty($result['truncated']) ? '+' : '' }}</div>
                <p>{{ $window === 'custom' ? 'from ' . $from->format('Y-m-d H:i') . ' to ' . $to->format('Y-m-d H:i') : 'in the ' . $windows[$window][0] }}</p>
            </div>
            <div class="topo-card topo-{{ $count(['cable_moved', 'link_removed', 'unmanaged_new', 'lag_member_removed']) ? 'warning' : 'info' }}">
                <h4>Links</h4>
                <div class="topo-card-value">{{ $count(['cable_moved', 'link_removed', 'link_back', 'link_added', 'unmanaged_new', 'neighbor_new', 'lag_member_removed', 'lag_member_added']) }}</div>
                <p>{{ $count(['cable_moved']) }} moved, {{ $count(['link_added', 'unmanaged_new', 'neighbor_new']) }} new, {{ $count(['link_removed']) }} gone</p>
            </div>
            <div class="topo-card topo-{{ $count(['root_changed']) ? 'warning' : 'info' }}">
                <h4>Spanning tree</h4>
                <div class="topo-card-value">{{ $count(['root_changed', 'root_new', 'root_gone', 'blocking_started', 'blocking_stopped']) }}</div>
                <p>{{ $number($count(['root_changed']), 'root bridge move', 'root bridge moves') }}, {{ $number($count(['blocking_started', 'blocking_stopped']), 'blocked port change', 'blocked port changes') }}</p>
            </div>
            <div class="topo-card topo-{{ $count(['ospf_lost', 'bgp_lost']) ? 'warning' : 'info' }}">
                <h4>Routing</h4>
                <div class="topo-card-value">{{ $count(['ospf_lost', 'ospf_up', 'bgp_lost', 'bgp_up']) }}</div>
                <p>OSPF adjacencies and BGP sessions: {{ $count(['ospf_lost', 'bgp_lost']) }} lost, {{ $count(['ospf_up', 'bgp_up']) }} up</p>
            </div>
            <div class="topo-card topo-{{ ! $baseline['saved'] ? 'info' : (($baseline['missing_total'] ?? 0) ? 'warning' : 'ok') }}">
                <h4>Baseline</h4>
                @if($baseline['saved'])
                    <div class="topo-card-value">{{ $baseline['missing_total'] ?? count($baseline['missing']) }}</div>
                    <p>missing, {{ $baseline['new_total'] }} new since it was saved {{ $ago($baseline['saved']['saved_at']) }}</p>
                @else
                    <div class="topo-card-value" style="font-size: 16px; line-height: 31px">not saved</div>
                    <p>save the topology as expected to see what goes missing</p>
                @endif
            </div>
            <div class="topo-card topo-info">
                <h4>Last snapshot</h4>
                <div class="topo-card-value" style="font-size: 16px; line-height: 31px">{{ $snapshot ? $ago($snapshot['started_at']) : 'none' }}</div>
                <p>
                    @if(isset($snapshot['stats']['facts'])){{ $number($snapshot['stats']['facts'], 'fact', 'facts') }} of {{ $number($snapshot['stats']['devices'] ?? 0, 'device', 'devices') }} @endif
                    @if($checks)<br>background checks {{ $ago($checks['started_at']) }} @endif
                </p>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <strong>Changes</strong>
                <span class="text-muted">(newest first, as seen by the snapshots: a change shows up at the first snapshot after it)</span>
            </div>
            <div class="table-responsive">
            <table class="table table-condensed table-hover topo-stp-table">
                <thead><tr><th>When</th><th>Change</th><th>What</th><th>Devices</th></tr></thead>
                <tbody>
                @php $dropped = $result['dropped'] ?? null; @endphp
                @foreach($result['events'] as $event)
                    <tr>
                        <td title="{{ date('Y-m-d H:i:s', $event['time']) }}">{{ date('M j H:i', $event['time']) }}</td>
                        <td><span class="topo-sev topo-sev-{{ $event['level'] }}">{{ $types[$event['type']] ?? $event['type'] }}</span></td>
                        <td class="topo-wrap">{{ $event['text'] }}</td>
                        <td class="topo-wrap">
                            @foreach(array_slice($event['devices'], 0, 6) as $deviceId)
                                <a href="{{ $deviceUrl($deviceId) }}">{{ $names[$deviceId] ?? '#' . $deviceId }}</a>@if(! $loop->last), @endif
                            @endforeach
                            @if(count($event['devices']) > 6) &hellip; @endif
                            @if($event['devices'])
                                <a href="{{ route('topology.l2', ['device' => $event['devices'][0]]) }}" title="Open on the L2 map"><i class="fa fa-sitemap"></i></a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                {{-- snapshots a cut window leaves out, counted --}}
                @foreach($dropped['snapshots'] ?? [] as $snapshot)
                    <tr class="text-muted">
                        <td title="{{ date('Y-m-d H:i:s', $snapshot['time']) }}">{{ date('M j H:i', $snapshot['time']) }}</td>
                        <td><span class="topo-sev topo-sev-info">not listed</span></td>
                        <td class="topo-wrap" colspan="2">{{ $factCounts($snapshot['kinds']) }} changed, not listed; filter by device, group or location to see them</td>
                    </tr>
                @endforeach
                @if(! empty($dropped['earlier']))
                    <tr class="text-muted">
                        <td>earlier</td>
                        <td><span class="topo-sev topo-sev-info">not listed</span></td>
                        <td class="topo-wrap" colspan="2">{{ $factCounts($dropped['earlier']['kinds']) }} changed in earlier snapshots of the window, not listed;
                            filter by device, group or location or choose a shorter window to see them</td>
                    </tr>
                @endif
                @if(! $result['events'] && empty($dropped['snapshots']))
                    <tr><td colspan="4"><span class="topo-sev topo-sev-ok">ok</span> No topology changes in this window.</td></tr>
                @endif
                </tbody>
            </table>
            </div>
            @if(! empty($result['truncated']))
                <div class="panel-footer text-warning"><i class="fa fa-exclamation-triangle"></i> Too many changes: those after
                    {{ $result['since'] }} are listed, that snapshot and the earlier ones are only counted. Choose a shorter window or
                    filter by device, group or location.</div>
            @endif
        </div>

        @if(! empty($result['preview']))
            @php $preview = $result['preview']; @endphp
            <div class="panel panel-primary">
                <div class="panel-heading"><strong>Save the baseline</strong></div>
                <div class="panel-body">
                    <p>
                        Saves the topology of the last snapshot{{ $preview['snapshot'] ? ' (' . $ago($preview['snapshot']) . ')' : '' }} as the expected topology of
                        {{ $preview['devices'] }} device{{ $preview['devices'] === 1 ? '' : 's' }}{{ $scope ? ' (the current filter)' : '' }}:
                        @foreach($preview['kinds'] as $kind => $facts){{ $facts }} {{ ($facts === 1 ? $kinds : $kindsPlural)[$kind] ?? $kind }}@if(! $loop->last), @endif @endforeach
                        ({{ $number($preview['facts'], 'fact', 'facts') }}).
                        @if($preview['replaces']) It replaces the saved baseline of these devices ({{ $number($preview['replaces'], 'fact', 'facts') }}). @endif
                    </p>
                    <form method="post" action="{{ route('topology.changes.baseline') }}" style="display: inline">
                        @csrf
                        <input type="hidden" name="action" value="save">
                        @foreach($keep as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                        <button type="submit" class="btn btn-sm btn-primary" @disabled(! $preview['facts'])><i class="fa fa-save"></i> Save the baseline</button>
                    </form>
                    <a class="btn btn-sm btn-default" href="{{ route('topology.changes', $keep) }}">Cancel</a>
                </div>
            </div>
        @endif

        <div class="panel panel-default">
            <div class="panel-heading">
                <strong>Baseline</strong>
                @if($baseline['saved'])
                    <span class="text-muted">(saved {{ $baseline['saved']['saved_at'] }}{{ $baseline['saved']['saved_by'] ? ' by ' . $baseline['saved']['saved_by'] : '' }},
                        {{ $number($baseline['saved']['facts'], 'fact', 'facts') }}, compared with the last snapshot)</span>
                @else
                    <span class="text-muted">(not saved for these devices)</span>
                @endif
                @if($admin)
                    <span class="pull-right">
                        <a class="btn btn-xs btn-primary" href="{{ route('topology.changes', $keep + ['baseline' => 'preview']) }}"><i class="fa fa-save"></i> {{ $baseline['saved'] ? 'Update' : 'Save' }} the baseline</a>
                        @if($baseline['saved'])
                            <form method="post" action="{{ route('topology.changes.baseline') }}" style="display: inline" onsubmit="return confirm('Forget the baseline of these devices?')">
                                @csrf
                                <input type="hidden" name="action" value="clear">
                                @foreach($keep as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                                <button type="submit" class="btn btn-xs btn-default"><i class="fa fa-trash"></i> Clear</button>
                            </form>
                        @endif
                    </span>
                @endif
            </div>
            @if($baseline['saved'])
                <table class="table table-condensed table-hover topo-stp-table">
                    <thead><tr><th>Missing</th><th>What</th><th>Missing since</th>@if($admin)<th></th>@endif</tr></thead>
                    <tbody>
                    @forelse($baseline['missing'] as $fact)
                        <tr>
                            <td>{{ $kinds[$fact['kind']] ?? $fact['kind'] }}</td>
                            <td class="topo-wrap">{{ $fact['text'] }}</td>
                            <td>{{ $fact['since'] ? $fact['since'] . ' (' . $ago($fact['since']) . ')' : 'unknown (before the kept history)' }}</td>
                            @if($admin)
                                <td>
                                    @if($fact['change'] !== null)
                                        <form method="post" action="{{ route('topology.changes.baseline') }}" style="display: inline"
                                              onsubmit='return confirm(@json('Accept on ' . number_format($fact['switches']) . ($fact['switches'] === 1 ? ' switch: ' : ' switches: ') . $fact['text'] . '? The baseline then expects it.'))'>
                                            @csrf
                                            <input type="hidden" name="action" value="accept_roots">
                                            @foreach($fact['change'] as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                                            @foreach($keep as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                                            <button type="submit" class="btn btn-xs btn-default" title="Expected now: the baseline takes it for these switches"><i class="fa fa-check"></i> Accept</button>
                                        </form>
                                    @else
                                        <form method="post" action="{{ route('topology.changes.baseline') }}" style="display: inline" onsubmit="return confirm('Gone on purpose? It is removed from the baseline and no longer reported as missing.')">
                                            @csrf
                                            <input type="hidden" name="action" value="accept">
                                            <input type="hidden" name="hash" value="{{ $fact['hash'] }}">
                                            @foreach($keep as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                                            <button type="submit" class="btn btn-xs btn-default" title="Gone on purpose: remove it from the baseline"><i class="fa fa-check"></i> Accept</button>
                                        </form>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $admin ? 4 : 3 }}"><span class="topo-sev topo-sev-ok">ok</span> Nothing of the baseline is missing.</td></tr>
                    @endforelse
                    @if(($baseline['missing_total'] ?? 0) > count($baseline['missing']))
                        <tr><td colspan="{{ $admin ? 4 : 3 }}" class="text-muted">and {{ $baseline['missing_total'] - count($baseline['missing']) }} more</td></tr>
                    @endif
                    </tbody>
                </table>
                @if($baseline['new'])
                    <div class="panel-body" style="padding-top: 0">
                        <details>
                            <summary>{{ $baseline['new_total'] }} new since the baseline{{ $baseline['new_total'] > count($baseline['new']) ? ' (the first ' . count($baseline['new']) . ')' : '' }}</summary>
                            <ul style="margin: 6px 0 0">
                                @foreach($baseline['new'] as $fact)
                                    <li>{{ $kinds[$fact['kind']] ?? $fact['kind'] }}: {{ $fact['text'] }}</li>
                                @endforeach
                            </ul>
                        </details>
                    </div>
                @endif
            @else
                <div class="panel-body text-muted">
                    The baseline is the topology you expect: links, port-channel members, root bridges, OSPF adjacencies and BGP sessions.
                    Once it is saved this page lists what is missing, and the redundancy page keeps reporting a missing uplink after LibreNMS
                    deleted its LLDP/CDP neighbor. @if(! $admin) An admin can save it. @endif
                </div>
            @endif
        </div>

        @if($checks || $result['findings'])
            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>Findings that came or went</strong>
                    <span class="text-muted">(background checks of the spanning tree, link, routing and redundancy pages)</span>
                </div>
                <table class="table table-condensed table-hover topo-stp-table">
                    <thead><tr><th>When</th><th></th><th>Severity</th><th>Finding</th><th>Devices</th></tr></thead>
                    <tbody>
                    @forelse($result['findings'] as $finding)
                        @php $resolved = $finding['status'] === 'resolved'; @endphp
                        <tr>
                            <td>{{ date('M j H:i', strtotime($resolved ? $finding['resolved_at'] : $finding['first_seen'])) }}</td>
                            <td @if($finding['status'] === 'changed') title="reported again with other ports or devices" @endif>{{ $finding['status'] }}</td>
                            <td><span class="topo-sev topo-sev-{{ $resolved ? 'ok' : $finding['severity'] }}">{{ $finding['severity'] }}</span></td>
                            <td class="topo-wrap"><a href="{{ route('topology.' . $finding['analysis'], $scope) }}">{{ $finding['title'] }}</a></td>
                            <td class="topo-wrap">
                                @foreach(array_slice($finding['devices'], 0, 6) as $deviceId)
                                    <a href="{{ $deviceUrl($deviceId) }}">{{ $finding['device_names'][$deviceId] ?? '#' . $deviceId }}</a>@if(! $loop->last), @endif
                                @endforeach
                                @if(count($finding['devices']) > 6) &hellip; @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No finding came or went in this window.</td></tr>
                    @endforelse
                    @if(! empty($result['findings_truncated']))
                        <tr><td colspan="5" class="text-warning">Only the newest findings are shown: choose a shorter window or filter.</td></tr>
                    @endif
                    </tbody>
                </table>
            </div>
        @endif

        <p class="text-muted" style="font-size: 12px">
            The history records LLDP/CDP links, port-channel members, the root bridge each switch sees, blocked ports, OSPF adjacencies
            and BGP sessions every <i>History interval</i> minutes. LibreNMS refreshes LLDP/CDP neighbors at discovery (every 6 hours
            by default), so a cable change can take that long to show up. Devices disabled in LibreNMS keep their last state.
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
        $('#topo-changes-device').select2({
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
