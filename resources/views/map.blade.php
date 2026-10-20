{{-- L2 / L3 / site map page (PageController::l2/l3, SiteController::show): filters and view options; resources/assets/topology.js draws the map from the JSON API. --}}
@extends('layouts.librenmsv1')

@section('title', ['l2' => 'Layer 2 Map', 'l3' => 'Layer 3 Map', 'sites' => 'Site / WAN Map'][$mode] ?? 'Topology')

@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    // saved views and the custom map export are on the L2 and L3 maps (PageController::map), not on the site map
    $views ??= null;
    $savedView ??= null;
    $viewError ??= null;
    $canExportCustomMap ??= false;
@endphp

@section('content')
<div class="container-fluid topo-page" id="topo-app">
    @include('topology::partials.nav', ['active' => $mode])

    <form id="topo-filters" class="topo-toolbar" autocomplete="off">
        @if($mode === 'sites')
            {{-- the site map centers on a location (and the sites within the hops) instead of a device --}}
            <label><span class="topo-group-label">Center on site</span>
                <select id="topo-site" name="location" class="form-control" style="max-width: 220px">
                    <option value="">All sites</option>
                    @foreach($filters['locations'] as $location)
                        <option value="{{ $location['id'] }}" @selected($params['location'] == $location['id'])>{{ $location['name'] }}</option>
                    @endforeach
                </select>
            </label>
        @else
        <label><span class="topo-group-label">Center on</span>
            <select id="topo-device" name="device" class="form-control">
                <option value=""></option>
                @if($params['device'] && $deviceName)
                    <option value="{{ $params['device'] }}" selected>{{ $deviceName }}</option>
                @endif
            </select>
        </label>
        @endif
        <label><span class="topo-group-label">Hops</span>
            <select name="depth" class="form-control">
                @foreach(range(1, 10) as $depth)
                    <option value="{{ $depth }}" @selected($params['depth'] == $depth)>{{ $depth }}</option>
                @endforeach
            </select>
        </label>
        <label><span class="topo-group-label">Group</span>
            <select name="group" class="form-control">
                <option value="">All</option>
                @foreach($filters['groups'] as $group)
                    <option value="{{ $group['id'] }}" @selected($params['group'] == $group['id'])>{{ $group['name'] }}</option>
                @endforeach
            </select>
        </label>
        @if($mode !== 'sites')
        <label><span class="topo-group-label">Location</span>
            <select name="location" class="form-control" style="max-width: 220px">
                <option value="">All</option>
                @foreach($filters['locations'] as $location)
                    <option value="{{ $location['id'] }}" @selected($params['location'] == $location['id'])>{{ $location['name'] }}</option>
                @endforeach
            </select>
        </label>
        @endif

        @if($mode === 'sites')
            <label title="Join sites through their OSPF adjacencies"><input type="checkbox" name="ospf" value="1" @checked($params['ospf'])> OSPF</label>
            <label title="Join sites through their BGP sessions; sessions to peers LibreNMS does not monitor lead to one cloud per remote AS">
                <input type="checkbox" name="bgp" value="1" @checked($params['bgp'])> BGP
            </label>
            <label title="Include sites without any link to another site">
                <input type="checkbox" name="isolated" value="1" @checked($params['isolated'])> Unlinked sites
            </label>
        @elseif($mode === 'l2')
            <label><span class="topo-group-label">VLAN</span>
                <select id="topo-vlan" name="vlan" class="form-control" data-selected="{{ $params['vlan'] }}">
                    <option value="">All VLANs</option>
                    @if($params['vlan'])<option value="{{ $params['vlan'] }}" selected>{{ $params['vlan'] }}</option>@endif
                </select>
            </label>
            <label title="Show LLDP/CDP neighbors that are not monitored by LibreNMS (phones, APs, unmanaged switches)">
                <input type="checkbox" name="unmanaged" value="1" @checked($params['unmanaged'])> Unmanaged neighbors
            </label>
            <label title="Draw port-channel members as one link">
                <input type="checkbox" name="lag" value="1" @checked($params['lag'])> Collapse port-channels
            </label>
            <label title="Include devices without any discovered neighbor">
                <input type="checkbox" name="isolated" value="1" @checked($params['isolated'])> Isolated devices
            </label>
        @else
            <label><span class="topo-group-label">VRF</span>
                <select id="topo-vrf" name="vrf" class="form-control" data-selected="{{ $params['vrf'] }}">
                    <option value="">All VRFs</option>
                    @if($params['vrf'])<option value="{{ $params['vrf'] }}" selected>{{ $params['vrf'] }}</option>@endif
                </select>
            </label>
            <label title="Show subnets that only one device is connected to (user VLANs, loopback-like networks)">
                <input type="checkbox" name="stubs" value="1" @checked($params['stubs'])> Stub networks
            </label>
            <label><input type="checkbox" name="ospf" value="1" @checked($params['ospf'])> OSPF</label>
            <label><input type="checkbox" name="bgp" value="1" @checked($params['bgp'])> BGP</label>
            {{-- more overlays, shown by topology.js when the map's devices have such data; disabled until then so they stay out of the URL,
                 unless the page's query (with an open saved view's parameters) sets them --}}
            @foreach([
                'ospfv3' => ['OSPFv3', 'OSPFv3 adjacencies'],
                'isis' => ['IS-IS', 'IS-IS adjacencies'],
                'ipsec' => ['IPsec', 'IPsec tunnels to monitored devices and external peers'],
                'mpls' => ['MPLS LSPs', 'MPLS label switched paths, with an arrow toward the tail (off by default: a full mesh joins every two PEs)'],
                'pw' => ['Pseudowires', 'Pseudowires between PE devices, with the MTU check of their attachment circuits'],
            ] as $layer => [$layerLabel, $layerTitle])
                <label data-layer="{{ $layer }}" title="{{ $layerTitle }}" style="display: none"><input type="checkbox" name="{{ $layer }}" value="1" @checked($params[$layer]) @disabled(! array_key_exists($layer, $query))> {{ $layerLabel }}</label>
            @endforeach
            <label><input type="checkbox" name="ipv6" value="1" @checked($params['ipv6'])> IPv6</label>
            <label title="Include every device with an IP address, not only routers, L3 switches and firewalls">
                <input type="checkbox" name="all_devices" value="1" @checked($params['all_devices'])> All devices
            </label>
        @endif
        <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-refresh"></i> Update map</button>
    </form>

    <div id="topo-viewbar" class="topo-viewbar">
        <label><span class="topo-group-label">Layout</span>
            <select class="form-control" data-view="layout">
                <option value="hierarchical">Hierarchical (top-down)</option>
                <option value="organic">Organic</option>
                @if($mode === 'sites')<option value="geographic">Geographic (latitude / longitude)</option>@endif
            </select>
        </label>
        @if($mode === 'sites')
            <label><span class="topo-group-label">Color links by</span>
                <select class="form-control" data-view="colorBy">
                    <option value="speed">Capacity</option>
                    <option value="util">Utilization</option>
                    <option value="status">Status</option>
                </select>
            </label>
        @endif
        @if($mode === 'l2')
            <label><span class="topo-group-label">Color links by</span>
                <select class="form-control" data-view="colorBy">
                    <option value="speed">Speed</option>
                    <option value="util">Utilization</option>
                    <option value="status">Status</option>
                </select>
            </label>
            <label><input type="checkbox" data-view="stp"> STP overlay</label>
            <label title="Mark the top-most down device of each outage with the number of down devices behind it"><input type="checkbox" data-view="outages"> Outages</label>
        @endif
        @if($mode !== 'sites')
        <label><input type="checkbox" data-view="interfaceLabels"> Interface labels</label>
        @endif
        <label><input type="checkbox" data-view="linkLabels"> Link labels</label>
        <span class="topo-spacer"></span>
        @if($savedView)
            <span id="topo-view-badge" class="topo-view-badge" title="Saved view of {{ $savedView['owner'] }}{{ $savedView['shared'] ? ', shared' : '' }}: {{ $savedView['scope'] }}">
                <i class="fa fa-bookmark"></i> {{ $savedView['name'] }}
            </span>
        @endif
        <input id="topo-search" class="form-control" type="search" list="topo-search-list" placeholder="{{ $mode === 'sites' ? 'Find site' : 'Find device' }}&hellip;" style="width: 170px">
        <datalist id="topo-search-list"></datalist>
        <div class="btn-group">
            <button id="topo-fit" type="button" class="btn btn-default" title="Fit to screen"><i class="fa fa-expand"></i></button>
            <button id="topo-save-layout" type="button" class="btn btn-default" title="Save device positions (this browser)"><i class="fa fa-save"></i></button>
            <button id="topo-reset-layout" type="button" class="btn btn-default" title="Reset layout"><i class="fa fa-undo"></i></button>
            @if($views !== null)
            <div class="btn-group" id="topo-views">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Saved views">
                    <i class="fa fa-bookmark"></i> <span class="caret"></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-right topo-views-menu">
                    <li class="dropdown-header">Saved views of this map</li>
                    @forelse(array_slice($views, 0, 30) as $view)
                        <li class="{{ $savedView && $savedView['id'] === $view['id'] ? 'active' : '' }}">
                            <a href="{{ $view['url'] }}" title="{{ $view['scope'] }}" data-view-id="{{ $view['id'] }}">
                                <i class="fa {{ $view['shared'] ? 'fa-users' : 'fa-user' }} fa-fw"></i> {{ $view['name'] }}
                                <small class="text-muted">{{ $view['mine'] ? ($view['shared'] ? 'shared' : 'personal') : $view['owner'] }}</small>
                            </a>
                        </li>
                    @empty
                        <li class="disabled"><a href="#">None yet</a></li>
                    @endforelse
                    @if(count($views) > 30)
                        <li class="disabled"><a href="#">{{ count($views) - 30 }} more on the Saved Views page</a></li>
                    @endif
                    <li role="separator" class="divider"></li>
                    <li><a href="#" id="topo-view-save"><i class="fa fa-plus fa-fw"></i> Save this map as a view&hellip;</a></li>
                    @if($savedView && $savedView['can_edit'])
                        <li><a href="#" id="topo-view-update" title="The scope, filters, positions and display options of the map as it is now"><i class="fa fa-floppy-disk fa-fw"></i> Update &ldquo;{{ $savedView['name'] }}&rdquo;</a></li>
                    @endif
                    <li><a href="{{ route('topology.views') }}"><i class="fa fa-list fa-fw"></i> Manage saved views</a></li>
                </ul>
            </div>
            @endif
            <div class="btn-group" id="topo-export">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Export">
                    <i class="fa fa-download"></i> <span class="caret"></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-right">
                    <li class="dropdown-header">This map as drawn</li>
                    <li><a href="#" id="topo-export-png"><i class="fa fa-image fa-fw"></i> PNG image</a></li>
                    <li><a href="#" id="topo-export-drawio" title="Opens in draw.io (diagrams.net), which also exports it to Visio"><i class="fa fa-diagram-project fa-fw"></i> draw.io diagram (.drawio)</a></li>
                    <li><a href="#" id="topo-export-graphml" title="Opens in yEd with the positions and labels of this map"><i class="fa fa-share-nodes fa-fw"></i> GraphML for yEd (.graphml)</a></li>
                    <li><a href="#" id="topo-export-csv"><i class="fa fa-table fa-fw"></i> Links as CSV</a></li>
                    @if($canExportCustomMap)
                        <li role="separator" class="divider"></li>
                        <li><a href="#" id="topo-export-custommap" title="A LibreNMS Custom Map of this map, with live status and utilization (admins)"><i class="fa fa-map fa-fw"></i> LibreNMS Custom Map&hellip;</a></li>
                    @endif
                </ul>
            </div>
            <button id="topo-fullscreen" type="button" class="btn btn-default" title="Full screen"><i class="fa fa-arrows-alt"></i></button>
        </div>
    </div>

    @if($viewError)
        <div class="alert alert-warning topo-view-error"><i class="fa fa-exclamation-triangle"></i> {{ $viewError }}</div>
    @endif

    <div class="topo-body">
        <div class="topo-canvas-wrap">
            <div id="topo-canvas"></div>
            <div id="topo-sim" class="topo-sim" style="display: none"></div>
            <div id="topo-loading" class="topo-loading"><span><i class="fa fa-spinner fa-spin"></i> Building map&hellip;</span></div>
        </div>
        <aside id="topo-details" class="topo-panel"></aside>
    </div>
    <div id="topo-status" class="topo-status-bar"></div>
</div>

@if($views !== null)
{{-- Save the map as a view (ViewController). Like the dialog below, outside #topo-app, whose full screen mode would put it
     under its backdrop. --}}
<div class="modal fade" id="topo-view-modal" tabindex="-1" role="dialog" aria-labelledby="topo-view-title">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="topo-view-title"><i class="fa fa-bookmark"></i> Save as a view</h4>
            </div>
            <div class="modal-body">
                <p class="text-muted">Keeps the scope, filters, device positions and display options of this map.</p>
                <div class="form-group">
                    <label for="topo-view-name">Name</label>
                    <input id="topo-view-name" class="form-control" maxlength="100" autocomplete="off" placeholder="e.g. Core and distribution">
                </div>
                <div class="checkbox">
                    <label><input type="checkbox" id="topo-view-shared"> Share with everyone who can open the maps</label>
                </div>
                <p class="text-muted small">Each user sees a shared view with their own permissions: only the devices they may see. Its name is
                    shown to all of them, so leave out names of devices, groups or locations they should not see.</p>
                <div id="topo-view-message"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="topo-view-create"><i class="fa fa-check"></i> Save view</button>
            </div>
        </div>
    </div>
</div>
@endif

@if($canExportCustomMap)
    {{-- Export to a LibreNMS Custom Map (ExportController): preview first, then create or replace. Outside #topo-app,
         whose full screen mode would put the dialog under its backdrop. --}}
    <div class="modal fade" id="topo-custommap-modal" tabindex="-1" role="dialog" aria-labelledby="topo-custommap-title">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="topo-custommap-title"><i class="fa fa-map"></i> Export to a LibreNMS Custom Map</h4>
                </div>
                <div class="modal-body">
                    <p class="text-muted">A Custom Map of this map with its positions. LibreNMS draws it with the live status of the devices and
                        the utilization of the links, it can be edited in LibreNMS' map editor and shown on a dashboard (Custom Map widget).</p>
                    <div class="form-group">
                        <label for="topo-custommap-target">Map</label>
                        <select id="topo-custommap-target" class="form-control"><option value="">Create a new map</option></select>
                    </div>
                    <div class="form-group">
                        <label for="topo-custommap-name">Name</label>
                        <input id="topo-custommap-name" class="form-control" maxlength="100" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="topo-custommap-group">Menu group <small class="text-muted">(optional: groups the map in LibreNMS' Maps menu)</small></label>
                        <input id="topo-custommap-group" class="form-control" maxlength="100" value="Topology" autocomplete="off">
                    </div>
                    <div id="topo-custommap-preview" class="topo-custommap-preview"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="topo-custommap-create" disabled><i class="fa fa-check"></i> Create map</button>
                </div>
            </div>
        </div>
    </div>
@endif
@endsection

@php
    $topoConfig = [
        'mode' => $mode,
        'urls' => [
            'api' => route('topology.api.' . $mode),
            'deviceSelect' => url('ajax/select/device'),
            'l2Page' => route('topology.l2'),
            'l3Page' => route('topology.l3'),
            'stpPage' => route('topology.stp'),
            'redundancyPage' => route('topology.redundancy'),
            'simulate' => route('topology.api.simulate'),
            'hosts' => route('topology.api.hosts'),
            'hostsPage' => route('topology.hosts'),
            'devicesPage' => url('devices'),
        ] + ($views !== null ? [
            'views' => route('topology.api.views.store'),
            'view' => $savedView ? route('topology.api.views.save', ['id' => $savedView['id']]) : null,
        ] : []) + ($canExportCustomMap ? [
            'customMapPreview' => route('topology.api.custom-map.preview'),
            'customMap' => route('topology.api.custom-map'),
        ] : []),
        // the saved view this page opened (?view=)
        'view' => $savedView ? array_intersect_key($savedView, array_flip(['id', 'name', 'owner', 'mine', 'shared', 'can_edit', 'options'])) : null,
    ];
@endphp

@push('scripts')
    <script src="{{ asset('js/vis-network.min.js') }}"></script>
    <script src="{{ asset('js/vis-data.min.js') }}"></script>
    <script src="{{ route('topology.asset', 'topology.js') }}?v={{ $assetVersion }}"></script>
    <script>
        window.TopologyMap.init(@json($topoConfig));
    </script>
@endpush
