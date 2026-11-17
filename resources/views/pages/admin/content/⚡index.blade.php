<?php

use App\Actions\Reader\UnverifyContentSource;
use App\Actions\Reader\VerifyContentSource;
use App\Enums\AccessType;
use App\Enums\ContentStatus;
use App\Models\ContentSource;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Reading files: the sources an admin may vouch for. Nothing is streamed to readers until a source is verified here, with the license text and the
 * rights holder on record. A reader's own upload (user_owned) is private: it is never listed and its id answers 404 on every action.
 */
new #[Title('Reading files')] class extends Component {
    use WithPagination;

    #[Url(as: 'show')]
    public string $show = 'all';

    public ?int $verifying = null;

    public string $license = '';

    public string $rightsHolder = '';

    public function boot(): void
    {
        Gate::authorize('access-admin');
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function filters(): array
    {
        return ['all' => __('All'), 'unverified' => __('Unverified'), 'verified' => __('Verified'), 'failed' => __('Failed')];
    }

    /**
     * Newest first. The story is named (its title is all that is read), the file path and checksum never leave the database (a list only learns whether a file is stored).
     *
     * @return LengthAwarePaginator<int, ContentSource>
     */
    #[Computed]
    public function sources(): LengthAwarePaginator
    {
        return $this->listed()
            ->when($this->show === 'unverified', fn (Builder $query) => $query->whereNot(fn (Builder $verified) => $this->verified($verified)))
            ->when($this->show === 'verified', fn (Builder $query) => $query->where(fn (Builder $verified) => $this->verified($verified)))
            ->when($this->show === 'failed', fn (Builder $query) => $query->where('status', ContentStatus::Failed->value))
            ->select(['id', 'media_id', 'access_type', 'format', 'license', 'verified_by', 'verified_at', 'status', 'failure_reason', 'created_at'])
            ->selectRaw('(storage_path is not null) as has_file')
            ->with(['media' => fn ($query) => $query->withTrashed()->select(['id', 'title', 'deleted_at'])])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * The rows `ContentSource::isVerified()` accepts: an admin stamped them (the list never holds a reader's own upload, which needs no admin).
     *
     * @param  Builder<ContentSource>  $query
     */
    protected function verified(Builder $query): void
    {
        $query->whereNotNull('verified_at')->whereNotNull('verified_by');
    }

    /**
     * Opens the verify dialog with what is on record, so a license can be corrected rather than retyped.
     */
    public function edit(int $sourceId): void
    {
        Gate::authorize('access-admin');

        $source = $this->listed()->findOrFail($sourceId);

        $this->verifying = $source->id;
        $this->license = $source->license;
        $this->rightsHolder = (string) $source->rights_holder;
        $this->resetErrorBag();

        Flux::modal('verify-content')->show();
    }

    public function save(VerifyContentSource $verify): void
    {
        Gate::authorize('access-admin');

        $source = $this->listed()->with('media:id,title')->findOrFail($this->verifying);

        $verify->handle($source, auth()->user(), $this->license, $this->rightsHolder === '' ? null : $this->rightsHolder);

        $this->reset('verifying', 'license', 'rightsHolder');
        unset($this->sources);

        Flux::modal('verify-content')->close();
        Flux::toast(variant: 'success', text: __(':title is verified.', ['title' => $source->media?->title]));
    }

    public function unverify(int $sourceId, UnverifyContentSource $unverify): void
    {
        Gate::authorize('access-admin');

        $source = $this->listed()->with('media:id,title')->findOrFail($sourceId);

        $unverify->handle($source);

        unset($this->sources);

        Flux::toast(variant: 'success', text: __(':title is no longer offered to readers.', ['title' => $source->media?->title]));
    }

    /**
     * What this page may list and change: every source except a reader's own upload.
     *
     * @return Builder<ContentSource>
     */
    private function listed(): Builder
    {
        return ContentSource::query()->where('access_type', '!=', AccessType::UserOwned->value);
    }
}; ?>

<section class="py-8">
    <x-admin-header :title="__('Reading files')" :description="__('Books Bookflix may let readers open. A source is shown to readers only after you verify it, with its license and rights holder on record. Readers\' own uploads are private and not listed.')" />

    @php($shown = array_key_exists($show, $this->filters) ? $show : 'all')

    <nav aria-label="{{ __('Show') }}" class="mt-6 flex flex-wrap gap-2">
        @foreach ($this->filters as $value => $label)
            <x-chip wire:key="show-{{ $value }}" :href="route('admin.content', ['show' => $value])" wire:click.prevent="$set('show', '{{ $value }}')" :active="$shown === $value" class="min-h-11 px-4">{{ $label }}</x-chip>
        @endforeach
    </nav>

    @if ($this->sources->isEmpty())
        @if ($shown === 'all')
            <x-admin-empty class="mt-6 rounded-card bg-surface-card" icon="document-check" :title="__('No reading files yet')" :description="__('Public-domain imports and licensed files appear here, ready to be verified.')" />
        @else
            <x-admin-empty class="mt-6 rounded-card bg-surface-card" icon="document-check" :title="__('Nothing here')" :description="__('No reading file matches this filter.')" />
        @endif
    @else
        <x-admin-table :label="__('Reading files')" class="mt-6">
            {{-- A table from md up; below it each source is a card, each cell named by its data-label (the header row is hidden there). --}}
            <flux:table class="table-auto! max-md:block max-md:whitespace-normal! max-md:[&_tbody]:block max-md:[&_thead]:hidden max-md:[&_tr]:block max-md:[&_td]:block max-md:[&_td]:border-0 max-md:[&_td]:px-0">
                <flux:table.columns>
                    <flux:table.column>{{ __('Story') }}</flux:table.column>
                    <flux:table.column>{{ __('Access') }}</flux:table.column>
                    <flux:table.column>{{ __('Format') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('License') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->sources as $source)
                        @php($cell = 'max-md:flex! max-md:gap-4 max-md:py-1 max-md:before:w-24 max-md:before:shrink-0 max-md:before:text-ink-subtle max-md:before:content-[attr(data-label)]')
                        @php($title = $source->media?->title ?? __('Removed story'))
                        <flux:table.row wire:key="source-{{ $source->id }}" class="max-md:mb-4 max-md:rounded-card max-md:bg-surface-card max-md:p-4">
                            <flux:table.cell class="md:max-w-72 md:whitespace-normal!">
                                @if ($source->media && ! $source->media->trashed())
                                    <a href="{{ route('admin.media.edit', $source->media) }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control text-body text-ink underline-offset-4 hover:underline">{{ $title }}</a>
                                @else
                                    <span class="block text-body text-ink-subtle">{{ $title }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell data-label="{{ __('Access') }}" class="{{ $cell }}">{{ $source->access_type->label() }}</flux:table.cell>
                            <flux:table.cell data-label="{{ __('Format') }}" class="{{ $cell }}">{{ $source->format->label() }}</flux:table.cell>
                            <flux:table.cell data-label="{{ __('Status') }}" class="{{ $cell }}">
                                <div>
                                    @if ($source->isVerified())
                                        <span class="inline-flex items-center gap-1.5 text-body text-ink"><flux:icon name="check-circle" variant="outline" class="size-5" />{{ __('Verified') }}</span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 text-body text-ink-subtle"><flux:icon name="clock" variant="outline" class="size-5" />{{ __('Unverified') }}</span>
                                    @endif
                                    @if ($source->status === \App\Enums\ContentStatus::Failed)
                                        <span class="block text-meta text-ink-subtle">{{ $source->failure_reason ? __('Failed: :reason', ['reason' => $source->failure_reason]) : __('Failed') }}</span>
                                    @elseif ($source->status === \App\Enums\ContentStatus::Pending && $source->access_type !== \App\Enums\AccessType::ExternalLink)
                                        <span class="block text-meta text-ink-subtle">{{ __('File pending') }}</span>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell data-label="{{ __('License') }}" class="{{ $cell }} md:max-w-64 md:whitespace-normal!">
                                <span class="line-clamp-2 text-meta text-ink-subtle">{{ $source->license }}</span>
                            </flux:table.cell>
                            <flux:table.cell data-label="{{ __('Actions') }}" class="{{ $cell }} max-md:items-center">
                                <div class="flex items-center gap-2 md:justify-end max-md:flex-1 max-md:justify-end">
                                    <flux:button variant="filled" class="min-h-11" icon="shield-check" icon:variant="outline" wire:click="edit({{ $source->id }})" aria-label="{{ __('Verify :title', ['title' => $title]) }}" data-test="verify-{{ $source->id }}">{{ $source->isVerified() ? __('Edit') : __('Verify') }}</flux:button>
                                    @if ($source->isVerified() || $source->has_file)
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button variant="filled" class="min-h-11 min-w-11" icon="ellipsis-horizontal" icon:variant="outline" aria-label="{{ __('More actions for :title', ['title' => $title]) }}" />
                                            <flux:menu class="shadow-overlay!">
                                                @if ($source->has_file)
                                                    <flux:menu.item :href="\App\Reader\ContentUrl::preview(auth()->user(), $source)" target="_blank" icon="arrow-top-right-on-square" class="min-h-11! pe-4!" data-test="preview-{{ $source->id }}">{{ __('Preview file') }}</flux:menu.item>
                                                @endif
                                                @if ($source->isVerified())
                                                <flux:menu.item wire:click="unverify({{ $source->id }})" icon="x-circle" class="min-h-11! pe-4!" data-test="unverify-{{ $source->id }}">{{ __('Unverify') }}</flux:menu.item>
                                                @endif
                                            </flux:menu>
                                        </flux:dropdown>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-admin-table>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-meta text-ink-subtle">{{ trans_choice(':count reading file|:count reading files', $this->sources->total(), ['count' => Number::format($this->sources->total())]) }}</p>
            <x-pager :paginator="$this->sources" class="justify-end" />
        </div>
    @endif

    <flux:modal name="verify-content" class="w-full max-w-lg text-ink shadow-overlay!">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Verify this source') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Readers can open it once you save. Record why Bookflix may offer it, and who holds the rights.') }}</flux:text>
            </div>

            <flux:textarea wire:model="license" :label="__('License')" rows="4" maxlength="2000" required />
            <flux:input wire:model="rightsHolder" :label="__('Rights holder (optional)')" maxlength="255" autocomplete="off" />

            <div class="flex flex-wrap justify-end gap-3">
                <flux:modal.close>
                    <x-cta variant="secondary" icon="x-mark">{{ __('Cancel') }}</x-cta>
                </flux:modal.close>
                <flux:button type="submit" variant="filled" class="focus-ring min-h-11" icon="check" icon:variant="outline" wire:loading.attr="disabled" wire:target="save">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
