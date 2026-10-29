<?php

namespace Agoodley\LibrenmsAnsible\Http\Controllers;

use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Reports\HtmlReport;
use Agoodley\LibrenmsAnsible\Services\DiagnosticService;
use Agoodley\LibrenmsAnsible\Services\Eventlog;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Services\ReportService;
use Agoodley\LibrenmsAnsible\Support\MemoryLimit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The exports of a run and of a change (Services\ReportService), for the admins who may see their pages: the report
 * shown in the browser (to read, print, or save as PDF), the Word document and the ZIP archive downloaded. The report
 * holds device output, so its page loads and runs nothing (HtmlReport::CSP), and no other page may frame it.
 */
class ReportController extends Controller
{
    /** Memory for an export (a change's archive holds the logs of all its runs), unless PHP or LibreNMS allow more. */
    private const MEMORY_MB = 512;

    /**
     * plugin/ansible/runs/{id}/export/{format}: a run's report (html), Word document (docx) or archive (zip).
     */
    public function run(Request $request, int $id, string $format): Response
    {
        return $this->export($request, $format, fn (ReportService $service, array $export) => $service->run($id, $format, $export), "There is no Ansible run #$id.",
            route('ansible.runs.show', $id));
    }

    /**
     * plugin/ansible/changes/{id}/export/{format}: a change's report (html), Word document (docx) or archive (zip).
     */
    public function change(Request $request, int $id, string $format): Response
    {
        return $this->export($request, $format, fn (ReportService $service, array $export) => $service->change($id, $format, $export), "There is no Ansible change #$id.",
            route('ansible.changes.show', $id));
    }

    /**
     * @param  \Closure(ReportService, array): ?array  $make
     */
    private function export(Request $request, string $format, \Closure $make, string $missing, string $page): Response
    {
        if (! (new RunRepository())->available() || ! (new ChangeRepository())->available()) {
            abort(503, 'The Ansible plugin\'s tables are missing or out of date: run ./lnms migrate.');
        }
        if ($format !== 'html' && ! class_exists(\ZipArchive::class)) {
            abort(503, 'The Word and ZIP exports need PHP\'s zip extension (php-zip, part of LibreNMS\' install instructions): install it and restart PHP-FPM.');
        }
        MemoryLimit::raise(self::MEMORY_MB);
        $runs = new RunRepository();
        $diagnostics = new DiagnosticService(new Plugin(), $runs, $this->inventoryService(), $this->settings(), new Eventlog(), new ChangeRepository());
        try {
            $file = $make(new ReportService($runs, new ChangeRepository(), $diagnostics, $this->settings()->changeSettings()),
                ['by' => (string) $request->user()->username, 'at' => time(), 'url' => $page]);
        } catch (\Throwable $e) {
            abort(500, $this->failure('export', $e));
        }
        if ($file === null) {
            abort(404, $missing);
        }
        $headers = ['Content-Type' => $file['type'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer',
            'Content-Disposition' => ($format === 'html' ? 'inline' : 'attachment') . '; filename="' . $file['name'] . '"'];
        if ($format === 'html') {
            $headers['Content-Security-Policy'] = HtmlReport::CSP . "; frame-ancestors 'none'";
        }

        return new Response($file['body'], 200, $headers);
    }
}
