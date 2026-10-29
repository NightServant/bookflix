<?php

use App\Access\MatureAccess;
use App\Enums\Visibility;
use App\Jobs\ExportUserData;
use App\Library\ExportStorage;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
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

    /**
     * Ask for a copy of the signed-in reader's own data (the job writes it and mails the link). It takes no id: it is always the reader who is
     * signed in. One request per reader every `cooldown_minutes`; a second click inside that time only says when the next is possible.
     */
    public function exportData(): void
    {
        $user = Auth::user();

        $queued = RateLimiter::attempt($this->exportKey($user->id), 1, function () use ($user): bool {
            ExportUserData::dispatch($user->id);

            return true;
        }, $this->exportCooldown());

        unset($this->export);

        if (! $queued) {
            Flux::toast(variant: 'warning', text: __('You asked for an export a moment ago. You can ask again in :wait.', ['wait' => $this->export['waitText']]));

            return;
        }

        Flux::toast(variant: 'success', text: __('Preparing your export. We will email you when it is ready.'));
    }

    /**
     * Where the export stands: how long until another can be asked for, whether one is being prepared, and the newest file still on disk (with a
     * fresh signed link, which only this signed-in reader can use). A file older than the last request is the previous export, not the new one.
     *
     * @return array{wait: int, waitText: string, preparing: bool, ready: bool, previous: bool, at: Illuminate\Support\Carbon|null, link: string|null}
     */
    #[Computed]
    public function export(): array
    {
        $user = Auth::user();
        $exports = app(ExportStorage::class);
        $wait = RateLimiter::availableIn($this->exportKey($user->id));
        $latest = $exports->latest($user->id);
        $requestedAt = $wait > 0 ? now()->subSeconds($this->exportCooldown() - $wait)->startOfSecond() : null;
        $ready = $latest !== null && ($requestedAt === null || $latest['at']->greaterThanOrEqualTo($requestedAt));

        return [
            'wait' => $wait,
            'waitText' => trans_choice(':count minute|:count minutes', max(1, (int) ceil($wait / 60))),
            'preparing' => $wait > 0 && ! $ready,
            'ready' => $ready,
            'previous' => $latest !== null && ! $ready,
            'at' => $latest['at'] ?? null,
            'link' => $latest === null ? null : $exports->link($user->id, $latest['file']),
        ];
    }

    private function exportKey(int $userId): string
    {
        return "export-data:{$userId}";
    }

    private function exportCooldown(): int
    {
        return (int) config('bookflix.exports.cooldown_minutes') * 60;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading', ['title' => __('Privacy'), 'description' => __('Choose who can see your profile and library, whether mature titles are shown, and take a copy of your data.')])

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

        @php($export = $this->export)

        <x-settings-card :title="__('Export Your Data')" :description="__('Take a copy of what Bookflix holds about you: your profile, library, collections, ratings, reading progress, history and activity, as JSON files in one zip. We email you a link when it is ready. It works for :hours hours, and the file is deleted after :days days.', ['hours' => config('bookflix.exports.link_hours'), 'days' => config('bookflix.exports.retention_days')])">
            <div class="space-y-4">
                <x-cta variant="secondary" icon="arrow-down-tray" icon:variant="outline" wire:click="exportData" :disabled="$export['wait'] > 0" data-test="export-data-button">
                    {{ __('Export my data') }}
                </x-cta>

                <div role="status" aria-live="polite" @if ($export['preparing']) wire:poll.5s @endif class="max-w-prose space-y-2 text-meta text-ink-subtle text-pretty empty:hidden">
                    @if ($export['preparing'])
                        <p>{{ __('Preparing your export. We will email :email when it is ready, usually within a few minutes.', ['email' => auth()->user()->email]) }}</p>
                    @endif

                    @if ($export['ready'])
                        <p class="text-ink">{{ __('Your export is ready.') }} <span class="text-ink-subtle">{{ __('Prepared :when. We emailed a link to :email; this one works for :hours hours.', ['when' => $export['at']->diffForHumans(), 'email' => auth()->user()->email, 'hours' => config('bookflix.exports.link_hours')]) }}</span></p>
                    @elseif ($export['previous'])
                        <p>{{ __('Your previous export, prepared :when, stays available until the new one is ready.', ['when' => $export['at']->diffForHumans()]) }}</p>
                    @endif

                    @if ($export['link'])
                        <p>
                            <a href="{{ $export['link'] }}" data-test="export-download-link" class="focus-ring inline-flex min-h-11 items-center gap-2 rounded-control text-body font-medium text-ink underline underline-offset-4 hover:text-ink-subtle">
                                <flux:icon name="arrow-down-tray" variant="outline" class="size-5" />
                                {{ __('Download the zip') }}
                            </a>
                        </p>
                    @endif

                    @if ($export['wait'] > 0)
                        <p>{{ __('You can ask for another in :wait.', ['wait' => $export['waitText']]) }}</p>
                    @endif
                </div>
            </div>
        </x-settings-card>
    </x-pages::settings.layout>
</section>
