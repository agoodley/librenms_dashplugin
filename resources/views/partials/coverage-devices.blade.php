{{-- Table of monitored devices without LLDP/CDP neighbors on the coverage page; $rows from CoverageAnalyzer, helpers from coverage.blade.php. --}}
@php
    $states = [
        'silent' => 'neighbors see it, it reports none',
        'isolated' => 'no neighbor data on either side',
        'quiet' => 'no LLDP/CDP: usual for this type',
    ];
@endphp
<table class="table table-condensed table-hover topo-stp-table">
    <thead><tr><th>Device</th><th>Type</th><th>Coverage</th><th>Seen by</th><th></th></tr></thead>
    <tbody>
    @forelse($rows as $device)
        <tr>
            <td class="topo-wrap">
                @if(! $device['up'])<span class="topo-status-down" title="down"><i class="fa fa-arrow-down"></i></span>@endif
                <a href="{{ $deviceUrl($device['device_id']) }}">{{ $name($device['device_id']) }}</a>
            </td>
            <td style="white-space: nowrap">{!! $role($device['role']) !!}@if($device['type'] !== '')<br><small class="text-muted">{{ $device['type'] }}</small>@endif</td>
            <td class="topo-wrap">
                {{ $states[$device['state']] ?? $device['state'] }}
                @if($device['module_off'])<br><small class="text-warning">discovery-protocols module disabled in LibreNMS</small>@endif
            </td>
            <td class="topo-wrap">
                @forelse(array_slice($device['seen_by'], 0, 6) as $sighting)
                    <a href="{{ $deviceUrl($sighting['device_id']) }}">{{ $name($sighting['device_id']) }}</a>
                    @if($sighting['port_id'])<a href="{{ $portUrl($sighting['device_id'], $sighting['port_id']) }}">{{ $sighting['port'] }}</a>@endif
                    @if($sighting['remote_port'] !== '')<span class="text-muted">&harr; {{ $sighting['remote_port'] }}</span>@endif
                    @if(! $loop->last)<br>@endif
                @empty
                    <span class="text-muted">-</span>
                @endforelse
                @if(count($device['seen_by']) > 6)<br><small class="text-muted">and {{ count($device['seen_by']) - 6 }} more</small>@endif
            </td>
            <td><a href="{{ $mapUrl($device['device_id']) }}" title="Show on the L2 map"><i class="fa fa-sitemap"></i></a></td>
        </tr>
    @empty
        <tr><td colspan="5">None: every monitored switch, router, firewall and access point reports LLDP/CDP neighbors.</td></tr>
    @endforelse
    </tbody>
</table>
