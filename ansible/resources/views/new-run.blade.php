{{-- New run (LaunchController::create): template, targets, survey answers, preview, launch. The targets list and
     the preview are filled by resources/assets/ansible.js from the plugin's JSON; every check is made again on the
     server at the preview and at the launch. --}}
@extends('layouts.librenmsv1')

@section('title', 'New Ansible run')

@push('styles')
    <link href="{{ route('ansible.asset', 'ansible.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $usableTemplate = $template !== null && ! $templateReasons;
    $typeLabels = ['string' => 'text', 'text' => 'several lines', 'int' => 'whole number', 'enum' => 'one of a list', 'secret' => 'secret'];
@endphp

@section('content')
<div class="container-fluid ans-page" id="ans-new-run">
    @include('ansible::partials.nav', ['active' => 'new'])

    @if($runnerError)
        <div class="alert alert-danger" id="ans-runner-error"><i class="fa fa-exclamation-triangle"></i> {{ $runnerError }}
            <a href="{{ url('plugin/settings/ansible') }}">Settings</a></div>
    @endif
    @if($blocked)
        <div class="alert alert-danger" id="ans-launch-blocked"><i class="fa fa-ban"></i> {{ $blocked }}
            <a href="{{ url('plugin/settings/ansible') }}">Settings</a></div>
    @endif

    <h4 class="ans-step"><span class="badge">1</span> Template</h4>
    <form method="get" action="{{ route('ansible.runs.new') }}" class="form-inline" id="ans-template-form">
        @if($device)<input type="hidden" name="device" value="{{ $device }}">@endif
        <select name="template" class="form-control input-sm" id="ans-template" style="min-width: 320px" onchange="this.form.submit()">
            <option value="">Choose a template…</option>
            @foreach($usable as $option)
                <option value="{{ $option->id }}" @selected($template && $template->id === $option->id)>{{ $option->name }} ({{ $option->playbook }})</option>
            @endforeach
            @if($template && $templateReasons)
                <option value="{{ $template->id }}" selected>{{ $template->name }} (cannot be launched)</option>
            @endif
        </select>
        <noscript><button type="submit" class="btn btn-sm btn-default">Choose</button></noscript>
    </form>
    @if(! $runnerError && ! $usable)
        <div class="alert alert-warning" style="margin-top: 8px">No template of the runner can be launched by the plugin: change them in Semaphore UI as the reasons below say.</div>
    @endif
    @if($unusable)
        <details style="margin-top: 8px" id="ans-unusable" @if(! $usable) open @endif>
            <summary class="text-muted">{{ \Agoodley\LibrenmsAnsible\Support\Format::count(count($unusable), 'template') }} the plugin cannot launch, and why</summary>
            <table class="table table-condensed ans-table" style="margin-top: 6px">
                @foreach($unusable as $item)
                    <tr data-template="{{ $item['template']->id }}"><th style="width: 30%" class="ans-wrap">{{ $item['template']->name }} <span class="ans-muted ans-code">{{ $item['template']->playbook }}</span></th>
                        <td><ul style="margin: 0; padding-left: 16px">@foreach($item['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul></td></tr>
                @endforeach
            </table>
        </details>
    @endif

    @if($template && $templateReasons)
        <div class="alert alert-danger" style="margin-top: 8px" id="ans-template-refused"><strong>{{ $template->name }} cannot be launched safely:</strong>
            <ul style="margin: 4px 0 0">@foreach($templateReasons as $reason)<li>{{ $reason }}</li>@endforeach</ul></div>
    @endif

    @if($usableTemplate)
        @if($template->description)
            <p class="ans-help" style="margin-top: 6px">{{ $template->description }}</p>
        @endif
        @foreach($templateNotes as $note)
            <div class="alert alert-info" style="margin-top: 8px; padding: 6px 10px"><i class="fa fa-info-circle"></i> {{ $template->name }} {{ $note }}.</div>
        @endforeach

        <h4 class="ans-step"><span class="badge">2</span> Targets <small class="text-muted">at most {{ $maxTargets }} per run</small></h4>
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
                <thead><tr><th style="width: 28px"></th><th>Device</th><th>Inventory name</th><th>OS</th><th>Platform</th><th>Status</th><th>Location</th><th>Can it be a target?</th></tr></thead>
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
                                <textarea class="form-control input-sm ans-var" id="ans-var-{{ $loop->index }}" name="{{ $var->name }}" rows="4" maxlength="10000">{{ $var->default }}</textarea>
                                @break
                            @case('enum')
                                <select class="form-control input-sm ans-var" id="ans-var-{{ $loop->index }}" name="{{ $var->name }}">
                                    @unless($var->required)<option value="">(none)</option>@endunless
                                    @foreach($var->values as $value)
                                        <option value="{{ $value['value'] }}" @selected($var->default === $value['value'])>{{ $value['name'] }}</option>
                                    @endforeach
                                </select>
                                @break
                            @case('secret')
                                <input type="password" class="form-control input-sm ans-var" id="ans-var-{{ $loop->index }}" name="{{ $var->name }}" maxlength="1000" autocomplete="new-password">
                                @break
                            @case('int')
                                <input type="text" inputmode="numeric" class="form-control input-sm ans-var" id="ans-var-{{ $loop->index }}" name="{{ $var->name }}" value="{{ $var->default }}" style="max-width: 160px">
                                @break
                            @default
                                <input type="text" class="form-control input-sm ans-var" id="ans-var-{{ $loop->index }}" name="{{ $var->name }}" value="{{ $var->default }}" maxlength="1000">
                        @endswitch
                        <p class="ans-help">{{ $typeLabels[$var->type] ?? $var->type }}{{ $var->description !== '' ? ' · ' . $var->description : '' }}{{ $var->isSecret() ? ' · sent to the runner only, never stored or shown' : '' }}</p>
                        <div class="ans-error-text ans-var-error"></div>
                    </div>
                </div>
            @empty
                <p class="text-muted">This template has no survey variables.</p>
            @endforelse
        </form>

        <h4 class="ans-step"><span class="badge">4</span> Preview and launch</h4>
        <p class="ans-help">The preview shows exactly what will be launched. The launch checks everything again against the runner.</p>
        <div class="ans-launchbar">
            <button type="button" class="btn btn-primary btn-sm" id="ans-preview-button"><i class="fa fa-eye"></i> Preview</button>
            <button type="button" class="btn btn-success btn-sm" id="ans-launch-button" disabled><i class="fa fa-play"></i> Launch</button>
            <span class="ans-muted" id="ans-launch-state"></span>
        </div>
        <div id="ans-preview" class="ans-preview"></div>

        @php
            $config = [
                'targets' => route('ansible.api.targets'),
                'preview' => route('ansible.api.preview'),
                'store' => route('ansible.runs.store'),
                'template' => $template->id,
                'requestToken' => $requestToken,
                'device' => $device,
                'maxTargets' => $maxTargets,
                'deviceUrl' => url('device'),
            ];
        @endphp
        {{-- @json() splits its argument on commas: it gets one variable --}}
        <script type="application/json" id="ans-new-run-config">@json($config)</script>
    @endif
</div>
@endsection

@section('scripts')
    @if($usableTemplate)
        <script src="{{ route('ansible.asset', 'ansible.js') }}?v={{ $assetVersion }}"></script>
        <script>AnsiblePlugin.newRun(JSON.parse(document.getElementById('ans-new-run-config').textContent));</script>
    @endif
@endsection
