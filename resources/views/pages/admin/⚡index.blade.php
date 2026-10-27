<?php

use App\Enums\DuplicateStatus;
use App\Enums\ReviewStatus;
use App\Models\DuplicateCandidate;
use App\Models\ExternalSource;
use App\Models\IngestionRun;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Admin')] class extends Component {
    public function boot(): void
    {
        Gate::authorize('access-admin');
    }

    /**
     * The figures on the task cards: six small queries, kept for a minute so reloading the dashboard costs none.
     *
     * @return array{media: int, users: int, duplicates: int, classifications: int, sources: int, lastRun: ?array{status: string, at: string}}
     */
    #[Computed]
    public function counts(): array
    {
        return Cache::remember('admin.dashboard-counts', 60, function (): array {
            $lastRun = IngestionRun::query()->latest('started_at')->first(['status', 'started_at']);

            return [
                'media' => Media::query()->count(),
                'users' => User::query()->count(),
                'duplicates' => DuplicateCandidate::query()->where('status', DuplicateStatus::Pending)->count(),
                'classifications' => MediaDeweyClass::query()->where('review_status', ReviewStatus::Pending)->count(),
                'sources' => ExternalSource::providers()->where('enabled', true)->count(),
                'lastRun' => $lastRun ? ['status' => $lastRun->status->value, 'at' => $lastRun->started_at->diffForHumans()] : null,
            ];
        });
    }
}; ?>

<section class="py-8">
    <x-page-header :title="__('Admin')" :description="__('Run and review the catalog: sync providers, fix records, clear duplicates and manage users. Pick a task below.')" />

    @php($counts = $this->counts)

    <div class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <x-admin-task
            :href="route('admin.duplicates')"
            icon="square-2-stack"
            :title="__('Duplicate review')"
            :description="__('Pairs the sync found similar but not certain. Merge them or keep both.')"
            :stat="$counts['duplicates'] > 0 ? trans_choice(':count pair waiting|:count pairs waiting', $counts['duplicates']) : __('All clear')"
            :attention="$counts['duplicates'] > 0"
        />

        <x-admin-task
            :href="route('admin.classifications')"
            icon="rectangle-group"
            :title="__('Classification review')"
            :description="__('Accept or reject the shelves the sync suggested, least confident first.')"
            :stat="$counts['classifications'] > 0 ? __(':count to review', ['count' => $counts['classifications']]) : __('All clear')"
            :attention="$counts['classifications'] > 0"
        />

        <x-admin-task
            :href="route('admin.providers')"
            icon="arrow-path"
            :title="__('Providers and sync runs')"
            :description="__('Turn metadata sources on or off and start a sync.')"
            :stat="$counts['lastRun'] ? __('Last sync :status, :when', ['status' => $counts['lastRun']['status'], 'when' => $counts['lastRun']['at']]) : __('Never synced')"
        />

        <x-admin-task
            :href="route('admin.media.index')"
            icon="book-open"
            :title="__('Media')"
            :description="__('Search the catalog, correct a record or remove an invalid one.')"
            :stat="trans_choice(':count story|:count stories', $counts['media'])"
        />

        <x-admin-task
            :href="route('admin.users')"
            icon="users"
            :title="__('Users')"
            :description="__('Everyone with an account, with their role and status.')"
            :stat="trans_choice(':count person|:count people', $counts['users'])"
        />
    </div>
</section>
