<?php

namespace App\Providers;

use App\Filament\Auth\Pages\Login as FilamentLogin;
use App\Filament\Auth\Responses\LoginResponse;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::component('app.filament.auth.pages.login', FilamentLogin::class);

        RateLimiter::for('pertamina-api', function ($job) {
            return Limit::perMinute(10)
                ->by('pertamina-api-account-' . $job->account_id);
        });
    }
}
