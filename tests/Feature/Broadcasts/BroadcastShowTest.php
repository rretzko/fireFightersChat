<?php

declare(strict_types=1);

namespace Tests\Feature\Broadcasts;

use App\Actions\SendBroadcast;
use App\Enums\OrganizationRole;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastShowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    private function attachUser(OrganizationRole $role): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role, 'joined_at' => now()]);

        return $user;
    }

    public function test_owner_sees_the_per_recipient_breakdown(): void
    {
        $owner = $this->attachUser(OrganizationRole::Owner);
        $member = Member::factory()->for($this->organization, 'organization')->create();

        $broadcast = app(SendBroadcast::class)($this->organization, $owner, 'Muster tonight.');

        $this->actingAs($owner)
            ->get(route('broadcasts.show', $broadcast))
            ->assertOk()
            ->assertSee($member->fullName())
            ->assertSee($member->formattedPhoneNumber());
    }

    public function test_a_sender_does_not_see_the_per_recipient_breakdown(): void
    {
        $owner = $this->attachUser(OrganizationRole::Owner);
        $sender = $this->attachUser(OrganizationRole::Sender);
        $member = Member::factory()->for($this->organization, 'organization')->create();

        $broadcast = app(SendBroadcast::class)($this->organization, $owner, 'Muster tonight.');

        $this->actingAs($sender)
            ->get(route('broadcasts.show', $broadcast))
            ->assertOk()
            ->assertSee('Muster tonight.')
            ->assertDontSee($member->formattedPhoneNumber());
    }

    /**
     * Regression test for the middleware-priority fix in bootstrap/app.php:
     * implicit route-model binding for {broadcast} must resolve against the
     * *viewer's* tenant, not leak another organization's broadcast. Before
     * that fix, SubstituteBindings ran before IdentifyTenant bound the
     * tenant, so this exact scenario either 404'd for everyone or (worse,
     * if the scope had failed open instead of closed) leaked cross-tenant.
     */
    public function test_a_broadcast_from_another_organization_is_not_found(): void
    {
        $owner = $this->attachUser(OrganizationRole::Owner);

        $otherOrg = Organization::factory()->create();
        $otherOwner = User::factory()->create();
        $otherOrg->users()->attach($otherOwner, ['role' => OrganizationRole::Owner, 'joined_at' => now()]);
        Member::factory()->for($otherOrg, 'organization')->create();

        $otherBroadcast = app(SendBroadcast::class)($otherOrg, $otherOwner, 'Not for org A');

        $this->actingAs($owner)
            ->get(route('broadcasts.show', $otherBroadcast))
            ->assertNotFound();
    }

    public function test_own_broadcast_is_reachable_via_implicit_route_model_binding(): void
    {
        $owner = $this->attachUser(OrganizationRole::Owner);
        Member::factory()->for($this->organization, 'organization')->create();

        $broadcast = app(SendBroadcast::class)($this->organization, $owner, 'Reachable');

        $this->actingAs($owner)
            ->get(route('broadcasts.show', $broadcast))
            ->assertOk();
    }
}
