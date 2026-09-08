<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\OrganizationRole;
use App\Support\PhoneNumberNormalizer;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property int|null $current_organization_id
 * @property string $name
 * @property string $email
 * @property string|null $contact_phone_number Directory info for the login account — not members.phone_number
 *                                             (the tenant-scoped SMS roster destination). Named differently on purpose so the two are never confused.
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'contact_phone_number', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    public function formattedContactPhoneNumber(): ?string
    {
        return $this->contact_phone_number !== null
            ? PhoneNumberNormalizer::format($this->contact_phone_number)
            : null;
    }

    /**
     * @return BelongsToMany<Organization, $this, OrganizationUser, 'pivot'>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->using(OrganizationUser::class)
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    /**
     * Work out which organization should be active for this request: an
     * explicitly requested one (if the user actually belongs to it), then
     * their last-known current organization, then whichever org they joined
     * first. Returns null only if the user has no organizations at all.
     */
    public function resolveCurrentOrganization(?int $preferredOrganizationId = null): ?Organization
    {
        $organizations = $this->organizations()->orderBy('organization_user.joined_at')->get();

        if ($preferredOrganizationId !== null) {
            $preferred = $organizations->firstWhere('id', $preferredOrganizationId);

            if ($preferred !== null) {
                return $preferred;
            }
        }

        if ($this->current_organization_id !== null) {
            $current = $organizations->firstWhere('id', $this->current_organization_id);

            if ($current !== null) {
                return $current;
            }
        }

        return $organizations->first();
    }

    public function roleIn(Organization $organization): ?OrganizationRole
    {
        $membership = $this->organizations->firstWhere('id', $organization->id);

        return $membership?->pivot->role;
    }

    public function belongsToOrganization(Organization $organization): bool
    {
        return $this->roleIn($organization) !== null;
    }

    public function hasAdministrativeRoleIn(Organization $organization): bool
    {
        return $this->roleIn($organization)?->isAdministrative() ?? false;
    }
}
