{{-- Targets per result, worst first ($summary: result => count). A dry run ($dryRun) says what would change; one its
     log does not prove to be a dry run ($unproven) shows that it may have changed its devices instead. --}}
<span class="ans-results">
@if(! empty($unproven))
    <span class="label label-danger ans-not-proven" title="The log does not prove this was a dry run: a task may have run outside check mode">NOT PROVEN: may have changed its devices</span>
@else
@foreach(\Agoodley\LibrenmsAnsible\Runs\RunResults::ORDER as $result)
    @if(! empty($summary[$result]))
        <span class="label label-{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::css($result) }}" title="{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::label($result, ! empty($dryRun)) }}">{{ $summary[$result] }} {{ strtolower(\Agoodley\LibrenmsAnsible\Runs\RunResults::label($result, ! empty($dryRun))) }}</span>
    @endif
@endforeach
@endif
</span>
