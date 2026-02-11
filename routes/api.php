<?php

use App\Http\Controllers\App\AttendanceController;
use App\Http\Controllers\App\ProgressController;
use App\Http\Controllers\App\ProjectController;
use App\Http\Controllers\App\SyncController;
use App\Http\Controllers\App\UploadController;
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
});
