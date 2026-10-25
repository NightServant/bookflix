<?php

use App\Enums\AgeRating;
use App\Enums\PublicationStatus;
use App\Models\Media;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit media')] class extends Component {
    /**
     * The fields an admin can edit. Saving one locks it so the next sync leaves it alone.
     *
     * @var list<string>
     */
    private const FIELDS = ['title', 'description', 'age_rating', 'status'];

    public Media $media;

    public string $title = '';
    public string $description = '';
    public string $age_rating = '';
    public string $status = '';

    public function boot(): void
    {
        Gate::authorize('access-admin');
    }

    public function mount(Media $media): void
    {
        $this->media = $media;
        $this->fill(collect(self::FIELDS)->mapWithKeys(fn (string $field): array => [$field => (string) $this->stored($field)])->all());
    }

    /**
     * Save the form. Only fields that differ from what is stored are written and locked.
     */
    public function save(): void
    {
        Gate::authorize('access-admin');

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'age_rating' => [Rule::enum(AgeRating::class)],
            'status' => [Rule::enum(PublicationStatus::class)],
        ]);

        $validated['title'] = trim($validated['title']);
        $validated['description'] = filled($validated['description']) ? trim($validated['description']) : null;

        $changed = array_filter($validated, fn (mixed $value, string $field): bool => $value !== $this->stored($field), ARRAY_FILTER_USE_BOTH);

        if ($changed === []) {
            Flux::toast(text: __('Nothing to save.'));

            return;
        }

        $this->media->update([...$changed, 'locked_fields' => array_values(array_unique([...$this->media->locked_fields, ...array_keys($changed)]))]);

        if (isset($changed['title'])) {
            $this->media->refreshSearchText();
        }

        Flux::toast(variant: 'success', text: __('Saved. Edited fields are locked against sync.'));
    }

    /**
     * Let the provider sync overwrite a field again.
     */
    public function unlock(string $field): void
    {
        Gate::authorize('access-admin');

        $this->media->update(['locked_fields' => array_values(array_diff($this->media->locked_fields, [$field]))]);
    }

    /**
     * Soft delete a record that should not be in the catalogue.
     */
    public function remove(): void
    {
        Gate::authorize('access-admin');

        $this->media->delete();

        Flux::toast(text: __('Record removed.'));

        $this->redirectRoute('admin.media.index', navigate: true);
    }

    private function stored(string $field): mixed
    {
        $value = $this->media->getAttribute($field);

        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}; ?>

<section class="py-12">
    <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('admin.media.index')" wire:navigate>{{ __('Media') }}</flux:button>
    <h1 class="mt-2 text-section text-ink">{{ $media->title }}</h1>
    <p class="mt-1 text-meta text-ink-muted">{{ $media->mediaType->name }} · {{ $media->slug }}</p>

    <form wire:submit="save" class="mt-6 max-w-2xl space-y-6">
        <flux:input wire:model="title" :label="__('Title')" required />
        <flux:textarea wire:model="description" :label="__('Description')" rows="6" />

        <flux:select wire:model="age_rating" :label="__('Age rating')">
            @foreach (App\Enums\AgeRating::cases() as $case)
                <flux:select.option :value="$case->value">{{ str($case->value)->headline() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="status" :label="__('Publication status')">
            @foreach (App\Enums\PublicationStatus::cases() as $case)
                <flux:select.option :value="$case->value">{{ str($case->value)->headline() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:button variant="filled" type="submit" data-test="save-media-button">{{ __('Save') }}</flux:button>
    </form>

    <div class="mt-12 max-w-2xl">
        <h2 class="text-lead text-ink">{{ __('Locked fields') }}</h2>
        <p class="mt-1 text-meta text-ink-muted">{{ __('A provider sync never overwrites these.') }}</p>

        <ul class="mt-4 space-y-2">
            @forelse ($media->locked_fields as $field)
                <li wire:key="locked-{{ $field }}" class="flex items-center justify-between rounded-lg bg-surface-card px-4 py-2">
                    <span class="text-body text-ink">{{ str($field)->headline() }}</span>
                    <flux:button size="sm" variant="filled" wire:click="unlock('{{ $field }}')">{{ __('Unlock') }}</flux:button>
                </li>
            @empty
                <li class="text-body text-ink-muted">{{ __('None.') }}</li>
            @endforelse
        </ul>
    </div>

    <div class="mt-12 max-w-2xl">
        <h2 class="text-lead text-ink">{{ __('Remove invalid record') }}</h2>
        <p class="mt-1 text-meta text-ink-muted">{{ __('Hides the record from the catalogue. It stays in the database.') }}</p>
        <flux:button class="mt-4" variant="filled" wire:click="remove" wire:confirm="{{ __('Remove this record from the catalogue?') }}" data-test="remove-media-button">
            {{ __('Remove') }}
        </flux:button>
    </div>
</section>
