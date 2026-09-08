<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Bounds broadcast sending both per-sender (a compromised or fat-fingered
 * account) and per-organization (total spend regardless of how many senders
 * are hitting it) — see business plan §7 and config/sms.php.
 */
class BroadcastRateLimiter
{
    public static function tooManyAttempts(User $user, Organization $organization): bool
    {
        return RateLimiter::tooManyAttempts(
            self::userKey($user),
            (int) config('sms.rate_limits.broadcasts_per_user_per_minute'),
        ) || RateLimiter::tooManyAttempts(
            self::orgKey($organization),
            (int) config('sms.rate_limits.broadcasts_per_org_per_hour'),
        );
    }

    public static function hit(User $user, Organization $organization): void
    {
        RateLimiter::hit(self::userKey($user), 60);
        RateLimiter::hit(self::orgKey($organization), 3600);
    }

    public static function availableInSeconds(User $user, Organization $organization): int
    {
        return max(
            RateLimiter::availableIn(self::userKey($user)),
            RateLimiter::availableIn(self::orgKey($organization)),
        );
    }

    private static function userKey(User $user): string
    {
        return "broadcasts:user:{$user->id}";
    }

    private static function orgKey(Organization $organization): string
    {
        return "broadcasts:org:{$organization->id}";
    }
}
