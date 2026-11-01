<?php

namespace Agoodley\LibrenmsInventory\Http\Controllers;

use Agoodley\LibrenmsInventory\Support\ErrorText;
use Agoodley\LibrenmsInventory\Support\Log;
use Illuminate\Routing\Controller as BaseController;

/**
 * Shared by the plugin's controllers: error messages and the asset version.
 */
abstract class Controller extends BaseController
{
    /**
     * Log a failure and say what to do. Exception texts can hold SQL and paths, so they are only shown with
     * APP_DEBUG.
     */
    protected function failure(string $what, \Throwable $e): string
    {
        Log::error("$what failed: " . ErrorText::forLog($e));
        $message = "The $what failed: see logs/librenms.log for details.";

        return config('app.debug') ? $message . ' (' . ErrorText::forLog($e) . ')' : $message;
    }

    /**
     * Cache busting version of the CSS (its modification time).
     */
    public static function assetVersion(): string
    {
        $files = glob(__DIR__ . '/../../../resources/assets/*') ?: [];

        return (string) array_reduce($files, fn ($carry, $file) => max($carry, (int) filemtime($file)), 0);
    }
}
