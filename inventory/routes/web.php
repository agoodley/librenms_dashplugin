<?php

use Agoodley\LibrenmsInventory\Http\Controllers\AssetController;
use Agoodley\LibrenmsInventory\Http\Controllers\InventoryController;
use Agoodley\LibrenmsInventory\Http\Controllers\SettingsController;
use Agoodley\LibrenmsInventory\Http\Controllers\SoftwareController;
use Agoodley\LibrenmsInventory\Http\Middleware\RequireAccess;
use Agoodley\LibrenmsInventory\InventoryServiceProvider;
use Illuminate\Support\Facades\Route;

// Every URI has at least two segments after "plugin/" so it never collides with LibreNMS' own "plugin/{plugin}" page
// route. Every page is read-only and shows each user the devices LibreNMS lets them see; the settings and a software
// check now are for LibreNMS admins.
Route::middleware(['web', 'auth', RequireAccess::class])
    ->prefix(InventoryServiceProvider::URI_PREFIX)
    ->name('inventory.')
    ->group(function (): void {
        Route::get('overview', [InventoryController::class, 'overview'])->name('overview');
        Route::get('devices', [InventoryController::class, 'devices'])->name('devices');
        Route::get('modules', [InventoryController::class, 'modules'])->name('modules');
        Route::get('optics', [InventoryController::class, 'optics'])->name('optics');
        Route::get('software', [SoftwareController::class, 'software'])->name('software');
        Route::get('upgrades', [SoftwareController::class, 'upgrades'])->name('upgrades');
        Route::get('search', [InventoryController::class, 'search'])->name('search');
        Route::get('export/{kind}', [InventoryController::class, 'export'])->where('kind', 'devices|modules|optics|models|versions|software|upgrades')->name('export');
        Route::get('assets/{file}', AssetController::class)->where('file', '[A-Za-z0-9_-]+\.css')->name('asset');

        Route::middleware(RequireAccess::class . ':admin')->group(function (): void {
            Route::post('software/check', [SoftwareController::class, 'check'])->name('software.check');
            Route::post('settings', [SettingsController::class, 'save'])->name('settings.save');
        });
    });
