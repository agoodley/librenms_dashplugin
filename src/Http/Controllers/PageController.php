<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class PageController extends Controller
{
    use BuildsTopology;

    public function l2(Request $request): View
    {
        return $this->map($request, 'l2');
    }

    public function l3(Request $request): View
    {
        return $this->map($request, 'l3');
    }

    public function stp(Request $request): View
    {
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);
        $repo = new TopologyRepository($request->user());

        $result = null;
        $error = null;
        try {
            $result = $this->service($request, $settings)->stp($params);
        } catch (\Throwable $e) {
            Log::error('topology plugin: STP analysis failed: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
            $error = $e->getMessage();
        }

        return view('topology::stp', [
            'params' => $params,
            'result' => $result,
            'error' => $error,
            'filters' => $repo->filterOptions(),
            'deviceName' => $params['device'] ? $repo->deviceName($params['device']) : null,
            'assetVersion' => self::assetVersion(),
        ]);
    }

    private function map(Request $request, string $mode): View
    {
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);
        $repo = new TopologyRepository($request->user());

        return view('topology::map', [
            'mode' => $mode,
            'params' => $params,
            'filters' => $repo->filterOptions(),
            'deviceName' => $params['device'] ? $repo->deviceName($params['device']) : null,
            'assetVersion' => self::assetVersion(),
        ]);
    }

    public static function assetVersion(): string
    {
        $files = glob(__DIR__ . '/../../../resources/assets/*') ?: [];

        return (string) array_reduce($files, fn ($carry, $file) => max($carry, (int) filemtime($file)), 0);
    }
}
