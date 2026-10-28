@props(['years'])

{{--
    The filter controls of Search and Discover: seven independent groups (format, genre and subgenre, themes, tropes, editorial demographic,
    audience rating, content warnings) and the publication details, each a disclosure section with the count of its choices.
    OR within a group, AND between groups: the groups never feed one another, and a content warning is only ever excluded.

    This is the markup only. The page renders it once, inside a <template> beside the JSON data island (see <x-filter-islands>), and the Alpine
    data `filterPanel` (resources/js/filter-panel.js) puts one copy where it belongs: the sidebar from lg, the sheet below. Everything here reads
    that scope: `data` (the island), `draft` (the choices, committed on a pause or on Apply), `query` (the filter-the-list texts).
    A native <input type="checkbox"> is used throughout, so labels, Space, the focus ring and the mixed state of a family are the browser's own.
--}}
<div class="flex flex-col">
    <x-filter-section name="types" :title="__('Format')">
        <x-filter-checks model="mediaTypes" source="types" :columns="2" />
    </x-filter-section>

    <x-filter-section name="genres" :title="__('Genre')">
        <p id="fp-genres-help" class="mb-3 text-meta text-ink-subtle">{{ __('Choose a family to include every subgenre in it, or open it and choose subgenres one by one.') }}</p>

        <x-filter-search model="genres" :label="__('Search genres and subgenres')" status="genreStatus" />

        <div @keydown="navigate($event)" class="mt-2">
            <template x-if="query.genres.trim() === ''">
                <ul aria-describedby="fp-genres-help">
                    <template x-for="family in data.genres" :key="family.slug">
                        <li data-family class="border-t border-hairline first:border-t-0">
                            <div class="flex items-center">
                                <label class="flex size-11 shrink-0 cursor-pointer items-center justify-center">
                                    <input
                                        type="checkbox"
                                        class="filter-check"
                                        :aria-label="family.name"
                                        :data-genre="family.slug"
                                        :checked="genreState(family.slug) === 'on'"
                                        x-effect="$el.indeterminate = genreState(family.slug) === 'mixed'"
                                        @change="toggleGenre(family.slug)"
                                        data-nav
                                    >
                                </label>
                                <button
                                    type="button"
                                    data-nav
                                    data-family-toggle
                                    :data-slug="family.slug"
                                    :aria-expanded="isFamilyOpen(family.slug)"
                                    :aria-controls="'fp-family-' + family.slug"
                                    @click="toggleFamily(family.slug)"
                                    class="focus-ring flex min-h-11 min-w-0 flex-1 items-center justify-between gap-3 rounded-control pe-2 ps-1 text-start text-body text-ink hover:bg-surface-input"
                                >
                                    <span x-text="family.name" class="min-w-0"></span>
                                    <span class="flex shrink-0 items-center gap-2 text-ink-subtle">
                                        <span x-show="family.count > 0" x-text="family.count" class="text-meta tabular-nums"></span>
                                        <flux:icon name="chevron-down" variant="micro" class="size-5 motion-safe:transition-transform motion-safe:duration-(--motion-fast)" x-bind:class="isFamilyOpen(family.slug) && 'rotate-180'" />
                                    </span>
                                </button>
                            </div>

                            <div :id="'fp-family-' + family.slug" x-show="isFamilyOpen(family.slug)" style="display: none">
                                <template x-if="isFamilyOpen(family.slug)">
                                    <div class="ms-5 border-s border-hairline pb-3 ps-3">
                                        <template x-for="group in family.groups" :key="group.name ?? ''">
                                            <div role="group" :aria-label="group.name ?? family.name" class="pt-2">
                                                <p x-show="group.name" x-text="group.name" class="px-2 pb-1 text-legal tracking-wide text-ink-subtle uppercase"></p>
                                                <ul>
                                                    <template x-for="item in group.items" :key="item.slug">
                                                        <li>
                                                            <label class="filter-row" :class="genreState(item.slug) === 'implied' && 'cursor-default'">
                                                                <input
                                                                    type="checkbox"
                                                                    class="filter-check"
                                                                    :data-genre="item.slug"
                                                                    :checked="genreState(item.slug) !== 'off'"
                                                                    :data-implied="genreState(item.slug) === 'implied' ? '' : null"
                                                                    :aria-disabled="genreState(item.slug) === 'implied' ? 'true' : null"
                                                                    @click="genreState(item.slug) === 'implied' && $event.preventDefault()"
                                                                    @change="toggleGenre(item.slug)"
                                                                    data-nav
                                                                >
                                                                <span class="min-w-0"><span x-text="item.name"></span><span class="sr-only" x-text="impliedText(item.slug) && ', ' + impliedText(item.slug)"></span></span>
                                                                <span class="ms-auto shrink-0 text-meta tabular-nums text-ink-subtle" x-text="genreState(item.slug) === 'implied' ? @js(__('Included')) : (item.count || '')"></span>
                                                            </label>
                                                        </li>
                                                    </template>
                                                </ul>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </li>
                    </template>
                </ul>
            </template>

            <template x-if="query.genres.trim() !== ''">
                <div>
                    <p x-show="genreMatches.length === 0" style="display: none" class="px-2 py-3 text-meta text-ink-subtle">{{ __('No genres match. Try a shorter word.') }}</p>
                    <ul>
                        <template x-for="hit in genreMatches" :key="hit.slug">
                            <li>
                                <label class="filter-row items-start! py-2.5!" :class="genreState(hit.slug) === 'implied' && 'cursor-default'">
                                    <input
                                        type="checkbox"
                                        class="filter-check mt-0.5"
                                        :data-genre="hit.slug"
                                        :checked="genreState(hit.slug) !== 'off'"
                                        x-effect="$el.indeterminate = genreState(hit.slug) === 'mixed'"
                                        :data-implied="genreState(hit.slug) === 'implied' ? '' : null"
                                        :aria-disabled="genreState(hit.slug) === 'implied' ? 'true' : null"
                                        @click="genreState(hit.slug) === 'implied' && $event.preventDefault()"
                                        @change="toggleGenre(hit.slug)"
                                        data-nav
                                    >
                                    <span class="min-w-0 flex-1">
                                        <span x-text="hit.name" class="block"></span>
                                        <span class="block text-meta text-ink-subtle">
                                            <span x-text="hit.path"></span><span x-show="hit.also.length" x-text="hit.also.length ? ' · ' + @js(__('also in')) + ' ' + hit.also.join(', ') : ''"></span>
                                        </span>
                                    </span>
                                    <span class="shrink-0 text-meta tabular-nums text-ink-subtle" x-text="genreState(hit.slug) === 'implied' ? @js(__('Included')) : (hit.count || '')"></span>
                                </label>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>
        </div>
    </x-filter-section>

    <x-filter-section name="themes" :title="__('Themes')">
        <p class="mb-3 text-meta text-ink-subtle">{{ __('What a story explores, apart from its genre.') }}</p>
        <x-filter-search model="themes" :label="__('Search themes')" status="searchStatus('themes')" />
        <div class="mt-2 max-h-72 overflow-y-auto overscroll-contain">
            <x-filter-checks model="themes" source="themes" search="themes" />
        </div>
    </x-filter-section>

    <x-filter-section name="tropes" :title="__('Tropes')">
        <p class="mb-3 text-meta text-ink-subtle">{{ __('Recurring story devices, searchable on their own.') }}</p>
        <x-filter-search model="tropes" :label="__('Search tropes')" status="searchStatus('tropes')" />
        <div class="mt-2 max-h-72 overflow-y-auto overscroll-contain" @keydown="navigate($event)">
            <template x-for="group in tropeGroups" :key="group.name ?? ''">
                <div role="group" :aria-label="group.name ?? @js(__('Tropes'))" class="pt-2">
                    <p x-show="group.name" x-text="group.name" class="px-2 pb-1 text-legal tracking-wide text-ink-subtle uppercase"></p>
                    <ul>
                        <template x-for="option in group.items" :key="option.value">
                            <li>
                                <label class="filter-row">
                                    <input type="checkbox" class="filter-check" data-filter="tropes" :value="option.value" :checked="has('tropes', option.value)" @change="toggle('tropes', option.value)" data-nav>
                                    <span x-text="option.label"></span>
                                </label>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>
            <p x-show="tropeGroups.length === 0" style="display: none" class="px-2 py-3 text-meta text-ink-subtle">{{ __('No tropes match.') }}</p>
        </div>
    </x-filter-section>

    <x-filter-section name="demographics" :title="__('Demographic')">
        <p class="mb-3 text-meta text-ink-subtle">{{ __('Who a work is published for, as its editors see it. Not an age rating.') }}</p>
        <x-filter-checks model="demographics" source="demographics" />
    </x-filter-section>

    <x-filter-section name="ratings" :title="__('Audience rating')">
        <p class="mb-3 text-meta text-ink-subtle">{{ __('The age a work is rated for. Not a demographic.') }}</p>
        <x-filter-checks model="ratings" source="ratings" />
    </x-filter-section>

    <x-filter-section name="warnings" :title="__('Content warnings')">
        <p id="fp-warnings-help" class="text-meta text-ink">{{ __('Hide works with:') }}</p>
        <p class="mb-3 mt-1 text-meta text-ink-subtle">{{ __('Works whose warning status is unknown are not hidden: unknown does not mean absent.') }}</p>
        <x-filter-search model="warnings" :label="__('Search content warnings')" status="searchStatus('warnings')" />
        <div class="mt-2 max-h-72 overflow-y-auto overscroll-contain" role="group" aria-labelledby="fp-warnings-help">
            <x-filter-checks model="excludeWarnings" source="warnings" search="warnings" />
        </div>
    </x-filter-section>

    <x-filter-section name="more" :title="__('More filters')">
        <div class="flex flex-col gap-6">
            <fieldset>
                <legend class="mb-1 text-meta font-medium text-ink">{{ __('Status') }}</legend>
                <x-filter-checks model="statuses" source="statuses" :columns="2" />
            </fieldset>

            <fieldset>
                <legend class="mb-1 text-meta font-medium text-ink">{{ __('Language') }}</legend>
                <x-filter-checks model="languages" source="languages" :columns="2" />
            </fieldset>

            <x-range-filter x-model="draft.years" :label="__('Year')" :min="$years[0]" :max="$years[1]" />

            <div>
                <label for="fp-length" class="mb-2 block text-meta font-medium text-ink">{{ __('Length') }}</label>
                <select id="fp-length" x-model="draft.length" class="focus-ring h-11 w-full rounded-control border border-border-input bg-surface-input px-3 text-body text-ink">
                    <option value="">{{ __('Any length') }}</option>
                    <template x-for="option in data.lengths" :key="option.value"><option :value="option.value" x-text="option.label"></option></template>
                </select>
            </div>

            <div>
                <label for="fp-rating" class="mb-2 block text-meta font-medium text-ink">{{ __('Reader rating') }}</label>
                <select id="fp-rating" x-model="draft.minRating" class="focus-ring h-11 w-full rounded-control border border-border-input bg-surface-input px-3 text-body text-ink">
                    <option value="">{{ __('Any rating') }}</option>
                    <template x-for="option in data.minRatings" :key="option.value"><option :value="option.value" x-text="option.label"></option></template>
                </select>
            </div>
        </div>
    </x-filter-section>
</div>
