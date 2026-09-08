<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\OrganizationInvitation;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Reachable outside the tenant middleware group on purpose: accepting an
 * invitation is how a user JOINS a new organization, so there's no current
 * tenant to bind yet (and the invitation might be for a different org than
 * whichever one the user is already in). Every query below bypasses
 * OrganizationScope for that reason and filters explicitly instead.
 */
new #[Title('Accept invitation')] class extends Component {
    #[Locked]
    public int $invitationId;

    public function mount(string $token): void
    {
        $invitation = OrganizationInvitation::withoutGlobalScope(OrganizationScope::class)
            ->where('token', $token)
            ->first();

        abort_if($invitation === null, 404);

        $this->invitationId = $invitation->id;
    }

    #[Computed]
    public function invitation(): OrganizationInvitation
    {
        return OrganizationInvitation::withoutGlobalScope(OrganizationScope::class)
            ->with(['organization', 'invitedBy'])
            ->findOrFail($this->invitationId);
    }

    #[Computed]
    public function emailMatches(): bool
    {
        return strcasecmp(Auth::user()->email, $this->invitation->email) === 0;
    }

    #[Computed]
    public function alreadyAMember(): bool
    {
        return $this->invitation->organization->users()
            ->where('users.id', Auth::id())
            ->exists();
    }

    public function accept(): void
    {
        $invitation = $this->invitation;

        abort_if($invitation->accepted_at !== null, 404);
        abort_if($invitation->isExpired(), 404);
        abort_unless($this->emailMatches, 403);

        $user = Auth::user();

        // Not $invitation->update(...) anywhere below: save()/update() on an
        // already-loaded model go through newModelQuery(), which
        // RE-APPLIES OrganizationScope even though this page's queries
        // deliberately bypass it (no tenant is bound here — see class
        // docblock). With no tenant bound, that write would silently match
        // zero rows and no-op, leaving accepted_at null forever. Verified
        // by a failing test before this fix: the invitation's own
        // organization/role attach worked, but accepted_at silently never
        // got set. Query-builder updates bypass the scope for the write too.
        if ($this->alreadyAMember) {
            OrganizationInvitation::withoutGlobalScope(OrganizationScope::class)
                ->whereKey($invitation->id)
                ->update(['accepted_at' => now()]);

            $this->redirect(route('dashboard'), navigate: true);

            return;
        }

        DB::transaction(function () use ($invitation, $user): void {
            $invitation->organization->users()->attach($user->id, [
                'role' => $invitation->role,
                'joined_at' => now(),
            ]);

            $user->forceFill(['current_organization_id' => $invitation->organization_id])->save();

            OrganizationInvitation::withoutGlobalScope(OrganizationScope::class)
                ->whereKey($invitation->id)
                ->update(['accepted_at' => now()]);

            AuditLog::create([
                'organization_id' => $invitation->organization_id,
                'user_id' => $user->id,
                'action' => 'invitation.accepted',
                'subject_type' => $invitation->getMorphClass(),
                'subject_id' => $invitation->id,
                'meta' => ['role' => $invitation->role->value],
            ]);
        });

        $this->redirect(route('dashboard'), navigate: true);
    }
}; ?>

<div class="flex w-full max-w-sm flex-col gap-6">
    @if ($this->invitation->accepted_at !== null)
        <flux:callout color="zinc" :heading="__('This invitation has already been used.')" />
    @elseif ($this->invitation->isExpired())
        <flux:callout color="red" :heading="__('This invitation has expired.')" :text="__('Ask :name for a new one.', ['name' => $this->invitation->invitedBy?->name ?? __('the person who invited you')])" />
    @elseif (! $this->emailMatches)
        <flux:callout color="yellow" :heading="__('This invitation was sent to a different email address.')">
            {{ __('You\'re signed in as :current, but this invitation was sent to :invited.', ['current' => auth()->user()->email, 'invited' => $this->invitation->email]) }}
        </flux:callout>
    @else
        <div>
            <flux:heading size="lg">{{ __('You\'re invited!') }}</flux:heading>
            <flux:subheading>
                {{ __(':name invited you to join :org as :role.', [
                    'name' => $this->invitation->invitedBy?->name ?? __('A team member'),
                    'org' => $this->invitation->organization->name,
                    'role' => $this->invitation->role->value,
                ]) }}
            </flux:subheading>
        </div>

        <flux:button variant="primary" wire:click="accept" class="w-full">
            {{ __('Accept invitation') }}
        </flux:button>
    @endif
</div>
