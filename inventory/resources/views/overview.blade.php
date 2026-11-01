{{-- The overview (InventoryController::overview): the models across the network and the software versions. --}}
@extends('layouts.librenmsv1')

@section('title', 'Inventory')

@push('styles')
    <link href="{{ route('inventory.asset', 'inventory.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $versionsShown = 4;
@endphp

@section('content')
<div class="container-fluid inv-page" id="inv-overview">
    @include('inventory::partials.nav', ['active' => 'overview'])

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @include('inventory::partials.filters', ['action' => route('inventory.overview'), 'export' => ['models' => 'Models CSV', 'versions' => 'Versions CSV']])

    @if($ok)
        <div class="inv-cards" id="inv-cards">
            <a class="inv-card" href="{{ route('inventory.devices', $query) }}">
                <h4>Devices</h4><div class="inv-card-value">{{ number_format($devices) }}</div><p>{{ number_format($deviceSerials) }} with a serial number</p>
            </a>
            <div class="inv-card">
                <h4>Models</h4><div class="inv-card-value">{{ number_format(count($models)) }}</div>
                <p>{{ number_format(array_sum(array_column($models, 'units'))) }} units: each member of a stack counted</p>
            </div>
            <div class="inv-card">
                <h4>Software versions</h4><div class="inv-card-value">{{ number_format(count($versions)) }}</div>
                <p>OS and version pairs</p>
            </div>
            <a class="inv-card" href="{{ route('inventory.modules', $query) }}">
                <h4>Serial numbers</h4><div class="inv-card-value">{{ number_format($serials) }}</div><p>in the hardware inventory: chassis, modules, power supplies, optics</p>
            </a>
            <a class="inv-card {{ $origins['third-party'] ? 'inv-warning' : '' }}" href="{{ route('inventory.optics', $query) }}">
                <h4>Optics</h4><div class="inv-card-value">{{ number_format($optics) }}</div>
                <p>{{ number_format($origins['third-party']) }} third-party, {{ number_format($origins['genuine']) }} genuine, {{ number_format($origins['unknown']) }} unknown</p>
            </a>
            @if($software !== null && $software['no_policy'] < array_sum(array_intersect_key($software, \Agoodley\LibrenmsInventory\Software\Status::ALL)))
                <a class="inv-card {{ $software['not_compliant'] ? 'inv-danger' : 'inv-ok' }}" href="{{ route('inventory.software', ['status' => $software['not_compliant'] ? 'not_compliant' : 'compliant'] + $query) }}" id="inv-card-software">
                    <h4>Software not compliant</h4><div class="inv-card-value">{{ number_format($software['not_compliant']) }}</div>
                    <p>{{ number_format($software['blocked']) }} blocked, {{ number_format($software['below_minimum'] + $software['not_approved']) }} below the minimum or not approved;
                        {{ number_format($software['compliant']) }} compliant</p>
                </a>
            @endif
        </div>

        <div class="inv-section"><h4 id="inv-models">Models</h4>
            <span class="inv-help">Each chassis of a device's hardware inventory (so each member of a stack) by its model; a device without one by the model LibreNMS has for it.</span></div>
        <div class="table-responsive">
        <table class="table table-condensed table-hover inv-table inv-stack" id="inv-model-table">
            <thead><tr><th>Model</th><th class="inv-num">Units</th><th class="inv-num">Devices</th><th>OS</th><th>Software versions <span class="inv-muted">(units)</span></th></tr></thead>
            <tbody>
            @forelse($models as $model)
                <tr data-model="{{ $model['model'] }}">
                    <td data-label="Model" class="inv-wrap">
                        @if($model['model'] === \Agoodley\LibrenmsInventory\Reports\Summaries::UNKNOWN)
                            <span class="inv-muted">{{ $model['model'] }}</span>
                        @else
                            <a href="{{ route('inventory.devices', ['model' => $model['model']] + $query) }}">{{ $model['model'] }}</a>
                        @endif
                    </td>
                    <td data-label="Units" class="inv-num">{{ number_format($model['units']) }}</td>
                    <td data-label="Devices" class="inv-num">{{ number_format(count($model['devices'])) }}</td>
                    <td data-label="OS" class="inv-wrap">{{ implode(', ', array_map(fn ($os) => $osNames[$os] ?? $os, $model['os'])) }}</td>
                    <td data-label="Software versions">
                        <ul class="inv-versions">
                            @foreach(array_slice($model['versions'], 0, $versionsShown, true) as $version => $count)
                                <li><span class="inv-code">{{ $version }}</span> <span class="inv-muted">({{ $count }})</span></li>
                            @endforeach
                            @if(count($model['versions']) > $versionsShown)
                                <li class="inv-muted">and {{ count($model['versions']) - $versionsShown }} more</li>
                            @endif
                        </ul>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="inv-muted">No device.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>

        <div class="inv-section"><h4 id="inv-versions">Software versions</h4>
            <span class="inv-help">The version LibreNMS has for each device (a stack runs one).</span></div>
        <div class="table-responsive">
        <table class="table table-condensed table-hover inv-table inv-stack" id="inv-version-table">
            <thead><tr><th>OS</th><th>Version</th><th class="inv-num">Devices</th><th>Models <span class="inv-muted">(devices)</span></th></tr></thead>
            <tbody>
            @forelse($versions as $version)
                <tr data-os="{{ $version['os'] }}" data-version="{{ $version['version'] }}">
                    <td data-label="OS">{{ $version['os_name'] }}</td>
                    <td data-label="Version" class="inv-code inv-wrap">
                        @if($version['version'] === \Agoodley\LibrenmsInventory\Reports\Summaries::UNKNOWN)
                            <span class="inv-muted">{{ $version['version'] }}</span>
                        @else
                            <a href="{{ route('inventory.devices', ['os' => $version['os'], 'version' => $version['version']] + $query) }}">{{ $version['version'] }}</a>
                        @endif
                    </td>
                    <td data-label="Devices" class="inv-num">{{ number_format(count($version['devices'])) }}</td>
                    <td data-label="Models" class="inv-wrap">
                        @foreach(array_slice($version['models'], 0, 3, true) as $model => $count)
                            {{ $model }} <span class="inv-muted">({{ $count }})</span>@if(! $loop->last), @endif
                        @endforeach
                        @if(count($version['models']) > 3)<span class="inv-muted">and {{ count($version['models']) - 3 }} more</span>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="inv-muted">No device.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    @endif
</div>
@endsection
