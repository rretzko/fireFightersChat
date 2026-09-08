<?php

declare(strict_types=1);

namespace Tests\Feature\Broadcasts;

use App\Actions\SendBroadcast;
use App\Enums\OrganizationRole;
use App\Models\Broadcast;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\BroadcastRateLimiter;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BroadcastComposeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
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

    public function test_any_org_role_can_view_the_broadcasts_page(): void
    {
        $sender = $this->attachUser(OrganizationRole::Sender);

        $this->actingAs($sender)
            ->get(route('broadcasts.index'))
            ->assertOk();
    }

    public function test_a_sender_can_compose_and_send_a_broadcast(): void
    {
        $sender = $this->attachUser(OrganizationRole::Sender);
        Member::factory()->for($this->organization, 'organization')->count(2)->create();

        Tenant::set($this->organization);
        $this->actingAs($sender);

        Livewire::test('pages::broadcasts.index')
            ->set('body', 'Muster at the station, 6pm.')
            ->call('send')
            ->assertHasNoErrors();

        $broadcast = Broadcast::sole();
        $this->assertSame('Muster at the station, 6pm.', $broadcast->body);
        $this->assertSame($sender->id, $broadcast->sender_user_id);
    }

    public function test_an_empty_body_is_rejected(): void
    {
        $sender = $this->attachUser(OrganizationRole::Sender);

        Tenant::set($this->organization);
        $this->actingAs($sender);

        Livewire::test('pages::broadcasts.index')
            ->set('body', '')
            ->call('send')
            ->assertHasErrors(['body']);

        $this->assertSame(0, Broadcast::count());
    }

    public function test_sending_with_no_active_members_shows_an_error_and_creates_no_broadcast(): void
    {
        $sender = $this->attachUser(OrganizationRole::Sender);

        Tenant::set($this->organization);
        $this->actingAs($sender);

        Livewire::test('pages::broadcasts.index')
            ->set('body', 'Anybody out there?')
            ->call('send')
            ->assertHasErrors(['body']);

        $this->assertSame(0, Broadcast::count());
    }

    public function test_exceeding_the_per_user_rate_limit_blocks_sending(): void
    {
        $sender = $this->attachUser(OrganizationRole::Sender);
        Member::factory()->for($this->organization, 'organization')->create();

        Tenant::set($this->organization);
        $this->actingAs($sender);

        for ($i = 0; $i < config('sms.rate_limits.broadcasts_per_user_per_minute'); $i++) {
            BroadcastRateLimiter::hit($sender, $this->organization);
        }

        Livewire::test('pages::broadcasts.index')
            ->set('body', 'One too many')
            ->call('send')
            ->assertHasErrors(['body']);

        $this->assertSame(0, Broadcast::count());
    }

    public function test_broadcasts_from_other_organizations_never_appear_in_the_list(): void
    {
        $owner = $this->attachUser(OrganizationRole::Owner);

        $otherOrg = Organization::factory()->create();
        $otherSender = User::factory()->create();
        $otherOrg->users()->attach($otherSender, ['role' => OrganizationRole::Owner, 'joined_at' => now()]);
        Member::factory()->for($otherOrg, 'organization')->create();

        app(SendBroadcast::class)($otherOrg, $otherSender, 'Not for org A');

        Tenant::set($this->organization);
        $this->actingAs($owner);

        Livewire::test('pages::broadcasts.index')
            ->assertDontSee('Not for org A');
    }
}
