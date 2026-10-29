<?php

namespace App\Discovery;

use App\Access\MatureAccess;
use App\Discovery\Search\TaxonomyFacets;
use App\Models\Genre;
use App\Models\MediaType;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * What a reader may say they like, and what they said: the formats, the genre tree and the themes on offer, the rules a pick must pass, and
 * the slugs of what is saved. The offer comes from TaxonomyFacets (the lists the filter panel uses, kept ten minutes), so the mature family
 * is on it only for a reader past the gate (MatureAccess). Onboarding and Settings, Preferences both read it, and the rules are the same
 * for both: a pick is always a slug that is on offer to this reader, never an id.
 */
class PreferenceChoices
{
    public function __construct(private readonly TaxonomyFacets $facets) {}

    /**
     * @return array<string, string> media type slug to name
     */
    public function types(): array
    {
        return $this->facets->types();
    }

    /**
     * The genre families with their subgenres, as TaxonomyFacets::genres() gives them, for this reader.
     *
     * @return list<array{slug: string, name: string, is_mature: bool, count: int, groups: list<array{name: string|null, items: list<array{slug: string, name: string, is_mature: bool, count: int}>}>}>
     */
    public function genres(User $user): array
    {
        return $this->facets->genres(MatureAccess::allows($user));
    }

    /**
     * @return array<string, string> theme slug to name
     */
    public function themes(): array
    {
        return $this->facets->themes();
    }

    /**
     * Every genre slug on offer to this reader, families and subgenres alike.
     *
     * @return list<string>
     */
    public function genreSlugs(User $user): array
    {
        $slugs = [];

        foreach ($this->genres($user) as $family) {
            $slugs[] = $family['slug'];

            foreach ($family['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    $slugs[] = $item['slug'];
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * The validation rules for the page properties `mediaTypes`, `genres` and (with `$themes`) `themes`: lists of slugs that are on offer. A list can
     * be no longer than what is on offer, so a repeated slug cannot be used to make the page work on a huge payload.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(User $user, bool $themes = false): array
    {
        $types = array_map('strval', array_keys($this->types()));
        $genres = $this->genreSlugs($user);

        $rules = [
            'mediaTypes' => ['array', 'max:'.max(1, count($types))],
            'mediaTypes.*' => [Rule::in($types)],
            'genres' => ['array', 'max:'.max(1, count($genres))],
            'genres.*' => [Rule::in($genres)],
        ];

        if ($themes) {
            $available = array_map('strval', array_keys($this->themes()));
            $rules += ['themes' => ['array', 'max:'.max(1, count($available))], 'themes.*' => [Rule::in($available)]];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mediaTypes.*.in' => __('That format is not available.'),
            'genres.*.in' => __('That genre is not available.'),
            'themes.*.in' => __('That theme is not available.'),
        ];
    }

    /**
     * The slugs of what the reader saved, for the pages to start from. A mature genre they chose while they could see them is not in the list
     * while they cannot (it is kept, not shown).
     *
     * @return array{mediaTypes: list<string>, genres: list<string>, themes: list<string>}
     */
    public function chosen(User $user): array
    {
        $saved = $user->preferences()->get(['preferable_type', 'preferable_id'])->groupBy('preferable_type')->map(fn ($rows) => $rows->pluck('preferable_id')->all());

        return [
            'mediaTypes' => array_values(MediaType::query()->whereIn('id', $saved->get('media_type', []))->pluck('slug')->all()),
            'genres' => array_values(Genre::query()->visible(MatureAccess::allows($user))->whereIn('id', $saved->get('genre', []))->pluck('slug')->all()),
            'themes' => array_values(Theme::query()->whereIn('id', $saved->get('theme', []))->pluck('slug')->all()),
        ];
    }

    /**
     * The family slugs that hold one of these genre slugs, for the picker to start those rows open.
     *
     * @param  list<array{slug: string, groups: list<array{items: list<array{slug: string}>}>}>  $families
     * @param  list<string>  $chosen
     * @return list<string>
     */
    public function familiesHolding(array $families, array $chosen): array
    {
        $open = [];

        foreach ($families as $family) {
            foreach ($family['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    if (in_array($item['slug'], $chosen, true)) {
                        $open[] = $family['slug'];

                        continue 3;
                    }
                }
            }
        }

        return $open;
    }
}
