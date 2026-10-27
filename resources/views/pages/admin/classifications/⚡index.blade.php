<?php

use App\Classification\ClassificationCandidate;
use App\Classification\ClassificationService;
use App\Classification\PrimaryClassificationExists;
use App\Classification\SecondaryClassificationLimit;
use App\Enums\ClassificationMethod;
use App\Enums\ClassificationType;
use App\Enums\ReviewStatus;
use App\Models\DeweyClass;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Classification review')] class extends Component {
    use WithPagination;

    #[Url(as: 'status')]
    public string $status = 'pending';

    /** Which action the class dialog is for: `change` swaps a row's class, `add` gives a story another class. */
    public string $mode = 'change';

    public ?int $rowId = null;

    public ?int $mediaId = null;

    public string $code = '';

    public string $type = 'secondary';

    public function boot(): void
    {
        Gate::authorize('access-admin');
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * The rows in the chosen status, least confident first.
     *
     * @return LengthAwarePaginator<int, MediaDeweyClass>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $query = MediaDeweyClass::query()
            ->where('review_status', ReviewStatus::tryFrom($this->status) ?? ReviewStatus::Pending)
            ->whereHas('media')
            ->with(['media.mediaType', 'media.creators', 'deweyClass'])
            ->orderBy('confidence')
            ->orderBy('id');

        $rows = $query->paginate(25);

        if ($rows->isEmpty() && $rows->currentPage() > 1) {
            $this->setPage($rows->lastPage());
            $rows = $query->paginate(25);
        }

        return $rows;
    }

    /**
     * @return Collection<int, DeweyClass>
     */
    #[Computed]
    public function classes(): Collection
    {
        return DeweyClass::query()->orderBy('code')->get(['id', 'code', 'name']);
    }

    public function accept(int $rowId): void
    {
        Gate::authorize('access-admin');

        $row = $this->row($rowId);

        $this->perform(
            fn () => app(ClassificationService::class)->accept($row, auth()->user()),
            __('Accepted :class for “:title”.', ['class' => $row->deweyClass->code, 'title' => $row->media->title]),
        );
    }

    public function reject(int $rowId): void
    {
        Gate::authorize('access-admin');

        $row = $this->row($rowId);

        $this->perform(
            fn () => app(ClassificationService::class)->reject($row, auth()->user()),
            __('Rejected :class for “:title”.', ['class' => $row->deweyClass->code, 'title' => $row->media->title]),
        );
    }

    /**
     * Swap the row's class for the one with this code; the row's type is kept.
     */
    public function change(int $rowId, string $code): void
    {
        Gate::authorize('access-admin');

        $row = $this->row($rowId);

        if (($class = $this->classWithCode($code)) === null) {
            return;
        }

        $this->perform(
            fn () => app(ClassificationService::class)->replace($row, $class, auth()->user()),
            __('Changed “:title” from :old to :new.', ['title' => $row->media->title, 'old' => $row->deweyClass->code, 'new' => $class->code]),
        );
    }

    public function add(int $mediaId, string $code, string $type): void
    {
        Gate::authorize('access-admin');

        $media = Media::findOrFail($mediaId);
        $kind = ClassificationType::tryFrom($type) ?? abort(422);

        if (($class = $this->classWithCode($code)) === null) {
            return;
        }

        $this->perform(
            fn () => app(ClassificationService::class)->assign($media, $class, $kind, new ClassificationCandidate($class->code, 1.0, ClassificationMethod::Manual, 'admin'), auth()->user()),
            __('Added :class as a :type class of “:title”.', ['class' => $class->code, 'type' => $kind->value, 'title' => $media->title]),
        );
    }

    public function remove(int $rowId): void
    {
        Gate::authorize('access-admin');

        $row = $this->row($rowId);

        $this->perform(
            fn () => app(ClassificationService::class)->remove($row, auth()->user()),
            __('Removed :class from “:title”.', ['class' => $row->deweyClass->code, 'title' => $row->media->title]),
        );
    }

    public function openChange(int $rowId): void
    {
        Gate::authorize('access-admin');

        $this->reset('code', 'type', 'mediaId');
        $this->resetErrorBag();
        $this->mode = 'change';
        $this->rowId = $this->row($rowId)->id;

        Flux::modal('class-editor')->show();
    }

    public function openAdd(int $mediaId): void
    {
        Gate::authorize('access-admin');

        $this->reset('code', 'type', 'rowId');
        $this->resetErrorBag();
        $this->mode = 'add';
        $this->mediaId = Media::findOrFail($mediaId)->id;

        Flux::modal('class-editor')->show();
    }

    /**
     * Submit the class dialog; it closes unless the pick was refused.
     */
    public function save(): void
    {
        Gate::authorize('access-admin');

        $this->resetErrorBag();

        $this->mode === 'add'
            ? $this->add((int) $this->mediaId, $this->code, $this->type)
            : $this->change((int) $this->rowId, $this->code);

        if ($this->getErrorBag()->isEmpty()) {
            Flux::modal('class-editor')->close();
        }
    }

    private function row(int $rowId): MediaDeweyClass
    {
        return MediaDeweyClass::with(['media', 'deweyClass'])->findOrFail($rowId);
    }

    private function classWithCode(string $code): ?DeweyClass
    {
        $class = DeweyClass::where('code', $code)->first();

        if ($class === null) {
            $this->addError('code', __('Choose a class from the list.'));
        }

        return $class;
    }

    private function perform(Closure $action, string $success): void
    {
        try {
            $action();
        } catch (PrimaryClassificationExists) {
            Flux::toast(variant: 'danger', text: __('That story already has a primary class. Change or remove it first.'));

            return;
        } catch (SecondaryClassificationLimit) {
            Flux::toast(variant: 'danger', text: __('That story already has :max secondary classes. Remove one first.', ['max' => config('bookflix.classification.max_secondary')]));

            return;
        }

        Flux::toast(variant: 'success', text: $success);
    }
}; ?>

<section class="py-8">
    <x-admin-header :back="route('admin.index')" :title="__('Classification review')" :description="__('Classes the sync suggested for each story, least confident first. Accept or reject each one: accepted classes show on the shelves, rejected ones never do.')" />

    <x-admin-toolbar>
        <flux:select class="max-w-xs" wire:model.live="status" :aria-label="__('Status')">
            <flux:select.option value="pending">{{ __('Pending') }}</flux:select.option>
            <flux:select.option value="accepted">{{ __('Accepted') }}</flux:select.option>
            <flux:select.option value="rejected">{{ __('Rejected') }}</flux:select.option>
        </flux:select>
    </x-admin-toolbar>

    <ul class="mt-6 space-y-4">
        @forelse ($this->rows as $row)
            <li wire:key="row-{{ $row->id }}" class="rounded-card bg-surface-card p-4 md:grid md:grid-cols-[1fr_auto] md:items-center md:gap-6">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge size="sm" :color="match ($row->review_status) { ReviewStatus::Accepted => 'green', ReviewStatus::Pending => 'amber', default => 'zinc' }">{{ __(ucfirst($row->review_status->value)) }}</flux:badge>
                        <flux:badge size="sm" color="zinc">{{ __(ucfirst($row->classification_type->value)) }}</flux:badge>
                        <span class="text-meta text-ink-subtle">{{ __(':percent% confidence', ['percent' => round($row->confidence * 100)]) }} · {{ __(ucfirst($row->classification_method->value)) }}</span>
                    </div>

                    <h2 class="mt-2 text-lead text-ink">
                        <a href="{{ route('admin.media.edit', $row->media) }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control hover:underline md:min-h-0">{{ $row->media->title }}</a>
                    </h2>
                    <p class="text-meta text-ink-muted">
                        {{ $row->media->mediaType->name }}@if ($row->media->publication_year) · {{ $row->media->publication_year }}@endif
                        @if ($row->media->creators->isNotEmpty()) · {{ $row->media->creators->pluck('name')->join(', ') }}@endif
                    </p>
                    <p class="mt-2 text-body text-ink-subtle">{{ $row->deweyClass->code }} · {{ $row->deweyClass->name }}</p>
                    @if ($row->reviewed_at)
                        <p class="text-meta text-ink-muted">{{ __('Reviewed :date', ['date' => $row->reviewed_at->toFormattedDateString()]) }}</p>
                    @endif
                </div>

                @php($subject = $row->deweyClass->code.' '.$row->deweyClass->name)
                <div class="mt-4 flex flex-wrap items-center gap-2 md:mt-0">
                    @if ($row->review_status !== ReviewStatus::Accepted)
                        <flux:button variant="filled" size="sm" icon="check" icon:variant="outline" class="focus-ring min-h-11 md:min-h-0" wire:click="accept({{ $row->id }})" :aria-label="__('Accept :class for :title', ['class' => $subject, 'title' => $row->media->title])">{{ __('Accept') }}</flux:button>
                    @endif
                    @if ($row->review_status !== ReviewStatus::Rejected)
                        <flux:button variant="filled" size="sm" icon="x-mark" icon:variant="outline" class="focus-ring min-h-11 md:min-h-0" wire:click="reject({{ $row->id }})" :aria-label="__('Reject :class for :title', ['class' => $subject, 'title' => $row->media->title])">{{ __('Reject') }}</flux:button>
                    @endif
                    <flux:button variant="filled" size="sm" icon="arrows-right-left" icon:variant="outline" class="focus-ring min-h-11 md:min-h-0" wire:click="openChange({{ $row->id }})" :aria-label="__('Change :class for :title', ['class' => $subject, 'title' => $row->media->title])">{{ __('Change') }}</flux:button>

                    <flux:dropdown position="bottom" align="end">
                        <flux:button variant="filled" size="sm" icon:trailing="chevron-down" icon:variant="outline" class="focus-ring min-h-11 md:min-h-0" :aria-label="__('More actions for :class on :title', ['class' => $subject, 'title' => $row->media->title])">{{ __('More') }}</flux:button>

                        <flux:menu>
                            <flux:menu.item icon="plus" icon:variant="outline" class="focus-ring min-h-11 md:min-h-0" wire:click="openAdd({{ $row->media_id }})">{{ __('Add class') }}</flux:menu.item>
                            <flux:menu.item icon="trash" icon:variant="outline" class="focus-ring min-h-11 md:min-h-0" wire:click="remove({{ $row->id }})" wire:confirm="{{ __('Remove :class from this story?', ['class' => $row->deweyClass->code]) }}">{{ __('Remove class') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </li>
        @empty
            <li><x-admin-empty class="rounded-card bg-surface-card" icon="check-circle" :title="__('Nothing to review here')" :description="__('Classes the sync suggests will queue up under Pending.')" /></li>
        @endforelse
    </ul>

    <div class="mt-6">
        <flux:pagination :paginator="$this->rows" />
    </div>

    <flux:modal name="class-editor" class="w-full max-w-lg" aria-labelledby="class-editor-title">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg" id="class-editor-title">{{ $mode === 'add' ? __('Add a class') : __('Change class') }}</flux:heading>
                <flux:subheading>
                    {{ $mode === 'add' ? __('Give the story another class. It is accepted at once.') : __('Swap this class for another. The new class is accepted at once and the old one is rejected.') }}
                </flux:subheading>
            </div>

            <flux:select wire:model="code" :label="__('Class')">
                <flux:select.option value="">{{ __('Choose a class') }}</flux:select.option>
                @foreach ($this->classes as $class)
                    <flux:select.option value="{{ $class->code }}" wire:key="class-{{ $class->id }}">{{ $class->code }} · {{ $class->name }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($mode === 'add')
                <flux:select wire:model="type" :label="__('Type')">
                    <flux:select.option value="secondary">{{ __('Secondary') }}</flux:select.option>
                    <flux:select.option value="primary">{{ __('Primary') }}</flux:select.option>
                </flux:select>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" icon="x-mark" icon:variant="outline" class="focus-ring min-h-11 md:min-h-0">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="filled" :icon="$mode === 'add' ? 'plus' : 'check'" icon:variant="outline" class="focus-ring min-h-11 md:min-h-0">{{ $mode === 'add' ? __('Add class') : __('Change class') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
