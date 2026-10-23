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
    // phase 4: a batch whose secret answers came later than its state before it counts: the state is taken again, then the page that holds them launches it
    $retaking = \Agoodley\LibrenmsAnsible\Changes\ChangeLifecycle::beforeRetaking($change, $batches[(int) $change['current_batch']] ?? []);
    $waitText = ['canary' => 'waits for an admin to continue after the first batch',
        'secrets' => $retaking ? 'batch ' . (int) $change['current_batch'] . '\'s state before it is taken again (its secret answers came later than it counts), then the page '
            . 'that holds them launches it; if none does by ' . (Clock::local($change['resume_at'], 'Y-m-d H:i T') ?? '?') . ', the change ends'
            : 'waits for the secret answers until ' . (Clock::local($change['resume_at'], 'Y-m-d H:i T') ?? '?') . ' (the plugin never keeps them), then the change ends',
        'timer' => 'pause between batches until ' . (Clock::local($change['resume_at'], 'Y-m-d H:i T') ?? '?'),
        'blast' => 'batch ' . (int) $change['current_batch'] . ' waits for an approver to accept its new blast radius (it grew since the approval)',
        'blast_unknown' => 'batch ' . (int) $change['current_batch'] . ' waits: its blast radius cannot be computed now (checked again every minute: the batch starts '
            . 'once it can be); Continue computes it first, and goes without one only while it still cannot',
        'verification' => 'batch ' . (int) $change['current_batch'] . ' made unexpected changes: batch ' . ((int) $change['current_batch'] + 1) . ' waits for an admin to accept them',
        'verify_unknown' => 'batch ' . (int) $change['current_batch'] . ' cannot be verified (checked again every minute): batch ' . ((int) $change['current_batch'] + 1)
            . ' waits for an admin to accept going on without its verification'];
    // phase 4: the verification of the batches
    // a change applied without verification (Verify changes off, or no topology plugin then) has its outcome from its Apply on;
    // one not applied yet says when a change applied now would not be verified ($verifyWhy)
    $outcome = $change['verification'] ?? null;
    $verifyWhy = $change['apply_started_at'] === null && ! $final ? ($verifyWhy ?? null) : null;
    // applied before the upgrade to phase 4: its first batch ran before it, not verified, and it goes on as in phase 3
    $beforeUpgrade = \Agoodley\LibrenmsAnsible\Changes\ChangeLifecycle::appliedBefore($change, $batches);
    $verifyOn = $settings->verify || $verification || $outcome !== null || $beforeUpgrade;
    $verifyCurrent = $verification[(int) $change['current_batch']] ?? null;
    $verifyWait = in_array($change['waiting'], \Agoodley\LibrenmsAnsible\Changes\ChangeLifecycle::VERIFY_WAITS, true) && ! $final ? $verifyCurrent : null;
    $resultCss = ['verified' => 'success', 'unexpected' => 'danger', 'unknown' => 'warning'];
    $resultLabels = ['verified' => 'verified', 'unexpected' => 'unexpected changes', 'unknown' => 'cannot be verified'];
    // the blast radius of the batch the change waits for (it grew, or cannot be computed), as computed before it
    $waitBatch = in_array($change['waiting'], ['blast', 'blast_unknown'], true) && ! $final ? ($batches[(int) $change['current_batch']] ?? null) : null;
    $waitBlast = is_array($waitBatch['blast'] ?? null) ? $waitBatch['blast'] : null;
    $blastNames = fn (?array $record) => (array) ($record['names'] ?? []) + ($topology?->names ?? []) + array_filter(array_map(fn ($t) => (string) ($t['display'] ?: $t['hostname']), $targets));
    $deviceName = fn (int $id, array $names) => (string) (($names[$id] ?? '') ?: "#$id");
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
        $waits = ($currentBatch['status'] ?? null) === 'pending' && str_starts_with((string) ($currentBatch['note'] ?? ''), 'waits: ');
        // a batch blocked by its blast radius (it grew, or cannot be computed) waits: it is not about to start
        $blocked = $status === ChangeStatus::PAUSED && in_array($change['waiting'], ['blast', 'blast_unknown'], true) && ($currentBatch['status'] ?? null) === 'pending';
        // phase 4: nor is a batch whose state before it waits for fresh data
        $beforeNow = (array) (((array) ($currentBatch['verify'] ?? []))['before'] ?? []);
        $dataWait = in_array($beforeNow['status'] ?? null, ['refresh', 'waiting', 'retake'], true) && ($currentBatch['status'] ?? null) === 'pending' && ! $final
            ? 'waits for fresh data for the state before it' . (($beforeNow['status'] ?? null) === 'waiting' && (int) ($beforeNow['deadline'] ?? 0) > time()
                ? ', at most until ' . Clock::show((int) $beforeNow['deadline'], 'H:i T') : '') : null;
        $logNote = $final || $currentBatch === null ? 'no batch was launched' : 'batch ' . (int) $change['current_batch'] . ($waits
            ? ' ' . Clock::localize((string) $currentBatch['note']) : ': ' . ($blocked ? (string) ChangeStatus::waitingText($change['waiting']) : match ($currentBatch['status']) { // "batch 2 waits: lab-sw3 is busy in run #92 ..."
            'pending' => $dataWait ?? 'about to start',
            'preparing' => 'preparing' . ($preparing !== '' ? " ($preparing)" : ''),
            'prepared' => 'waits for the secret answers',
            'launching' => 'being launched',
            default => $batchLabels[$currentBatch['status']] ?? (string) $currentBatch['status'],
        }));
    }
@endphp

@section('content')
<div class="container-fluid ans-page" id="ans-change" data-change="{{ $change['id'] }}">
    @include('ansible::partials.nav', ['active' => 'change', 'changeId' => $change['id']])

    <div class="ans-cards">
        @php
            // while the page holds the secret answers it launches the batch that waits for them itself: shown as applying
            $heldAttributes = $change['waiting'] === 'secrets' && ! $final;
            $cardCss = fn (string $state) => 'ans-card ans-' . (['success' => 'ok', 'danger' => 'critical', 'warning' => 'warning'][ChangeStatus::css($state)] ?? 'info');
            // a batch the plugin started on its own that waits for a device busy in another run: until when
            $currentRow = $batches[(int) $change['current_batch']] ?? null;
            $busyUntil = $status === ChangeStatus::APPLYING && ($currentRow['status'] ?? null) === 'pending' && str_starts_with((string) ($currentRow['note'] ?? ''), 'waits: ')
                && ($currentRow['started_at'] ?? null) !== null ? Clock::unix($currentRow['started_at']) + \Agoodley\LibrenmsAnsible\Changes\ChangeLifecycle::waitSeconds($settings) : null;
        @endphp
        <div class="{{ $cardCss($status) }}" id="ans-change-status-card" @if($heldAttributes) data-held-class="{{ $cardCss(ChangeStatus::APPLYING) }}" @endif>
            <h4>Status</h4>
            <div class="ans-card-value"><span id="ans-change-status" class="label label-{{ ChangeStatus::css($status) }}" @if($heldAttributes) data-held-text="{{ ChangeStatus::label(ChangeStatus::APPLYING) }}" data-held-class="label label-{{ ChangeStatus::css(ChangeStatus::APPLYING) }}" @endif>{{ ChangeStatus::label($status) }}</span></div>
            <p>@if(in_array($status, ChangeStatus::RESERVING, true) || $status === ChangeStatus::APPLIED)batch {{ $change['current_batch'] }} of {{ $change['batch_count'] }}@else{{ $change['batch_count'] }} {{ (int) $change['batch_count'] === 1 ? 'batch' : 'batches' }}@endif
                @if($change['waiting'])<span id="ans-change-waiting" @if($change['waiting'] === 'secrets') data-held-text=" · batch {{ (int) $change['current_batch'] }} is launched with the secret answers this page holds"@endif> · {{ $waitText[$change['waiting']] ?? '' }}</span>@endif
                @if($busyUntil)<span id="ans-change-busy-wait"> · batch {{ (int) $change['current_batch'] }} waits for a device busy in another run until {{ Clock::show($busyUntil, 'Y-m-d H:i T') }}, then the change ends</span>@endif
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
        @if($verifyOn)
            @php
                $verifyCss = $outcome !== null ? ['verified' => 'ok', 'accepted' => 'warning', 'not_verified' => 'critical'][$outcome] ?? 'info'
                    : ($beforeUpgrade || ($verifyCurrent['result'] ?? null) === 'unexpected' ? 'critical' : (($verifyCurrent['result'] ?? null) === 'unknown' || $verifyWhy !== null ? 'warning' : 'info'));
                $verifyNow = $outcome !== null ? ucfirst((string) \Agoodley\LibrenmsAnsible\Changes\VerificationText::label($outcome))
                    : ($final || $beforeUpgrade ? 'Not verified' : ($change['apply_started_at'] === null ? ($verifyWhy !== null ? 'Not if applied now' : 'Each batch') : 'Batch ' . (int) $change['current_batch'] . ': '
                        . (isset($verifyCurrent['result']) ? $resultLabels[$verifyCurrent['result']] ?? $verifyCurrent['result'] : (in_array($verifyCurrent['after']['status'] ?? null, ['waiting', 'settling'], true)
                            ? 'waits for fresh data' : (in_array($verifyCurrent['before']['status'] ?? null, ['refresh', 'waiting', 'take'], true) ? 'state before it' : 'to do')))));
            @endphp
            <div class="ans-card ans-{{ $verifyCss }}" id="ans-verify-card">
                <h4>Verification</h4>
                <div class="ans-card-value" style="font-size: 17px" id="ans-verify-state">{{ $verifyNow }}</div>
                <p id="ans-verify-note">@if($outcome !== null && ($change['verification_note'] ?? null)){{ \Agoodley\LibrenmsAnsible\Changes\VerificationText::note($change['verification_note'],
                        ! $verification && $change['apply_started_at'] !== null) }}@elseif($beforeUpgrade){{ ucfirst((string) \Agoodley\LibrenmsAnsible\Changes\VerificationText::note(
                        \Agoodley\LibrenmsAnsible\Changes\VerificationOutcome::BEFORE_UPGRADE)) }}@elseif($verifyWhy !== null){{ ucfirst($verifyWhy) }}: a change applied now runs without a verification
                    @elseif(! $settings->verify && ! $final)switched off in the settings @else the network around each batch, before and after it (topology plugin)@endif</p>
            </div>
        @endif
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
            $asRecorded = $status === ChangeStatus::DRY_RUN ? 'as when the dry run was launched' : 'as at the dry run';
        @endphp
        @if($fetchedWithout || $withheldNow)
            <div class="alert alert-warning" id="ans-change-withheld"><i class="fa fa-eye-slash"></i>
                @if($fetchedWithout)
                    <span id="ans-change-fetched-without"><strong>Left out of an inventory the runner fetched during {{ $phase }}:</strong>
                        {{ implode('; ', $fetchedWithout) }}. The runner no longer reaches {{ count($fetchedWithout) === 1 ? 'it' : 'them' }} {{ $asRecorded }}, so {{ $phase }}
                        may not have run on {{ count($fetchedWithout) === 1 ? 'it' : 'them' }}, and {{ $outcome }}.</span>
                @endif
                @if($withheldNow)
                    <span id="ans-change-withheld-now"><strong>Withheld from the runner while {{ $phase }} is in progress:</strong> {{ implode('; ', $withheldNow) }}.
                        The runner no longer reaches {{ count($withheldNow) === 1 ? 'it' : 'them' }} {{ $asRecorded }}, so the inventory endpoint leaves
                        {{ count($withheldNow) === 1 ? 'it' : 'them' }} out: if the runner fetches the inventory before that is set back, {{ $phase }} does not run on
                        {{ count($withheldNow) === 1 ? 'it' : 'them' }}, and {{ $outcome }}.</span>
                @endif
                <a href="{{ route('ansible.inventory') }}">Inventory</a>
            </div>
        @endif
        @if($waitBatch !== null && $change['waiting'] === 'blast')
            @php $names = $blastNames($waitBlast); [$heldSince, $heldWord] = \Agoodley\LibrenmsAnsible\Changes\ChangeText::held($change, ! empty($waitBlast['accepted'])); @endphp
            <div class="ans-warning-loud" id="ans-blast-grew"><i class="fa fa-bolt"></i>
                Batch {{ (int) $change['current_batch'] }} does not start: its blast radius grew since {{ $heldSince }}.
                @if(! empty($waitBlast['core_lost']))<strong id="ans-blast-core-lost">It now takes down every core device of its part</strong> (it was {{ $heldWord }} with one left standing),
                    so what it cuts off is measured from the largest piece left, not from the core.@endif
                While it runs it would now cut off
                @forelse((array) ($waitBlast['lost'] ?? []) as $lostId)<a href="{{ url('device/' . $lostId) }}" class="{{ in_array($lostId, (array) ($waitBlast['added'] ?? []), true) ? 'ans-blast-added' : '' }}">{{ $deviceName((int) $lostId, $names) }}</a>@if(! $loop->last), @endif @empty no other monitored device @endforelse
                @if((int) ($waitBlast['unmanaged'] ?? 0) > 0) and {{ (int) $waitBlast['unmanaged'] }} unmanaged {{ (int) $waitBlast['unmanaged'] === 1 ? 'neighbor' : 'neighbors' }}@endif{{ (array) ($waitBlast['added'] ?? []) ? ':' : '' }}
                @if((array) ($waitBlast['added'] ?? []))<strong>{{ \Agoodley\LibrenmsAnsible\Changes\BlastRadius::listText((array) ($waitBlast['added'] ?? []), $names) }}</strong>
                    {{ count((array) ($waitBlast['added'] ?? [])) === 1 ? 'was' : 'were' }} not {{ $heldWord }}@endif
                ({{ $heldWord }}: {{ \Agoodley\LibrenmsAnsible\Changes\BlastRadius::listText((array) ($waitBlast['held'] ?? []), $names) }}).
                @if(! empty($waitBlast['unplanned']))<span id="ans-blast-grew-unplanned">Besides, {{ $waitBlast['error'] ?? '' }}: once the new blast radius is accepted, the batch waits for an admin to continue it without one.</span>@endif
                @if(! empty($waitBlast['simulate']))<a href="{{ url($waitBlast['simulate']) }}" class="btn btn-xs btn-default" id="ans-blast-grew-simulate" target="_blank" rel="noopener"><i class="fa fa-bolt"></i> Simulate it on the L2 map</a>@endif
                <div style="font-weight: normal; margin-top: 4px">{{ ucfirst(ApprovalRules::accepter($settings->approval)) }} accepts the new blast radius
                    (recorded in the timeline and the eventlog), and the batch starts again; or stop the change. Computed {{ $local($waitBlast['at'] ?? null) }}, as {{ $waitBatch['username'] ?: 'the admin who starts the batch' }} sees the network.</div>
            </div>
            @if($noAccepter)
                <div class="alert alert-warning" id="ans-no-accepter"><i class="fa fa-user-times"></i> {{ $noAccepter }} The change waits, its devices reserved for it,
                    until an admin grants it on the plugin's <a href="{{ url('plugin/settings/ansible') }}#{{ ApprovalRules::acceptPermission($settings->approval) === 'run' ? 'ans-permission' : 'ans-approve-permission' }}">settings page</a>,
                    or stops the change.</div>
            @endif
        @elseif($waitBatch !== null && $change['waiting'] === 'blast_unknown')
            @php
                $names = $blastNames($waitBlast);
                $held = (array) ($waitBlast['window'] ?? $waitBlast['lost'] ?? []);
                $unplanned = array_values(array_filter((array) ($waitBlast['unplanned'] ?? []), 'is_array'));
                $fix = $unplanned ? ' (' . implode('; ', array_map(fn (array $u) => $deviceName((int) ($u['device_id'] ?? 0), $names) . ': '
                    . \Agoodley\LibrenmsAnsible\Changes\TopologyPlan::fixText((string) ($u['reason'] ?? '')), $unplanned)) . ')'
                    : (str_contains((string) ($waitBlast['error'] ?? ''), 'disabled') ? ' (enable the topology plugin again in Plugin Admin)' : '');
            @endphp
            <div class="ans-warning-loud" id="ans-blast-unknown"><i class="fa fa-question-circle"></i>
                Batch {{ (int) $change['current_batch'] }} does not start: its blast radius cannot be computed now ({{ $waitBlast['error'] ?? 'no answer' }}).
                <div style="font-weight: normal; margin-top: 4px">Fix that{{ $fix }}: the plugin checks again every minute (last {{ $local($waitBlast['at'] ?? null) }}) and starts
                    the batch once its blast radius can be computed. Or {{ ApprovalRules::accepter($settings->approval) }} continues it: <em>Continue</em> computes it first;
                    only if it still cannot be computed does the batch go without one, its maintenance window then covering its devices and the devices
                    {{ \Agoodley\LibrenmsAnsible\Changes\ChangeText::held($change, false)[1] }}{{ is_array($waitBatch['blast_accepted'] ?? null) ? ' or accepted' : '' }} as cut off
                    ({{ \Agoodley\LibrenmsAnsible\Changes\BlastRadius::listText(array_map('intval', $held), $names, 10, 'none') }}), the decision recorded in the timeline
                    and the eventlog. Or stop the change.</div>
            </div>
            @if($noAccepter)
                <div class="alert alert-warning" id="ans-no-accepter"><i class="fa fa-user-times"></i> {{ $noAccepter }} The change waits, its devices reserved for it,
                    until an admin grants it on the plugin's <a href="{{ url('plugin/settings/ansible') }}#ans-permission">settings page</a>, or stops the change.</div>
            @endif
        @endif
        @if($verifyWait !== null)
            @php $verifyEval = (array) ($verifyWait['evaluation'] ?? []); @endphp
            <div class="ans-warning-loud" id="{{ $change['waiting'] === 'verification' ? 'ans-verify-unexpected' : 'ans-verify-unknown' }}"><i class="fa fa-{{ $change['waiting'] === 'verification' ? 'exclamation-triangle' : 'question-circle' }}"></i>
                @if($change['waiting'] === 'verification')
                    Batch {{ (int) $change['current_batch'] }} made {{ \Agoodley\LibrenmsAnsible\Support\Format::count((int) ($verifyWait['counts']['unexpected'] ?? 0), 'unexpected change') }}:
                    <ul style="margin: 4px 0 0; font-weight: normal" id="ans-verify-unexpected-list">@foreach(array_slice((array) ($verifyEval['unexpected'] ?? []), 0, 20) as $entry)<li data-type="{{ $entry['type'] }}">{{ $entry['text'] }}</li>@endforeach</ul>
                    <div style="font-weight: normal; margin-top: 4px">Batch {{ (int) $change['current_batch'] + 1 }} does not start before {{ ApprovalRules::accepter($settings->approval) }} accepts them
                        (recorded in the timeline and the eventlog); or stop the change. Compared {{ Clock::show((int) ($verifyWait['after']['taken_at'] ?? 0), 'Y-m-d H:i T') }} on fresh data,
                        as {{ ($batches[(int) $change['current_batch']]['username'] ?? '') ?: 'the admin who started the batch' }} sees the network.</div>
                @else
                    Batch {{ (int) $change['current_batch'] }} cannot be verified: {{ $verifyWait['error'] ?? 'unknown' }}.
                    <div style="font-weight: normal; margin-top: 4px">@if(($verifyWait['after']['status'] ?? null) === 'incomplete')The plugin takes a new state after it every 5 minutes
                        and goes on by itself once the topology plugin reads all of it. @elseif(($verifyWait['before']['status'] ?? null) === 'taken')The plugin checks again every minute and goes on by
                        itself once the batch can be verified. @endif Or {{ ApprovalRules::accepter($settings->approval) }} accepts going on without its verification (recorded); or stop the change.</div>
                @endif
            </div>
            @if($noAccepter)
                <div class="alert alert-warning" id="ans-no-accepter"><i class="fa fa-user-times"></i> {{ $noAccepter }} The change waits, its devices reserved for it,
                    until an admin grants it on the plugin's <a href="{{ url('plugin/settings/ansible') }}#ans-permission">settings page</a>, or stops the change.</div>
            @endif
        @endif
        @if($laterBusy)
            <div class="alert alert-warning" id="ans-change-later-busy"><i class="fa fa-clock-o"></i> <strong>Busy devices of later batches:</strong> {{ $laterBusy }}.
                A batch waits for its devices when it starts, at most {{ intdiv(\Agoodley\LibrenmsAnsible\Changes\ChangeLifecycle::waitSeconds($settings), 60) }} minutes;
                then the change ends.</div>
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
                @if($secretVars && ($actions['dry_run'] || $actions['apply'] || ($actions['continue'] && $continueAs === null)))
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
                    @if($actions['approve'] || $actions['reject'] || $actions['accept'] || $actions['accept_verification'])
                        <input type="text" class="form-control input-sm" id="ans-approval-comment" maxlength="500" placeholder="comment (optional)" style="width: 240px">
                    @endif
                    @if($actions['accept'])
                        <button type="button" class="btn btn-sm btn-warning" data-action="accept" id="ans-action-accept"><i class="fa fa-check"></i> Accept the new blast radius of batch {{ (int) $change['current_batch'] }}</button>
                    @endif
                    @if($actions['accept_verification'])
                        <button type="button" class="btn btn-sm btn-warning" data-action="accept-verification" id="ans-action-accept-verification"><i class="fa fa-check"></i>
                            {{ $change['waiting'] === 'verification' ? 'Accept what batch ' . (int) $change['current_batch'] . ' changed' : 'Go on without the verification of batch ' . (int) $change['current_batch'] }}</button>
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
                        <button type="button" class="btn btn-sm {{ $change['waiting'] === 'blast_unknown' ? 'btn-warning' : 'btn-success' }}" data-action="continue" id="ans-action-continue"><i class="fa fa-forward"></i> {{ match ($change['waiting']) {
                            'secrets' => 'Launch batch ' . $change['current_batch'],
                            'blast_unknown' => 'Continue batch ' . $change['current_batch'],
                            default => 'Continue with batch ' . ((int) $change['current_batch'] + 1),
                        } }}</button>
                    @endif
                    @if($actions['stop'])
                        <button type="button" class="btn btn-sm btn-danger" data-action="stop" id="ans-action-stop"><i class="fa fa-stop"></i> {{ $status === ChangeStatus::DRY_RUN ? 'Stop the dry run' : 'Stop the change' }}</button>
                    @endif
                    @if($actions['cancel'])
                        <button type="button" class="btn btn-sm btn-default" data-action="cancel" id="ans-action-cancel"><i class="fa fa-ban"></i> Cancel the change</button>
                    @endif
                    <span class="ans-muted" id="ans-action-state"></span>
                </div>
                @if(in_array($change['waiting'], \Agoodley\LibrenmsAnsible\Changes\ChangeLifecycle::VERIFY_WAITS, true) && ! $actions['accept_verification'] && ! $final)
                    <p class="ans-help" id="ans-accept-verification-waits" style="margin-top: 6px">{{ $canAccept && $verifyRefusal ? $verifyRefusal : ucfirst(ApprovalRules::accepter($settings->approval)) . ' accepts it.' }}</p>
                @endif
                @if($actions['approve'] || $actions['reject'] || $actions['accept'] || $actions['accept_verification'])
                    <p class="ans-help" id="ans-comment-note" style="margin-top: 6px">The comment goes into the timeline and, as written, into the eventlog of each device of
                        the change: whoever may see one of those devices in LibreNMS can read it.</p>
                @endif
                @if($status === ChangeStatus::AWAITING && $canApprove && $approvalRefusal)
                    <p class="ans-help" id="ans-approval-refusal" style="margin-top: 6px">{{ $approvalRefusal }}</p>
                @elseif($status === ChangeStatus::AWAITING && ! $actions['approve'])
                    <p class="ans-help" id="ans-approval-waits" style="margin-top: 6px">{{ ApprovalRules::waitsFor($settings->approval) }}</p>
                @endif
                @if($change['waiting'] === 'blast' && ! $actions['accept'] && ! $final)
                    <p class="ans-help" id="ans-accept-waits" style="margin-top: 6px">{{ $canAccept && $acceptRefusal ? $acceptRefusal : ucfirst(ApprovalRules::accepter($settings->approval)) . ' accepts the new blast radius.' }}</p>
                @endif
                @if($change['waiting'] === 'blast_unknown' && $continueRefusal !== null && ! $final)
                    <p class="ans-help" id="ans-continue-waits" style="margin-top: 6px">{{ $continueRefusal }}</p>
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
                <div class="panel-heading"><strong>Batches</strong> <span class="text-muted">({{ \Agoodley\LibrenmsAnsible\Support\Format::count(count($targets), 'device') }}; stops at {{ $plan['stop_at'] ?? 1 }} failed {{ ($plan['stop_at'] ?? 1) == 1 ? 'device' : 'devices' }} in a batch{{ ! empty($plan['wait_after_first']) && count($batches) > 1 ? '; waits for an admin after the first batch' : '' }}{{ ! empty($plan['pause_minutes']) ? '; ' . $plan['pause_minutes'] . ' min between batches' : '' }})</span>
                    @if($topology && $topology->allByHand())
                        <div class="ans-help" id="ans-batches-planner">Planned by hand: the topology plugin could plan none of these devices when the change was made, so nothing
                            tells what depends on them. Each batch is checked again before it starts and right before its launch: one that would cut off a device by then waits for
                            an admin to accept it.</div>
                    @elseif($topology)
                        <div class="ans-help" id="ans-batches-planner">Planned by the topology plugin: farthest from the core first, the core last, never two devices that back each other up in one batch.
                            What each batch cuts off was computed when the change was made, and is part of what is dry-run and approved; it is computed again before the batch starts and
                            right before its launch, and a batch that would cut off more waits for an admin to accept it.
                            @if($topology->coreText() !== '')<span id="ans-batches-core">Measured from the core: {{ $topology->coreText() }}.</span>@endif</div>
                    @elseif($topologyPlanned)
                        <div class="ans-help text-danger" id="ans-batches-unreadable">The topology plugin's part of this change's plan cannot be read: its batches do not start.</div>
                    @else
                        @php
                            // why the settings planned them, as the change's creation recorded it
                            $unknownWhy = null;
                            foreach ($events as $event) {
                                if (preg_match('/^The blast radius of its batches is unknown: they were planned by the settings, as (.+)\.$/s', (string) $event['message'], $found)) {
                                    $unknownWhy = $found[1];
                                    break;
                                }
                            }
                        @endphp
                        <div class="ans-help" id="ans-blast-unknown-note"><i class="fa fa-question-circle"></i> Blast radius unknown: these batches were planned by the plugin's settings, not by the topology plugin{{ $unknownWhy !== null ? '. When the change was made: ' . $unknownWhy : '' }}.</div>
                    @endif
                </div>
                @if($topology && ! $topology->allByHand() && $topology->coreWarnings(($change['fix']['kind'] ?? null) === 'stp_root_priority'))
                    {{-- what the admin should check of the core, in the warning's colour (as the preview says it); a root priority fix starts with the core on purpose --}}
                    <div class="panel-body" id="ans-core-warnings" style="padding-bottom: 0">
                        @foreach($topology->coreWarnings(($change['fix']['kind'] ?? null) === 'stp_root_priority') as $warning)
                            <p class="ans-help text-warning ans-core-warning">{{ $warning }}</p>
                        @endforeach
                    </div>
                @endif
                <div class="table-responsive">
                <table class="table table-condensed ans-table ans-stack" id="ans-batches">
                    <thead><tr><th>Batch</th><th>Devices</th>@if($topology)<th>Down while it runs</th>@endif<th>State</th><th>Run</th><th>Results</th><th>When</th></tr></thead>
                    <tbody>
                    @foreach($batches as $number => $batch)
                        @php $batchRun = $batchRuns[$number] ?? null; @endphp
                        <tr data-batch="{{ $number }}">
                            <td data-label="Batch">{{ $number }}{{ $number === 1 && count($batches) > 1 ? ' (canary)' : '' }}@if($topology && isset($blast[$number]))<div class="ans-help ans-blast-tier">{{ $blast[$number]['tier_text'] }}</div>@endif</td>
                            <td class="ans-wrap" data-label="Devices">{{ implode(', ', array_map(fn ($d) => $targets[$d]['display'] ?: $targets[$d]['hostname'], $byBatch[$number] ?? [])) }}</td>
                            @if($topology)
                                @php
                                    $approvedBlast = $blast[$number] ?? null;
                                    $record = is_array($batch['blast'] ?? null) ? $batch['blast'] : null;
                                    $accepted = is_array($batch['blast_accepted'] ?? null) ? $batch['blast_accepted'] : null;
                                    $override = is_array($batch['blast_override'] ?? null) ? $batch['blast_override'] : null;
                                    $names = $blastNames($record);
                                @endphp
                                <td class="ans-wrap ans-blast" data-label="Down while it runs">
                                    @if($approvedBlast === null)
                                        <span class="text-muted">-</span>
                                    @elseif($approvedBlast['hand'])
                                        <span class="text-warning ans-blast-hand">planned by hand: nothing tells what depends on {{ count($byBatch[$number] ?? []) === 1 ? 'it' : 'them' }}</span>
                                        @if($approvedBlast['skipped'])<div class="ans-help">{{ implode('; ', array_map(fn ($s) => $s['name'] . ': ' . $s['text'], $approvedBlast['skipped'])) }}</div>@endif
                                    @else
                                        <span class="ans-blast-lost">@forelse($approvedBlast['lost'] as $lost)<a href="{{ url('device/' . $lost['device_id']) }}">{{ $lost['name'] }}</a>@if(! $loop->last), @endif @empty<span class="text-muted">no other monitored device</span>@endforelse</span>
                                        @if($approvedBlast['unmanaged'] > 0)<div class="ans-help">and {{ $approvedBlast['unmanaged'] }} unmanaged {{ $approvedBlast['unmanaged'] === 1 ? 'neighbor' : 'neighbors' }} (phones, access points...)</div>@endif
                                        @if($approvedBlast['reference'] !== 'core')<div class="ans-help">no core device of its part is left: counted from the largest piece that stays together</div>@endif
                                    @endif
                                    @if($approvedBlast !== null && $approvedBlast['simulate'] && ! $approvedBlast['hand'])<div><a href="{{ $approvedBlast['simulate'] }}" class="ans-blast-simulate" target="_blank" rel="noopener" title="The topology plugin's failure simulation of this batch on the L2 map, on the network as it is now"><i class="fa fa-bolt"></i> Simulate</a></div>@endif
                                    @if($accepted)
                                        <div class="ans-help ans-blast-accepted text-warning">accepted by {{ $accepted['by'] ?? '?' }} {{ $local($accepted['at'] ?? null) }}: {{ \Agoodley\LibrenmsAnsible\Changes\BlastRadius::listText(array_map('intval', (array) ($accepted['lost'] ?? [])), $names) }}</div>
                                    @endif
                                    @if($record)
                                        <div class="ans-help ans-blast-check" data-state="{{ $record['state'] ?? '' }}">
                                            @switch($record['state'] ?? '')
                                                @case('same')<span class="text-success">checked {{ $local($record['at'] ?? null) }}: as {{ \Agoodley\LibrenmsAnsible\Changes\ChangeText::held($change, (bool) $accepted)[1] }}</span>@break
                                                @case('shrank')<span class="text-success">checked {{ $local($record['at'] ?? null) }}: shrank, no longer {{ \Agoodley\LibrenmsAnsible\Changes\BlastRadius::listText(array_map('intval', (array) ($record['removed'] ?? [])), $names) }}</span>@break
                                                @case('grew')<span class="text-danger">checked {{ $local($record['at'] ?? null) }}: grew{{ ! empty($record['core_lost']) ? ', it now takes down every core device of its part' : '' }}{{ ($record['added'] ?? []) ? (! empty($record['core_lost']) ? '; also ' : ', also ') . \Agoodley\LibrenmsAnsible\Changes\BlastRadius::listText(array_map('intval', (array) $record['added']), $names) : '' }}</span>@break
                                                @case('unknown')<span class="text-danger">checked {{ $local($record['at'] ?? null) }}: cannot be computed ({{ $record['error'] ?? '' }})</span>@break
                                                @case('override')<span class="text-warning">continued without a blast radius by {{ $override['by'] ?? '?' }} {{ $local($override['at'] ?? null) }} ({{ $record['error'] ?? '' }})</span>@break
                                            @endswitch
                                            @php
                                                // what the check measured from, when it is not what the batch was approved (or accepted) with: the approved one is said above
                                                $checkedFrom = ($record['reference'] ?? 'core') === 'largest part' ? 'largest part' : 'core';
                                                $heldFrom = ($accepted['reference'] ?? $approvedBlast['reference'] ?? 'core') === 'largest part' ? 'largest part' : 'core';
                                            @endphp
                                            @if($checkedFrom !== $heldFrom && in_array($record['state'] ?? '', ['same', 'shrank', 'grew'], true) && empty($record['core_lost']))<div class="ans-blast-reference">{{ $checkedFrom === 'largest part' ? 'measured from the largest piece left: no core device of its part is left' : 'measured from the core: a core device of its part is left' }}</div>@endif
                                        </div>
                                    @endif
                                </td>
                            @endif
                            <td data-label="State"><span class="label label-{{ $batchCss[$batch['status']] ?? 'default' }} ans-batch-state" @if($batch['status'] === 'prepared' && ! $final) data-held-text="launching" @endif>{{ $batchLabels[$batch['status']] ?? $batch['status'] }}</span>
                                @if($batch['note'])<div class="ans-help ans-wrap">{{ Clock::localize($batch['note']) }}</div>@endif</td>
                            <td data-label="Run">@if($batchRun)<a href="{{ route('ansible.runs.show', $batchRun['id']) }}">#{{ $batchRun['id'] }}</a> <span class="label label-{{ RunStatus::css($batchRun['status']) }}">{{ RunStatus::label($batchRun['status']) }}</span>@endif</td>
                            <td data-label="Results">@if($batchRun)@include('ansible::partials.results', ['resultSummary' => $batchRun['summary'] ?? [], 'resultDryRun' => false, 'resultUnproven' => false])@endif</td>
                            <td class="ans-muted" style="white-space: nowrap" data-label="When">{{ $local($batch['started_at']) }}{{ $batch['ended_at'] ? ' – ' . Clock::local($batch['ended_at'], 'H:i:s') : '' }}{{ $batch['username'] && $batch['started_at'] ? ' · ' . $batch['username'] : '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
                @if($topology && $topology->leftOut)
                    <div class="panel-body ans-help" id="ans-left-out" style="padding-top: 0">Left out of the change, as the topology plugin cannot plan them (nothing tells what depends on them):
                        {{ \Agoodley\LibrenmsAnsible\Changes\RolloutPlan::reasonsText($topology->leftOut, $topology->names, 20) }}.</div>
                @endif
            </div>
            @if($verification)
                @include('ansible::partials.verification', ['verification' => $verification, 'resultCss' => $resultCss, 'resultLabels' => $resultLabels, 'final' => $final])
            @endif
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
                <div class="panel-heading"><strong>Maintenance windows</strong> <span class="text-muted">({{ $settings->maintenance ? 'one per batch' . ($topology ? ', on its devices and the devices it cuts off' : '') . ', closed ' . intdiv($settings->settleSeconds, 60) . ' min after it' : 'switched off in the settings' }})</span>
                    <a class="pull-right" href="{{ url('alert-schedule') }}">LibreNMS' maintenance</a></div>
                <table class="table table-condensed ans-table" id="ans-windows-table">
                    @forelse($windows as $window)
                        <tr data-window="{{ $window['schedule_id'] }}">
                            <td>#{{ $window['schedule_id'] }}<br><span class="ans-muted">batch {{ $window['batch'] }}</span>
                                @if($window['cut_off'] ?? [])<div class="ans-help ans-window-cut-off">also {{ \Agoodley\LibrenmsAnsible\Changes\BlastRadius::listText(array_map('intval', (array) $window['cut_off']), $blastNames(is_array($batches[(int) $window['batch']]['blast'] ?? null) ? $batches[(int) $window['batch']]['blast'] : null)) }}: cut off by the batch</div>@endif</td>
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
            <span class="text-muted" id="ans-change-log-title">@if($run){{ $run['kind'] === 'dry_run' ? 'dry run' : 'batch ' . $run['batch'] }}, <a href="{{ route('ansible.runs.show', $run['id']) }}">run #{{ $run['id'] }}</a> ({{ RunStatus::label($run['status']) }})@elseif($logNote)<span @if(($currentBatch['status'] ?? null) === 'prepared' && ! $final) data-held-text="batch {{ (int) $change['current_batch'] }}: being launched with the secret answers this page holds" @endif>{{ $logNote }}</span>@else nothing runs now @endif</span>
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
                'accept' => route('ansible.changes.accept-blast', $change['id']),
                'accept-verification' => route('ansible.changes.accept-verification', $change['id']),
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
            // the check of the blast radius this page shows, which Accept and Continue name (refused when it changed since)
            'blast' => $blastCheck,
            // the verification this page shows, which an acceptance names (refused when it changed since)
            'verify' => $verifyCheck,
            'hasSecrets' => (bool) $secretVars,
            // whom a Continue starts the batch as when it is not the admin of this page (an approver without the run permission)
            'continueAs' => $continueAs,
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
