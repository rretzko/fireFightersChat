<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Enums\MemberStatus;
use App\Enums\OrganizationRole;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MemberRosterTest extends TestCase
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

    public function test_owner_can_view_the_members_page(): void
    {
        $this->actingAs($this->owner)
            ->get(route('members.index'))
            ->assertOk();
    }

    public function test_sender_role_cannot_view_the_members_page(): void
    {
        $sender = User::factory()->create();
        $this->organization->users()->attach($sender, [
            'role' => OrganizationRole::Sender,
            'joined_at' => now(),
        ]);

        $this->actingAs($sender)
            ->get(route('members.index'))
            ->assertForbidden();
    }

    public function test_owner_can_add_a_member(): void
    {
        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::members.index')
            ->set('first_name', 'Jane')
            ->set('last_name', 'Doe')
            ->set('phone_number', '(508) 555-0100')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('members', [
            'organization_id' => $this->organization->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone_number' => '+15085550100',
        ]);
    }

    public function test_invalid_phone_number_is_rejected(): void
    {
        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::members.index')
            ->set('first_name', 'Jane')
            ->set('phone_number', '123')
            ->call('save')
            ->assertHasErrors(['phone_number']);

        $this->assertSame(0, Member::count());
    }

    public function test_duplicate_phone_number_within_the_same_organization_is_rejected(): void
    {
        Member::factory()->for($this->organization, 'organization')->create(['phone_number' => '+15085550100']);

        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::members.index')
            ->set('first_name', 'Jane')
            ->set('phone_number', '508-555-0100')
            ->call('save')
            ->assertHasErrors(['phone_number']);

        $this->assertSame(1, Member::count());
    }

    public function test_owner_can_edit_a_member(): void
    {
        $member = Member::factory()->for($this->organization, 'organization')->create([
            'first_name' => 'Old',
            'phone_number' => '+15085550100',
        ]);

        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::members.index')
            ->call('editMember', $member->id)
            ->assertSet('first_name', 'Old')
            ->assertSet('phone_number', '+1 (508) 555-0100')
            ->set('first_name', 'New')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New', $member->fresh()->first_name);
        $this->assertSame('+15085550100', $member->fresh()->phone_number);
    }

    public function test_the_roster_table_shows_formatted_phone_numbers_not_raw_e164(): void
    {
        Member::factory()->for($this->organization, 'organization')->create(['phone_number' => '+15085550100']);

        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::members.index')
            ->assertSee('+1 (508) 555-0100')
            ->assertDontSee('+15085550100');
    }

    public function test_owner_can_toggle_a_member_opted_out_and_back(): void
    {
        $member = Member::factory()->for($this->organization, 'organization')->create();

        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        $component = Livewire::test('pages::members.index');

        $component->call('toggleOptOut', $member->id);
        $this->assertSame(MemberStatus::OptedOut, $member->fresh()->status);

        $component->call('toggleOptOut', $member->id);
        $this->assertSame(MemberStatus::Active, $member->fresh()->status);
    }

    public function test_members_from_other_organizations_are_never_shown_or_editable(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherMember = Member::factory()->for($otherOrg, 'organization')->create(['first_name' => 'Unique Othername']);

        Tenant::set($this->organization);
        $this->actingAs($this->owner);

        Livewire::test('pages::members.index')
            ->assertDontSee($otherMember->fullName())
            ->assertDontSee($otherMember->formattedPhoneNumber());

        Tenant::set($this->organization);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test('pages::members.index')
            ->call('editMember', $otherMember->id);
    }
}
