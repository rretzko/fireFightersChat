<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Organization;

/**
 * Holds the active organization for the current request. Bound as a singleton
 * so the identity set by IdentifyTenant is visible to the OrganizationScope
 * and anywhere else that needs to know "which tenant am I in right now".
 */
class TenantContext
{
    protected ?Organization $organization = null;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function check(): bool
    {
        return $this->organization !== null;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }
}
