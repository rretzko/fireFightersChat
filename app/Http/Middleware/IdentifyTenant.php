<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active organization for the authenticated user and binds it
 * into the TenantContext singleton for the rest of the request. Every
 * tenant-scoped model query is then automatically filtered by it (see
 * BelongsToOrganization / OrganizationScope).
 *
 * Users with no organization yet are redirected to create one, since almost
 * nothing in the app makes sense without a tenant selected.
 */
class IdentifyTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        /** @var mixed $sessionOrganizationId */
        $sessionOrganizationId = $request->session()->get('current_organization_id');

        $organization = $user->resolveCurrentOrganization(
            is_numeric($sessionOrganizationId) ? (int) $sessionOrganizationId : null,
        );

        if ($organization === null) {
            if ($request->routeIs('organizations.create')) {
                return $next($request);
            }

            return redirect()->route('organizations.create');
        }

        if ($user->current_organization_id !== $organization->id) {
            $user->forceFill(['current_organization_id' => $organization->id])->save();
        }

        $request->session()->put('current_organization_id', $organization->id);
        Tenant::set($organization);

        return $next($request);
    }
}
