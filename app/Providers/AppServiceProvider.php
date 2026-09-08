<?php

namespace App\Providers;

use App\Http\Middleware\IdentifyTenant;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\SmsGateway;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);

        $this->app->bind(SmsGateway::class, function (): SmsGateway {
            return match (config('sms.gateway')) {
                'log' => new LogSmsGateway,
                default => throw new RuntimeException('Unsupported SMS gateway: '.config('sms.gateway')),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Livewire only replays a hardcoded allowlist of middleware for its
        // AJAX component-update requests (clicking a button, submitting a
        // form) — the initial page load runs the full route middleware
        // stack, but subsequent wire:click/wire:submit calls do NOT, unless
        // the middleware is registered here. Without this, IdentifyTenant
        // never runs for those requests, Tenant::get() is null, and every
        // tenant-scoped policy check (create a member, send a broadcast,
        // ...) fails even for a legitimate org owner.
        Livewire::addPersistentMiddleware([
            IdentifyTenant::class,
        ]);
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
            : null,
        );
    }
}
