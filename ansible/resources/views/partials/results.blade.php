{{-- Targets per result, worst first ($resultSummary: result => count). A dry run ($resultDryRun) says what would change;
     one its log does not prove to be a dry run ($resultUnproven) shows that it may have changed its devices instead.
     Each include passes all three: an @include sees the variables of the page that includes it, so these names are the
     partial's own (the change page has a $dryRun of its own). --}}
<span class="ans-results">
@if($resultUnproven)
    <span class="label label-danger ans-not-proven" title="The log does not prove this was a dry run: a task may have run outside check mode">NOT PROVEN: may have changed its devices</span>
@else
@foreach(\Agoodley\LibrenmsAnsible\Runs\RunResults::ORDER as $result)
    @if(! empty($resultSummary[$result]))
        <span class="label label-{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::css($result) }}" title="{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::label($result, $resultDryRun) }}">{{ $resultSummary[$result] }} {{ strtolower(\Agoodley\LibrenmsAnsible\Runs\RunResults::label($result, $resultDryRun)) }}</span>
    @endif
@endforeach
@endif
</span>
