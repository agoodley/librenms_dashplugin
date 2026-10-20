{{-- Landing page /plugin/topology (Hooks\Page), rendered inside LibreNMS' plugin page layout: every page of
     Support\Navigation, by group. --}}
@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}" rel="stylesheet">
@endpush
<div class="container-fluid">
    <h3>Network Topology</h3>
    @foreach(\Agoodley\LibrenmsTopology\Support\Navigation::groups() as $group)
        <h4 class="topo-landing-group"><i class="fa {{ $group['icon'] }} fa-fw"></i> {{ $group['label'] }}</h4>
        <div class="topo-cards" style="display: flex; flex-wrap: wrap; gap: 12px;">
            @foreach($group['pages'] as $page)
                <a class="topo-card panel panel-default" style="flex: 1 1 260px; padding: 14px;" href="{{ route($page['route']) }}">
                    <h4><i class="fa {{ $page['icon'] }} fa-fw"></i> {{ $page['title'] }}</h4>
                    <p>{{ $page['description'] }}</p>
                </a>
            @endforeach
        </div>
    @endforeach
</div>
