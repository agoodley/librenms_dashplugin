{{-- The overview (ComplianceController::index): how compliant the devices the user may see are, each rule's outcomes over
     them, the findings (filtered by rule, severity, message, device), a page of them, and their export. --}}
@extends('layouts.librenmsv1')

@section('title', 'Compliance')

@push('styles')
    <link href="{{ route('compliance.asset', 'compliance.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    use Agoodley\LibrenmsCompliance\Rules\Rule;
    use Agoodley\LibrenmsCompliance\Support\Clock;
    $query = array_filter($filters, fn ($value) => $value !== '');
@endphp

@section('content')
<div class="container-fluid cmp-page" id="cmp-overview">
    @include('compliance::partials.nav', ['active' => 'overview'])

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif
    @if($settings?->error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $settings->error }}</div>
    @endif

    <p class="cmp-help" style="margin: 0 0 8px">The latest configuration backup of each device, as LibreNMS' Config tab shows it, checked against the
        <a href="{{ route('compliance.rules') }}">rules</a>; what a switch port faces comes from LibreNMS' LLDP/CDP neighbors and spanning tree. Lines of
        the backups are shown with their secrets hidden.</p>

    <p id="cmp-last-run" style="margin: 0 0 8px">
        @if($lastRun)
            <i class="fa fa-clock-o"></i> Last check of every device: {{ Clock::show((int) $lastRun['started']) }}
            @if($lastRunDetails)
            <span class="cmp-muted">({{ Clock::ago(Clock::store((int) $lastRun['started'])) }}; {{ $lastRun['checked'] }} checked, {{ $lastRun['no_backup'] }} without a backup,
                {{ $lastRun['errors'] }} not checked)</span>
            @else
            <span class="cmp-muted">({{ Clock::ago(Clock::store((int) $lastRun['started'])) }})</span>
            @endif
            @if(! empty($lastRun['requested']))
                <span class="cmp-muted">· the settings changed: the next scheduled check checks every device</span>
            @endif
            @if($lastRunDetails && $lastRun['error'])
                <span class="text-danger" id="cmp-last-run-error"><i class="fa fa-exclamation-triangle"></i> {{ $lastRun['error'] }}</span>
            @endif
            @foreach($lastRunDetails ? $lastRun['unreachable'] ?? [] : [] as $why)
                <span class="text-warning"><i class="fa fa-exclamation-triangle"></i> {{ $why }}</span>
            @endforeach
        @else
            <i class="fa fa-clock-o"></i> The devices were not checked yet: the scheduled check runs every hour (or <span class="cmp-code">./lnms compliance:check --force</span>).
        @endif
    </p>

    @if($cards)
        <div class="cmp-cards" id="cmp-cards">
            <a class="cmp-card cmp-info" href="{{ route('compliance.devices') }}">
                <h4>Devices</h4><div class="cmp-card-value">{{ $cards['devices'] }}</div><p>{{ $cards['checked'] }} with a backup in their last check</p>
            </a>
            <a class="cmp-card cmp-ok" href="{{ route('compliance.devices', ['state' => 'compliant']) }}">
                <h4>Compliant</h4><div class="cmp-card-value">{{ $cards['compliant'] }}</div><p>no rule failed</p>
            </a>
            <a class="cmp-card {{ $cards['critical'] ? 'cmp-critical' : ($cards['with_findings'] ? 'cmp-warning' : 'cmp-ok') }}" href="{{ route('compliance.devices', ['state' => 'findings']) }}">
                <h4>With findings</h4><div class="cmp-card-value">{{ $cards['with_findings'] }}</div>
                <p>{{ $cards['critical'] }} critical, {{ $cards['warning'] }} warning, {{ $cards['info'] }} info</p>
            </a>
            <a class="cmp-card cmp-none" href="{{ route('compliance.devices', ['state' => 'no_backup']) }}">
                <h4>No backup</h4><div class="cmp-card-value">{{ $cards['no_backup'] }}</div><p>no backup tool backs them up, or none of them yet</p>
            </a>
            <a class="cmp-card {{ $cards['errors'] ? 'cmp-warning' : 'cmp-none' }}" href="{{ route('compliance.devices', ['state' => 'error']) }}">
                <h4>Not checked</h4><div class="cmp-card-value">{{ $cards['errors'] }}</div><p>their backup could not be read</p>
            </a>
            <a class="cmp-card cmp-none" href="{{ route('compliance.devices', ['state' => 'not_applicable']) }}">
                <h4>No rule applies</h4><div class="cmp-card-value">{{ $cards['not_applicable'] }}</div><p>an OS the rules do not read</p>
            </a>
        </div>
    @endif

    @if($rules)
        <h4 style="margin-top: 4px">Rules</h4>
        <div class="table-responsive">
        <table class="table table-condensed table-hover cmp-table cmp-stack" id="cmp-rule-table">
            <thead><tr><th>Rule</th><th>Severity</th><th class="cmp-num">Fails on</th><th class="cmp-num">Passes on</th><th class="cmp-num">Does not apply to</th><th class="cmp-num">Could not run on</th></tr></thead>
            <tbody>
            @foreach($rules as $id => $stat)
                <tr data-rule="{{ $id }}">
                    <td data-label="Rule" class="cmp-wrap"><a href="{{ route('compliance.rules') }}#rule-{{ $id }}">{{ $stat['rule']->title }}</a>
                        @if($stat['rule']->custom)<span class="cmp-muted">(yours)</span>@endif</td>
                    <td data-label="Severity">@include('compliance::partials.severity', ['severity' => $stat['rule']->severity])</td>
                    <td data-label="Fails on" class="cmp-num">@if($stat['fail'])<a href="{{ route('compliance.overview', ['rule' => $id]) }}#cmp-findings"><strong>{{ $stat['fail'] }}</strong></a>@else 0 @endif</td>
                    <td data-label="Passes on" class="cmp-num">{{ $stat['pass'] }}</td>
                    <td data-label="Does not apply to" class="cmp-num">{{ $stat['na'] }}</td>
                    <td data-label="Could not run on" class="cmp-num">{{ $stat['error'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @endif

    <h4 id="cmp-findings">Findings <span class="cmp-muted" style="font-size: 13px">({{ $findings['total'] }})</span></h4>
    <form method="get" action="{{ route('compliance.overview') }}" class="cmp-filterbar" id="cmp-filters">
        <div><label for="cmp-filter-rule">Rule</label>
            <select class="form-control" id="cmp-filter-rule" name="rule">
                <option value="">every rule</option>
                @foreach($titles as $id => $title)
                    <option value="{{ $id }}" @selected($filters['rule'] === (string) $id)>{{ $title }}</option>
                @endforeach
            </select></div>
        <div><label for="cmp-filter-severity">Severity</label>
            <select class="form-control" id="cmp-filter-severity" name="severity">
                <option value="">every severity</option>
                @foreach(Rule::SEVERITIES as $severity)
                    <option value="{{ $severity }}" @selected($filters['severity'] === $severity)>{{ $severity }}</option>
                @endforeach
            </select></div>
        <div><label for="cmp-filter-device">Device</label>
            <input class="form-control" id="cmp-filter-device" name="device" value="{{ $filters['device'] }}" placeholder="name or hostname" maxlength="200"></div>
        <div><label for="cmp-filter-text">Message</label>
            <input class="form-control" id="cmp-filter-text" name="text" value="{{ $filters['text'] }}" placeholder="text" maxlength="200"></div>
        <div><button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-filter"></i> Filter</button>
            @if($query)<a class="btn btn-sm btn-default" href="{{ route('compliance.overview') }}#cmp-findings">Clear</a>@endif</div>
        <span class="cmp-spacer"></span>
        <div class="cmp-export" style="margin: 0">
            <a class="btn btn-sm btn-default" id="cmp-export-csv" href="{{ route('compliance.export', ['format' => 'csv'] + $query) }}"><i class="fa fa-download"></i> CSV</a>
            <a class="btn btn-sm btn-default" id="cmp-export-json" href="{{ route('compliance.export', ['format' => 'json'] + $query) }}" title="with the lines of the backups"><i class="fa fa-download"></i> JSON</a>
        </div>
    </form>

    @if($findings['rows'])
        <div class="table-responsive">
        <table class="table table-condensed table-hover cmp-table cmp-stack" id="cmp-finding-table">
            <thead><tr><th>Severity</th><th>Device</th><th>Rule</th><th>Finding</th><th>Since</th></tr></thead>
            <tbody>
            @foreach($findings['rows'] as $finding)
                @php($name = $names[$finding['device_id']]['name'] ?? '#' . $finding['device_id'])
                <tr data-device="{{ $finding['device_id'] }}" data-rule="{{ $finding['rule'] }}">
                    <td data-label="Severity">@include('compliance::partials.severity', ['severity' => $finding['severity']])</td>
                    <td data-label="Device" class="cmp-wrap cmp-device-cell"><a href="{{ route('compliance.device', $finding['device_id']) }}#finding-{{ $finding['rule'] }}">{{ $name }}</a></td>
                    <td data-label="Rule" class="cmp-wrap cmp-rule-cell">{{ $titles[$finding['rule']] ?? $finding['rule'] }}</td>
                    <td data-label="Finding" class="cmp-wrap">{{ $finding['message'] }}</td>
                    <td data-label="Since" class="cmp-nowrap">{{ Clock::local($finding['first_seen']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @include('compliance::partials.pager', ['page' => $page, 'pages' => $pages])
    @elseif(! $error)
        <p class="cmp-muted" id="cmp-no-findings">{{ $query ? 'No finding matches the filters.' : ($cards && $cards['devices'] ? 'No finding: every rule passed or did not apply.' : 'No finding yet.') }}</p>
    @endif
</div>
@endsection
