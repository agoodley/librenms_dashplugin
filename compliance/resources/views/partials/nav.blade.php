{{-- Tabs of every page of the plugin; "Settings" for LibreNMS admins. --}}
<div class="cmp-nav">
    <ul class="nav nav-tabs">
        <li class="{{ $active === 'overview' ? 'active' : '' }}"><a href="{{ route('compliance.overview') }}"><i class="fa fa-check-square-o fa-fw"></i> Overview</a></li>
        <li class="{{ $active === 'devices' ? 'active' : '' }}"><a href="{{ route('compliance.devices') }}"><i class="fa fa-server fa-fw"></i> Devices</a></li>
        @if($active === 'device')
            <li class="active"><a href="#"><i class="fa fa-file-text-o fa-fw"></i> {{ $deviceName }}</a></li>
        @endif
        <li class="{{ $active === 'rules' ? 'active' : '' }}"><a href="{{ route('compliance.rules') }}"><i class="fa fa-list fa-fw"></i> Rules</a></li>
        @if(\Agoodley\LibrenmsCompliance\Support\Access::isAdmin(auth()->user()))
            <li><a href="{{ url('plugin/settings/compliance') }}"><i class="fa fa-cog fa-fw"></i> Settings</a></li>
        @endif
    </ul>
</div>
