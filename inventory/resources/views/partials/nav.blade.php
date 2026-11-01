{{-- Tabs of every page of the plugin, and the serial number search. --}}
<div class="inv-nav">
    <ul class="nav nav-tabs">
        @foreach(['overview' => ['Overview', 'fa-cubes'], 'devices' => ['Devices', 'fa-server'], 'modules' => ['Modules & serial numbers', 'fa-barcode'],
            'optics' => ['Optics', 'fa-dot-circle-o']] as $key => [$label, $icon])
            <li class="{{ $active === $key ? 'active' : '' }}"><a href="{{ route('inventory.' . $key, array_intersect_key($query ?? [], array_flip(['group', 'location', 'os']))) }}"><i class="fa {{ $icon }} fa-fw"></i> {{ $label }}</a></li>
        @endforeach
    </ul>
    <form method="get" action="{{ route('inventory.search') }}" class="inv-search" role="search">
        <label for="inv-search-q" class="sr-only">Serial or part number</label>
        <input class="form-control" id="inv-search-q" name="q" value="{{ $active === 'search' ? ($filters['q'] ?? '') : '' }}" placeholder="serial or part number" maxlength="200">
        <button type="submit" class="btn btn-sm btn-default" title="Search"><i class="fa fa-search"></i></button>
    </form>
</div>
@if($hidden ?? [])
    <div class="alert alert-info" id="inv-role-hidden">Your LibreNMS role may not view {{ implode(' or ', $hidden) }}: those are left out.</div>
@endif
