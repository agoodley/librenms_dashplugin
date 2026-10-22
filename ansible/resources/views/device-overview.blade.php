{{-- Device overview panel (Hooks\DeviceOverview, admins only): the device's latest changes and runs, and a link to a new
     change on it. --}}
<link href="{{ route('ansible.asset', 'ansible.css') }}?v={{ \Agoodley\LibrenmsAnsible\Http\Controllers\Controller::assetVersion() }}" rel="stylesheet">
<div class="panel panel-default panel-condensed ans-device-panel" id="ans-device-panel">
    <div class="panel-heading">
        <i class="fa fa-terminal fa-lg icon-theme" aria-hidden="true"></i>
        <strong>Ansible</strong>
        <span class="pull-right">
            <a class="btn btn-xs btn-default" href="{{ route('ansible.changes', ['device' => $device->device_id]) }}">All changes</a>
            <a class="btn btn-xs btn-default" href="{{ route('ansible.runs', ['device' => $device->device_id]) }}">All runs</a>
            @if($canRun && $refusal)
                <span class="ans-muted" id="ans-device-cannot" style="font-size: 12px">cannot be a target: {{ $refusal['text'] }}</span>
            @elseif($canRun)
                <a class="btn btn-xs btn-success" href="{{ route('ansible.changes.new', ['device' => $device->device_id]) }}" id="ans-device-change"><i class="fa fa-plus"></i> New change on this device</a>
            @endif
        </span>
    </div>
    @if($error)
        <div class="panel-body text-danger">{{ $error }}</div>
    @endif
    @if(! $error && $changes)
        {{-- three columns, so that it fits a phone like the runs below: the status under the change, who and when under the template --}}
        <div class="table-responsive ans-device-runs" id="ans-device-changes">
        <table class="table table-condensed table-hover" style="margin-bottom: 0">
            <thead><tr><th>Change</th><th>Template</th><th>On this device</th></tr></thead>
            <tbody>
            @foreach($changes as $change)
                <tr data-change="{{ $change['id'] }}">
                    <td><a href="{{ route('ansible.changes.show', $change['id']) }}">#{{ $change['id'] }}</a>
                        <div class="ans-device-result"><span class="label label-{{ \Agoodley\LibrenmsAnsible\Changes\ChangeStatus::css($change['status']) }}">{{ \Agoodley\LibrenmsAnsible\Changes\ChangeStatus::label($change['status']) }}</span></div></td>
                    <td class="ans-wrap">{{ $change['template_name'] }}@if($change['reference'] !== '') <span class="ans-code ans-muted">{{ $change['reference'] }}</span>@endif
                        <div class="ans-muted">{{ $change['username'] }}, in batch {{ $change['batch'] }} · @foreach(explode(' ', (string) \Agoodley\LibrenmsAnsible\Support\Clock::local($change['created_at'])) as $part)<span class="ans-nowrap">{{ $part }}</span> @endforeach</div></td>
                    <td class="ans-device-result">
                        @if(($change['dry_run_state'] ?? null) === 'unverified')
                            {{-- not proven to be a dry run: whatever its result says, it may have changed the device --}}
                            <span class="label label-danger ans-not-proven" title="Its log does not prove it was a dry run: a task may have run outside check mode">dry run NOT PROVEN: may have changed this device</span>
                        @elseif($change['dry_result'])
                            <span class="label label-{{ in_array($change['dry_result'], ['failed', 'unreachable'], true) ? 'danger' : ($change['dry_result'] === 'changed' ? 'warning' : 'default') }}">dry run: {{ \Agoodley\LibrenmsAnsible\Changes\ChangeText::DRY_RESULTS[$change['dry_result']] ?? $change['dry_result'] }}</span>
                        @endif
                        @if(($change['batch_status'] ?? null) === 'cancelled' && in_array($change['result'], [null, 'not_run'], true)
                            && \Agoodley\LibrenmsAnsible\Changes\ChangeStatus::isFinal($change['status']))
                            {{-- its batch never ran (the change was stopped or ended before it): said so, not as a batch that ran without it --}}
                            <span class="label label-{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::css('not_run') }} ans-not-run">applied: not run ({{ $change['status'] === \Agoodley\LibrenmsAnsible\Changes\ChangeStatus::STOPPED ? 'stopped before its batch' : 'its batch was not launched' }})</span>
                        @elseif($change['result'])
                            <span class="label label-{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::css($change['result']) }}">applied: {{ strtolower(\Agoodley\LibrenmsAnsible\Runs\RunResults::LABELS[$change['result']] ?? $change['result']) }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @endif
    @if($error)
    @elseif(! $runs && ! $changes)
        <div class="panel-body text-muted">No Ansible change or run had this device as a target yet.</div>
    @elseif($runs)
        {{-- fits a phone: the date and the time, and the labels of the result, wrap; at worst the table scrolls in the panel --}}
        <div class="table-responsive ans-device-runs">
        <table class="table table-condensed table-hover" style="margin-bottom: 0">
            <thead><tr><th>Run</th><th>Template</th><th>When</th><th>Who</th><th>Result</th></tr></thead>
            <tbody>
            @foreach($runs as $run)
                <tr>
                    <td><a href="{{ route('ansible.runs.show', $run['id']) }}">#{{ $run['id'] }}</a></td>
                    <td class="ans-wrap">{{ $run['template_name'] }}</td>
                    <td>@foreach(explode(' ', (string) \Agoodley\LibrenmsAnsible\Support\Clock::local($run['created_at'])) as $part)<span class="ans-nowrap">{{ $part }}</span> @endforeach</td>
                    <td class="ans-wrap">{{ $run['username'] }}</td>
                    <td class="ans-device-result">
                        @if($run['result'] && \Agoodley\LibrenmsAnsible\Runs\RunResults::unproven($run) && empty($run['unexpected']))
                            <span class="label label-danger ans-not-proven">{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::NOT_PROVEN }}</span>
                        @elseif($run['result'])
                            <span class="label label-{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::css($run['result']) }}">{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::label($run['result'], ($run['kind'] ?? null) === 'dry_run') }}</span>
                        @endif
                        @if(! $run['result'] || ! in_array($run['status'], ['success', 'failed'], true))
                            {{-- how the run ended, when that says more than the device's result (incomplete, stopped, lost, released) --}}
                            <span class="label label-{{ \Agoodley\LibrenmsAnsible\Runs\RunStatus::css($run['status']) }}">{{ \Agoodley\LibrenmsAnsible\Runs\RunStatus::label($run['status']) }}</span>
                        @endif
                        @if(! empty($run['unexpected']))
                            <span class="label label-danger" title="The runner ran on this device although it was not a target">not a target</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>
