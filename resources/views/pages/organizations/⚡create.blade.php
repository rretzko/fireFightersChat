<?php

use App\Enums\OrganizationRole;
use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Create your organization')] class extends Component {
    public string $name = '';

    /**
     * Create the organization, make the current user its owner, and make it
     * their active tenant. This is the only way an organization comes into
     * existence in the prototype — there's no separate admin-only path yet.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        $user = Auth::user();

        $organization = DB::transaction(function () use ($validated, $user): Organization {
            $organization = Organization::create([
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['name']),
            ]);

            $organization->users()->attach($user->id, [
                'role' => OrganizationRole::Owner,
                'joined_at' => now(),
            ]);

            $user->forceFill(['current_organization_id' => $organization->id])->save();

            AuditLog::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'action' => 'organization.created',
            ]);

            return $organization;
        });

        $this->redirect(route('dashboard'), navigate: true);
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $suffix = 1;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}; ?>

<div class="flex w-full max-w-sm flex-col gap-6">
    <div>
        <flux:heading size="lg">{{ __('Create your organization') }}</flux:heading>
        <flux:subheading>{{ __('Set up your department so you can start managing members and sending messages.') }}</flux:subheading>
    </div>

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:input
            wire:model="name"
            :label="__('Organization name')"
            type="text"
            required
            autofocus
            :placeholder="__('e.g. Example Volunteer Fire Department')"
        />

        <flux:button type="submit" variant="primary" class="w-full">
            {{ __('Create organization') }}
        </flux:button>
    </form>
</div>
