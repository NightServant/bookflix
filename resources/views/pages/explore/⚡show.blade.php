<?php

use App\Classification\Dewey\DeweyHierarchy;
use App\Models\DeweyClass;
use App\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Explore the Library')] class extends Component {
    use WithPagination;

    public string $code = '';

    public function mount(string $code): void
    {
        $this->code = $code;

        abort_if($this->deweyClass === null, 404);
    }

    #[Computed]
    public function deweyClass(): ?DeweyClass
    {
        return app(DeweyHierarchy::class)->findByCode($this->code);
    }

    /**
     * The classes directly below this one; a section such as 813 has none and shows its stories only.
     *
     * @return Collection<int, DeweyClass>
     */
    #[Computed]
    public function children(): Collection
    {
        return app(DeweyHierarchy::class)->children($this->deweyClass);
    }

    /**
     * Stories whose public primary class is this class or one below it.
     *
     * @return LengthAwarePaginator<int, Media>
     */
    #[Computed]
    public function stories(): LengthAwarePaginator
    {
        return Media::query()->inDeweySubtree($this->deweyClass)->orderBy('title')->orderBy('id')->paginate(24);
    }

    #[Computed]
    public function levelLabel(): string
    {
        return match ($this->deweyClass->level) {
            1 => __('Class'),
            2 => __('Division'),
            default => __('Section'),
        };
    }

    #[Computed]
    public function childrenLabel(): string
    {
        return $this->deweyClass->level === 1 ? __('Divisions') : __('Sections');
    }
}; ?>

<section class="py-12">
    <x-dewey-breadcrumb :dewey-class="$this->deweyClass" />

    <h1 class="mt-4 text-section text-ink">{{ $this->deweyClass->code }} {{ $this->deweyClass->name }}</h1>
    <p class="mt-1 text-meta text-ink-muted">{{ $this->levelLabel }} · {{ __('Dewey-inspired') }}</p>

    @if ($this->deweyClass->description)
        <p class="mt-2 max-w-prose text-body text-ink-subtle">{{ $this->deweyClass->description }}</p>
    @endif

    @if ($this->children->isNotEmpty())
        <h2 class="mt-8 text-lead text-ink">{{ $this->childrenLabel }}</h2>
        <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($this->children as $child)
                <li wire:key="child-{{ $child->id }}">
                    <x-dewey-class-card :dewey-class="$child" />
                </li>
            @endforeach
        </ul>
    @endif

    <h2 class="mt-12 text-lead text-ink">{{ __('Stories') }}</h2>
    @if ($this->stories->isEmpty())
        <p class="mt-4 text-body text-ink-subtle">{{ __('No stories here yet.') }}</p>
    @else
        <ul class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ($this->stories as $media)
                <li wire:key="story-{{ $media->id }}">
                    <x-story-tile :media="$media" />
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$this->stories" class="mt-8" />
    @endif
</section>
