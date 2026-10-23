{{-- The Fixes page (FixController): the topology plugin's findings whose fix is clear, as the admin sees the network, by
     kind; Fix opens a new change made from the chosen fixes of one kind. resources/assets/ansible.js loads the list from
     the plugin's JSON and shows it as text, never as HTML: device names, ports and descriptions come from LibreNMS and from
     LLDP/CDP, and can hold anything. --}}
@extends('layouts.librenmsv1')

@section('title', 'Ansible fixes')

@push('styles')
    <link href="{{ route('ansible.asset', 'ansible.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@section('content')
<div class="container-fluid ans-page" id="ans-fixes">
    @include('ansible::partials.nav', ['active' => 'fixes'])

    <p class="ans-help" id="ans-fixes-intro" style="margin: 0 0 8px">The topology plugin's findings whose fix is clear, as you see the network: port descriptions that
        name the wrong neighbor or none (the description its link check builds from the LLDP/CDP neighbor), and a spanning tree root that is not the intended one (the
        first designated root bridge of its part of the network, else its first core device, with priority 4096, the next one as secondary with 8192). <strong>Fix</strong>
        opens a new change of the chosen fixes of one kind: the template the settings map to the kind, the fixes' devices, the answers as data, read only. It is
        dry-run, approved and applied as any change, each batch verified: its findings must be resolved, and nothing else must change.</p>
    @if($unavailable !== null)
        <div class="alert alert-warning" id="ans-fixes-unavailable"><i class="fa fa-exclamation-triangle"></i> The topology plugin cannot list fixes now: {{ $unavailable }}.</div>
    @endif
    @unless($canRun)
        <div class="alert alert-info" id="ans-fixes-no-run"><i class="fa fa-info-circle"></i> Making a change needs the Ansible plugin's run permission: an admin grants it on
            the plugin's <a href="{{ url('plugin/settings/ansible') }}#ans-permission">settings page</a>.</div>
    @endunless
    <p class="ans-muted" id="ans-fixes-status">Loading the fixes…</p>
    <div id="ans-fixes-list"></div>

    <script type="application/json" id="ans-fixes-config">@json(['list' => route('ansible.api.fixes'), 'deviceUrl' => url('device'), 'settings' => url('plugin/settings/ansible') . '#ans-verify-form'])</script>
</div>
@endsection

@section('scripts')
    <script src="{{ route('ansible.asset', 'ansible.js') }}?v={{ $assetVersion }}"></script>
    <script>AnsiblePlugin.fixes(JSON.parse(document.getElementById('ans-fixes-config').textContent));</script>
@endsection
