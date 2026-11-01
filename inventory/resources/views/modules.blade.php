{{-- The modules (InventoryController::modules): the hardware inventory's entries with a serial number, or a module's model. --}}
@extends('layouts.librenmsv1')

@section('title', 'Inventory: modules and serial numbers')

@push('styles')
    <link href="{{ route('inventory.asset', 'inventory.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $classes = ['chassis' => 'chassis', 'stack' => 'stack', 'module' => 'module', 'powerSupply' => 'power supply', 'fan' => 'fan', 'port' => 'port (pluggable)',
        'cpu' => 'CPU', 'storageDrive' => 'storage drive', 'battery' => 'battery', 'backplane' => 'backplane', 'container' => 'container', 'other' => 'other'];
@endphp

@section('content')
<div class="container-fluid inv-page" id="inv-modules">
    @include('inventory::partials.nav', ['active' => 'modules'])

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @include('inventory::partials.filters', ['action' => route('inventory.modules'), 'export' => ['modules' => 'CSV'],
        'fields' => ['class' => ['Class', $classes], 'q' => ['Serial, model or name', null]],
        'deviceName' => $ok && $filters['device'] ? ($names[$filters['device']]['name'] ?? null) : null])

    @if($ok)
        <p class="inv-help">{{ number_format($total) }} entries of the hardware inventory (ENTITY-MIB, LibreNMS' Inventory page): every one with a serial
            number, and chassis, modules, power supplies, fans and pluggables with a model.</p>
        <div class="table-responsive">
        <table class="table table-condensed table-hover inv-table inv-stack" id="inv-module-table">
            <thead><tr><th>Device</th><th>Class</th><th>Name</th><th>Model</th><th>Serial number</th><th>Revisions</th><th>Description</th></tr></thead>
            <tbody>
            @forelse($rows as $module)
                <tr data-device="{{ $module['device_id'] }}" data-index="{{ $module['index'] }}">
                    <td data-label="Device" class="inv-wrap"><a href="{{ url('device/device=' . $module['device_id'] . '/tab=entphysical/') }}">{{ $names[$module['device_id']]['name'] ?? '#' . $module['device_id'] }}</a></td>
                    <td data-label="Class">{{ $classes[$module['class']] ?? $module['class'] }}@if(! $module['fru'] && $module['class'] !== 'chassis') <span class="inv-muted" title="not field replaceable">(fixed)</span>@endif</td>
                    <td data-label="Name" class="inv-wrap">{{ $module['name'] }}</td>
                    <td data-label="Model" class="inv-wrap">{{ $module['model'] }}</td>
                    <td data-label="Serial number" class="inv-code">{{ $module['serial'] }}</td>
                    <td data-label="Revisions" class="inv-wrap inv-muted">{{ implode(' · ', array_filter(['HW ' . $module['hardware_rev'], 'FW ' . $module['firmware_rev'],
                        'SW ' . $module['software_rev']], fn ($text) => strlen($text) > 3)) }}</td>
                    <td data-label="Description" class="inv-wrap">{{ $module['description'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="inv-muted">No entry matches the filters{{ $hidden ? '' : ' (LibreNMS fills the hardware inventory at discovery)' }}.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        @include('inventory::partials.pager', ['page' => min($filters['page'], $pages), 'pages' => $pages, 'total' => $total])
    @endif
</div>
@endsection
