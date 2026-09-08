<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Member;
use App\Rules\PhoneNumber as PhoneNumberRule;
use App\Services\MemberCsvImporter;
use App\Support\PhoneNumberNormalizer;
use App\Support\Tenant;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Members')] class extends Component {
    use WithFileUploads, WithPagination;

    public string $search = '';

    public bool $showMemberModal = false;

    public bool $showImportModal = false;

    public ?int $editingMemberId = null;

    public string $first_name = '';

    public string $last_name = '';

    public string $phone_number = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $csvFile = null;

    public ?string $importSummary = null;

    /** @var array<int, string> */
    public array $importErrors = [];

    public function mount(): void
    {
        Gate::authorize('viewAny', Member::class);
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Member>
     */
    #[Computed]
    public function members()
    {
        return Member::query()
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($inner): void {
                    $inner->where('first_name', 'like', "%{$this->search}%")
                        ->orWhere('last_name', 'like', "%{$this->search}%")
                        ->orWhere('phone_number', 'like', "%{$this->search}%");
                });
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->paginate(15);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function addMember(): void
    {
        Gate::authorize('create', Member::class);

        $this->resetForm();
        $this->showMemberModal = true;
    }

    public function editMember(int $memberId): void
    {
        $member = Member::findOrFail($memberId);

        Gate::authorize('update', $member);

        $this->editingMemberId = $member->id;
        $this->first_name = $member->first_name;
        $this->last_name = (string) $member->last_name;
        $this->phone_number = $member->formattedPhoneNumber();
        $this->showMemberModal = true;
    }

    public function save(): void
    {
        $member = $this->editingMemberId !== null ? Member::findOrFail($this->editingMemberId) : null;

        Gate::authorize($member !== null ? 'update' : 'create', $member ?? Member::class);

        $validated = $this->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['required', 'string', new PhoneNumberRule],
        ]);

        $normalizedPhone = PhoneNumberNormalizer::toE164($validated['phone_number']);

        $duplicate = Member::where('phone_number', $normalizedPhone)
            ->when($member !== null, fn ($query) => $query->whereKeyNot($member->id))
            ->exists();

        if ($duplicate) {
            $this->addError('phone_number', __('A member with this phone number already exists.'));

            return;
        }

        $attributes = [
            'first_name' => $validated['first_name'],
            'last_name' => ($validated['last_name'] ?? '') !== '' ? $validated['last_name'] : null,
            'phone_number' => $normalizedPhone,
        ];

        if ($member !== null) {
            $member->update($attributes);
            AuditLog::record('member.updated', $member, user: Auth::user());
            $message = __('Member updated.');
        } else {
            $member = Member::create($attributes);
            AuditLog::record('member.created', $member, user: Auth::user());
            $message = __('Member added.');
        }

        $this->showMemberModal = false;
        $this->resetForm();
        unset($this->members);

        Flux::toast(variant: 'success', text: $message);
    }

    public function toggleOptOut(int $memberId): void
    {
        $member = Member::findOrFail($memberId);

        Gate::authorize('update', $member);

        if ($member->isActive()) {
            $member->optOut();
            AuditLog::record('member.opted_out', $member, user: Auth::user());
        } else {
            $member->optIn();
            AuditLog::record('member.opted_in', $member, user: Auth::user());
        }

        unset($this->members);
    }

    public function closeMemberModal(): void
    {
        $this->showMemberModal = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->editingMemberId = null;
        $this->first_name = '';
        $this->last_name = '';
        $this->phone_number = '';
        $this->resetErrorBag(['first_name', 'last_name', 'phone_number']);
    }

    public function importCsv(): void
    {
        Gate::authorize('create', Member::class);

        $this->validate([
            'csvFile' => ['required', 'file', 'mimes:csv,txt', 'max:1024'],
        ]);

        $organization = Tenant::get();

        abort_if($organization === null, 403);

        $result = app(MemberCsvImporter::class)->import(
            $organization,
            (string) file_get_contents($this->csvFile->getRealPath()),
        );

        AuditLog::record('members.imported', meta: [
            'created' => $result->created,
            'updated' => $result->updated,
            'error_count' => count($result->errors),
        ], user: Auth::user());

        $this->importSummary = __(':created added, :updated updated.', [
            'created' => $result->created,
            'updated' => $result->updated,
        ]);
        $this->importErrors = $result->errors;
        $this->csvFile = null;

        unset($this->members);
    }

    public function closeImportModal(): void
    {
        $this->showImportModal = false;
        $this->csvFile = null;
        $this->importSummary = null;
        $this->importErrors = [];
    }
}; ?>

<div>
<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="lg">{{ __('Members') }}</flux:heading>
            <flux:subheading>{{ __('Manage your department\'s roster. Opted-out members are skipped on every broadcast.') }}</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <flux:button variant="outline" icon="arrow-up-tray" wire:click="$set('showImportModal', true)">
                {{ __('Import CSV') }}
            </flux:button>

            <flux:button variant="primary" icon="user-plus" wire:click="addMember">
                {{ __('Add member') }}
            </flux:button>
        </div>
    </div>

    <flux:input
        wire:model.live.debounce.300ms="search"
        icon="magnifying-glass"
        :placeholder="__('Search by name or phone number...')"
        clearable
        class="max-w-sm"
    />

    <flux:table :paginate="$this->members">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Phone number') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Actions') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->members as $member)
                <flux:table.row :key="$member->id">
                    <flux:table.cell variant="strong">{{ $member->fullName() }}</flux:table.cell>
                    <flux:table.cell>{{ $member->formattedPhoneNumber() }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="match ($member->status) {
                            \App\Enums\MemberStatus::Active => 'green',
                            \App\Enums\MemberStatus::OptedOut => 'zinc',
                            \App\Enums\MemberStatus::Invalid => 'red',
                        }">
                            {{ $member->status->value }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex items-center gap-1">
                            <flux:button variant="ghost" size="sm" icon="pencil" wire:click="editMember({{ $member->id }})" :aria-label="__('Edit')" />

                            @if ($member->isActive())
                                <flux:button variant="ghost" size="sm" icon="user-minus" wire:click="toggleOptOut({{ $member->id }})" :aria-label="__('Opt out')" />
                            @else
                                <flux:button variant="ghost" size="sm" icon="user-plus" wire:click="toggleOptOut({{ $member->id }})" :aria-label="__('Reactivate')" />
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4">
                        <flux:text class="py-6 text-center">{{ __('No members yet. Add your first member to get started.') }}</flux:text>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>

<flux:modal name="member-form" wire:model="showMemberModal" @close="closeMemberModal" class="max-w-md">
    <form wire:submit="save" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ $editingMemberId !== null ? __('Edit member') : __('Add member') }}</flux:heading>
        </div>

        <flux:input wire:model="first_name" :label="__('First name')" required autofocus />
        <flux:input wire:model="last_name" :label="__('Last name')" />
        <flux:input wire:model="phone_number" :label="__('Phone number')" type="tel" required :placeholder="__('e.g. (555) 555-0100')" />

        <div class="flex justify-end gap-3">
            <flux:button type="button" variant="outline" wire:click="closeMemberModal">
                {{ __('Cancel') }}
            </flux:button>
            <flux:button type="submit" variant="primary">
                {{ __('Save') }}
            </flux:button>
        </div>
    </form>
</flux:modal>

<flux:modal name="import-members" wire:model="showImportModal" @close="closeImportModal" class="max-w-md">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Import roster from CSV') }}</flux:heading>
            <flux:subheading>{{ __('File needs a header row with "first_name", "last_name" (optional), and "phone_number" columns. Existing members are matched by phone number and updated.') }}</flux:subheading>
        </div>

        <flux:file-upload wire:model="csvFile" accept=".csv,text/csv" :label="__('Roster CSV')">
            <flux:file-upload.dropzone :heading="__('Drop your CSV here or click to browse')" />
        </flux:file-upload>

        @if ($importSummary)
            <flux:callout color="green" :heading="$importSummary" />
        @endif

        @if (! empty($importErrors))
            <flux:callout color="yellow" :heading="__(':count row(s) skipped', ['count' => count($importErrors)])">
                <ul class="list-disc ps-5 text-sm">
                    @foreach ($importErrors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </flux:callout>
        @endif

        <div class="flex justify-end gap-3">
            <flux:button type="button" variant="outline" wire:click="closeImportModal">
                {{ __('Close') }}
            </flux:button>
            <flux:button type="button" variant="primary" wire:click="importCsv">
                {{ __('Import') }}
            </flux:button>
        </div>
    </div>
</flux:modal>
</div>
