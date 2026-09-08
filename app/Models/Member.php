<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Enums\MemberStatus;
use App\Support\PhoneNumberNormalizer;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $first_name
 * @property string|null $last_name
 * @property string $phone_number
 * @property MemberStatus $status
 * @property Carbon|null $opted_out_at
 */
#[Fillable(['organization_id', 'first_name', 'last_name', 'phone_number', 'status', 'opted_out_at'])]
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MemberStatus::class,
            'opted_out_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<InboundMessage, $this>
     */
    public function inboundMessages(): HasMany
    {
        return $this->hasMany(InboundMessage::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function formattedPhoneNumber(): string
    {
        return PhoneNumberNormalizer::format($this->phone_number);
    }

    public function isActive(): bool
    {
        return $this->status === MemberStatus::Active;
    }

    public function optOut(): void
    {
        $this->forceFill([
            'status' => MemberStatus::OptedOut,
            'opted_out_at' => now(),
        ])->save();
    }

    public function optIn(): void
    {
        $this->forceFill([
            'status' => MemberStatus::Active,
            'opted_out_at' => null,
        ])->save();
    }
}
