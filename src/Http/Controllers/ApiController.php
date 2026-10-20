<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class ApiController extends Controller
{
    use BuildsTopology;

    public function l2(Request $request): JsonResponse
    {
        return $this->respond($request, 'l2');
    }

    public function l3(Request $request): JsonResponse
    {
        return $this->respond($request, 'l3');
    }

    public function stp(Request $request): JsonResponse
    {
        return $this->respond($request, 'stp');
    }

    private function respond(Request $request, string $map): JsonResponse
    {
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);

        try {
            return response()->json($this->service($request, $settings)->$map($params));
        } catch (\Throwable $e) {
            Log::error("topology plugin: building $map failed: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString());

            return response()->json(['error' => "Unable to build the $map view: " . $e->getMessage()], 500);
        }
    }
}
