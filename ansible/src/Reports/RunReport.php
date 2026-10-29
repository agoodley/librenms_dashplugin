<?php

namespace Agoodley\LibrenmsAnsible\Reports;

use Agoodley\LibrenmsAnsible\Runs\RunResults;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Runs\RunText;
use Agoodley\LibrenmsAnsible\Services\DiagnosticService;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Agoodley\LibrenmsAnsible\Support\Format;

/**
 * The report of a run, as its page shows it: what ran (the template, its answers, who launched it and when), how it
 * ended per target, a diagnostic run's output per device (Runs\RunOutputs: the devices that answered the same together),
 * and its log. Its archive (files()) holds the report, the results as CSV, each device's output in a text file, what was
 * not attributed to a device, the whole log and the same data as JSON.
 */
final class RunReport
{
    /** Most lines of the log the report shows (its start and its end): the archive holds it whole. */
    public const LOG_LINES = 2000;

    /** What a target's result is called in a diagnostic run's output, as its page says it. */
    private const OUTPUT_RESULTS = ['ok' => 'ok', 'changed' => 'would change', 'skipped' => 'skipped', 'failed' => 'failed', 'unreachable' => 'unreachable'];

    private const OUTPUT_TONES = ['ok' => 'success', 'changed' => 'warning', 'failed' => 'danger', 'unreachable' => 'danger'];

    /** What a block of output is, said in its caption as the run page says it (an output is said nothing about). */
    private const BLOCKS = ['commands' => 'the commands it would send', 'diff' => 'what would change', 'yaml' => 'as Ansible printed it', 'text' => 'the module\'s text'];

    /**
     * @param  array  $run  RunRepository::find()
     * @param  array<int, array>  $targets  RunRepository::targets()
     * @param  array<string, mixed>|null  $outputs  a diagnostic run's DiagnosticService::outputs(); null for another run
     * @param  array{lines: string[], source: string}  $log  DiagnosticService::log()
     * @param  array{by: string, at: int, url: string}  $export
     */
    public function __construct(
        private readonly array $run,
        private readonly array $targets,
        private readonly ?array $outputs,
        private readonly array $log,
        private readonly array $export,
    ) {
    }

    /**
     * The name of its files, without extension.
     */
    public function name(): string
    {
        return 'ansible-run-' . (int) $this->run['id'];
    }

    public function report(): Report
    {
        $run = $this->run;
        $status = (string) $run['status'];
        $checkMode = RunResults::checkMode($run);
        $unproven = RunResults::unproven($run);
        $report = new Report(RunText::label((int) $run['id'], $run['context'] ?? null),
            implode(' · ', array_filter([self::kind($run), (string) $run['template_name'], RunStatus::label($status)])), ReportText::footer($this->export));

        $report->heading('Summary')->facts($this->summary());
        foreach ($this->alerts() as [$text, $tone]) {
            $report->paragraph($text, $tone);
        }

        $report->heading('Targets');
        $rows = [];
        foreach ($this->targets as $target) {
            $result = $target['result'] ?? null;
            $notProven = $unproven && $result !== null && empty($target['unexpected']);
            $rows[] = array_merge([
                ['text' => ReportText::device($target) . (! empty($target['unexpected']) ? ' (not a target)' : ''), 'tone' => ! empty($target['unexpected']) ? 'danger' : ''],
                (string) $target['inventory_name'],
                $result === null ? ['text' => RunStatus::isFinal($status) ? '-' : 'pending', 'tone' => 'muted']
                    : ['text' => $notProven ? RunResults::NOT_PROVEN : RunResults::label($result, $checkMode), 'tone' => $notProven ? 'danger' : ReportText::tone(RunResults::css($result))],
            ], array_map(fn (string $counter) => (string) ($target['counts'][$counter] ?? ''), ReportText::COUNTERS));
        }
        $report->table(array_merge(['Device', 'Inventory name', 'Result'], ReportText::COUNTERS), $rows);

        $report->heading('Survey answers');
        $answers = ReportText::answers((array) ($run['variables'] ?? []), '******** (secret: sent to the runner only, not stored)');
        $report->facts(array_merge($answers ?: [['Answers', 'The template has no survey variables.', 'muted']], [['Limit', str_replace(',', ', ', (string) $run['limit'])]]));

        if ($this->outputs !== null) {
            $this->outputsSection($report);
        }

        $report->heading('Log');
        $lines = ReportText::log($this->log['lines']);
        $report->paragraph(match ($this->log['source']) {
            DiagnosticService::RUNNER => 'The whole log, from the runner (task #' . $run['runner_task_id'] . '): the plugin keeps the start and the end of a log longer than the Output size setting.',
            DiagnosticService::CUT => 'The start and the end of the log, as the plugin keeps a log longer than the Output size setting: the runner '
                . ($run['runner_task_id'] !== null ? '(task #' . $run['runner_task_id'] . ') ' : '') . 'did not give the whole log.',
            default => 'The log as the runner printed it.',
        } . (count($lines) > self::LOG_LINES ? ' The report shows its first and its last ' . number_format(self::LOG_LINES / 2) . ' lines: the ZIP export holds all '
            . number_format(count($lines)) . '.' : ''), 'muted');
        if (count($lines) > self::LOG_LINES) {
            $half = intdiv(self::LOG_LINES, 2);
            $lines = array_merge(array_slice($lines, 0, $half), ['… ' . number_format(count($lines) - self::LOG_LINES) . ' lines left out here (the ZIP export holds them) …'],
                array_slice($lines, -$half));
        }
        $report->text($lines ?: ['(no log)'], 'log');

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
        $rows = [array_merge(['device_id', 'device', 'inventory_name', 'not_a_target', 'result'], ReportText::COUNTERS)];
        foreach ($this->targets as $deviceId => $target) {
            $rows[] = array_merge([(int) $deviceId, ReportText::device($target), (string) $target['inventory_name'], ! empty($target['unexpected']) ? 'yes' : '',
                (string) ($target['result'] ?? '')], array_map(fn (string $counter) => (string) ($target['counts'][$counter] ?? ''), ReportText::COUNTERS));
        }
        $files['results.csv'] = Archive::csv($rows);
        if ($this->outputs !== null && ! empty($this->outputs['final'])) {
            $devices = (array) $this->outputs['devices'];
            $texts = [];
            foreach ($this->outputs['tasks'] as $task) {
                foreach ($task['groups'] as $group) {
                    foreach ($group['devices'] as $deviceId) {
                        $texts[$deviceId][] = self::taskText($task['task'], $group['results']);
                    }
                }
            }
            foreach ($devices as $deviceId => $name) {
                $files[ReportText::deviceFile('devices', (int) $deviceId, (string) $name, 'txt')] = (string) $name . "\n" . str_repeat('=', max(3, mb_strlen((string) $name))) . "\n\n"
                    . (isset($texts[$deviceId]) ? implode("\n", $texts[$deviceId]) : "(no result attributed to this device: see log.txt)\n");
            }
            if ($this->outputs['unattributed']) {
                $files['not-attributed.txt'] = "Results of the log not attributed to a device, and why\n\n" . implode("\n", array_map(fn (array $entry) => 'Host ' . $entry['host']
                    . ' · not attributed: ' . $entry['reason'] . "\n" . self::taskText($entry['task'], [$entry]), $this->outputs['unattributed']));
            }
        }
        $files['log.txt'] = implode("\n", ReportText::log($this->log['lines'])) . "\n";
        $files['run.json'] = self::json($this->data());

        return $files;
    }

    /**
     * The run as data, for the archive: what the report shows.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $run = $this->run;
        $targets = [];
        foreach ($this->targets as $deviceId => $target) {
            $targets[] = ['device_id' => (int) $deviceId, 'device' => ReportText::device($target), 'inventory_name' => (string) $target['inventory_name'],
                'not_a_target' => ! empty($target['unexpected']), 'result' => $target['result'] ?? null, 'counts' => $target['counts'] ?? null];
        }
        $answers = [];
        foreach ((array) ($run['variables'] ?? []) as $variable) {
            if (is_array($variable)) {
                $answers[] = ['name' => $variable['name'] ?? null, 'label' => $variable['label'] ?? null, 'secret' => ! empty($variable['secret']),
                    'value' => empty($variable['secret']) ? ($variable['value'] ?? null) : null];
            }
        }

        return ['run' => ['id' => (int) $run['id'], 'kind' => $run['kind'] ?? null, 'context' => $run['context'] ?? null, 'change_id' => isset($run['change_id']) ? (int) $run['change_id'] : null,
            'batch' => isset($run['batch']) ? (int) $run['batch'] : null, 'status' => $run['status'], 'proof' => $run['proof'] ?? null, 'error' => $run['error'] ?? null,
            'template_id' => $run['template_id'], 'template' => $run['template_name'], 'playbook' => $run['playbook'], 'limit' => $run['limit'], 'user' => $run['username'],
            'runner_task_id' => $run['runner_task_id'], 'commit' => $run['commit_hash'] ?? null, 'created_at' => self::iso($run['created_at'] ?? null),
            'started_at' => self::iso($run['started_at'] ?? null), 'finished_at' => self::iso($run['finished_at'] ?? null), 'summary' => (object) ($run['summary'] ?? []),
            'unexpected_hosts' => $run['unexpected_hosts'] ?? []],
            'answers' => $answers, 'targets' => $targets,
            'outputs' => $this->outputs !== null && ! empty($this->outputs['final']) ? ['source' => $this->outputs['source'], 'devices' => $this->outputs['devices'],
                'tasks' => $this->outputs['tasks'], 'not_attributed' => $this->outputs['unattributed'], 'cut' => $this->outputs['cut']] : null,
            'log' => ['source' => $this->log['source'], 'lines' => count(ReportText::log($this->log['lines']))],
            'exported' => ['by' => $this->export['by'], 'at' => gmdate('Y-m-d\TH:i:s\Z', $this->export['at']), 'url' => $this->export['url']]];
    }

    /**
     * The facts of the run.
     *
     * @return array<int, array{0: string, 1: string, 2?: string}>
     */
    private function summary(): array
    {
        $run = $this->run;
        $status = (string) $run['status'];
        $unproven = RunResults::unproven($run);
        $facts = [['Kind', self::kind($run)],
            ['Status', RunStatus::label($status) . ($run['error'] ? ': ' . Clock::localize((string) $run['error']) : ''), ReportText::tone(RunStatus::css($status))],
            ['Results', ReportText::results((array) ($run['summary'] ?? []), RunResults::checkMode($run), $unproven), ReportText::resultsTone((array) ($run['summary'] ?? []), $unproven)]];
        if (RunResults::checkMode($run)) {
            $facts[] = ['Check mode', match ($run['proof'] ?? null) {
                'verified' => 'Proven: its log shows that every task ran in check mode, so it changed nothing.',
                'unverified' => 'NOT PROVEN: its log shows a task outside check mode (or no sign of --check). It may have changed its devices.',
                default => RunStatus::isFinal($status) ? 'Not judged: the run did not start, or its log is not whole.' : 'Judged when the run ends.',
            }, match ($run['proof'] ?? null) {
                'verified' => 'success',
                'unverified' => 'danger',
                default => 'muted',
            }];
        }
        $facts[] = ['Template', $run['template_name'] . "\n" . $run['playbook'] . ' · template #' . $run['template_id'] . ' of project ' . $run['runner_project']];
        $facts[] = ['Launched by', $run['username'] . ' at ' . ReportText::time($run['created_at'])];
        $facts[] = ['Started', ReportText::time($run['started_at'])];
        $facts[] = ['Ended', ReportText::time($run['finished_at'])];
        $facts[] = ['Duration', Format::duration(Format::runDuration($run))];
        if ($run['runner_task_id'] !== null) {
            $facts[] = ['Runner task', '#' . $run['runner_task_id']];
        }
        if (! empty($run['commit_hash'])) {
            $facts[] = ['Commit', substr((string) $run['commit_hash'], 0, 12)];
        }
        if ($run['stopped_by'] || $run['stop_reason']) {
            $facts[] = ['Stopped', trim(($run['stopped_by'] ? 'asked by ' . $run['stopped_by'] : '') . ($run['stop_reason'] ? ': ' . Clock::localize((string) $run['stop_reason']) : ''), ': ')];
        }
        if ($run['released_by']) {
            $facts[] = ['Devices released by', (string) $run['released_by']];
        }

        return $facts;
    }

    /**
     * What the run page says in its alerts: a run in check mode not proven to be, hosts the runner ran on that were no targets.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function alerts(): array
    {
        $run = $this->run;
        $alerts = [];
        if (RunResults::unproven($run)) {
            $alerts[] = [($run['kind'] ?? null) === 'diagnostic'
                ? 'This diagnostic run is NOT PROVEN to have run in check mode: its log shows a task outside check mode (or no sign of --check). It may have changed its '
                    . 'devices: check them, and the template\'s playbook (a task with check_mode: false runs for real).'
                : 'This dry run is NOT PROVEN to be one: its log shows a task outside check mode (or no sign of --check). It may have changed its devices: check them. '
                    . 'Its change can be neither approved nor applied.', 'danger'];
        }
        if ($run['unexpected_hosts'] ?? []) {
            $alerts[] = ['The runner ran this template on hosts that were not its targets: ' . implode(', ', (array) $run['unexpected_hosts']) . '. Check in the runner that the '
                . 'template uses the LibreNMS inventory and lets a launch set the limit, and what was done to these hosts.', 'danger'];
        }

        return $alerts;
    }

    /**
     * A diagnostic run's output per device: each task that printed something, its devices grouped by identical output; the
     * tasks that printed nothing in one list; the results not attributed to a device, with why.
     */
    private function outputsSection(Report $report): void
    {
        $outputs = $this->outputs;
        $report->heading('Output per device');
        if (empty($outputs['final'])) {
            $report->paragraph('The run has not ended: its output per device is read once it ended.', 'muted');

            return;
        }
        if ($outputs['source'] === DiagnosticService::CUT) {
            $report->paragraph('The log was cut to the Output size setting, and the runner no longer gives it whole: the output per device cannot be read from a log '
                . 'with a gap. The log below keeps its start and its end.', 'warning');

            return;
        }
        $names = (array) $outputs['devices'];
        $list = fn (array $ids) => implode(', ', array_map(fn ($id) => (string) ($names[$id] ?? "#$id"), $ids));
        $quiet = [];
        foreach ($outputs['tasks'] as $task) {
            $printed = false;
            foreach ($task['groups'] as $group) {
                foreach ($group['results'] as $result) {
                    $printed = $printed || $result['blocks'];
                }
            }
            $title = $task['task'] !== '' ? $task['task'] : 'PLAY';
            if (! $printed) {
                $counts = [];
                foreach ($task['groups'] as $group) {
                    $result = $group['results'][0]['result'] ?? 'ok';
                    $counts[$result] = ($counts[$result] ?? 0) + count($group['devices']);
                }
                $quiet[] = $title . ': ' . implode(', ', array_map(fn (string $result, int $count) => $count . ' ' . (self::OUTPUT_RESULTS[$result] ?? $result),
                    array_keys($counts), $counts));
                continue;
            }
            $devices = array_sum(array_map(fn (array $group) => count($group['devices']), $task['groups']));
            $report->heading('TASK [' . $title . ']', 3);
            $report->paragraph(Format::count($devices, 'device') . ($devices > 1 ? ' · ' . (count($task['groups']) === 1 ? 'all the same' : count($task['groups']) . ' different results') : ''),
                'muted');
            foreach ($task['groups'] as $group) {
                $who = $list($group['devices']) . (count($group['devices']) > 1 ? ' (' . count($group['devices']) . ' devices, the same)' : '');
                foreach ($group['results'] as $result) {
                    $said = (self::OUTPUT_RESULTS[$result['result']] ?? $result['result']) . ($result['item'] !== null ? ' · item ' . $result['item'] : '');
                    if (! $result['blocks']) {
                        $report->paragraph($who . ': ' . $said . ', no output', self::OUTPUT_TONES[$result['result']] ?? 'muted');
                    }
                    foreach ($result['blocks'] as $block) {
                        $report->text($block['lines'], $block['kind'], $who . ' · ' . $said . (isset(self::BLOCKS[$block['kind']]) ? ' · ' . self::BLOCKS[$block['kind']] : '')
                            . ($block['cut'] ? ' (cut)' : ''));
                    }
                }
            }
        }
        if (! $outputs['tasks']) {
            $report->paragraph('The log names no task result of a target.', 'muted');
        } elseif (count($quiet) === count($outputs['tasks'])) {
            $report->paragraph('No task printed output for a device. To read what the devices answer, register the result of the commands and print it with a debug task '
                . '(debug: var=result.stdout_lines), outside a loop.', 'muted');
        }
        if ($quiet) {
            $report->heading('Tasks without output', 3);
            $report->items($quiet);
        }
        if ($outputs['unattributed']) {
            $report->heading('Not attributed to a device', 3);
            $report->paragraph(Format::count(count($outputs['unattributed']), 'result') . ' of the log the plugin does not attribute to a device: device output may imitate '
                . 'Ansible\'s lines. The reason is given for each.', 'warning');
            foreach ($outputs['unattributed'] as $entry) {
                $said = 'named ' . $entry['host'] . ' · TASK [' . $entry['task'] . ']' . ($entry['item'] !== null ? ' · item ' . $entry['item'] : '') . ' · '
                    . (self::OUTPUT_RESULTS[$entry['result']] ?? $entry['result']);
                $report->paragraph($said . ': not attributed, ' . $entry['reason'] . '.', 'muted');
                foreach ($entry['blocks'] as $block) {
                    $report->text($block['lines'], $block['kind'], $said . ($block['cut'] ? ' (cut)' : ''));
                }
            }
        }
        if ($outputs['cut']) {
            $report->paragraph('The report keeps 4 MB of the output: the rest is in the log.', 'warning');
        }
    }

    /**
     * A task's results as text: its name, then each result with what it printed.
     *
     * @param  array<int, array{result: string, item: ?string, blocks: array}>  $results
     */
    private static function taskText(string $task, array $results): string
    {
        $text = 'TASK [' . ($task !== '' ? $task : 'PLAY') . "]\n";
        foreach ($results as $result) {
            $text .= '  ' . (self::OUTPUT_RESULTS[$result['result']] ?? $result['result']) . ($result['item'] !== null ? ' (item ' . $result['item'] . ')' : '') . "\n";
            foreach ($result['blocks'] as $block) {
                if (isset(self::BLOCKS[$block['kind']])) {
                    $text .= '  -- ' . self::BLOCKS[$block['kind']] . ($block['cut'] ? ' (cut)' : '') . "\n";
                }
                $text .= implode("\n", $block['lines']) . "\n";
            }
        }

        return $text;
    }

    /**
     * What kind of run it is, in words.
     */
    public static function kind(array $run): string
    {
        return match ($run['kind'] ?? null) {
            'diagnostic' => 'Diagnostic run (check mode: --check --diff)',
            'dry_run' => 'Dry run of change #' . (int) ($run['change_id'] ?? 0) . ' (check mode: --check --diff)',
            'batch' => 'Batch ' . (int) ($run['batch'] ?? 0) . ' of change #' . (int) ($run['change_id'] ?? 0),
            default => 'Run',
        };
    }

    /**
     * A stored time in ISO 8601 (UTC), null for none.
     */
    public static function iso(?string $stored): ?string
    {
        $time = Clock::unix($stored);

        return $time === null ? null : gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    /**
     * Data as indented JSON, invalid UTF-8 replaced.
     */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
    }
}
