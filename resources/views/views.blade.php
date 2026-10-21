{{-- Saved views of the maps (ViewController::index): the user's own and the shared ones, with their actions, and the
     layouts saved in this browser, which can be saved as views. --}}
@extends('layouts.librenmsv1')

@section('title', 'Saved Views')

@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@section('content')
<div class="container-fluid topo-page">
    @include('topology::partials.nav', ['active' => 'views', 'roleNote' => 'the maps of these views leave them out'])

    @if($done)
        <div class="alert alert-{{ $done['ok'] ? 'success' : 'danger' }} topo-views-done">
            <i class="fa fa-{{ $done['ok'] ? 'check' : 'exclamation-triangle' }}"></i> {{ $done['message'] }}
        </div>
    @endif
    @if(! $available)
        <div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> The plugin's table <code>topology_views</code> is missing:
            run <code>./lnms migrate</code> as the librenms user (see the plugin's README, Installation).</div>
    @endif

    <p class="text-muted">To save a view, open a map, arrange it and use <i class="fa fa-bookmark"></i> &rarr; <i>Save this map as a view</i>.
        A view keeps the scope, filters, device positions and display options of the map. A shared view is listed for everyone who can
        open the maps; each of them sees it with their own permissions, only the devices they may see.</p>

    <div class="table-responsive">
        <table class="table table-condensed table-hover topo-views-table">
            <thead>
                <tr><th>Name</th><th>Map</th><th>Scope</th><th>Owner</th><th>Shared</th><th>Updated</th><th style="position: relative"><span class="sr-only">Actions</span></th></tr>
            </thead>
            <tbody>
                @forelse($views as $view)
                    <tr data-view-id="{{ $view['id'] }}">
                        <td><a href="{{ $view['url'] }}"><i class="fa fa-bookmark fa-fw"></i> {{ $view['name'] }}</a></td>
                        <td>{{ $pages[$view['page']] ?? $view['page'] }}
                            @if(! empty($roleHidden[$view['page']]))
                                <i class="fa fa-lock text-muted" title="Your LibreNMS role may not view {{ implode(', ', $roleHidden[$view['page']]) }}: this map leaves them out"
                                   aria-label="Your LibreNMS role may not view {{ implode(', ', $roleHidden[$view['page']]) }}"></i>
                            @endif
                        </td>
                        <td>{{ $view['scope'] }}</td>
                        <td>{{ $view['owner'] }}</td>
                        <td>@if($view['shared'])<i class="fa fa-users"></i> shared @else personal @endif</td>
                        <td class="text-nowrap">{{ $view['updated_at'] ? \Illuminate\Support\Carbon::parse($view['updated_at'])->diffForHumans() : '' }}</td>
                        <td class="topo-view-actions">
                            @if($view['can_edit'])
                                <form method="post" action="{{ route('topology.views.act') }}" class="topo-inline-form">
                                    @csrf
                                    <input type="hidden" name="id" value="{{ $view['id'] }}">
                                    <input type="hidden" name="action" value="rename">
                                    <input name="name" class="form-control input-sm" value="{{ $view['name'] }}" maxlength="100" required aria-label="New name">
                                    <button type="submit" class="btn btn-xs btn-default">Rename</button>
                                </form>
                                <form method="post" action="{{ route('topology.views.act') }}" class="topo-inline-form">
                                    @csrf
                                    <input type="hidden" name="id" value="{{ $view['id'] }}">
                                    <input type="hidden" name="action" value="{{ $view['shared'] ? 'unshare' : 'share' }}">
                                    <button type="submit" class="btn btn-xs btn-default">{{ $view['shared'] ? 'Stop sharing' : 'Share' }}</button>
                                </form>
                            @endif
                            @if($view['can_delete'])
                                <form method="post" action="{{ route('topology.views.act') }}" class="topo-inline-form" data-confirm="Delete the view &quot;{{ $view['name'] }}&quot;?"
                                      onsubmit="return window.confirm(this.getAttribute('data-confirm'))">
                                    @csrf
                                    <input type="hidden" name="id" value="{{ $view['id'] }}">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="btn btn-xs btn-danger">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">No saved views yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h4 class="topo-landing-group"><i class="fa fa-save fa-fw"></i> Layouts saved in this browser</h4>
    <p class="text-muted">Device positions saved with <i class="fa fa-save"></i> on a map stay in this browser. Save one as a view to keep it
        on the server, open it anywhere and share it.</p>
    <div id="topo-browser-layouts"></div>
</div>
@endsection

@push('scripts')
<script>
    // Layouts of the maps saved in this browser's local storage (topology.layout.<map>.<scope>), each with "Save as a view".
    (function () {
        var box = document.getElementById('topo-browser-layouts');
        var url = @json(route('topology.api.views.store'));
        var names = {l2: 'Layer 2', l3: 'Layer 3'};
        var esc = function (value) {
            return String(value).replace(/[&<>"']/g, function (c) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
            });
        };
        var layouts = [];
        try {
            for (var i = 0; i < window.localStorage.length; i++) {
                var key = window.localStorage.key(i);
                var match = /^topology\.layout\.(l2|l3)\.(.*)$/.exec(key || '');
                if (match) {
                    var positions = JSON.parse(window.localStorage.getItem(key) || 'null');
                    if (positions && typeof positions === 'object') {
                        layouts.push({key: key, page: match[1], scope: match[2], positions: positions});
                    }
                }
            }
        } catch (e) {
            // storage unavailable
        }
        if (!layouts.length) {
            box.innerHTML = '<p class="text-muted">None.</p>';
            return;
        }
        var describe = function (scope) {
            var params = new URLSearchParams(scope);
            var parts = [];
            if (params.get('device')) parts.push('device #' + params.get('device') + ', ' + (params.get('depth') || '?') + ' hops');
            if (params.get('group')) parts.push('group #' + params.get('group'));
            if (params.get('location')) parts.push('location #' + params.get('location'));
            if (params.get('vrf')) parts.push('VRF ' + params.get('vrf'));
            return parts.length ? parts.join(' · ') : 'whole network';
        };
        var html = '<div class="table-responsive"><table class="table table-condensed topo-views-table"><thead><tr><th>Map</th><th>Scope</th><th>Devices</th>' +
            '<th style="position: relative"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
        layouts.forEach(function (layout, index) {
            html += '<tr><td>' + esc(names[layout.page]) + '</td><td>' + esc(describe(layout.scope)) + '</td><td>' + Object.keys(layout.positions).length +
                '</td><td><button type="button" class="btn btn-xs btn-primary" data-save="' + index + '"><i class="fa fa-bookmark"></i> Save as a view</button> ' +
                '<button type="button" class="btn btn-xs btn-default" data-forget="' + index + '">Remove from this browser</button></td></tr>';
        });
        box.innerHTML = html + '</tbody></table></div>';

        box.querySelectorAll('[data-save]').forEach(function (button) {
            button.addEventListener('click', function () {
                var layout = layouts[Number(button.getAttribute('data-save'))];
                var name = window.prompt('Name of the view', names[layout.page] + ' - ' + describe(layout.scope));
                if (!name) {
                    return;
                }
                var token = document.querySelector('meta[name="csrf-token"]');
                button.disabled = true;
                fetch(url, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token ? token.getAttribute('content') : ''},
                    body: JSON.stringify({page: layout.page, query: layout.scope, positions: layout.positions, name: name, shared: false})
                }).then(function (response) {
                    return response.json().then(function (body) {
                        if (!response.ok) {
                            throw new Error(body.error || 'HTTP ' + response.status);
                        }
                        window.location.reload();
                    });
                }).catch(function (error) {
                    button.disabled = false;
                    window.alert('Unable to save the view: ' + error.message);
                });
            });
        });
        box.querySelectorAll('[data-forget]').forEach(function (button) {
            button.addEventListener('click', function () {
                try {
                    window.localStorage.removeItem(layouts[Number(button.getAttribute('data-forget'))].key);
                } catch (e) {
                    // storage unavailable
                }
                button.closest('tr').remove();
            });
        });
    })();
</script>
@endpush
