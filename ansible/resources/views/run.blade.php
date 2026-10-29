{{-- One run (RunController::show): header, targets with their result, the answers, and the log, followed live
     (resources/assets/ansible.js polls the plugin every 2 seconds while the run is unfinished); a diagnostic run's output
     per device once it ended (DiagnosticController::outputs, inserted as text). Device output can hold anything: every
     value is escaped, the log through Runs\Ansi::html(). --}}
@extends('layouts.librenmsv1')

@section('title', 'Ansible run #' . $run['id'])

@push('styles')
    <link href="{{ route('ansible.asset', 'ansible.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    // the plugin keeps times in UTC: shown in LibreNMS' time zone
    $local = fn (?string $time) => \Agoodley\LibrenmsAnsible\Support\Clock::local($time);
    $status = $run['status'];
    $final = \Agoodley\LibrenmsAnsible\Runs\RunStatus::isFinal($status);
    $counters = ['ok', 'changed', 'unreachable', 'failed', 'skipped'];
    $variables = $run['variables'] ?? [];
    // a change's dry run and a diagnostic run (in check mode) say what would change; one its log does not prove to be in check mode
    // may have changed its devices
    $dryRun = $checkMode;
    $unproven = \Agoodley\LibrenmsAnsible\Runs\RunResults::unproven($run);
@endphp

@section('content')
<div class="container-fluid ans-page" id="ans-run" data-run="{{ $run['id'] }}">
    @include('ansible::partials.nav', ['active' => 'run', 'runId' => $run['id']])

    <div class="ans-cards">
        <div class="ans-card ans-{{ ['success' => 'ok', 'danger' => 'critical', 'warning' => 'warning'][\Agoodley\LibrenmsAnsible\Runs\RunStatus::css($status)] ?? 'info' }}" id="ans-run-status-card">
            <h4>Status</h4>
            <div class="ans-card-value"><span id="ans-run-status" class="label label-{{ \Agoodley\LibrenmsAnsible\Runs\RunStatus::css($status) }}">{{ \Agoodley\LibrenmsAnsible\Runs\RunStatus::label($status) }}</span></div>
            <p>runner: <span id="ans-run-runner-status">{{ $run['runner_status'] ?? '-' }}</span>@if($run['stopped_by']) · stop asked by {{ $run['stopped_by'] }}@endif
                @if($run['stop_reason']) · {{ \Agoodley\LibrenmsAnsible\Support\Clock::localize($run['stop_reason']) }}@endif @if($run['released_by']) · devices released by {{ $run['released_by'] }}@endif</p>
        </div>
        <div class="ans-card ans-info">
            <h4>Template</h4>
            <div class="ans-card-value" style="font-size: 17px">{{ $run['template_name'] }}</div>
            <p>{{ $run['playbook'] }} · template #{{ $run['template_id'] }} of project {{ $run['runner_project'] }}</p>
        </div>
        <div class="ans-card ans-info">
            <h4>Launched by</h4>
            <div class="ans-card-value" style="font-size: 17px">{{ $run['username'] }}</div>
            <p>{{ $local($run['created_at']) }}{{ $run['runner_task_id'] ? ' · runner task #' . $run['runner_task_id'] : '' }}</p>
        </div>
        <div class="ans-card ans-info">
            <h4>Time</h4>
            <div class="ans-card-value" style="font-size: 17px" id="ans-run-duration">{{ \Agoodley\LibrenmsAnsible\Support\Format::duration(\Agoodley\LibrenmsAnsible\Support\Format::runDuration($run)) }}</div>
            <p>started <span id="ans-run-started">{{ $local($run['started_at']) ?? '-' }}</span> · ended <span id="ans-run-finished">{{ $local($run['finished_at']) ?? '-' }}</span></p>
        </div>
    </div>

    @if($dryRun)
        <div class="ans-warning-loud" id="ans-run-not-proven" @if(! $unproven) style="display: none" @endif><i class="fa fa-exclamation-triangle"></i>
            @if($diagnostic)
                This diagnostic run is NOT PROVEN to have run in check mode: its log shows a task outside check mode (or no sign of --check). It may have changed its
                devices: check them, and the template's playbook (a task with check_mode: false runs for real).
            @else
                This dry run is NOT PROVEN to be one: its log shows a task outside check mode (or no sign of --check). It may have changed its devices: check them.
                Its change can be neither approved nor applied.
            @endif</div>
    @endif
    @if($diagnostic)
        <div class="alert alert-info" id="ans-run-diagnostic" style="padding: 6px 10px"><i class="fa fa-stethoscope"></i>
            A diagnostic run: the template in check mode (<span class="ans-code">--check --diff</span>), as no change.
            <span id="ans-run-proof">{{ ($run['proof'] ?? null) === 'verified' ? 'Its log proves that every task ran in check mode: it changed nothing.'
                : (($run['proof'] ?? null) === 'unverified' ? '' : ($final ? 'Its log was not judged (the run did not start, or its log is not whole).' : 'Its log is judged when it ends.')) }}</span>
            @if($canRun)<a class="btn btn-xs btn-default" href="{{ route('ansible.diagnostics', ['from' => $run['id']]) }}" id="ans-run-again" style="margin-left: 6px"><i class="fa fa-repeat"></i> Run again</a>@endif
        </div>
    @endif
    @if(! empty($run['change_id']))
        <div class="alert alert-info" id="ans-run-change" style="padding: 6px 10px"><i class="fa fa-random"></i>
            This run is {{ $run['kind'] === 'dry_run' ? 'the dry run (--check --diff)' : 'batch ' . $run['batch'] }} of
            <a href="{{ route('ansible.changes.show', $run['change_id']) }}">change #{{ $run['change_id'] }}</a>: its page shows the {{ $run['kind'] === 'dry_run' ? 'diffs per device, the approval' : 'other batches, the backups and the maintenance windows' }}, and stops it.
            @if($run['commit_hash'])<span class="ans-muted">Commit <span class="ans-code">{{ substr($run['commit_hash'], 0, 12) }}</span>.</span>@endif</div>
    @endif

    <div id="ans-run-alerts">
        @if($run['unexpected_hosts'])
            <div class="alert alert-danger" id="ans-run-unexpected"><i class="fa fa-exclamation-triangle"></i>
                <strong>The runner ran this template on hosts that were not its targets:</strong> {{ implode(', ', $run['unexpected_hosts']) }}.
                Check in the runner that the template uses the LibreNMS inventory and lets a launch set the limit (Ansible prompts, Limit), and what was done to these hosts.</div>
        @endif
        @if($run['error'])
            <div class="alert alert-{{ in_array($status, ['not_launched', 'failed'], true) ? 'danger' : 'warning' }}" id="ans-run-error"><i class="fa fa-exclamation-triangle"></i>
                <strong>{{ \Agoodley\LibrenmsAnsible\Runs\RunStatus::label($status) }}:</strong> {{ \Agoodley\LibrenmsAnsible\Support\Clock::localize($run['error']) }}
                @if(in_array($status, \Agoodley\LibrenmsAnsible\Runs\RunStatus::WATCHED, true) && $run['watch_until'])
                    <br><span class="ans-muted">The plugin still asks the runner about this run until {{ \Agoodley\LibrenmsAnsible\Support\Clock::local($run['watch_until'], 'Y-m-d H:i T') }}: a later end is recorded here.</span>
                @endif
            </div>
        @endif
        @if($stoppedNote)
            <div class="alert alert-warning" id="ans-run-stopped-note"><i class="fa fa-exclamation-triangle"></i> {{ $stoppedNote }}
                @if($stoppedCommand)
                    <div style="margin-top: 6px">On the runner, <code class="ans-code" id="ans-run-leftover-command">{{ $stoppedCommand }}</code> lists them
                        (besides the grep itself); end each with <code class="ans-code">kill PID</code>.</div>
                @endif
                @if($held && $canRun)
                    <div style="margin-top: 6px"><button type="button" class="btn btn-sm btn-warning" id="ans-run-release-hold"><i class="fa fa-unlock"></i> Release the devices</button>
                        <span class="ans-muted">once that step no longer runs on the runner (written to the eventlog)</span></div>
                @endif
            </div>
        @endif
        @if($run['poll_error'] && ! $final)
            <div class="alert alert-warning" id="ans-run-poll-error"><i class="fa fa-plug"></i> {{ $run['poll_error'] }}
                <span class="ans-muted">The run keeps its devices until the runner reports its end.</span></div>
        @endif
    </div>

    @if($canRun && ! $final)
        <p><button type="button" class="btn btn-sm btn-danger" id="ans-run-stop"><i class="fa fa-stop"></i> Stop</button>
            <span class="ans-muted" id="ans-run-stop-note">asks the runner to stop the task: no further task starts, but the step in progress runs on, so the devices stay held for a while</span>
            @if($stopAfterAt)
                <br><span class="ans-muted" id="ans-run-stop-after">Still unfinished at {{ $stopAfterAt }}, the plugin stops it (Stop after setting).</span>
            @endif
            <span id="ans-run-release-box" @if(! $releasable) style="display: none" @endif>
                <button type="button" class="btn btn-sm btn-warning" id="ans-run-release" style="margin-left: 12px"><i class="fa fa-unlock"></i> Release the devices</button>
                <span class="ans-muted">the runner could not be asked about this run for a while: end it as released, its results unknown (written to the eventlog)</span>
            </span></p>
    @endif

    <div class="row">
        <div class="col-md-7">
            <div class="panel panel-default">
                <div class="panel-heading"><strong>Targets</strong> <span class="text-muted">({{ count($targets) }})</span>
                    <span id="ans-run-summary">@include('ansible::partials.results', ['resultSummary' => $run['summary'] ?? [], 'resultDryRun' => (bool) $dryRun, 'resultUnproven' => (bool) $unproven])</span></div>
                <div class="table-responsive">
                <table class="table table-condensed ans-table" id="ans-run-targets">
                    <thead><tr><th>Device</th><th>Inventory name</th><th>Result</th>@foreach($counters as $c)<th class="ans-num">{{ $c }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach($targets as $deviceId => $target)
                        <tr data-device="{{ $deviceId }}" @if($target['unexpected']) class="danger" data-unexpected="1" @endif>
                            <td class="ans-wrap"><a href="{{ url('device/' . $deviceId) }}">{{ $target['display'] ?: $target['hostname'] }}</a>
                                @if($target['unexpected'])<span class="label label-danger" title="The runner ran on this device although it was not a target">not a target</span>@endif</td>
                            <td class="ans-code">{{ $target['inventory_name'] }}</td>
                            <td class="ans-target-result">
                                @if($target['result'] && $unproven && empty($target['unexpected']))
                                    <span class="label label-danger ans-result">{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::NOT_PROVEN }}</span>
                                @elseif($target['result'])
                                    <span class="label label-{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::css($target['result']) }} ans-result">{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::label($target['result'], $dryRun) }}</span>
                                @else
                                    <span class="text-muted">{{ $final ? '-' : 'pending' }}</span>
                                @endif
                            </td>
                            @foreach($counters as $c)
                                <td class="ans-num ans-count" data-counter="{{ $c }}">{{ $target['counts'][$c] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="panel panel-default">
                <div class="panel-heading"><strong>Survey answers</strong></div>
                <table class="table table-condensed ans-table" id="ans-run-variables">
                    @forelse($variables as $variable)
                        <tr>
                            <th style="width: 40%" class="ans-wrap">{{ $variable['label'] }} <span class="ans-code ans-muted">{{ $variable['name'] }}</span></th>
                            <td class="ans-wrap">
                                @if($variable['secret'])
                                    <span class="text-muted">{{ $variable['value'] !== null ? '******** (secret: sent to the runner only, not stored)' : '(not given)' }}</span>
                                @elseif($variable['value'] === null)
                                    <span class="text-muted">(not given)</span>
                                @else
                                    <span class="ans-code" style="white-space: pre-wrap">{{ $variable['value'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-muted">The template has no survey variables.</td></tr>
                    @endforelse
                    <tr><th>Limit</th><td class="ans-code ans-wrap">{{ str_replace(',', ', ', $run['limit']) }}</td></tr>
                </table>
            </div>
        </div>
    </div>

    @if($diagnostic)
        <div class="panel panel-default" id="ans-outputs-panel">
            <div class="panel-heading"><strong>Output per device</strong> <span class="text-muted" id="ans-outputs-state">{{ $final ? 'loading…' : '(once the run ended)' }}</span>
                <label class="pull-right" style="font-weight: normal; margin: 0"><input type="checkbox" id="ans-outputs-quiet"> tasks without output</label></div>
            <div class="panel-body" id="ans-outputs" style="padding: 8px"></div>
        </div>
    @endif

    <div class="panel panel-default">
        <div class="panel-heading"><strong>Log</strong> <span class="text-muted" id="ans-log-state">{{ $final ? '' : '(live: refreshed every 2 seconds)' }}</span></div>
        <div class="panel-body" style="padding: 6px">
            <div class="ans-log-bar">
                <label style="font-weight: normal; margin: 0"><input type="checkbox" id="ans-log-follow" checked> follow the end</label>
                <span class="ans-muted" id="ans-log-count">{{ number_format($next) }} lines</span>
                @if($run['output_truncated'])
                    <span class="text-warning" id="ans-log-truncated"><i class="fa fa-scissors"></i> The log is longer than the Output size setting: its start and its end are kept here, the whole log is in the runner (task #{{ $run['runner_task_id'] }}).</span>
                @endif
            </div>
            <pre class="ans-log" id="ans-log">@foreach($lines as $line)<span class="ans-line">{!! \Agoodley\LibrenmsAnsible\Runs\Ansi::html($line) !!}</span>
@endforeach</pre>
        </div>
    </div>
</div>
@php
    $config = [
        'poll' => route('ansible.api.runs.show', $run['id']),
        'stop' => $canRun ? route('ansible.runs.stop', $run['id']) : null,
        'release' => $canRun ? route('ansible.runs.release', $run['id']) : null,
        'final' => $final,
        'next' => $next,
        // a diagnostic run's output per device, read once it ended
        'outputs' => $diagnostic ? route('ansible.api.runs.outputs', $run['id']) : null,
        'deviceUrl' => url('device'),
    ];
@endphp
{{-- @json() splits its argument on commas: it gets one variable --}}
<script type="application/json" id="ans-run-config">@json($config)</script>
@endsection

@section('scripts')
    <script src="{{ route('ansible.asset', 'ansible.js') }}?v={{ $assetVersion }}"></script>
    <script>AnsiblePlugin.run(JSON.parse(document.getElementById('ans-run-config').textContent));</script>
@endsection
