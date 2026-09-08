<?php

declare(strict_types=1);

use App\Actions\SendBroadcast;
use App\Enums\MemberStatus;
use App\Models\Broadcast;
use App\Models\Member;
use App\Support\BroadcastRateLimiter;
use App\Support\Tenant;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Broadcasts')] class extends Component {
    use WithPagination;

    public bool $showComposeModal = false;

    public string $body = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Broadcast::class);
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Broadcast>
     */
    #[Computed]
    public function broadcasts()
    {
        return Broadcast::query()
            ->withCount('messages')
            ->with('sender')
            ->latest()
            ->paginate(10);
    }

    #[Computed]
    public function activeMemberCount(): int
    {
        return Member::where('status', MemberStatus::Active)->count();
    }

    #[Computed]
    public function canManageRoster(): bool
    {
        return Gate::allows('viewAny', Member::class);
    }

    public function openComposeModal(): void
    {
        Gate::authorize('create', Broadcast::class);

        $this->body = '';
        $this->resetErrorBag('body');
        $this->showComposeModal = true;
    }

    public function closeComposeModal(): void
    {
        $this->showComposeModal = false;
        $this->body = '';
        $this->resetErrorBag('body');
    }

    public function send(SendBroadcast $sendBroadcast): void
    {
        Gate::authorize('create', Broadcast::class);

        $validated = $this->validate([
            'body' => ['required', 'string', 'max:1600'],
        ]);

        $user = Auth::user();
        $organization = Tenant::get();

        abort_if($organization === null, 403);

        if (BroadcastRateLimiter::tooManyAttempts($user, $organization)) {
            $this->addError('body', __('Too many broadcasts sent recently. Please try again in a few minutes.'));

            return;
        }

        if ($this->activeMemberCount === 0) {
            $this->addError('body', __('There are no active members to send to.'));

            return;
        }

        $sendBroadcast($organization, $user, $validated['body']);

        BroadcastRateLimiter::hit($user, $organization);

        $this->showComposeModal = false;
        $this->body = '';
        unset($this->broadcasts);

        Flux::toast(variant: 'success', text: __('Broadcast sent.'));
    }
}; ?>

<div>
<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="lg">{{ __('Broadcasts') }}</flux:heading>
            <flux:subheading>{{ __('Send a message to every active member as their own individual text.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="paper-airplane" wire:click="openComposeModal">
            {{ __('New broadcast') }}
        </flux:button>
    </div>

    <flux:table :paginate="$this->broadcasts">
        <flux:table.columns>
            <flux:table.column>{{ __('Sent') }}</flux:table.column>
            <flux:table.column>{{ __('Sender') }}</flux:table.column>
            <flux:table.column>{{ __('Message') }}</flux:table.column>
            <flux:table.column>{{ __('Recipients') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->broadcasts as $broadcast)
                <flux:table.row :key="$broadcast->id">
                    <flux:table.cell>
                        <flux:link :href="route('broadcasts.show', $broadcast)" wire:navigate>
                            {{ $broadcast->created_at?->format('M j, Y g:i A') }}
                        </flux:link>
                    </flux:table.cell>
                    <flux:table.cell>{{ $broadcast->sender->name }}</flux:table.cell>
                    <flux:table.cell class="max-w-sm truncate">{{ Str::limit($broadcast->body, 80) }}</flux:table.cell>
                    <flux:table.cell>{{ $broadcast->messages_count }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="match ($broadcast->status) {
                            \App\Enums\BroadcastStatus::Queued => 'zinc',
                            \App\Enums\BroadcastStatus::Sending => 'yellow',
                            \App\Enums\BroadcastStatus::Sent => 'green',
                            \App\Enums\BroadcastStatus::Failed => 'red',
                        }">
                            {{ $broadcast->status->value }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5">
                        <flux:text class="py-6 text-center">{{ __('No broadcasts sent yet.') }}</flux:text>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>

<flux:modal name="compose-broadcast" wire:model="showComposeModal" @close="closeComposeModal" class="max-w-lg">
    <form wire:submit="send" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('New broadcast') }}</flux:heading>
            <flux:subheading>
                {{ trans_choice('Sending to :count active member|Sending to :count active members', $this->activeMemberCount, ['count' => $this->activeMemberCount]) }}
            </flux:subheading>
        </div>

        <div>
            <flux:textarea wire:model.live="body" :label="__('Message')" rows="5" :placeholder="__('What do you want to tell the department?')" />
            <flux:text size="sm" class="mt-1 text-end">{{ strlen($body) }} / 1600</flux:text>
        </div>

        <div class="flex justify-end gap-3">
            <flux:button type="button" variant="outline" wire:click="closeComposeModal">
                {{ __('Cancel') }}
            </flux:button>
            <flux:button type="submit" variant="primary" icon="paper-airplane">
                {{ __('Send') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
</div>
