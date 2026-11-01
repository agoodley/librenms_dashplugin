<?php

use Agoodley\LibrenmsInventory\Http\Controllers\AssetController;
use Agoodley\LibrenmsInventory\Http\Controllers\InventoryController;
use Agoodley\LibrenmsInventory\Http\Middleware\RequireAccess;
use Agoodley\LibrenmsInventory\InventoryServiceProvider;
use Illuminate\Support\Facades\Route;

// Every URI has at least two segments after "plugin/" so it never collides with LibreNMS' own "plugin/{plugin}" page
// route. Every page is read-only and shows each user the devices LibreNMS lets them see.
Route::middleware(['web', 'auth', RequireAccess::class])
    ->prefix(InventoryServiceProvider::URI_PREFIX)
    ->name('inventory.')
    ->group(function (): void {
        Route::get('overview', [InventoryController::class, 'overview'])->name('overview');
        Route::get('devices', [InventoryController::class, 'devices'])->name('devices');
        Route::get('modules', [InventoryController::class, 'modules'])->name('modules');
        Route::get('optics', [InventoryController::class, 'optics'])->name('optics');
        Route::get('search', [InventoryController::class, 'search'])->name('search');
        Route::get('export/{kind}', [InventoryController::class, 'export'])->where('kind', 'devices|modules|optics|models|versions')->name('export');
        Route::get('assets/{file}', AssetController::class)->where('file', '[A-Za-z0-9_-]+\.css')->name('asset');
    });
