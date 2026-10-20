{{-- Landing page /plugin/topology (Hooks\Page), rendered inside LibreNMS' plugin page layout. --}}
@push('styles')
    <link href="{{ route('topology.asset', 'topology.css') }}" rel="stylesheet">
@endpush
<div class="container-fluid">
    <h3>Network Topology</h3>
    <div class="topo-cards" style="display: flex; flex-wrap: wrap; gap: 12px;">
        <a class="topo-card panel panel-default" style="flex: 1 1 260px; padding: 14px;" href="{{ route('topology.l2') }}">
            <h4><i class="fa fa-sitemap fa-fw"></i> Layer 2 Map</h4>
            <p>Switches and their LLDP/CDP links with interface names, link speeds, port-channels, VLAN highlighting and a spanning tree overlay (root bridge, blocked ports).</p>
        </a>
        <a class="topo-card panel panel-default" style="flex: 1 1 260px; padding: 14px;" href="{{ route('topology.l3') }}">
            <h4><i class="fa fa-project-diagram fa-fw"></i> Layer 3 Map</h4>
            <p>Routers, L3 switches and firewalls joined through their IP subnets, with point-to-point links, LAN segments, VRFs and OSPF / BGP adjacencies.</p>
        </a>
        <a class="topo-card panel panel-default" style="flex: 1 1 260px; padding: 14px;" href="{{ route('topology.stp') }}">
            <h4><i class="fa fa-stethoscope fa-fw"></i> Spanning Tree Diagnostics</h4>
            <p>Root bridge consistency, rogue roots, topology change storms, flapping and blocked ports, bridging loops, timer and protocol mismatches.</p>
        </a>
        <a class="topo-card panel panel-default" style="flex: 1 1 260px; padding: 14px;" href="{{ route('topology.links') }}">
            <h4><i class="fa fa-link fa-fw"></i> Link &amp; VLAN Diagnostics</h4>
            <p>Both ends of every link compared: speed, duplex, MTU, trunk/native/allowed VLANs and port-channels, plus split VLANs, errors, optical levels and port descriptions.</p>
        </a>
        <a class="topo-card panel panel-default" style="flex: 1 1 260px; padding: 14px;" href="{{ route('topology.redundancy') }}">
            <h4><i class="fa fa-code-branch fa-fw"></i> Redundancy &amp; Failure Impact</h4>
            <p>Single points of failure, uplinks that only look redundant (same switch, same stack member), redundancy lost right now, and outages with the devices behind them.</p>
        </a>
    </div>
</div>
