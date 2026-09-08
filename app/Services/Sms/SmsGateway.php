<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\Member;

/**
 * The seam between the app and whatever actually sends a text. Swapping
 * `LogSmsGateway` for a real `TwilioSmsGateway` (Phase 4) should be a
 * one-line config change (see config/sms.php), not a code change anywhere
 * that sends a message.
 */
interface SmsGateway
{
    public function send(Member $member, string $body): SmsSendResult;
}
