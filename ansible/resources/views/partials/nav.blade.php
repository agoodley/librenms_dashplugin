{{-- Tabs of every page of the plugin; "New change" and "Fixes" only for users holding the run permission. --}}
@php($navCanRun = \Agoodley\LibrenmsAnsible\Support\Access::canRun(auth()->user()))
<div class="ans-nav">
    <ul class="nav nav-tabs">
        <li class="{{ $active === 'changes' ? 'active' : '' }}"><a href="{{ route('ansible.changes') }}"><i class="fa fa-random fa-fw"></i> Changes</a></li>
        @if($navCanRun)
            <li class="{{ $active === 'new' ? 'active' : '' }}"><a href="{{ route('ansible.changes.new') }}" id="ans-nav-new-change"><i class="fa fa-plus fa-fw"></i> New change</a></li>
        @endif
        @if($active === 'change')
            <li class="active"><a href="#"><i class="fa fa-random fa-fw"></i> Change #{{ $changeId }}</a></li>
        @endif
        @if($navCanRun)
            <li class="{{ $active === 'fixes' ? 'active' : '' }}"><a href="{{ route('ansible.fixes') }}" id="ans-nav-fixes"><i class="fa fa-wrench fa-fw"></i> Fixes</a></li>
        @endif
        <li class="{{ $active === 'runs' ? 'active' : '' }}"><a href="{{ route('ansible.runs') }}"><i class="fa fa-list fa-fw"></i> Runs</a></li>
        @if($active === 'run')
            <li class="active"><a href="#"><i class="fa fa-terminal fa-fw"></i> Run #{{ $runId }}</a></li>
        @endif
        <li class="{{ $active === 'inventory' ? 'active' : '' }}"><a href="{{ route('ansible.inventory') }}"><i class="fa fa-sitemap fa-fw"></i> Inventory</a></li>
        <li><a href="{{ url('plugin/settings/ansible') }}"><i class="fa fa-cog fa-fw"></i> Settings</a></li>
    </ul>
</div>
