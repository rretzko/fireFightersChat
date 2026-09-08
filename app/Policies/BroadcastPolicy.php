<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Broadcast;
use App\Models\User;
use App\Support\Tenant;

/**
 * Any authorized member (owner/admin/sender) can compose and view broadcasts
 * within their own organization — this is the core "unlimited senders, no
 * per-seat fee" requirement from the business plan.
 */
class BroadcastPolicy
{
    public function viewAny(User $user): bool
    {
        $organization = Tenant::get();

        return $organization !== null && $user->belongsToOrganization($organization);
    }

    public function view(User $user, Broadcast $broadcast): bool
    {
        return $user->belongsToOrganization($broadcast->organization);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }
}
