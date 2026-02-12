<?php

use App\Http\Controllers\App\AttendanceController;
use App\Http\Controllers\App\ProgressController;
use App\Http\Controllers\App\ProjectController;
use App\Http\Controllers\App\ProjectUploadController;
use App\Http\Controllers\App\SyncController;
use App\Http\Controllers\App\UploadController;
use App\Http\Controllers\Auth\FaceAuthController;
use App\Http\Controllers\Auth\FaceLoginController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('/docs/user-guide', function () {
    return Inertia::render('docs/UserGuide');
})->name('docs.user-guide');

/*
|--------------------------------------------------------------------------
| Face Login Routes
|--------------------------------------------------------------------------
*/

Route::get('/face-login', [FaceLoginController::class, 'index'])
    ->middleware('guest')
    ->name('face-login');

Route::post('/auth/face/verify', [FaceAuthController::class, 'verify'])
    ->middleware(['web', 'throttle:face-login'])
    ->name('auth.face.verify');

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
    Route::get('/project-uploads', [ProjectUploadController::class, 'page'])->name('app.project-uploads');
    Route::get('/progress', [ProgressController::class, 'index'])->name('app.progress');
    Route::get('/sync', [SyncController::class, 'index'])->name('app.sync');
});

require __DIR__.'/settings.php';
