<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\TestCase;

/**
 * Regression test for a real production bug: Livewire only replays a
 * hardcoded allowlist of middleware (see
 * Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware) for its AJAX
 * component-update requests. The initial page load runs the full route
 * middleware stack, but a subsequent wire:click/wire:submit call does NOT,
 * unless the middleware is explicitly registered via
 * Livewire::addPersistentMiddleware() (see AppServiceProvider::boot()).
 *
 * Without that registration, IdentifyTenant never runs for those requests,
 * Tenant::get() is null, and every tenant-scoped policy check (create a
 * member, send a broadcast, ...) fails even for a legitimate org owner —
 * which is exactly what a real user hit: sidebar links visible (they render
 * during a normal page load), but every button click 403'd.
 *
 * Livewire::test() cannot catch this class of bug: it calls
 * withoutMiddleware() internally (see
 * Livewire\Features\SupportTesting\RequestBroker), so it never exercises
 * this mechanism at all — every test using it must already assume a tenant
 * is bound. This test instead replays a genuine Livewire AJAX round-trip
 * through the real HTTP kernel: it extracts an actually-rendered snapshot
 * the same way the browser's JS client would, then POSTs a real
 * component-update request to Livewire's real update endpoint.
 */
class LivewirePersistentMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_livewire_action_call_can_authorize_using_the_tenant_bound_by_custom_middleware(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => OrganizationRole::Owner, 'joined_at' => now()]);

        $this->actingAs($owner);

        $page = $this->get(route('members.index'));
        $page->assertOk();

        $snapshot = $this->extractSnapshot($page->getContent());

        // Laravel's test client reuses the same application container (and
        // therefore the same TenantContext singleton) across every request
        // made within one test method — it does NOT reset between $this->get()
        // and $this->postJson() the way two real, separate requests would.
        // Without this reset, the tenant bound by the GET above would still
        // be sitting in the container for the AJAX call below regardless of
        // whether PersistentMiddleware actually replayed IdentifyTenant for
        // it, making this test pass unconditionally — verified: it did,
        // even with the persistent-middleware registration commented out.
        Tenant::set(null);

        // Simulating the real JS client's AJAX call, not a form post — CSRF
        // is a separate, already-solved concern (Livewire's JS reads it from
        // the XSRF-TOKEN cookie); disabling it here isolates the thing this
        // test actually cares about, which is whether IdentifyTenant runs.
        $response = $this->withoutMiddleware(PreventRequestForgery::class)
            ->withHeaders(['X-Livewire' => 'true'])
            ->postJson(EndpointResolver::updatePath(), [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [
                        ['path' => '', 'method' => 'addMember', 'params' => []],
                    ],
                ]],
            ]);

        $response->assertOk();

        $updatedSnapshot = json_decode(
            $response->json('components.0.snapshot'),
            associative: true,
        );

        // addMember() only sets this after Gate::authorize('create', ...)
        // passes — if IdentifyTenant didn't run, that authorize() call
        // throws and this response would be an error, not a snapshot with
        // the modal opened.
        $this->assertTrue($updatedSnapshot['data']['showMemberModal']);
    }

    private function extractSnapshot(string $html): string
    {
        preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);

        return html_entity_decode($matches[1], ENT_QUOTES);
    }
}
