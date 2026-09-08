<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Database-query-level tenant boundary. This is the closest Laravel equivalent
 * to the Postgres Row-Level Security safety net described in the business plan:
 * even a query that forgets to filter by organization still can't cross tenants.
 *
 * Fails closed: if no tenant is bound (e.g. a console command or job that
 * forgot to set one), the query returns nothing rather than every
 * organization's data. Code that legitimately needs cross-tenant access
 * (an admin report, a per-tenant queue worker looping over all orgs) must
 * bind a tenant explicitly or opt out via withoutGlobalScope(self::class).
 *
 * @implements Scope<Model>
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenant::check()) {
            $builder->where($model->qualifyColumn('organization_id'), Tenant::id());

            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
