<?php

use App\Actions\Preferences\SavePreferences;
use App\Discovery\PreferenceChoices;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Preferences')] class extends Component {
    /** @var list<string> media type slugs */
    public array $mediaTypes = [];

    /** @var list<string> genre slugs, families and subgenres */
    public array $genres = [];

    /** @var list<string> theme slugs */
    public array $themes = [];

    public function mount(): void
    {
        ['mediaTypes' => $this->mediaTypes, 'genres' => $this->genres, 'themes' => $this->themes] = app(PreferenceChoices::class)->chosen(Auth::user());
    }

    /**
     * Save the signed-in reader's formats, genres and themes (what is not ticked is removed).
     */
    public function save(): void
    {
        $user = Auth::user();
        $choices = app(PreferenceChoices::class);

        $this->validate($choices->rules($user, themes: true), $choices->messages());

        app(SavePreferences::class)->handle($user, $this->mediaTypes, $this->genres, $this->themes);

        Flux::toast(variant: 'success', text: __('Preferences saved.'));
    }
}; ?>

@php
    $user = auth()->user();
    $choices = app(\App\Discovery\PreferenceChoices::class);
    $families = $choices->genres($user);
@endphp

<section class="w-full">
    @include('partials.settings-heading', ['title' => __('Preferences'), 'description' => __('Tell us the formats, genres and themes you enjoy, and your recommendations start from there. Change them whenever your taste does.')])

    <x-pages::settings.layout>
        <form wire:submit="save" class="space-y-6">
            <x-settings-card :title="__('Formats')" :description="__('What you like to read. Pick as many as you like.')">
                <x-choice-chips :legend="__('Formats')" :hide-legend="true" :options="$choices->types()" wire:model="mediaTypes" data-test="types" />
                <flux:error name="mediaTypes.*" />
            </x-settings-card>

            <x-settings-card :title="__('Genres')" :description="__('Pick a whole family, or open it to choose subgenres.')">
                <x-genre-picker :families="$families" :open="$choices->familiesHolding($families, $genres)" wire:model="genres" data-test="genres" />
                <flux:error name="genres.*" />
            </x-settings-card>

            <x-settings-card :title="__('Themes')" :description="__('The ideas you keep coming back to, such as found family or revenge.')">
                <x-choice-chips :legend="__('Themes')" :hide-legend="true" :options="$choices->themes()" wire:model="themes" data-test="themes" />
                <flux:error name="themes.*" />
            </x-settings-card>

            <x-cta variant="secondary" type="submit" icon="check" data-test="update-preferences-button">{{ __('Save') }}</x-cta>
        </form>

        <x-settings-card :title="__('Start Over')" :description="__('Go through the short setup again. It starts from what you chose, and lets you add more stories you have read to your library.')">
            <x-cta variant="secondary" icon="arrow-path" :href="route('onboarding')" wire:navigate data-test="retake-onboarding">{{ __('Take the setup again') }}</x-cta>
        </x-settings-card>
    </x-pages::settings.layout>
</section>
