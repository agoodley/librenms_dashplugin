{{-- A finding whose fix is clear, linked to the Ansible plugin's Fixes page when that plugin is enabled and the user is an
     admin (Support\AnsibleFixes): $fixPage is the page's URL or null, $analysis the check. Nothing without it. --}}
@php
    $fixLink = \Agoodley\LibrenmsTopology\Support\AnsibleFixes::link($fixPage ?? null, $analysis, $finding);
@endphp
@if($fixLink !== null)
    <a href="{{ $fixLink }}" class="topo-fix-link" title="Make a change that fixes it, on the Ansible plugin's Fixes page"><i class="fa fa-wrench"></i> Fix with Ansible</a>
@endif
