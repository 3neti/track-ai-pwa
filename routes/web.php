<?php

use App\Http\Controllers\App\AttendanceController;
use App\Http\Controllers\App\ContractController;
use App\Http\Controllers\App\ProgressController;
use App\Http\Controllers\App\ProjectController;
use App\Http\Controllers\App\ProjectProgressController;
use App\Http\Controllers\App\ProjectUploadController;
use App\Http\Controllers\App\SarasProjectSelectionController;
use App\Http\Controllers\App\SyncController;
use App\Http\Controllers\App\UploadController;
use App\Http\Controllers\Auth\FaceAuthController;
use App\Http\Controllers\Auth\FaceLoginController;
use App\Http\Controllers\Auth\FaceRegistrationController;
use App\Http\Controllers\Auth\FaceRegistrationStatusController;
use App\Http\Controllers\Developer\SarasApiXrayController;
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

Route::get('/face-register', [FaceRegistrationController::class, 'index'])
    ->middleware('auth')
    ->name('face-register');

Route::post('/auth/face/register', [FaceRegistrationController::class, 'store'])
    ->middleware(['auth', 'throttle:face-login'])
    ->name('auth.face.register');

Route::post('/auth/face/registration-status', [FaceRegistrationStatusController::class, 'show'])
    ->middleware(['web', 'throttle:face-login'])
    ->name('auth.face.registration-status');

Route::get('dashboard', function () {
    return redirect('/app/contracts');
})->middleware(['auth', 'verified'])->name('dashboard');

/*
|--------------------------------------------------------------------------
| Track AI App Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->prefix('app')->group(function () {
    Route::get('/contracts', [ContractController::class, 'index'])->name('app.contracts');
    Route::get('/projects', [ProjectController::class, 'index'])->name('app.projects');
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('app.attendance');
    Route::get('/uploads', [UploadController::class, 'index'])->name('app.uploads');
    Route::get('/project-uploads', [ProjectUploadController::class, 'page'])->name('app.project-uploads');
    Route::get('/progress', [ProgressController::class, 'index'])->name('app.progress');
    Route::get('/project-progress', [ProjectProgressController::class, 'index'])->name('app.project-progress');
    Route::get('/project-context', [SarasProjectSelectionController::class, 'index'])->name('app.project-context');
    Route::post('/project-context', [SarasProjectSelectionController::class, 'update'])->name('app.project-context.update');
    Route::post('/project-context/refresh', [SarasProjectSelectionController::class, 'refresh'])->name('app.project-context.refresh');
    Route::post('/project-context/reset', [SarasProjectSelectionController::class, 'reset'])->name('app.project-context.reset');
    Route::get('/sync', [SyncController::class, 'index'])->name('app.sync');
});

/*
|--------------------------------------------------------------------------
| Developer Tools
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->prefix('developer')->group(function () {
    Route::get('/saras-api-xray', [SarasApiXrayController::class, 'index'])->name('developer.saras-api-xray');
    Route::get('/api/payload-map', [SarasApiXrayController::class, 'payloadMap'])->name('developer.api.payload-map');
    Route::get('/api/traces', [SarasApiXrayController::class, 'traces'])->name('developer.api.traces');
    Route::get('/api/traces/{apiTrace}', [SarasApiXrayController::class, 'show'])->name('developer.api.traces.show');
});

require __DIR__.'/settings.php';
