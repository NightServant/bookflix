<?php

use App\Actions\Reader\DeleteContentSource;
use App\Enums\AccessType;
use App\Models\ContentSource;
use App\Models\Media;
use App\Reader\Cbz\CbrConverter;
use App\Reader\UploadContentSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * Add your own file (9.7): the reader picks a story, drops an EPUB, PDF, CBZ or CBR on it, confirms the copy is theirs and uploads. The
 * checking, the caps, the quota and the hourly limit are UploadContentSource's; this page only asks and shows what it answers. Every action
 * asks the reader gate again, and a file is only ever removed through DeleteContentSource, which checks that it is the reader's own.
 */
new #[Title('Add your own file')] class extends Component {
    use WithFileUploads;

    /** The most stories the picker holds: the reader's library first, then the catalog by title. */
    private const PICKER_LIMIT = 500;

    /** The slug of the story the file belongs to. */
    public string $story = '';

    public $file = null;

    public bool $own = false;

    public bool $added = false;

    /** Bumped when the form resets, so the picker and the native file input are made fresh. */
    public int $round = 0;

    public function mount(): void
    {
        Gate::authorize('use-reader-side');

        $slug = request()->query('story');

        if (is_string($slug) && $this->visibleStory($slug) !== null) {
            $this->story = $slug;
        }
    }

    public function updatedFile(): void
    {
        $this->added = false;
        $this->resetErrorBag('file');
    }

    public function updatedStory(): void
    {
        $this->added = false;
        $this->resetErrorBag(['story', 'media']);
    }

    public function removeFile(): void
    {
        Gate::authorize('use-reader-side');

        $this->file?->delete();
        $this->file = null;
        $this->resetErrorBag();
    }

    public function save(UploadContentSource $uploads): void
    {
        Gate::authorize('use-reader-side');

        $this->added = false;

        $this->validate([
            'story' => ['required'],
            'file' => ['required', 'file'],
            'own' => ['accepted'],
        ], [
            'story.required' => __('Choose the story this file belongs to.'),
            'file.required' => __('Choose a file to add.'),
            'own.accepted' => __('Confirm that this is your own copy.'),
        ]);

        $media = $this->visibleStory($this->story);

        if ($media === null) {
            $this->addError('media', __('Choose a story from the catalog.'));

            return;
        }

        $uploads->handle(auth()->user(), $media, $this->file);

        $this->file->delete();
        $this->reset('file', 'story', 'own');
        $this->resetErrorBag();
        $this->added = true;
        $this->round++;
        unset($this->files, $this->used);
    }

    public function delete(int $id, DeleteContentSource $delete): void
    {
        Gate::authorize('use-reader-side');

        $source = ContentSource::query()->find($id);

        abort_if($source === null, 404);

        $delete->handle(auth()->user(), $source);

        unset($this->files, $this->used);
    }

    /**
     * What the picker offers: the stories this reader may see (the mature gate applies), their own library first.
     *
     * @return array<string, string> slug => "Title · Creators"
     */
    #[Computed]
    public function stories(): array
    {
        $user = auth()->user();
        $picked = $this->story !== '' ? $this->visibleStory($this->story) : null;

        $media = Media::query()->visibleTo($user)->with('creators')
            ->orderByRaw('exists (select 1 from user_library where user_library.media_id = media.id and user_library.user_id = ?) desc', [$user->id])
            ->orderBy('title')
            ->limit(self::PICKER_LIMIT)
            ->get();

        if ($picked !== null && ! $media->contains('id', $picked->id)) {
            $media->push($picked->loadMissing('creators'));
        }

        return $media->mapWithKeys(function (Media $story): array {
            $creators = $story->creators->sortBy('pivot.position')->pluck('name')->unique()->take(2)->implode(', ');

            return [$story->slug => $creators === '' ? $story->title : $story->title.' · '.$creators];
        })->all();
    }

    /**
     * The reader's own files, newest first.
     *
     * @return Collection<int, ContentSource>
     */
    #[Computed]
    public function files(): Collection
    {
        return ContentSource::query()
            ->where('access_type', AccessType::UserOwned)
            ->where('owner_user_id', auth()->id())
            ->with('media')
            ->latest('id')
            ->get();
    }

    #[Computed]
    public function used(): int
    {
        return (int) $this->files->sum('byte_size');
    }

    /** The quota in bytes. */
    #[Computed]
    public function quota(): int
    {
        return (int) ((float) config('bookflix.reader.upload.quota_mb') * 1048576);
    }

    /**
     * The sizes line, built from config. CBR is only mentioned when this server can unpack it.
     */
    #[Computed]
    public function limits(): string
    {
        $mb = fn (string $key): string => Number::fileSize((int) ((float) config('bookflix.reader.upload.'.$key) * 1048576), precision: 0);
        $comics = app(CbrConverter::class)->available() ? __('CBZ or CBR') : __('CBZ');

        return __('EPUB up to :epub · PDF up to :pdf · :comics up to :cbz', ['epub' => $mb('epub_mb'), 'pdf' => $mb('pdf_mb'), 'comics' => $comics, 'cbz' => $mb('cbz_mb')]);
    }

    #[Computed]
    public function accept(): string
    {
        return app(CbrConverter::class)->available() ? '.epub,.pdf,.cbz,.cbr' : '.epub,.pdf,.cbz';
    }

    private function visibleStory(string $slug): ?Media
    {
        return Media::query()->visibleTo(auth()->user())->where('slug', $slug)->first();
    }
};
?>

@php
    // "1.2 MB", "38 MB", "2 GB": one decimal only when it says something.
    $size = fn (int $bytes): string => (string) preg_replace('/\.0(?= )/', '', Number::fileSize($bytes, precision: 1));
@endphp

<div class="pb-8">
    <x-page-header :eyebrow="__('Library')" :title="__('Add your own file')" :description="__('Read a DRM-free copy you own, with your place saved like any other story. Only you can see and open the files you add.')" />

    <div class="mt-8 flex flex-col items-start gap-6 lg:flex-row lg:gap-10">
        <form
            wire:submit="save"
            x-data="{ dragging: false, uploading: false, progress: 0 }"
            x-on:livewire-upload-start="uploading = true; progress = 0"
            x-on:livewire-upload-progress="progress = $event.detail.progress"
            x-on:livewire-upload-finish="uploading = false"
            x-on:livewire-upload-error="uploading = false"
            x-on:livewire-upload-cancel="uploading = false"
            class="w-full min-w-0 rounded-card border border-hairline bg-surface-card p-5 sm:p-7 lg:w-[47.5rem] lg:max-w-full"
        >
            @php
                $storyError = $errors->first('story') ?: $errors->first('media');
                $fileError = $errors->first('file');
                $rawName = $file?->getClientOriginalName();
            @endphp

            @if ($errors->has('user'))
                <p role="alert" class="mb-6 rounded-control bg-surface-input p-4 text-meta text-ink">{{ $errors->first('user') }}</p>
            @endif

            {{-- 1: the story --}}
            <section aria-labelledby="upload-step-story">
                <h2 id="upload-step-story" class="flex items-center gap-3 text-lead text-ink">
                    <span aria-hidden="true" class="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-surface-input text-meta text-ink-subtle">1</span>
                    {{ __('Which story is it?') }}
                </h2>
                <div class="mt-4">
                    <x-combobox wire:model="story" :options="$this->stories" :label="__('Story')" :hide-label="true" :placeholder="__('Search the catalog')" wire:key="upload-story-{{ $round }}" />
                    <p class="mt-2 text-meta text-ink-subtle">{{ __('Search the catalog and pick the story this file belongs to. Your progress is kept on that story.') }}</p>
                    @if ($storyError)
                        <p role="alert" class="mt-2 text-meta text-ink">{{ $storyError }}</p>
                    @endif
                </div>
            </section>

            {{-- 2: the file --}}
            <section aria-labelledby="upload-step-file" class="mt-8">
                <h2 id="upload-step-file" class="flex items-center gap-3 text-lead text-ink">
                    <span aria-hidden="true" class="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-surface-input text-meta text-ink-subtle">2</span>
                    {{ __('Choose the file') }}
                </h2>

                <div
                    x-on:dragover.prevent="dragging = true"
                    x-on:dragleave.prevent="dragging = false"
                    x-on:drop.prevent="dragging = false; if ($event.dataTransfer.files.length) { $refs.file.files = $event.dataTransfer.files; $refs.file.dispatchEvent(new Event('change', { bubbles: true })) }"
                    x-bind:class="dragging ? 'border-ink bg-surface-input' : 'border-border-input'"
                    class="mt-4 flex flex-col items-center gap-4 rounded-card border border-dashed px-4 py-8 text-center"
                >
                    <span aria-hidden="true" class="inline-flex size-12 items-center justify-center rounded-full bg-surface-input text-ink"><flux:icon name="arrow-up-tray" variant="outline" class="size-6" /></span>
                    <p class="text-body text-ink-subtle">{{ __('Drag a file here, or') }}</p>

                    <input
                        x-ref="file"
                        wire:key="upload-input-{{ $round }}"
                        type="file"
                        id="upload-file"
                        name="file"
                        wire:model="file"
                        accept="{{ $this->accept }}"
                        aria-describedby="upload-limits {{ $fileError ? 'upload-file-error' : '' }}"
                        class="peer sr-only"
                    >
                    <label for="upload-file" class="inline-flex min-h-11 cursor-pointer items-center justify-center rounded-control bg-surface-input px-5 text-body font-medium text-ink hover:bg-surface-input/80 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent-electric">{{ __('Choose a file') }}</label>

                    <p id="upload-limits" class="text-meta text-ink-subtle">{{ $this->limits }}</p>
                </div>

                <div x-show="uploading" x-cloak style="display: none" class="mt-4">
                    <div role="progressbar" aria-label="{{ __('Upload progress') }}" aria-valuemin="0" aria-valuemax="100" x-bind:aria-valuenow="progress" x-bind:aria-valuetext="progress + '%'" class="h-1 overflow-hidden rounded-full bg-ink-muted/35">
                        <div class="h-full bg-ink" x-bind:style="'width: ' + progress + '%'"></div>
                    </div>
                    <p class="mt-2 text-meta text-ink-subtle" role="status"><span x-text="progress + '%'"></span> {{ __('uploaded') }}</p>
                </div>

                @if ($fileError)
                    <p id="upload-file-error" role="alert" class="mt-3 text-meta text-ink">{{ $fileError }}</p>
                @endif

                @if ($file)
                    @php
                        $extension = strtoupper(pathinfo((string) $rawName, PATHINFO_EXTENSION));
                    @endphp
                    <div data-chosen-file class="mt-4 flex items-center gap-3 rounded-card bg-surface-input p-2 ps-4">
                        <flux:icon name="document-text" variant="outline" class="size-6 shrink-0 text-ink-subtle" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-body font-medium text-ink">{{ $rawName }}</p>
                            <p class="text-meta text-ink-subtle">{{ $extension !== '' ? $extension.' · ' : '' }}{{ $size((int) $file->getSize()) }}</p>
                        </div>
                        <button type="button" wire:click="removeFile" x-on:click="$refs.file.value = ''" aria-label="{{ __('Remove :name', ['name' => $rawName]) }}" class="focus-ring inline-flex size-11 shrink-0 items-center justify-center rounded-control text-ink-subtle hover:text-ink">
                            <flux:icon name="x-mark" variant="outline" class="size-5" />
                        </button>
                    </div>
                @endif
            </section>

            {{-- 3: confirm --}}
            <section aria-labelledby="upload-step-confirm" class="mt-8">
                <h2 id="upload-step-confirm" class="flex items-center gap-3 text-lead text-ink">
                    <span aria-hidden="true" class="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-surface-input text-meta text-ink-subtle">3</span>
                    {{ __('Confirm') }}
                </h2>
                <div class="mt-4">
                    <flux:checkbox wire:model="own" error:bag="none" :label="__('This is my own copy, and it has no DRM.')" />
                    <p class="mt-2 text-meta text-ink-subtle">{{ __('Bookflix stores the file privately for you. It\'s never shared or shown to anyone else.') }}</p>
                    @error('own')
                        <p role="alert" class="mt-2 text-meta text-ink">{{ $message }}</p>
                    @enderror
                </div>
            </section>

            @if ($added)
                <p role="status" class="mt-6 flex items-center gap-2 rounded-control bg-surface-input p-4 text-body text-ink">
                    <flux:icon name="check" variant="outline" class="size-5 shrink-0" />
                    {{ __('Added. Open it from the story or from Your files.') }}
                </p>
            @endif

            <div class="mt-8 grid grid-cols-2 gap-3 sm:flex sm:justify-end">
                <x-cta variant="secondary" :href="route('library.index')" wire:navigate class="sm:min-w-32">{{ __('Cancel') }}</x-cta>
                <x-cta type="submit" x-bind:disabled="uploading" wire:loading.attr="disabled" wire:target="save" class="sm:min-w-32 disabled:opacity-50">{{ __('Upload') }}</x-cta>
            </div>
        </form>

        <section aria-labelledby="your-files" class="w-full shrink-0 rounded-card border border-hairline bg-surface-card p-5 sm:p-7 lg:w-[25rem]">
            <h2 id="your-files" class="text-lead text-ink">{{ __('Your files') }}</h2>

            @php
                $percent = $this->quota > 0 ? min(100, (int) round($this->used / $this->quota * 100)) : 0;
                $usage = __(':used of :quota used', ['used' => $size($this->used), 'quota' => $size($this->quota)]);
            @endphp
            <div class="mt-4">
                <p class="text-meta text-ink">{{ $usage }}</p>
                <div role="progressbar" aria-label="{{ __('Storage used') }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-valuetext="{{ $usage }}" class="mt-2 h-1 overflow-hidden rounded-full bg-ink-muted/35">
                    <div class="h-full bg-ink" style="width: {{ $percent }}%"></div>
                </div>
            </div>

            @if ($this->files->isNotEmpty())
                <ul class="mt-4 divide-y divide-hairline">
                    @foreach ($this->files as $source)
                        <li wire:key="file-{{ $source->id }}" class="flex items-center gap-2 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-body text-ink">{{ $source->media->title }}</p>
                                <p class="text-meta text-ink-subtle">{{ $source->format->label() }} · {{ $size((int) $source->byte_size) }}@if ($source->status->value === 'pending') · {{ __('Converting') }}@elseif ($source->status->value === 'failed') · {{ __('Could not be converted') }}@endif</p>
                            </div>
                            @if ($source->status->value === 'ready')
                                <a href="{{ route('reader.show', ['slug' => $source->media->slug, 'source' => $source->id]) }}" aria-label="{{ __('Read :title', ['title' => $source->media->title]) }}" class="focus-ring inline-flex size-11 shrink-0 items-center justify-center rounded-control text-ink hover:bg-surface-input">
                                    <flux:icon name="book-open" variant="outline" class="size-6" />
                                </a>
                            @endif
                            <button type="button" wire:click="delete({{ $source->id }})" wire:confirm="{{ __('Delete this file? Your place in the story is kept.') }}" aria-label="{{ __('Delete :title', ['title' => $source->media->title]) }}" class="focus-ring inline-flex size-11 shrink-0 items-center justify-center rounded-control text-ink hover:bg-surface-input">
                                <flux:icon name="trash" variant="outline" class="size-6" />
                            </button>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-4 text-body text-ink-subtle">{{ __('Nothing added yet.') }}</p>
            @endif

            <p class="mt-4 text-meta text-ink-subtle">{{ __('Deleting a file keeps your place in the story. Up to :count uploads an hour.', ['count' => (int) config('bookflix.reader.upload.per_hour')]) }}</p>
        </section>
    </div>
</div>
