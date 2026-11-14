<?php

use App\Admin\AdminCounts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Overview')] class extends Component {
    public function boot(): void
    {
        Gate::authorize('access-admin');
    }

    /**
     * The figures on this page and in the sidebar's badges: a few small queries, kept for a minute so reloading costs none (see AdminCounts).
     *
     * @return array{media: int, users: int, admins?: int, duplicates: int, classifications: int, sources: int, oldestDuplicate?: ?string, oldestClassification?: ?string, lastRun: ?array{status: string, at: string}}
     */
    #[Computed]
    public function counts(): array
    {
        return AdminCounts::get();
    }

    /**
     * How long ago the oldest waiting item arrived, or a dash when nothing waits.
     */
    public function oldest(?string $at): string
    {
        return $at === null ? '—' : Carbon::parse($at)->diffForHumans();
    }
}; ?>

<section class="py-8">
    <x-admin-header :title="__('Overview')" :description="__('What is waiting for a decision, and how the catalog and its jobs are doing.')" />

    @php($counts = $this->counts)

    <h2 class="mt-10 text-section tracking-tight text-ink">{{ __('Needs attention') }}</h2>

    <x-admin-table :label="__('Needs attention')" class="mt-4">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Task') }}</flux:table.column>
                <flux:table.column>{{ __('Waiting') }}</flux:table.column>
                <flux:table.column>{{ __('Oldest') }}</flux:table.column>
                <flux:table.column><span class="sr-only">{{ __('Action') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                <flux:table.row>
                    <flux:table.cell>
                        <span class="block text-ink">{{ __('Duplicate review') }}</span>
                        <span class="block text-meta text-ink-subtle">{{ __('Pairs the sync found similar but not certain') }}</span>
                    </flux:table.cell>
                    <flux:table.cell class="tabular-nums">{{ $counts['duplicates'] > 0 ? trans_choice(':count pair waiting|:count pairs waiting', $counts['duplicates'], ['count' => Number::format($counts['duplicates'])]) : __('All clear') }}</flux:table.cell>
                    <flux:table.cell>{{ $this->oldest($counts['oldestDuplicate'] ?? null) }}</flux:table.cell>
                    <flux:table.cell class="text-end">
                        <flux:button size="sm" variant="filled" class="min-h-11" :href="route('admin.duplicates')" wire:navigate :aria-label="__('Review duplicates')">{{ __('Review') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>

                <flux:table.row>
                    <flux:table.cell>
                        <span class="block text-ink">{{ __('Classification review') }}</span>
                        <span class="block text-meta text-ink-subtle">{{ __('Classes suggested for a story, least confident first') }}</span>
                    </flux:table.cell>
                    <flux:table.cell class="tabular-nums">{{ $counts['classifications'] > 0 ? __(':count to review', ['count' => Number::format($counts['classifications'])]) : __('All clear') }}</flux:table.cell>
                    <flux:table.cell>{{ $this->oldest($counts['oldestClassification'] ?? null) }}</flux:table.cell>
                    <flux:table.cell class="text-end">
                        <flux:button size="sm" variant="filled" class="min-h-11" :href="route('admin.classifications')" wire:navigate :aria-label="__('Review classifications')">{{ __('Review') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
    </x-admin-table>

    <h2 class="mt-12 text-section tracking-tight text-ink">{{ __('At a glance') }}</h2>

    <ul class="mt-4 divide-y divide-hairline rounded-card bg-surface-card">
        @foreach ([
            [__('Catalog'), trans_choice(':count story|:count stories', $counts['media'], ['count' => Number::format($counts['media'])]).' · '.trans_choice(':count enabled source|:count enabled sources', $counts['sources'], ['count' => $counts['sources']]), route('admin.media.index'), __('Open media')],
            [__('Last sync'), $counts['lastRun'] ? __('Last sync :status, :when', ['status' => $counts['lastRun']['status'], 'when' => $counts['lastRun']['at']]) : __('Never synced'), route('admin.providers'), __('Open sync')],
            [__('Models'), __('Versions, training jobs and the ML service'), route('admin.models'), __('Open models')],
            [__('People'), isset($counts['admins']) ? trans_choice(':count account|:count accounts', $counts['users'], ['count' => Number::format($counts['users'])]).', '.trans_choice(':count admin|:count admins', $counts['admins'], ['count' => $counts['admins']]) : trans_choice(':count person|:count people', $counts['users'], ['count' => Number::format($counts['users'])]), route('admin.users'), __('Open users')],
        ] as [$label, $line, $href, $action])
            <li wire:key="glance-{{ $label }}" class="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                <span class="w-40 shrink-0 text-body text-ink">{{ $label }}</span>
                <span class="min-w-0 flex-1 text-body text-ink-subtle">{{ $line }}</span>
                <flux:button size="sm" variant="filled" class="min-h-11" :href="$href" wire:navigate>{{ $action }}</flux:button>
            </li>
        @endforeach
    </ul>
</section>
