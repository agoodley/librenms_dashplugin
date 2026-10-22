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
        <div class="table-responsive ans-device-runs" id="ans-device-changes">
        <table class="table table-condensed table-hover" style="margin-bottom: 0">
            <thead><tr><th>Change</th><th>Template</th><th>When</th><th>Status</th><th>On this device</th></tr></thead>
            <tbody>
            @foreach($changes as $change)
                <tr>
                    <td><a href="{{ route('ansible.changes.show', $change['id']) }}">#{{ $change['id'] }}</a>@if($change['reference'] !== '')<div class="ans-code ans-muted ans-wrap">{{ $change['reference'] }}</div>@endif</td>
                    <td class="ans-wrap">{{ $change['template_name'] }}<div class="ans-muted">{{ $change['username'] }}, batch {{ $change['batch'] }}</div></td>
                    <td>@foreach(explode(' ', (string) \Agoodley\LibrenmsAnsible\Support\Clock::local($change['created_at'])) as $part)<span class="ans-nowrap">{{ $part }}</span> @endforeach</td>
                    <td><span class="label label-{{ \Agoodley\LibrenmsAnsible\Changes\ChangeStatus::css($change['status']) }}">{{ \Agoodley\LibrenmsAnsible\Changes\ChangeStatus::label($change['status']) }}</span></td>
                    <td class="ans-device-result">
                        @if($change['dry_result'])
                            <span class="label label-{{ in_array($change['dry_result'], ['failed', 'unreachable'], true) ? 'danger' : ($change['dry_result'] === 'changed' ? 'warning' : 'default') }}">dry run: {{ \Agoodley\LibrenmsAnsible\Changes\ChangeText::DRY_RESULTS[$change['dry_result']] ?? $change['dry_result'] }}</span>
                        @endif
                        @if($change['result'])
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
                        @if($run['result'])
                            <span class="label label-{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::css($run['result']) }}">{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::LABELS[$run['result']] ?? $run['result'] }}</span>
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
