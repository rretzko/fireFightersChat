<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | SMS Gateway
    |--------------------------------------------------------------------------
    |
    | Which App\Services\Sms\SmsGateway implementation actually sends
    | messages. "log" never touches Twilio — it's the default until real
    | Twilio credentials and A2P 10DLC registration are in place (Phase 4).
    |
    */

    'gateway' => env('SMS_GATEWAY', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Rate Limits
    |--------------------------------------------------------------------------
    |
    | Bounds how often broadcasts can be sent, both per sender (contain a
    | compromised or fat-fingered account) and per organization (bound total
    | spend regardless of how many senders are hitting it). See business
    | plan §7: "Rate limiting on sending ... to control per-tenant Twilio
    | costs and to contain a compromised or malicious account."
    |
    */

    'rate_limits' => [
        'broadcasts_per_user_per_minute' => (int) env('SMS_RATE_LIMIT_USER_PER_MINUTE', 5),
        'broadcasts_per_org_per_hour' => (int) env('SMS_RATE_LIMIT_ORG_PER_HOUR', 20),
    ],

];
