{{-- The filter bar: device group, location and OS of every page, then the page's own $fields (name => [label, choices
     value => label, or null for a text field]), the exports ($export: kind => label). --}}
<form method="get" action="{{ $action }}" class="inv-filterbar" id="inv-filters">
    @if($filters['device'])
        <input type="hidden" name="device" value="{{ $filters['device'] }}">
    @endif
    <div><label for="inv-filter-group">Device group</label>
        <select class="form-control" id="inv-filter-group" name="group">
            <option value="">every group</option>
            @foreach($options['groups'] as $group)
                <option value="{{ $group['id'] }}" @selected($filters['group'] === $group['id'])>{{ $group['name'] }}</option>
            @endforeach
        </select></div>
    <div><label for="inv-filter-location">Location</label>
        <select class="form-control" id="inv-filter-location" name="location" style="max-width: 240px">
            <option value="">every location</option>
            @foreach($options['locations'] as $location)
                <option value="{{ $location['id'] }}" @selected($filters['location'] === $location['id'])>{{ $location['name'] }}</option>
            @endforeach
        </select></div>
    <div><label for="inv-filter-os">OS</label>
        <select class="form-control" id="inv-filter-os" name="os">
            <option value="">every OS</option>
            @foreach($options['os'] as $os => $name)
                <option value="{{ $os }}" @selected($filters['os'] === (string) $os)>{{ $name }}</option>
            @endforeach
        </select></div>
    @foreach($fields ?? [] as $name => [$label, $choices])
        <div><label for="inv-filter-{{ $name }}">{{ $label }}</label>
            @if($choices === null)
                <input class="form-control" id="inv-filter-{{ $name }}" name="{{ $name }}" value="{{ $filters[$name] }}" maxlength="200" placeholder="text">
            @else
                <select class="form-control" id="inv-filter-{{ $name }}" name="{{ $name }}" style="max-width: 240px">
                    <option value="">every one</option>
                    @foreach($choices as $value => $text)
                        <option value="{{ $value }}" @selected($filters[$name] === (string) $value)>{{ $text }}</option>
                    @endforeach
                    @if($filters[$name] !== '' && ! array_key_exists($filters[$name], $choices))
                        <option value="{{ $filters[$name] }}" selected>{{ $filters[$name] }}</option>
                    @endif
                </select>
            @endif
        </div>
    @endforeach
    <div><button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-filter"></i> Filter</button>
        @if($query)<a class="btn btn-sm btn-default" href="{{ $action }}">Clear</a>@endif</div>
    <span class="inv-spacer"></span>
    @if($export ?? null)
        <div class="inv-export">
            @foreach((array) $export as $kind => $label)
                <a class="btn btn-sm btn-default" id="inv-export-{{ $kind }}" href="{{ route('inventory.export', ['kind' => $kind] + $query) }}"><i class="fa fa-download"></i> {{ $label }}</a>
            @endforeach
        </div>
    @endif
</form>
@if($filters['device'] && ($deviceName ?? null))
    <p class="inv-help">Only <strong>{{ $deviceName }}</strong>: <a href="{{ request()->fullUrlWithoutQuery(['device', 'page']) }}">every device</a></p>
@endif
