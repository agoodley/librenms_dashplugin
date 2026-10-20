<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\History\HistoryService;
use Agoodley\LibrenmsTopology\History\Window;
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

    /**
     * plugin/topology/changes: what changed in the window, the baseline, findings that came or went.
     */
    public function changes(Request $request): View
    {
        [$from, $to, $window] = self::window($request->query());
        $admin = self::isAdmin($request);

        return $this->page($request, 'changes', 'topology history', function (array $params, PluginSettings $settings) use ($request, $from, $to, $admin) {
            $service = $this->history($request, $settings);
            $result = self::forUser($service->changes($params, $from, $to), $admin);
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

        return $this->json('topology history', fn () => self::forUser($this->history($request, $settings)->changes($params, $from, $to), self::isAdmin($request)));
    }

    /**
     * POST plugin/topology/changes/baseline (admins): action=save saves the topology of the last
     * snapshot as the baseline of the page's scope, action=clear forgets it, action=accept takes
     * one missing fact (hash) out of it.
     */
    public function baseline(Request $request): RedirectResponse
    {
        abort_unless(self::isAdmin($request), 403);

        $settings = $this->settings();
        $params = MapParams::parse($request->only(['device', 'group', 'location']), $settings);
        $back = array_filter(['device' => $params['device'], 'group' => $params['group'], 'location' => $params['location'],
            'window' => $request->input('window'), 'from' => $request->input('from'), 'to' => $request->input('to')], fn ($value) => is_scalar($value) && $value !== '');
        $user = (string) ($request->user()->username ?? '');

        try {
            $service = $this->history($request, $settings);
            $hash = $request->input('hash');
            if ($request->input('action') === 'clear') {
                $removed = $service->clearBaseline($params);
                $message = "Baseline cleared: $removed facts removed.";
            } elseif ($request->input('action') === 'accept') {
                abort_unless(is_string($hash) && preg_match('/^[0-9a-f]{40}$/', $hash), 422);
                $vlans = is_string($request->input('vlans')) && preg_match('/^\d+(,\d+)*$/', $request->input('vlans'))
                    ? array_map('intval', explode(',', $request->input('vlans'))) : null;
                $message = $service->acceptMissing($params, $hash, $vlans) ? 'Removed from the baseline: it is no longer expected.' : 'Nothing to remove: the baseline has changed.';
                $message .= " ($hash)";
            } else {
                $saved = $service->saveBaseline($params, $user);
                $message = "Baseline saved: {$saved['saved']} facts" . ($saved['removed'] ? " (replacing {$saved['removed']})" : '') . '.';
            }
            Log::info("topology plugin: $user: $message " . json_encode($back));

            return redirect()->route('topology.changes', $back)->with('topology_status', $message);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            return redirect()->route('topology.changes', $back)->with('topology_error', $this->failure('baseline update', $e));
        }
    }

    private function history(Request $request, PluginSettings $settings): HistoryService
    {
        return new HistoryService(new HistoryRepository($request->user()), new TopologyRepository($request->user()), $settings);
    }

    /**
     * Who saved the baseline and the counts of the runs (they cover the whole network) are for
     * admins only.
     */
    private static function forUser(array $result, bool $admin): array
    {
        if (! $admin) {
            if (isset($result['baseline']['saved'])) {
                $result['baseline']['saved']['saved_by'] = null;
            }
            foreach (array_keys($result['runs'] ?? []) as $type) {
                unset($result['runs'][$type]['stats']);
            }
        }

        return $result;
    }

    /**
     * May the user change the baseline (LibreNMS admin)?
     */
    private static function isAdmin(Request $request): bool
    {
        return (bool) $request->user()?->can('admin');
    }

    /**
     * Time window of the page (see Window::resolve()).
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface, 2: string} from, to, window key ('custom' for dates)
     */
    public static function window(array $query): array
    {
        [$from, $to, $window] = Window::resolve($query, Carbon::now()->getTimestamp());

        return [Carbon::createFromTimestamp($from, date_default_timezone_get()), Carbon::createFromTimestamp($to, date_default_timezone_get()), $window];
    }
}
