{{-- The Inventory panel on a device's overview (Hooks\DeviceOverview): its chassis (each member of a stack), its software
     against the policies and its last version change, how many serial numbers its hardware inventory has, its optics.
     Nothing for a device that has none of them. --}}
@php
    $id = (int) $device->device_id;
    $units = $chassis;
    $checked = $software !== null && $software['status'] !== \Agoodley\LibrenmsInventory\Software\Status::NO_POLICY;
@endphp
@if($error || count($units) > 1 || $counts['serials'] > 0 || $counts['optics'] > 0 || $checked || $upgrade)
<link href="{{ route('inventory.asset', 'inventory.css') }}?v={{ \Agoodley\LibrenmsInventory\Http\Controllers\Controller::assetVersion() }}" rel="stylesheet">
<div class="panel panel-default panel-condensed inv-device-panel" id="inv-device-panel">
    <div class="panel-heading">
        <i class="fa fa-cubes fa-lg icon-theme" aria-hidden="true"></i>
        <strong>Inventory</strong>
        <a class="pull-right" href="{{ route('inventory.devices', ['device' => $id]) }}" style="font-size: 12px">in the inventory</a>
    </div>
    <div class="panel-body">
        @if($error)
            <div class="alert alert-warning" style="margin: 0">{{ $error }}</div>
        @else
            <dl class="dl-horizontal">
                @if(count($units) > 1)
                    <dt>Chassis</dt>
                    <dd><ul>@foreach($units as $unit)<li><span class="inv-code">{{ $unit['serial'] ?: '-' }}</span> {{ $unit['model'] }}@if($unit['name'] !== '')<span class="text-muted"> ({{ $unit['name'] }})</span>@endif</li>@endforeach</ul></dd>
                @endif
                @if($checked)
                    <dt>Software</dt>
                    <dd id="inv-device-software"><a href="{{ route('inventory.software', ['device' => $id]) }}" title="{{ $software['message'] }}">@include('inventory::partials.status', ['status' => (string) $software['status']])</a>
                        <span class="inv-code">{{ $software['version'] }}</span>@if($software['policy'])<span class="text-muted"> ({{ $software['policy'] }})</span>@endif
                        @if(\Agoodley\LibrenmsInventory\Software\Status::failing((string) $software['status']))<div class="inv-why">{{ $software['message'] }}</div>@endif</dd>
                @endif
                @if($upgrade)
                    <dt>Last {{ $upgrade['direction'] }}</dt>
                    <dd id="inv-device-upgrade"><a href="{{ route('inventory.upgrades', ['device' => $id]) }}">{{ \Agoodley\LibrenmsInventory\Support\Clock::local($upgrade['seen_at'], 'Y-m-d') }}</a>:
                        <span class="inv-code">{{ $upgrade['from_version'] }}</span> to <span class="inv-code">{{ $upgrade['to_version'] }}</span></dd>
                @endif
                <dt>Serial numbers</dt>
                <dd>@if($counts['serials'] > 0)<a href="{{ route('inventory.modules', ['device' => $id]) }}">{{ number_format($counts['serials']) }} in the hardware inventory</a>@else
                    <span class="text-muted">none in the hardware inventory</span>@endif</dd>
                <dt>Optics</dt>
                <dd>@if($counts['optics'] > 0)<a href="{{ route('inventory.optics', ['device' => $id]) }}">{{ number_format($counts['optics']) }}</a>
                    @if($origins)<span class="text-muted">({{ $origins['third-party'] }} third-party, {{ $origins['genuine'] }} genuine, {{ $origins['unknown'] }} unknown)</span>@endif
                    @else<span class="text-muted">none</span>@endif</dd>
            </dl>
        @endif
    </div>
</div>
@endif
