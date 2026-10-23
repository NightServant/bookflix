<?php

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules, WithFileUploads;

    public string $name = '';
    public string $email = '';

    public string $bio = '';

    /** @var list<string> */
    public array $preferredLanguages = [];

    public string $readingLength = 'any';

    public $avatar = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;

        $profile = Auth::user()->profile;
        $this->bio = $profile->bio ?? '';
        $this->preferredLanguages = $profile->preferred_languages;
        $this->readingLength = $profile->reading_length->value;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Update the reading profile (bio, languages, reading length, avatar).
     *
     * ponytail: avatar is stored as uploaded, no resizing; add an image library if avatar bandwidth becomes a problem.
     */
    public function updateReadingProfile(): void
    {
        $validated = $this->validate($this->readingProfileRules());

        $user = Auth::user();
        $disk = Storage::disk(config('bookflix.avatar_disk'));
        $previousAvatarPath = $user->profile->avatar_path;

        $data = [
            'bio' => $validated['bio'] ?: null,
            'preferred_languages' => array_values($validated['preferredLanguages']),
            'reading_length' => $validated['readingLength'],
        ];

        if ($this->avatar) {
            $data['avatar_path'] = $this->avatar->storeAs(
                "avatars/{$user->id}",
                Str::ulid().'.'.$this->avatar->extension(),
                config('bookflix.avatar_disk'),
            );
        }

        $user->setRelation('profile', $user->profile()->updateOrCreate([], $data));

        if ($this->avatar && $previousAvatarPath) {
            $disk->delete($previousAvatarPath);
        }

        $this->reset('avatar');

        Flux::toast(variant: 'success', text: __('Reading profile updated.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('home', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeactivateAccount(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="filled" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

            </div>
        </form>

        <flux:separator class="my-8" />

        <form wire:submit="updateReadingProfile" class="my-6 w-full space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Reading profile') }}</flux:heading>
                <flux:subheading>{{ __('Tell us how you like to read') }}</flux:subheading>
            </div>

            <div class="flex items-center gap-4">
                <flux:avatar
                    size="lg"
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    :src="$avatar?->isPreviewable() ? $avatar->temporaryUrl() : auth()->user()->avatarUrl()"
                />

                <div class="flex-1">
                    <flux:input wire:model="avatar" :label="__('Avatar')" type="file" accept="image/jpeg,image/png,image/webp" />
                </div>
            </div>

            <flux:textarea wire:model="bio" :label="__('Bio')" rows="3" maxlength="1000" />

            <flux:checkbox.group wire:model="preferredLanguages" :label="__('Preferred languages')">
                @foreach (config('bookflix.languages') as $code)
                    <flux:checkbox
                        :value="$code"
                        :label="class_exists(Locale::class) ? ucfirst(Locale::getDisplayLanguage($code, app()->getLocale())) : strtoupper($code)"
                    />
                @endforeach
            </flux:checkbox.group>

            <flux:radio.group wire:model="readingLength" :label="__('Preferred reading length')">
                @foreach (App\Enums\ReadingLength::cases() as $length)
                    <flux:radio :value="$length->value" :label="__(ucfirst($length->value))" />
                @endforeach
            </flux:radio.group>

            <flux:button variant="filled" type="submit" data-test="update-reading-profile-button">
                {{ __('Save') }}
            </flux:button>
        </form>

        @if ($this->showDeactivateAccount)
            <livewire:pages::settings.deactivate-account-form />
        @endif
    </x-pages::settings.layout>
</section>
