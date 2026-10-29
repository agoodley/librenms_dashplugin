{{-- Device overview panel (Hooks\DeviceOverview): the findings of the device's last check, when it was checked and of
     which backup, a link to its compliance page. Nothing for a device that has no backup and was never checked. --}}
@php
    use Agoodley\LibrenmsCompliance\Data\ResultRepository;
    use Agoodley\LibrenmsCompliance\Support\Clock;
@endphp
@if($error || $row)
<link href="{{ route('compliance.asset', 'compliance.css') }}?v={{ \Agoodley\LibrenmsCompliance\Http\Controllers\Controller::assetVersion() }}" rel="stylesheet">
<div class="panel panel-default panel-condensed cmp-device-panel" id="cmp-device-panel">
    <div class="panel-heading">
        <i class="fa fa-check-square-o fa-lg icon-theme" aria-hidden="true"></i>
        <strong>Compliance</strong>
        @if($row)
            @if($findings)
                <span class="label label-danger">{{ count($findings) + $more }} {{ count($findings) + $more === 1 ? 'finding' : 'findings' }}</span>
            @elseif($row['status'] === ResultRepository::CHECKED)
                <span class="label label-success">{{ \Agoodley\LibrenmsCompliance\Services\Overview::applied($row) ? 'compliant' : 'no rule applies' }}</span>
            @endif
            <span class="text-muted" style="font-size: 12px">checked {{ Clock::ago($row['checked_at']) }}@if($row['backup_at']), backup of {{ Clock::local($row['backup_at']) }}@endif</span>
            <span class="pull-right"><a class="btn btn-xs btn-default" href="{{ route('compliance.device', $device->device_id) }}">Details</a></span>
        @endif
    </div>
    @if($error)
        <div class="panel-body text-danger">{{ $error }}</div>
    @elseif($row['status'] !== ResultRepository::CHECKED)
        <div class="panel-body"><div class="alert alert-warning" style="margin: 0; padding: 6px 10px"><i class="fa fa-exclamation-triangle"></i>
            Its last check read no backup: {{ $row['error'] }}@if($findings) The findings are of the check before.@endif</div></div>
    @endif
    @if($findings)
        <div class="panel-body">
            <ul id="cmp-device-findings">
                @foreach($findings as $rule => $finding)
                    <li>@include('compliance::partials.severity', ['severity' => $finding['severity']])
                        <a href="{{ route('compliance.device', $device->device_id) }}#finding-{{ $rule }}">{{ isset($rules[$rule]) ? $rules[$rule]->title : $rule }}</a>:
                        {{ \Agoodley\LibrenmsCompliance\Support\ErrorText::line($finding['message'], 160) }}</li>
                @endforeach
            </ul>
            @if($more)<div class="text-muted" style="font-size: 12px; margin-top: 4px">and {{ $more }} more</div>@endif
        </div>
    @endif
</div>
@endif
