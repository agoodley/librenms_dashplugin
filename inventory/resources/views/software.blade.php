{{-- Software compliance (SoftwareController::software): each device's software against the policies as the last check saw
     it, and the policies. --}}
@extends('layouts.librenmsv1')

@section('title', 'Inventory: software compliance')

@push('styles')
    <link href="{{ route('inventory.asset', 'inventory.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    use Agoodley\LibrenmsInventory\Http\Controllers\SoftwareController;
    use Agoodley\LibrenmsInventory\Software\Status;
    use Agoodley\LibrenmsInventory\Support\Clock;
    $statusChoices = ['not_compliant' => 'not compliant (each kind)', 'blocked' => 'blocked', 'below_minimum' => 'below the minimum', 'not_approved' => 'not approved',
        'compliant' => 'compliant (each kind)', 'approved' => 'approved', 'allowed' => 'allowed', 'unknown' => 'version unknown', 'no_policy' => 'no policy'];
    $last = $lastRun ?? null;
@endphp

@section('content')
<div class="container-fluid inv-page" id="inv-software">
    @include('inventory::partials.nav', ['active' => 'software'])

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif
    @include('inventory::partials.flash')

    @if($ok && ! $available)
        <div class="alert alert-warning" id="inv-no-tables">The plugin's tables are missing: run <span class="inv-code">./lnms migrate</span> in the LibreNMS directory
            (LibreNMS updates run it too). Software compliance needs them; the other pages do not.</div>
    @elseif($ok)
        @include('inventory::partials.filters', ['action' => route('inventory.software'), 'export' => ['software' => 'CSV'],
            'fields' => ['status' => ['Status', $statusChoices], 'model' => ['Model', null], 'version' => ['Software version', null], 'q' => ['Name, serial or model', null]],
            'deviceName' => $filters['device'] ? ($rows[0]['name'] ?? null) : null])

        @if($settingsError)
            <div class="alert alert-danger">{{ $settingsError }}</div>
        @endif
        @if($policyErrors)
            <div class="alert alert-danger">The policies of the settings have errors, so the check does not run: {{ implode(' ', $policyErrors) }}</div>
        @endif

        <div class="inv-checked" id="inv-last-check">
            <span class="inv-help">
                @if($last)
                    Checked {{ Clock::ago(Clock::store((int) $last['started'])) }} ({{ Clock::show((int) $last['started']) }}); the check runs every hour.
                    @if($last['error'])<span class="text-danger">{{ $last['error'] }}</span>@endif
                @else
                    Not checked yet: the check runs every hour (<span class="inv-code">./lnms inventory:check</span>).
                @endif
                @if($admin)
                    <a href="{{ url('plugin/settings/inventory') }}">Policies and settings</a>
                @endif
            </span>
            @if($admin)
                <form method="post" action="{{ route('inventory.software.check', $query) }}" class="inv-inline">
                    @csrf
                    <button type="submit" class="btn btn-xs btn-default" id="inv-check-now"><i class="fa fa-refresh"></i> Check now</button>
                </form>
            @endif
        </div>

        @if(! $policies && ! $policyErrors)
            <div class="alert alert-info" id="inv-no-policies">There are no software policies yet, so every device is listed without a policy.
                @if($admin) Write them in the <a href="{{ url('plugin/settings/inventory') }}">plugin's settings</a>. @else LibreNMS admins write them in the plugin's settings. @endif
                The check records each device's upgrades and downgrades anyway (<a href="{{ route('inventory.upgrades') }}">Upgrades</a>).</div>
        @endif

        <div class="inv-cards" id="inv-software-cards">
            <a class="inv-card {{ $counts['not_compliant'] ? 'inv-danger' : '' }}" href="{{ route('inventory.software', ['status' => 'not_compliant'] + $query) }}" id="inv-card-not-compliant">
                <h4>Not compliant</h4><div class="inv-card-value">{{ number_format($counts['not_compliant']) }}</div>
                <p>{{ number_format($counts['blocked']) }} blocked, {{ number_format($counts['below_minimum']) }} below the minimum, {{ number_format($counts['not_approved']) }} not approved</p>
            </a>
            <a class="inv-card inv-ok" href="{{ route('inventory.software', ['status' => 'compliant'] + $query) }}" id="inv-card-compliant">
                <h4>Compliant</h4><div class="inv-card-value">{{ number_format($counts['compliant']) }}</div>
                <p>{{ number_format($counts['approved']) }} approved, {{ number_format($counts['allowed']) }} allowed</p>
            </a>
            <a class="inv-card" href="{{ route('inventory.software', ['status' => 'unknown'] + $query) }}" id="inv-card-unknown">
                <h4>Version unknown</h4><div class="inv-card-value">{{ number_format($counts['unknown']) }}</div><p>a policy applies, LibreNMS has no version</p>
            </a>
            <a class="inv-card" href="{{ route('inventory.software', ['status' => 'no_policy'] + $query) }}" id="inv-card-no-policy">
                <h4>No policy</h4><div class="inv-card-value">{{ number_format($counts['no_policy']) }}</div><p>no policy applies to them</p>
            </a>
        </div>

        <p class="inv-help">{{ number_format($total) }} {{ $total === 1 ? 'device' : 'devices' }}, the worst first.
            @if($unchecked > 0)
                {{ number_format($unchecked) }} {{ $unchecked === 1 ? 'device' : 'devices' }} of the filters {{ $unchecked === 1 ? 'is' : 'are' }} not checked: disabled, left out by
                the settings, or added since the last check.
            @endif
        </p>
        <div class="table-responsive">
        <table class="table table-condensed table-hover inv-table inv-stack" id="inv-software-table">
            <thead><tr><th>Device</th><th>Location</th><th>OS</th><th>Model</th><th>Version</th><th>Status</th><th>Policy</th><th>Why</th><th>Since</th></tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr data-device="{{ $row['device_id'] }}" data-status="{{ $row['status'] }}">
                    <td data-label="Device" class="inv-wrap"><a href="{{ $row['url'] }}">{{ $row['name'] }}</a></td>
                    <td data-label="Location" class="inv-wrap">{{ $row['location'] }}</td>
                    <td data-label="OS">{{ $osNames[(string) $row['os']] ?? $row['os'] }}</td>
                    <td data-label="Model" class="inv-wrap">{{ $row['model'] }}</td>
                    <td data-label="Version" class="inv-wrap"><span class="inv-code">{{ $row['version'] }}</span>
                        @if($row['version'] !== '' && $row['version'] !== null)<div class="inv-muted" title="as the checks saw it">since {{ Clock::local($row['version_since'], 'Y-m-d') }}</div>@endif</td>
                    <td data-label="Status">@include('inventory::partials.status', ['status' => (string) $row['status']])</td>
                    <td data-label="Policy" class="inv-wrap">{{ $row['policy'] }}</td>
                    <td data-label="Why" class="inv-wrap inv-why">{{ $row['message'] }}</td>
                    <td data-label="Since" class="inv-nowrap" title="{{ Clock::local($row['status_since']) }}">{{ Clock::ago($row['status_since']) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="inv-muted">@if($last) No device matches the filters. @else The devices were not checked yet. @endif</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        @include('inventory::partials.pager', ['page' => min($filters['page'], $pages), 'pages' => $pages, 'total' => $total])

        @if($policies)
            <div class="inv-section"><h4 id="inv-policies">Policies</h4>
                <span class="inv-help">A device gets the policy for its model (its own, or a stack member's), else the one for its OS; of several, the first. The blocked
                    versions of every policy for it count.</span></div>
            <div class="table-responsive">
            <table class="table table-condensed inv-table inv-stack" id="inv-policy-table">
                <thead><tr><th>Policy</th><th>For</th><th>Approved</th><th>Minimum</th><th>Blocked</th><th>Note</th><th class="inv-num">Devices</th></tr></thead>
                <tbody>
                @foreach($policies as $policy)
                    <tr data-policy="{{ $policy->name }}">
                        <td data-label="Policy" class="inv-wrap">{{ $policy->name }}</td>
                        <td data-label="For" class="inv-wrap inv-code">{{ SoftwareController::scope($policy) }}</td>
                        <td data-label="Approved" class="inv-wrap inv-code">{{ SoftwareController::patterns($policy->approved) }}</td>
                        <td data-label="Minimum" class="inv-code">{{ $policy->minimum }}</td>
                        <td data-label="Blocked" class="inv-wrap inv-code">{{ SoftwareController::patterns($policy->blocked) }}</td>
                        <td data-label="Note" class="inv-wrap">{{ $policy->note }}</td>
                        <td data-label="Devices" class="inv-num" title="the devices of the filters whose status it gave">{{ number_format($perPolicy[$policy->name] ?? 0) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    @endif
</div>
@endsection
