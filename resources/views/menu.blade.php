{{-- Entries in the LibreNMS "Plugins" menu (Support\Navigation). LibreNMS wraps each plugin menu entry in one <li>, so the
     items after the first close it and open their own. --}}
@foreach(\Agoodley\LibrenmsTopology\Support\Navigation::groups() as $group)
    @if(! $loop->first)</li><li role="presentation" class="dropdown-header">Topology {{ strtolower($group['label']) }}</li><li>@endif
    @foreach($group['pages'] as $page)
        @if(! $loop->first)</li><li>@endif
        <a href="{{ route($page['route']) }}"><i class="fa {{ $page['icon'] }} fa-fw fa-lg" aria-hidden="true"></i> {{ $page['title'] }}</a>
    @endforeach
@endforeach
