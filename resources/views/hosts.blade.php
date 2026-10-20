{{-- Host locator page (HostController::index), rendered from HostService::search(). --}}
@extends('layouts.librenmsv1')

@section('title', 'Host Locator')

@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $ago = fn ($time) => $time ? \Agoodley\LibrenmsTopology\Stp\StpAnalyzer::duration(max(0, time() - (int) $time)) . ' ago' : 'unknown';
    $date = fn ($time) => $time ? date('Y-m-d H:i', (int) $time) : '-';
    $vlanText = fn (array $entry) => implode(', ', array_map(fn ($vlan, $name) => $vlan . ($name !== null && $name !== '' && $name !== sprintf('VLAN%04d', $vlan) ? ' (' . $name . ')' : ''), $entry['vlans'], $entry['vlan_names'])) ?: '-';
    $statusBadges = [
        'located' => ['ok', 'located'],
        'behind' => ['warning', 'behind a switch'],
        'owner' => ['ok', 'address of a monitored device'],
        'gateway' => ['ok', 'virtual router address'],
        'uplinks' => ['warning', 'only on uplinks'],
        'none' => ['info', 'not in a forwarding table'],
    ];
@endphp

@section('content')
<div class="container-fluid topo-page">
    @include('topology::partials.nav', ['active' => 'hosts'])

    <form class="topo-filterbar" method="get" action="{{ route('topology.hosts') }}">
        <label>Host
            <input type="text" name="q" class="form-control" style="width: 340px" value="{{ $q }}" placeholder="IP address, MAC address or hostname" autofocus>
        </label>
        <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-search"></i> Locate</button>
        @if($q !== '')
            <a class="btn btn-sm btn-default" href="{{ route('topology.api.hosts', ['q' => $q]) }}" target="_blank"><i class="fa fa-code"></i> JSON</a>
        @endif
    </form>

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @if($result)
        @if($result['message'])
            <div class="alert alert-info"><i class="fa fa-info-circle"></i> {{ $result['message'] }}</div>
        @endif
        @if($result['devices'])
            <p>
                Monitored device{{ count($result['devices']) === 1 ? '' : 's' }}:
                @foreach($result['devices'] as $device)
                    <a href="{{ url('device/' . $device['device_id']) }}">{{ $device['name'] }}</a>@if($device['ips']) <span class="text-muted">({{ implode(', ', $device['ips']) }})</span>@endif
                    <a class="text-muted" href="{{ route('topology.l2', ['device' => $device['device_id'], 'depth' => 1]) }}" title="L2 map around {{ $device['name'] }}"><i class="fa fa-sitemap"></i></a>@if(! $loop->last), @endif
                @endforeach
            </p>
        @elseif($result['resolved'])
            <p class="text-muted">{{ $result['query']['value'] }} resolves to {{ implode(', ', $result['resolved']) }}.</p>
        @endif

        @foreach($result['hosts'] as $host)
            @php
                [$badgeLevel, $badgeText] = $statusBadges[$host['status']] ?? ['info', $host['status']];
                if ($host['status'] === 'behind' && $host['device']) {
                    $badgeText = 'behind ' . $host['device']['name'];
                }
                $edge = $host['edge'];
                $answer = in_array($host['status'], ['owner', 'gateway'], true) ? $host['device'] : null;
                // the users of sessions that count: not historical, on a switch that LibreNMS still polls for them
                $users = array_values(array_unique(array_filter(array_column(array_filter($host['nac'], fn ($n) => ! empty($n['current'])), 'username'))));
            @endphp
            <div class="panel panel-default topo-host" data-mac="{{ $host['mac'] }}" data-status="{{ $host['status'] }}">
                <div class="panel-heading">
                    <span class="topo-sev topo-sev-{{ $badgeLevel }}">{{ $badgeText }}</span>
                    @if($host['stale'])<span class="topo-sev topo-sev-warning">not seen recently</span>@endif
                    <strong class="topo-mono" style="font-size: 13px; margin-left: 6px">{{ $host['readable'] }}</strong>
                    @if($host['gateway'])<span class="text-muted">{{ $host['gateway']['label'] }}</span>@elseif($host['vendor'])<span class="text-muted">{{ $host['vendor'] }}</span>@endif
                    @if($host['vlan'] !== null)<span class="topo-host-ip">VLAN {{ $host['vlan'] }}</span>@endif
                    @foreach(array_slice(array_values(array_unique(array_column($host['addresses'], 'display'))), 0, 6) as $ip)
                        <span class="topo-host-ip">{{ $ip }}</span>
                    @endforeach
                    @if($users)<span title="802.1X / MAB user"><i class="fa fa-user"></i> {{ implode(', ', $users) }}</span>@endif
                </div>
                <div class="panel-body">
                    @if($answer)
                        <div class="topo-host-edge topo-host-answer">
                            <i class="fa fa-server fa-fw"></i>
                            @if($answer['url'])<a href="{{ $answer['url'] }}"><strong>{{ $answer['name'] }}</strong></a>@else<strong>{{ $answer['name'] }}</strong>@endif
                            <span class="text-muted">{{ $host['status'] === 'gateway' ? 'answers this virtual router address now' : 'has this MAC address on its own interface' }}</span>
                            @if($host['map_url'])
                                <span class="topo-actions" style="display: inline-flex; margin: 0 0 0 8px">
                                    <a class="btn btn-xs btn-default" href="{{ $host['map_url'] }}"><i class="fa fa-sitemap"></i> Show on the L2 map</a>
                                </span>
                            @endif
                        </div>
                    @endif
                    @if($edge)
                        <div class="topo-host-edge">
                            <i class="fa fa-plug fa-fw"></i>
                            @if($answer || $host['status'] === 'behind')<span class="text-muted">learned on</span>@endif
                            <a href="{{ $edge['device_url'] }}"><strong>{{ $edge['device'] }}</strong></a>
                            @if($edge['port_url'])
                                <a href="{{ $edge['port_url'] }}"><strong>{{ $edge['port'] }}</strong></a>
                            @else
                                <strong>{{ $edge['port'] }}</strong>
                            @endif
                            @if($edge['description'])<span class="text-muted">"{{ $edge['description'] }}"</span>@endif
                            @if($edge['port_status'] && $edge['port_status'] !== 'up')<span class="topo-status-{{ $edge['port_status'] }}">{{ $edge['port_status'] }}</span>@endif
                            @if($edge['neighbor'])
                                <span class="topo-host-behind">
                                    <i class="fa fa-long-arrow-right"></i>
                                    @if($edge['neighbor']['url'])<a href="{{ $edge['neighbor']['url'] }}">{{ $edge['neighbor']['name'] }}</a>@else{{ $edge['neighbor']['name'] }}@endif
                                    @if($edge['neighbor']['port'])<span class="text-muted">{{ $edge['neighbor']['port'] }}</span>@endif
                                </span>
                            @endif
                            @if($host['map_url'] && ! $answer)
                                <span class="topo-actions" style="display: inline-flex; margin: 0 0 0 8px">
                                    <a class="btn btn-xs btn-default" href="{{ $host['map_url'] }}"><i class="fa fa-sitemap"></i> Show on the L2 map</a>
                                </span>
                            @endif
                        </div>
                        <table class="table table-condensed topo-kv topo-host-facts">
                            <tr><th>VLAN</th><td>{{ $vlanText($edge) }}</td><th>MACs on the port</th><td>{{ $edge['macs'] }}</td></tr>
                            <tr><th>Last seen</th><td>{{ $ago($edge['last_seen']) }} <span class="text-muted">({{ $date($edge['last_seen']) }})</span></td><th>First seen</th><td>{{ $date($edge['first_seen']) }}</td></tr>
                        </table>
                    @elseif($host['status'] === 'none')
                        <p>No switch you can see has this MAC address in its forwarding table.</p>
                    @endif

                    @foreach($host['notes'] as $note)
                        <div class="topo-host-note topo-host-note-{{ $note['level'] }}"><i class="fa {{ $note['level'] === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle' }}"></i> {{ $note['text'] }}</div>
                    @endforeach

                    @if($host['addresses'])
                        <h5>Addresses</h5>
                        <table class="table table-condensed table-hover topo-stp-table">
                            <thead><tr><th>Address</th><th>Learned on</th><th>VRF / context</th></tr></thead>
                            <tbody>
                            @foreach($host['addresses'] as $address)
                                <tr>
                                    <td class="topo-mono">{{ $address['display'] }}</td>
                                    <td><a href="{{ url('device/' . $address['device_id']) }}">{{ $address['device'] }}</a> <span class="text-muted">{{ $address['family'] === 6 ? 'IPv6 neighbors' : 'ARP' }}</span></td>
                                    <td>{{ $address['context'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif

                    @if($host['nac'])
                        <h5>802.1X / MAB sessions</h5>
                        <table class="table table-condensed table-hover topo-stp-table">
                            <thead><tr><th>User</th><th>Status</th><th>Method</th><th>Switch port</th><th>VLAN</th><th>Updated</th></tr></thead>
                            <tbody>
                            @foreach($host['nac'] as $session)
                                <tr class="{{ $session['historical'] || ! empty($session['inactive']) ? 'text-muted' : '' }}">
                                    <td>{{ $session['username'] ?: '-' }}@if($session['domain'] && $session['domain'] !== 'other') <small class="text-muted">{{ $session['domain'] }}</small>@endif</td>
                                    <td>{{ $session['status'] ?: '-' }}@if($session['historical']) <small>(earlier session)</small>@elseif(! empty($session['inactive'])) <small>(not current: {{ $session['inactive'] }})</small>@endif</td>
                                    <td>{{ $session['method'] ?: '-' }}</td>
                                    <td><a href="{{ url('device/' . $session['device_id']) }}">{{ $session['device'] }}</a>
                                        @if($session['port_url'])<a href="{{ $session['port_url'] }}">{{ $session['port'] }}</a>@else{{ $session['port'] }}@endif</td>
                                    <td>{{ $session['vlan'] ?? '-' }}</td>
                                    <td>{{ $date($session['last_seen']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif

                    @if($host['seen'])
                        @if($answer)
                            <h5>Learned on <small class="text-muted">(each on the way to {{ $answer['name'] }})</small></h5>
                        @else
                            <h5>Also learned on <small class="text-muted">(and why the host is not there)</small></h5>
                        @endif
                        <table class="table table-condensed table-hover topo-stp-table">
                            <thead><tr><th>Switch</th><th>Port</th><th>VLAN</th><th>Last seen</th><th>{{ $answer ? 'Leads to' : 'Why not here' }}</th></tr></thead>
                            <tbody>
                            @foreach($host['seen'] as $entry)
                                <tr class="{{ $entry['reason'] === 'stale' ? 'text-muted' : '' }}">
                                    <td><a href="{{ $entry['device_url'] }}">{{ $entry['device'] }}</a></td>
                                    <td>@if($entry['port_url'])<a href="{{ $entry['port_url'] }}">{{ $entry['port'] }}</a>@else{{ $entry['port'] }}@endif</td>
                                    <td>{{ $vlanText($entry) }}</td>
                                    <td>{{ $ago($entry['last_seen']) }}</td>
                                    <td class="topo-wrap">{{ $entry['detail'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        @endforeach

        @if($result['truncated'])
            <p class="text-muted">Only the first {{ count($result['hosts']) }} answers are shown.</p>
        @endif
        <p class="text-muted" style="font-size: 12px">
            LibreNMS reads forwarding (MAC address) tables and ARP / IPv6 neighbor tables at discovery, every 6 hours by default,
            so check <i>last seen</i>. The <code>arp-table</code> poller module refreshes ARP every poll; 802.1X / MAB sessions need the
            <code>nac</code> poller module. Only switches and routers you can see in LibreNMS are searched.
        </p>
    @elseif(! $error)
        <p class="text-muted">
            Enter an IP address (<code>10.10.10.55</code>, <code>2001:db8::55</code>), a MAC address in any notation
            (<code>d4:be:d9:01:02:03</code>, <code>d4be.d901.0203</code>) or a hostname to find the switch port it is plugged into.
            Inter-switch links and port-channels are followed to the switch the host is connected to, past IP phones, access points and
            unmanaged switches. A gateway address leads to the router that answers it.
        </p>
    @endif
</div>
@endsection
