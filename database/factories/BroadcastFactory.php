<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BroadcastStatus;
use App\Models\Broadcast;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Broadcast>
 */
class BroadcastFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'sender_user_id' => User::factory(),
            'body' => fake()->sentence(),
            'status' => BroadcastStatus::Queued,
        ];
    }
}
