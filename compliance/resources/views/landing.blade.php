{{-- The plugin's page in Plugin Admin (Hooks\Page): where its pages are. --}}
<div style="margin: 15px; max-width: 800px;">
    <h4>Compliance: rule checks on the configuration backups</h4>
    <p>Checks the latest configuration backup of each device (Oxidized, RANCID or Unimus, as LibreNMS' Config tab shows it) against rules:
        portfast and BPDU guard on edge ports, no portfast toward a switch, root guard toward the switches below, and baselines of AAA, NTP,
        syslog, SNMP and SSH; plus the rules an admin writes. What a port faces comes from LibreNMS' LLDP/CDP neighbors and spanning tree.</p>
    <ul>
        <li><a href="{{ route('compliance.overview') }}">Overview</a>: how compliant the devices are, each rule's outcomes, the findings and their export</li>
        <li><a href="{{ route('compliance.devices') }}">Devices</a>: each device's state, and its findings with the lines of its backup</li>
        <li><a href="{{ route('compliance.rules') }}">Rules</a>: what each rule checks and how to fix it</li>
        @if(\Agoodley\LibrenmsCompliance\Support\Access::isAdmin(auth()->user()))
            <li><a href="{{ url('plugin/settings/compliance') }}">Settings</a>: when the devices are checked, which rules, the servers, the eventlog, your own rules</li>
        @endif
    </ul>
</div>
