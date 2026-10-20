<?php

use Agoodley\LibrenmsTopology\Http\Controllers\ApiController;
use Agoodley\LibrenmsTopology\Http\Controllers\AssetController;
use Agoodley\LibrenmsTopology\Http\Controllers\CoverageController;
use Agoodley\LibrenmsTopology\Http\Controllers\PageController;
use Agoodley\LibrenmsTopology\Http\Controllers\PlanningController;
use Agoodley\LibrenmsTopology\Http\Controllers\RoutingController;
use Agoodley\LibrenmsTopology\Http\Controllers\SiteController;
use Agoodley\LibrenmsTopology\TopologyServiceProvider;
use Illuminate\Support\Facades\Route;

// Every URI has at least two segments after "plugin/" so it can never collide with
// LibreNMS' own "plugin/{plugin}" page route.
Route::middleware(['web', 'auth'])
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

        Route::get('api/l2', [ApiController::class, 'l2'])->name('api.l2');
        Route::get('api/l3', [ApiController::class, 'l3'])->name('api.l3');
        Route::get('api/sites', [SiteController::class, 'api'])->name('api.sites');
        Route::get('api/stp', [ApiController::class, 'stp'])->name('api.stp');
        Route::get('api/links', [ApiController::class, 'links'])->name('api.links');
        Route::get('api/redundancy', [ApiController::class, 'redundancy'])->name('api.redundancy');
        Route::get('api/simulate', [ApiController::class, 'simulate'])->name('api.simulate');
        Route::get('api/routing', [RoutingController::class, 'api'])->name('api.routing');

        Route::get('coverage', [CoverageController::class, 'index'])->name('coverage');
        Route::get('api/coverage', [CoverageController::class, 'api'])->name('api.coverage');

        // rollout planner and device dependencies
        Route::get('rollout', [PlanningController::class, 'rollout'])->name('rollout');
        Route::get('api/rollout', [PlanningController::class, 'rolloutApi'])->name('api.rollout');
        Route::get('dependencies', [PlanningController::class, 'dependencies'])->name('dependencies');
        Route::post('dependencies', [PlanningController::class, 'applyDependencies'])->name('dependencies.apply');
        Route::get('api/dependencies', [PlanningController::class, 'dependenciesApi'])->name('api.dependencies');

        Route::get('assets/{file}', AssetController::class)
            ->where('file', '[A-Za-z0-9_-]+\.(js|css)')
            ->name('asset');
    });
