<?php

use Agoodley\LibrenmsTopology\Http\Controllers\ApiController;
use Agoodley\LibrenmsTopology\Http\Controllers\AssetController;
use Agoodley\LibrenmsTopology\Http\Controllers\HistoryController;
use Agoodley\LibrenmsTopology\Http\Controllers\PageController;
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
        Route::get('stp', [PageController::class, 'stp'])->name('stp');
        Route::get('links', [PageController::class, 'links'])->name('links');
        Route::get('redundancy', [PageController::class, 'redundancy'])->name('redundancy');
        Route::get('changes', [HistoryController::class, 'changes'])->name('changes');
        Route::post('changes/baseline', [HistoryController::class, 'baseline'])->name('changes.baseline');

        Route::get('api/l2', [ApiController::class, 'l2'])->name('api.l2');
        Route::get('api/l3', [ApiController::class, 'l3'])->name('api.l3');
        Route::get('api/stp', [ApiController::class, 'stp'])->name('api.stp');
        Route::get('api/links', [ApiController::class, 'links'])->name('api.links');
        Route::get('api/redundancy', [ApiController::class, 'redundancy'])->name('api.redundancy');
        Route::get('api/simulate', [ApiController::class, 'simulate'])->name('api.simulate');
        Route::get('api/changes', [HistoryController::class, 'api'])->name('api.changes');

        Route::get('assets/{file}', AssetController::class)
            ->where('file', '[A-Za-z0-9_-]+\.(js|css)')
            ->name('asset');
    });
