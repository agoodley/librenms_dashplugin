<?php

use Agoodley\LibrenmsCompliance\ComplianceServiceProvider;
use Agoodley\LibrenmsCompliance\Http\Controllers\AssetController;
use Agoodley\LibrenmsCompliance\Http\Controllers\ComplianceController;
use Agoodley\LibrenmsCompliance\Http\Controllers\SettingsController;
use Agoodley\LibrenmsCompliance\Http\Middleware\RequireAccess;
use Illuminate\Support\Facades\Route;

// Every URI has at least two segments after "plugin/" so it never collides with LibreNMS' own "plugin/{plugin}" page
// route. The pages are for the users LibreNMS lets read configuration backups, each for the devices they may see;
// checking a device now and the settings are for LibreNMS admins.
Route::middleware(['web', 'auth', RequireAccess::class])
    ->prefix(ComplianceServiceProvider::URI_PREFIX)
    ->name('compliance.')
    ->group(function (): void {
        Route::get('overview', [ComplianceController::class, 'index'])->name('overview');
        Route::get('devices', [ComplianceController::class, 'devices'])->name('devices');
        Route::get('devices/{id}', [ComplianceController::class, 'device'])->whereNumber('id')->name('device');
        Route::get('rules', [ComplianceController::class, 'rules'])->name('rules');
        Route::get('findings/export/{format}', [ComplianceController::class, 'export'])->where('format', 'csv|json')->name('export');
        Route::get('assets/{file}', AssetController::class)->where('file', '[A-Za-z0-9_-]+\.css')->name('asset');

        Route::middleware(RequireAccess::class . ':admin')->group(function (): void {
            Route::post('devices/{id}/check', [ComplianceController::class, 'check'])->whereNumber('id')->name('device.check');
            Route::post('settings', [SettingsController::class, 'save'])->name('settings.save');
        });
    });
