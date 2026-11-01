<?php

namespace Agoodley\LibrenmsInventory\Http\Controllers;

use Agoodley\LibrenmsInventory\Data\InventoryRepository;
use Agoodley\LibrenmsInventory\Support\Access;
use Agoodley\LibrenmsInventory\Support\ErrorText;
use Agoodley\LibrenmsInventory\Support\Filters;
use Agoodley\LibrenmsInventory\Support\Log;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller as BaseController;

/**
 * Shared by the plugin's controllers: a page with its filters, error messages and the asset version.
 */
abstract class Controller extends BaseController
{
    /**
     * A page of the plugin: the view with the filters, what $build returns (or the error it threw), the filters' choices
     * and what the user's role may not view.
     *
     * @param  callable(InventoryRepository): array  $build
     */
    protected function render(string $view, array $filters, callable $build): View
    {
        $repo = new InventoryRepository(request()->user());
        $data = [];
        $error = null;
        try {
            $data = $build($repo);
            $options = $repo->options();
        } catch (\Throwable $e) {
            $error = $this->failure("inventory $view page", $e);
            $options = ['groups' => [], 'locations' => [], 'os' => []];
        }
        $hidden = array_keys(array_filter(['the hardware inventory' => ! $repo->allowsModules(), 'optics' => ! $repo->allowsOptics()]));

        return view("inventory::$view", $data + [
            'filters' => $filters,
            'admin' => Access::isAdmin(request()->user()),
            'query' => Filters::query($filters),
            'options' => $options,
            'error' => $error,
            'hidden' => $hidden,
            'ok' => $error === null,
            'assetVersion' => self::assetVersion(),
        ]);
    }

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
