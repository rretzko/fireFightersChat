<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MessageStatus;
use App\Models\Broadcast;
use App\Models\Member;
use App\Models\Message;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 *
 * Note: the default definition creates independent related factories, so
 * organization_id/broadcast/member won't share the same org unless the
 * caller correlates them explicitly (e.g. via ->for($organization) or by
 * passing matching IDs) — deliberate, to keep this factory usable in
 * isolation for simple Message-only tests.
 */
class MessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'broadcast_id' => Broadcast::factory(),
            'member_id' => Member::factory(),
            'direction' => 'outbound',
            'status' => MessageStatus::Queued,
        ];
    }
}
