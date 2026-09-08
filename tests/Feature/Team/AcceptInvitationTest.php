<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AcceptInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_matching_authenticated_user_can_accept_an_invitation(): void
    {
        $organization = Organization::factory()->create();
        $invitation = OrganizationInvitation::factory()
            ->for($organization, 'organization')
            ->create(['email' => 'invitee@example.com', 'role' => OrganizationRole::Admin]);

        $user = User::factory()->create(['email' => 'invitee@example.com']);
        $this->actingAs($user);

        Livewire::test('pages::invitations.accept', ['token' => $invitation->token])
            ->call('accept')
            ->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertSame($organization->id, $user->current_organization_id);
        $this->assertSame(OrganizationRole::Admin, $user->roleIn($organization));
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_accepting_is_case_insensitive_on_email(): void
    {
        $organization = Organization::factory()->create();
        $invitation = OrganizationInvitation::factory()
            ->for($organization, 'organization')
            ->create(['email' => 'Invitee@Example.com']);

        $user = User::factory()->create(['email' => 'invitee@example.com']);
        $this->actingAs($user);

        Livewire::test('pages::invitations.accept', ['token' => $invitation->token])
            ->call('accept')
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($user->fresh()->belongsToOrganization($organization));
    }

    public function test_a_mismatched_email_cannot_accept(): void
    {
        $organization = Organization::factory()->create();
        $invitation = OrganizationInvitation::factory()
            ->for($organization, 'organization')
            ->create(['email' => 'invitee@example.com']);

        $wrongUser = User::factory()->create(['email' => 'someone-else@example.com']);
        $this->actingAs($wrongUser);

        Livewire::test('pages::invitations.accept', ['token' => $invitation->token])
            ->call('accept')
            ->assertForbidden();
    }

    public function test_an_expired_invitation_cannot_be_accepted(): void
    {
        $organization = Organization::factory()->create();
        $invitation = OrganizationInvitation::factory()
            ->for($organization, 'organization')
            ->expired()
            ->create(['email' => 'invitee@example.com']);

        $user = User::factory()->create(['email' => 'invitee@example.com']);
        $this->actingAs($user);

        Livewire::test('pages::invitations.accept', ['token' => $invitation->token])
            ->call('accept')
            ->assertNotFound();

        $this->assertFalse($user->fresh()->belongsToOrganization($organization));
    }

    public function test_an_already_accepted_invitation_cannot_be_accepted_again(): void
    {
        $organization = Organization::factory()->create();
        $invitation = OrganizationInvitation::factory()
            ->for($organization, 'organization')
            ->accepted()
            ->create(['email' => 'invitee@example.com']);

        $user = User::factory()->create(['email' => 'invitee@example.com']);
        $this->actingAs($user);

        Livewire::test('pages::invitations.accept', ['token' => $invitation->token])
            ->call('accept')
            ->assertNotFound();
    }

    public function test_a_bogus_token_returns_404(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::invitations.accept', ['token' => 'not-a-real-token'])
            ->assertNotFound();
    }

    public function test_an_unauthenticated_visitor_is_sent_to_login_and_returns_to_the_invitation_after_authenticating(): void
    {
        $organization = Organization::factory()->create();
        $invitation = OrganizationInvitation::factory()
            ->for($organization, 'organization')
            ->create(['email' => 'brandnew@example.com']);

        // Hitting the invite link while logged out: Laravel's auth
        // middleware remembers this as the "intended" URL.
        $this->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('login'));

        // Register a brand-new account with the invited email — Fortify's
        // registration response should honor the stored intended URL.
        $response = $this->post(route('register.store'), [
            'name' => 'Brand New',
            'email' => 'brandnew@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect(route('invitations.accept', $invitation->token));
        $this->assertAuthenticated();
    }

    public function test_accepting_while_already_a_member_does_not_duplicate_the_membership(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['email' => 'invitee@example.com']);
        $organization->users()->attach($user, ['role' => OrganizationRole::Sender, 'joined_at' => now()]);

        $invitation = OrganizationInvitation::factory()
            ->for($organization, 'organization')
            ->create(['email' => 'invitee@example.com', 'role' => OrganizationRole::Admin]);

        $this->actingAs($user);

        Livewire::test('pages::invitations.accept', ['token' => $invitation->token])
            ->call('accept')
            ->assertRedirect(route('dashboard'));

        $this->assertSame(1, $organization->users()->where('users.id', $user->id)->count());
    }
}
