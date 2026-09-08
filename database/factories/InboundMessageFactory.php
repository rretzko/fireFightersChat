<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InboundMessage;
use App\Models\Member;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboundMessage>
 */
class InboundMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'member_id' => Member::factory(),
            'body' => fake()->sentence(),
            'received_at' => now(),
        ];
    }
}
