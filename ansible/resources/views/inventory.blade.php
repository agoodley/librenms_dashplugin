{{-- Inventory (InventoryController::show): what the runner gets from LibreNMS with the inventory user's token, the
     devices left out and why, the fetches and the setup steps. --}}
@extends('layouts.librenmsv1')

@section('title', 'Ansible inventory')

@push('styles')
    <link href="{{ route('ansible.asset', 'ansible.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $hosts = $inventory ? $inventory->hosts() : [];
    $groups = $inventory ? $inventory->groups() : [];
    $groupInfo = $inventory ? $inventory->groupInfo() : [];
    $hostGroups = [];
    foreach ($groups as $group => $members) {
        foreach ($members as $member) {
            $hostGroups[$member][] = $group;
        }
    }
    $kinds = ['os' => 'LibreNMS os', 'platform' => 'Ansible platform', 'group' => 'device group', 'location' => 'location', 'type' => 'device type'];
    $reasonLabels = ['disabled' => 'disabled', 'outside_group' => 'outside the allowed group', 'no_mapping' => 'no platform mapping',
        'no_address' => 'no usable address', 'not_visible' => 'not visible to the inventory\'s user'];
    $script = 'inventory/librenms_inventory.py';
    $fetch = $inventoryUser['fetch'] ?? null;
@endphp

@section('content')
<div class="container-fluid ans-page">
    @include('ansible::partials.nav', ['active' => 'inventory'])

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif
    @if($mapErrors)
        <div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> Lines of the Platform mapping setting that were skipped:
            <ul style="margin: 4px 0 0">@foreach($mapErrors as $mapError)<li>{{ $mapError }}</li>@endforeach</ul></div>
    @endif

    <div class="ans-cards">
        <div class="ans-card ans-{{ $hosts ? 'ok' : 'warning' }}">
            <h4>Hosts</h4>
            <div class="ans-card-value" id="ans-inventory-hosts">{{ count($hosts) }}</div>
            <p>{{ $inventoryUser ? 'what the runner gets with the token of the inventory user ' . $inventoryUser['username'] : 'as a user who sees every device (no inventory user is chosen yet)' }}</p>
        </div>
        <div class="ans-card ans-info">
            <h4>Groups</h4>
            <div class="ans-card-value">{{ count($groups) }}</div>
            <p>by LibreNMS os, platform, device group, location and type</p>
        </div>
        <div class="ans-card ans-{{ $leftOut ? 'warning' : 'ok' }}">
            <h4>Left out</h4>
            <div class="ans-card-value">{{ count($leftOut) }}</div>
            <p>devices that cannot be targets, with the reason below</p>
        </div>
        <div class="ans-card ans-{{ $fetch ? 'ok' : 'critical' }}" id="ans-last-fetch">
            @if(! $inventoryUser)
                <h4>Inventory user</h4>
                <div class="ans-card-value" style="font-size: 16px">not chosen</div>
                <p>{{ $inventoryUserProblem }} <a href="{{ url('plugin/settings/ansible') }}#ans-inventory-user">Settings</a></p>
            @else
                <h4>Last fetch by the runner</h4>
                @if($fetch)
                    <div class="ans-card-value" style="font-size: 16px">{{ $fetch['fetched_at'] }}</div>
                    <p>with the token of the inventory user {{ $inventoryUser['username'] }}, {{ \Agoodley\LibrenmsAnsible\Support\Format::count($fetch['hosts'], 'host') }}{{ $fetch['address'] ? ', from ' . $fetch['address'] : '' }}</p>
                @else
                    <div class="ans-card-value" style="font-size: 16px">never</div>
                    <p>with the token of the inventory user {{ $inventoryUser['username'] }}: follow the setup steps below</p>
                @endif
            @endif
        </div>
    </div>

    <p>
        <a class="btn btn-sm btn-default" href="{{ route('ansible.inventory.download') }}" id="ans-inventory-download"><i class="fa fa-download"></i> Download JSON</a>
        <span class="ans-muted">the inventory of this page, as Ansible's dynamic inventory JSON</span>
    </p>

    <details class="panel panel-default" id="ans-setup" @if(! $fetch) open @endif>
        <summary class="panel-heading" style="cursor: pointer"><strong>Setup steps</strong> <span class="text-muted">(the runner pulls the inventory from LibreNMS)</span></summary>
        <div class="panel-body">
            <ol style="padding-left: 18px; margin-bottom: 0">
                <li>In LibreNMS, create a user for the inventory (a role that sees the devices the runner may change, e.g. global-read)
                    and an API token for it (its user menu → API Access).</li>
                <li>Copy <span class="ans-code">contrib/librenms_inventory.py</span> of the plugin into the playbook repository as
                    <span class="ans-code">{{ $script }}</span>, executable (<span class="ans-code">git update-index --chmod=+x {{ $script }}</span>), and commit it.</li>
                <li>In Semaphore UI, add an environment with the variable <span class="ans-code">LIBRENMS_URL</span> =
                    <span class="ans-code">{{ url('/') }}</span> and the secret environment variable <span class="ans-code">LIBRENMS_TOKEN</span>
                    = the token (optional: <span class="ans-code">LIBRENMS_CA_BUNDLE</span>, the path of a CA file in the runner).</li>
                <li>Add an inventory of type <em>File</em> with the path <span class="ans-code">{{ $script }}</span> in that repository, with the
                    SSH key of the devices (and the become key for "enable"). Its id goes in the plugin settings
                    @if($connection && $connection['inventory'])(now: #{{ $connection['inventory'] }})@endif.</li>
                <li>Give each template the plugin may launch this inventory and the environment, and tick "Allow limit override".</li>
                <li>Check it: the script fetches <span class="ans-code">{{ $endpoint }}</span>; a run (or Test connection) makes the runner fetch it, and the
                    last fetch above shows it worked.</li>
            </ol>
        </div>
    </details>

    <div class="row">
        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading"><strong>Groups</strong> <span class="text-muted">({{ count($groups) }})</span></div>
                <div style="max-height: 520px; overflow-y: auto">
                <table class="table table-condensed ans-table" id="ans-inventory-groups">
                    <thead><tr><th>Group</th><th>From</th><th class="ans-num">Hosts</th></tr></thead>
                    <tbody>
                    @foreach($groups as $group => $members)
                        <tr>
                            <td class="ans-code">{{ $group }}</td>
                            <td class="ans-wrap">{{ $kinds[$groupInfo[$group]['kind'] ?? ''] ?? '' }}: {{ $groupInfo[$group]['label'] ?? '' }}</td>
                            <td class="ans-num">{{ count($members) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading"><strong>Left out</strong> <span class="text-muted">({{ count($leftOut) }})</span></div>
                <div style="max-height: 520px; overflow-y: auto">
                    <table class="table table-condensed ans-table" id="ans-inventory-left-out">
                        <thead><tr><th>Device</th><th>Why</th></tr></thead>
                        <tbody>
                        @forelse($leftOut as $deviceId => $reason)
                            <tr data-device="{{ $deviceId }}" data-reason="{{ $reason['reason'] }}">
                                <td class="ans-wrap"><a href="{{ url('device/' . $deviceId) }}">{{ $names[$deviceId] ?? '#' . $deviceId }}</a></td>
                                <td class="ans-wrap" title="{{ $reasonLabels[$reason['reason']] ?? $reason['reason'] }}">{{ $reason['text'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="text-muted">None.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><strong>Hosts</strong> <span class="text-muted">({{ count($hosts) }})</span></div>
        <div class="table-responsive" style="max-height: 720px; overflow-y: auto">
            <table class="table table-condensed table-hover ans-table" id="ans-inventory-hosts-table">
                <thead><tr><th>Inventory name</th><th>Device</th><th>ansible_host</th><th>Platform</th><th>Connection</th><th>Facts</th><th>Groups</th></tr></thead>
                <tbody>
                @forelse($hosts as $name => $vars)
                    <tr data-device="{{ $vars['librenms_device_id'] }}">
                        <td class="ans-code">{{ $name }}</td>
                        <td class="ans-wrap"><a href="{{ url('device/' . $vars['librenms_device_id']) }}">{{ $names[$vars['librenms_device_id']] ?? $vars['librenms_hostname'] }}</a></td>
                        <td class="ans-code">{{ $vars['ansible_host'] }}</td>
                        <td class="ans-code">{{ $vars['ansible_network_os'] }}</td>
                        <td class="ans-code" title="{{ $vars['ansible_connection'] }}">{{ \Illuminate\Support\Str::afterLast($vars['ansible_connection'], '.') }}{{ ! empty($vars['ansible_become']) ? ' + enable' : '' }}</td>
                        <td class="ans-wrap" style="font-size: 12px">
                            {{ $vars['librenms_os'] }}@if($vars['librenms_sysname']) · {{ $vars['librenms_sysname'] }}@endif
                            @if($vars['librenms_hardware']) · {{ $vars['librenms_hardware'] }}@endif
                            @if($vars['librenms_version']) · {{ $vars['librenms_version'] }}@endif
                            @if($vars['librenms_location']) · {{ $vars['librenms_location'] }}@endif
                        </td>
                        <td class="ans-code ans-wrap" style="font-size: 11px">{{ implode(' ', $hostGroups[$name] ?? []) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">No device is a host: see the devices left out below and the Platform mapping setting.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($fetches && (! $inventoryUser || count($fetches) > 1 || ! $fetch))
        <div class="panel panel-default" id="ans-fetches">
            <div class="panel-heading"><strong>Fetches by user</strong></div>
            <table class="table table-condensed ans-table">
                <thead><tr><th>User</th><th>Last fetch</th><th class="ans-num">Hosts</th><th>From</th></tr></thead>
                @foreach($fetches as $row)
                    <tr><td>{{ $row['username'] }}</td><td>{{ $row['fetched_at'] }}</td><td class="ans-num">{{ $row['hosts'] }}</td><td>{{ $row['address'] }}</td></tr>
                @endforeach
            </table>
            <div class="panel-footer ans-muted" style="font-size: 12px">
                @if($inventoryUser)
                    Only the inventory user's token gets an answer now, and the plugin checks targets against what that user may see.
                @else
                    The plugin trusts none of these yet: an admin chooses the inventory user in the settings, then only that user's token gets an answer.
                @endif
            </div>
        </div>
    @endif
</div>
@endsection
