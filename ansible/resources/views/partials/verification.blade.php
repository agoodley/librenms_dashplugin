{{-- The verification of a change's batches (phase 4), on the change page: per batch the state before it, the wait for
     fresh data after it, what the comparison found (unexpected, what the fix was made for, the rest), and an acceptance.
     The texts come from the topology plugin (names of devices, ports, findings): escaped. --}}
@php
    use Agoodley\LibrenmsAnsible\Support\Clock;
    $at = fn ($time) => is_int($time) && $time > 0 ? Clock::show($time, 'Y-m-d H:i:s T') : '';
    $beforeLabels = ['refresh' => 'to be taken from data LibreNMS discovers again', 'take' => 'to be taken', 'waiting' => 'waits for fresh data', 'taken' => 'taken',
        'unknown' => 'could not be taken'];
    $afterLabels = ['settling' => 'settle time', 'waiting' => 'waits for fresh data', 'stale' => 'no fresh data', 'done' => 'compared'];
@endphp
<div class="panel panel-default" id="ans-verification">
    <div class="panel-heading"><strong>Verification</strong> <span class="text-muted">(the topology plugin's state of the network around each batch, before it and after it on fresh
        data, compared: links, port-channels, blocked ports, root bridges, OSPF and BGP, and its findings)</span></div>
    <table class="table table-condensed ans-table ans-stack" id="ans-verification-table">
        <thead><tr><th style="width: 60px">Batch</th><th>Before</th><th>After</th><th>Result</th></tr></thead>
        <tbody>
        @foreach($verification as $number => $verify)
            @php
                $before = (array) ($verify['before'] ?? []);
                $after = (array) ($verify['after'] ?? []);
                $result = $verify['result'] ?? null;
                $evaluation = (array) ($verify['evaluation'] ?? []);
            @endphp
            <tr data-batch="{{ $number }}" data-result="{{ $result ?? '' }}">
                <td data-label="Batch">{{ $number }}</td>
                <td class="ans-wrap ans-verify-before" data-label="Before" data-state="{{ $before['status'] ?? '' }}">
                    {{ $beforeLabels[$before['status'] ?? ''] ?? '-' }}@if(($before['status'] ?? null) === 'taken') {{ $at($before['taken_at'] ?? null) }}@endif
                    @if(($before['status'] ?? null) === 'taken' && ($before['size'] ?? null))<div class="ans-help">{{ $before['size'] }}; {{ $before['note'] ?? '' }}</div>@endif
                    @if(($before['status'] ?? null) === 'waiting')<div class="ans-help">until {{ $at($before['deadline'] ?? null) }}@if($before['waiting'] ?? null): {{ $before['waiting'] }}@endif</div>@endif
                    @if(($before['status'] ?? null) === 'unknown')<div class="ans-help text-warning">{{ $before['error'] ?? '' }}</div>@endif
                </td>
                <td class="ans-wrap ans-verify-after" data-label="After" data-state="{{ $after['status'] ?? '' }}">
                    {{ $afterLabels[$after['status'] ?? ''] ?? '-' }}@if(($after['status'] ?? null) === 'done') {{ $at($after['taken_at'] ?? null) }}@endif
                    @if(($after['status'] ?? null) === 'settling')<div class="ans-help">until {{ $at($after['due'] ?? null) }}</div>@endif
                    @if(in_array($after['status'] ?? null, ['waiting', 'stale'], true))
                        <div class="ans-help">data newer than {{ $at($after['due'] ?? null) }}{{ ($after['deadline'] ?? 0) < PHP_INT_MAX ? ', waits until ' . $at($after['deadline'] ?? null) : '' }}{{ ($after['waiting'] ?? null) ? ': ' . $after['waiting'] : '' }}</div>
                    @endif
                    @if($after['note'] ?? null)<div class="ans-help text-warning">{{ $after['note'] }}</div>@endif
                </td>
                <td class="ans-wrap ans-verify-result" data-label="Result">
                    @if($result !== null)<span class="label label-{{ $resultCss[$result] ?? 'default' }}">{{ $resultLabels[$result] ?? $result }}</span>@endif
                    @if($result === 'unknown')<div class="ans-help">{{ $verify['error'] ?? '' }}</div>@endif
                    @if(is_array($verify['accepted'] ?? null))<div class="ans-help text-warning ans-verify-accepted">accepted by {{ $verify['accepted']['by'] ?? '?' }}
                        {{ Clock::local($verify['accepted']['at'] ?? null) }}@if(($verify['accepted']['comment'] ?? '') !== ''): "{{ $verify['accepted']['comment'] }}"@endif</div>@endif
                    @if(($verify['said'] ?? null) === 'record' && $result !== 'verified')<div class="ans-help">recorded (the settings: only record)</div>@endif
                    @foreach([['unexpected', 'Unexpected', 'text-danger'], ['expected', 'What the fix was made for', 'text-success']] as [$list, $title, $css])
                        @if($evaluation[$list] ?? [])
                            <div class="ans-help {{ $css }}" style="margin-top: 4px"><strong>{{ $title }}:</strong></div>
                            <ul class="ans-verify-{{ $list }}" style="margin: 0; padding-left: 16px">@foreach(array_slice((array) $evaluation[$list], 0, 50) as $entry)<li data-type="{{ $entry['type'] }}">{{ $entry['text'] }}</li>@endforeach</ul>
                        @endif
                    @endforeach
                    @if($evaluation['listed'] ?? [])
                        <details class="ans-verify-listed-box"><summary class="ans-help">{{ \Agoodley\LibrenmsAnsible\Support\Format::count(count((array) $evaluation['listed']), 'other change') }}
                            (no reason to stop: links back, findings below the setting or resolved...)</summary>
                            <ul class="ans-verify-listed" style="margin: 0; padding-left: 16px">@foreach(array_slice((array) $evaluation['listed'], 0, 100) as $entry)<li data-type="{{ $entry['type'] }}">{{ $entry['text'] }}</li>@endforeach</ul></details>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
