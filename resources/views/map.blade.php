{{-- L2 / L3 map page (PageController::l2/l3): filters and view options; resources/assets/topology.js draws the map from the JSON API. --}}
@extends('layouts.librenmsv1')

@section('title', $mode === 'l2' ? 'Layer 2 Topology' : 'Layer 3 Topology')

@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@section('content')
<div class="container-fluid topo-page" id="topo-app">
    @include('topology::partials.nav', ['active' => $mode])

    <form id="topo-filters" class="topo-toolbar" autocomplete="off">
        <label><span class="topo-group-label">Center on</span>
            <select id="topo-device" name="device" class="form-control">
                <option value=""></option>
                @if($params['device'] && $deviceName)
                    <option value="{{ $params['device'] }}" selected>{{ $deviceName }}</option>
                @endif
            </select>
        </label>
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
        <label><span class="topo-group-label">Location</span>
            <select name="location" class="form-control" style="max-width: 220px">
                <option value="">All</option>
                @foreach($filters['locations'] as $location)
                    <option value="{{ $location['id'] }}" @selected($params['location'] == $location['id'])>{{ $location['name'] }}</option>
                @endforeach
            </select>
        </label>

        @if($mode === 'l2')
            <label><span class="topo-group-label">VLAN</span>
                <select id="topo-vlan" name="vlan" class="form-control" data-selected="{{ $params['vlan'] }}">
                    <option value="">All VLANs</option>
                    @if($params['vlan'])<option value="{{ $params['vlan'] }}" selected>{{ $params['vlan'] }}</option>@endif
                </select>
            </label>
            <label title="Show LLDP/CDP neighbors that are not monitored by LibreNMS (phones, APs, unmanaged switches)">
                <input type="checkbox" name="unmanaged" value="1" @checked($params['unmanaged'])> Unmanaged neighbors
            </label>
            <label title="Draw port-channel / LAG members as one link">
                <input type="checkbox" name="lag" value="1" @checked($params['lag'])> Collapse LAGs
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
            {{-- more overlays, shown by topology.js when the map's devices have such data; disabled until then so they stay out of the URL --}}
            @foreach([
                'ospfv3' => ['OSPFv3', 'OSPFv3 adjacencies'],
                'isis' => ['IS-IS', 'IS-IS adjacencies'],
                'ipsec' => ['IPsec', 'IPsec tunnels to monitored devices and external peers'],
                'mpls' => ['MPLS LSPs', 'MPLS label switched paths, with an arrow toward the tail (off by default: a full mesh joins every two PEs)'],
                'pw' => ['Pseudowires', 'Pseudowires between PE devices, with the MTU check of their attachment circuits'],
            ] as $layer => [$layerLabel, $layerTitle])
                <label data-layer="{{ $layer }}" title="{{ $layerTitle }}" style="display: none"><input type="checkbox" name="{{ $layer }}" value="1" @checked($params[$layer]) @disabled(! request()->has($layer))> {{ $layerLabel }}</label>
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
            </select>
        </label>
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
        <label><input type="checkbox" data-view="interfaceLabels"> Interface labels</label>
        <label><input type="checkbox" data-view="linkLabels"> Link labels</label>
        <span class="topo-spacer"></span>
        <input id="topo-search" class="form-control" type="search" list="topo-search-list" placeholder="Find device&hellip;" style="width: 170px">
        <datalist id="topo-search-list"></datalist>
        <div class="btn-group">
            <button id="topo-fit" type="button" class="btn btn-default" title="Fit to screen"><i class="fa fa-expand"></i></button>
            <button id="topo-save-layout" type="button" class="btn btn-default" title="Save device positions (this browser)"><i class="fa fa-save"></i></button>
            <button id="topo-reset-layout" type="button" class="btn btn-default" title="Reset layout"><i class="fa fa-undo"></i></button>
            <button id="topo-export-png" type="button" class="btn btn-default" title="Export PNG"><i class="fa fa-image"></i></button>
            <button id="topo-export-csv" type="button" class="btn btn-default" title="Export links as CSV"><i class="fa fa-table"></i></button>
            <button id="topo-fullscreen" type="button" class="btn btn-default" title="Full screen"><i class="fa fa-arrows-alt"></i></button>
        </div>
    </div>

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
        ],
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
