<?php

use App\Access\MatureAccess;
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

    public bool $showMature = false;

    public bool $confirmAdult = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $profile = Auth::user()->profile;

        $this->profileVisibility = $profile->profile_visibility->value;
        $this->libraryVisibility = $profile->library_visibility->value;
        $this->personalizationEnabled = $profile->personalization_enabled;
        $this->showMature = MatureAccess::allows(Auth::user());
        $this->confirmAdult = $this->showMature;
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

    /**
     * Turn mature and adult titles on or off. Turning them on needs the reader's confirmation that they are 18 or older, stored with the moment
     * it was given; turning them off forgets it, so the next time asks again.
     */
    public function updateMature(): void
    {
        $this->validate([
            'showMature' => ['boolean'],
            'confirmAdult' => ['boolean', 'accepted_if:showMature,true'],
        ], [
            'confirmAdult.accepted_if' => __('Confirm that you are 18 or older to show mature and adult titles.'),
        ]);

        $profile = Auth::user()->profile;
        $confirmedAt = $this->showMature ? ($profile->adult_confirmed_at ?? now()) : null;

        Auth::user()->profile()->updateOrCreate([], ['show_mature' => $this->showMature, 'adult_confirmed_at' => $confirmedAt]);

        $this->confirmAdult = $this->showMature;

        Flux::toast(variant: 'success', text: $this->showMature ? __('Mature and adult titles are now shown.') : __('Mature and adult titles are hidden.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading', ['title' => __('Privacy'), 'description' => __('Choose who can see your profile and library, and whether mature titles are shown, then save.')])

    <flux:heading level="2" class="sr-only">{{ __('Privacy settings') }}</flux:heading>

    <x-pages::settings.layout>
        <x-settings-card :title="__('Who Can See What')" :description="__('Your profile and library are private until you say otherwise.')">
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

            <x-cta variant="secondary" type="submit" icon="check" icon:variant="outline" data-test="update-privacy-button">
                {{ __('Save') }}
            </x-cta>
        </form>
        </x-settings-card>

        <x-settings-card :title="__('Mature and Adult Titles')" :description="__('Adult-rated titles and the mature, erotic and controversial genres stay hidden everywhere in Bookflix until you turn this on.')">
            <form wire:submit="updateMature" class="w-full space-y-6">
                <flux:switch
                    wire:model="showMature"
                    :label="__('Show mature and adult titles')"
                    :description="__('Off by default. When on, they appear in search, Discover, shelves and recommendations. Filters never show them on their own.')"
                />

                <div x-data x-show="$wire.showMature" style="display: none">
                    <flux:field variant="inline">
                        <flux:checkbox wire:model="confirmAdult" />
                        <flux:label>{{ __('I confirm that I am 18 years of age or older.') }}</flux:label>
                        <flux:error name="confirmAdult" />
                    </flux:field>
                </div>

                <x-cta variant="secondary" type="submit" icon="check" icon:variant="outline" data-test="update-mature-button">
                    {{ __('Save') }}
                </x-cta>
            </form>
        </x-settings-card>
    </x-pages::settings.layout>
</section>
