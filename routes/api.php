<?php

use App\Http\Controllers\App\AttendanceController;
use App\Http\Controllers\App\ProgressController;
use App\Http\Controllers\App\ProjectController;
use App\Http\Controllers\App\ProjectUploadController;
use App\Http\Controllers\App\SarasStatusController;
use App\Http\Controllers\App\SyncController;
use App\Http\Controllers\App\UploadController;
use App\Http\Controllers\App\UploadPreviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the RouteServiceProvider and are assigned
| the "api" middleware group. All routes require authentication.
|
*/

Route::middleware(['auth:sanctum'])->group(function () {
    // Projects
    Route::post('/projects/sync', [ProjectController::class, 'sync'])->name('api.projects.sync');

    // Attendance
    Route::get('/attendance/status', [AttendanceController::class, 'status'])->name('api.attendance.status');
    Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn'])->name('api.attendance.check-in');
    Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut'])->name('api.attendance.check-out');

    // Uploads
    Route::post('/uploads/init', [UploadController::class, 'init'])->name('api.uploads.init');
    Route::post('/uploads/file', [UploadController::class, 'file'])->name('api.uploads.file');

    // Progress
    Route::post('/progress/submit', [ProgressController::class, 'submit'])->name('api.progress.submit');
    Route::post('/progress/photo', [ProgressController::class, 'uploadPhoto'])->name('api.progress.photo');
    Route::post('/progress/ai', [ProgressController::class, 'runAi'])->name('api.progress.ai');
    Route::get('/progress/ai/{workflowId}', [ProgressController::class, 'aiStatus'])->name('api.progress.ai-status');

    // Offline Sync
    Route::post('/sync/batch', [SyncController::class, 'batch'])->name('api.sync.batch');

    // Project Uploads (CRUD)
    Route::prefix('/projects/{project}/uploads')->group(function () {
        Route::get('/', [ProjectUploadController::class, 'index'])->name('api.projects.uploads.index');
        Route::post('/', [ProjectUploadController::class, 'store'])->name('api.projects.uploads.store');
        Route::get('/{upload}', [ProjectUploadController::class, 'show'])->name('api.projects.uploads.show');
        Route::patch('/{upload}', [ProjectUploadController::class, 'update'])->name('api.projects.uploads.update');
        Route::delete('/{upload}', [ProjectUploadController::class, 'destroy'])->name('api.projects.uploads.destroy');
        Route::post('/{upload}/retry', [ProjectUploadController::class, 'retry'])->name('api.projects.uploads.retry');
        Route::post('/{upload}/file', [ProjectUploadController::class, 'file'])->name('api.projects.uploads.file');
    });

    // Upload Preview (direct upload access, not project-scoped)
    Route::get('/uploads/{upload}/preview', [UploadPreviewController::class, 'preview'])->name('api.uploads.preview');

    // Saras Status
    Route::get('/saras/status', [SarasStatusController::class, 'status'])->name('api.saras.status');
    Route::post('/saras/health-check', [SarasStatusController::class, 'healthCheck'])->name('api.saras.health-check');
});
