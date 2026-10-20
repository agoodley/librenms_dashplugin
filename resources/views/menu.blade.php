{{-- LibreNMS wraps each plugin menu entry in one <li>; the extra items close and reopen it. --}}
<a href="{{ route('topology.l2') }}"><i class="fa fa-sitemap fa-fw fa-lg" aria-hidden="true"></i> L2 Topology Map</a></li>
<li><a href="{{ route('topology.l3') }}"><i class="fa fa-project-diagram fa-fw fa-lg" aria-hidden="true"></i> L3 Topology Map</a></li>
<li><a href="{{ route('topology.stp') }}"><i class="fa fa-stethoscope fa-fw fa-lg" aria-hidden="true"></i> STP Diagnostics</a></li>
<li><a href="{{ route('topology.links') }}"><i class="fa fa-link fa-fw fa-lg" aria-hidden="true"></i> Link &amp; VLAN Diagnostics</a></li>
<li><a href="{{ route('topology.redundancy') }}"><i class="fa fa-code-branch fa-fw fa-lg" aria-hidden="true"></i> Redundancy &amp; Failure Impact</a>
