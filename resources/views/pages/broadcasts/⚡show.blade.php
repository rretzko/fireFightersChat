<?php

declare(strict_types=1);

use App\Enums\BroadcastStatus;
use App\Enums\MessageStatus;
use App\Models\Broadcast;
use App\Models\Member;
use App\Models\Message;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Broadcast')] class extends Component {
    #[Locked]
    public int $broadcastId;

    public function mount(Broadcast $broadcast): void
    {
        Gate::authorize('view', $broadcast);

        $this->broadcastId = $broadcast->id;
    }

    #[Computed]
    public function broadcast(): Broadcast
    {
        return Broadcast::with('sender')->findOrFail($this->broadcastId);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        return $this->broadcast->messages()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();
    }

    /**
     * Per-recipient names/phone numbers are roster information — restricted
     * to owner/admin, same as the member roster itself (business plan: no
     * visible roster of everyone else's number for ordinary senders).
     */
    #[Computed]
    public function canViewRecipients(): bool
    {
        return Gate::allows('viewAny', Member::class);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Message>
     */
    #[Computed]
    public function messages()
    {
        if (! $this->canViewRecipients) {
            return collect();
        }

        return $this->broadcast->messages()->with('member')->orderBy('id')->get();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('broadcasts.index')" wire:navigate>
            {{ __('Back to broadcasts') }}
        </flux:button>
    </div>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="lg">{{ __('Broadcast') }}</flux:heading>
            <flux:subheading>
                {{ __('Sent by :name on :date', ['name' => $this->broadcast->sender->name, 'date' => $this->broadcast->created_at?->format('M j, Y g:i A')]) }}
            </flux:subheading>
        </div>

        <flux:badge :color="match ($this->broadcast->status) {
            BroadcastStatus::Queued => 'zinc',
            BroadcastStatus::Sending => 'yellow',
            BroadcastStatus::Sent => 'green',
            BroadcastStatus::Failed => 'red',
        }">
            {{ $this->broadcast->status->value }}
        </flux:badge>
    </div>

    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:text class="whitespace-pre-wrap">{{ $this->broadcast->body }}</flux:text>
    </div>

    <div class="flex flex-wrap gap-2">
        @foreach (MessageStatus::cases() as $status)
            @if (($this->statusCounts[$status->value] ?? 0) > 0)
                <flux:badge size="sm" color="zinc">
                    {{ $status->value }}: {{ $this->statusCounts[$status->value] }}
                </flux:badge>
            @endif
        @endforeach
    </div>

    @if ($this->canViewRecipients)
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Member') }}</flux:table.column>
                <flux:table.column>{{ __('Phone number') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Sent at') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->messages as $message)
                    <flux:table.row :key="$message->id">
                        <flux:table.cell>{{ $message->member->fullName() }}</flux:table.cell>
                        <flux:table.cell>{{ $message->member->formattedPhoneNumber() }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="match ($message->status) {
                                MessageStatus::Queued => 'zinc',
                                MessageStatus::Sent => 'blue',
                                MessageStatus::Delivered => 'green',
                                MessageStatus::Failed, MessageStatus::Undelivered => 'red',
                            }">
                                {{ $message->status->value }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>{{ $message->sent_at?->format('M j, g:i A') ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">
                            <flux:text class="py-6 text-center">{{ __('No recipients.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    @endif
</div>
