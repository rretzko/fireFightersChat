<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    /**
     * Renaming the org, changing its Twilio/A2P configuration, etc.
     */
    public function update(User $user, Organization $organization): bool
    {
        return $user->hasAdministrativeRoleIn($organization);
    }

    /**
     * Inviting/removing team members and changing their roles.
     */
    public function manageTeam(User $user, Organization $organization): bool
    {
        return $user->hasAdministrativeRoleIn($organization);
    }
}
