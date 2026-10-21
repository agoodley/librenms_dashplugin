<?php

use Agoodley\LibrenmsTopology\Http\Controllers\ApiController;
use Agoodley\LibrenmsTopology\Http\Controllers\AssetController;
use Agoodley\LibrenmsTopology\Http\Controllers\CoverageController;
use Agoodley\LibrenmsTopology\Http\Controllers\ExportController;
use Agoodley\LibrenmsTopology\Http\Controllers\HistoryController;
use Agoodley\LibrenmsTopology\Http\Controllers\HostController;
use Agoodley\LibrenmsTopology\Http\Controllers\PageController;
use Agoodley\LibrenmsTopology\Http\Controllers\PathController;
use Agoodley\LibrenmsTopology\Http\Controllers\PlanningController;
use Agoodley\LibrenmsTopology\Http\Controllers\RoutingController;
use Agoodley\LibrenmsTopology\Http\Controllers\SiteController;
use Agoodley\LibrenmsTopology\Http\Controllers\ViewController;
use Agoodley\LibrenmsTopology\Http\Middleware\RaiseMemoryLimit;
use Agoodley\LibrenmsTopology\TopologyServiceProvider;
use Illuminate\Support\Facades\Route;

// Every URI has at least two segments after "plugin/" so it can never collide with
// LibreNMS' own "plugin/{plugin}" page route. Every request may work on the whole network: enough memory for it.
Route::middleware(['web', 'auth', RaiseMemoryLimit::class])
    ->prefix(TopologyServiceProvider::URI_PREFIX)
    ->name('topology.')
    ->group(function (): void {
        Route::get('l2', [PageController::class, 'l2'])->name('l2');
        Route::get('l3', [PageController::class, 'l3'])->name('l3');
        Route::get('sites', [SiteController::class, 'show'])->name('sites');
        Route::get('stp', [PageController::class, 'stp'])->name('stp');
        Route::get('links', [PageController::class, 'links'])->name('links');
        Route::get('redundancy', [PageController::class, 'redundancy'])->name('redundancy');
        Route::get('routing', [RoutingController::class, 'index'])->name('routing');
        Route::get('changes', [HistoryController::class, 'changes'])->name('changes');
        Route::post('changes/baseline', [HistoryController::class, 'baseline'])->name('changes.baseline');
        Route::get('hosts', [HostController::class, 'index'])->name('hosts');

        Route::get('api/l2', [ApiController::class, 'l2'])->name('api.l2');
        Route::get('api/l3', [ApiController::class, 'l3'])->name('api.l3');
        Route::get('api/sites', [SiteController::class, 'api'])->name('api.sites');
        Route::get('api/stp', [ApiController::class, 'stp'])->name('api.stp');
        Route::get('api/links', [ApiController::class, 'links'])->name('api.links');
        Route::get('api/redundancy', [ApiController::class, 'redundancy'])->name('api.redundancy');
        Route::get('api/simulate', [ApiController::class, 'simulate'])->name('api.simulate');
        Route::get('api/routing', [RoutingController::class, 'api'])->name('api.routing');
        Route::get('api/changes', [HistoryController::class, 'api'])->name('api.changes');
        Route::get('api/hosts', [HostController::class, 'api'])->name('api.hosts');

        Route::get('path', [PathController::class, 'index'])->name('path');
        Route::get('api/path', [PathController::class, 'api'])->name('api.path');

        Route::get('coverage', [CoverageController::class, 'index'])->name('coverage');
        Route::get('api/coverage', [CoverageController::class, 'api'])->name('api.coverage');

        // rollout planner and device dependencies
        Route::get('rollout', [PlanningController::class, 'rollout'])->name('rollout');
        Route::get('api/rollout', [PlanningController::class, 'rolloutApi'])->name('api.rollout');
        Route::get('dependencies', [PlanningController::class, 'dependencies'])->name('dependencies');
        Route::post('dependencies', [PlanningController::class, 'applyDependencies'])->name('dependencies.apply');
        Route::get('api/dependencies', [PlanningController::class, 'dependenciesApi'])->name('api.dependencies');

        // sharing & export
        Route::get('views', [ViewController::class, 'index'])->name('views');
        Route::post('views', [ViewController::class, 'act'])->name('views.act');
        Route::get('api/views', [ViewController::class, 'list'])->name('api.views');
        Route::post('api/views', [ViewController::class, 'store'])->name('api.views.store');
        Route::post('api/views/{id}', [ViewController::class, 'save'])->where('id', '[0-9]+')->name('api.views.save');
        Route::post('api/views/{id}/delete', [ViewController::class, 'destroy'])->where('id', '[0-9]+')->name('api.views.delete');
        Route::post('api/custom-map/preview', [ExportController::class, 'preview'])->name('api.custom-map.preview');
        Route::post('api/custom-map', [ExportController::class, 'create'])->name('api.custom-map');

        Route::get('assets/{file}', AssetController::class)
            ->where('file', '[A-Za-z0-9_-]+\.(js|css)')
            ->name('asset');
    });
