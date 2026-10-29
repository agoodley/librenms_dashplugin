<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
use Agoodley\LibrenmsAnsible\Changes\ChangeSettings;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\DeviceRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Reports\Archive;
use Agoodley\LibrenmsAnsible\Reports\DocxReport;
use Agoodley\LibrenmsAnsible\Reports\HtmlReport;
use Agoodley\LibrenmsAnsible\Reports\Report;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Services\DiagnosticService;
use Agoodley\LibrenmsAnsible\Services\Eventlog;
use Agoodley\LibrenmsAnsible\Services\InventoryService;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Services\ReportService;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeRunner;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\SqliteSql;
use PHPUnit\Framework\TestCase;

/**
 * The exports of runs and changes: the HTML report (everything escaped, nothing loaded), the Word document (a package
 * Word opens: well-formed parts, the built-in heading styles), the ZIP archive and its CSV files, and the reports of a
 * diagnostic run (the example playbook's log of the test server) and of a change, recorded in SQLite.
 */
class ReportsTest extends TestCase
{
    private const EXPORT = ['by' => 'alice', 'at' => 1793000000, 'url' => 'https://librenms.example/plugin/ansible/runs/1'];

    private SqliteSql $sql;

    private RunRepository $runs;

    private ChangeRepository $changes;

    private FakeRunner $runner;

    protected function setUp(): void
    {
        $this->sql = new SqliteSql();
        $this->runs = new RunRepository($this->sql);
        $this->changes = new ChangeRepository($this->sql);
        $this->runner = new FakeRunner();
    }

    private function service(): ReportService
    {
        $settings = new PluginSettings();

        return new ReportService($this->runs, $this->changes, new DiagnosticService(new Plugin(), $this->runs, new InventoryService(new DeviceRepository(), $settings), $settings,
            new Eventlog(), $this->changes, null, fn () => $this->runner), new ChangeSettings());
    }

    /** The files of a ZIP archive: path => content. */
    private static function unzip(string $zip): array
    {
        $path = tempnam(sys_get_temp_dir(), 'report-test-');
        file_put_contents($path, $zip);
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($path) === true, 'a ZIP archive');
        $files = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $files[(string) $archive->getNameIndex($i)] = (string) $archive->getFromIndex($i);
        }
        $archive->close();
        unlink($path);

        return $files;
    }

    /** Text device output may hold, aimed at the formats it goes into. */
    private const HOSTILE = '</pre><script>alert(1)</script><img src=x onerror=alert(2)> & "q" \'a\' ]]> =cmd|calc';

    private static function hostileReport(): Report
    {
        return (new Report('Run <b>#1</b>', 'sub & title', 'foot <i>'))
            ->heading('Summary <x>')->paragraph(self::HOSTILE, 'danger')->facts([['Label "x"', self::HOSTILE, 'warning']])
            ->table(['Device', 'ok'], [[['text' => self::HOSTILE, 'tone' => 'danger'], '3'], ['b', '12']])->items([self::HOSTILE])
            ->text(['--- before', '+++ after', '@@ -1 +1 @@', '-old ' . self::HOSTILE, '+new', ' same', "bell\x07 and escape\x1b[31m"], 'diff', 'caption ' . self::HOSTILE)
            ->text(['+ not a diff'], 'output');
    }

    public function testTheHtmlReportEscapesEveryTextAndLoadsNothing(): void
    {
        $html = HtmlReport::render(self::hostileReport());

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;/pre&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('<title>Run &lt;b&gt;#1&lt;/b&gt;</title>', $html);
        $this->assertStringContainsString('<meta http-equiv="Content-Security-Policy" content="default-src &apos;none&apos;; style-src &apos;unsafe-inline&apos;', $html,
            'a saved report runs nothing either');
        $this->assertDoesNotMatchRegularExpression('/\s(src|href)="(?!#s\d)/', $html, 'nothing to load, links only to its own sections');
        $this->assertStringContainsString('<p class="t-danger">', $html);
        $this->assertStringContainsString('<td class="r-num">12</td>', $html, 'a column of numbers is aligned to the right');
    }

    public function testADiffIsColouredLineByLine(): void
    {
        $html = HtmlReport::render(self::hostileReport());

        $this->assertStringContainsString("\n--- before\n+++ after\n<span class=\"d-hunk\">@@ -1 +1 @@</span>\n<span class=\"d-del\">-old &lt;/pre&gt;", $html,
            'the file headers are not coloured, the hunk and the changed lines are');
        $this->assertStringContainsString("<span class=\"d-add\">+new</span>\n same", $html);
        $this->assertStringContainsString("<pre>\n+ not a diff</pre>", $html, 'only a diff is coloured');
    }

    public function testTheWordDocumentIsAPackageWordOpens(): void
    {
        $parts = DocxReport::parts(self::hostileReport(), self::EXPORT['at']);

        foreach ($parts as $name => $xml) {
            $document = new \DOMDocument();
            $this->assertTrue(@$document->loadXML($xml), "$name is well-formed XML");
        }
        $this->assertSame('[Content_Types].xml', array_key_first($parts));
        foreach (['/word/document.xml', '/word/styles.xml', '/word/settings.xml', '/word/footer1.xml', '/docProps/core.xml'] as $part) {
            $this->assertStringContainsString('PartName="' . $part . '"', $parts['[Content_Types].xml']);
        }
        $document = $parts['word/document.xml'];
        $this->assertStringContainsString('<w:pStyle w:val="Title"/></w:pPr><w:r><w:t xml:space="preserve">Run &lt;b&gt;#1&lt;/b&gt;</w:t>', $document);
        $this->assertStringContainsString('<w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t xml:space="preserve">Summary &lt;x&gt;</w:t>', $document,
            'a section is a heading Word\'s navigation finds');
        $this->assertStringContainsString('&lt;/pre&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $document);
        $this->assertStringContainsString('<w:pStyle w:val="CodeDel"/></w:pPr><w:r><w:t xml:space="preserve">-old ', $document);
        $this->assertStringContainsString('<w:pStyle w:val="CodeHunk"/>', $document);
        $this->assertStringContainsString('<w:pStyle w:val="Code"/></w:pPr><w:r><w:t xml:space="preserve">--- before</w:t>', $document);
        $this->assertStringContainsString('bell and escape[31m', $document, 'characters XML cannot hold are dropped');
        $this->assertStringContainsString('<w:trPr><w:tblHeader/></w:trPr>', $document, 'the header row repeats on each page');
        $this->assertStringContainsString('<w:jc w:val="right"/></w:pPr><w:r><w:t xml:space="preserve">12</w:t>', $document);
        $this->assertStringContainsString('<w:pgSz w:w="11906" w:h="16838"/>', $document, 'A4');
        $this->assertStringContainsString('<w:instrText xml:space="preserve"> PAGE </w:instrText>', $parts['word/footer1.xml']);
        foreach (['Title', 'Heading1', 'Heading2', 'Code', 'CodeAdd', 'CodeDel', 'CodeHunk', 'Caption', 'TableText', 'ReportTable', 'NoteDanger', 'Footer'] as $style) {
            $this->assertStringContainsString('w:styleId="' . $style . '"', $parts['word/styles.xml']);
        }

        $files = self::unzip(DocxReport::render(self::hostileReport(), self::EXPORT['at']));
        $this->assertSame(array_keys($parts), array_keys($files), 'packed as it is');
    }

    public function testArchivesHoldSafeNamesAndCsvASpreadsheetOpensSafely(): void
    {
        $this->assertSame("'=cmd|' /C calc'!A0,'+SUM(1),'-2+3,'@x,12,-5,\"a,b\",\"x\ny\",\"say \"\"hi\"\"\"\r\n",
            Archive::csv([['=cmd|\' /C calc\'!A0', '+SUM(1)', '-2+3', '@x', '12', '-5', 'a,b', "x\ny", 'say "hi"']]), 'what would be a formula starts with an apostrophe');
        $this->assertSame('etc-passwd', Archive::name('../../etc/passwd'));
        $this->assertSame('unnamed', Archive::name('..'));
        $this->assertSame('core-sw1.lab', Archive::name('core sw1.lab'));
        $this->assertSame('abc', Archive::name('abc.-x', 4), 'never ends with a dot or a dash');
        $this->assertSame(['a.txt' => 'A', 'b/c.txt' => 'C'], self::unzip(Archive::zip(['a.txt' => 'A', 'b/c.txt' => 'C'])));
    }

    /** A finished diagnostic run of the example playbook on inst-sw1 and inst-sw2, its log the test server's. */
    private function diagnosticRun(array $run = []): int
    {
        $log = DryRunProofTest::log('diagnostic-show');
        $id = $this->runs->create($run + ['user_id' => 1, 'username' => 'alice', 'runner' => 'semaphore', 'runner_project' => 1, 'template_id' => '3',
            'template_name' => 'Show commands', 'playbook' => 'show_commands.yml', 'limit' => 'inst-sw1,inst-sw2', 'request_token' => bin2hex(random_bytes(16)),
            'variables' => [['name' => 'commands', 'label' => 'Show commands', 'type' => 'text', 'value' => "show version\nshow vlan", 'secret' => false, 'required' => true],
                ['name' => 'enable', 'label' => 'Enable', 'type' => 'secret', 'value' => '********', 'secret' => true, 'required' => false]],
            'status' => RunStatus::SUCCESS, 'kind' => 'diagnostic', 'context' => 'diagnostic run', 'proof' => 'verified', 'runner_task_id' => '40',
            'summary' => ['ok' => 2], 'output' => implode("\n", $log) . "\n", 'created_at' => '2026-10-29 09:00:00', 'started_at' => '2026-10-29 09:00:02',
            'finished_at' => '2026-10-29 09:00:31'],
            [1 => ['inventory_name' => 'inst-sw1', 'hostname' => 'inst-sw1', 'display' => 'inst-sw1'], 2 => ['inventory_name' => 'inst-sw2', 'hostname' => 'inst-sw2', 'display' => '']]);
        $this->runs->setResults($id, [1 => ['result' => 'ok', 'counts' => ['ok' => 4, 'changed' => 0, 'unreachable' => 0, 'failed' => 0, 'skipped' => 0]],
            2 => ['result' => 'ok', 'counts' => ['ok' => 4, 'changed' => 0, 'unreachable' => 0, 'failed' => 0, 'skipped' => 0]]]);

        return $id;
    }

    public function testTheReportOfADiagnosticRunHasTheOutputOfEachDevice(): void
    {
        $id = $this->diagnosticRun();
        $html = $this->service()->run($id, 'html', self::EXPORT);

        $this->assertSame(["ansible-run-$id.html", 'text/html; charset=UTF-8'], [$html['name'], $html['type']]);
        $body = $html['body'];
        $this->assertStringContainsString("<h1>Ansible run #$id (diagnostic run)</h1>", $body);
        $this->assertStringContainsString('Proven: its log shows that every task ran in check mode, so it changed nothing.', $body);
        $this->assertStringContainsString('<h3>TASK [What the device answered]</h3>', $body);
        $this->assertStringContainsString('inst-sw1 uptime is 1 year, 18 weeks, 5 days, 1 hour, 11 minutes', $body);
        $this->assertStringContainsString('<figcaption>inst-sw2 · ok</figcaption>', $body, 'a device without display name by its hostname');
        $this->assertStringContainsString('<li>Run the show commands: 2 ok</li>', $body, 'a task without output in one line');
        $this->assertStringContainsString('******** (secret: sent to the runner only, not stored)', $body);
        $this->assertStringContainsString('<td class="t-success">No change</td>', $body, 'a run in check mode says what would change');
        $this->assertStringContainsString('Exported by alice on 2026-', $body);
        $this->assertNull($this->service()->run($id + 1, 'html', self::EXPORT));

        $docx = $this->service()->run($id, 'docx', self::EXPORT);
        $this->assertSame("ansible-run-$id.docx", $docx['name']);
        $this->assertStringContainsString('inst-sw1 uptime is', self::unzip($docx['body'])['word/document.xml']);

        $files = self::unzip($this->service()->run($id, 'zip', self::EXPORT)['body']);
        $folder = "ansible-run-$id/";
        $this->assertSame(array_map(fn ($f) => $folder . $f, ['report.html', 'results.csv', 'devices/1-inst-sw1.txt', 'devices/2-inst-sw2.txt', 'log.txt', 'run.json']), array_keys($files));
        $this->assertStringStartsWith("device_id,device,inventory_name,not_a_target,result,ok,changed,unreachable,failed,skipped\r\n1,inst-sw1,inst-sw1,,ok,4,0,0,0,0\r\n",
            $files[$folder . 'results.csv']);
        $this->assertStringContainsString("TASK [What the device answered]\n  ok\nshow version\n", $files[$folder . 'devices/1-inst-sw1.txt']);
        $this->assertStringContainsString('inst-sw2 uptime is', $files[$folder . 'devices/2-inst-sw2.txt']);
        $this->assertStringNotContainsString('inst-sw2 uptime is', $files[$folder . 'devices/1-inst-sw1.txt']);
        $this->assertStringContainsString('PLAY RECAP', $files[$folder . 'log.txt']);
        $this->assertStringNotContainsString("\e[", $files[$folder . 'log.txt'], 'no ANSI code');
        $data = json_decode($files[$folder . 'run.json'], true);
        $this->assertSame(['diagnostic', 'verified', 2], [$data['run']['kind'], $data['run']['proof'], count($data['targets'])]);
        $this->assertSame([['name' => 'commands', 'label' => 'Show commands', 'secret' => false, 'value' => "show version\nshow vlan"],
            ['name' => 'enable', 'label' => 'Enable', 'secret' => true, 'value' => null]], $data['answers'], 'no secret, not even its mask');
        $this->assertSame('What the device answered', $data['outputs']['tasks'][3]['task']);
    }

    public function testTheLogOfARunCutToTheOutputSizeComesFromTheRunner(): void
    {
        $log = DryRunProofTest::log('diagnostic-show');
        $id = $this->diagnosticRun(['output' => $log[0] . "\n", 'output_tail' => end($log) . "\n", 'output_truncated' => 1, 'output_omitted' => count($log) - 2]);
        $this->runner->logs['40'] = $log;

        $html = $this->service()->run($id, 'html', self::EXPORT)['body'];
        $this->assertSame(['output 40 0'], $this->runner->calls, 'the runner asked once for the log the report shows and reads per device');
        $this->assertStringContainsString('The whole log, from the runner (task #40)', $html);
        $this->assertStringContainsString('inst-sw1 uptime is', $html, 'the output per device read from it');
        $files = self::unzip($this->service()->run($id, 'zip', self::EXPORT)['body']);
        $this->assertSame(count($log), count(explode("\n", rtrim($files["ansible-run-$id/log.txt"], "\n"))), 'the whole log in the archive');

        $this->runner->outputError = new \Agoodley\LibrenmsAnsible\Runner\RunnerError(\Agoodley\LibrenmsAnsible\Runner\RunnerError::NOT_FOUND, 'gone');
        $html = $this->service()->run($id, 'html', self::EXPORT)['body'];
        $this->assertStringContainsString('the output per device cannot be read from a log with a gap', $html);
        $this->assertStringContainsString('lines left out here (Output size setting)', $html, 'the start and the end the plugin keeps');
    }

    public function testALongLogIsCutInTheReportNotInTheArchive(): void
    {
        $lines = array_map(fn (int $i) => "line $i", range(1, 2500));
        $id = $this->diagnosticRun(['kind' => 'batch', 'change_id' => 4, 'batch' => 1, 'context' => 'change #4, batch 1 of 1', 'output' => implode("\n", $lines) . "\n",
            'proof' => null]);

        $html = $this->service()->run($id, 'html', self::EXPORT)['body'];
        $this->assertStringContainsString("line 1000\n… 500 lines left out here (the ZIP export holds them) …\nline 1501", $html);
        $this->assertStringNotContainsString('Output per device', $html, 'only a diagnostic run is read per device');
        $this->assertStringContainsString('Batch 1 of change #4', $html);
        $this->assertSame(2500, count(explode("\n", rtrim(self::unzip($this->service()->run($id, 'zip', self::EXPORT)['body'])["ansible-run-$id/log.txt"], "\n"))));
    }

    /** A change of three devices in two batches, dry-run, approved, its first batch applied. */
    private function change(): int
    {
        $plan = BatchPlan::make([501, 502, 503], [], 1, 5, 1, true, 0);
        $rows = [];
        foreach ([501 => 'lab-sw1', 502 => 'lab-sw2', 503 => 'lab-sw3'] as $deviceId => $name) {
            $rows[$deviceId] = ['inventory_name' => "$name.netops.lab", 'hostname' => "$name.netops.lab", 'display' => $name, 'batch' => (int) $plan->batchOf($deviceId)];
        }
        $id = $this->changes->create(['user_id' => 1, 'username' => 'alice', 'template_id' => '77', 'template_name' => 'Port description', 'playbook' => 'desc.yml',
            'reference' => 'CHG-1', 'status' => ChangeStatus::DRAFT, 'variables' => [['name' => 'interface', 'label' => 'Interface', 'value' => 'Fa0/1', 'secret' => false],
                ['name' => 'token', 'label' => 'Token', 'value' => null, 'secret' => true]],
            'has_secrets' => true, 'plan' => $plan->toArray(), 'target_count' => 3, 'batch_count' => $plan->count(), 'request_token' => bin2hex(random_bytes(16))], $rows, $plan->count());
        $dryRun = $this->runs->create(['user_id' => 1, 'username' => 'alice', 'runner' => 'semaphore', 'runner_project' => 1, 'template_id' => '77', 'template_name' => 'Port description',
            'playbook' => 'desc.yml', 'limit' => 'lab-sw1.netops.lab,lab-sw2.netops.lab,lab-sw3.netops.lab', 'request_token' => bin2hex(random_bytes(16)), 'variables' => [],
            'status' => RunStatus::SUCCESS, 'kind' => 'dry_run', 'change_id' => $id, 'proof' => 'verified', 'summary' => ['changed' => 1, 'ok' => 2],
            'output' => "PLAY [p] [CHECK MODE] ****\n\e[0;33mchanged: [lab-sw2.netops.lab]\e[0m\n", 'started_at' => '2026-10-29 09:00:00', 'finished_at' => '2026-10-29 09:01:00'],
            [501 => ['inventory_name' => 'lab-sw1.netops.lab', 'hostname' => 'lab-sw1.netops.lab', 'display' => 'lab-sw1']]);
        $this->changes->updateAt($id, 0, ['status' => ChangeStatus::APPLYING, 'dry_run_id' => $dryRun, 'dry_run_state' => 'verified', 'dry_run_at' => '2026-10-29 09:01:00',
            'dry_runs' => 1, 'approved_by' => 'bob', 'approved_at' => '2026-10-29 09:05:00', 'applied_by' => 'alice', 'apply_started_at' => '2026-10-29 09:10:00']);
        $this->changes->updateTargets($id, [501 => ['dry_result' => 'ok', 'dry_counts' => ['ok' => 2, 'changed' => 0], 'result' => 'ok', 'before_status' => 'fresh',
            'before_version' => 'abcdef0123456789', 'before_at' => '2026-10-29 09:10:30'], 502 => ['dry_result' => 'changed', 'dry_counts' => ['ok' => 1, 'changed' => 1]],
            503 => ['dry_result' => 'ok']]);
        $this->changes->replaceDiffs($id, $dryRun, [['device_id' => 502, 'task' => 'Set the description', 'item' => null, 'kind' => 'diff',
            'lines' => ['--- before', '+++ after', '@@ -1 +1 @@', '-description old', '+description <new>'], 'cut' => false, 'host' => null, 'reason' => null],
            ['device_id' => null, 'task' => 'Loop', 'item' => 'x', 'kind' => 'text', 'lines' => ['raw'], 'cut' => false, 'host' => 'lab-sw9', 'reason' => 'it names a host that is not a target']]);
        $this->changes->addApproval(['change_id' => $id, 'user_id' => 2, 'username' => 'bob', 'decision' => 'approved', 'comment' => '=looks fine', 'fingerprint' => 'f',
            'dry_run_id' => $dryRun, 'created_at' => 1793005500]);
        $this->changes->updateBatch($id, 1, ['status' => 'done', 'started_at' => '2026-10-29 09:10:00', 'ended_at' => '2026-10-29 09:12:00', 'username' => 'alice']);
        $this->changes->addWindow(['change_id' => $id, 'batch' => 1, 'schedule_id' => 41, 'status' => 'closed', 'opened_at' => 1793005800, 'ends_at' => 1793009400]);
        $this->changes->addEvent($id, 'created', 'Ansible change #' . $id . ' created by alice.', 'alice', 1793005000);
        $this->changes->addEvent($id, 'approval', 'Approved by bob at 2026-10-29 09:05 UTC.', 'bob', 1793005500);
        $this->changes->addEvent($id, 'batch', 'Batch 1 done.', null, 1793006000);

        return $id;
    }

    public function testTheReportOfAChange(): void
    {
        $id = $this->change();
        $html = $this->service()->change($id, 'html', self::EXPORT);

        $this->assertSame("ansible-change-$id.html", $html['name']);
        $body = $html['body'];
        $this->assertStringContainsString("<h1>Ansible change #$id (CHG-1)</h1>", $body);
        $this->assertStringContainsString('<p class="r-sub">Port description · Applying</p>', $body);
        $this->assertStringContainsString('secret: asked at each launch, never kept', $body);
        $this->assertStringContainsString('<h3>lab-sw2 (lab-sw2.netops.lab)</h3>', $body, 'what would change on the device that would change');
        $this->assertStringContainsString('<span class="d-add">+description &lt;new&gt;</span>', $body);
        $this->assertStringNotContainsString('<h3>lab-sw1 (lab-sw1.netops.lab)</h3>', $body, 'nothing for a device without change');
        $this->assertStringContainsString('named lab-sw9: it names a host that is not a target', $body);
        $this->assertStringContainsString('<td class="t-success">approved</td><td>bob</td>', $body);
        $this->assertStringContainsString('backed up · abcdef0123', $body);
        $this->assertStringContainsString('<td class="t-success">done</td>', $body);
        $this->assertStringContainsString('<td>#41</td>', $body);
        $this->assertLessThan(strpos($body, 'Batch 1 done.'), strpos($body, 'created by alice'), 'the timeline oldest first');
        $this->assertStringContainsString('<td class="t-muted">the plugin</td>', $body);
        $this->assertNull($this->service()->change($id + 1, 'html', self::EXPORT));

        $document = self::unzip($this->service()->change($id, 'docx', self::EXPORT)['body'])['word/document.xml'];
        $this->assertStringContainsString('+description &lt;new&gt;', $document);

        $files = self::unzip($this->service()->change($id, 'zip', self::EXPORT)['body']);
        $folder = "ansible-change-$id/";
        $this->assertSame(array_map(fn ($f) => $folder . $f, ['report.html', 'devices.csv', 'diffs/502-lab-sw2.diff', 'diffs/not-attributed.txt', 'timeline.csv',
            'runs/1-dry-run.log', 'change.json']), array_keys($files));
        $this->assertSame("# Set the description · diff\n--- before\n+++ after\n@@ -1 +1 @@\n-description old\n+description <new>\n", $files[$folder . 'diffs/502-lab-sw2.diff']);
        $this->assertStringContainsString("502,lab-sw2,lab-sw2.netops.lab,2,changed,1,1,,,,", $files[$folder . 'devices.csv']);
        $this->assertStringContainsString(',bob,approval,Approved by bob at ', $files[$folder . 'timeline.csv']);
        $this->assertSame("PLAY [p] [CHECK MODE] ****\nchanged: [lab-sw2.netops.lab]\n", $files[$folder . 'runs/1-dry-run.log'], 'as text, no ANSI code');
        $data = json_decode($files[$folder . 'change.json'], true);
        $this->assertSame(['CHG-1', 'applying', 3, 2], [$data['change']['reference'], $data['change']['status'], count($data['targets']), count($data['batches'])]);
        $this->assertSame([['name' => 'interface', 'label' => 'Interface', 'secret' => false, 'value' => 'Fa0/1'], ['name' => 'token', 'label' => 'Token', 'secret' => true,
            'value' => null]], $data['answers']);
        $this->assertSame('=looks fine', $data['approvals'][0]['comment'], 'JSON as it is: only CSV cells are guarded');
    }
}
