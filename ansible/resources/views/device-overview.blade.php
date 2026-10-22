{{-- Device overview panel (Hooks\DeviceOverview, admins only): the device's latest runs and a link to run a template on it. --}}
<link href="{{ route('ansible.asset', 'ansible.css') }}?v={{ \Agoodley\LibrenmsAnsible\Http\Controllers\Controller::assetVersion() }}" rel="stylesheet">
<div class="panel panel-default panel-condensed ans-device-panel" id="ans-device-panel">
    <div class="panel-heading">
        <i class="fa fa-terminal fa-lg icon-theme" aria-hidden="true"></i>
        <strong>Ansible</strong>
        <span class="pull-right">
            <a class="btn btn-xs btn-default" href="{{ route('ansible.runs', ['device' => $device->device_id]) }}">All runs</a>
            @if($canRun && $refusal)
                <span class="ans-muted" id="ans-device-cannot" style="font-size: 12px">cannot be a target: {{ $refusal['text'] }}</span>
            @elseif($canRun)
                <a class="btn btn-xs btn-success" href="{{ route('ansible.runs.new', ['device' => $device->device_id]) }}" id="ans-device-run"><i class="fa fa-play"></i> Run a template on this device</a>
            @endif
        </span>
    </div>
    @if($error)
        <div class="panel-body text-danger">{{ $error }}</div>
    @elseif(! $runs)
        <div class="panel-body text-muted">No Ansible run had this device as a target yet.</div>
    @else
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
