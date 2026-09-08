<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\Member;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Default gateway for the prototype: never actually calls Twilio. Logs what
 * would have been sent and reports success, so the rest of the messaging
 * pipeline (fan-out, per-recipient status, broadcast history) can be built
 * and demoed before real Twilio credentials and A2P 10DLC registration exist.
 */
class LogSmsGateway implements SmsGateway
{
    public function send(Member $member, string $body): SmsSendResult
    {
        $id = 'log_'.Str::uuid()->toString();

        Log::info('Mock SMS sent', [
            'provider_message_id' => $id,
            'to' => $member->phone_number,
            'body' => $body,
        ]);

        return new SmsSendResult(successful: true, providerMessageId: $id);
    }
}
