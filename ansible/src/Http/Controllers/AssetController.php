<?php

namespace Agoodley\LibrenmsAnsible\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the plugin's JavaScript and CSS straight from the package (no asset publishing step needed).
 */
class AssetController extends Controller
{
    private const TYPES = [
        'js' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
    ];

    /**
     * Serve resources/assets/{file}. The route only accepts plain *.js / *.css names; the realpath check keeps
     * the file inside the assets directory anyway.
     */
    public function __invoke(string $file): BinaryFileResponse
    {
        $file = basename($file);
        $directory = realpath(__DIR__ . '/../../../resources/assets');
        $path = realpath(__DIR__ . '/../../../resources/assets/' . $file);
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        if ($path === false || $directory === false || ! isset(self::TYPES[$extension]) || ! str_starts_with($path, $directory . '/')) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => self::TYPES[$extension],
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
