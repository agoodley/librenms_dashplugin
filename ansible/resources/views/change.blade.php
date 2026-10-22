{{-- One change (ChangeController::show): its status, the actions the user may take, the batch plan and the batches,
     the dry run per device with its diffs, the approvals, the backups and maintenance windows, the live log of the run
     it follows now, and its timeline. resources/assets/ansible.js polls the plugin every 2 seconds while the change is
     not over and reloads the page when its state moved on. Device output and diffs can hold anything: every value is
     escaped, the log through Runs\Ansi::html(). --}}
@extends('layouts.librenmsv1')

@section('title', 'Ansible change #' . $change['id'])

@push('styles')
    <link href="{{ route('ansible.asset', 'ansible.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
    use Agoodley\LibrenmsAnsible\Changes\ChangeText;
    use Agoodley\LibrenmsAnsible\Changes\BackupState;
    use Agoodley\LibrenmsAnsible\Changes\ApprovalRules;
    use Agoodley\LibrenmsAnsible\Changes\Connections;
    use Agoodley\LibrenmsAnsible\Runs\RunResults;
    use Agoodley\LibrenmsAnsible\Runs\RunStatus;
    use Agoodley\LibrenmsAnsible\Support\Clock;
    $local = fn (?string $time) => Clock::local($time);
    $status = $change['status'];
    $final = ChangeStatus::isFinal($status);
    $dryState = $change['dry_run_state'];
    $plan = (array) $change['plan'];
    $dryCss = ['changed' => 'warning', 'ok' => 'success', 'skipped' => 'default', 'failed' => 'danger', 'unreachable' => 'danger', 'not_run' => 'default', 'unknown' => 'warning'];
    $backupCss = [BackupState::FRESH => 'success', BackupState::REQUESTED => 'info', BackupState::FAILED => 'danger', BackupState::UNAVAILABLE => 'danger',
        BackupState::NO_NODE => 'warning', BackupState::EXCLUDED => 'default'];
    $batchCss = ['pending' => 'default', 'preparing' => 'info', 'prepared' => 'info', 'launching' => 'info', 'running' => 'info', 'done' => 'success',
        'failed' => 'danger', 'stopped' => 'warning', 'cancelled' => 'default'];
    $batchLabels = ['pending' => 'to do', 'preparing' => 'window and backups', 'prepared' => 'waits for the secret answers', 'launching' => 'launching',
        'running' => 'running', 'done' => 'done', 'failed' => 'failed', 'stopped' => 'stopped', 'cancelled' => 'not run'];
    $waitText = ['canary' => 'waits for an admin to continue after the first batch',
        'secrets' => 'waits for the secret answers until ' . (Clock::local($change['resume_at'], 'Y-m-d H:i T') ?? '?') . ' (the plugin never keeps them), then the change ends',
        'timer' => 'pause between batches until ' . (Clock::local($change['resume_at'], 'Y-m-d H:i T') ?? '?')];
    $counters = ['ok', 'changed', 'unreachable', 'failed', 'skipped'];
    $variables = (array) ($change['variables'] ?? []);
    $byBatch = [];
    foreach ($targets as $deviceId => $target) {
        $byBatch[(int) $target['batch']][] = $deviceId;
    }
    $short = fn (?string $oid) => $oid ? substr($oid, 0, 10) : '';
    // how the runner reached each device at the dry run (Connections): a batch runs only while the inventory reaches it the same way
    $reached = (array) (((array) ($change['fingerprint_parts'] ?? []))['connections'] ?? []);
    // the blocks not attributed, per host they name: a device whose diff is among them says so
    $unattributedOf = array_count_values(array_map(fn ($block) => (string) $block['host'], $unattributed));
    // the Log panel once the apply started: the current batch's run, or what that batch does before it has one
    $logNote = null;
    if (! $run && $change['apply_started_at'] !== null) {
        $currentBatch = $batches[(int) $change['current_batch']] ?? null;
        $preparing = implode(' and ', array_filter([$settings->maintenance ? 'maintenance window' : null, $settings->backups && $oxidized ? 'backups' : null]));
        $logNote = $final || $currentBatch === null ? 'no batch was launched' : 'batch ' . (int) $change['current_batch'] . ': ' . match ($currentBatch['status']) {
            'pending' => 'about to start',
            'preparing' => 'preparing' . ($preparing !== '' ? " ($preparing)" : ''),
            'prepared' => 'waits for the secret answers',
            'launching' => 'being launched',
            default => $batchLabels[$currentBatch['status']] ?? (string) $currentBatch['status'],
        };
    }
@endphp

@section('content')
<div class="container-fluid ans-page" id="ans-change" data-change="{{ $change['id'] }}">
    @include('ansible::partials.nav', ['active' => 'change', 'changeId' => $change['id']])

    <div class="ans-cards">
        <div class="ans-card ans-{{ ['success' => 'ok', 'danger' => 'critical', 'warning' => 'warning'][ChangeStatus::css($status)] ?? 'info' }}" id="ans-change-status-card">
            <h4>Status</h4>
            <div class="ans-card-value"><span id="ans-change-status" class="label label-{{ ChangeStatus::css($status) }}">{{ ChangeStatus::label($status) }}</span></div>
            <p>@if(in_array($status, ChangeStatus::RESERVING, true) || $status === ChangeStatus::APPLIED)batch {{ $change['current_batch'] }} of {{ $change['batch_count'] }}@else{{ $change['batch_count'] }} {{ (int) $change['batch_count'] === 1 ? 'batch' : 'batches' }}@endif
                @if($change['waiting']) · {{ $waitText[$change['waiting']] ?? '' }}@endif
                @if($change['finished_at']) · ended {{ $local($change['finished_at']) }}@endif</p>
        </div>
        <div class="ans-card ans-info">
            <h4>Template</h4>
            <div class="ans-card-value" style="font-size: 17px">{{ $change['template_name'] }}</div>
            <p>{{ $change['playbook'] }} · template #{{ $change['template_id'] }}</p>
        </div>
        <div class="ans-card ans-info">
            <h4>Requested by</h4>
            <div class="ans-card-value" style="font-size: 17px">{{ $change['username'] }}</div>
            <p>{{ $local($change['created_at']) }}@if($change['reference'] !== '') · <span class="ans-code" id="ans-change-reference">{{ $change['reference'] }}</span>@endif</p>
        </div>
        <div class="ans-card ans-{{ $dryState === 'verified' ? 'ok' : (in_array($dryState, ['unverified', 'failed'], true) ? 'critical' : 'info') }}" id="ans-dry-run-card">
            <h4>Dry run</h4>
            <div class="ans-card-value" style="font-size: 17px" id="ans-dry-run-state">{{ ['verified' => 'Verified', 'unverified' => 'NOT PROVEN', 'failed' => 'Failed', 'running' => 'Running',
                'launching' => 'Launching', 'void' => 'No longer counts', 'not_launched' => 'Not launched'][$dryState ?? ''] ?? 'Not yet' }}</div>
            <p>@if($dryRun)<a href="{{ route('ansible.runs.show', $dryRun['id']) }}">run #{{ $dryRun['id'] }}</a> · {{ $local($change['dry_run_at']) }}@endif
                @if($change['dry_run_commit']) · commit <span class="ans-code" id="ans-dry-run-commit">{{ substr($change['dry_run_commit'], 0, 12) }}</span>
                @elseif(in_array($dryState, ['verified', 'failed', 'unverified'], true))
                    · <span class="text-warning" id="ans-dry-run-no-commit" title="Semaphore records the commit of a repository with a Git URL only">commit not recorded (local repository): batches are not pinned to the dry run's commit</span>
                @endif
                @if($dryRunExpires) · counts until {{ Clock::show($dryRunExpires, 'Y-m-d H:i T') }}@endif</p>
        </div>
    </div>

    <div id="ans-change-alerts">
        @if($dryState === 'unverified')
            <div class="ans-warning-loud" id="ans-dry-run-unverified"><i class="fa fa-exclamation-triangle"></i>
                This dry run is NOT PROVEN to be a dry run: it may have changed the devices. Check them before anything else. It can be neither approved nor applied.
                <ul style="margin: 4px 0 0; font-weight: normal">@foreach((array) $change['dry_run_findings'] as $finding)<li>{{ $finding }}</li>@endforeach</ul></div>
        @elseif($dryState === 'failed')
            <div class="alert alert-danger" id="ans-dry-run-failed"><strong>The dry run did not succeed on every target:</strong> it cannot be approved or applied. Fix the cause and run it again,
                or make a new change without these devices.
                <ul style="margin: 4px 0 0">@foreach((array) $change['dry_run_findings'] as $finding)<li>{{ $finding }}</li>@endforeach</ul>
                @if($passed && $passed['devices'] && $canRun)
                    <div style="margin-top: 6px"><a class="btn btn-sm btn-default" id="ans-change-without-failed"
                        href="{{ route('ansible.changes.new', ['template' => $change['template_id'], 'from' => $change['id'], 'devices' => 'passed']) }}"><i class="fa fa-plus"></i>
                        A new change without them ({{ \Agoodley\LibrenmsAnsible\Support\Format::count(count($passed['devices']), 'device') }})</a>
                        <span class="ans-muted">(answers, reference and first batch filled in; it is dry-run and approved again)</span></div>
                @endif
            </div>
        @endif
        @if($change['error'] && ! in_array($dryState, ['unverified', 'failed'], true) && $status !== ChangeStatus::APPLIED)
            <div class="alert alert-{{ in_array($status, [ChangeStatus::FAILED, ChangeStatus::REJECTED], true) ? 'danger' : 'warning' }}" id="ans-change-error">
                @php $outcome = ChangeText::outcome($status, Clock::localize($change['error'])); @endphp
                @if(str_contains($outcome, ': '))<strong>{{ strstr($outcome, ': ', true) }}:</strong> {{ substr(strstr($outcome, ': '), 2) }}@else<strong>{{ $outcome }}</strong>@endif
                @if($remaining && $canRun && ($remaining['devices'] || $remaining['left_out']))
                    <div style="margin-top: 6px">
                        @if($remaining['devices'])
                            <a class="btn btn-sm btn-default" id="ans-change-remaining"
                                href="{{ route('ansible.changes.new', ['template' => $change['template_id'], 'from' => $change['id'], 'devices' => 'remaining']) }}"><i class="fa fa-plus"></i>
                                A new change with the {{ count($remaining['devices']) }} remaining {{ count($remaining['devices']) === 1 ? 'device' : 'devices' }}</a>
                            <span class="ans-muted">(answers, reference and first batch filled in; not a resume: it is dry-run and approved again)</span>
                        @endif
                        @if($remaining['left_out'])
                            <div class="ans-help" id="ans-change-remaining-held">Not offered yet: @foreach($remaining['left_out'] as $why){{ $why }}@if(! $loop->last); @endif @endforeach</div>
                        @endif
                    </div>
                @endif
            </div>
        @endif
        @php
            // the targets of the dry run or batch in progress that the runner does not get: left out of a fetch already (recorded), or
            // withheld from it now (an edit since the dry run, as the Inventory page lists them)
            $phaseBatch = $batches[(int) $change['current_batch']] ?? null;
            $inProgress = $status === ChangeStatus::DRY_RUN || (in_array($status, [ChangeStatus::APPLYING, ChangeStatus::STOPPING], true)
                && in_array($phaseBatch['status'] ?? null, ['launching', 'running'], true));
            $phase = $status === ChangeStatus::DRY_RUN ? 'the dry run' : 'batch ' . (int) $change['current_batch'];
            $fetchedWithout = [];
            foreach ($inProgress ? ($status === ChangeStatus::DRY_RUN ? array_keys($targets) : ($byBatch[(int) $change['current_batch']] ?? [])) : [] as $deviceId) {
                if (($targets[$deviceId]['served_hash'] ?? null) === \Agoodley\LibrenmsAnsible\Changes\InventoryGuard::LEFT_OUT) {
                    $fetchedWithout[$deviceId] = (string) (($targets[$deviceId]['served_note'] ?? '') ?: \Agoodley\LibrenmsAnsible\Changes\NextChange::name($targets[$deviceId]));
                }
            }
            $withheldNow = $inProgress ? array_diff_key($withheld, $fetchedWithout) : [];
            $outcome = $status === ChangeStatus::DRY_RUN ? 'it does not count' : 'the change stops when the batch ends';
        @endphp
        @if($fetchedWithout || $withheldNow)
            <div class="alert alert-warning" id="ans-change-withheld"><i class="fa fa-eye-slash"></i>
                @if($fetchedWithout)
                    <span id="ans-change-fetched-without"><strong>Left out of an inventory the runner fetched during {{ $phase }}:</strong>
                        {{ implode('; ', $fetchedWithout) }}. The runner no longer reaches {{ count($fetchedWithout) === 1 ? 'it' : 'them' }} as at the dry run, so {{ $phase }}
                        may not have run on {{ count($fetchedWithout) === 1 ? 'it' : 'them' }}, and {{ $outcome }}.</span>
                @endif
                @if($withheldNow)
                    <span id="ans-change-withheld-now"><strong>Withheld from the runner while {{ $phase }} is in progress:</strong> {{ implode('; ', $withheldNow) }}.
                        The runner no longer reaches {{ count($withheldNow) === 1 ? 'it' : 'them' }} as at the dry run, so the inventory endpoint leaves
                        {{ count($withheldNow) === 1 ? 'it' : 'them' }} out: if the runner fetches the inventory before that is set back, {{ $phase }} does not run on
                        {{ count($withheldNow) === 1 ? 'it' : 'them' }}, and {{ $outcome }}.</span>
                @endif
                <a href="{{ route('ansible.inventory') }}">Inventory</a>
            </div>
        @endif
        @if($backupWarning)
            <div class="alert alert-warning" id="ans-backup-check"><i class="fa fa-archive"></i> {{ $backupWarning }}</div>
        @endif
        @if($stopped && ($stopped['held'] || $status === ChangeStatus::STOPPED))
            <div class="alert alert-warning" id="ans-change-stopped-note"><i class="fa fa-exclamation-triangle"></i>
                Batch {{ $stopped['run']['batch'] }} (<a href="{{ route('ansible.runs.show', $stopped['run']['id']) }}">run #{{ $stopped['run']['id'] }}</a>): {{ $stopped['note'] }}
                @if($stopped['command'] && $stopped['held'])
                    <div style="margin-top: 6px">On the runner, <code class="ans-code">{{ $stopped['command'] }}</code> lists them (besides the grep itself); end each with <code class="ans-code">kill PID</code>.
                        Then release the devices on the <a href="{{ route('ansible.runs.show', $stopped['run']['id']) }}">run page</a>.</div>
                @endif
            </div>
        @endif
        @if($secretVars && ! $final)
            <div class="alert alert-info" id="ans-change-secrets-note"><i class="fa fa-key"></i> This template has secret answers ({{ implode(', ', array_column($secretVars, 'label')) }}).
                The plugin never keeps them: they are asked at the dry run and at each launch of a batch, and they are not part of what was dry-run or approved.</div>
        @endif
    </div>

    @if(array_filter($actions))
        <div class="panel panel-default" id="ans-change-actions">
            <div class="panel-body" style="padding: 8px 10px">
                @if($secretVars && ($actions['dry_run'] || $actions['apply'] || $actions['continue']))
                    <div class="form-inline" id="ans-secret-fields" style="margin-bottom: 6px">
                        @foreach($secretVars as $var)
                            <label style="font-weight: normal; margin-right: 8px">{{ $var['label'] }} <span class="ans-code ans-muted">{{ $var['name'] }}</span>
                                <span class="ans-muted ans-secret-mark" data-name="{{ $var['name'] }}" data-required="{{ ! empty($var['required']) ? 1 : 0 }}">{{ ! empty($var['required']) ? '(required)' : '' }}</span>
                                <input type="password" class="form-control input-sm ans-secret" name="{{ $var['name'] }}" maxlength="1000" autocomplete="new-password"@if(! empty($var['required'])) data-required="1"@endif></label>
                        @endforeach
                        <span class="ans-help">never stored: sent to the runner with the launch, and kept in this page's memory for the next batches while it stays open</span>
                    </div>
                @endif
                <div class="ans-launchbar" style="margin-top: 0">
                    @if($actions['dry_run'])
                        <button type="button" class="btn btn-sm btn-primary" data-action="dry-run" id="ans-action-dry-run"><i class="fa fa-search"></i> {{ $change['dry_runs'] ? 'Run the dry run again' : 'Run the dry run' }}</button>
                    @endif
                    @if($actions['approve'] || $actions['reject'])
                        <input type="text" class="form-control input-sm" id="ans-approval-comment" maxlength="500" placeholder="comment (optional)" style="width: 240px">
                    @endif
                    @if($actions['approve'])
                        <button type="button" class="btn btn-sm btn-success" data-action="approve" id="ans-action-approve"><i class="fa fa-check"></i> Approve</button>
                    @endif
                    @if($actions['reject'])
                        <button type="button" class="btn btn-sm btn-danger" data-action="reject" id="ans-action-reject"><i class="fa fa-times"></i> Reject</button>
                    @endif
                    @if($actions['apply'])
                        <button type="button" class="btn btn-sm btn-success" data-action="apply" id="ans-action-apply"><i class="fa fa-play"></i> Apply in {{ $change['batch_count'] }} {{ (int) $change['batch_count'] === 1 ? 'batch' : 'batches' }}</button>
                    @endif
                    @if($actions['continue'])
                        <button type="button" class="btn btn-sm btn-success" data-action="continue" id="ans-action-continue"><i class="fa fa-forward"></i> {{ $change['waiting'] === 'secrets' ? 'Launch batch ' . $change['current_batch'] : 'Continue with batch ' . ((int) $change['current_batch'] + 1) }}</button>
                    @endif
                    @if($actions['stop'])
                        <button type="button" class="btn btn-sm btn-danger" data-action="stop" id="ans-action-stop"><i class="fa fa-stop"></i> {{ $status === ChangeStatus::DRY_RUN ? 'Stop the dry run' : 'Stop the change' }}</button>
                    @endif
                    @if($actions['cancel'])
                        <button type="button" class="btn btn-sm btn-default" data-action="cancel" id="ans-action-cancel"><i class="fa fa-ban"></i> Cancel the change</button>
                    @endif
                    <span class="ans-muted" id="ans-action-state"></span>
                </div>
                @if($status === ChangeStatus::AWAITING && $canApprove && $approvalRefusal)
                    <p class="ans-help" id="ans-approval-refusal" style="margin-top: 6px">{{ $approvalRefusal }}</p>
                @elseif($status === ChangeStatus::AWAITING && ! $actions['approve'])
                    <p class="ans-help" id="ans-approval-waits" style="margin-top: 6px">{{ ApprovalRules::waitsFor($settings->approval) }}</p>
                @endif
                @if($noApprover)
                    <div class="alert alert-warning" id="ans-no-approver" style="margin: 6px 0 0"><i class="fa fa-user-times"></i> {{ $noApprover }}
                        An admin grants it on the plugin's <a href="{{ url('plugin/settings/ansible') }}#ans-approve-permission">settings page</a> (<em>Approve permission</em>),
                        or sets <em>Approval</em> to off there.</div>
                @endif
            </div>
        </div>
    @elseif($status === ChangeStatus::AWAITING)
        <p class="ans-muted" id="ans-approval-waits">{{ ApprovalRules::waitsFor($settings->approval) }}</p>
        @if($noApprover)
            <div class="alert alert-warning" id="ans-no-approver"><i class="fa fa-user-times"></i> {{ $noApprover }}
                An admin grants it on the plugin's <a href="{{ url('plugin/settings/ansible') }}#ans-approve-permission">settings page</a> (<em>Approve permission</em>),
                or sets <em>Approval</em> to off there.</div>
        @endif
    @endif

    <div class="row">
        <div class="col-md-7">
            <div class="panel panel-default">
                <div class="panel-heading"><strong>Batches</strong> <span class="text-muted">({{ \Agoodley\LibrenmsAnsible\Support\Format::count(count($targets), 'device') }}; stops at {{ $plan['stop_at'] ?? 1 }} failed {{ ($plan['stop_at'] ?? 1) == 1 ? 'device' : 'devices' }} in a batch{{ ! empty($plan['wait_after_first']) && count($batches) > 1 ? '; waits for an admin after the first batch' : '' }}{{ ! empty($plan['pause_minutes']) ? '; ' . $plan['pause_minutes'] . ' min between batches' : '' }})</span></div>
                <div class="table-responsive">
                <table class="table table-condensed ans-table ans-stack" id="ans-batches">
                    <thead><tr><th>Batch</th><th>Devices</th><th>State</th><th>Run</th><th>Results</th><th>When</th></tr></thead>
                    <tbody>
                    @foreach($batches as $number => $batch)
                        @php $batchRun = $batchRuns[$number] ?? null; @endphp
                        <tr data-batch="{{ $number }}">
                            <td data-label="Batch">{{ $number }}{{ $number === 1 && count($batches) > 1 ? ' (canary)' : '' }}</td>
                            <td class="ans-wrap" data-label="Devices">{{ implode(', ', array_map(fn ($d) => $targets[$d]['display'] ?: $targets[$d]['hostname'], $byBatch[$number] ?? [])) }}</td>
                            <td data-label="State"><span class="label label-{{ $batchCss[$batch['status']] ?? 'default' }} ans-batch-state">{{ $batchLabels[$batch['status']] ?? $batch['status'] }}</span>
                                @if($batch['note'])<div class="ans-help ans-wrap">{{ Clock::localize($batch['note']) }}</div>@endif</td>
                            <td data-label="Run">@if($batchRun)<a href="{{ route('ansible.runs.show', $batchRun['id']) }}">#{{ $batchRun['id'] }}</a> <span class="label label-{{ RunStatus::css($batchRun['status']) }}">{{ RunStatus::label($batchRun['status']) }}</span>@endif</td>
                            <td data-label="Results">@if($batchRun)@include('ansible::partials.results', ['resultSummary' => $batchRun['summary'] ?? [], 'resultDryRun' => false, 'resultUnproven' => false])@endif</td>
                            <td class="ans-muted" style="white-space: nowrap" data-label="When">{{ $local($batch['started_at']) }}{{ $batch['ended_at'] ? ' – ' . Clock::local($batch['ended_at'], 'H:i:s') : '' }}{{ $batch['username'] ? ' · ' . $batch['username'] : '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="panel panel-default">
                <div class="panel-heading"><strong>Answers</strong></div>
                <table class="table table-condensed ans-table" id="ans-change-variables">
                    @forelse($variables as $variable)
                        <tr>
                            <th style="width: 40%" class="ans-wrap">{{ $variable['label'] }} <span class="ans-code ans-muted">{{ $variable['name'] }}</span></th>
                            <td class="ans-wrap">
                                @if(! empty($variable['secret']))
                                    <span class="text-muted">secret: asked at each launch, never kept</span>
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
                </table>
            </div>
            <div class="panel panel-default">
                <div class="panel-heading"><strong>Approval</strong> <span class="text-muted">(the settings ask for {{ ApprovalRules::LABELS[$settings->approval] ?? $settings->approval }}{{ ApprovalRules::needed($settings->approval) ? ', valid ' . intdiv($settings->approvalSeconds, 3600) . ' h' : '' }})</span></div>
                <table class="table table-condensed ans-table" id="ans-approvals">
                    @forelse($approvals as $approval)
                        <tr class="{{ $approval['voided_at'] ? 'text-muted' : '' }}">
                            <td><span class="label label-{{ $approval['decision'] === 'approved' ? ($approval['voided_at'] ? 'default' : 'success') : 'danger' }}">{{ $approval['decision'] }}</span></td>
                            <td>{{ $approval['username'] }}<br><span class="ans-muted">{{ $local($approval['created_at']) }}</span></td>
                            <td class="ans-wrap">{{ $approval['comment'] }}@if($approval['voided_at'])<div class="ans-help">no longer counts: {{ $approval['void_reason'] }}</div>@endif</td>
                        </tr>
                    @empty
                        <tr><td class="text-muted" id="ans-approvals-none">{{ ApprovalRules::needed($settings->approval) ? 'None yet.' : 'None needed: the settings ask for no approval.' }}</td></tr>
                    @endforelse
                    @if($approvalExpires)
                        <tr><td colspan="3" class="ans-muted">The approval counts until {{ Clock::show($approvalExpires, 'Y-m-d H:i T') }}.</td></tr>
                    @endif
                </table>
            </div>
        </div>
    </div>

    <div class="panel panel-default" id="ans-dry-run">
        <div class="panel-heading"><strong>Dry run per device</strong>
            <span class="text-muted">(results from the PLAY RECAP; what would change from the diffs Ansible printed: for cisco.ios.ios_config, diff against an intended configuration, <span class="ans-code">diff_against: intended</span> with <span class="ans-code">intended_config</span>, or run with -v to see its commands, outside loops)</span></div>
        <div class="table-responsive">
        <table class="table table-condensed ans-table ans-stack" id="ans-dry-run-devices">
            <thead><tr><th>Device</th><th>Batch</th><th>Dry run</th>@foreach($counters as $c)<th class="ans-num">{{ $c }}</th>@endforeach<th>What would change</th></tr></thead>
            <tbody>
            @foreach($targets as $deviceId => $target)
                @php $blocks = $diffs[$deviceId] ?? []; $address = Connections::address($reached, (int) $deviceId); @endphp
                <tr data-device="{{ $deviceId }}">
                    <td class="ans-wrap"><a href="{{ url('device/' . $deviceId) }}">{{ $target['display'] ?: $target['hostname'] }}</a><div class="ans-code ans-muted">{{ $target['inventory_name'] }}</div>
                        @if($address !== null)<div class="ans-muted ans-dry-address" title="The address the runner reached this device at in the dry run (its overwrite IP, else its hostname). A batch runs on it only while the inventory still gives this address and platform.">dry-run at <span class="ans-code">{{ $address }}</span></div>@endif</td>
                    <td data-label="Batch">{{ $target['batch'] }}</td>
                    <td data-label="Dry run">@if($dryState === 'unverified' && $target['dry_result'])<span class="label label-danger ans-dry-result ans-not-proven" title="The log does not prove this was a dry run: whatever the PLAY RECAP says, it may have changed this device">NOT PROVEN: may have changed this device</span>@elseif($target['dry_result'])<span class="label label-{{ $dryCss[$target['dry_result']] ?? 'default' }} ans-dry-result">{{ ChangeText::DRY_RESULTS[$target['dry_result']] ?? $target['dry_result'] }}</span>@else<span class="text-muted">-</span>@endif</td>
                    @foreach($counters as $c)<td class="ans-num ans-stack-inline" data-label="{{ $c }}">{{ $target['dry_counts'][$c] ?? '' }}</td>@endforeach
                    <td class="ans-diff-cell" data-label="What would change">
                        @forelse($blocks as $block)
                            <details class="ans-diff-block" @if(count($blocks) <= 3) open @endif>
                                <summary class="ans-muted">{{ $block['task'] ?: 'task' }}{{ $block['item'] !== null ? ' · item ' . $block['item'] : '' }} · {{ ['diff' => 'diff', 'commands' => 'commands', 'text' => 'the module\'s text'][$block['kind']] ?? $block['kind'] }}{{ $block['cut'] ? ' (cut)' : '' }}</summary>
                                <pre class="ans-diff">@foreach((array) $block['lines'] as $line)<span class="{{ $block['kind'] === 'diff' ? (str_starts_with($line, '+') && ! str_starts_with($line, '+++') ? 'ans-diff-add' : (str_starts_with($line, '-') && ! str_starts_with($line, '---') ? 'ans-diff-del' : (str_starts_with($line, '@@') ? 'ans-diff-hunk' : ''))) : '' }}">{{ $line }}</span>
@endforeach</pre>
                            </details>
                        @empty
                            @if($target['dry_result'] === 'changed' && ($unattributedOf[$target['inventory_name']] ?? 0) > 0)
                                <span class="ans-muted ans-diff-elsewhere">would change; what it printed is <a href="#ans-unattributed">not attributed</a> ({{ $unattributedOf[$target['inventory_name']] }}
                                    {{ $unattributedOf[$target['inventory_name']] === 1 ? 'block' : 'blocks' }} naming it below, with why)</span>
                            @elseif($target['dry_result'] === 'changed')
                                <span class="ans-muted">would change; the module printed no diff</span>
                            @endif
                        @endforelse
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @if($unattributed)
            <div class="panel-body" id="ans-unattributed">
                <p class="text-warning"><i class="fa fa-question-circle"></i> <strong>Diffs the plugin does not attribute to a device</strong> (device output may imitate Ansible's lines; the reason is given for each):</p>
                @foreach($unattributed as $block)
                    <details class="ans-diff-block">
                        <summary class="ans-muted">{{ $block['task'] ?: 'task' }}{{ $block['item'] !== null ? ' · item ' . $block['item'] : '' }} · named {{ $block['host'] }}: {{ $block['reason'] }}</summary>
                        <pre class="ans-diff">@foreach((array) $block['lines'] as $line){{ $line }}
@endforeach</pre>
                    </details>
                @endforeach
            </div>
        @endif
    </div>

    <div class="row">
        <div class="col-md-7">
            <div class="panel panel-default" id="ans-backups">
                <div class="panel-heading"><strong>Backups</strong> <span class="text-muted">({{ $oxidized ? ($settings->backups ? 'Oxidized, before and after each batch; ' . ($settings->refuseWithoutBackup ? 'a batch without a fresh backup is refused' : 'a batch without a fresh backup goes with a warning') : 'switched off in the settings') : 'LibreNMS does not use Oxidized' }})</span></div>
                <div class="table-responsive">
                <table class="table table-condensed ans-table" id="ans-backups-table">
                    <thead><tr><th>Device</th><th>Before</th><th>After</th><th></th></tr></thead>
                    <tbody>
                    @foreach($targets as $deviceId => $target)
                        <tr data-device="{{ $deviceId }}">
                            <td class="ans-wrap">{{ $target['display'] ?: $target['hostname'] }}</td>
                            @foreach(['before', 'after'] as $phase)
                                <td class="ans-backup" data-phase="{{ $phase }}">
                                    @if($target["{$phase}_status"])
                                        <span class="label label-{{ $backupCss[$target["{$phase}_status"]] ?? 'default' }}">{{ BackupState::LABELS[$target["{$phase}_status"]] ?? $target["{$phase}_status"] }}</span>
                                        @if($target["{$phase}_version"])<span class="ans-code" title="Oxidized version">{{ $short($target["{$phase}_version"]) }}</span>@endif
                                        <div class="ans-help">{{ $target["{$phase}_at"] ? Clock::local($target["{$phase}_at"]) : ($target["{$phase}_requested_at"] ? 'asked ' . Clock::local($target["{$phase}_requested_at"]) : '') }}{{ $target["{$phase}_error"] ? ' · ' . $target["{$phase}_error"] : '' }}</div>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                            @endforeach
                            <td><a href="{{ url('device/' . $deviceId . '/config') }}" class="btn btn-xs btn-default" title="LibreNMS' config page of the device: Oxidized's versions and diffs"><i class="fa fa-align-justify"></i> Config</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="panel panel-default" id="ans-windows">
                <div class="panel-heading"><strong>Maintenance windows</strong> <span class="text-muted">({{ $settings->maintenance ? 'one per batch, closed ' . intdiv($settings->settleSeconds, 60) . ' min after it' : 'switched off in the settings' }})</span>
                    <a class="pull-right" href="{{ url('alert-schedule') }}">LibreNMS' maintenance</a></div>
                <table class="table table-condensed ans-table" id="ans-windows-table">
                    @forelse($windows as $window)
                        <tr data-window="{{ $window['schedule_id'] }}">
                            <td>#{{ $window['schedule_id'] }}<br><span class="ans-muted">batch {{ $window['batch'] }}</span></td>
                            <td><span class="label label-{{ $window['status'] === 'open' ? 'info' : ($window['status'] === 'closed' ? 'success' : 'warning') }} ans-window-state">{{ $window['status'] === 'open' ? ($window['closing'] ? 'closes at its end' : 'open') : (['closed' => 'closed', 'gone' => 'removed in LibreNMS'][$window['status']] ?? $window['status']) }}</span></td>
                            <td class="ans-muted">{{ $local($window['opened_at']) }} – {{ Clock::local($window['status'] === 'closed' ? $window['closed_at'] : $window['ends_at'], 'Y-m-d H:i:s T') }}{{ $window['error'] ? ' · ' . $window['error'] : '' }}</td>
                        </tr>
                    @empty
                        <tr><td class="text-muted">{{ $settings->maintenance ? 'None yet: the plugin opens one before each batch.' : 'None: maintenance windows are switched off in the settings.' }}</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </div>

    <div class="panel panel-default" id="ans-change-log-panel">
        <div class="panel-heading"><strong>Log</strong>
            <span class="text-muted" id="ans-change-log-title">@if($run){{ $run['kind'] === 'dry_run' ? 'dry run' : 'batch ' . $run['batch'] }}, <a href="{{ route('ansible.runs.show', $run['id']) }}">run #{{ $run['id'] }}</a> ({{ RunStatus::label($run['status']) }})@elseif($logNote){{ $logNote }}@else nothing runs now @endif</span>
            <span class="text-muted" id="ans-log-state">{{ ! $final || $pending ? '(live: refreshed every 2 seconds)' : '' }}</span></div>
        <div class="panel-body" style="padding: 6px">
            <div class="ans-log-bar">
                <label style="font-weight: normal; margin: 0"><input type="checkbox" id="ans-log-follow" checked> follow the end</label>
                <span class="ans-muted" id="ans-log-count">{{ number_format($next) }} lines</span>
            </div>
            <pre class="ans-log" id="ans-log">@foreach($lines as $line)<span class="ans-line">{!! \Agoodley\LibrenmsAnsible\Runs\Ansi::html($line) !!}</span>
@endforeach</pre>
        </div>
    </div>

    <div class="panel panel-default" id="ans-timeline">
        <div class="panel-heading"><strong>Timeline</strong> <span class="text-muted">(newest first; each target's eventlog in LibreNMS has these steps too)</span></div>
        <table class="table table-condensed ans-table ans-stack" id="ans-timeline-table">
            @foreach(array_reverse($events) as $event)
                <tr data-kind="{{ $event['kind'] }}"><td style="white-space: nowrap; width: 160px" class="ans-muted ans-stack-inline">{{ $local($event['at']) }}</td><td style="width: 110px" class="ans-stack-inline">{{ $event['username'] ?? 'the plugin' }}</td>
                    <td class="ans-wrap">{{ Clock::localize($event['message']) }}</td></tr>
            @endforeach
        </table>
    </div>
    @php
        $config = [
            'poll' => route('ansible.api.changes.show', $change['id']),
            'actions' => [
                'dry-run' => route('ansible.changes.dry-run', $change['id']), 'approve' => route('ansible.changes.approve', $change['id']),
                'reject' => route('ansible.changes.reject', $change['id']), 'apply' => route('ansible.changes.apply', $change['id']),
                'continue' => route('ansible.changes.continue', $change['id']), 'stop' => route('ansible.changes.stop', $change['id']),
                'cancel' => route('ansible.changes.cancel', $change['id']),
            ],
            'final' => $final && ! $pending,
            'over' => $final,
            'signature' => $signature,
            'run' => $run['id'] ?? null,
            'next' => $next,
            'targets' => count($targets),
            'batches' => (int) $change['batch_count'],
            // what a batch waits for before it starts, as the settings are
            'prepare' => implode(' and ', array_filter([$settings->maintenance ? 'maintenance window' : null, $settings->backups && $oxidized ? 'backups' : null])),
            'currentBatch' => (int) $change['current_batch'],
            'waiting' => $change['waiting'],
            'hasSecrets' => (bool) $secretVars,
            'dryRuns' => (int) $change['dry_runs'],
        ];
    @endphp
    {{-- inside the page's root, so that the page's refresh (it fetches itself again) brings it along; @json() splits its
         argument on commas: it gets one variable --}}
    <script type="application/json" id="ans-change-config">@json($config)</script>
</div>
@endsection

@section('scripts')
    <script src="{{ route('ansible.asset', 'ansible.js') }}?v={{ $assetVersion }}"></script>
    <script>AnsiblePlugin.change(JSON.parse(document.getElementById('ans-change-config').textContent));</script>
@endsection
