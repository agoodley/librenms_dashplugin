{{-- Navigation of every page (Support\Navigation): the groups, then the pages of the current group. Links keep the
     current scope; topology.js updates the [data-topo-nav] links when the map filters change. Below it, what the user's
     role may not view of the page's data (Support\RoleData), with $roleNote saying where it is left out. --}}
@php
    $navQuery = array_filter([
        'device' => $params['device'] ?? null,
        'depth' => ! empty($params['device']) ? ($params['depth'] ?? null) : null,
        'group' => $params['group'] ?? null,
        'location' => $params['location'] ?? null,
        'vlan' => $params['vlan'] ?? null,
    ]);
    $navSuffix = $navQuery ? '?' . http_build_query($navQuery) : '';
    $navGroups = \Agoodley\LibrenmsTopology\Support\Navigation::groups();
    $navActiveGroup = \Agoodley\LibrenmsTopology\Support\Navigation::groupOf($active);
    $navLink = function (array $page) use ($navSuffix): string {
        $base = route($page['route']);
        if (! \Agoodley\LibrenmsTopology\Support\Navigation::scoped($page)) {
            return 'href="' . e($base) . '"';
        }

        return 'href="' . e($base . $navSuffix) . '" data-topo-nav data-base="' . e($base) . '"';
    };
    // what the user's LibreNMS role may not read among this page's data
    $navRoleHidden = (new \Agoodley\LibrenmsTopology\Data\TopologyRepository(auth()->user()))
        ->hiddenKinds(\Agoodley\LibrenmsTopology\Support\RoleData::PAGES[$active] ?? []);
@endphp
<div class="topo-nav">
    <ul class="nav nav-tabs topo-nav-groups">
        @foreach($navGroups as $groupKey => $group)
            <li class="{{ $groupKey === $navActiveGroup ? 'active' : '' }}">
                <a {!! $navLink(reset($group['pages'])) !!}><i class="fa {{ $group['icon'] }} fa-fw"></i> {{ $group['label'] }}</a>
            </li>
        @endforeach
    </ul>
    @if($navActiveGroup && count($navGroups[$navActiveGroup]['pages']) > 1)
        <ul class="nav nav-pills topo-nav-pages">
            @foreach($navGroups[$navActiveGroup]['pages'] as $pageKey => $page)
                <li class="{{ $pageKey === $active ? 'active' : '' }}">
                    <a {!! $navLink($page) !!}><i class="fa {{ $page['icon'] }} fa-fw"></i> {{ $page['tab'] }}</a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@if($navRoleHidden)
    <div class="alert alert-info topo-role-hidden" style="margin: 8px 0">
        <i class="fa fa-lock"></i> Your LibreNMS role may not view {{ implode(', ', $navRoleHidden) }}: {{ $roleNote ?? 'this page leaves them out' }}.
    </div>
@endif
