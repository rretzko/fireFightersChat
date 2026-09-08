<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Models\Member;
use App\Models\Message;
use App\Models\Scopes\OrganizationScope;
use App\Services\Sms\SmsGateway;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one broadcast to one member. Runs inside a Bus::batch() dispatched by
 * SendBroadcast, one job per active member at fan-out time.
 *
 * Deliberately takes memberId + body rather than loading them off relations
 * at execution time: queued jobs run with no tenant bound (there's no HTTP
 * request), and every tenant-scoped model query fails closed with no tenant
 * bound (see OrganizationScope) — the explicit withoutGlobalScope() calls
 * below are what make that safe here.
 */
class SendBroadcastMessage implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $messageId,
        public readonly int $memberId,
        public readonly string $body,
    ) {}

    public function handle(SmsGateway $gateway): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $member = Member::withoutGlobalScope(OrganizationScope::class)->findOrFail($this->memberId);

        $result = $gateway->send($member, $this->body);

        Message::withoutGlobalScope(OrganizationScope::class)
            ->whereKey($this->messageId)
            ->update([
                'status' => ($result->successful ? MessageStatus::Sent : MessageStatus::Failed)->value,
                'twilio_sid' => $result->providerMessageId,
                'error_code' => $result->errorCode,
                'sent_at' => now(),
            ]);
    }
}
