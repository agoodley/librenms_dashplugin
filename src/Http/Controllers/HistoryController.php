<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\History\HistoryService;
use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Topology history: the Changes page, its JSON (api/changes) and the baseline (admins).
 */
class HistoryController extends Controller
{
    use BuildsTopology;

    /** Windows of the Changes page: key => [label, length in days]. */
    public const WINDOWS = ['1d' => ['last 24 hours', 1], '7d' => ['last 7 days', 7], '30d' => ['last 30 days', 30]];

    /**
     * plugin/topology/changes: what changed in the window, the baseline, findings that came or went.
     */
    public function changes(Request $request): View
    {
        [$from, $to, $window] = self::window($request->query());
        $admin = self::isAdmin($request);

        return $this->page($request, 'changes', 'topology history', function (array $params, PluginSettings $settings) use ($request, $from, $to, $admin) {
            $service = $this->history($request, $settings);
            $result = $service->changes($params, $from, $to);
            if ($admin && $request->query('baseline') === 'preview' && ($result['available'] ?? false)) {
                $result['preview'] = $service->baselinePreview($params);
            }

            return $result;
        }, ['window' => $window, 'from' => $from, 'to' => $to, 'admin' => $admin]);
    }

    /**
     * plugin/topology/api/changes: the same as JSON.
     */
    public function api(Request $request): JsonResponse
    {
        [$from, $to] = self::window($request->query());
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);

        return $this->json('topology history', fn () => $this->history($request, $settings)->changes($params, $from, $to));
    }

    /**
     * POST plugin/topology/changes/baseline (admins): action=save saves the topology of the last
     * snapshot as the baseline of the page's scope, action=clear forgets it.
     */
    public function baseline(Request $request): RedirectResponse
    {
        abort_unless(self::isAdmin($request), 403);

        $settings = $this->settings();
        $params = MapParams::parse($request->only(['device', 'group', 'location']), $settings);
        $back = array_filter(['device' => $params['device'], 'group' => $params['group'], 'location' => $params['location'], 'window' => $request->input('window')]);
        $user = (string) ($request->user()->username ?? '');

        try {
            $service = $this->history($request, $settings);
            if ($request->input('action') === 'clear') {
                $removed = $service->clearBaseline($params);
                $message = "Baseline cleared: $removed facts removed.";
            } else {
                $saved = $service->saveBaseline($params, $user);
                $message = "Baseline saved: {$saved['saved']} facts" . ($saved['removed'] ? " (replacing {$saved['removed']})" : '') . '.';
            }
            Log::info("topology plugin: $user: $message " . json_encode($back));

            return redirect()->route('topology.changes', $back)->with('topology_status', $message);
        } catch (\Throwable $e) {
            return redirect()->route('topology.changes', $back)->with('topology_error', $this->failure('baseline update', $e));
        }
    }

    private function history(Request $request, PluginSettings $settings): HistoryService
    {
        return new HistoryService(new HistoryRepository($request->user()), new TopologyRepository($request->user()), $settings);
    }

    /**
     * May the user change the baseline (LibreNMS admin)?
     */
    private static function isAdmin(Request $request): bool
    {
        return (bool) $request->user()?->can('admin');
    }

    /**
     * Time window of the page: `window` (1d, 7d, 30d) or `from` / `to` dates (custom, at most a year).
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface, 2: string} from, to, window key ('custom' for dates)
     */
    public static function window(array $query): array
    {
        $now = Carbon::now();
        if (! empty($query['from'])) {
            try {
                $from = Carbon::parse((string) $query['from']);
                $to = ! empty($query['to']) ? Carbon::parse((string) $query['to']) : $now->copy();
                if (strlen(trim((string) ($query['to'] ?? ''))) <= 10 && ! empty($query['to'])) {
                    $to = $to->endOfDay(); // a date includes that whole day
                }
                if ($from < $to) {
                    return [$from->max($to->copy()->subYear()), $to->min($now), 'custom'];
                }
            } catch (\Throwable) {
                // invalid dates: the default window
            }
        }

        $window = isset(self::WINDOWS[$query['window'] ?? '']) ? (string) $query['window'] : '1d';

        return [$now->copy()->subDays(self::WINDOWS[$window][1]), $now, $window];
    }
}
