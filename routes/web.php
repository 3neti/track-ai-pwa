<?php

use App\Http\Controllers\App\AttendanceController;
use App\Http\Controllers\App\ProgressController;
use App\Http\Controllers\App\ProjectController;
use App\Http\Controllers\App\SyncController;
use App\Http\Controllers\App\UploadController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

/*
|--------------------------------------------------------------------------
| Track AI App Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->prefix('app')->group(function () {
    Route::get('/projects', [ProjectController::class, 'index'])->name('app.projects');
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('app.attendance');
    Route::get('/uploads', [UploadController::class, 'index'])->name('app.uploads');
    Route::get('/progress', [ProgressController::class, 'index'])->name('app.progress');
    Route::get('/sync', [SyncController::class, 'index'])->name('app.sync');
});

require __DIR__.'/settings.php';
