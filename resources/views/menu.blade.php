{{-- Entries in the LibreNMS "Plugins" menu (Support\Navigation): the overview page, then each group under its header.
     LibreNMS wraps each plugin menu entry in one <li>, so the items after the first close it and open their own. --}}
<a href="{{ $overview }}"><i class="fa fa-th-large fa-fw fa-lg" aria-hidden="true"></i> Topology overview</a>
@foreach(\Agoodley\LibrenmsTopology\Support\Navigation::groups() as $group)
    </li><li role="presentation" class="dropdown-header">Topology {{ strtolower($group['label']) }}</li><li>
    @foreach($group['pages'] as $page)
        @if(! $loop->first)</li><li>@endif
        <a href="{{ route($page['route']) }}"><i class="fa {{ $page['icon'] }} fa-fw fa-lg" aria-hidden="true"></i> {{ $page['title'] }}</a>
    @endforeach
@endforeach
