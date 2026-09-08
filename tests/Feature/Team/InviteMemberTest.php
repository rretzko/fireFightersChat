<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class InviteMemberTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->create();

        $this->organization->users()->attach($this->owner, [
            'role' => OrganizationRole::Owner,
            'joined_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Tenant::set(null);

        parent::tearDown();
    }

    private function attachUser(OrganizationRole $role): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role, 'joined_at' => now()]);

        return $user;
    }

    public function test_owner_can_view_the_team_page(): void
    {
        $this->actingAs($this->owner)
            ->get(route('team.index'))
            ->assertOk();
    }

    public function test_team_page_shows_formatted_phone_numbers_and_a_placeholder_when_unset(): void
    {
        $this->owner->forceFill(['contact_phone_number' => '+15085550100'])->save();
        $noPhone = $this->attachUser(OrganizationRole::Sender);
        $this->assertNull($noPhone->contact_phone_number);

        $this->actingAs($this->owner)
            ->get(route('team.index'))
            ->assertOk()
            ->assertSee('+1 (508) 555-0100')
            ->assertDontSee('+15085550100')
            ->assertSee('—');
    }

    public function test_sender_cannot_view_the_team_page(): void
    {
        $sender = $this->attachUser(OrganizationRole::Sender);

        $this->actingAs($sender)
            ->get(route('team.index'))
            ->assertForbidden();
    }

    public function test_owner_can_invite_a_new_member_by_email(): void
    {
        Mail::fake();

        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::team.index')
            ->set('inviteEmail', 'newperson@example.com')
            ->set('inviteRole', 'admin')
            ->call('invite')
            ->assertHasNoErrors();

        $invitation = OrganizationInvitation::sole();

        $this->assertSame('newperson@example.com', $invitation->email);
        $this->assertSame(OrganizationRole::Admin, $invitation->role);
        $this->assertSame($this->owner->id, $invitation->invited_by_user_id);
        $this->assertNotEmpty($invitation->token);

        Mail::assertSent(OrganizationInvitationMail::class, fn ($mail) => $mail->hasTo('newperson@example.com'));
    }

    public function test_cannot_invite_someone_who_is_already_a_member(): void
    {
        Mail::fake();

        $existingMember = $this->attachUser(OrganizationRole::Sender);

        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::team.index')
            ->set('inviteEmail', $existingMember->email)
            ->set('inviteRole', 'sender')
            ->call('invite')
            ->assertHasErrors(['inviteEmail']);

        $this->assertSame(0, OrganizationInvitation::count());
        Mail::assertNothingSent();
    }

    public function test_reinviting_the_same_email_replaces_the_pending_invitation(): void
    {
        Mail::fake();

        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::team.index')
            ->set('inviteEmail', 'again@example.com')
            ->set('inviteRole', 'sender')
            ->call('invite');

        Livewire::test('pages::team.index')
            ->set('inviteEmail', 'again@example.com')
            ->set('inviteRole', 'admin')
            ->call('invite');

        $invitation = OrganizationInvitation::sole();
        $this->assertSame(OrganizationRole::Admin, $invitation->role);
    }

    public function test_sender_cannot_invite_anyone(): void
    {
        $sender = $this->attachUser(OrganizationRole::Sender);

        Tenant::set($this->organization);
        $this->actingAs($sender);

        Livewire::test('pages::team.index')->assertForbidden();
    }

    public function test_owner_can_cancel_a_pending_invitation(): void
    {
        Mail::fake();

        $invitation = OrganizationInvitation::factory()->for($this->organization, 'organization')->create();

        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::team.index')
            ->call('cancelInvitation', $invitation->id);

        $this->assertSame(0, OrganizationInvitation::count());
    }

    public function test_owner_can_resend_an_invitation(): void
    {
        Mail::fake();

        $invitation = OrganizationInvitation::factory()->for($this->organization, 'organization')->create([
            'expires_at' => now()->addHour(),
        ]);

        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::team.index')
            ->call('resendInvitation', $invitation->id);

        Mail::assertSent(OrganizationInvitationMail::class, fn ($mail) => $mail->hasTo($invitation->email));
        $this->assertTrue($invitation->fresh()->expires_at->isAfter(now()->addDays(6)));
    }

    public function test_owner_can_change_another_members_role(): void
    {
        $sender = $this->attachUser(OrganizationRole::Sender);

        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::team.index')
            ->call('updateRole', $sender->id, 'admin');

        $this->assertSame('admin', $this->organization->users()->find($sender->id)->pivot->role->value);
    }

    public function test_the_last_owner_cannot_be_demoted(): void
    {
        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::team.index')
            ->call('updateRole', $this->owner->id, 'sender');

        $this->assertSame(OrganizationRole::Owner, $this->organization->users()->find($this->owner->id)->pivot->role);
    }

    public function test_the_last_owner_cannot_be_removed(): void
    {
        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::team.index')
            ->call('removeMember', $this->owner->id);

        $this->assertTrue($this->organization->users()->where('users.id', $this->owner->id)->exists());
    }

    public function test_an_owner_can_be_demoted_if_another_owner_exists(): void
    {
        $secondOwner = $this->attachUser(OrganizationRole::Owner);

        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::team.index')
            ->call('updateRole', $secondOwner->id, 'admin');

        $this->assertSame('admin', $this->organization->users()->find($secondOwner->id)->pivot->role->value);
    }

    public function test_removing_a_member_clears_their_current_organization_if_it_was_this_one(): void
    {
        $sender = $this->attachUser(OrganizationRole::Sender);
        $sender->forceFill(['current_organization_id' => $this->organization->id])->save();

        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::team.index')
            ->call('removeMember', $sender->id);

        $this->assertNull($sender->fresh()->current_organization_id);
        $this->assertFalse($this->organization->users()->where('users.id', $sender->id)->exists());
    }

    public function test_pending_invitations_never_leak_across_organizations(): void
    {
        $otherOrg = Organization::factory()->create();
        OrganizationInvitation::factory()->for($otherOrg, 'organization')->create(['email' => 'other-org@example.com']);
        OrganizationInvitation::factory()->for($this->organization, 'organization')->create(['email' => 'this-org@example.com']);

        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::team.index')
            ->assertSee('this-org@example.com')
            ->assertDontSee('other-org@example.com');
    }
}
