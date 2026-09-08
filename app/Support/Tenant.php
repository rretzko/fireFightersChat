<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Organization;
use Illuminate\Support\Facades\App;

/**
 * Static convenience wrapper around the bound TenantContext singleton.
 */
class Tenant
{
    public static function context(): TenantContext
    {
        return App::make(TenantContext::class);
    }

    public static function set(?Organization $organization): void
    {
        self::context()->set($organization);
    }

    public static function get(): ?Organization
    {
        return self::context()->get();
    }

    public static function check(): bool
    {
        return self::context()->check();
    }

    public static function id(): ?int
    {
        return self::context()->id();
    }
}
