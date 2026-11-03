<?php

namespace Agoodley\LibrenmsAnsible\Reports;

use Agoodley\LibrenmsAnsible\Changes\ApprovalRules;
use Agoodley\LibrenmsAnsible\Changes\BackupState;
use Agoodley\LibrenmsAnsible\Changes\BlastRadius;
use Agoodley\LibrenmsAnsible\Changes\ChangeSettings;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Changes\ChangeText;
use Agoodley\LibrenmsAnsible\Changes\VerificationText;
use Agoodley\LibrenmsAnsible\Runs\RunResults;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Agoodley\LibrenmsAnsible\Support\Format;

/**
 * The report of a change, as its page shows it: what was asked (the template and its answers, the devices in batches,
 * what each batch takes down while it runs), the dry run per device with what would change (the diffs of the latest dry
 * run), the approvals, how each batch ran and was verified, the backups and maintenance windows, the runs, and the
 * timeline. Its archive (files()) holds the report, the devices as CSV, each device's diff, the timeline as CSV, the
 * log of each run and the same data as JSON.
 */
final class ChangeReport
{
    /** A batch's state, as the change page says it. */
    public const BATCH_STATES = ['pending' => 'to do', 'preparing' => 'window and backups', 'prepared' => 'waits for the secret answers', 'launching' => 'launching',
        'running' => 'running', 'done' => 'done', 'failed' => 'failed', 'stopped' => 'stopped', 'cancelled' => 'not run'];

    private const BATCH_TONES = ['done' => 'success', 'failed' => 'danger', 'stopped' => 'warning', 'cancelled' => 'muted', 'pending' => 'muted'];

    private const DRY_TONES = ['changed' => 'warning', 'ok' => 'success', 'failed' => 'danger', 'unreachable' => 'danger', 'unknown' => 'warning', 'skipped' => 'muted',
        'not_run' => 'muted'];

    private const BACKUP_TONES = [BackupState::FRESH => 'success', BackupState::FAILED => 'danger', BackupState::UNAVAILABLE => 'danger', BackupState::NO_NODE => 'warning',
        BackupState::EXCLUDED => 'muted'];

    /** A batch's verification before it, after it, and what it found, as the change page says them. */
    private const BEFORE = ['refresh' => 'to be taken from data LibreNMS discovers again', 'take' => 'to be taken right before the launch', 'waiting' => 'waits for fresh data',
        'ready' => 'to be taken right before the launch', 'retake' => 'to be taken again (the batch waited)', 'taken' => 'taken', 'unknown' => 'could not be taken'];

    private const AFTER = ['settling' => 'settle time', 'waiting' => 'waits for fresh data', 'stale' => 'no fresh data', 'done' => 'compared', 'incomplete' => 'compared in part',
        'missing' => 'devices of its scope missing'];

    private const RESULTS = ['verified' => ['success', 'verified'], 'unexpected' => ['danger', 'unexpected changes'], 'unknown' => ['warning', 'cannot be verified']];

    /** Most entries of a verification's list the report names (the change page names as many). */
    private const LISTED = 100;

    /**
     * @param  array{change: array, targets: array<int, array>, batches: array<int, array>, runs: array<int, array>, approvals: array<int, array>,
     *               windows: array<int, array>, events: array<int, array>, diffs: array<int, array<int, array>>, unattributed: array<int, array>,
     *               verification: array<int, array>, blast: array<int, ?array>, names: array<int, string>, settings: ChangeSettings,
     *               logs: array<int, array{lines: string[], source: string}>}  $data  the change and what its page shows of it (ReportService::changeData()): the
     *               diffs of its latest dry run per device and those not attributed, each batch's verification and what it takes down while it runs, the names
     *               of the devices it names; the logs of its runs only for the archive
     * @param  array{by: string, at: int, url: string}  $export
     */
    public function __construct(private readonly array $data, private readonly array $export)
    {
    }

    public function name(): string
    {
        return 'ansible-change-' . (int) $this->data['change']['id'];
    }

    public function report(): Report
    {
        $change = $this->data['change'];
        $status = (string) $change['status'];
        $report = new Report(ChangeText::name($change), implode(' · ', [(string) $change['template_name'], ChangeStatus::label($status)]), ReportText::footer($this->export));

        $report->heading('Summary')->facts($this->summary());
        foreach ($this->alerts() as $alert) {
            $report->paragraph($alert[0], $alert[1]);
            if ($alert[2] ?? []) {
                $report->items($alert[2]);
            }
        }

        $report->heading('Answers');
        $report->facts(ReportText::answers((array) ($change['variables'] ?? []), 'secret: asked at each launch, never kept')
            ?: [['Answers', 'The template has no survey variables.', 'muted']]);

        $this->approvals($report);
        $this->batches($report);
        if ($this->data['verification']) {
            $this->verification($report);
        }
        $this->dryRun($report);
        $this->backupsAndWindows($report);
        $this->runs($report);

        $report->heading('Timeline');
        $report->paragraph('Oldest first; each target\'s eventlog in LibreNMS has these steps too.', 'muted');
        $report->table(['At', 'By', 'What'], array_map(fn (array $event) => [ReportText::time($event['at']), ['text' => (string) ($event['username'] ?? 'the plugin'),
            'tone' => $event['username'] === null ? 'muted' : ''], Clock::localize((string) $event['message'])], $this->data['events']));

        return $report;
    }

    /**
     * The archive's files, the report first: path => content.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        $files = ['report.html' => HtmlReport::render($this->report())];
        $rows = [array_merge(['device_id', 'device', 'inventory_name', 'batch', 'dry_run'], array_map(fn ($c) => "dry_$c", ReportText::COUNTERS), ['result'], ReportText::COUNTERS,
            ['backup_before', 'version_before', 'backup_after', 'version_after'])];
        foreach ($this->data['targets'] as $deviceId => $target) {
            $rows[] = array_merge([(int) $deviceId, ReportText::device($target), (string) $target['inventory_name'], (int) $target['batch'], (string) ($target['dry_result'] ?? '')],
                array_map(fn ($c) => (string) ($target['dry_counts'][$c] ?? ''), ReportText::COUNTERS), [(string) ($target['result'] ?? '')],
                array_map(fn ($c) => (string) ($target['counts'][$c] ?? ''), ReportText::COUNTERS),
                [(string) ($target['before_status'] ?? ''), (string) ($target['before_version'] ?? ''), (string) ($target['after_status'] ?? ''), (string) ($target['after_version'] ?? '')]);
        }
        $files['devices.csv'] = Archive::csv($rows);
        foreach ($this->data['diffs'] as $deviceId => $blocks) {
            $target = $this->data['targets'][$deviceId] ?? ['inventory_name' => "#$deviceId"];
            $files[ReportText::deviceFile('diffs', (int) $deviceId, ReportText::device($target), 'diff')] = implode("\n", array_map(fn (array $block) => self::blockText($block), $blocks));
        }
        if ($this->data['unattributed']) {
            $files['diffs/not-attributed.txt'] = "Diffs the plugin does not attribute to a device, and why\n\n" . implode("\n", array_map(fn (array $block) => 'named ' . $block['host']
                . ': ' . $block['reason'] . "\n" . self::blockText($block), $this->data['unattributed']));
        }
        $timeline = [['at_utc', 'at', 'user', 'kind', 'message']];
        foreach ($this->data['events'] as $event) {
            $timeline[] = [RunReport::iso($event['at']), ReportText::time($event['at']), (string) ($event['username'] ?? ''), (string) $event['kind'], Clock::localize((string) $event['message'])];
        }
        $files['timeline.csv'] = Archive::csv($timeline);
        foreach ($this->data['runs'] as $runId => $run) {
            if (isset($this->data['logs'][$runId])) {
                $files['runs/' . $runId . '-' . ($run['kind'] === 'batch' ? 'batch-' . (int) $run['batch'] : str_replace('_', '-', Archive::name((string) $run['kind']))) . '.log']
                    = implode("\n", ReportText::log($this->data['logs'][$runId]['lines'])) . "\n";
            }
        }
        $files['change.json'] = RunReport::json($this->data());

        return $files;
    }

    /**
     * The change as data, for the archive: what the report shows.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $change = $this->data['change'];
        $answers = [];
        foreach ((array) ($change['variables'] ?? []) as $variable) {
            if (is_array($variable)) {
                $answers[] = ['name' => $variable['name'] ?? null, 'label' => $variable['label'] ?? null, 'secret' => ! empty($variable['secret']),
                    'value' => empty($variable['secret']) ? ($variable['value'] ?? null) : null];
            }
        }
        $targets = [];
        foreach ($this->data['targets'] as $deviceId => $target) {
            $targets[] = ['device_id' => (int) $deviceId, 'device' => ReportText::device($target), 'inventory_name' => (string) $target['inventory_name'], 'batch' => (int) $target['batch'],
                'dry_run' => ['result' => $target['dry_result'] ?? null, 'counts' => $target['dry_counts'] ?? null],
                'result' => $target['result'] ?? null, 'counts' => $target['counts'] ?? null,
                'backups' => ['before' => self::backup($target, 'before'), 'after' => self::backup($target, 'after')],
                'diffs' => array_map(fn (array $block) => ['task' => $block['task'], 'item' => $block['item'], 'kind' => $block['kind'], 'lines' => $block['lines'], 'cut' => (bool) $block['cut']],
                    $this->data['diffs'][$deviceId] ?? [])];
        }
        $batches = [];
        foreach ($this->data['batches'] as $number => $batch) {
            $batches[] = ['number' => (int) $number, 'status' => $batch['status'], 'note' => $batch['note'] ?? null, 'run_id' => $batch['run_id'] !== null ? (int) $batch['run_id'] : null,
                'devices' => array_values(array_map('intval', array_keys(array_filter($this->data['targets'], fn ($t) => (int) $t['batch'] === (int) $number)))),
                'started_at' => RunReport::iso($batch['started_at'] ?? null), 'ended_at' => RunReport::iso($batch['ended_at'] ?? null), 'by' => $batch['username'] ?? null,
                'verification' => isset($this->data['verification'][$number]) ? ['result' => $this->data['verification'][$number]['result'] ?? null,
                    'evaluation' => $this->data['verification'][$number]['evaluation'] ?? null, 'accepted' => $this->data['verification'][$number]['accepted'] ?? null] : null];
        }

        return ['change' => ['id' => (int) $change['id'], 'reference' => $change['reference'], 'status' => $change['status'], 'error' => $change['error'] ?? null,
            'template_id' => $change['template_id'], 'template' => $change['template_name'], 'playbook' => $change['playbook'], 'user' => $change['username'],
            'created_at' => RunReport::iso($change['created_at'] ?? null), 'dry_run' => ['run_id' => $change['dry_run_id'] !== null ? (int) $change['dry_run_id'] : null,
                'state' => $change['dry_run_state'] ?? null, 'at' => RunReport::iso($change['dry_run_at'] ?? null), 'commit' => $change['dry_run_commit'] ?? null,
                'findings' => $change['dry_run_findings'] ?? null],
            'approved_by' => $change['approved_by'] ?? null, 'approved_at' => RunReport::iso($change['approved_at'] ?? null), 'applied_by' => $change['applied_by'] ?? null,
            'apply_started_at' => RunReport::iso($change['apply_started_at'] ?? null), 'finished_at' => RunReport::iso($change['finished_at'] ?? null),
            'verification' => $change['verification'] ?? null, 'verification_note' => $change['verification_note'] ?? null, 'plan' => $change['plan'] ?? null],
            'answers' => $answers, 'targets' => $targets, 'batches' => $batches,
            'approvals' => array_map(fn (array $a) => ['decision' => $a['decision'], 'by' => $a['username'], 'at' => RunReport::iso($a['created_at'] ?? null), 'comment' => $a['comment'],
                'voided_at' => RunReport::iso($a['voided_at'] ?? null), 'void_reason' => $a['void_reason'] ?? null], array_values($this->data['approvals'])),
            'windows' => array_map(fn (array $w) => ['schedule_id' => (int) $w['schedule_id'], 'batch' => (int) $w['batch'], 'status' => $w['status'],
                'opened_at' => RunReport::iso($w['opened_at'] ?? null), 'ends_at' => RunReport::iso($w['ends_at'] ?? null), 'closed_at' => RunReport::iso($w['closed_at'] ?? null),
                'error' => $w['error'] ?? null, 'cut_off' => $w['cut_off'] ?? null], array_values($this->data['windows'])),
            'runs' => array_map(fn (array $r) => ['id' => (int) $r['id'], 'kind' => $r['kind'], 'batch' => $r['batch'] !== null ? (int) $r['batch'] : null, 'status' => $r['status'],
                'proof' => $r['proof'] ?? null, 'summary' => (object) ($r['summary'] ?? []), 'by' => $r['username'], 'started_at' => RunReport::iso($r['started_at'] ?? null),
                'finished_at' => RunReport::iso($r['finished_at'] ?? null)], array_values($this->data['runs'])),
            'not_attributed' => array_map(fn (array $block) => ['host' => $block['host'], 'reason' => $block['reason'], 'task' => $block['task'], 'item' => $block['item'],
                'kind' => $block['kind'], 'lines' => $block['lines'], 'cut' => (bool) $block['cut']], $this->data['unattributed']),
            'timeline' => array_map(fn (array $e) => ['at' => RunReport::iso($e['at']), 'by' => $e['username'], 'kind' => $e['kind'], 'message' => $e['message']], $this->data['events']),
            'exported' => ['by' => $this->export['by'], 'at' => gmdate('Y-m-d\TH:i:s\Z', $this->export['at']), 'url' => $this->export['url']]];
    }

    /**
     * @return array<int, array{0: string, 1: string, 2?: string}>
     */
    private function summary(): array
    {
        $change = $this->data['change'];
        $settings = $this->data['settings'];
        $status = (string) $change['status'];
        $plan = (array) ($change['plan'] ?? []);
        $dry = ChangeText::DRY_STATES[$change['dry_run_state'] ?? ''] ?? null;
        $outcome = $change['verification'] ?? null;
        $facts = [
            ['Status', ChangeStatus::label($status), ReportText::tone(ChangeStatus::css($status))],
            ['Template', $change['template_name'] . "\n" . $change['playbook'] . ' · template #' . $change['template_id']],
            ['Requested by', $change['username'] . ' at ' . ReportText::time($change['created_at'])],
        ];
        if ((string) $change['reference'] !== '') {
            $facts[] = ['Reference', (string) $change['reference']];
        }
        $facts[] = ['Devices', Format::count(count($this->data['targets']), 'device') . ' in ' . Format::count(count($this->data['batches']), 'batch', 'batches') . "\n"
            . 'stops at ' . ($plan['stop_at'] ?? 1) . ' failed ' . (($plan['stop_at'] ?? 1) == 1 ? 'device' : 'devices') . ' in a batch'
            . (! empty($plan['wait_after_first']) && count($this->data['batches']) > 1 ? '; waits for an admin after the first batch' : '')
            . (! empty($plan['pause_minutes']) ? '; ' . $plan['pause_minutes'] . ' min between batches' : '')];
        $facts[] = ['Dry run', ($dry !== null ? ucfirst($dry[1]) : 'Not yet') . ($change['dry_run_id'] !== null ? ' · run #' . (int) $change['dry_run_id'] : '')
            . ($change['dry_run_at'] ? ' · ' . ReportText::time($change['dry_run_at']) : '') . ($change['dry_run_commit'] ? ' · commit ' . substr((string) $change['dry_run_commit'], 0, 12) : '')
            . ((int) ($change['dry_runs'] ?? 0) > 1 ? "\n" . (int) $change['dry_runs'] . ' dry runs: the diffs below are the latest one\'s' : ''), $dry !== null ? ReportText::tone($dry[0]) : 'muted'];
        $approved = $change['approved_by'] ? 'approved by ' . $change['approved_by'] . ' at ' . ReportText::time($change['approved_at']) : null;
        $mode = ApprovalRules::ofChange($change, $settings->approval);
        $facts[] = ['Approval', ($approved !== null ? ucfirst($approved) . "\n" : '') . 'the change needs ' . (ApprovalRules::LABELS[$mode] ?? $mode),
            $approved !== null ? 'success' : ''];
        if ($change['apply_started_at']) {
            $facts[] = ['Applied', 'started by ' . ($change['applied_by'] ?: '?') . ' at ' . ReportText::time($change['apply_started_at'])];
        }
        if ($change['finished_at']) {
            $facts[] = ['Ended', ReportText::time($change['finished_at'])];
        }
        if ($outcome !== null) {
            $note = VerificationText::note($change['verification_note'] ?? null, ! $this->data['verification'] && $change['apply_started_at'] !== null);
            $facts[] = ['Verification', ucfirst((string) VerificationText::label($outcome)) . ($note ? "\n" . $note : ''),
                ReportText::tone(VerificationText::css($outcome, $change['verification_note'] ?? null))];
        }

        return $facts;
    }

    /**
     * What the change page says in its alerts: a dry run not proven to be one or that failed (with what its log shows), how
     * the change ended.
     *
     * @return array<int, array{0: string, 1: string, 2?: string[]}>
     */
    private function alerts(): array
    {
        $change = $this->data['change'];
        $status = (string) $change['status'];
        $findings = array_map('strval', array_values((array) ($change['dry_run_findings'] ?? [])));
        $alerts = [];
        if (($change['dry_run_state'] ?? null) === 'unverified') {
            $alerts[] = ['This dry run is NOT PROVEN to be a dry run: it may have changed the devices. Check them before anything else. It can be neither approved nor applied.',
                'danger', $findings];
        } elseif (($change['dry_run_state'] ?? null) === 'failed') {
            $alerts[] = ['The dry run did not succeed on every target: it cannot be approved or applied.', 'danger', $findings];
        }
        if ($change['error'] && ! in_array($change['dry_run_state'] ?? null, ['unverified', 'failed'], true) && $status !== ChangeStatus::APPLIED) {
            $alerts[] = [ChangeText::outcome($status, Clock::localize((string) $change['error'])), in_array($status, [ChangeStatus::FAILED, ChangeStatus::REJECTED], true) ? 'danger' : 'warning'];
        }

        return $alerts;
    }

    private function approvals(Report $report): void
    {
        $settings = $this->data['settings'];
        $report->heading('Approval');
        $change = $this->data['change'];
        $mode = ApprovalRules::ofChange($change, $settings->approval);
        $report->paragraph('The change needs ' . (ApprovalRules::LABELS[$mode] ?? $mode)
            . (ApprovalRules::needed($mode) ? ', valid ' . intdiv($settings->approvalSeconds, 3600) . ' h' : '') . '.', 'muted');
        if (! $this->data['approvals']) {
            $report->paragraph(ApprovalRules::needed($mode) ? 'None yet.' : 'None needed: the change needs no approval.', 'muted');

            return;
        }
        $report->table(['Decision', 'By', 'At', 'Comment'], array_map(fn (array $approval) => [
            ['text' => (string) $approval['decision'] . ($approval['voided_at'] ? ' (no longer counts)' : ''),
                'tone' => $approval['voided_at'] ? 'muted' : ($approval['decision'] === 'approved' ? 'success' : 'danger')],
            (string) $approval['username'], ReportText::time($approval['created_at']),
            trim((string) $approval['comment'] . ($approval['voided_at'] ? "\nno longer counts: " . $approval['void_reason'] : '')),
        ], array_values($this->data['approvals'])));
    }

    private function batches(Report $report): void
    {
        $targets = $this->data['targets'];
        $runs = $this->data['runs'];
        $blast = $this->data['blast'];
        $topology = (bool) array_filter($blast);
        $report->heading('Batches');
        $rows = [];
        foreach ($this->data['batches'] as $number => $batch) {
            $devices = array_map(fn (array $target) => ReportText::device($target), array_filter($targets, fn (array $target) => (int) $target['batch'] === (int) $number));
            $run = $batch['run_id'] !== null ? ($runs[(int) $batch['run_id']] ?? null) : null;
            $row = [$number . ($number === 1 && count($this->data['batches']) > 1 ? ' (canary)' : ''), implode(', ', $devices)];
            if ($topology) {
                $row[] = self::blastText($blast[$number] ?? null);
            }
            $row[] = ['text' => (self::BATCH_STATES[$batch['status']] ?? (string) $batch['status']) . ($batch['note'] ? "\n" . Clock::localize((string) $batch['note']) : ''),
                'tone' => self::BATCH_TONES[$batch['status']] ?? ''];
            $row[] = $run !== null ? '#' . (int) $run['id'] . ' ' . RunStatus::label((string) $run['status']) : '';
            $row[] = $run !== null ? ReportText::results((array) ($run['summary'] ?? []), false, false) : '';
            $row[] = $batch['started_at'] ? ReportText::time($batch['started_at']) . ($batch['ended_at'] ? ' – ' . (Clock::local($batch['ended_at'], 'H:i:s') ?? '') : '')
                . ($batch['username'] ? ' · ' . $batch['username'] : '') : '';
            $rows[] = $row;
        }
        $report->table(array_merge(['Batch', 'Devices'], $topology ? ['Down while it runs'] : [], ['State', 'Run', 'Results', 'When']), $rows);
    }

    /**
     * What a batch takes down while it runs, as the topology plugin computed it with the change.
     */
    private static function blastText(?array $blast): string
    {
        if ($blast === null) {
            return '-';
        }
        if ($blast['hand']) {
            return 'planned by hand: nothing tells what depends on its devices';
        }
        $lost = array_map(fn (array $device) => (string) $device['name'], $blast['lost']);

        return ($lost ? implode(', ', $lost) : 'no other monitored device') . ($blast['unmanaged'] > 0 ? "\nand " . $blast['unmanaged'] . ' unmanaged '
            . ($blast['unmanaged'] === 1 ? 'neighbor' : 'neighbors') : '') . ($blast['reference'] !== 'core' ? "\nno core device of its part is left: counted from the largest piece left" : '');
    }

    private function verification(Report $report): void
    {
        $report->heading('Verification');
        $report->paragraph('The topology plugin\'s state of the network around each batch, before it and after it on fresh data, compared: links, port-channels, blocked ports, '
            . 'root bridges, OSPF and BGP, and its findings.', 'muted');
        $at = fn ($time) => is_int($time) && $time > 0 ? Clock::show($time, 'Y-m-d H:i:s T') : '';
        $rows = [];
        $lists = [];
        foreach ($this->data['verification'] as $number => $verify) {
            $before = (array) ($verify['before'] ?? []);
            $after = (array) ($verify['after'] ?? []);
            $result = $verify['result'] ?? null;
            $evaluation = (array) ($verify['evaluation'] ?? []);
            $accepted = is_array($verify['accepted'] ?? null) ? $verify['accepted'] : null;
            $rows[] = [(string) $number,
                trim((self::BEFORE[$before['status'] ?? ''] ?? '-') . (($before['status'] ?? null) === 'taken' ? ' ' . $at($before['taken_at'] ?? null) : '')
                    . (($before['status'] ?? null) === 'unknown' ? "\n" . ($before['error'] ?? '') : '')),
                trim((self::AFTER[$after['status'] ?? ''] ?? '-') . (in_array($after['status'] ?? null, ['done', 'incomplete'], true) ? ' ' . $at($after['taken_at'] ?? null) : '')
                    . (($after['note'] ?? null) ? "\n" . $after['note'] : '')),
                ['text' => trim(($result !== null ? self::RESULTS[$result][1] ?? $result : '-') . ($result === 'unknown' ? "\n" . ($verify['error'] ?? '') : '')
                    . ($accepted !== null ? "\naccepted by " . ($accepted['by'] ?? '?') . ' ' . ReportText::time($accepted['at'] ?? null)
                        . (($accepted['comment'] ?? '') !== '' ? ': "' . $accepted['comment'] . '"' : '') : '')),
                    'tone' => $result !== null ? (self::RESULTS[$result][0] ?? '') : 'muted']];
            foreach ([['unexpected', 'unexpected'], ['expected', 'what the fix was made for'], ['listed', 'other changes (no reason to stop)']] as [$list, $title]) {
                $entries = array_slice((array) ($evaluation[$list] ?? []), 0, self::LISTED);
                if ($entries) {
                    $lists[] = ["Batch $number, $title (" . count((array) $evaluation[$list]) . ')', array_map(fn ($entry) => (string) ($entry['text'] ?? ''), $entries)];
                }
            }
        }
        $report->table(['Batch', 'Before', 'After', 'Result'], $rows);
        foreach ($lists as [$title, $entries]) {
            $report->heading(ucfirst($title), 3);
            $report->items($entries);
        }
    }

    private function dryRun(Report $report): void
    {
        $change = $this->data['change'];
        $unproven = ($change['dry_run_state'] ?? null) === 'unverified';
        $report->heading('Dry run per device');
        $report->paragraph('Results from the PLAY RECAP of the latest dry run' . ($change['dry_run_id'] !== null ? ' (run #' . (int) $change['dry_run_id'] . ')' : '')
            . '; what would change from the diffs Ansible printed.', 'muted');
        $rows = [];
        foreach ($this->data['targets'] as $target) {
            $result = $target['dry_result'] ?? null;
            $rows[] = array_merge([ReportText::device($target), (string) $target['inventory_name'], (string) $target['batch'],
                $result === null ? ['text' => '-', 'tone' => 'muted'] : ($unproven ? ['text' => 'NOT PROVEN: may have changed this device', 'tone' => 'danger']
                    : ['text' => ChangeText::DRY_RESULTS[$result] ?? $result, 'tone' => self::DRY_TONES[$result] ?? ''])],
                array_map(fn (string $counter) => (string) ($target['dry_counts'][$counter] ?? ''), ReportText::COUNTERS));
        }
        $report->table(array_merge(['Device', 'Inventory name', 'Batch', 'Dry run'], ReportText::COUNTERS), $rows);

        $unattributedOf = array_count_values(array_map(fn (array $block) => (string) $block['host'], $this->data['unattributed']));
        $report->heading('What would change', 2);
        $any = false;
        foreach ($this->data['targets'] as $deviceId => $target) {
            $blocks = $this->data['diffs'][$deviceId] ?? [];
            if (! $blocks && ($target['dry_result'] ?? null) !== 'changed') {
                continue;
            }
            $any = true;
            $report->heading(ReportText::device($target) . ' (' . $target['inventory_name'] . ')', 3);
            if (! $blocks) {
                $count = $unattributedOf[(string) $target['inventory_name']] ?? 0;
                $report->paragraph($count > 0 ? 'Would change; what it printed is not attributed (' . Format::count($count, 'block') . ' naming it under "Diffs not attributed", with why).'
                    : 'Would change; the module printed no diff.', 'muted');
            }
            foreach ($blocks as $block) {
                $report->text((array) $block['lines'], (string) $block['kind'], self::blockCaption($block));
            }
        }
        if (! $any) {
            $report->paragraph($change['dry_run_id'] !== null ? 'The dry run would change nothing.' : 'No dry run yet.', 'muted');
        }
        if ($this->data['unattributed']) {
            $report->heading('Diffs not attributed', 3);
            $report->paragraph('Diffs the plugin does not attribute to a device: device output may imitate Ansible\'s lines. The reason is given for each.', 'warning');
            foreach ($this->data['unattributed'] as $block) {
                $report->text((array) $block['lines'], (string) $block['kind'], self::blockCaption($block) . ' · named ' . $block['host'] . ': ' . $block['reason']);
            }
        }
    }

    private function backupsAndWindows(Report $report): void
    {
        $settings = $this->data['settings'];
        $report->heading('Backups');
        $report->paragraph($settings->backups ? 'Oxidized, before and after each batch; ' . ($settings->refuseWithoutBackup ? 'a batch without a fresh backup is refused.'
            : 'a batch without a fresh backup goes with a warning.') : 'Switched off in the settings.', 'muted');
        $cell = function (array $target, string $phase): array {
            $state = $target["{$phase}_status"] ?? null;
            if (! $state) {
                return ['text' => '-', 'tone' => 'muted'];
            }

            return ['text' => (BackupState::LABELS[$state] ?? $state) . ($target["{$phase}_version"] ? ' · ' . substr((string) $target["{$phase}_version"], 0, 10) : '')
                . "\n" . ($target["{$phase}_at"] ? ReportText::time($target["{$phase}_at"]) : ($target["{$phase}_requested_at"] ? 'asked ' . ReportText::time($target["{$phase}_requested_at"]) : ''))
                . ($target["{$phase}_error"] ? ' · ' . $target["{$phase}_error"] : ''), 'tone' => self::BACKUP_TONES[$state] ?? ''];
        };
        $report->table(['Device', 'Before', 'After'], array_map(fn (array $target) => [ReportText::device($target), $cell($target, 'before'), $cell($target, 'after')],
            array_values($this->data['targets'])));

        $report->heading('Maintenance windows');
        if (! $this->data['windows']) {
            $report->paragraph($settings->maintenance ? 'None: the plugin opens one before each batch.' : 'None: maintenance windows are switched off in the settings.', 'muted');

            return;
        }
        $names = $this->data['names'];
        $report->table(['Window', 'Batch', 'State', 'From – to', 'Also on'], array_map(fn (array $window) => [
            '#' . (int) $window['schedule_id'], (string) $window['batch'],
            ['text' => $window['status'] === 'open' ? ($window['closing'] ? 'closes at its end' : 'open') : (['closed' => 'closed', 'gone' => 'removed in LibreNMS'][$window['status']] ?? $window['status']),
                'tone' => $window['status'] === 'closed' ? 'success' : ($window['status'] === 'open' ? '' : 'warning')],
            ReportText::time($window['opened_at']) . ' – ' . (Clock::local($window['status'] === 'closed' ? $window['closed_at'] : $window['ends_at'], 'Y-m-d H:i:s T') ?? '?')
                . ($window['error'] ? "\n" . $window['error'] : ''),
            ($window['cut_off'] ?? []) ? BlastRadius::listText(array_map('intval', (array) $window['cut_off']), $names) . ': cut off by the batch' : '',
        ], array_values($this->data['windows'])));
    }

    private function runs(Report $report): void
    {
        $report->heading('Runs');
        if (! $this->data['runs']) {
            $report->paragraph('No run yet.', 'muted');

            return;
        }
        $report->table(['Run', 'What', 'Status', 'Results', 'By', 'Started', 'Ended'], array_map(fn (array $run) => [
            '#' . (int) $run['id'], $run['kind'] === 'dry_run' ? 'dry run' : 'batch ' . (int) $run['batch'],
            ['text' => RunStatus::label((string) $run['status']), 'tone' => ReportText::tone(RunStatus::css((string) $run['status']))],
            ['text' => ReportText::results((array) ($run['summary'] ?? []), RunResults::checkMode($run), RunResults::unproven($run)),
                'tone' => ReportText::resultsTone((array) ($run['summary'] ?? []), RunResults::unproven($run))],
            (string) $run['username'], ReportText::time($run['started_at']), ReportText::time($run['finished_at']),
        ], array_values($this->data['runs'])));
        $report->paragraph('The ZIP export holds the log of each run.', 'muted');
    }

    private static function blockCaption(array $block): string
    {
        return ($block['task'] ?: 'task') . ($block['item'] !== null ? ' · item ' . $block['item'] : '') . ' · '
            . (['diff' => 'diff', 'commands' => 'the commands it would send', 'text' => 'the module\'s text'][$block['kind']] ?? $block['kind']) . ($block['cut'] ? ' (cut)' : '');
    }

    private static function blockText(array $block): string
    {
        return '# ' . self::blockCaption($block) . "\n" . implode("\n", (array) $block['lines']) . "\n";
    }

    /**
     * @return array{state: ?string, version: ?string, at: ?string, error: ?string}
     */
    private static function backup(array $target, string $phase): array
    {
        return ['state' => $target["{$phase}_status"] ?? null, 'version' => $target["{$phase}_version"] ?? null, 'at' => RunReport::iso($target["{$phase}_at"] ?? null),
            'error' => $target["{$phase}_error"] ?? null];
    }
}
