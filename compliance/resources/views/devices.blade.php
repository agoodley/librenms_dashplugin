{{-- The devices (ComplianceController::devices): each device the user may see that was checked, its state and its findings,
     the worst first, filtered by state and name. --}}
@extends('layouts.librenmsv1')

@section('title', 'Compliance devices')

@push('styles')
    <link href="{{ route('compliance.asset', 'compliance.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    use Agoodley\LibrenmsCompliance\Services\Overview;
    use Agoodley\LibrenmsCompliance\Support\Clock;
    $labels = ['findings' => ['findings', 'danger'], 'compliant' => ['compliant', 'success'], 'not_applicable' => ['no rule applies', 'default'],
        'no_backup' => ['no backup', 'default'], 'error' => ['not checked', 'warning']];
@endphp

@section('content')
<div class="container-fluid cmp-page" id="cmp-devices">
    @include('compliance::partials.nav', ['active' => 'devices'])

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    <form method="get" action="{{ route('compliance.devices') }}" class="cmp-filterbar" id="cmp-device-filters">
        <div><label for="cmp-filter-state">State</label>
            <select class="form-control" id="cmp-filter-state" name="state">
                @foreach(Overview::STATES as $value => $label)
                    <option value="{{ $value }}" @selected($state === $value)>{{ $label }}</option>
                @endforeach
            </select></div>
        <div><label for="cmp-filter-name">Device</label>
            <input class="form-control" id="cmp-filter-name" name="device" value="{{ $text }}" placeholder="name or hostname" maxlength="200"></div>
        <div><button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-filter"></i> Filter</button>
            @if($state !== '' || $text !== '')<a class="btn btn-sm btn-default" href="{{ route('compliance.devices') }}">Clear</a>@endif</div>
        <span class="cmp-spacer"></span>
        <span class="cmp-muted" style="font-size: 12px">{{ $total }} {{ $total === 1 ? 'device' : 'devices' }}</span>
    </form>

    @if($rows)
        <div class="table-responsive">
        <table class="table table-condensed table-hover cmp-table cmp-stack" id="cmp-device-table">
            <thead><tr><th>Device</th><th>State</th><th class="cmp-num">Critical</th><th class="cmp-num">Warning</th><th class="cmp-num">Info</th><th>Backup</th><th>Checked</th></tr></thead>
            <tbody>
            @foreach($rows as $row)
                @php([$label, $css] = $labels[$row['state']] ?? [$row['state'], 'default'])
                <tr data-device="{{ $row['device_id'] }}">
                    <td data-label="Device" class="cmp-wrap"><a href="{{ route('compliance.device', $row['device_id']) }}">{{ $row['name'] }}</a>
                        @if($row['hostname'] !== $row['name'])<div class="cmp-muted cmp-code">{{ $row['hostname'] }}</div>@endif</td>
                    <td data-label="State"><span class="label label-{{ $css }}">{{ $label }}</span>
                        @if($row['row']['status'] !== \Agoodley\LibrenmsCompliance\Data\ResultRepository::CHECKED && $row['state'] === 'findings')
                            <div class="cmp-muted" style="font-size: 12px">of an earlier check: {{ $row['row']['error'] }}</div>
                        @elseif(in_array($row['state'], ['no_backup', 'error'], true))
                            <div class="cmp-muted" style="font-size: 12px">{{ $row['row']['error'] }}</div>
                        @endif</td>
                    <td data-label="Critical" class="cmp-num">{{ $row['counts']['critical'] ?? 0 }}</td>
                    <td data-label="Warning" class="cmp-num">{{ $row['counts']['warning'] ?? 0 }}</td>
                    <td data-label="Info" class="cmp-num">{{ $row['counts']['info'] ?? 0 }}</td>
                    <td data-label="Backup" class="cmp-nowrap">@if($row['row']['backup_at']){{ Clock::local($row['row']['backup_at']) }}@endif
                        @if($row['row']['provider'])<span class="cmp-muted">{{ $row['row']['provider'] }}</span>@endif</td>
                    <td data-label="Checked" class="cmp-nowrap">{{ Clock::ago($row['row']['checked_at']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @include('compliance::partials.pager', ['page' => $page, 'pages' => $pages])
    @elseif(! $error)
        <p class="cmp-muted" id="cmp-no-devices">{{ $state !== '' || $text !== '' ? 'No device matches the filters.' : 'No device was checked yet.' }}</p>
    @endif
</div>
@endsection
