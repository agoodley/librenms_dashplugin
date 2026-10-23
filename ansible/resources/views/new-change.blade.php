{{-- New change (ChangeController::create): template, targets (and the first batch), the answers that are not secret,
     a reference, the preview with the batch plan, and Create. Nothing runs yet: the change page dry-runs it. The
     targets list and the preview are filled by resources/assets/ansible.js from the plugin's JSON; every check is
     made again on the server at the preview, at the creation, and at the dry run and each batch. --}}
@extends('layouts.librenmsv1')

@section('title', 'New Ansible change')

@push('styles')
    <link href="{{ route('ansible.asset', 'ansible.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $usableTemplate = $template !== null && ! $templateReasons;
    // a new change made from an earlier one: its answers (not the secret ones) and reference filled in
    $answer = fn ($var) => $prefill !== null && array_key_exists($var->name, $prefill['vars']) ? $prefill['vars'][$var->name] : $var->default;
    // a change made from fixes (the Fixes page): its template, devices and the lines of its fix variable (read only) are the fix's
    $fixVar = $fix !== null ? ($fix['variable'] ?? null) : null;
    $fixReference = $fix !== null && ($fix['title'] ?? '') !== '' ? mb_substr('Fix: ' . $fix['title'], 0, 120) : null;
    $typeLabels = ['string' => 'text', 'text' => 'several lines', 'int' => 'whole number', 'enum' => 'one of a list', 'secret' => 'secret'];
@endphp

@section('content')
<div class="container-fluid ans-page" id="ans-new-change">
    @include('ansible::partials.nav', ['active' => 'new'])

    @if($runnerError)
        <div class="alert alert-danger" id="ans-runner-error"><i class="fa fa-exclamation-triangle"></i> {{ $runnerError }}
            <a href="{{ url('plugin/settings/ansible') }}">Settings</a></div>
    @endif
    @if($blocked)
        <div class="alert alert-danger" id="ans-launch-blocked"><i class="fa fa-ban"></i>
            @if(count($blocked) === 1){{ $blocked[0] }}@else<ul style="margin: 0; padding-left: 18px">@foreach($blocked as $reason)<li>{{ $reason }}</li>@endforeach</ul>@endif
            <a href="{{ url('plugin/settings/ansible') }}">Settings</a></div>
    @endif

    @php
        // what the settings make of a change: its approval, its batches, and the windows and backups that are on
        $approvalText = [\Agoodley\LibrenmsAnsible\Changes\ApprovalRules::OFF => 'then, as the settings ask for no approval, applied',
            \Agoodley\LibrenmsAnsible\Changes\ApprovalRules::SECOND => 'approved by a second admin (not the requester) with the approve permission, then applied'][$changeSettings->approval]
            ?? 'approved by an admin with the approve permission, then applied';
        $around = array_filter([$changeSettings->maintenance ? 'in a LibreNMS maintenance window' . ($topologyWhy === null ? ' that also covers the devices it cuts off' : '') : null,
            $changeSettings->backups && $oxidized ? 'with Oxidized backups before and after it' : null]);
    @endphp
    <p class="ans-help" style="margin: 0 0 6px" id="ans-new-change-intro">A change is dry-run first (<span class="ans-code">--check --diff</span>, with the diff per device),
        {{ $approvalText }} in batches ({{ $planText }}){{ $around ? ', each batch ' . implode(', ', $around) : '' }}.
        @if($topologyWhy === null)
            <span id="ans-new-change-topology">The topology plugin plans the batches: farthest from the core first, the core last, never two devices that back each other up in one
                batch, the first batch taken from its first one; the preview shows what each batch cuts off while it runs, which is approved with the change and checked again
                before the batch starts.</span>
        @else
            <span id="ans-new-change-no-topology">The topology plugin does not plan the batches ({{ $topologyWhy }}): the blast radius of each batch is unknown.</span>
        @endif
    </p>

    @if($fix)
        <div class="alert alert-{{ $fix['errors'] ? 'danger' : 'info' }}" id="ans-fix-note"><i class="fa fa-wrench"></i>
            <strong>A change made from {{ count($fix['ids'] ?? []) === 1 ? 'a fix' : count($fix['ids'] ?? []) . ' fixes' }} of the topology plugin ({{ strtolower($fix['label'] ?? '') }}):</strong>
            {{ $fix['title'] ?? '' }}.
            @if(($fix['effect'] ?? '') !== '')<div style="margin-top: 4px" id="ans-fix-effect">{{ $fix['effect'] }}</div>@endif
            <div style="margin-top: 4px">The template, the devices and the answer of <span class="ans-code">{{ $fixVar }}</span> are the fix's: computed from the findings as you
                see them now, read only; the change is created only if they are still what this page shows. <a href="{{ route('ansible.fixes') }}">Back to the Fixes page</a>.</div>
            @if($fix['errors'])
                <ul style="margin: 4px 0 0" id="ans-fix-errors">@foreach($fix['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>
            @endif
        </div>
    @endif
    @if($prefill)
        <div class="alert alert-info" id="ans-prefill-note"><i class="fa fa-copy"></i> A new change from
            <a href="{{ route('ansible.changes.show', $prefill['from']) }}">change #{{ (int) $prefill['from'] }}</a>, {{ $prefill['text'] }}: its template,
            answers, reference and first batch are filled in, its devices chosen and listed first. Not a resume: it is dry-run and approved again; secret answers
            are asked again.
            @if($prefill['moved'] ?? [])
                <div style="margin-top: 4px" class="text-warning" id="ans-prefill-moved"><i class="fa fa-exclamation-triangle"></i>
                    How the runner reaches {{ count($prefill['moved']) === 1 ? 'a device' : 'devices' }} changed since change #{{ (int) $prefill['from'] }}
                    ({{ implode('; ', $prefill['moved']) }}): check that it is intended.</div>
            @endif
            @if($prefill['left_out'])
                <div style="margin-top: 4px" id="ans-prefill-left-out">Left out, they cannot be targets yet:
                    <ul style="margin: 2px 0 0">@foreach($prefill['left_out'] as $why)<li>{{ $why }}</li>@endforeach</ul></div>
            @endif
        </div>
    @endif

    <h4 class="ans-step"><span class="badge">1</span> Template</h4>
    <form method="get" action="{{ route('ansible.changes.new') }}" class="form-inline" id="ans-template-form">
        @if($device)<input type="hidden" name="device" value="{{ $device }}">@endif
        @if($prefill)<input type="hidden" name="from" value="{{ $prefill['from'] }}"><input type="hidden" name="devices" value="{{ $prefill['kind'] }}">@endif
        <select name="template" class="form-control input-sm" id="ans-template" style="min-width: 320px" onchange="this.form.submit()" @if($fix) disabled title="the template the settings map to this kind of fix" @endif>
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
    @if(! $runnerError && ! $usable)
        <div class="alert alert-warning" style="margin-top: 8px">No template of the runner can be used for changes: change them in Semaphore UI as the reasons below say.</div>
    @endif
    @if($unusable)
        <details style="margin-top: 8px" id="ans-unusable" @if(! $usable) open @endif>
            <summary class="text-muted">{{ \Agoodley\LibrenmsAnsible\Support\Format::count(count($unusable), 'template') }} the plugin cannot use for changes, and why</summary>
            <table class="table table-condensed ans-table" style="margin-top: 6px">
                @foreach($unusable as $item)
                    <tr data-template="{{ $item['template']->id }}"><th style="width: 30%" class="ans-wrap">{{ $item['template']->name }} <span class="ans-muted ans-code">{{ $item['template']->playbook }}</span></th>
                        <td><ul style="margin: 0; padding-left: 16px">@foreach($item['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul></td></tr>
                @endforeach
            </table>
        </details>
    @endif

    @if($template && $templateReasons)
        <div class="alert alert-danger" style="margin-top: 8px" id="ans-template-refused"><strong>{{ $template->name }} cannot be used for changes:</strong>
            <ul style="margin: 4px 0 0">@foreach($templateReasons as $reason)<li>{{ $reason }}</li>@endforeach</ul></div>
    @endif

    @if($usableTemplate)
        @if($template->description)
            <p class="ans-help" style="margin-top: 6px">{{ $template->description }}</p>
        @endif
        @foreach($templateNotes as $note)
            <div class="alert alert-info" style="margin-top: 8px; padding: 6px 10px"><i class="fa fa-info-circle"></i> {{ $template->name }} {{ $note }}.</div>
        @endforeach

        <h4 class="ans-step"><span class="badge">2</span> Targets <small class="text-muted">at most {{ $maxTargets }} per change · tick "first" to choose the first batch (the canary){{ $topologyWhy === null ? ', among the devices of the planner\'s first batch' : '' }}</small></h4>
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
                <thead><tr><th style="width: 28px"></th><th style="width: 48px" title="in the first batch (the canary)">first</th><th>Device</th><th>Can it be a target?</th><th>Inventory name</th><th class="hidden-xs">OS</th><th>Platform</th><th class="hidden-xs">Status</th><th class="hidden-xs">Location</th></tr></thead>
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
                                @if($fixVar === $var->name)
                                    <textarea class="form-control input-sm ans-var ans-fix-var" id="ans-var-{{ $loop->index }}" name="{{ $var->name }}" rows="{{ min(12, max(2, count($fix['lines'] ?? []))) }}" readonly>{{ implode("\n", $fix['lines'] ?? []) }}</textarea>
                                    <p class="ans-help ans-fix-var-note">computed from the fixes, read only: one line per {{ ($fix['kind'] ?? '') === 'stp_root_priority' ? 'switch: <host> <mode> <instances> <priority>' : 'port: <host> <interface> <description>' }}</p>
                                @else
                                    <textarea class="form-control input-sm ans-var" id="ans-var-{{ $loop->index }}" name="{{ $var->name }}" rows="4" maxlength="10000">{{ $answer($var) }}</textarea>
                                @endif
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
                                <p class="form-control-static ans-muted" id="ans-var-{{ $loop->index }}">asked at the dry run and at the apply (the plugin never keeps secret answers)</p>
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

        <h4 class="ans-step"><span class="badge">4</span> Reference</h4>
        <div class="form-inline">
            <input type="text" class="form-control input-sm" id="ans-reference" maxlength="120" placeholder="e.g. CHG-1234" style="min-width: 260px" autocomplete="off" value="{{ $fixReference ?? ($prefill['reference'] ?? '') }}">
            <span class="ans-help">optional: a ticket number or a few words (letters, digits, spaces and . , : _ - / ( ) @ + =)</span>
        </div>

        <h4 class="ans-step"><span class="badge">5</span> Preview and create</h4>
        <p class="ans-help">The preview shows exactly what the change will be, with its batches. Creating it runs nothing: its page launches the dry run.</p>
        <div class="ans-launchbar">
            <button type="button" class="btn btn-primary btn-sm" id="ans-preview-button"><i class="fa fa-eye"></i> Preview</button>
            <button type="button" class="btn btn-success btn-sm" id="ans-create-button" disabled><i class="fa fa-plus"></i> Create the change</button>
            <span class="ans-muted" id="ans-launch-state"></span>
        </div>
        <div id="ans-preview" class="ans-preview"></div>

        @php
            $config = [
                'targets' => route('ansible.api.targets'),
                'preview' => route('ansible.api.changes.preview'),
                'store' => route('ansible.changes.store'),
                'template' => $template->id,
                'requestToken' => $requestToken,
                'device' => $device,
                'prefill' => $prefill !== null ? ['devices' => $prefill['devices'], 'canary' => $prefill['canary'], 'unplanned' => $prefill['unplanned'] ?? 'leave'] : null,
                // a change made from fixes: its devices are the fix's (not to be changed here), its lines go with the preview and the creation
                'fix' => $fix !== null && ! $fix['errors'] ? ['kind' => $fix['kind'], 'ids' => $fix['ids'], 'scope' => $fix['scope'], 'devices' => $fix['devices'],
                    'lines' => $fix['lines'], 'variable' => $fix['variable']] : null,
                'maxTargets' => $maxTargets,
                'deviceUrl' => url('device'),
                // the topology plugin plans the batches (the first batch comes from its first one)
                'topology' => $topologyWhy === null,
            ];
        @endphp
        {{-- @json() splits its argument on commas: it gets one variable --}}
        <script type="application/json" id="ans-new-change-config">@json($config)</script>
    @endif
</div>
@endsection

@section('scripts')
    @if($usableTemplate)
        <script src="{{ route('ansible.asset', 'ansible.js') }}?v={{ $assetVersion }}"></script>
        <script>AnsiblePlugin.newChange(JSON.parse(document.getElementById('ans-new-change-config').textContent));</script>
    @endif
@endsection
