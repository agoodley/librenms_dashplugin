{{-- Tabs of every page of the plugin; "New run" only for users holding the run permission. --}}
@php($navCanRun = \Agoodley\LibrenmsAnsible\Support\Access::canRun(auth()->user()))
<div class="ans-nav">
    <ul class="nav nav-tabs">
        <li class="{{ $active === 'runs' ? 'active' : '' }}"><a href="{{ route('ansible.runs') }}"><i class="fa fa-list fa-fw"></i> Runs</a></li>
        @if($navCanRun)
            <li class="{{ $active === 'new' ? 'active' : '' }}"><a href="{{ route('ansible.runs.new') }}"><i class="fa fa-play fa-fw"></i> New run</a></li>
        @endif
        @if($active === 'run')
            <li class="active"><a href="#"><i class="fa fa-terminal fa-fw"></i> Run #{{ $runId }}</a></li>
        @endif
        <li class="{{ $active === 'inventory' ? 'active' : '' }}"><a href="{{ route('ansible.inventory') }}"><i class="fa fa-sitemap fa-fw"></i> Inventory</a></li>
        <li><a href="{{ url('plugin/settings/ansible') }}"><i class="fa fa-cog fa-fw"></i> Settings</a></li>
    </ul>
</div>
