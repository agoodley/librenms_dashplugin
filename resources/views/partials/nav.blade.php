@php
    $navQuery = array_filter([
        'device' => $params['device'] ?? null,
        'depth' => ! empty($params['device']) ? ($params['depth'] ?? null) : null,
        'group' => $params['group'] ?? null,
        'location' => $params['location'] ?? null,
        'vlan' => $params['vlan'] ?? null,
    ]);
    $navSuffix = $navQuery ? '?' . http_build_query($navQuery) : '';
@endphp
<div class="topo-nav">
    <ul class="nav nav-tabs">
        <li class="{{ $active === 'l2' ? 'active' : '' }}">
            <a href="{{ route('topology.l2') . $navSuffix }}" data-topo-nav data-base="{{ route('topology.l2') }}"><i class="fa fa-sitemap fa-fw"></i> Layer 2 Map</a>
        </li>
        <li class="{{ $active === 'l3' ? 'active' : '' }}">
            <a href="{{ route('topology.l3') . $navSuffix }}" data-topo-nav data-base="{{ route('topology.l3') }}"><i class="fa fa-project-diagram fa-fw"></i> Layer 3 Map</a>
        </li>
        <li class="{{ $active === 'stp' ? 'active' : '' }}">
            <a href="{{ route('topology.stp') . $navSuffix }}" data-topo-nav data-base="{{ route('topology.stp') }}"><i class="fa fa-stethoscope fa-fw"></i> Spanning Tree Diagnostics</a>
        </li>
    </ul>
</div>
