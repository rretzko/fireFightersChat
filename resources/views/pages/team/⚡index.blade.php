<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\Tenant;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Team')] class extends Component {
    public bool $showInviteModal = false;

    public string $inviteEmail = '';

    public string $inviteRole = 'sender';

    public function mount(): void
    {
        Gate::authorize('manageTeam', $this->organization);
    }

    #[Computed]
    public function organization(): Organization
    {
        $organization = Tenant::get();

        abort_if($organization === null, 403);

        return $organization;
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    #[Computed]
    public function teamMembers()
    {
        return $this->organization->users()->orderBy('organization_user.joined_at')->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, OrganizationInvitation>
     */
    #[Computed]
    public function pendingInvitations()
    {
        return OrganizationInvitation::whereNull('accepted_at')->latest()->get();
    }

    #[Computed]
    public function ownerCount(): int
    {
        return $this->teamMembers->filter(fn (User $user) => $user->pivot->role === OrganizationRole::Owner)->count();
    }

    public function openInviteModal(): void
    {
        Gate::authorize('manageTeam', $this->organization);

        $this->inviteEmail = '';
        $this->inviteRole = 'sender';
        $this->resetErrorBag(['inviteEmail', 'inviteRole']);
        $this->showInviteModal = true;
    }

    public function closeInviteModal(): void
    {
        $this->showInviteModal = false;
    }

    public function invite(): void
    {
        Gate::authorize('manageTeam', $this->organization);

        $validated = $this->validate([
            'inviteEmail' => ['required', 'email', 'max:255'],
            'inviteRole' => ['required', Rule::in(['admin', 'sender'])],
        ]);

        $alreadyMember = $this->organization->users()
            ->where('email', $validated['inviteEmail'])
            ->exists();

        if ($alreadyMember) {
            $this->addError('inviteEmail', __('This person is already on the team.'));

            return;
        }

        // Re-inviting replaces any prior pending invitation for this email.
        OrganizationInvitation::where('organization_id', $this->organization->id)
            ->where('email', $validated['inviteEmail'])
            ->whereNull('accepted_at')
            ->delete();

        $invitation = OrganizationInvitation::create([
            'organization_id' => $this->organization->id,
            'email' => $validated['inviteEmail'],
            'role' => $validated['inviteRole'],
            'invited_by_user_id' => Auth::id(),
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($invitation->email)->send(new OrganizationInvitationMail($invitation));

        AuditLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => Auth::id(),
            'action' => 'team.invited',
            'subject_type' => $invitation->getMorphClass(),
            'subject_id' => $invitation->id,
            'meta' => ['email' => $invitation->email, 'role' => $invitation->role->value],
        ]);

        $this->showInviteModal = false;
        unset($this->pendingInvitations);

        Flux::toast(variant: 'success', text: __('Invitation sent to :email.', ['email' => $invitation->email]));
    }

    public function resendInvitation(int $invitationId): void
    {
        Gate::authorize('manageTeam', $this->organization);

        $invitation = OrganizationInvitation::findOrFail($invitationId);

        $invitation->update(['expires_at' => now()->addDays(7)]);

        Mail::to($invitation->email)->send(new OrganizationInvitationMail($invitation));

        AuditLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => Auth::id(),
            'action' => 'team.invitation_resent',
            'subject_type' => $invitation->getMorphClass(),
            'subject_id' => $invitation->id,
        ]);

        Flux::toast(variant: 'success', text: __('Invitation resent.'));
    }

    public function cancelInvitation(int $invitationId): void
    {
        Gate::authorize('manageTeam', $this->organization);

        $invitation = OrganizationInvitation::findOrFail($invitationId);
        $invitation->delete();

        AuditLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => Auth::id(),
            'action' => 'team.invitation_cancelled',
            'meta' => ['email' => $invitation->email],
        ]);

        unset($this->pendingInvitations);
    }

    public function updateRole(int $userId, string $role): void
    {
        Gate::authorize('manageTeam', $this->organization);

        if (! in_array($role, array_map(fn (OrganizationRole $r) => $r->value, OrganizationRole::cases()), true)) {
            return;
        }

        if ($this->isLastOwner($userId) && $role !== OrganizationRole::Owner->value) {
            Flux::toast(variant: 'danger', text: __('An organization must have at least one owner.'));

            return;
        }

        $this->organization->users()->updateExistingPivot($userId, ['role' => $role]);

        AuditLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => Auth::id(),
            'action' => 'team.role_changed',
            'meta' => ['target_user_id' => $userId, 'role' => $role],
        ]);

        unset($this->teamMembers);

        Flux::toast(variant: 'success', text: __('Role updated.'));
    }

    public function removeMember(int $userId): void
    {
        Gate::authorize('manageTeam', $this->organization);

        if ($this->isLastOwner($userId)) {
            Flux::toast(variant: 'danger', text: __('An organization must have at least one owner.'));

            return;
        }

        $this->organization->users()->detach($userId);

        User::where('id', $userId)
            ->where('current_organization_id', $this->organization->id)
            ->update(['current_organization_id' => null]);

        AuditLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => Auth::id(),
            'action' => 'team.member_removed',
            'meta' => ['target_user_id' => $userId],
        ]);

        unset($this->teamMembers);

        Flux::toast(variant: 'success', text: __('Member removed.'));
    }

    protected function isLastOwner(int $userId): bool
    {
        $member = $this->teamMembers->firstWhere('id', $userId);

        return $member !== null
            && $member->pivot->role === OrganizationRole::Owner
            && $this->ownerCount <= 1;
    }
}; ?>

<div>
<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="lg">{{ __('Team') }}</flux:heading>
            <flux:subheading>{{ __('Manage who can log in and what they can do in :org.', ['org' => $this->organization->name]) }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="user-plus" wire:click="openInviteModal">
            {{ __('Invite member') }}
        </flux:button>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Contact phone') }}</flux:table.column>
            <flux:table.column>{{ __('Role') }}</flux:table.column>
            <flux:table.column>{{ __('Actions') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->teamMembers as $member)
                <flux:table.row :key="$member->id">
                    <flux:table.cell variant="strong">
                        {{ $member->name }}
                        @if ($member->id === auth()->id())
                            <flux:badge size="sm" color="zinc">{{ __('You') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $member->email }}</flux:table.cell>
                    <flux:table.cell>{{ $member->formattedContactPhoneNumber() ?? '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="match ($member->pivot->role) {
                            OrganizationRole::Owner => 'purple',
                            OrganizationRole::Admin => 'blue',
                            OrganizationRole::Sender => 'zinc',
                        }">
                            {{ $member->pivot->role->value }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:dropdown position="bottom" align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" :aria-label="__('Actions')" />

                            <flux:menu>
                                @foreach (OrganizationRole::cases() as $role)
                                    @if ($member->pivot->role !== $role)
                                        <flux:menu.item wire:click="updateRole({{ $member->id }}, '{{ $role->value }}')">
                                            {{ __('Make :role', ['role' => $role->value]) }}
                                        </flux:menu.item>
                                    @endif
                                @endforeach

                                <flux:menu.separator />

                                <flux:menu.item variant="danger" wire:click="removeMember({{ $member->id }})">
                                    {{ __('Remove from team') }}
                                </flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    @if ($this->pendingInvitations->isNotEmpty())
        <div>
            <flux:heading size="md">{{ __('Pending invitations') }}</flux:heading>

            <flux:table class="mt-2">
                <flux:table.columns>
                    <flux:table.column>{{ __('Email') }}</flux:table.column>
                    <flux:table.column>{{ __('Role') }}</flux:table.column>
                    <flux:table.column>{{ __('Invited') }}</flux:table.column>
                    <flux:table.column>{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->pendingInvitations as $invitation)
                        <flux:table.row :key="$invitation->id">
                            <flux:table.cell>{{ $invitation->email }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" color="zinc">{{ $invitation->role->value }}</flux:badge>
                                @if ($invitation->isExpired())
                                    <flux:badge size="sm" color="red">{{ __('Expired') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $invitation->created_at?->diffForHumans() }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center gap-1">
                                    <flux:button variant="ghost" size="sm" icon="arrow-path" wire:click="resendInvitation({{ $invitation->id }})" :aria-label="__('Resend')" />
                                    <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="cancelInvitation({{ $invitation->id }})" :aria-label="__('Cancel')" />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>

<flux:modal name="invite-member" wire:model="showInviteModal" @close="closeInviteModal" class="max-w-md">
    <form wire:submit="invite" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Invite a team member') }}</flux:heading>
            <flux:subheading>{{ __('They\'ll get an email with a link to join.') }}</flux:subheading>
        </div>

        <flux:input wire:model="inviteEmail" :label="__('Email address')" type="email" required autofocus placeholder="name@example.com" />

        <flux:select wire:model="inviteRole" :label="__('Role')">
            <flux:select.option value="sender">{{ __('Sender — can send broadcasts') }}</flux:select.option>
            <flux:select.option value="admin">{{ __('Admin — can also manage members and the team') }}</flux:select.option>
        </flux:select>

        <div class="flex justify-end gap-3">
            <flux:button type="button" variant="outline" wire:click="closeInviteModal">
                {{ __('Cancel') }}
            </flux:button>
            <flux:button type="submit" variant="primary">
                {{ __('Send invite') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
</div>
