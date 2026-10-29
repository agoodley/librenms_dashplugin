{{-- The rules (ComplianceController::rules): the plugin's, on or off in the settings, with what each checks and how to fix
     a failure; the admin's own rules from the settings, with their patterns; what is wrong with them. --}}
@extends('layouts.librenmsv1')

@section('title', 'Compliance rules')

@push('styles')
    <link href="{{ route('compliance.asset', 'compliance.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    use Agoodley\LibrenmsCompliance\Config\Dialect;
    $servers = ['ntp' => 'ntp_servers', 'syslog' => 'syslog_servers', 'aaa' => 'aaa_servers'];
    $patternLabels = ['when' => 'only sections with a line', 'unless' => 'not sections with a line', 'must' => 'must have a line', 'must_not' => 'must have no line'];
@endphp

@section('content')
<div class="container-fluid cmp-page" id="cmp-rules">
    @include('compliance::partials.nav', ['active' => 'rules'])

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    <p class="cmp-help" style="margin: 0 0 8px">The plugin's rules read the configurations of {{ implode(', ', array_unique(array_map(fn ($d) => Dialect::NAMES[$d] ?? $d, Dialect::OS))) }}
        (LibreNMS os {{ implode(', ', array_keys(Dialect::OS)) }}); they do not apply to other devices. Your own rules apply to every device with a backup, or to the
        os they name.</p>

    <h4>The plugin's rules</h4>
    <div class="table-responsive">
    <table class="table table-condensed cmp-table cmp-stack" id="cmp-builtin-rules">
        <thead><tr><th>Rule</th><th>Severity</th><th>Checks</th><th>Fix</th></tr></thead>
        <tbody>
        @foreach($builtin as $id => $rule)
            <tr id="rule-{{ $id }}" data-rule="{{ $id }}">
                <td data-label="Rule" class="cmp-wrap"><strong>{{ $rule->title }}</strong>
                    <div class="cmp-muted cmp-code">{{ $id }}</div>
                    @if(isset($disabled[$id]))<span class="label label-default" title="in the settings">off</span>@endif</td>
                <td data-label="Severity">@include('compliance::partials.severity', ['severity' => $rule->severity])</td>
                <td data-label="Checks" class="cmp-wrap">{{ $rule->description }}
                    @if(isset($servers[$id]) && $settings)
                        @php($list = $settings->servers($servers[$id]))
                        <div class="cmp-help">{{ $list ? 'The servers of the settings: ' . implode(', ', $list) : 'The settings name no server: one is enough.' }}</div>
                    @endif</td>
                <td data-label="Fix" class="cmp-wrap cmp-help">{{ $rule->fix }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>

    <h4>Your rules</h4>
    @if($customErrors)
        <div class="alert alert-danger" id="cmp-custom-errors"><i class="fa fa-exclamation-triangle"></i> {{ implode(' ', $customErrors) }}</div>
    @endif
    @if($custom)
        <div class="table-responsive">
        <table class="table table-condensed cmp-table cmp-stack" id="cmp-custom-rules">
            <thead><tr><th>Rule</th><th>Severity</th><th>Checks</th><th>Fix</th></tr></thead>
            <tbody>
            @foreach($custom as $id => $rule)
                <tr id="rule-{{ $id }}" data-rule="{{ $id }}">
                    <td data-label="Rule" class="cmp-wrap"><strong>{{ $rule->title }}</strong><div class="cmp-muted cmp-code">{{ $id }}</div></td>
                    <td data-label="Severity">@include('compliance::partials.severity', ['severity' => $rule->severity])</td>
                    <td data-label="Checks" class="cmp-wrap">
                        @if($rule->description !== '')<div>{{ $rule->description }}</div>@endif
                        <div class="cmp-help">
                            {{ $rule->spec['os'] ? 'os ' . implode(', ', $rule->spec['os']) : 'every os' }};
                            {{ $rule->spec['section'] !== null ? 'each section ' . $rule->spec['section'] : 'the lines at the left margin' }}
                            @if($rule->spec['ports'] !== null)(interfaces facing {{ $rule->spec['ports'] }})@endif
                        </div>
                        @foreach($patternLabels as $key => $label)
                            @if($rule->spec[$key])
                                <div class="cmp-help">{{ $label }}: <span class="cmp-code">{{ implode('  ·  ', array_keys($rule->spec[$key])) }}</span></div>
                            @endif
                        @endforeach
                    </td>
                    <td data-label="Fix" class="cmp-wrap cmp-help">{{ $rule->fix }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @else
        <p class="cmp-muted" id="cmp-no-custom">None yet: an admin writes them in YAML on the
            @if(\Agoodley\LibrenmsCompliance\Support\Access::isAdmin(auth()->user()))<a href="{{ url('plugin/settings/compliance') }}#cmp-custom">settings page</a>@else settings page @endif.</p>
    @endif
</div>
@endsection
