<?php

use App\Enums\Visibility;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Privacy settings')] class extends Component {
    public string $profileVisibility = 'private';
    public string $libraryVisibility = 'private';
    public bool $personalizationEnabled = true;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $profile = Auth::user()->profile;

        $this->profileVisibility = $profile->profile_visibility->value;
        $this->libraryVisibility = $profile->library_visibility->value;
        $this->personalizationEnabled = $profile->personalization_enabled;
    }

    /**
     * Update the privacy settings for the currently authenticated user.
     */
    public function updatePrivacy(): void
    {
        $validated = $this->validate([
            'profileVisibility' => [Rule::enum(Visibility::class)],
            'libraryVisibility' => [Rule::enum(Visibility::class)],
            'personalizationEnabled' => ['boolean'],
        ]);

        Auth::user()->profile()->updateOrCreate([], [
            'profile_visibility' => $validated['profileVisibility'],
            'library_visibility' => $validated['libraryVisibility'],
            'personalization_enabled' => $validated['personalizationEnabled'],
        ]);

        Flux::toast(variant: 'success', text: __('Privacy settings updated.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading', ['title' => __('Privacy'), 'description' => __('Choose who can see your profile and library, then save.')])

    <flux:heading level="2" class="sr-only">{{ __('Privacy settings') }}</flux:heading>

    <x-pages::settings.layout>
        <x-settings-card :title="__('Who can see what')" :description="__('Your profile and library are private until you say otherwise.')">
        <form wire:submit="updatePrivacy" class="w-full space-y-6">
            <flux:radio.group wire:model="profileVisibility" :label="__('Profile visibility')">
                <flux:radio value="private" :label="__('Private')" :description="__('Only you can see your profile.')" />
                <flux:radio value="public" :label="__('Public')" :description="__('Anyone can see your profile.')" />
            </flux:radio.group>

            <flux:radio.group wire:model="libraryVisibility" :label="__('Library visibility')">
                <flux:radio value="private" :label="__('Private')" :description="__('Only you can see your library.')" />
                <flux:radio value="public" :label="__('Public')" :description="__('Anyone can see your library.')" />
            </flux:radio.group>

            <flux:separator variant="subtle" />

            <flux:switch
                wire:model="personalizationEnabled"
                :label="__('Personalization')"
                :description="__('Use your activity to recommend books. Turn off to stop recording it.')"
            />

            <flux:button variant="filled" type="submit" icon="check" icon:variant="outline" data-test="update-privacy-button">
                {{ __('Save') }}
            </flux:button>
        </form>
        </x-settings-card>
    </x-pages::settings.layout>
</section>
