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
    {{-- as the topology plugin's pages filter: a device, a device group, a location (each one given narrows the list; the topology
         plugin then computes the fixes of those devices only) --}}
    <form class="form-inline ans-filterbar" method="get" action="{{ route('ansible.fixes') }}" id="ans-fixes-filter" style="margin: 0 0 8px">
        <label for="ans-fixes-device" style="font-weight: normal">Device</label>
        <select id="ans-fixes-device" name="device" class="form-control input-sm" style="min-width: 200px">
            <option value="">All devices</option>
            @if(isset($filter['device']))
                <option value="{{ $filter['device'] }}" selected>{{ $deviceName }}</option>
            @endif
        </select>
        <label for="ans-fixes-group" style="font-weight: normal">Group</label>
        <select id="ans-fixes-group" name="group" class="form-control input-sm">
            <option value="">All</option>
            @foreach($groups as $groupId => $groupName)
                <option value="{{ $groupId }}" @selected(($filter['group'] ?? null) === (int) $groupId)>{{ $groupName }}</option>
            @endforeach
        </select>
        <label for="ans-fixes-location" style="font-weight: normal">Location</label>
        <select id="ans-fixes-location" name="location" class="form-control input-sm" style="max-width: 240px">
            <option value="">All</option>
            @foreach($locations as $locationId => $locationName)
                <option value="{{ $locationId }}" @selected(($filter['location'] ?? null) === (int) $locationId)>{{ $locationName }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-sm btn-primary" id="ans-fixes-filter-button"><i class="fa fa-filter"></i> Filter</button>
        @if($filter)
            <a class="btn btn-sm btn-default" href="{{ route('ansible.fixes') }}" id="ans-fixes-filter-clear">Every device</a>
        @endif
    </form>
    @if($filterInvalid)
        <div class="alert alert-warning" id="ans-fixes-filter-invalid"><i class="fa fa-exclamation-triangle"></i> The filter is not valid (a device, a device group and a
            location are given by their number): the page lists the fixes of every device.</div>
    @endif
    <p class="ans-muted" id="ans-fixes-status">Loading the fixes…</p>
    <div id="ans-fixes-list"></div>

    @php
        $fixesConfig = ['list' => route('ansible.api.fixes', $filter), 'filtered' => (bool) $filter, 'deviceUrl' => url('device'),
            'settings' => url('plugin/settings/ansible') . '#ans-verify-form'];
    @endphp
    <script type="application/json" id="ans-fixes-config">@json($fixesConfig)</script>
</div>
@endsection

@section('scripts')
    <script src="{{ route('ansible.asset', 'ansible.js') }}?v={{ $assetVersion }}"></script>
    <script>AnsiblePlugin.fixes(JSON.parse(document.getElementById('ans-fixes-config').textContent));</script>
    <script>
        // the device picker of the topology plugin's pages: LibreNMS' own device search (select2), when the page has it
        (function ($) {
            if (!$ || !$.fn || !$.fn.select2) {
                return;
            }
            $('#ans-fixes-device').select2({
                theme: 'bootstrap', width: '240px', allowClear: true, placeholder: 'All devices',
                ajax: {url: @json(url('ajax/select/device')), delay: 200, data: function (params) {
                    return {term: params.term, page: params.page || 1};
                }}
            });
        })(window.jQuery);
    </script>
@endsection
