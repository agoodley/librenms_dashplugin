<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
use Agoodley\LibrenmsAnsible\Changes\ChangeSettings;
use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Reports\Archive;
use Agoodley\LibrenmsAnsible\Reports\ChangeReport;
use Agoodley\LibrenmsAnsible\Reports\DocxReport;
use Agoodley\LibrenmsAnsible\Reports\HtmlReport;
use Agoodley\LibrenmsAnsible\Reports\ReportText;
use Agoodley\LibrenmsAnsible\Reports\RunReport;

/**
 * The exports of a run and of a change: a report to read and print (HTML, which the browser saves as PDF), the same
 * report as a Word document, and a ZIP archive with the report and the raw data (CSV, each device's output or diff, the
 * logs, JSON). From what the plugin recorded: an export changes nothing and launches nothing; it asks the runner only for
 * the whole log of a run whose stored log was cut (DiagnosticService::log()).
 */
final class ReportService
{
    /** The formats: file extension => content type. */
    public const FORMATS = ['html' => 'text/html; charset=UTF-8', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'zip' => 'application/zip'];

    public function __construct(
        private readonly RunRepository $runs,
        private readonly ChangeRepository $changes,
        private readonly DiagnosticService $diagnostics,
        private readonly ChangeSettings $settings,
    ) {
    }

    /**
     * A run's export in a format; null when there is no such run.
     *
     * @param  array{by: string, at: int, url: string}  $export
     * @return array{name: string, type: string, body: string}|null
     */
    public function run(int $id, string $format, array $export): ?array
    {
        $run = $this->runs->find($id);
        if ($run === null) {
            return null;
        }
        // the whole log once: the runner is asked for it when the stored one was cut
        $log = $this->diagnostics->log($run);
        $outputs = ($run['kind'] ?? null) === 'diagnostic' ? $this->diagnostics->outputs($id, $log) : null;

        return self::render(new RunReport($run, $this->runs->targets($id), $outputs, $log, $export), $format, $export['at']);
    }

    /**
     * A change's export in a format; null when there is no such change.
     *
     * @param  array{by: string, at: int, url: string}  $export
     * @return array{name: string, type: string, body: string}|null
     */
    public function change(int $id, string $format, array $export): ?array
    {
        $data = $this->changeData($id, $format === 'zip');

        return $data === null ? null : self::render(new ChangeReport($data, $export), $format, $export['at']);
    }

    /**
     * A change and what its page shows of it (ChangeReport), with the logs of its runs for the archive ($logs).
     *
     * @return array<string, mixed>|null
     */
    public function changeData(int $id, bool $logs = false): ?array
    {
        $change = $this->changes->find($id);
        if ($change === null) {
            return null;
        }
        $targets = $this->changes->targets($id);
        $batches = $this->changes->batches($id);
        $verification = [];
        foreach ($batches as $number => $batch) {
            if (is_array($batch['verify'] ?? null)) {
                $verification[(int) $number] = $batch['verify'] + ['evaluation' => ($batch['verify']['result'] ?? null) !== null
                    ? ($this->changes->resultOf($id, (int) $number)['evaluation'] ?? null) : null];
            }
        }
        $diffs = [];
        $unattributed = [];
        foreach ($this->changes->diffs($id) as $diff) {
            if ($diff['device_id'] === null) {
                $unattributed[] = $diff;
            } else {
                $diffs[(int) $diff['device_id']][] = $diff;
            }
        }
        // what each batch takes down while it runs, as the topology plugin computed it with the change (part of what was approved)
        $topology = BatchPlan::topologyPart($change['plan'] ?? null);
        $blast = [];
        foreach (array_keys($batches) as $number) {
            $blast[$number] = $topology !== null ? ChangeService::blastForPage($topology, (int) $number, $targets) : null;
        }
        $names = ($topology?->names ?? []);
        foreach ($targets as $deviceId => $target) {
            $names[(int) $deviceId] = ReportText::device($target);
        }
        $runs = $this->runs->ofChange($id);
        $runLogs = [];
        if ($logs) {
            foreach (array_keys($runs) as $runId) {
                $run = $this->runs->find((int) $runId);
                if ($run !== null) {
                    $runLogs[(int) $runId] = $this->diagnostics->log($run);
                }
            }
        }

        return ['change' => $change, 'targets' => $targets, 'batches' => $batches, 'runs' => $runs, 'approvals' => $this->changes->approvals($id),
            'windows' => $this->changes->windows($id), 'events' => $this->changes->events($id), 'diffs' => $diffs, 'unattributed' => $unattributed,
            'verification' => $verification, 'blast' => $blast, 'names' => $names, 'settings' => $this->settings, 'logs' => $runLogs];
    }

    /**
     * @param  RunReport|ChangeReport  $report
     * @return array{name: string, type: string, body: string}
     */
    private static function render(object $report, string $format, int $now): array
    {
        $body = match ($format) {
            'docx' => DocxReport::render($report->report(), $now),
            'zip' => Archive::zip(self::folder($report->name(), $report->files())),
            default => HtmlReport::render($report->report()),
        };

        return ['name' => $report->name() . '.' . (isset(self::FORMATS[$format]) ? $format : 'html'), 'type' => self::FORMATS[$format] ?? self::FORMATS['html'], 'body' => $body];
    }

    /**
     * The archive's files in a folder of its name: unpacked, they stay together.
     *
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    private static function folder(string $name, array $files): array
    {
        $inFolder = [];
        foreach ($files as $path => $content) {
            $inFolder[$name . '/' . $path] = $content;
        }

        return $inFolder;
    }
}
