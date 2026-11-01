{{-- Pages of a list: the query of the page with another page number. --}}
@if($pages > 1)
    <nav class="cmp-pager" aria-label="pages">
        @if($page > 1)
            <a class="btn btn-xs btn-default" href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}" rel="prev">&laquo; previous</a>
        @endif
        <span class="cmp-muted">page {{ $page }} of {{ $pages }}</span>
        @if($page < $pages)
            <a class="btn btn-xs btn-default" href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}" rel="next">next &raquo;</a>
        @endif
    </nav>
@endif
