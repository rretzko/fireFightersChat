<?php

use App\Http\Middleware\IdentifyTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => IdentifyTenant::class,
        ]);

        // Tenant-scoped models (Member, Broadcast, ...) fail closed with no
        // tenant bound. Implicit route-model binding runs via
        // SubstituteBindings, so IdentifyTenant must bind the tenant before
        // that runs — otherwise any future route using {member}/{broadcast}
        // style binding would 404 on perfectly valid records. Without this,
        // IdentifyTenant sinks to the end of the pipeline (after
        // SubstituteBindings) since it isn't in Laravel's default priority
        // list at all.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: IdentifyTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
