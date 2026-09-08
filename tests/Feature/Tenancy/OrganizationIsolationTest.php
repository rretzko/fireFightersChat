<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\OrganizationRole;
use App\Models\Broadcast;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The business plan leans on Postgres Row-Level Security as a database-level
 * safety net; Laravel/SQLite has no equivalent, so BelongsToOrganization +
 * OrganizationScope have to carry that weight in the application layer
 * instead. These tests exist specifically to catch a regression that would
 * otherwise leak one organization's data into another's view.
 */
class OrganizationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $userA;

    private User $userB;

    private Member $memberA;

    private Member $memberB;

    private Broadcast $broadcastA;

    private Broadcast $broadcastB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();

        $this->orgA->users()->attach($this->userA, ['role' => OrganizationRole::Owner, 'joined_at' => now()]);
        $this->orgB->users()->attach($this->userB, ['role' => OrganizationRole::Owner, 'joined_at' => now()]);

        $this->memberA = Member::factory()->for($this->orgA, 'organization')->create();
        $this->memberB = Member::factory()->for($this->orgB, 'organization')->create();

        $this->broadcastA = Broadcast::factory()->for($this->orgA, 'organization')->for($this->userA, 'sender')->create();
        $this->broadcastB = Broadcast::factory()->for($this->orgB, 'organization')->for($this->userB, 'sender')->create();
    }

    protected function tearDown(): void
    {
        Tenant::set(null);

        parent::tearDown();
    }

    public function test_member_queries_are_scoped_to_the_current_tenant(): void
    {
        Tenant::set($this->orgA);

        $members = Member::all();

        $this->assertTrue($members->contains($this->memberA));
        $this->assertFalse($members->contains($this->memberB));
    }

    public function test_broadcast_queries_are_scoped_to_the_current_tenant(): void
    {
        Tenant::set($this->orgB);

        $broadcasts = Broadcast::all();

        $this->assertTrue($broadcasts->contains($this->broadcastB));
        $this->assertFalse($broadcasts->contains($this->broadcastA));
    }

    public function test_a_member_from_another_organization_cannot_be_found_by_id(): void
    {
        Tenant::set($this->orgA);

        $this->assertNull(Member::find($this->memberB->id));
    }

    public function test_queries_return_nothing_when_no_tenant_is_bound(): void
    {
        // The scope fails closed: with no tenant bound (e.g. a console
        // command or job that forgot to set one), tenant-scoped queries see
        // nothing rather than leaking every organization's data. Legitimate
        // cross-tenant access must bind a tenant explicitly or opt out via
        // withoutGlobalScope(OrganizationScope::class).
        Tenant::set(null);

        $this->assertTrue(Member::all()->isEmpty());
    }

    public function test_new_records_are_automatically_stamped_with_the_current_tenant(): void
    {
        Tenant::set($this->orgA);

        $member = Member::create([
            'first_name' => 'Auto',
            'last_name' => 'Scoped',
            'phone_number' => '+15555550100',
        ]);

        $this->assertSame($this->orgA->id, $member->organization_id);
    }

    public function test_policy_denies_viewing_a_member_from_another_organization(): void
    {
        $this->assertTrue($this->userB->can('view', $this->memberB));
        $this->assertFalse($this->userA->can('view', $this->memberB));
    }

    public function test_member_viewany_policy_is_scoped_to_the_current_tenant(): void
    {
        Tenant::set($this->orgA);
        $this->assertTrue($this->userA->can('viewAny', Member::class));

        Tenant::set($this->orgB);
        $this->assertFalse($this->userA->can('viewAny', Member::class));
    }
}
