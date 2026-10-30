<?php

namespace App\Livewire;

use App\Access\MatureAccess;
use App\Catalog\Taxonomy\LegacySlugs;
use App\Discovery\Search\SearchFilters;
use App\Discovery\Search\TaxonomyFacets;
use App\Models\Media;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A page that narrows the catalogue with the taxonomy (Search and Discover) and the filter state it needs: one `filters` array kept in the URL (each change
 * is a history entry, so the back button restores the last selection and a copied link restores all of it), the chips that show it, and the
 * labels behind them. The controls themselves are the `<x-filter-panel>` (resources/js/filter-panel.js), which edits a draft in the browser and
 * commits it to `filters`; the server only ever sees the committed array, cleaned here.
 *
 * Several values of one filter mean any of them, different filters all have to hold (see SearchFilters). Nothing here is an access rule: the page
 * asks HybridSearch for results as the signed-in reader, and the access gate (App\Access\MatureAccess) is applied there, whatever `filters` holds: a mature
 * genre in a crafted link finds nothing for a reader without access, and the panel never offers one.
 *
 * A page extends this class and may define `filtersChanged()` to react after a change (Search logs the query).
 *
 * @property-read array<string, mixed> $facets
 */
abstract class FilteredPage extends Component
{
    use WithPagination;

    /**
     * Every filter the panel offers, empty. The panel binds to these keys, so they always exist.
     *
     * @var array<string, mixed>
     */
    protected const NO_FILTERS = [
        'mediaTypes' => [],
        'genres' => [],
        'themes' => [],
        'tropes' => [],
        'demographics' => [],
        'ratings' => [],
        'excludeWarnings' => [],
        'statuses' => [],
        'languages' => [],
        'years' => [],
        'length' => '',
        'minRating' => '',
    ];

    /** The filters that hold a list of slugs, as against the year range and the two single choices. */
    protected const LIST_FILTERS = ['mediaTypes', 'genres', 'themes', 'tropes', 'demographics', 'ratings', 'excludeWarnings', 'statuses', 'languages'];

    /**
     * What the reader narrowed to, in the shape of NO_FILTERS.
     *
     * @var array<string, mixed>
     */
    #[Url(history: true)]
    public array $filters = [];

    /**
     * Clean what arrived in the URL (the keys the page offers, valid strings, lists that are lists, a retired genre or theme slug upgraded to the node
     * that replaced it). Call it from mount().
     */
    protected function initFilters(): void
    {
        $this->filters = [...self::NO_FILTERS, ...Arr::only($this->filters, array_keys(self::NO_FILTERS))];
        $this->settleFilters();
    }

    /**
     * Back to the shape the page and the panel read, after anything wrote to `filters`.
     */
    protected function settleFilters(): void
    {
        $filters = $this->cleanFilters([...self::NO_FILTERS, ...Arr::only($this->filters, array_keys(self::NO_FILTERS))]);

        foreach (self::LIST_FILTERS as $key) {
            $filters[$key] = array_slice($this->strings($filters[$key]), 0, $key === 'genres' ? SearchFilters::MAX_GENRES : SearchFilters::MAX_VALUES);
        }

        $filters['years'] = array_slice(array_values(Arr::wrap($filters['years'])), 0, 2);

        // The two single choices are left out while empty, so a link carries only what was chosen.
        foreach (['length', 'minRating'] as $key) {
            $filters[$key] = is_scalar($filters[$key]) ? (string) $filters[$key] : '';
            $filters[$key] === '' && $filters = Arr::except($filters, $key);
        }

        // The reader rating is in stars (0 to 5); a link from the ten point scale it once had ("8") becomes the stars it meant ("4"), and a value that means nothing is dropped.
        if (isset($filters['minRating'])) {
            $stars = SearchFilters::minStars($filters['minRating']);
            $filters = $stars === null ? Arr::except($filters, 'minRating') : [...$filters, 'minRating' => SearchFilters::starsKey($stars)];
        }

        // A bookmarked genre or theme slug from before the taxonomy becomes the slug that replaced it, so its chip can be shown and removed.
        $filters = [...$filters, ...app(LegacySlugs::class)->upgrade(['genres' => $filters['genres'], 'themes' => $filters['themes']])];

        $this->filters = $filters;
    }

    public function updatedFilters(): void
    {
        $this->settleFilters();
        $this->resetPage();
        $this->filtersChanged();
    }

    /**
     * A page that has to react to a change in the filters (log the search) does it here.
     */
    protected function filtersChanged(): void {}

    /**
     * Chip groups a page adds to the ones its filters make (Discover's family of formats), ahead of them.
     *
     * @return list<array{group: string, label: string|null, chips: list<array{value: string|null, text: string, remove: string}>}>
     */
    protected function extraChipGroups(): array
    {
        return [];
    }

    /**
     * Drop one chip: a single value of a list filter, or the whole of a single-value one.
     */
    public function removeFilter(string $group, ?string $value = null): void
    {
        if (! array_key_exists($group, self::NO_FILTERS)) {
            return;
        }

        $this->filters[$group] = in_array($group, self::LIST_FILTERS, true)
            ? array_values(array_filter(Arr::wrap($this->filters[$group] ?? []), fn (mixed $item): bool => (string) $item !== $value))
            : self::NO_FILTERS[$group];

        $this->updatedFilters();
    }

    public function clearFilters(): void
    {
        $this->filters = self::NO_FILTERS;
        $this->updatedFilters();
    }

    /**
     * Whether this reader may see mature and adult titles (see MatureAccess): what the panel offers and the labels reach.
     */
    protected function matureAccess(): bool
    {
        return MatureAccess::allows(auth()->user());
    }

    /**
     * The filters as the search sees them: only the keys the page offers, with the year slider's two ends mapped to a range
     * (a slider left at its limits filters nothing). `$override` replaces filters the page derives itself (Discover's family of formats).
     *
     * @param  array<string, mixed>  $override
     */
    protected function searchFilters(array $override = []): SearchFilters
    {
        $input = [...Arr::only($this->filters, array_keys(self::NO_FILTERS)), ...$override];
        [$from, $to] = array_pad(array_values(Arr::wrap($input['years'] ?? [])), 2, null);
        [$lowest, $highest] = $this->facets['years'];

        return SearchFilters::fromArray([
            ...$input,
            'yearFrom' => (int) $from > $lowest ? $from : null,
            'yearTo' => $to !== null && (int) $to < $highest ? $to : null,
        ]);
    }

    /**
     * Invalid UTF-8 replaced and dropped, once, at the boundary: a filter value that is not a valid string is emptied (and then dropped), so the page
     * can always be serialized, searched and logged.
     *
     * @param  array<array-key, mixed>  $filters
     * @return array<array-key, mixed>
     */
    private function cleanFilters(array $filters): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            is_array($value) => $this->cleanFilters($value),
            is_string($value) => mb_check_encoding($value, 'UTF-8') ? $value : '',
            default => $value,
        }, $filters);
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return array_values(array_unique(array_filter(Arr::wrap($value), fn (mixed $item): bool => is_string($item) && $item !== '')));
    }

    /**
     * The year slider's two ends, kept ten minutes as plain numbers.
     *
     * @return array{0: int, 1: int}
     */
    private function yearBounds(): array
    {
        return Cache::remember('search:year-bounds:v1', 600, function (): array {
            $row = Media::query()->unmerged()->toBase()->selectRaw('min(media.publication_year) as lowest, max(media.publication_year) as highest')->first();

            return [(int) data_get($row, 'lowest', 1900), (int) data_get($row, 'highest', now()->year)];
        });
    }

    /**
     * The label of every value of every filter, plus the year range: what the chips are named from. Kept as plain arrays by TaxonomyFacets.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function facets(): array
    {
        return [...app(TaxonomyFacets::class)->labels($this->matureAccess()), 'years' => $this->yearBounds()];
    }

    /**
     * What the filter panel is built from in the browser: the page's one JSON data island (see <x-filter-islands>).
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function panel(): array
    {
        return app(TaxonomyFacets::class)->panel($this->matureAccess());
    }

    /**
     * What the panel's Alpine data starts from: the year range, how long a pause before a change is applied on a wide screen, and the words it says itself.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function panelConfig(): array
    {
        return [
            'years' => $this->facets['years'],
            'pause' => 400,
            'labels' => [
                'matches' => __(':count matches'),
                'noMatches' => __('No matches'),
                'includedWith' => __('included with'),
                'family' => __('Genre family'),
            ],
        ];
    }

    /**
     * The active filters as chips, in groups in the panel's order: the group's label, and for each value what the chip says, the value to remove
     * (null for a single-value filter) and what its remove button is called. A content warning chip says what it does: "Hide: Gore".
     *
     * @return list<array{group: string, label: string|null, chips: list<array{value: string|null, text: string, remove: string}>}>
     */
    #[Computed]
    public function chips(): array
    {
        $filters = $this->searchFilters();
        $labels = $this->facets;
        $groups = [];

        foreach ($this->extraChipGroups() as $group) {
            $groups[$group['group']] = $group;
        }

        $add = function (string $group, ?string $label, ?string $value, string $text) use (&$groups): void {
            $groups[$group] ??= ['group' => $group, 'label' => $label, 'chips' => []];
            $groups[$group]['chips'][] = ['value' => $value, 'text' => $text, 'remove' => __('Remove filter :label', ['label' => $text])];
        };

        foreach ([
            'mediaTypes' => [(string) __('Type'), $filters->mediaTypes, $labels['mediaTypes']],
            'genres' => [(string) __('Genre'), $filters->genres, $labels['genres']],
            'themes' => [(string) __('Theme'), $filters->themes, $labels['themes']],
            'tropes' => [(string) __('Trope'), $filters->tropes, $labels['tropes']],
            'demographics' => [(string) __('Demographic'), $filters->demographics, $labels['demographics']],
            'ratings' => [(string) __('Audience'), $filters->ratings, $labels['ratings']],
            'excludeWarnings' => [null, $filters->excludeWarnings, $labels['excludeWarnings']],
            'statuses' => [(string) __('Status'), $filters->statuses, $labels['statuses']],
            'languages' => [(string) __('Language'), $filters->languages, $labels['languages']],
        ] as $group => [$label, $values, $names]) {
            foreach ($values as $value) {
                $name = $names[$value] ?? $value;
                $add($group, $label, $value, $group === 'excludeWarnings' ? __('Hide: :warning', ['warning' => $name]) : $name);
            }
        }

        if ($filters->yearFrom !== null || $filters->yearTo !== null) {
            $add('years', __('Year'), null, ($filters->yearFrom ?? $labels['years'][0]).' – '.($filters->yearTo ?? $labels['years'][1]));
        }

        if ($filters->length !== null) {
            $add('length', __('Length'), null, $labels['lengths'][$filters->length]);
        }

        if ($filters->minRating !== null) {
            $stars = SearchFilters::starsKey($filters->minRating);
            $add('minRating', __('Reader rating'), null, $labels['minRatings'][$stars] ?? __(':stars+ stars', ['stars' => $stars]));
        }

        $order = ['family', ...array_keys(self::NO_FILTERS)];

        return array_values(Arr::sort($groups, fn (array $group): int => (int) array_search($group['group'], $order, true)));
    }
}
