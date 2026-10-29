{{-- Diagnostic runs (DiagnosticController::index): for users with the run permission, a template, the devices and the
     answers, then Run (a run in check mode, --check --diff, of no change: its page shows what each task printed per
     device); for every admin, the latest diagnostic runs. The device list and the launch are made by
     resources/assets/ansible.js from the plugin's JSON; every check is made again on the server at the launch. --}}
@extends('layouts.librenmsv1')

@section('title', 'Ansible diagnostics')

@push('styles')
    <link href="{{ route('ansible.asset', 'ansible.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $usableTemplate = $template !== null && ! $templateReasons;
    // a diagnostic run again: the earlier run's answers (not the secret ones) filled in
    $answer = fn ($var) => $prefill !== null && array_key_exists($var->name, $prefill['vars']) ? $prefill['vars'][$var->name] : $var->default;
    $typeLabels = ['string' => 'text', 'text' => 'several lines', 'int' => 'whole number', 'enum' => 'one of a list', 'secret' => 'secret'];
@endphp

@section('content')
<div class="container-fluid ans-page" id="ans-diagnostics">
    @include('ansible::partials.nav', ['active' => 'diagnostics'])

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    <p class="ans-help" style="margin: 0 0 6px" id="ans-diagnostics-intro">A diagnostic run runs a template on the devices you choose in check mode
        (<span class="ans-code">--check --diff</span>), as a change's dry run, but as no change: no approval, batches, backups or maintenance windows. Use it to read
        what the devices answer (show commands, printed with a debug task) or what a template would change on them. Its log must prove that every task ran in check
        mode, as a dry run's: one that is not proven may have changed its devices, which its page and the devices' eventlog say in red. Its page shows what each task
        printed, the devices that printed the same grouped together.</p>

    @if($canRun)
        @if($runnerError)
            <div class="alert alert-danger" id="ans-runner-error"><i class="fa fa-exclamation-triangle"></i> {{ $runnerError }}
                <a href="{{ url('plugin/settings/ansible') }}">Settings</a></div>
        @endif
        @if($blocked)
            <div class="alert alert-danger" id="ans-launch-blocked"><i class="fa fa-ban"></i>
                @if(count($blocked) === 1){{ $blocked[0] }}@else<ul style="margin: 0; padding-left: 18px">@foreach($blocked as $reason)<li>{{ $reason }}</li>@endforeach</ul>@endif
                <a href="{{ url('plugin/settings/ansible') }}">Settings</a></div>
        @endif
        @if($prefill)
            <div class="alert alert-info" id="ans-prefill-note"><i class="fa fa-repeat"></i> Again as
                <a href="{{ route('ansible.runs.show', $prefill['run_id']) }}">run #{{ (int) $prefill['run_id'] }}</a>: its template, devices and answers are filled in
                (secret answers are asked again).</div>
        @endif

        <h4 class="ans-step"><span class="badge">1</span> Template</h4>
        <form method="get" action="{{ route('ansible.diagnostics') }}" class="form-inline" id="ans-template-form">
            {{-- the devices chosen (a device's panel, the map) stay chosen with another template --}}
            @if($devices)<input type="hidden" name="devices" value="{{ implode(',', $devices) }}">@endif
            @if($prefill)<input type="hidden" name="from" value="{{ $prefill['run_id'] }}">@endif
            <select name="template" class="form-control input-sm" id="ans-template" style="min-width: 320px" onchange="this.form.submit()">
                <option value="">Choose a template…</option>
                @foreach($usable as $option)
                    <option value="{{ $option->id }}" @selected($template && $template->id === $option->id)>{{ $option->name }} ({{ $option->playbook }})</option>
                @endforeach
                @if($template && $templateReasons)
                    <option value="{{ $template->id }}" selected>{{ $template->name }} (cannot be used)</option>
                @endif
            </select>
            <noscript><button type="submit" class="btn btn-sm btn-default">Choose</button></noscript>
        </form>
        @if(! $runnerError && ! $usable && ! $template)
            <div class="alert alert-warning" style="margin-top: 8px">No template of the runner can be used for diagnostic runs: change them in Semaphore UI as the reasons below say.</div>
        @endif
        @if($unusable)
            <details style="margin-top: 8px" id="ans-unusable" @if(! $usable) open @endif>
                <summary class="text-muted">{{ \Agoodley\LibrenmsAnsible\Support\Format::count(count($unusable), 'template') }} the plugin cannot use for diagnostic runs, and why</summary>
                <table class="table table-condensed ans-table" style="margin-top: 6px">
                    @foreach($unusable as $item)
                        <tr data-template="{{ $item['template']->id }}"><th style="width: 30%" class="ans-wrap">{{ $item['template']->name }} <span class="ans-muted ans-code">{{ $item['template']->playbook }}</span></th>
                            <td><ul style="margin: 0; padding-left: 16px">@foreach($item['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul></td></tr>
                    @endforeach
                </table>
            </details>
        @endif
        @if($template && $templateReasons)
            <div class="alert alert-danger" style="margin-top: 8px" id="ans-template-refused"><strong>{{ $template->name }} cannot be used for diagnostic runs:</strong>
                <ul style="margin: 4px 0 0">@foreach($templateReasons as $reason)<li>{{ $reason }}</li>@endforeach</ul></div>
        @endif

        @if($usableTemplate)
            @if($template->description)
                <p class="ans-help" style="margin-top: 6px">{{ $template->description }}</p>
            @endif
            @foreach($templateNotes as $note)
                <div class="alert alert-info" style="margin-top: 8px; padding: 6px 10px"><i class="fa fa-info-circle"></i> {{ $template->name }} {{ $note }}.</div>
            @endforeach

            <h4 class="ans-step"><span class="badge">2</span> Devices <small class="text-muted">at most {{ $maxTargets }}</small></h4>
            <div class="ans-filterbar" id="ans-target-filters">
                <label>Device group
                    <select class="form-control" id="ans-filter-group">
                        <option value="">All</option>
                        @foreach($groups as $groupId => $name)<option value="{{ $groupId }}">{{ $name }}</option>@endforeach
                    </select>
                </label>
                <label>Location
                    <select class="form-control" id="ans-filter-location" style="max-width: 220px">
                        <option value="">All</option>
                        @foreach($locations as $locationId => $name)<option value="{{ $locationId }}">{{ $name }}</option>@endforeach
                    </select>
                </label>
                <label>Platform / os
                    <select class="form-control" id="ans-filter-os"><option value="">All</option></select>
                </label>
                <label>Search <input type="search" class="form-control" id="ans-filter-text" placeholder="name, hostname, os"></label>
                <label style="padding-bottom: 4px"><input type="checkbox" id="ans-filter-eligible" checked> only devices that can be targets</label>
                <span class="ans-spacer"></span>
                <button type="button" class="btn btn-xs btn-default" id="ans-select-shown"><i class="fa fa-check-square-o"></i> Select shown</button>
                <button type="button" class="btn btn-xs btn-default" id="ans-select-none"><i class="fa fa-square-o"></i> Clear</button>
            </div>
            <p class="ans-muted" id="ans-targets-status">Loading the devices…</p>
            <div class="ans-targets-wrap">
                <table class="table table-condensed table-hover ans-table ans-targets" id="ans-targets">
                    <thead><tr><th style="width: 28px"></th><th>Device</th><th>Can it be a target?</th><th>Inventory name</th><th class="hidden-xs">OS</th><th>Platform</th><th class="hidden-xs">Status</th><th class="hidden-xs">Location</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>

            <h4 class="ans-step"><span class="badge">3</span> Survey answers</h4>
            <form id="ans-vars" class="form-horizontal" autocomplete="off" onsubmit="return false">
                @forelse($template->surveyVars as $var)
                    <div class="form-group" data-var="{{ $var->name }}">
                        <label class="col-sm-3 control-label" for="ans-var-{{ $loop->index }}" style="font-weight: normal">{{ $var->label() }}@if($var->required) <span class="text-danger" title="required">*</span>@endif
                            <br><span class="ans-code ans-muted">{{ $var->name }}{{ $var->target === 'env' ? ' (environment)' : '' }}</span></label>
                        <div class="col-sm-7">
                            @switch($var->type)
                                @case('text')
                                    <textarea class="form-control input-sm ans-var" id="ans-var-{{ $loop->index }}" name="{{ $var->name }}" rows="4" maxlength="10000">{{ $answer($var) }}</textarea>
                                    @break
                                @case('enum')
                                    <select class="form-control input-sm ans-var" id="ans-var-{{ $loop->index }}" name="{{ $var->name }}">
                                        @unless($var->required)<option value="">(none)</option>@endunless
                                        @foreach($var->values as $value)
                                            <option value="{{ $value['value'] }}" @selected((string) $answer($var) === (string) $value['value'])>{{ $value['name'] }}</option>
                                        @endforeach
                                    </select>
                                    @break
                                @case('secret')
                                    {{-- sent with the launch to the runner only (Semaphore's write-only secret field), never stored --}}
                                    <input type="password" class="form-control input-sm ans-var" id="ans-var-{{ $loop->index }}" name="{{ $var->name }}" autocomplete="new-password" maxlength="1000">
                                    @break
                                @case('int')
                                    <input type="text" inputmode="numeric" class="form-control input-sm ans-var" id="ans-var-{{ $loop->index }}" name="{{ $var->name }}" value="{{ $answer($var) }}" style="max-width: 160px">
                                    @break
                                @default
                                    <input type="text" class="form-control input-sm ans-var" id="ans-var-{{ $loop->index }}" name="{{ $var->name }}" value="{{ $answer($var) }}" maxlength="1000">
                            @endswitch
                            <p class="ans-help">{{ $typeLabels[$var->type] ?? $var->type }}{{ $var->description !== '' ? ' · ' . $var->description : '' }}</p>
                            <div class="ans-error-text ans-var-error"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-muted">This template has no survey variables.</p>
                @endforelse
            </form>

            <h4 class="ans-step"><span class="badge">4</span> Run</h4>
            <p class="ans-help">The run starts at once, in check mode; its page follows it and then shows what each task printed per device.</p>
            <div class="ans-launchbar">
                <button type="button" class="btn btn-primary btn-sm" id="ans-diagnostic-run" disabled><i class="fa fa-play"></i> Run in check mode</button>
                <span class="ans-muted" id="ans-launch-state"></span>
            </div>
            <div id="ans-diagnostic-errors"></div>

            @php
                $config = [
                    'targets' => route('ansible.api.targets', ['for' => 'diagnostic']),
                    'store' => route('ansible.diagnostics.store'),
                    'template' => $template->id,
                    'requestToken' => $requestToken,
                    'devices' => $devices,
                    'maxTargets' => $maxTargets,
                    'deviceUrl' => url('device'),
                ];
            @endphp
            {{-- @json() splits its argument on commas: it gets one variable --}}
            <script type="application/json" id="ans-diagnostics-config">@json($config)</script>
        @endif
    @endif

    <div class="panel panel-default" style="margin-top: 16px">
        <div class="panel-heading"><strong>Latest diagnostic runs</strong>
            <a class="pull-right" href="{{ route('ansible.runs', ['kind' => 'diagnostic']) }}" id="ans-diagnostics-all">All diagnostic runs</a></div>
        <div class="table-responsive">
        <table class="table table-condensed table-hover ans-table" id="ans-diagnostics-latest">
            <thead><tr><th>#</th><th>Started</th><th>User</th><th>Template</th><th>Devices</th><th>Status</th></tr></thead>
            <tbody>
            @forelse($latest as $run)
                <tr data-run="{{ $run['id'] }}">
                    <td><a href="{{ route('ansible.runs.show', $run['id']) }}">#{{ $run['id'] }}</a></td>
                    <td style="white-space: nowrap">{{ \Agoodley\LibrenmsAnsible\Support\Clock::local($run['started_at'] ?? $run['created_at']) }}</td>
                    <td>{{ $run['username'] }}</td>
                    <td class="ans-wrap">{{ $run['template_name'] }}</td>
                    <td><span class="ans-wrap">{{ implode(', ', $run['target_names']) }}{{ $run['targets'] > count($run['target_names']) ? ' and ' . ($run['targets'] - count($run['target_names'])) . ' more' : '' }}</span>
                        @if($run['summary'] || \Agoodley\LibrenmsAnsible\Runs\RunResults::unproven($run))
                            <br>@include('ansible::partials.results', ['resultSummary' => $run['summary'] ?? [], 'resultDryRun' => true,
                                'resultUnproven' => \Agoodley\LibrenmsAnsible\Runs\RunResults::unproven($run)])
                        @endif</td>
                    <td><span class="label label-{{ \Agoodley\LibrenmsAnsible\Runs\RunStatus::css($run['status']) }}">{{ \Agoodley\LibrenmsAnsible\Runs\RunStatus::label($run['status']) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted">No diagnostic run yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
@endsection

@section('scripts')
    @if($canRun && $usableTemplate)
        <script src="{{ route('ansible.asset', 'ansible.js') }}?v={{ $assetVersion }}"></script>
        <script>AnsiblePlugin.diagnostics(JSON.parse(document.getElementById('ans-diagnostics-config').textContent));</script>
    @endif
@endsection
