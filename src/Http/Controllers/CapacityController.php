<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Capacity\CapacityAnalyzer;
use Agoodley\LibrenmsTopology\Capacity\CapacityCsv;
use Agoodley\LibrenmsTopology\Capacity\CapacityService;
use Agoodley\LibrenmsTopology\Data\CapacityRepository;
use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\History\FindingBadges;
use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Support\Csv;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Switch port capacity: the page (plugin/topology/capacity), its JSON (api/capacity) and its CSV exports
 * (capacity/export: the ports of a list, the switches or the locations), with the same query parameters:
 * device, group, location, days (days without use that make a port unused; default: the setting) and list
 * (CapacityAnalyzer::LISTS: the ports the list and the export show).
 */
class CapacityController extends Controller
{
    use BuildsTopology;

    /**
     * plugin/topology/capacity: the cards, findings, locations, switches and a list of ports.
     */
    public function index(Request $request): View
    {
        [$days, $list] = self::options($request->query());

        return $this->page($request, 'capacity', 'port capacity analysis',
            fn (array $params, PluginSettings $settings) => $this->analyze($request, $settings, $params, $days),
            ['list' => $list, 'days' => $days, 'lowPercent' => $this->settings()->int('capacity_low_percent', 0, 100)]);
    }

    /**
     * api/capacity: the same data as JSON, every port unless a list is asked for; errors become {error} with HTTP 500.
     */
    public function api(Request $request): JsonResponse
    {
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);
        [$days, $list] = self::options($request->query());

        return $this->json('port capacity analysis', function () use ($request, $settings, $params, $days, $list): array {
            $result = $this->analyze($request, $settings, $params, $days);
            if ($request->query('list') !== null) {
                $result['ports'] = CapacityAnalyzer::filter($result['ports'], $list);
                $result['list'] = $list;
            }

            return $result;
        }, 'capacity');
    }

    /**
     * capacity/export?what=ports|switches|locations: CSV; a failure is a short text with HTTP 500.
     */
    public function export(Request $request): Response
    {
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);
        [$days, $list] = self::options($request->query());
        $what = in_array($request->query('what'), CapacityCsv::KINDS, true) ? (string) $request->query('what') : 'ports';
        try {
            $rows = CapacityCsv::rows($what, $this->analyze($request, $settings, $params, $days), $list);
        } catch (\Throwable $e) {
            return response($this->failure('port capacity export', $e), 500, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $name = 'port-capacity-' . ($what === 'ports' ? str_replace('_', '-', $list) . '-' : '') . $what . '-' . date('Ymd-Hi');

        return response(Csv::of($rows), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => "attachment; filename=\"$name.csv\"",
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * The days and list of a query: days 1-3650 (null: the setting), a list of CapacityAnalyzer::LISTS (default not_in_use).
     *
     * @return array{0: ?int, 1: string}
     */
    public static function options(array $query): array
    {
        $days = $query['days'] ?? null;
        $days = is_scalar($days) && ctype_digit(trim((string) $days)) ? max(1, min(3650, (int) $days)) : null;
        $list = $query['list'] ?? null;
        $list = is_string($list) && isset(CapacityAnalyzer::LISTS[$list]) ? $list : 'not_in_use';

        return [$days, $list];
    }

    /**
     * The analysis with the logged in user's permissions, its findings marked from the history of the background checks.
     */
    private function analyze(Request $request, PluginSettings $settings, array $params, ?int $days): array
    {
        $history = new HistoryRepository($request->user());

        return FindingBadges::annotate('capacity', (new CapacityService(new CapacityRepository($request->user()), $settings, $history))->analyze($params, $days),
            $history, 120 * $settings->int('history_interval', 5, 1440));
    }
}
