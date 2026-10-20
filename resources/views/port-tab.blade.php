{{-- Topology section of the port page's Plugins tab (Hooks\PortTab), from HostService::portTab(). --}}
<link href="{{ route('topology.asset', 'topology.css') }}?v={{ \Agoodley\LibrenmsTopology\Http\Controllers\PageController::assetVersion() }}" rel="stylesheet">
@php
    $ago = fn ($time) => $time ? \Agoodley\LibrenmsTopology\Stp\StpAnalyzer::duration(max(0, time() - (int) $time)) . ' ago' : 'unknown';
@endphp
<div class="panel panel-default topo-port-tab">
    <div class="panel-heading"><strong><i class="fa fa-sitemap"></i> Topology</strong>
        @if($tab && $tab['visible'])
            <a class="btn btn-xs btn-default" style="margin-left: 8px" href="{{ $tab['map_url'] }}"><i class="fa fa-sitemap"></i> L2 map</a>
        @endif
    </div>
    <div class="panel-body">
    @if(! empty($hidden))
        <p class="text-muted"><i class="fa fa-lock"></i> Your LibreNMS role may not view {{ implode(', ', $hidden) }}: they are left out.</p>
    @endif
    @if(! $tab)
        <p class="text-muted">The topology information of this port could not be loaded, see logs/librenms.log.</p>
    @elseif(! $tab['visible'])
        <p class="text-muted">No topology information for this port.</p>
    @else
        <h5 style="margin-top: 0">Far end</h5>
        @forelse($tab['neighbors'] as $neighbor)
            <div>
                @if($neighbor['url'])<a href="{{ $neighbor['url'] }}"><strong>{{ $neighbor['name'] }}</strong></a>@else<strong>{{ $neighbor['name'] }}</strong>@endif
                {{ $neighbor['port'] }}
                @if(! $neighbor['device_id'])<span class="text-muted">(unmanaged neighbor{{ ($neighbor['platform'] ?: $neighbor['description']) !== '' ? ': ' . ($neighbor['platform'] ?: $neighbor['description']) : '' }})</span>@endif
                @if($neighbor['via'])<small class="text-muted">on member {{ $neighbor['via'] }}</small>@endif
            </div>
        @empty
            <p class="text-muted">No LLDP/CDP neighbor on this port.</p>
        @endforelse

        @if($tab['parent'] || $tab['members'])
            <h5>Port-channel</h5>
            @if($tab['parent'])
                <div>Member of @if($tab['parent']['url'])<a href="{{ $tab['parent']['url'] }}">{{ $tab['parent']['label'] }}</a>@else{{ $tab['parent']['label'] }}@endif</div>
            @endif
            @if($tab['members'])
                <div>Members:
                    @foreach($tab['members'] as $member)
                        @if($member['url'])<a href="{{ $member['url'] }}">{{ $member['label'] }}</a>@else{{ $member['label'] }}@endif
                        @if($member['status'] && $member['status'] !== 'up')<span class="topo-status-{{ $member['status'] }}">{{ $member['status'] }}</span>@endif
                        @if(! $loop->last), @endif
                    @endforeach
                </div>
            @endif
        @endif

        <h5>VLANs</h5>
        @php
            $untagged = array_values(array_filter($tab['vlans'], fn ($v) => $v['untagged']));
            $tagged = array_values(array_filter($tab['vlans'], fn ($v) => ! $v['untagged']));
            $vlanName = fn ($v) => $v['vlan'] . ($v['name'] && $v['name'] !== sprintf('VLAN%04d', $v['vlan']) ? ' (' . $v['name'] . ')' : '');
        @endphp
        <table class="table table-condensed topo-kv">
            <tr><th>Mode</th><td>{{ $tab['port']['ifTrunk'] ? 'trunk (' . $tab['port']['ifTrunk'] . ')' : 'access' }}</td></tr>
            <tr><th>Native / access VLAN</th><td>{{ $untagged ? implode(', ', array_map($vlanName, $untagged)) : ($tab['port']['ifVlan'] ?: '-') }}</td></tr>
            @if($tagged)
                <tr><th>Tagged</th><td class="topo-vlans">{{ implode(', ', array_map($vlanName, $tagged)) }}</td></tr>
            @endif
        </table>

        @if($tab['stp'])
            <h5>Spanning tree</h5>
            <div class="table-responsive">
            <table class="table table-condensed table-hover topo-stp-table" style="width: auto">
                <thead><tr><th>Instance</th><th>State</th><th>Role</th><th>Cost</th><th>To forwarding</th></tr></thead>
                <tbody>
                @foreach($tab['stp'] as $row)
                    <tr>
                        <td>{{ $row['label'] }}@if($row['root_bridge']) <i class="fa fa-star" style="color: #f1c40f" title="this switch is the root bridge"></i>@endif</td>
                        <td class="{{ \Agoodley\LibrenmsTopology\Stp\StpIndex::isBlocking($row['state']) ? 'topo-text-warning' : '' }}">{{ $row['state'] }}@if($row['via_lag']) <small class="text-muted">(port-channel)</small>@endif</td>
                        <td>{{ $row['role'] }}</td>
                        <td>{{ $row['cost'] }}</td>
                        <td>{{ $row['transitions'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif

        <h5>MAC addresses learned
            @if($tab['fdb_port'])
                on port-channel @if($tab['fdb_port']['url'])<a href="{{ $tab['fdb_port']['url'] }}">{{ $tab['fdb_port']['label'] }}</a>@else{{ $tab['fdb_port']['label'] }}@endif
            @endif
            <small class="text-muted">
                {{ $tab['mac_count'] }} at the last discovery{{ $tab['last_discovery'] ? ' (' . $ago($tab['last_discovery']) . ')' : '' }}@if(count($tab['macs']) < $tab['mac_count']), showing the newest {{ count($tab['macs']) }}@endif
            </small>
        </h5>
        @if($tab['macs'])
            <div class="table-responsive">
            <table class="table table-condensed table-hover topo-stp-table">
                <thead><tr><th>MAC address</th><th>Vendor</th><th>VLAN</th><th>IP addresses</th><th>Last seen</th><th></th></tr></thead>
                <tbody>
                @foreach($tab['macs'] as $mac)
                    <tr class="{{ $mac['stale'] ? 'text-muted' : '' }}">
                        <td class="topo-mono">{{ $mac['readable'] }}</td>
                        <td>{{ $mac['vendor'] ?? '' }}</td>
                        <td>{{ $mac['vlan'] ?? '-' }}</td>
                        <td class="topo-mono topo-wrap">{{ implode(', ', array_slice($mac['ips'], 0, 4)) }}</td>
                        <td>{{ $ago($mac['last_seen']) }}@if($mac['stale']) <small>(not at the last discovery)</small>@endif</td>
                        <td><a href="{{ $mac['locate_url'] }}" title="Locate this host"><i class="fa fa-map-marker"></i> Locate</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @else
            <p class="text-muted">None in the forwarding table LibreNMS has for this switch{{ $tab['parent'] ? ' (neither on this port nor on its port-channel)' : '' }}.</p>
        @endif

        @if($tab['nac'])
            <h5>802.1X / MAB sessions</h5>
            <div class="table-responsive">
            <table class="table table-condensed table-hover topo-stp-table">
                <thead><tr><th>MAC address</th><th>User</th><th>Status</th><th>Method</th><th>Host mode</th><th>VLAN</th></tr></thead>
                <tbody>
                @foreach($tab['nac'] as $session)
                    <tr class="{{ $session['historical'] || ! empty($session['inactive']) ? 'text-muted' : '' }}">
                        <td class="topo-mono">{{ $session['readable'] }}</td>
                        <td>{{ $session['username'] ?: '-' }}</td>
                        <td>{{ $session['status'] ?: '-' }}@if($session['historical']) <small>(earlier session)</small>@elseif(! empty($session['inactive'])) <small>(not current: {{ $session['inactive'] }})</small>@endif</td>
                        <td>{{ $session['method'] ?: '-' }}</td>
                        <td>{{ $session['host_mode'] ?: '-' }}</td>
                        <td>{{ $session['vlan'] ?? '-' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    @endif
    </div>
</div>
