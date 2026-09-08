<?php

declare(strict_types=1);

namespace Tests\Feature\Broadcasts;

use App\Actions\SendBroadcast;
use App\Enums\BroadcastStatus;
use App\Enums\MessageStatus;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SendBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_message_for_every_active_member_and_skips_opted_out_ones(): void
    {
        $organization = Organization::factory()->create();
        $sender = User::factory()->create();

        Member::factory()->for($organization, 'organization')->count(3)->create();
        Member::factory()->for($organization, 'organization')->optedOut()->create();

        $broadcast = app(SendBroadcast::class)($organization, $sender, 'Muster at the station, 6pm.');

        $this->assertSame(3, $broadcast->messages()->withoutGlobalScopes()->count());
        $this->assertSame(BroadcastStatus::Sent, $broadcast->fresh()->status);
    }

    public function test_it_uses_the_mocked_gateway_and_marks_messages_sent(): void
    {
        $organization = Organization::factory()->create();
        $sender = User::factory()->create();
        Member::factory()->for($organization, 'organization')->create();

        $broadcast = app(SendBroadcast::class)($organization, $sender, 'Test message');

        $message = $broadcast->messages()->withoutGlobalScopes()->sole();

        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertNotNull($message->twilio_sid);
        $this->assertStringStartsWith('log_', $message->twilio_sid);
        $this->assertNotNull($message->sent_at);
    }

    public function test_it_records_an_audit_log_entry_with_the_recipient_count(): void
    {
        $organization = Organization::factory()->create();
        $sender = User::factory()->create();
        Member::factory()->for($organization, 'organization')->count(2)->create();

        $broadcast = app(SendBroadcast::class)($organization, $sender, 'Test message');

        $log = AuditLog::withoutGlobalScopes()->where('action', 'broadcast.sent')->sole();

        $this->assertSame($organization->id, $log->organization_id);
        $this->assertSame($sender->id, $log->user_id);
        $this->assertSame($broadcast->id, $log->subject_id);
        $this->assertSame(2, $log->meta['recipient_count']);
    }

    public function test_it_handles_an_organization_with_no_active_members(): void
    {
        $organization = Organization::factory()->create();
        $sender = User::factory()->create();

        $broadcast = app(SendBroadcast::class)($organization, $sender, 'Nobody to send to');

        $this->assertSame(0, $broadcast->messages()->withoutGlobalScopes()->count());
        $this->assertSame(BroadcastStatus::Sent, $broadcast->status);
    }

    public function test_it_never_messages_members_of_another_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $sender = User::factory()->create();

        Member::factory()->for($organization, 'organization')->create();
        Member::factory()->for($otherOrg, 'organization')->create();

        $broadcast = app(SendBroadcast::class)($organization, $sender, 'Only for us');

        $this->assertSame(1, $broadcast->messages()->withoutGlobalScopes()->count());
    }
}
