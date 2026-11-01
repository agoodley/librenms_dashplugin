{{-- The optics (InventoryController::optics): by kind, and each optic with the port it sits in. --}}
@extends('layouts.librenmsv1')

@section('title', 'Inventory: optics')

@push('styles')
    <link href="{{ route('inventory.asset', 'inventory.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    use Agoodley\LibrenmsInventory\Reports\Exports;
    $originLabels = ['genuine' => 'genuine', 'third-party' => 'third-party', 'unknown' => 'unknown'];
    $originTitle = 'Genuine: its vendor or part number is the maker of the device it sits in; third-party: another vendor; unknown: the optic names no vendor (LibreNMS often has none for Cisco optics), or the device\'s maker is not known to the plugin. Optics coded for a switch report its maker whoever made them.';
@endphp

@section('content')
<div class="container-fluid inv-page" id="inv-optics">
    @include('inventory::partials.nav', ['active' => 'optics'])

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @include('inventory::partials.filters', ['action' => route('inventory.optics'), 'export' => ['optics' => 'CSV'],
        'fields' => ['type' => ['Type', $ok ? array_combine($types, $types) : []], 'vendor' => ['Vendor', $ok ? array_combine($vendors, $vendors) : []],
            'origin' => ['Origin', $originLabels], 'q' => ['Serial, model or port', null]],
        'deviceName' => $ok && $filters['device'] ? ($names[$filters['device']]['name'] ?? null) : null])

    @if($ok)
        <div class="inv-cards" id="inv-optic-cards">
            <div class="inv-card"><h4>Optics</h4><div class="inv-card-value">{{ number_format($total) }}</div><p>of {{ number_format($all) }} on these devices</p></div>
            <a class="inv-card" href="{{ request()->fullUrlWithQuery(['origin' => 'genuine', 'page' => null]) }}" title="{{ $originTitle }}">
                <h4>Genuine</h4><div class="inv-card-value">{{ number_format($origins['genuine']) }}</div><p>the vendor of the device they sit in</p></a>
            <a class="inv-card {{ $origins['third-party'] ? 'inv-warning' : '' }}" href="{{ request()->fullUrlWithQuery(['origin' => 'third-party', 'page' => null]) }}" title="{{ $originTitle }}">
                <h4>Third-party</h4><div class="inv-card-value">{{ number_format($origins['third-party']) }}</div><p>another vendor</p></a>
            <a class="inv-card" href="{{ request()->fullUrlWithQuery(['origin' => 'unknown', 'page' => null]) }}" title="{{ $originTitle }}">
                <h4>Unknown</h4><div class="inv-card-value">{{ number_format($origins['unknown']) }}</div><p>no vendor in LibreNMS' data</p></a>
        </div>

        <div class="inv-section"><h4>By kind</h4><span class="inv-help">Type, vendor, model and wavelength, the most common first.</span></div>
        <div class="table-responsive">
        <table class="table table-condensed table-hover inv-table inv-stack" id="inv-kind-table">
            <thead><tr><th>Type</th><th>Vendor</th><th>Model</th><th class="inv-num">Wavelength</th><th class="inv-num">Optics</th><th class="inv-num">Devices</th><th>Origin</th></tr></thead>
            <tbody>
            @forelse($kinds as $kind)
                <tr>
                    <td data-label="Type" class="inv-wrap">{{ $kind['type'] }}</td>
                    <td data-label="Vendor" class="inv-wrap">{{ $kind['vendor'] }}</td>
                    <td data-label="Model" class="inv-wrap">{{ $kind['model'] }}</td>
                    <td data-label="Wavelength" class="inv-num">{{ $kind['wavelength'] !== null ? $kind['wavelength'] . ' nm' : '' }}</td>
                    <td data-label="Optics" class="inv-num">{{ number_format($kind['count']) }}</td>
                    <td data-label="Devices" class="inv-num">{{ number_format($kind['devices']) }}</td>
                    <td data-label="Origin">
                        @foreach($kind['origin'] as $origin => $count)
                            <span class="label inv-origin-{{ $origin }}" title="{{ $originTitle }}">{{ $originLabels[$origin] ?? $origin }}{{ count($kind['origin']) > 1 ? ' ' . $count : '' }}</span>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="inv-muted">No optic matches the filters{{ $hidden ? '' : ' (LibreNMS reads the optics of the devices it supports at discovery)' }}.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>

        @if($rows)
            <div class="inv-section"><h4>Each optic</h4></div>
            <div class="table-responsive">
            <table class="table table-condensed table-hover inv-table inv-stack" id="inv-optic-table">
                <thead><tr><th>Device</th><th>Port</th><th>Type</th><th>Vendor</th><th>Model</th><th>Serial number</th><th class="inv-num">Wavelength</th>
                    <th class="inv-num">Distance</th><th>Origin</th></tr></thead>
                <tbody>
                @foreach($rows as $optic)
                    <tr data-device="{{ $optic['device_id'] }}" data-optic="{{ $optic['id'] }}">
                        <td data-label="Device" class="inv-wrap"><a href="{{ url('device/device=' . $optic['device_id'] . '/') }}">{{ $names[$optic['device_id']]['name'] ?? '#' . $optic['device_id'] }}</a></td>
                        <td data-label="Port" class="inv-wrap">@if($optic['port_url'])<a href="{{ $optic['port_url'] }}" title="{{ $optic['port_alias'] }}">{{ $optic['port'] ?: '#' . $optic['port_id'] }}</a>@else{{ $optic['port'] }}@endif
                            @if($optic['port_status'] !== '' && $optic['port_status'] !== 'up')<span class="inv-muted">({{ $optic['port_status'] }})</span>@endif</td>
                        <td data-label="Type" class="inv-wrap">{{ $optic['type'] }}</td>
                        <td data-label="Vendor" class="inv-wrap">{{ $optic['vendor'] }}</td>
                        <td data-label="Model" class="inv-wrap">{{ $optic['model'] }}{{ $optic['revision'] !== '' ? ' ' : '' }}<span class="inv-muted">{{ $optic['revision'] }}</span></td>
                        <td data-label="Serial number" class="inv-code">{{ $optic['serial'] }}</td>
                        <td data-label="Wavelength" class="inv-num">{{ $optic['wavelength'] !== null ? $optic['wavelength'] . ' nm' : '' }}</td>
                        <td data-label="Distance" class="inv-num">{{ $optic['distance'] !== null ? number_format($optic['distance']) . ' m' : '' }}</td>
                        <td data-label="Origin"><span class="label inv-origin-{{ $optic['origin'] ?? 'unknown' }}" title="{{ $originTitle }}">{{ Exports::origin($optic['origin']) }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            @include('inventory::partials.pager', ['page' => min($filters['page'], $pages), 'pages' => $pages, 'total' => $total])
        @endif
    @endif
</div>
@endsection
