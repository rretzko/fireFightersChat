<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone_number' => $this->validPhoneNumber(),
            'status' => MemberStatus::Active,
        ];
    }

    /**
     * A random-but-always-valid NANP number: area code and exchange code
     * can't start with 0 or 1 (see PhoneNumberNormalizer). A plain
     * numerify('##########') occasionally generated an invalid one,
     * making any test that round-trips a factory member through the
     * PhoneNumber validation rule flaky.
     */
    private function validPhoneNumber(): string
    {
        $areaCode = (string) fake()->numberBetween(2, 9).fake()->numerify('##');
        $exchange = (string) fake()->numberBetween(2, 9).fake()->numerify('##');
        $subscriber = fake()->numerify('####');

        return "+1{$areaCode}{$exchange}{$subscriber}";
    }

    public function optedOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MemberStatus::OptedOut,
            'opted_out_at' => now(),
        ]);
    }
}
