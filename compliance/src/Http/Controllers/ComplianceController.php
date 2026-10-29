<?php

namespace Agoodley\LibrenmsCompliance\Http\Controllers;

use Agoodley\LibrenmsCompliance\Data\LibrenmsNetwork;
use Agoodley\LibrenmsCompliance\Data\ResultRepository;
use Agoodley\LibrenmsCompliance\Data\SettingsRepository;
use Agoodley\LibrenmsCompliance\Rules\BuiltinRules;
use Agoodley\LibrenmsCompliance\Rules\Outcome;
use Agoodley\LibrenmsCompliance\Rules\Rule;
use Agoodley\LibrenmsCompliance\Services\Backup;
use Agoodley\LibrenmsCompliance\Services\CheckService;
use Agoodley\LibrenmsCompliance\Services\Overview;
use Agoodley\LibrenmsCompliance\Support\Access;
use Agoodley\LibrenmsCompliance\Support\Clock;
use Agoodley\LibrenmsCompliance\Support\Csv;
use Agoodley\LibrenmsCompliance\Support\PluginSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The plugin's pages: the overview (how compliant the devices are, each rule's outcomes, the findings, filtered, and
 * their export), the devices, a device's results (its findings with the lines of its backup, each rule's outcome, its
 * switch ports) and the rules. Each user sees the devices LibreNMS lets them see (Support\Access); checking a device
 * now is for admins.
 */
class ComplianceController extends Controller
{
    /** Findings or devices per page. */
    private const PER_PAGE = 50;

    /** Most findings an export holds. */
    private const EXPORT_MAX = 50000;

    /** The filters of the findings, and their longest value. */
    private const FILTERS = ['rule' => 64, 'severity' => 16, 'text' => 200, 'device' => 200];

    /**
     * GET plugin/compliance/overview.
     */
    public function index(Request $request): View
    {
        $data = ['error' => null, 'cards' => null, 'rules' => [], 'findings' => ['rows' => [], 'total' => 0], 'names' => [], 'filters' => $this->filters($request),
            'page' => 1, 'pages' => 1, 'lastRun' => null, 'settings' => null, 'titles' => [], 'assetVersion' => self::assetVersion()];
        try {
            $results = new ResultRepository();
            if (! $results->available()) {
                $data['error'] = 'The plugin\'s tables are missing: run ./lnms migrate in the LibreNMS directory.';

                return view('compliance::overview', $data);
            }
            $settings = (new SettingsRepository())->get();
            $data['settings'] = $settings;
            $scope = Access::deviceIds($request->user());
            $summaries = $results->summaries($scope);
            $data['cards'] = Overview::cards($summaries, $results->counts($scope));
            $data['rules'] = Overview::rules($summaries, $settings->rules());
            $data['titles'] = self::titles($settings);
            $data['lastRun'] = CheckService::forLibrenms($settings)->lastRun();
            $data['page'] = max(1, min(100000, (int) $request->query('page', 1)));
            $data['findings'] = $results->page($this->matching($scope, $data['filters']['device'], array_keys($summaries)), self::findingFilters($data['filters']),
                $data['page'], self::PER_PAGE);
            $data['pages'] = max(1, (int) ceil($data['findings']['total'] / self::PER_PAGE));
            $data['names'] = (new LibrenmsNetwork())->names(array_column($data['findings']['rows'], 'device_id'));
        } catch (\Throwable $e) {
            $data['error'] = $this->failure('overview', $e);
        }

        return view('compliance::overview', $data);
    }

    /**
     * GET plugin/compliance/devices: the devices and their state.
     */
    public function devices(Request $request): View
    {
        $state = (string) $request->query('state', '');
        $state = isset(Overview::STATES[$state]) ? $state : '';
        $text = mb_substr(trim((string) $request->query('device', '')), 0, 200);
        $data = ['error' => null, 'rows' => [], 'total' => 0, 'state' => $state, 'text' => $text, 'page' => 1, 'pages' => 1, 'assetVersion' => self::assetVersion()];
        try {
            $results = new ResultRepository();
            if (! $results->available()) {
                $data['error'] = 'The plugin\'s tables are missing: run ./lnms migrate in the LibreNMS directory.';

                return view('compliance::devices', $data);
            }
            $scope = Access::deviceIds($request->user());
            $summaries = $results->summaries($scope);
            $rows = Overview::devices($summaries, $results->counts($scope), (new LibrenmsNetwork())->names(array_keys($summaries)), $state, $text);
            $data['total'] = count($rows);
            $data['pages'] = max(1, (int) ceil(count($rows) / self::PER_PAGE));
            $data['page'] = max(1, min($data['pages'], (int) $request->query('page', 1)));
            $data['rows'] = array_slice($rows, ($data['page'] - 1) * self::PER_PAGE, self::PER_PAGE);
        } catch (\Throwable $e) {
            $data['error'] = $this->failure('device list', $e);
        }

        return view('compliance::devices', $data);
    }

    /**
     * GET plugin/compliance/devices/{id}: a device's results.
     */
    public function device(Request $request, int $id): View
    {
        $user = $request->user();
        if (! Access::canSeeDevice($user, $id)) {
            abort(403, 'You may not see this device.');
        }
        $network = new LibrenmsNetwork();
        $device = $network->device($id);
        if ($device === null) {
            abort(404, 'LibreNMS has no such device.');
        }
        $data = ['error' => null, 'id' => $id, 'device' => $device, 'row' => null, 'findings' => [], 'outcomes' => [], 'ports' => [], 'portIds' => [],
            'rules' => [], 'titles' => [], 'canCheck' => false, 'covered' => false, 'flash' => session('compliance_flash'), 'assetVersion' => self::assetVersion()];
        try {
            $results = new ResultRepository();
            if (! $results->available()) {
                $data['error'] = 'The plugin\'s tables are missing: run ./lnms migrate in the LibreNMS directory.';

                return view('compliance::device', $data);
            }
            $settings = (new SettingsRepository())->get();
            $service = CheckService::forLibrenms($settings);
            $data['covered'] = $service->covers($id);
            $data['canCheck'] = Access::isAdmin($user) && $data['covered'];
            $data['row'] = $results->device($id);
            $findings = $results->findings($id);
            uasort($findings, fn (array $a, array $b) => [Rule::rank((string) $a['severity']), $a['rule']] <=> [Rule::rank((string) $b['severity']), $b['rule']]);
            $data['findings'] = $findings;
            $rules = $settings->rules();
            $titles = self::titles($settings);
            // each rule of the last check, the failed first, then those that could not run, passed, did not apply
            $outcomes = [];
            foreach ((array) ($data['row']['outcomes'] ?? []) as $rule => $outcome) {
                $outcomes[] = ['rule' => (string) $rule, 'title' => $titles[$rule] ?? (string) $rule, 'severity' => isset($rules[$rule]) ? $rules[$rule]->severity : null,
                    'status' => (string) ($outcome[0] ?? ''), 'why' => (string) ($outcome[1] ?? ''), 'fix' => isset($rules[$rule]) ? $rules[$rule]->fix : ''];
            }
            $order = array_flip(Rule::STATUSES);
            usort($outcomes, fn (array $a, array $b) => [$order[$a['status']] ?? 9, Rule::rank((string) $a['severity'])] <=> [$order[$b['status']] ?? 9, Rule::rank((string) $b['severity'])]);
            $data['outcomes'] = $outcomes;
            $data['rules'] = $rules;
            $data['titles'] = $titles;
            $data['ports'] = (array) ($data['row']['ports'] ?? []);
            $data['portIds'] = array_fill_keys(array_keys($network->ports($id)), true);
        } catch (\Throwable $e) {
            $data['error'] = $this->failure('device page', $e);
        }

        return view('compliance::device', $data);
    }

    /**
     * POST plugin/compliance/devices/{id}/check: check the device now (admins).
     */
    public function check(int $id): RedirectResponse
    {
        try {
            $result = CheckService::forLibrenms()->checkDevice($id);
            $flash = match ($result['status']) {
                Backup::FOUND => ['success', 'Checked the backup from ' . $result['provider'] . ': ' . Outcome::count($result['findings'], 'finding') . ' (' . $result['new']
                    . ' new, ' . $result['resolved'] . ' resolved).'],
                Backup::NO_BACKUP => ['warning', 'No backup to check: ' . $result['why']],
                default => ['danger', 'The check failed: ' . $result['why']],
            };
        } catch (\InvalidArgumentException $e) {
            $flash = ['danger', $e->getMessage()];
        } catch (\Throwable $e) {
            $flash = ['danger', $this->failure('check', $e)];
        }

        return redirect()->route('compliance.device', $id)->with('compliance_flash', $flash);
    }

    /**
     * GET plugin/compliance/rules: what each rule checks and how to fix it.
     */
    public function rules(): View
    {
        $data = ['error' => null, 'settings' => null, 'builtin' => [], 'custom' => [], 'customErrors' => [], 'disabled' => [], 'assetVersion' => self::assetVersion()];
        try {
            $settings = (new SettingsRepository())->get();
            $data['settings'] = $settings;
            $data['builtin'] = BuiltinRules::rules();
            $data['disabled'] = array_fill_keys($settings->disabledRules(), true);
            $custom = $settings->customRules();
            $data['custom'] = $custom['rules'];
            $data['customErrors'] = $custom['errors'];
            $data['error'] = $settings->error;
        } catch (\Throwable $e) {
            $data['error'] = $this->failure('rules page', $e);
        }

        return view('compliance::rules', $data);
    }

    /**
     * GET plugin/compliance/findings/export/{format}: the findings the overview's filters select, as CSV (one per row,
     * without the evidence) or JSON (with the evidence: the lines of the backups, secrets hidden).
     */
    public function export(Request $request, string $format): Response
    {
        $results = new ResultRepository();
        if (! $results->available()) {
            abort(503, 'The plugin\'s tables are missing: run ./lnms migrate in the LibreNMS directory.');
        }
        $filters = $this->filters($request);
        $scope = Access::deviceIds($request->user());
        // the devices with results, for a filter by name when the user sees every device
        $known = $scope === null && $filters['device'] !== '' ? array_keys($results->summaries(null)) : [];
        $rows = $results->page($this->matching($scope, $filters['device'], $known), self::findingFilters($filters), 1, self::EXPORT_MAX)['rows'];
        $names = (new LibrenmsNetwork())->names(array_column($rows, 'device_id'));
        $titles = self::titles((new SettingsRepository())->get());
        $utc = fn (?string $stored) => $stored === null ? null : str_replace(' ', 'T', $stored) . 'Z';
        $name = 'compliance-findings-' . gmdate('Ymd-Hi');
        if ($format === 'json') {
            $findings = array_map(fn (array $row) => ['device_id' => $row['device_id'], 'device' => $names[$row['device_id']]['name'] ?? null,
                'hostname' => $names[$row['device_id']]['hostname'] ?? null, 'rule' => $row['rule'], 'title' => $titles[$row['rule']] ?? $row['rule'],
                'severity' => $row['severity'], 'message' => $row['message'], 'ports' => $row['ports'],
                'evidence' => array_map(fn ($line) => ['line' => (int) ($line[0] ?? 0), 'text' => (string) ($line[1] ?? '')], $row['evidence']),
                'first_seen' => $utc($row['first_seen']), 'last_seen' => $utc($row['last_seen'])], $rows);
            $body = json_encode(['generated' => gmdate('Y-m-d\TH:i:s\Z'), 'filters' => array_filter($filters, fn ($value) => $value !== ''), 'total' => count($findings),
                'findings' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            return response((string) $body, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => "attachment; filename=\"$name.json\"",
                'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
        }
        $csv = [['device', 'hostname', 'rule', 'title', 'severity', 'message', 'ports', 'first seen (UTC)', 'last seen (UTC)']];
        foreach ($rows as $row) {
            $csv[] = [$names[$row['device_id']]['name'] ?? '#' . $row['device_id'], $names[$row['device_id']]['hostname'] ?? '', $row['rule'], $titles[$row['rule']] ?? $row['rule'],
                $row['severity'], $row['message'], implode(' ', $row['ports']), $row['first_seen'], $row['last_seen']];
        }

        return response(Csv::of($csv), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => "attachment; filename=\"$name.csv\"",
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * The findings' filters of the request: a rule's id, a severity, a text of the message, a text of the device's name.
     *
     * @return array{rule: string, severity: string, text: string, device: string}
     */
    private function filters(Request $request): array
    {
        $filters = [];
        foreach (self::FILTERS as $key => $max) {
            $filters[$key] = mb_substr(trim((string) $request->query($key, '')), 0, $max);
        }
        if (! in_array($filters['severity'], Rule::SEVERITIES, true)) {
            $filters['severity'] = '';
        }
        if ($filters['rule'] !== '' && preg_match('~^[A-Za-z0-9_-]+$~', $filters['rule']) !== 1) {
            $filters['rule'] = '';
        }

        return $filters;
    }

    /**
     * The filters ResultRepository::page() takes (the device's name selects the devices instead).
     *
     * @return array{rule: string, severity: string, text: string}
     */
    private static function findingFilters(array $filters): array
    {
        return ['rule' => $filters['rule'], 'severity' => $filters['severity'], 'text' => $filters['text']];
    }

    /**
     * The devices of the scope (null: every device) whose name or hostname has the text; the scope itself without a
     * text.
     *
     * @param  int[]|null  $scope
     * @param  int[]  $known  the devices with results (when the scope is every device)
     * @return int[]|null
     */
    private function matching(?array $scope, string $text, array $known): ?array
    {
        if ($text === '') {
            return $scope;
        }
        $names = (new LibrenmsNetwork())->names($scope ?? $known);

        return array_keys(array_filter($names, fn (array $name) => mb_stripos($name['name'], $text) !== false || mb_stripos($name['hostname'], $text) !== false));
    }

    /**
     * Each rule's title by id: the rules of the settings, and the plugin's own (a finding of a rule switched off since
     * stays until the next check).
     *
     * @return array<string, string>
     */
    private static function titles(PluginSettings $settings): array
    {
        return array_map(fn (Rule $rule) => $rule->title, $settings->rules() + BuiltinRules::rules());
    }
}
