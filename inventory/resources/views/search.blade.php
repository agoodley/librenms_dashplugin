{{-- The search (InventoryController::search): where a serial number or part number is. --}}
@extends('layouts.librenmsv1')

@section('title', 'Inventory: search')

@push('styles')
    <link href="{{ route('inventory.asset', 'inventory.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $kinds = ['device' => 'device', 'module' => 'hardware inventory', 'optic' => 'optic'];
    $limit = \Agoodley\LibrenmsInventory\Data\InventoryRepository::SEARCH_LIMIT;
@endphp

@section('content')
<div class="container-fluid inv-page" id="inv-search">
    @include('inventory::partials.nav', ['active' => 'search'])

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @if($ok)
        @if($filters['q'] === '')
            <p class="inv-help">A serial number or part number, or a part of it (at least {{ \Agoodley\LibrenmsInventory\Http\Controllers\InventoryController::SEARCH_MIN }}
                characters), in the search box above: the devices, their hardware inventory (chassis, modules, power supplies, pluggables) and their optics
                that have it, among the devices you may see.</p>
        @elseif($short)
            <p class="text-warning" id="inv-search-short">Search for {{ \Agoodley\LibrenmsInventory\Http\Controllers\InventoryController::SEARCH_MIN }} characters or more.</p>
        @else
            <h4>{{ count($found) }} {{ count($found) === 1 ? 'match' : 'matches' }} for <span class="inv-code">{{ $filters['q'] }}</span></h4>
            @if($found)
                <div class="table-responsive">
                <table class="table table-condensed table-hover inv-table inv-stack" id="inv-search-table">
                    <thead><tr><th>Found in</th><th>Device</th><th>Location</th><th>Where</th><th>Model</th><th>Serial number</th></tr></thead>
                    <tbody>
                    @foreach($found as $row)
                        <tr data-kind="{{ $row['kind'] }}" data-device="{{ $row['device_id'] }}">
                            <td data-label="Found in">{{ $kinds[$row['kind']] ?? $row['kind'] }}</td>
                            <td data-label="Device" class="inv-wrap"><a href="{{ url('device/device=' . $row['device_id'] . '/' . ($row['kind'] === 'module' ? 'tab=entphysical/' : '')) }}">{{ $names[$row['device_id']]['name'] ?? '#' . $row['device_id'] }}</a></td>
                            <td data-label="Location" class="inv-wrap">{{ $names[$row['device_id']]['location'] ?? '' }}</td>
                            <td data-label="Where" class="inv-wrap">
                                @if($row['kind'] === 'optic' && $row['port_id'] !== null && isset($ports[$row['port_id']]))
                                    <a href="{{ url('device/device=' . $row['device_id'] . '/tab=port/port=' . $row['port_id'] . '/') }}">{{ $ports[$row['port_id']] }}</a> <span class="inv-muted">{{ $row['what'] }}</span>
                                @else
                                    {{ $row['what'] }}
                                @endif
                            </td>
                            <td data-label="Model" class="inv-wrap">{{ $row['model'] }}</td>
                            <td data-label="Serial number" class="inv-code">{{ $row['serial'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
                @if(count(array_filter($found, fn ($row) => $row['kind'] === 'module')) >= $limit || count(array_filter($found, fn ($row) => $row['kind'] === 'optic')) >= $limit)
                    <p class="inv-help">The first {{ $limit }} matches of each kind: a longer search finds fewer.</p>
                @endif
            @else
                <p class="inv-muted" id="inv-search-none">No device, hardware inventory entry or optic of the devices you may see has it.</p>
            @endif
        @endif
    @endif
</div>
@endsection
