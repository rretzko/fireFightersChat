<?php

namespace App\Providers;

use App\Http\Middleware\IdentifyTenant;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\SmsGateway;
use App\Services\Sms\TwilioSmsGateway;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use RuntimeException;
use Twilio\Rest\Api\V2010\Account\MessageList;
use Twilio\Rest\Client as TwilioClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);

        // Only actually instantiates when something resolves it — staying on
        // the "log" gateway never touches Twilio or needs real credentials.
        $this->app->singleton(TwilioClient::class, fn (): TwilioClient => new TwilioClient(
            config('services.twilio.sid'),
            config('services.twilio.token'),
        ));

        $this->app->singleton(
            MessageList::class,
            fn (Application $app): MessageList => $app->make(TwilioClient::class)->messages,
        );

        $this->app->bind(SmsGateway::class, function (Application $app): SmsGateway {
            return match (config('sms.gateway')) {
                'log' => new LogSmsGateway,
                'twilio' => new TwilioSmsGateway(
                    $app->make(MessageList::class),
                    config('services.twilio.from'),
                ),
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
