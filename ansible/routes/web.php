<?php

use Agoodley\LibrenmsAnsible\AnsibleServiceProvider;
use Agoodley\LibrenmsAnsible\Http\Controllers\AssetController;
use Agoodley\LibrenmsAnsible\Http\Controllers\InventoryController;
use Agoodley\LibrenmsAnsible\Http\Controllers\LaunchController;
use Agoodley\LibrenmsAnsible\Http\Controllers\RunController;
use Agoodley\LibrenmsAnsible\Http\Controllers\SettingsController;
use Agoodley\LibrenmsAnsible\Http\Middleware\AuthTokenHeader;
use Agoodley\LibrenmsAnsible\Http\Middleware\RequireAdmin;
use Illuminate\Support\Facades\Route;

// Every URI has at least two segments after "plugin/" so it never collides with LibreNMS' own "plugin/{plugin}"
// page route. Pages and their JSON are for LibreNMS admins; launching and stopping also need the run permission.
Route::middleware(['web', 'auth', RequireAdmin::class])
    ->prefix(AnsibleServiceProvider::URI_PREFIX)
    ->name('ansible.')
    ->group(function (): void {
        Route::get('runs', [RunController::class, 'index'])->name('runs');
        Route::get('api/runs', [RunController::class, 'list'])->name('api.runs');
        Route::get('runs/{id}', [RunController::class, 'show'])->whereNumber('id')->name('runs.show');
        Route::get('api/runs/{id}', [RunController::class, 'poll'])->whereNumber('id')->name('api.runs.show');

        Route::get('inventory', [InventoryController::class, 'show'])->name('inventory');
        Route::get('inventory/download', [InventoryController::class, 'download'])->name('inventory.download');

        Route::post('settings/connection', [SettingsController::class, 'connection'])->name('settings.connection');
        Route::post('settings/test', [SettingsController::class, 'test'])->name('settings.test');
        Route::post('settings/permission', [SettingsController::class, 'permission'])->name('settings.permission');

        Route::get('assets/{file}', AssetController::class)->where('file', '[A-Za-z0-9_-]+\.(js|css)')->name('asset');

        Route::middleware(RequireAdmin::class . ':run')->group(function (): void {
            Route::get('runs/new', [LaunchController::class, 'create'])->name('runs.new');
            Route::get('api/targets', [LaunchController::class, 'targets'])->name('api.targets');
            Route::post('api/preview', [LaunchController::class, 'preview'])->name('api.preview');
            Route::post('runs', [LaunchController::class, 'store'])->name('runs.store');
            Route::post('runs/{id}/stop', [RunController::class, 'stop'])->whereNumber('id')->name('runs.stop');
        });
    });

// The inventory the runner pulls (contrib/librenms_inventory.py): read only, a LibreNMS API token (X-Auth-Token
// or Bearer) instead of a session, scoped to the devices the token's user may see.
Route::middleware([AuthTokenHeader::class, 'auth:sanctum'])
    ->prefix(AnsibleServiceProvider::URI_PREFIX)
    ->name('ansible.')
    ->group(function (): void {
        Route::get('api/inventory', [InventoryController::class, 'runner'])->name('api.inventory');
    });
