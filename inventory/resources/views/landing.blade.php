{{-- The plugin's page in Plugin Admin (Hooks\Page): where its pages are. --}}
<div style="margin: 15px; max-width: 800px;">
    <h4>Inventory: models, software, serial numbers and optics across the network</h4>
    <p>Reports from what LibreNMS already collects (each device's model, software version, serial number and features, its hardware
        inventory and its optics), for the devices you may see, filtered by device group, location and OS, each exported as CSV.</p>
    <ul>
        <li><a href="{{ route('inventory.overview') }}">Overview</a>: the models across the network (each stack member counted) and the software versions</li>
        <li><a href="{{ route('inventory.devices') }}">Devices</a>: each device's model, software, serial numbers and how many modules and optics it has</li>
        <li><a href="{{ route('inventory.modules') }}">Modules &amp; serial numbers</a>: chassis, line cards, power supplies, fans and pluggables with their serial numbers and revisions</li>
        <li><a href="{{ route('inventory.optics') }}">Optics</a>: by type, vendor, model and wavelength, third-party optics included</li>
        <li><a href="{{ route('inventory.search') }}">Search</a>: where a serial number or part number is</li>
    </ul>
</div>
