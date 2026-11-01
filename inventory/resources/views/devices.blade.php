{{-- The devices (InventoryController::devices): each device's model, software, serial numbers, stack members and counts. --}}
@extends('layouts.librenmsv1')

@section('title', 'Inventory: devices')

@push('styles')
    <link href="{{ route('inventory.asset', 'inventory.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@section('content')
<div class="container-fluid inv-page" id="inv-devices">
    @include('inventory::partials.nav', ['active' => 'devices'])

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @include('inventory::partials.filters', ['action' => route('inventory.devices'), 'export' => ['devices' => 'CSV'],
        'fields' => ['model' => ['Model', null], 'version' => ['Software version', null], 'q' => ['Name, serial or model', null]],
        'deviceName' => $ok && $filters['device'] ? ($rows[$filters['device']]['name'] ?? null) : null])

    @if($ok)
        <p class="inv-help">{{ number_format($total) }} {{ $total === 1 ? 'device' : 'devices' }}. The serial number is the one LibreNMS shows for the device (a stack's: its
            first member's); the chassis are each member's, from its hardware inventory.</p>
        <div class="table-responsive">
        <table class="table table-condensed table-hover inv-table inv-stack" id="inv-device-table">
            <thead><tr><th>Device</th><th>Location</th><th>OS</th><th>Model</th><th>Version</th><th>Serial number</th><th>Chassis</th>
                <th class="inv-num">Serial numbers</th><th class="inv-num">Optics</th></tr></thead>
            <tbody>
            @forelse($rows as $id => $device)
                @php($units = $chassis[$id] ?? [])
                <tr data-device="{{ $id }}">
                    <td data-label="Device" class="inv-wrap"><a href="{{ $device['url'] }}">{{ $device['name'] }}</a>
                        @if($device['disabled'])<span class="label label-default">disabled</span>@endif</td>
                    <td data-label="Location" class="inv-wrap">{{ $device['location'] ?? '' }}</td>
                    <td data-label="OS">{{ $osNames[$device['os']] ?? $device['os'] }}</td>
                    <td data-label="Model" class="inv-wrap">{{ $device['hardware'] }}</td>
                    <td data-label="Version" class="inv-code inv-wrap">{{ $device['version'] }}
                        @if($device['features'])<div class="inv-muted">{{ $device['features'] }}</div>@endif</td>
                    <td data-label="Serial number" class="inv-code">{{ $device['serial'] }}</td>
                    <td data-label="Chassis" class="inv-wrap">
                        @if(count($units) > 1 || (count($units) === 1 && $units[0]['serial'] !== (string) $device['serial']))
                            @foreach($units as $unit)
                                <div><span class="inv-code">{{ $unit['serial'] ?: '-' }}</span> <span class="inv-muted">{{ $unit['model'] }}</span></div>
                            @endforeach
                        @endif
                    </td>
                    <td data-label="Serial numbers" class="inv-num">@if($counts[$id]['serials'] ?? 0)<a href="{{ route('inventory.modules', ['device' => $id]) }}">{{ number_format($counts[$id]['serials']) }}</a>@else 0 @endif</td>
                    <td data-label="Optics" class="inv-num">@if($counts[$id]['optics'] ?? 0)<a href="{{ route('inventory.optics', ['device' => $id]) }}">{{ number_format($counts[$id]['optics']) }}</a>@else 0 @endif</td>
                </tr>
            @empty
                <tr><td colspan="9" class="inv-muted">No device matches the filters.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        @include('inventory::partials.pager', ['page' => min($filters['page'], $pages), 'pages' => $pages, 'total' => $total])
    @endif
</div>
@endsection
