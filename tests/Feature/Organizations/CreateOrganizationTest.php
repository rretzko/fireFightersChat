<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CreateOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_an_organization_and_becomes_its_owner(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::organizations.create')
            ->set('name', 'Example Volunteer Fire Department')
            ->call('save')
            ->assertRedirect(route('dashboard'));

        $organization = Organization::sole();

        $this->assertSame('Example Volunteer Fire Department', $organization->name);
        $this->assertNotEmpty($organization->slug);

        $user->refresh();

        $this->assertSame($organization->id, $user->current_organization_id);
        $this->assertSame(OrganizationRole::Owner, $user->roleIn($organization));
    }

    public function test_organization_name_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::organizations.create')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);

        $this->assertSame(0, Organization::count());
    }

    public function test_duplicate_organization_names_get_distinct_slugs(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->actingAs($userA);
        Livewire::test('pages::organizations.create')
            ->set('name', 'Example Volunteer Fire Department')
            ->call('save');

        $this->actingAs($userB);
        Livewire::test('pages::organizations.create')
            ->set('name', 'Example Volunteer Fire Department')
            ->call('save');

        $slugs = Organization::pluck('slug');

        $this->assertCount(2, $slugs);
        $this->assertSame($slugs->count(), $slugs->unique()->count());
    }
}
