{{-- Targets per result, worst first ($summary: result => count). --}}
<span class="ans-results">
@foreach(\Agoodley\LibrenmsAnsible\Runs\RunResults::ORDER as $result)
    @if(! empty($summary[$result]))
        <span class="label label-{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::css($result) }}" title="{{ \Agoodley\LibrenmsAnsible\Runs\RunResults::LABELS[$result] }}">{{ $summary[$result] }} {{ strtolower(\Agoodley\LibrenmsAnsible\Runs\RunResults::LABELS[$result]) }}</span>
    @endif
@endforeach
</span>
