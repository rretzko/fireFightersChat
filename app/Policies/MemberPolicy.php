<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Member;
use App\Models\User;
use App\Support\Tenant;

/**
 * The business plan is explicit that composing members don't see "a visible
 * roster of everyone else's number" — so roster management (view/create/
 * update/delete) is restricted to owner/admin roles, not every sender.
 */
class MemberPolicy
{
    public function viewAny(User $user): bool
    {
        $organization = Tenant::get();

        return $organization !== null && $user->hasAdministrativeRoleIn($organization);
    }

    public function view(User $user, Member $member): bool
    {
        return $user->hasAdministrativeRoleIn($member->organization);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Member $member): bool
    {
        return $user->hasAdministrativeRoleIn($member->organization);
    }

    public function delete(User $user, Member $member): bool
    {
        return $user->hasAdministrativeRoleIn($member->organization);
    }
}
