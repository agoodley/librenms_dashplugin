{{-- Path trace page (PathController::index), rendered from PathService::trace(). --}}
@extends('layouts.librenmsv1')

@section('title', 'Path Trace')

@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $speed = fn ($bps) => $bps ? \Agoodley\LibrenmsTopology\Path\PathService::speedText((int) $bps) : '-';
    $roles = [
        'router' => 'fa-random', 'firewall' => 'fa-shield-alt', 'l3switch' => 'fa-sitemap', 'switch' => 'fa-sitemap', 'wireless' => 'fa-wifi',
        'server' => 'fa-server', 'phone' => 'fa-phone', 'other' => 'fa-cube', 'unknown' => 'fa-question-circle',
    ];
    $deviceUrl = fn ($id) => url('device/' . $id);
    $names = $result['device_names'] ?? [];
    $ago = fn ($time) => $time ? \Agoodley\LibrenmsTopology\Stp\StpAnalyzer::duration(max(0, time() - (int) $time)) . ' ago' : null;
    // one port of a hop: name, state, speed, traffic, errors, spanning tree, the router interface on it
    $port = function (?array $port) use ($speed): string {
        if ($port === null) {
            return '<span class="text-muted">-</span>';
        }
        if (! isset($port['port_id'])) {
            $html = e($port['name'] ?? '') ?: '<span class="text-muted">-</span>';
            if (! empty($port['interface'])) {
                $html = (! empty($port['interface']['url']) ? '<a href="' . e($port['interface']['url']) . '">' . e($port['interface']['name']) . '</a>' : e($port['interface']['name']))
                    . ($port['interface']['address'] ? ' <span class="topo-mono">' . e($port['interface']['address']) . '</span>' : '');
            }

            return $html;
        }
        $html = $port['url'] ? '<a href="' . e($port['url']) . '"><strong>' . e($port['name']) . '</strong></a>' : '<strong>' . e($port['name']) . '</strong>';
        if ($port['address']) {
            $html .= ' <span class="topo-mono">' . e($port['address']) . '</span>';
        }
        if ($port['status'] && $port['status'] !== 'up') {
            $html .= ' <span class="topo-status-' . e($port['status']) . '">' . e($port['status']) . '</span>';
        }
        $facts = [$speed($port['speed'])];
        if ($port['duplex'] === 'half') {
            $facts[] = '<span class="topo-text-warning">half duplex</span>';
        }
        if ($port['in_util'] !== null || $port['out_util'] !== null) {
            $facts[] = 'in ' . ($port['in_util'] ?? '-') . '% / out ' . ($port['out_util'] ?? '-') . '%';
        }
        if ($port['in_errors'] + $port['out_errors'] > 0) {
            $facts[] = '<span class="topo-text-warning">' . number_format($port['in_errors'] + $port['out_errors']) . ' errors</span>';
        }
        if ($port['stp']) {
            $facts[] = 'STP ' . e($port['stp']['state']) . ($port['stp']['role'] !== 'unknown' ? ' (' . e($port['stp']['role']) . ')' : '');
        }
        $html .= '<br><small class="text-muted">' . implode(' &middot; ', $facts) . '</small>';
        if ($port['members']) {
            $up = count(array_filter($port['members'], fn ($m) => $m['status'] === 'up'));
            $html .= '<br><small class="text-muted">members: ' . implode(', ', array_map(fn ($m) => ($m['url'] ? '<a href="' . e($m['url']) . '">' . e($m['name']) . '</a>' : e($m['name']))
                . ($m['status'] !== 'up' ? ' <span class="topo-status-' . e((string) $m['status']) . '">' . e((string) $m['status']) . '</span>' : ''), $port['members'])) . '</small>';
        }
        if ($port['interface']) {
            $html .= '<br><small>via ' . ($port['interface']['url'] ? '<a href="' . e($port['interface']['url']) . '">' . e($port['interface']['name']) . '</a>' : e($port['interface']['name']))
                . ($port['interface']['address'] ? ' <span class="topo-mono">' . e($port['interface']['address']) . '</span>' : '') . '</small>';
        }
        if ($port['alias'] !== '') {
            $html .= '<br><small class="text-muted">"' . e($port['alias']) . '"</small>';
        }

        return $html;
    };
    $swap = array_filter(['a' => $query['b'], 'b' => $query['a'], 'a_device' => $query['b_device'], 'a_port' => $query['b_port'],
        'b_device' => $query['a_device'], 'b_port' => $query['a_port'], 'vlan' => $query['vlan']], fn ($v) => $v !== null && $v !== '');
    $ready = ($query['a'] !== '' || $query['a_device']) && ($query['b'] !== '' || $query['b_device']);
@endphp

@section('content')
<div class="container-fluid topo-page">
    @include('topology::partials.nav', ['active' => 'path'])

    <form class="topo-filterbar" method="get" action="{{ route('topology.path') }}">
        <label>From (A)
            <input type="text" name="a" class="form-control" style="width: 260px" value="{{ $query['a'] !== '' ? $query['a'] : ($result['a']['label'] ?? '') }}"
                   placeholder="IP, MAC, hostname or device" @if($query['a_device']) readonly @endif autofocus>
        </label>
        @if($ready)
            <a class="btn btn-sm btn-default" href="{{ route('topology.path', $swap) }}" title="Trace the way back (B to A)"><i class="fa fa-exchange"></i></a>
        @endif
        <label>To (B)
            <input type="text" name="b" class="form-control" style="width: 260px" value="{{ $query['b'] !== '' ? $query['b'] : ($result['b']['label'] ?? '') }}"
                   placeholder="IP, MAC, hostname or device" @if($query['b_device']) readonly @endif>
        </label>
        <label>VLAN
            <input type="number" name="vlan" class="form-control" style="width: 90px" min="1" max="4094" value="{{ $query['vlan'] }}" placeholder="auto">
        </label>
        @foreach(['a_device', 'a_port', 'b_device', 'b_port'] as $key)
            @if($query[$key])<input type="hidden" name="{{ $key }}" value="{{ $query[$key] }}">@endif
        @endforeach
        <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-route"></i> Trace</button>
        @if($query['a_device'] || $query['b_device'])
            <a class="btn btn-sm btn-default" href="{{ route('topology.path') }}">New trace</a>
        @endif
        @if($ready)
            <a class="btn btn-sm btn-default" href="{{ route('topology.api.path', array_filter($query, fn ($v) => $v !== null && $v !== '')) }}" target="_blank"><i class="fa fa-code"></i> JSON</a>
        @endif
    </form>

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @if($result)
        @if($result['message'])
            <div class="alert alert-info"><i class="fa fa-info-circle"></i> {{ $result['message'] }}</div>
        @endif

        @if($result['a'] && $result['b'])
            <div class="row topo-path-ends">
                @foreach(['a' => 'From (A)', 'b' => 'To (B)'] as $side => $title)
                    @php $end = $result[$side]; @endphp
                    <div class="col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading"><strong>{{ $title }}</strong> <span class="topo-mono">{{ $end['label'] }}</span>
                                @if($end['resolved'])
                                    <span class="topo-sev topo-sev-ok">{{ $end['kind'] === 'host' ? ($end['status'] === 'behind' ? 'behind a switch' : 'located') : 'device' }}</span>
                                @else
                                    <span class="topo-sev topo-sev-warning">not found</span>
                                @endif
                            </div>
                            <div class="panel-body">
                                @if($end['resolved'])
                                    <table class="table table-condensed topo-kv">
                                        @if($end['kind'] === 'host')
                                            <tr><th>MAC</th><td class="topo-mono">{{ $end['readable'] }} @if($end['vendor'])<span class="text-muted">{{ $end['vendor'] }}</span>@endif</td></tr>
                                            <tr><th>{{ $end['status'] === 'behind' ? 'Behind' : 'Plugged into' }}</th>
                                                <td><a href="{{ $deviceUrl($end['device_id']) }}">{{ $end['device'] }}</a>
                                                    @if($end['port'])@if($end['port_url'])<a href="{{ $end['port_url'] }}">{{ $end['port'] }}</a>@else{{ $end['port'] }}@endif @endif
                                                    @if($end['host_url'])<a class="text-muted" href="{{ $end['host_url'] }}" title="Host locator"><i class="fa fa-map-marker"></i></a>@endif</td></tr>
                                        @else
                                            <tr><th>Device</th><td><a href="{{ $deviceUrl($end['device_id']) }}">{{ $end['device'] }}</a>
                                                @if($end['port'])@if($end['port_url'])<a href="{{ $end['port_url'] }}">{{ $end['port'] }}</a>@else{{ $end['port'] }}@endif @endif</td></tr>
                                        @endif
                                        <tr><th>Addresses</th><td class="topo-mono">{{ $end['ips'] ? implode(', ', array_map([\Agoodley\LibrenmsTopology\Path\PathService::class, 'display'], array_slice($end['ips'], 0, 4))) : '-' }}</td></tr>
                                        @if($end['vlan'])<tr><th>VLAN</th><td>{{ $end['vlan'] }}</td></tr>@endif
                                    </table>
                                    @foreach($end['notes'] as $note)
                                        <div class="topo-host-note topo-host-note-{{ $note['level'] }}"><i class="fa {{ $note['level'] === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle' }}"></i> {{ $note['text'] }}</div>
                                    @endforeach
                                    @if($end['others'] > 0)
                                        <p class="text-muted"><small>{{ $end['others'] }} more {{ $end['others'] === 1 ? 'answer' : 'answers' }} for {{ $end['input'] }}: the path uses the first, see the host locator.</small></p>
                                    @endif
                                @else
                                    <p>{{ $end['message'] }}</p>
                                    @if($end['host_url'])<a class="btn btn-xs btn-default" href="{{ $end['host_url'] }}"><i class="fa fa-map-marker"></i> Host locator</a>@endif
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if($result['hops'])
            @php $summary = $result['summary']; @endphp
            <div class="topo-cards">
                <div class="topo-card topo-{{ $summary['complete'] ? $summary['status'] : 'critical' }}">
                    <h4>Path</h4>
                    <div class="topo-card-value"><span class="topo-sev topo-sev-{{ $summary['complete'] ? $summary['status'] : 'critical' }}" style="font-size: 16px">{{ $summary['complete'] ? $summary['status'] : 'incomplete' }}</span></div>
                    <p>{{ $summary['critical'] }} critical, {{ $summary['warning'] }} warnings, {{ $summary['info'] }} info</p>
                </div>
                <div class="topo-card topo-info">
                    <h4>{{ $result['mode'] === 'l3' ? 'Routed' : 'Layer 2' }}</h4>
                    <div class="topo-card-value">{{ $summary['devices'] }}</div>
                    <p>{{ $summary['devices'] === 1 ? 'device' : 'devices' }} on the path{{ $result['mode'] === 'l3' ? ', ' . $summary['routers'] . ' routing' : ($result['vlan'] ? ' in VLAN ' . $result['vlan'] : '') }}
                        @if($summary['hidden']), {{ $summary['hidden'] }} you may not see @endif
                        @if($summary['estimated'])<br>{{ $summary['estimated'] }} next {{ $summary['estimated'] === 1 ? 'hop' : 'hops' }} estimated @endif</p>
                </div>
                <div class="topo-card topo-info">
                    <h4>Slowest link</h4>
                    <div class="topo-card-value">{{ $summary['slowest'] ? $speed($summary['slowest']['speed']) : '-' }}</div>
                    <p>{{ $summary['slowest']['where'] ?? 'no link speeds known' }}</p>
                </div>
                <div class="topo-card topo-{{ ($summary['busiest']['util'] ?? 0) >= 80 ? 'warning' : 'info' }}">
                    <h4>Busiest port</h4>
                    <div class="topo-card-value">{{ $summary['busiest'] ? $summary['busiest']['util'] . '%' : '-' }}</div>
                    <p>{{ $summary['busiest']['where'] ?? 'no traffic data' }}</p>
                </div>
            </div>

            @foreach($result['notes'] as $note)
                <div class="topo-host-note topo-host-note-{{ $note['level'] }}"><i class="fa {{ $note['level'] === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle' }}"></i> {{ $note['text'] }}</div>
            @endforeach

            @if($result['findings'])
                <div class="panel panel-default">
                    <div class="panel-heading"><strong>Problems on the path</strong> <span class="text-muted">({{ count($result['findings']) }})</span></div>
                    <table class="table table-condensed table-hover topo-stp-table">
                        <thead><tr><th style="width: 90px">Severity</th><th>Finding</th><th style="width: 22%">Devices</th></tr></thead>
                        <tbody>
                        @foreach($result['findings'] as $finding)
                            <tr>
                                <td><span class="topo-sev topo-sev-{{ $finding['severity'] }}">{{ $finding['severity'] }}</span></td>
                                <td class="topo-wrap">
                                    <strong>{{ ucfirst($finding['title']) }}</strong>
                                    <div class="topo-finding-detail">{{ $finding['detail'] }}</div>
                                    @if($finding['recommendation'])
                                        <div class="topo-finding-rec"><i class="fa fa-lightbulb-o"></i> {{ $finding['recommendation'] }}</div>
                                    @endif
                                </td>
                                <td class="topo-wrap">
                                    @foreach(array_slice($finding['devices'], 0, 12) as $deviceId)
                                        @if(isset($names[$deviceId]))<a href="{{ $deviceUrl($deviceId) }}">{{ $names[$deviceId] }}</a>@if(! $loop->last), @endif @endif
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>Hops</strong>
                    <span class="text-muted">{{ $result['complete'] ? 'from A to B' : 'as far as they could be followed' }}</span>
                    @if($result['map_url'])
                        <span class="topo-actions" style="display: inline-flex; margin: 0 0 0 8px">
                            <a class="btn btn-xs btn-default" href="{{ $result['map_url'] }}"><i class="fa fa-sitemap"></i> Show on the L2 map</a>
                        </span>
                    @endif
                </div>
                <table class="table table-condensed table-hover topo-stp-table topo-path">
                    <thead><tr><th style="width: 30px">#</th><th>Device</th><th>In</th><th>Out</th><th>Link to the next hop</th><th>Forwarding</th></tr></thead>
                    <tbody>
                    @foreach($result['hops'] as $index => $hop)
                        <tr class="topo-path-{{ $hop['type'] }}" data-hop="{{ $hop['type'] }}">
                            <td class="text-muted">{{ $index + 1 }}</td>
                            <td class="topo-wrap">
                                @switch($hop['type'])
                                    @case('host')
                                        <i class="fa fa-desktop fa-fw"></i> <strong class="topo-mono">{{ $hop['name'] }}</strong>
                                        @if(! empty($hop['ips']))<br><small class="topo-mono">{{ implode(', ', array_slice($hop['ips'], 0, 3)) }}</small>@endif
                                        @if(! empty($hop['vendor']))<br><small class="text-muted">{{ $hop['vendor'] }}</small>@endif
                                        @break
                                    @case('device')
                                        <i class="fa {{ $roles[$hop['role']] ?? 'fa-question-circle' }} fa-fw"></i>
                                        <a href="{{ $hop['url'] }}"><strong>{{ $hop['name'] }}</strong></a>
                                        @break
                                    @case('unmanaged')
                                        <i class="fa {{ $roles[$hop['role']] ?? 'fa-question-circle' }} fa-fw"></i> {{ $hop['name'] }} <small class="text-muted">(not monitored)</small>
                                        @break
                                    @case('hidden')
                                        <i class="fa fa-eye-slash fa-fw"></i> <span class="text-muted">a device you may not see</span>
                                        @break
                                    @default
                                        <i class="fa fa-ellipsis-h fa-fw"></i> <em>{{ $hop['name'] }}</em>
                                @endswitch
                            </td>
                            <td class="topo-wrap">{!! in_array($hop['type'], ['device', 'unmanaged'], true) ? $port($hop['in']) : '' !!}</td>
                            <td class="topo-wrap">{!! in_array($hop['type'], ['device', 'unmanaged'], true) ? $port($hop['out']) : '' !!}</td>
                            <td class="topo-wrap">
                                @if($hop['link'])
                                    <span class="topo-status-{{ $hop['link']['status'] }}">{{ $speed($hop['link']['speed']) }}</span>
                                    @if($hop['link']['lag'])<br><small class="text-muted">port-channel, {{ $hop['link']['up'] }} of {{ $hop['link']['members'] }} members up</small>@endif
                                @endif
                                @foreach($hop['notes'] as $note)<small class="text-muted">{{ $note['text'] }}</small>@endforeach
                            </td>
                            <td class="topo-wrap">
                                @if($hop['type'] === 'device' && $hop['layer'] === 'l3')
                                    @if($hop['route'])
                                        <span class="topo-mono">{{ $hop['route']['prefix'] ?? '' }}</span>
                                        @if($hop['route']['next_hop']) via <span class="topo-mono">{{ $hop['route']['next_hop'] }}</span>@endif
                                        @if($hop['route']['protocol'])<small class="text-muted">({{ $hop['route']['protocol'] }})</small>@endif
                                        @if(count($hop['route']['next_hops']) > 1)<br><small>one of {{ count($hop['route']['next_hops']) }} equal cost next hops: <span class="topo-mono">{{ implode(', ', $hop['route']['next_hops']) }}</span></small>@endif
                                    @elseif($hop['connected'])
                                        <small>delivers to its connected subnet <span class="topo-mono">{{ $hop['connected'] }}</span></small>
                                    @elseif(! $hop['estimated'])
                                        <small class="text-muted">routes</small>
                                    @endif
                                    @if($hop['vrf'])<br><small>VRF {{ $hop['vrf'] }}</small>@endif
                                    @if($hop['estimated'])<br><small class="topo-text-warning"><i class="fa fa-question-circle"></i> estimated: {{ $hop['estimated'] }}</small>@endif
                                @elseif($hop['type'] === 'device' && $hop['vlan'])
                                    <small>bridged in VLAN {{ $hop['vlan'] }}</small>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-muted" style="font-size: 12px">
                The path is worked out from what LibreNMS last read: LLDP/CDP links, spanning tree states, forwarding and ARP tables
                (every 6 hours by default), interface counters (every poll) and route tables (the <code>route</code> discovery module, off by default).
                It is not a live traceroute: ECMP, policy routing and changes since the last discovery can make the real path differ.
            </p>
        @endif
    @elseif(! $error)
        <p class="text-muted">
            Enter both ends of a path: an IP address (<code>10.10.10.55</code>), a MAC address, a hostname or the name of a device in LibreNMS.
            Within a VLAN the path follows the links whose ports forward in its spanning tree; between subnets it goes from A's gateway
            router by router through their route tables, with the switches between them. Every hop shows its ports with speed,
            utilization, errors, duplex and spanning tree state, and the problems on the path are listed.
        </p>
    @endif
</div>
@endsection
