<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentifyTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_an_organization_is_redirected_to_create_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('organizations.create'));
    }

    public function test_organization_create_page_is_reachable_without_an_organization(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('organizations.create'))
            ->assertOk();
    }

    public function test_user_with_an_organization_can_reach_the_dashboard(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $organization->users()->attach($user, [
            'role' => OrganizationRole::Owner,
            'joined_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame($organization->id, $user->refresh()->current_organization_id);
    }
}
