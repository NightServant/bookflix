<?php

use App\Actions\Library\AddToLibrary;
use App\Actions\Preferences\SavePreferences;
use App\Discovery\OnboardingPicks;
use App\Discovery\PreferenceChoices;
use App\Enums\ReadingStatus;
use App\Models\Media;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Three short steps that start a reader's taste (§33, §41): the formats they read, the genres they like, and at least three stories they have
 * read, which go into the library as completed. Every step can be skipped, and the reader can come back to it from Settings, Preferences.
 * What is picked is slugs (formats, genres) and story ids, checked again when finishing; nothing on the page says whose it is, it is always the
 * signed-in reader's. Finishing saves the picks, adds the stories with the `onboarding` surface (an opted-out reader leaves no interaction
 * behind), and marks the reader onboarded; skipping only marks them.
 */
new #[Title('Set up your library')] class extends Component {
    private const STEPS = 3;

    private const MIN_STORIES = 3;

    /** The step the reader is on. Only the buttons move it. */
    #[Locked]
    public int $step = 1;

    /** @var list<string> media type slugs */
    public array $mediaTypes = [];

    /** @var list<string> genre slugs, families and subgenres */
    public array $genres = [];

    /** @var list<int|string> story ids */
    public array $stories = [];

    /**
     * Taking onboarding again starts from what the reader chose before.
     */
    public function mount(): void
    {
        ['mediaTypes' => $this->mediaTypes, 'genres' => $this->genres] = app(PreferenceChoices::class)->chosen(Auth::user());
    }

    /**
     * The header is a band across the whole page, so the layout leaves `<main>` bare (see layouts/app).
     */
    public function render(): View
    {
        return $this->view()->layoutData(['flush' => true]);
    }

    /**
     * The stories of the last step, for the formats and genres chosen so far (see OnboardingPicks).
     *
     * @return EloquentCollection<int, Media>
     */
    #[Computed]
    public function picks(): EloquentCollection
    {
        return app(OnboardingPicks::class)->for(Auth::user(), $this->strings($this->mediaTypes), $this->strings($this->genres));
    }

    /**
     * Check the step just answered and open the next one. The last step has no next: it finishes.
     */
    public function next(): void
    {
        $choices = app(PreferenceChoices::class);
        $rules = $choices->rules(Auth::user());

        if ($this->step === 1) {
            $this->validate(Arr::only($rules, ['mediaTypes', 'mediaTypes.*']), $choices->messages());
        } elseif ($this->step === 2) {
            $this->validate(Arr::only($rules, ['genres', 'genres.*']), $choices->messages());
        }

        if ($this->step < self::STEPS) {
            $this->step++;
            $this->moved();
        }

        // Stories picked before the choices changed may not be on offer any more: only what is shown can be ticked, so only that is kept.
        if ($this->step === self::STEPS) {
            $this->stories = array_values(array_intersect($this->storyIds(), $this->picks->pluck('id')->all()));
        }
    }

    public function back(): void
    {
        $this->goTo($this->step - 1);
    }

    /**
     * Go back to a step already answered. A step ahead has to be answered first.
     */
    public function goTo(int $step): void
    {
        if ($step >= 1 && $step < $this->step) {
            $this->step = $step;
            $this->moved();
        }
    }

    /**
     * Save the picks, add the stories as completed and mark the reader onboarded, or say what is missing.
     */
    public function finish(): void
    {
        $user = Auth::user();
        $choices = app(PreferenceChoices::class);

        // A story counts when it exists, is not merged away and is one this reader may see; a list longer than the page ever offers goes to the rule below as it is.
        $ids = $this->storyIds();
        $this->stories = count($ids) > OnboardingPicks::LIMIT
            ? $ids
            : Media::query()->unmerged()->visibleTo($user)->whereIn('media.id', $ids)->pluck('media.id')->map(fn (mixed $id): int => (int) $id)->all();

        $this->validate([
            ...Arr::only($choices->rules($user), ['mediaTypes', 'mediaTypes.*', 'genres', 'genres.*']),
            'stories' => ['array', 'min:'.self::MIN_STORIES, 'max:'.OnboardingPicks::LIMIT],
        ], [
            ...$choices->messages(),
            'stories.min' => __('Pick at least :count stories to finish, or skip for now.', ['count' => self::MIN_STORIES]),
            'stories.max' => __('Pick up to :count stories.', ['count' => OnboardingPicks::LIMIT]),
        ]);

        DB::transaction(function () use ($user): void {
            app(SavePreferences::class)->handle($user, $this->mediaTypes, $this->genres);

            $stories = Media::query()->whereIn('id', $this->stories)->get()->keyBy('id');

            foreach ($this->stories as $id) {
                app(AddToLibrary::class)->handle($user, $stories->get($id), ReadingStatus::Completed, ['surface' => 'onboarding']);
            }

            $this->markOnboarded($user);
        });

        $this->redirectRoute('home', navigate: true);
    }

    /**
     * Leave without saying anything: the reader is marked onboarded and nothing else is written.
     */
    public function skip(): void
    {
        $this->markOnboarded(Auth::user());

        $this->redirectRoute('home', navigate: true);
    }

    private function markOnboarded(User $user): void
    {
        if ($user->profile->onboarded_at === null) {
            $user->profile()->updateOrCreate([], ['onboarded_at' => now()]);
            $user->unsetRelation('profile');
        }
    }

    /**
     * Tell the page the step changed, so it can bring focus to the new step's heading.
     */
    private function moved(): void
    {
        $this->dispatch('onboarding-step');
    }

    /**
     * @return list<int>
     */
    private function storyIds(): array
    {
        $ids = [];

        foreach ($this->stories as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_filter($values, 'is_string'));
    }
}; ?>

@php
    $user = auth()->user();
    $choices = app(\App\Discovery\PreferenceChoices::class);
    $total = 3;
    $titles = [
        1 => [__('What do you like to read?'), __('Pick the formats you enjoy. Choose as many as you like, or none.')],
        2 => [__('Which genres draw you in?'), __('Pick a whole family, or open it to choose subgenres. The more you tell us, the better your first recommendations.')],
        3 => [__('Which of these have you read?'), __('Pick at least :count stories you have read and liked. They go into your library as completed, so your recommendations start from real taste.', ['count' => 3])],
    ];
@endphp

<div x-data @onboarding-step.window="$nextTick(() => $refs.heading?.focus())" class="pb-12">
    <header class="bg-surface-deep px-gutter py-8 lg:py-12">
        <x-page-header :title="__('Make Bookflix yours')" :description="__('Tell us what you like to read and a few stories you have loved, and your library and recommendations start there. It takes a minute, and every step can be skipped.')">
            <x-slot:actions>
                <x-cta variant="secondary" icon="forward" wire:click="skip" data-test="skip-onboarding">{{ __('Skip for now') }}</x-cta>
            </x-slot:actions>
        </x-page-header>

        <x-stepper class="mt-8" :label="__('Onboarding steps')" :steps="[__('Types'), __('Genres'), __('Stories')]" :current="$step" action="goTo" />
    </header>

    <div class="px-gutter">
        <section wire:key="step-{{ $step }}" aria-labelledby="step-heading" @class(['mt-10', 'max-w-3xl' => $step < 3])>
            <p class="text-legal uppercase tracking-widest text-ink-subtle">{{ __('Step :number of :total', ['number' => $step, 'total' => $total]) }}</p>
            <h2 id="step-heading" x-ref="heading" tabindex="-1" class="mt-2 text-section tracking-tight text-ink outline-none">{{ $titles[$step][0] }}</h2>
            <p class="mt-2 max-w-prose text-body text-ink-subtle text-pretty">{{ $titles[$step][1] }}</p>

            <div class="mt-8">
                @if ($step === 1)
                    <x-choice-chips :legend="__('Formats')" :hide-legend="true" :options="$choices->types()" wire:model="mediaTypes" data-test="types" />
                    <flux:error name="mediaTypes.*" />
                    <flux:error name="mediaTypes" />

                    <p aria-live="polite" x-data x-text="$wire.mediaTypes.length === 0 ? @js(__('Nothing picked yet. That is fine.')) : $wire.mediaTypes.length + ' ' + ($wire.mediaTypes.length === 1 ? @js(__('format picked')) : @js(__('formats picked')))" class="mt-6 text-meta text-ink-subtle">
                        {{ count($mediaTypes) === 0 ? __('Nothing picked yet. That is fine.') : trans_choice(':count format picked|:count formats picked', count($mediaTypes)) }}
                    </p>
                @elseif ($step === 2)
                    @php($families = $choices->genres($user))

                    <x-genre-picker :families="$families" :open="$choices->familiesHolding($families, $genres)" wire:model="genres" data-test="genres" />
                    <flux:error name="genres.*" />
                    <flux:error name="genres" />

                    <p aria-live="polite" x-data x-text="$wire.genres.length === 0 ? @js(__('Nothing picked yet. That is fine.')) : $wire.genres.length + ' ' + ($wire.genres.length === 1 ? @js(__('genre picked')) : @js(__('genres picked')))" class="mt-6 text-meta text-ink-subtle">
                        {{ count($genres) === 0 ? __('Nothing picked yet. That is fine.') : trans_choice(':count genre picked|:count genres picked', count($genres)) }}
                    </p>
                @else
                    @php($picks = $this->picks)

                    @if ($picks->isEmpty())
                        <x-empty-state :title="__('No stories to pick from yet')" data-test="no-picks">
                            <p class="mt-2 text-body text-ink-subtle text-pretty">{{ __('The catalog is still filling up. Skip this for now: you can add the stories you have read to your library any time.') }}</p>
                        </x-empty-state>
                    @else
                        <ul class="grid grid-cols-3 gap-x-3 gap-y-6 sm:grid-cols-4 sm:gap-x-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8" data-test="picks">
                            @foreach ($picks as $story)
                                <li wire:key="pick-{{ $story->id }}">
                                    <x-cover-tile :media="$story" fluid badge selectable wire:model="stories" :value="$story->id" />
                                </li>
                            @endforeach
                        </ul>

                        <p aria-live="polite" data-test="picked" x-data x-text="Math.min($wire.stories.length, 3) + ' ' + @js(__('of')) + ' 3 ' + @js(__('needed')) + ($wire.stories.length > 3 ? ' (' + $wire.stories.length + ' ' + @js(__('picked')) + ')' : '')" class="mt-6 text-meta tabular-nums text-ink-subtle">
                            {{ __(':picked of :needed needed', ['picked' => min(count($stories), 3), 'needed' => 3]) }}
                        </p>
                    @endif

                    <flux:error name="stories" />
                @endif
            </div>

            <div class="mt-8 flex flex-wrap items-center gap-3">
                @if ($step > 1)
                    <x-cta variant="secondary" icon="arrow-left" wire:click="back" data-test="back">{{ __('Back') }}</x-cta>
                @endif

                @if ($step < $total)
                    <x-cta icon:trailing="arrow-right" wire:click="next" data-test="next">{{ __('Next') }}</x-cta>
                @else
                    <x-cta icon="check" wire:click="finish" data-test="finish">{{ __('Finish') }}</x-cta>
                @endif
            </div>
        </section>
    </div>
</div>
