<?php

namespace App\Providers;

use App\Contracts\FaceAuthProviderInterface;
use App\Services\FaceAuth\HypervergeDirectProvider;
use App\Services\FaceAuth\HypervergeStubProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerFaceAuthProvider();
    }

    /**
     * Register the face authentication provider.
     */
    private function registerFaceAuthProvider(): void
    {
        $this->app->singleton(FaceAuthProviderInterface::class, function () {
            if (config('hyperverge.mode') === 'live') {
                return new HypervergeDirectProvider(
                    baseUrl: config('hyperverge.base_url'),
                    appId: config('hyperverge.app_id'),
                    appKey: config('hyperverge.app_key'),
                    verifyPath: config('hyperverge.verify_path'),
                    timeout: config('hyperverge.timeout'),
                );
            }

            return new HypervergeStubProvider;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }
}
