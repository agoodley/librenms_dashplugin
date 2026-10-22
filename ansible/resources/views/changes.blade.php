{{-- Changes (ChangeController::index): the plugin's main page, newest first, with filters and paging. --}}
@extends('layouts.librenmsv1')

@section('title', 'Ansible changes')

@push('styles')
    <link href="{{ route('ansible.asset', 'ansible.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
    $pages = max(1, (int) ceil($total / $perPage));
    $pageUrl = fn (int $p) => route('ansible.changes', array_filter($filters + ['page' => $p], fn ($v) => $v !== null && $v !== ''));
    $statuses = ['open' => 'Not ended'] + ChangeStatus::LABELS;
    $dryStates = ['verified' => ['success', 'verified'], 'unverified' => ['danger', 'NOT PROVEN'], 'failed' => ['danger', 'failed'], 'running' => ['info', 'running'],
        'launching' => ['info', 'launching'], 'void' => ['default', 'no longer counts'], 'not_launched' => ['warning', 'not launched']];
@endphp

@section('content')
<div class="container-fluid ans-page">
    @include('ansible::partials.nav', ['active' => 'changes'])

    <form class="ans-filterbar" method="get" action="{{ route('ansible.changes') }}" id="ans-changes-filter">
        <label>Device
            <select name="device" class="form-control" style="max-width: 220px">
                <option value="">All</option>
                @foreach($options['devices'] as $deviceId => $name)
                    <option value="{{ $deviceId }}" @selected($filters['device'] === $deviceId)>{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label>Requester
            <select name="user" class="form-control">
                <option value="">All</option>
                @foreach($options['users'] as $user)
                    <option value="{{ $user }}" @selected($filters['user'] === $user)>{{ $user }}</option>
                @endforeach
            </select>
        </label>
        <label>Template
            <select name="template" class="form-control" style="max-width: 240px">
                <option value="">All</option>
                @foreach($options['templates'] as $templateId => $name)
                    <option value="{{ $templateId }}" @selected($filters['template'] === (string) $templateId)>{{ $name }} (#{{ $templateId }})</option>
                @endforeach
            </select>
        </label>
        <label>Status
            <select name="status" class="form-control">
                <option value="">All</option>
                @foreach($statuses as $value => $label)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>From <input type="date" name="from" class="form-control" value="{{ $filters['from'] }}"></label>
        <label>To <input type="date" name="to" class="form-control" value="{{ $filters['to'] }}"></label>
        <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-filter"></i> Filter</button>
        <a class="btn btn-sm btn-default" href="{{ route('ansible.api.changes', request()->query()) }}" target="_blank"><i class="fa fa-code"></i> JSON</a>
        <span class="ans-spacer"></span>
        @if($canRun)
            <a class="btn btn-sm btn-success" href="{{ route('ansible.changes.new') }}" id="ans-new-change"><i class="fa fa-plus"></i> New change</a>
        @endif
    </form>

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    <div class="panel panel-default">
        <div class="panel-heading"><strong>Changes</strong> <span class="text-muted">({{ $total }}{{ $total > $perPage ? ', page ' . $page . ' of ' . $pages : '' }})</span>
            <span class="ans-muted" style="margin-left: 8px">each change is dry-run, approved when the settings ask for it, and applied in batches; its runs are in <a href="{{ route('ansible.runs') }}">Runs</a></span></div>
        <div class="table-responsive">
        <table class="table table-condensed table-hover ans-table ans-stack" id="ans-changes">
            <thead>
            <tr><th>#</th><th>Created</th><th>Requester</th><th>Template</th><th>Reference</th><th>Targets</th><th>Dry run</th><th>Status</th></tr>
            </thead>
            <tbody>
            @forelse($rows as $change)
                @php($state = $dryStates[$change['dry_run_state'] ?? ''] ?? null)
                <tr data-change="{{ $change['id'] }}">
                    <td><a href="{{ route('ansible.changes.show', $change['id']) }}">#{{ $change['id'] }}</a></td>
                    <td style="white-space: nowrap" data-label="Created">{{ \Agoodley\LibrenmsAnsible\Support\Clock::local($change['created_at']) }}</td>
                    <td data-label="Requester">{{ $change['username'] }}</td>
                    <td class="ans-wrap" data-label="Template">{{ $change['template_name'] }}</td>
                    <td class="ans-wrap ans-code" data-label="Reference">{{ $change['reference'] }}</td>
                    <td data-label="Targets"><span class="ans-wrap">{{ implode(', ', $change['target_names']) }}{{ (int) $change['target_count'] > count($change['target_names']) ? ' and ' . ((int) $change['target_count'] - count($change['target_names'])) . ' more' : '' }}</span>
                        <br><span class="ans-muted">{{ \Agoodley\LibrenmsAnsible\Support\Format::count((int) $change['target_count'], 'device') }} in {{ \Agoodley\LibrenmsAnsible\Support\Format::count((int) $change['batch_count'], 'batch', 'batches') }}</span></td>
                    <td data-label="Dry run">@if($state)<span class="label label-{{ $state[0] }}">{{ $state[1] }}</span>@else<span class="text-muted">-</span>@endif</td>
                    <td data-label="Status"><span class="label label-{{ ChangeStatus::css($change['status']) }}">{{ ChangeStatus::label($change['status']) }}</span>
                        @if(in_array($change['status'], ChangeStatus::RESERVING, true))
                            <span class="ans-muted">batch {{ $change['current_batch'] }} of {{ $change['batch_count'] }}{{ $change['waiting'] ? ' · ' . ['canary' => 'waits for an admin', 'secrets' => 'waits for the secret answers', 'timer' => 'pause'][$change['waiting']] ?? '' : '' }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                @if($total > 0)
                    <tr><td colspan="8" class="text-muted">No changes on page {{ $page }}: <a href="{{ $pageUrl(1) }}">back to the first page</a>.</td></tr>
                @else
                    <tr><td colspan="8" class="text-muted">No changes{{ array_filter($filters) ? ' match these filters' : ' yet' }}.</td></tr>
                @endif
            @endforelse
            </tbody>
        </table>
        </div>
    </div>

    @if($pages > 1)
        <nav aria-label="Pages of changes">
            <ul class="pagination pagination-sm" style="margin-top: 0">
                <li class="{{ $page <= 1 ? 'disabled' : '' }}"><a href="{{ $page <= 1 ? '#' : $pageUrl($page - 1) }}" aria-label="Newer">&laquo;</a></li>
                @foreach(array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], fn ($p) => $p >= 1 && $p <= $pages)) as $p)
                    <li class="{{ $p === $page ? 'active' : '' }}"><a href="{{ $pageUrl($p) }}">{{ $p }}</a></li>
                @endforeach
                <li class="{{ $page >= $pages ? 'disabled' : '' }}"><a href="{{ $page >= $pages ? '#' : $pageUrl($page + 1) }}" aria-label="Older">&raquo;</a></li>
            </ul>
        </nav>
    @endif
</div>
@endsection
