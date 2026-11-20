<?php

declare(strict_types=1);

use DbCms\Core\Http\Middleware\CheckPermission;
use DbCms\Modules\ContentAudit\Http\Controllers\Admin\ApprovePostController;
use DbCms\Modules\ContentAudit\Http\Controllers\Admin\DiffGetController;
use DbCms\Modules\ContentAudit\Http\Controllers\Admin\IndexGetController;
use DbCms\Modules\ContentAudit\Http\Controllers\Admin\PreviewGetController;
use DbCms\Modules\ContentAudit\Http\Controllers\Admin\RejectPostController;
use DbCms\Modules\ContentAudit\Http\Controllers\Admin\ReviewGetController;
use DbCms\Modules\ContentAudit\Http\Controllers\Admin\RollbackPostController;
use DbCms\Modules\ContentAudit\Http\Controllers\Admin\SettingsPostController;
use Illuminate\Support\Facades\Route;

Route::middleware([CheckPermission::class.':access:admin'])->prefix('audit')->name('audit.')->group(function (): void {
    Route::get('/', IndexGetController::class)->name('index');
    Route::get('/preview/{type}/{id}', PreviewGetController::class)->name('preview');
    Route::get('/review/{type}/{id}', ReviewGetController::class)->name('review');
    Route::post('/approve/{type}/{id}', ApprovePostController::class)->name('approve');
    Route::post('/reject/{type}/{id}', RejectPostController::class)->name('reject');
    Route::get('/diff/{revision}', DiffGetController::class)->name('diff');
    Route::post('/rollback/{revision}', RollbackPostController::class)->name('rollback');
    Route::post('/settings', SettingsPostController::class)->middleware(CheckPermission::class.':manage:modules')->name('settings');
});
