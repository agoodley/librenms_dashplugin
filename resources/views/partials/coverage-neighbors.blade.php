{{-- Table of unmonitored LLDP/CDP neighbors on the coverage page; $rows from CoverageAnalyzer, helpers from coverage.blade.php. --}}
<table class="table table-condensed table-hover topo-stp-table">
    <thead><tr><th>Neighbor</th><th>Type</th><th>Platform or description</th><th>Seen on</th><th>In LibreNMS</th><th></th></tr></thead>
    <tbody>
    @forelse($rows as $neighbor)
        <tr>
            <td class="topo-wrap">
                <strong>{{ \Illuminate\Support\Str::limit($neighbor['name'], 60) }}</strong>
                @if($neighbor['protocols'])<br><small class="text-muted">{{ strtoupper(implode(', ', $neighbor['protocols'])) }}</small>@endif
            </td>
            <td style="white-space: nowrap">{!! $role($neighbor['role']) !!}</td>
            <td class="topo-wrap" title="{{ $neighbor['description'] }}">{{ \Illuminate\Support\Str::limit($neighbor['platform'] ?: ($neighbor['description'] ?: '-'), 60) }}</td>
            <td class="topo-wrap">
                @foreach(array_slice($neighbor['sightings'], 0, 6) as $sighting)
                    <a href="{{ $deviceUrl($sighting['device_id']) }}">{{ $name($sighting['device_id']) }}</a>
                    @if($sighting['port_id'])<a href="{{ $portUrl($sighting['device_id'], $sighting['port_id']) }}">{{ $sighting['port'] }}</a>@endif
                    @if($sighting['remote_port'] !== '')<span class="text-muted">&harr; {{ \Agoodley\LibrenmsTopology\Support\InterfaceName::short($sighting['remote_port']) }}</span>@endif
                    @if(! $loop->last)<br>@endif
                @endforeach
                @if(count($neighbor['sightings']) > 6)<br><small class="text-muted">and {{ count($neighbor['sightings']) - 6 }} more</small>@endif
            </td>
            <td class="topo-wrap">
                @if($neighbor['status'] === 'not_linked')
                    monitored as <a href="{{ $deviceUrl($neighbor['device_id']) }}">{{ $name($neighbor['device_id']) }}</a>, <span class="text-muted">not linked</span>
                @elseif($neighbor['status'] === 'ambiguous')
                    <span class="text-muted">name of</span>
                    @foreach(array_slice($neighbor['candidates'], 0, 4) as $deviceId)
                        <a href="{{ $deviceUrl($deviceId) }}">{{ $name($deviceId) }}</a>@if(! $loop->last), @endif
                    @endforeach
                    @if(count($neighbor['candidates']) > 4)<span class="text-muted">and {{ count($neighbor['candidates']) - 4 }} more</span>@endif
                    <span class="text-muted">(ambiguous, not linked)</span>
                @elseif($neighbor['status'] === 'hidden')
                    <span class="text-muted">monitored, not visible to you</span>
                @elseif($neighbor['status'] === 'disabled')
                    <a href="{{ $deviceUrl($neighbor['device_id']) }}">{{ $name($neighbor['device_id']) }}</a> <span class="text-muted">is disabled</span>
                @else
                    <span class="text-muted">not monitored</span>
                @endif
            </td>
            <td style="white-space: nowrap">
                <a href="{{ $mapUrl($neighbor['sightings'][0]['device_id']) }}" title="Show on the L2 map"><i class="fa fa-sitemap"></i></a>
                @if($canAddDevices && $neighbor['status'] === 'not_monitored')
                    <a class="btn btn-xs btn-default" href="{{ url('addhost') }}" title="Add {{ $neighbor['address'] ?? $neighbor['name'] }} in LibreNMS (Add Device)"><i class="fa fa-plus"></i> Add</a>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="6">None.</td></tr>
    @endforelse
    </tbody>
</table>
