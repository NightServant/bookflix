<?php

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Users')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function boot(): void
    {
        Gate::authorize('access-admin');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Read-only. Only the columns the table shows are selected, so credentials and two-factor data never leave the database.
     *
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $term = '%'.addcslashes(trim($this->search), '%_\\').'%';

        return User::query()
            ->select(['id', 'name', 'email', 'email_verified_at', 'is_admin', 'deactivated_at', 'created_at'])
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);
    }
}; ?>

<section class="py-8">
    <x-admin-header :back="route('admin.index')" :title="__('Users')" :description="__('Everyone with an account. Search by name or email to find a person.')" />

    <x-admin-toolbar>
        <flux:input class="max-w-sm" type="search" icon="magnifying-glass" icon:variant="outline" wire:model.live.debounce.300ms="search" :placeholder="__('Search name or email')" :aria-label="__('Search name or email')" />
    </x-admin-toolbar>

    <flux:table class="mt-6" :paginate="$this->users">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Joined') }}</flux:table.column>
            <flux:table.column>{{ __('Verified') }}</flux:table.column>
            <flux:table.column>{{ __('Role') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->users as $user)
                <flux:table.row wire:key="user-{{ $user->id }}">
                    <flux:table.cell variant="strong">{{ $user->name }}</flux:table.cell>
                    <flux:table.cell>{{ $user->email }}</flux:table.cell>
                    <flux:table.cell>{{ $user->created_at->toFormattedDateString() }}</flux:table.cell>
                    <flux:table.cell>{{ $user->email_verified_at ? __('Yes') : __('No') }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($user->is_admin)
                            <flux:badge size="sm" color="blue">{{ __('Admin') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($user->deactivated_at)
                            <flux:badge size="sm" color="zinc">{{ __('Deactivated') }}</flux:badge>
                            <span class="ms-2 text-meta text-ink-subtle">{{ $user->deactivated_at->toFormattedDateString() }}</span>
                        @else
                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6"><x-admin-empty icon="users" :title="__('No users found')" :description="__('Try a different name or email.')" /></flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
