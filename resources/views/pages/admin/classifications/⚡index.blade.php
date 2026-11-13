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
use Illuminate\Support\Number;
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
            ->when($this->status !== 'all', fn ($query) => $query->where('review_status', ReviewStatus::tryFrom($this->status) ?? ReviewStatus::Pending))
            ->whereHas('media')
            ->with(['media.mediaType', 'media.creators', 'deweyClass', 'modelVersion'])
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

    /**
     * The status filters, in the order of the chips.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function statuses(): array
    {
        return ['pending' => __('Pending'), 'accepted' => __('Accepted'), 'rejected' => __('Rejected'), 'all' => __('All')];
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
    <x-admin-header :title="__('Classification review')" :description="__('Classes the sync suggested for each story, least confident first. Accept or reject each one: accepted classes show on the shelves, rejected ones never do.')" />

    @php($shown = array_key_exists($status, $this->statuses) ? $status : 'pending')

    <nav aria-label="{{ __('Status') }}" class="mt-6 flex flex-wrap gap-2">
        @foreach ($this->statuses as $value => $label)
            <x-chip wire:key="status-{{ $value }}" :href="route('admin.classifications', ['status' => $value])" wire:click.prevent="$set('status', '{{ $value }}')" :active="$shown === $value" class="min-h-11 px-4">{{ $label }}</x-chip>
        @endforeach
    </nav>

    @if ($this->rows->isEmpty())
        <x-admin-empty class="mt-6 rounded-card bg-surface-card" icon="check-circle" :title="__('Nothing to review here')" :description="__('Classes the sync suggests will queue up under Pending.')" />
    @else
        {{-- A table from md up; below it each row is a card (the table's parts become blocks, each cell labelled by its data-label). --}}
        <flux:table class="mt-6 table-auto! max-md:block max-md:whitespace-normal! max-md:[&_tbody]:block max-md:[&_thead]:hidden max-md:[&_tr]:block max-md:[&_td]:block max-md:[&_td]:border-0 max-md:[&_td]:px-0">
            <flux:table.columns>
                <flux:table.column>{{ __('Story') }}</flux:table.column>
                <flux:table.column>{{ __('Class') }}</flux:table.column>
                <flux:table.column>{{ __('Confidence') }}</flux:table.column>
                <flux:table.column>{{ __('Method') }}</flux:table.column>
                <flux:table.column>{{ __('Source') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->rows as $row)
                    @php($subject = $row->deweyClass->code.' '.$row->deweyClass->name)
                    @php($cell = 'max-md:flex! max-md:gap-4 max-md:py-1 max-md:before:w-24 max-md:before:shrink-0 max-md:before:text-ink-subtle max-md:before:content-[attr(data-label)]')
                    <flux:table.row wire:key="row-{{ $row->id }}" class="max-md:mb-4 max-md:rounded-card max-md:bg-surface-card max-md:p-4">
                        <flux:table.cell class="md:max-w-60 md:whitespace-normal!">
                            <a href="{{ route('admin.media.edit', $row->media) }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control text-body text-ink hover:underline md:min-h-0">{{ $row->media->title }}</a>
                            <span class="block text-meta text-ink-subtle">
                                {{ $row->media->mediaType->name }}@if ($row->media->publication_year) · {{ $row->media->publication_year }}@endif
                                @if ($row->media->creators->isNotEmpty()) · {{ $row->media->creators->pluck('name')->join(', ') }}@endif
                            </span>
                        </flux:table.cell>
                        <flux:table.cell data-label="{{ __('Class') }}" class="{{ $cell }} md:max-w-52 md:whitespace-normal!">
                            <span>
                                <span class="text-ink">{{ $row->deweyClass->code }}</span>
                                <span class="block text-meta text-ink-subtle md:block">{{ $row->deweyClass->name }}</span>
                            </span>
                        </flux:table.cell>
                        <flux:table.cell data-label="{{ __('Confidence') }}" class="{{ $cell }} tabular-nums"><span title="{{ __(':percent% confidence', ['percent' => round($row->confidence * 100)]) }}">{{ round($row->confidence * 100) }}%</span></flux:table.cell>
                        <flux:table.cell data-label="{{ __('Method') }}" class="{{ $cell }}">{{ __(ucfirst($row->classification_method->value)) }} · {{ __(ucfirst($row->classification_type->value)) }}</flux:table.cell>
                        <flux:table.cell data-label="{{ __('Source') }}" class="{{ $cell }}">{{ $row->modelVersion ? __('Model :version', ['version' => $row->modelVersion->version]) : $row->source }}</flux:table.cell>
                        <flux:table.cell data-label="{{ __('Status') }}" class="{{ $cell }}">
                            <span>
                                <flux:badge size="sm" :color="match ($row->review_status) { ReviewStatus::Accepted => 'green', ReviewStatus::Pending => 'amber', default => 'zinc' }" :icon="match ($row->review_status) { ReviewStatus::Accepted => 'check-circle', ReviewStatus::Pending => 'clock', default => 'x-circle' }">{{ __(ucfirst($row->review_status->value)) }}</flux:badge>
                                @if ($row->reviewed_at)
                                    <span class="mt-1 block text-legal text-ink-subtle">{{ __('Reviewed :date', ['date' => $row->reviewed_at->toFormattedDateString()]) }}</span>
                                @endif
                            </span>
                        </flux:table.cell>
                        <flux:table.cell class="max-md:mt-2">
                            <div class="flex flex-wrap items-center gap-2 md:flex-nowrap md:justify-end">
                                @if ($row->review_status !== ReviewStatus::Accepted)
                                    <flux:button variant="filled" size="sm" class="focus-ring min-h-11" wire:click="accept({{ $row->id }})" :aria-label="__('Accept :class for :title', ['class' => $subject, 'title' => $row->media->title])">{{ __('Accept') }}</flux:button>
                                @endif
                                @if ($row->review_status !== ReviewStatus::Rejected)
                                    <flux:button variant="filled" size="sm" class="focus-ring min-h-11" wire:click="reject({{ $row->id }})" :aria-label="__('Reject :class for :title', ['class' => $subject, 'title' => $row->media->title])">{{ __('Reject') }}</flux:button>
                                @endif
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="filled" size="sm" icon:trailing="chevron-down" icon:variant="outline" class="focus-ring min-h-11" :aria-label="__('More actions for :class on :title', ['class' => $subject, 'title' => $row->media->title])">{{ __('More') }}</flux:button>

                                    <flux:menu>
                                        <flux:menu.item icon="arrows-right-left" icon:variant="outline" class="focus-ring min-h-11" wire:click="openChange({{ $row->id }})">{{ __('Change class') }}</flux:menu.item>
                                        <flux:menu.item icon="plus" icon:variant="outline" class="focus-ring min-h-11" wire:click="openAdd({{ $row->media_id }})">{{ __('Add class') }}</flux:menu.item>
                                        <flux:menu.item icon="trash" icon:variant="outline" class="focus-ring min-h-11" wire:click="remove({{ $row->id }})" wire:confirm="{{ __('Remove :class from this story?', ['class' => $row->deweyClass->code]) }}">{{ __('Remove class') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-meta text-ink-subtle">{{ trans_choice(':count class|:count classes', $this->rows->total(), ['count' => Number::format($this->rows->total())]) }} · {{ __('lowest confidence first') }}</p>
            <x-pager :paginator="$this->rows" class="justify-end" />
        </div>
    @endif

    <flux:modal name="class-editor" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-6" role="group" aria-labelledby="class-editor-title">
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
