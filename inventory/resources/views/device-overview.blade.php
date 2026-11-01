{{-- The Inventory panel on a device's overview (Hooks\DeviceOverview): its chassis (each member of a stack), how many serial
     numbers its hardware inventory has, its optics. Nothing for a device that has none of them. --}}
@php
    $id = (int) $device->device_id;
    $units = $chassis;
@endphp
@if($error || count($units) > 1 || $counts['serials'] > 0 || $counts['optics'] > 0)
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
