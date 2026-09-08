<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_phone_number_can_be_set_and_is_normalized_to_e164(): void
    {
        $user = User::factory()->create(['contact_phone_number' => null]);

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('name', $user->name)
            ->set('email', $user->email)
            ->set('contact_phone_number', '(508) 555-0100')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('+15085550100', $user->fresh()->contact_phone_number);
    }

    public function test_phone_number_is_optional_and_can_be_cleared(): void
    {
        $user = User::factory()->create(['contact_phone_number' => '+15085550100']);

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('name', $user->name)
            ->set('email', $user->email)
            ->set('contact_phone_number', '')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertNull($user->fresh()->contact_phone_number);
    }

    public function test_invalid_phone_number_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('name', $user->name)
            ->set('email', $user->email)
            ->set('contact_phone_number', '123')
            ->call('updateProfileInformation')
            ->assertHasErrors(['contact_phone_number']);
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser');

        $response
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertFalse(auth()->check());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $response->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }
}
