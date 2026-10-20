{{-- "new" mark of a finding that the background checks first reported in their latest run (History\FindingBadges). --}}
@if(! empty($finding['history']['new']))
    <span class="label label-primary topo-new" title="New: first reported by the background checks at {{ $finding['history']['first_seen'] }}">new</span>
@endif
