{{-- The version changes the software checks saw (SoftwareController::upgrades), the newest first. --}}
@extends('layouts.librenmsv1')

@section('title', 'Inventory: upgrades')

@push('styles')
    <link href="{{ route('inventory.asset', 'inventory.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    use Agoodley\LibrenmsInventory\Support\Clock;
@endphp

@section('content')
<div class="container-fluid inv-page" id="inv-upgrades">
    @include('inventory::partials.nav', ['active' => 'upgrades'])

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    @if($ok && ! $available)
        <div class="alert alert-warning" id="inv-no-tables">The plugin's tables are missing: run <span class="inv-code">./lnms migrate</span> in the LibreNMS directory
            (LibreNMS updates run it too).</div>
    @elseif($ok)
        @include('inventory::partials.filters', ['action' => route('inventory.upgrades'), 'export' => ['upgrades' => 'CSV'],
            'fields' => ['model' => ['Model', null], 'q' => ['Name, serial or model', null]],
            'deviceName' => $filters['device'] ? ($names[$filters['device']]['name'] ?? null) : null])

        <p class="inv-help">{{ number_format($total) }} {{ $total === 1 ? 'version change' : 'version changes' }}, the newest first: what the hourly software check saw
            change in the version LibreNMS has for each device
            @if($first)(since {{ Clock::show($first, 'Y-m-d') }}, its first run)@else(none yet: the check has not run)@endif.
            A change shows at the check after LibreNMS polled the device's new version.</p>
        <div class="table-responsive">
        <table class="table table-condensed table-hover inv-table inv-stack" id="inv-upgrade-table">
            <thead><tr><th>Seen</th><th>Device</th><th>Location</th><th>OS</th><th>Model</th><th>From</th><th>To</th><th>Change</th><th>On the old version</th></tr></thead>
            <tbody>
            @forelse($rows as $row)
                @php
                    $from = Clock::unix($row['from_since']);
                    $seen = Clock::unix($row['seen_at']);
                @endphp
                <tr data-device="{{ $row['device_id'] }}" data-direction="{{ $row['direction'] }}">
                    <td data-label="Seen" class="inv-nowrap">{{ Clock::local($row['seen_at']) }}</td>
                    <td data-label="Device" class="inv-wrap"><a href="{{ url('device/device=' . $row['device_id'] . '/') }}">{{ $names[$row['device_id']]['name'] ?? '#' . $row['device_id'] }}</a></td>
                    <td data-label="Location" class="inv-wrap">{{ $names[$row['device_id']]['location'] ?? '' }}</td>
                    <td data-label="OS">{{ $osNames[(string) $row['os']] ?? $row['os'] }}</td>
                    <td data-label="Model" class="inv-wrap">{{ $row['model'] }}</td>
                    <td data-label="From" class="inv-code inv-wrap">{{ $row['from_version'] }}</td>
                    <td data-label="To" class="inv-code inv-wrap">{{ $row['to_version'] }}</td>
                    <td data-label="Change"><span class="label {{ $row['direction'] === 'downgrade' ? 'label-warning' : 'label-info' }}">{{ $row['direction'] }}</span></td>
                    <td data-label="On the old version" class="inv-nowrap" title="since {{ $from !== null ? Clock::show($from) : '?' }}, as the checks saw it">
                        @if($from !== null && $seen !== null){{ number_format((int) floor(($seen - $from) / 86400)) }} days @endif</td>
                </tr>
            @empty
                <tr><td colspan="9" class="inv-muted">No version change seen for the devices of the filters.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        @include('inventory::partials.pager', ['page' => min($filters['page'], $pages), 'pages' => $pages, 'total' => $total])
    @endif
</div>
@endsection
