<?php

use Agoodley\LibrenmsAnsible\AnsibleServiceProvider;
use Agoodley\LibrenmsAnsible\Http\Controllers\AssetController;
use Agoodley\LibrenmsAnsible\Http\Controllers\ChangeController;
use Agoodley\LibrenmsAnsible\Http\Controllers\InventoryController;
use Agoodley\LibrenmsAnsible\Http\Controllers\TargetController;
use Agoodley\LibrenmsAnsible\Http\Controllers\RunController;
use Agoodley\LibrenmsAnsible\Http\Controllers\SettingsController;
use Agoodley\LibrenmsAnsible\Http\Middleware\AuthTokenHeader;
use Agoodley\LibrenmsAnsible\Http\Middleware\RequireAdmin;
use Illuminate\Support\Facades\Route;

// Every URI has at least two segments after "plugin/" so it never collides with LibreNMS' own "plugin/{plugin}"
// page route. Pages and their JSON are for LibreNMS admins; preparing, dry-running, applying and stopping changes
// also need the run permission, approving and rejecting them the approve permission. Every run belongs to a change:
// there is no launch of its own any more.
Route::middleware(['web', 'auth', RequireAdmin::class])
    ->prefix(AnsibleServiceProvider::URI_PREFIX)
    ->name('ansible.')
    ->group(function (): void {
        Route::get('changes', [ChangeController::class, 'index'])->name('changes');
        Route::get('api/changes', [ChangeController::class, 'list'])->name('api.changes');
        Route::get('changes/{id}', [ChangeController::class, 'show'])->whereNumber('id')->name('changes.show');
        Route::get('api/changes/{id}', [ChangeController::class, 'poll'])->whereNumber('id')->name('api.changes.show');

        Route::get('runs', [RunController::class, 'index'])->name('runs');
        Route::get('api/runs', [RunController::class, 'list'])->name('api.runs');
        Route::get('runs/{id}', [RunController::class, 'show'])->whereNumber('id')->name('runs.show');
        Route::get('api/runs/{id}', [RunController::class, 'poll'])->whereNumber('id')->name('api.runs.show');

        Route::get('inventory', [InventoryController::class, 'show'])->name('inventory');
        Route::get('inventory/download', [InventoryController::class, 'download'])->name('inventory.download');

        Route::post('settings/connection', [SettingsController::class, 'connection'])->name('settings.connection');
        Route::post('settings/runs', [SettingsController::class, 'runs'])->name('settings.runs');
        Route::post('settings/test', [SettingsController::class, 'test'])->name('settings.test');
        Route::post('settings/permission', [SettingsController::class, 'permission'])->name('settings.permission');

        Route::get('assets/{file}', AssetController::class)->where('file', '[A-Za-z0-9_-]+\.(js|css)')->name('asset');

        Route::middleware(RequireAdmin::class . ':run')->group(function (): void {
            Route::get('changes/new', [ChangeController::class, 'create'])->name('changes.new');
            Route::get('runs/new', fn () => redirect()->route('ansible.changes.new', array_filter(['template' => request()->query('template'),
                'device' => request()->query('device')], fn ($v) => is_string($v) && ctype_alnum($v))))->name('runs.new');
            Route::get('api/targets', [TargetController::class, 'targets'])->name('api.targets');
            Route::post('api/changes/preview', [ChangeController::class, 'preview'])->name('api.changes.preview');
            Route::post('changes', [ChangeController::class, 'store'])->name('changes.store');
            Route::post('changes/{id}/dry-run', [ChangeController::class, 'dryRun'])->whereNumber('id')->name('changes.dry-run');
            Route::post('changes/{id}/apply', [ChangeController::class, 'apply'])->whereNumber('id')->name('changes.apply');
            Route::post('changes/{id}/continue', [ChangeController::class, 'resume'])->whereNumber('id')->name('changes.continue');
            Route::post('changes/{id}/stop', [ChangeController::class, 'stop'])->whereNumber('id')->name('changes.stop');
            Route::post('changes/{id}/cancel', [ChangeController::class, 'cancel'])->whereNumber('id')->name('changes.cancel');
            Route::post('runs/{id}/stop', [RunController::class, 'stop'])->whereNumber('id')->name('runs.stop');
            Route::post('runs/{id}/release', [RunController::class, 'release'])->whereNumber('id')->name('runs.release');
        });

        Route::middleware(RequireAdmin::class . ':approve')->group(function (): void {
            Route::post('changes/{id}/approve', [ChangeController::class, 'approve'])->whereNumber('id')->name('changes.approve');
            Route::post('changes/{id}/reject', [ChangeController::class, 'reject'])->whereNumber('id')->name('changes.reject');
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

// Any other method on the inventory endpoint is answered 405 here, without a session or a token: otherwise LibreNMS'
// catch-all page route (in its web group) would take it and answer 419 for the missing CSRF token.
Route::match(['POST', 'PUT', 'PATCH', 'DELETE'], AnsibleServiceProvider::URI_PREFIX . '/api/inventory', [InventoryController::class, 'readOnly'])
    ->name('ansible.api.inventory.read-only');
