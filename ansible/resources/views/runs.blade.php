{{-- Runs (RunController::index): the audit log, newest first, with filters and paging. --}}
@extends('layouts.librenmsv1')

@section('title', 'Ansible runs')

@push('styles')
    <link href="{{ route('ansible.asset', 'ansible.css') }}?v={{ $assetVersion }}" rel="stylesheet">
@endpush

@php
    $pages = max(1, (int) ceil($total / $perPage));
    $pageUrl = fn (int $p) => route('ansible.runs', array_filter($filters + ['page' => $p], fn ($v) => $v !== null && $v !== ''));
    $statuses = ['unfinished' => 'Unfinished'] + \Agoodley\LibrenmsAnsible\Runs\RunStatus::LABELS;
@endphp

@section('content')
<div class="container-fluid ans-page">
    @include('ansible::partials.nav', ['active' => 'runs'])

    <form class="ans-filterbar" method="get" action="{{ route('ansible.runs') }}" id="ans-runs-filter">
        <label>Device
            <select name="device" class="form-control" style="max-width: 220px">
                <option value="">All</option>
                @foreach($options['devices'] as $deviceId => $name)
                    <option value="{{ $deviceId }}" @selected($filters['device'] === $deviceId)>{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label>User
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
        <a class="btn btn-sm btn-default" href="{{ route('ansible.api.runs', request()->query()) }}" target="_blank"><i class="fa fa-code"></i> JSON</a>
        <span class="ans-spacer"></span>
        @if($canRun)
            <a class="btn btn-sm btn-success" href="{{ route('ansible.changes.new') }}" id="ans-new-change"><i class="fa fa-plus"></i> New change</a>
        @endif
    </form>

    @if($error)
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
    @endif

    <div class="panel panel-default">
        <div class="panel-heading"><strong>Runs</strong> <span class="text-muted">({{ $total }}{{ $total > $perPage ? ', page ' . $page . ' of ' . $pages : '' }})</span></div>
        <div class="table-responsive">
        <table class="table table-condensed table-hover ans-table" id="ans-runs">
            <thead>
            <tr><th>#</th><th>Started</th><th>User</th><th>Change</th><th>Template</th><th>Targets</th><th>Status</th><th class="ans-num">Duration</th></tr>
            </thead>
            <tbody>
            @forelse($rows as $run)
                <tr data-run="{{ $run['id'] }}">
                    <td><a href="{{ route('ansible.runs.show', $run['id']) }}">#{{ $run['id'] }}</a></td>
                    <td style="white-space: nowrap">{{ \Agoodley\LibrenmsAnsible\Support\Clock::local($run['started_at'] ?? $run['created_at']) }}</td>
                    <td>{{ $run['username'] }}</td>
                    <td style="white-space: nowrap">@if(! empty($run['change_id']))<a href="{{ route('ansible.changes.show', $run['change_id']) }}">#{{ $run['change_id'] }}</a>
                        <br><span class="ans-muted">{{ $run['kind'] === 'dry_run' ? 'dry run' : 'batch ' . $run['batch'] }}</span>@else<span class="text-muted" title="a run of phase 1, before changes">-</span>@endif</td>
                    <td class="ans-wrap">{{ $run['template_name'] }}</td>
                    <td>
                        <span class="ans-wrap">{{ implode(', ', $run['target_names']) }}{{ $run['targets'] > count($run['target_names']) ? ' and ' . ($run['targets'] - count($run['target_names'])) . ' more' : '' }}</span>
                        @if($run['summary'])
                            <br>@include('ansible::partials.results', ['summary' => $run['summary']])
                        @endif
                        @if($run['unexpected_hosts'])
                            <br><span class="label label-danger" title="The runner ran on hosts that were not targets"><i class="fa fa-exclamation-triangle"></i> {{ \Agoodley\LibrenmsAnsible\Support\Format::count(count($run['unexpected_hosts']), 'other host') }}</span>
                        @endif
                    </td>
                    <td><span class="label label-{{ \Agoodley\LibrenmsAnsible\Runs\RunStatus::css($run['status']) }}">{{ \Agoodley\LibrenmsAnsible\Runs\RunStatus::label($run['status']) }}</span></td>
                    <td class="ans-num">{{ \Agoodley\LibrenmsAnsible\Support\Format::duration(\Agoodley\LibrenmsAnsible\Support\Format::runDuration($run)) }}</td>
                </tr>
            @empty
                @if($total > 0)
                    <tr><td colspan="8" class="text-muted">No runs on page {{ $page }}: <a href="{{ $pageUrl(1) }}">back to the first page</a>.</td></tr>
                @else
                    <tr><td colspan="8" class="text-muted">No runs{{ array_filter($filters) ? ' match these filters' : ' yet' }}.</td></tr>
                @endif
            @endforelse
            </tbody>
        </table>
        </div>
    </div>

    @if($pages > 1)
        <nav aria-label="Pages of runs">
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
