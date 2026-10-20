<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the plugin's JavaScript/CSS straight from the package (no asset publishing step needed).
 */
class AssetController extends Controller
{
    private const TYPES = [
        'js' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
    ];

    public function __invoke(string $file): BinaryFileResponse
    {
        $file = basename($file);
        $path = realpath(__DIR__ . '/../../../resources/assets/' . $file);
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        if ($path === false || ! isset(self::TYPES[$extension]) || ! str_starts_with($path, realpath(__DIR__ . '/../../../resources/assets'))) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => self::TYPES[$extension],
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
