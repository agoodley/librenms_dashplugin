{{-- Share of a location's or switch's access ports in use, as a bar (capacity page): $use (percent or null), $class (success, warning, danger). --}}
@if($use === null)
    <span class="text-muted">-</span>
@else
    <div class="topo-capacity-use" title="{{ \Agoodley\LibrenmsTopology\Capacity\CapacityAnalyzer::percentText($use) }}% in use">
        <div class="progress"><div class="progress-bar progress-bar-{{ $class }}" style="width: {{ max(0, min(100, $use)) }}%"></div></div>
        <span>{{ \Agoodley\LibrenmsTopology\Capacity\CapacityAnalyzer::percentText($use) }}%</span>
    </div>
@endif
