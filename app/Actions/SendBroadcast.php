<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\BroadcastStatus;
use App\Enums\MemberStatus;
use App\Enums\MessageStatus;
use App\Jobs\SendBroadcastMessage;
use App\Models\AuditLog;
use App\Models\Broadcast;
use App\Models\Member;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Creates a Broadcast, fans it out to one Message row per active member, and
 * dispatches one queued send job per recipient as a batch. Takes the
 * organization explicitly (rather than reading Tenant::get()) so it works
 * the same whether called from an authenticated request or, later, a console
 * command — every query below bypasses OrganizationScope and filters by
 * $organization->id directly for that reason (see the note on
 * MemberCsvImporter for why this matters: the scope fails closed).
 */
class SendBroadcast
{
    public function __invoke(Organization $organization, User $sender, string $body): Broadcast
    {
        $broadcast = DB::transaction(function () use ($organization, $sender, $body): Broadcast {
            $broadcast = Broadcast::create([
                'organization_id' => $organization->id,
                'sender_user_id' => $sender->id,
                'body' => $body,
                'status' => BroadcastStatus::Queued,
            ]);

            $memberIds = Member::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organization->id)
                ->where('status', MemberStatus::Active)
                ->pluck('id');

            foreach ($memberIds as $memberId) {
                Message::create([
                    'organization_id' => $organization->id,
                    'broadcast_id' => $broadcast->id,
                    'member_id' => $memberId,
                    'direction' => 'outbound',
                    'status' => MessageStatus::Queued,
                ]);
            }

            return $broadcast;
        });

        $recipientCount = Message::withoutGlobalScope(OrganizationScope::class)
            ->where('broadcast_id', $broadcast->id)
            ->count();

        // AuditLog::record() would rely on Tenant::get() to fill
        // organization_id, which isn't guaranteed bound here (see class
        // docblock) — set it explicitly instead.
        AuditLog::create([
            'organization_id' => $organization->id,
            'user_id' => $sender->id,
            'action' => 'broadcast.sent',
            'subject_type' => $broadcast->getMorphClass(),
            'subject_id' => $broadcast->id,
            'meta' => ['recipient_count' => $recipientCount],
        ]);

        $jobs = Message::withoutGlobalScope(OrganizationScope::class)
            ->where('broadcast_id', $broadcast->id)
            ->get(['id', 'member_id'])
            ->map(fn (Message $message) => new SendBroadcastMessage($message->id, $message->member_id, $body))
            ->all();

        if ($jobs === []) {
            $broadcast->update(['status' => BroadcastStatus::Sent]);

            return $broadcast;
        }

        $broadcastId = $broadcast->id;

        // Set to Sending *before* dispatching: with the sync queue driver the
        // batch (including its finally() callback below) runs immediately
        // inside dispatch(), so doing this after would clobber a Sent status
        // that already landed.
        $broadcast->update(['status' => BroadcastStatus::Sending]);

        Bus::batch($jobs)
            ->name("Broadcast #{$broadcastId}")
            ->allowFailures()
            ->finally(function (Batch $batch) use ($broadcastId): void {
                Broadcast::withoutGlobalScope(OrganizationScope::class)
                    ->whereKey($broadcastId)
                    ->update(['status' => BroadcastStatus::Sent->value]);
            })
            ->dispatch();

        return $broadcast;
    }
}
