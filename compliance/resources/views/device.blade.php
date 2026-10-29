{{-- A device's results (ComplianceController::device): its last check (of which backup, when), Check now for admins, its
     findings with the lines of the backup that show them (secrets hidden) and how to fix them, each rule's outcome, and
     its switch ports as the backup configures them and what LibreNMS says they face. --}}
@extends('layouts.librenmsv1')

@section('title', 'Compliance: ' . $device['name'])

@push('styles')
    <link href="{{ route('compliance.asset', 'compliance.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    use Agoodley\LibrenmsCompliance\Data\ResultRepository;
    use Agoodley\LibrenmsCompliance\Rules\PortRoles;
    use Agoodley\LibrenmsCompliance\Support\Clock;
    $statusLabels = [ResultRepository::CHECKED => ['checked', 'success'], 'no_backup' => ['no backup', 'default'], 'error' => ['not checked', 'warning']];
    $outcomeLabels = ['fail' => 'failed', 'pass' => 'passed', 'na' => 'does not apply', 'error' => 'could not run'];
    $roles = [PortRoles::HOST => 'a host', PortRoles::SWITCH => 'a switch', PortRoles::UPSTREAM => 'toward the root bridge (its root port)',
        PortRoles::DOWNSTREAM => 'a switch below (whose root port faces it)'];
    $portfast = ['on' => 'on', 'default' => 'on (global default)', 'auto' => 'automatic (EOS)', 'off' => 'off'];
    $bpduguard = ['on' => 'on', 'default' => 'on (global default)', 'off' => 'off'];
    $deviceUrl = url('device/device=' . $id . '/');
@endphp

@section('content')
<div class="container-fluid cmp-page" id="cmp-device" data-device="{{ $id }}">
    @include('compliance::partials.nav', ['active' => 'device', 'deviceName' => $device['name']])

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif
    @if($flash)
        <div class="alert alert-{{ in_array($flash[0] ?? '', ['success', 'warning', 'danger'], true) ? $flash[0] : 'info' }}" id="cmp-flash">{{ $flash[1] ?? '' }}</div>
    @endif

    <div class="panel panel-default cmp-device-head">
        <div class="panel-heading">
            <strong><a href="{{ $deviceUrl }}">{{ $device['name'] }}</a></strong>
            @if($row)
                @php([$label, $css] = $statusLabels[$row['status']] ?? [$row['status'], 'default'])
                <span class="label label-{{ $css }}" id="cmp-status">{{ $label }}</span>
            @endif
            @if($canCheck)
                <form method="post" action="{{ route('compliance.device.check', $id) }}" class="pull-right" style="margin: -2px 0 0" id="cmp-check-form">
                    @csrf
                    <button type="submit" class="btn btn-xs btn-primary" id="cmp-check-now" title="read the latest backup and check it now"><i class="fa fa-refresh"></i> Check now</button>
                </form>
            @endif
        </div>
        <div class="panel-body">
            <dl class="dl-horizontal">
                @if($row)
                    <dt>Checked</dt><dd>{{ Clock::local($row['checked_at']) }} <span class="cmp-muted">({{ Clock::ago($row['checked_at']) }})</span></dd>
                    @if($row['status'] !== ResultRepository::CHECKED)
                        <dt>Last check</dt><dd class="{{ $row['status'] === 'error' ? 'text-danger' : '' }}" id="cmp-device-error">{{ $row['error'] }}
                            @if($row['outcomes'])<div class="cmp-help">The results below are of the check before, of the backup it read.</div>@endif</dd>
                    @endif
                    @if($row['backup_at'] || $row['provider'])
                        <dt>Backup</dt><dd>@if($row['backup_at']){{ Clock::local($row['backup_at']) }}@endif @if($row['provider'])<span class="cmp-muted">from {{ $row['provider'] }}</span>@endif
                            <a href="{{ url('device/device=' . $id . '/tab=showconfig/') }}" class="cmp-help" style="margin-left: 6px">the Config tab</a></dd>
                    @endif
                    <dt>OS</dt><dd class="cmp-code">{{ $row['os'] ?: $device['os'] }}</dd>
                @else
                    <dt>Checked</dt><dd id="cmp-never">never{{ $covered ? '' : ': the plugin\'s settings do not cover this device' }}</dd>
                @endif
                @if(! $covered && $row)
                    <dt>Checks</dt><dd class="cmp-muted">The plugin's settings no longer cover this device (disabled, or not of their device group): its results go at the next check of every device.</dd>
                @endif
            </dl>
        </div>
    </div>

    <h4 id="cmp-findings">Findings <span class="cmp-muted" style="font-size: 13px">({{ count($findings) }})</span></h4>
    @forelse($findings as $rule => $finding)
        <div class="cmp-finding cmp-{{ $finding['severity'] }}" id="finding-{{ $rule }}">
            <h4>@include('compliance::partials.severity', ['severity' => $finding['severity']]) {{ $titles[$rule] ?? $rule }}
                <span class="cmp-muted" style="font-size: 12px">since {{ Clock::local($finding['first_seen']) }}</span></h4>
            <p>{{ $finding['message'] }}</p>
            @if($finding['evidence'])
                <details @if(count($finding['evidence']) <= 12) open @endif>
                    <summary class="cmp-help">The backup's {{ count($finding['evidence']) === 1 ? 'line' : count($finding['evidence']) . ' lines' }}</summary>
                    <pre class="cmp-evidence">@foreach($finding['evidence'] as [$number, $text])<span class="cmp-lineno">{{ $number }}</span>{{ $text }}
@endforeach</pre>
                </details>
            @endif
            @if(isset($rules[$rule]) && $rules[$rule]->fix !== '')
                <p class="cmp-help"><strong>Fix:</strong> {{ $rules[$rule]->fix }}</p>
            @endif
        </div>
    @empty
        <p class="cmp-muted" id="cmp-no-findings">{{ $row && $row['outcomes'] ? 'No finding: every rule passed or did not apply.' : 'No finding.' }}</p>
    @endforelse

    @if($outcomes)
        <h4>Rules</h4>
        <div class="table-responsive">
        <table class="table table-condensed cmp-table cmp-stack" id="cmp-outcomes">
            <thead><tr><th>Rule</th><th>Severity</th><th>Outcome</th><th>Why</th></tr></thead>
            <tbody>
            @foreach($outcomes as $outcome)
                <tr data-rule="{{ $outcome['rule'] }}" data-status="{{ $outcome['status'] }}">
                    <td data-label="Rule" class="cmp-wrap"><a href="{{ route('compliance.rules') }}#rule-{{ $outcome['rule'] }}">{{ $outcome['title'] }}</a></td>
                    <td data-label="Severity">@if($outcome['severity'])@include('compliance::partials.severity', ['severity' => $outcome['severity']])@endif</td>
                    <td data-label="Outcome"><span class="label cmp-st-{{ $outcome['status'] }}">{{ $outcomeLabels[$outcome['status']] ?? $outcome['status'] }}</span></td>
                    <td data-label="Why" class="cmp-wrap">@if($outcome['status'] === 'fail')<a href="#finding-{{ $outcome['rule'] }}">the finding</a>@else{{ $outcome['why'] }}@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @endif

    @if($ports)
        <h4>Switch ports</h4>
        <p class="cmp-help" style="margin: -4px 0 6px">As the backup configures them; what each faces comes from LibreNMS' LLDP/CDP neighbors and spanning tree (a port without a
            neighbor LibreNMS knows faces a host).</p>
        <div class="table-responsive">
        <table class="table table-condensed table-hover cmp-table cmp-stack" id="cmp-ports">
            <thead><tr><th>Port</th><th>Mode</th><th>Portfast</th><th>BPDU guard</th><th>Root guard</th><th>Faces</th></tr></thead>
            <tbody>
            @foreach($ports as $port)
                <tr data-port="{{ $port['name'] }}" class="{{ $port['shutdown'] ? 'cmp-muted' : '' }}">
                    <td data-label="Port" class="cmp-wrap">@if($port['port_id'] !== null && isset($portIds[$port['port_id']]))<a href="{{ url('device/device=' . $id . '/tab=port/port=' . $port['port_id'] . '/') }}">{{ $port['name'] }}</a>@else{{ $port['name'] }}@endif
                        @if($port['shutdown'])<span class="cmp-muted">(shut down)</span>@endif
                        @if($port['member'] !== null)<span class="cmp-muted">(in port-channel {{ $port['member'] }})</span>@endif</td>
                    <td data-label="Mode">{{ $port['mode'] }}</td>
                    <td data-label="Portfast">{{ $portfast[$port['portfast']] ?? $port['portfast'] }}</td>
                    <td data-label="BPDU guard">{{ $bpduguard[$port['bpduguard']] ?? $port['bpduguard'] }}</td>
                    <td data-label="Root guard">{{ $port['rootguard'] ? 'on' : 'off' }}</td>
                    <td data-label="Faces" class="cmp-wrap">{{ $roles[$port['role']] ?? $port['role'] }}@if($port['neighbors'])<div class="cmp-muted" style="font-size: 12px">{{ implode(', ', array_slice($port['neighbors'], 0, 4)) }}{{ count($port['neighbors']) > 4 ? ' …' : '' }}</div>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>
@endsection
