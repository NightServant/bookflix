<?php

namespace App\Catalog\Classification;

use App\Catalog\Ingestion\MappedTaxonomy;
use App\Catalog\Ingestion\ProhibitedContent;
use App\Catalog\Ingestion\TaxonomyMapper;
use App\Catalog\Taxonomy\WarningRecorder;
use App\Enums\AgeRating;
use App\Enums\WarningStatus;
use App\Models\Demographic;
use App\Models\ExternalSource;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaType;
use App\Models\Tag;
use App\Models\Theme;
use App\Models\Trope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Re-derives a stored media's type and taxonomy from what is stored (title, publishers, credits, genres, tags, page and chapter counts, origin, description)
 * with the classifier and the taxonomy mapper the importers use. Only what an admin has not locked (`locked_fields`) is touched, and it only adds:
 * a tag a mapping now turns into a genre, trope, demographic or warning is folded into it, nothing else is removed.
 *
 * Media from providers that state the format outright (AniList, MangaDex, MangaUpdates) keep their type: the stored data cannot beat what the provider said.
 */
class Reclassifier
{
    /** Providers whose own format field decided the type when the media was imported. */
    public const FORMAT_PROVIDERS = ['anilist', 'mangadex', 'mangaupdates'];

    /** Sources that only add signals to a media; they never say what it is. */
    private const SIGNAL_SOURCES = ['isbn', 'wikidata', 'wikipedia'];

    /** @var array<int, string>|null */
    private ?array $sourceKeys = null;

    /** @var array<string, int>|null */
    private ?array $typeIds = null;

    public function __construct(
        private MediaTypeClassifier $classifier,
        private TaxonomyMapper $mapper,
        private WarningRecorder $warnings,
    ) {}

    /**
     * @param  string|null  $provider  Treat the media as this provider's; by default its own source.
     * @param  bool  $apply  False for a dry run: nothing is written.
     * @param  float  $minConfidence  A new type below this confidence is not applied.
     */
    public function reclassify(Media $media, ?string $provider = null, bool $apply = true, float $minConfidence = 0.6): ReclassifyOutcome
    {
        $media->load(['mediaType', 'externalIds', 'tags', 'genres', 'themes', 'tropes', 'demographics', 'publishers', 'creators']);
        $provider ??= $this->providerOf($media);
        $old = $media->mediaType->slug;

        if ($media->tags->contains(fn (Tag $tag): bool => ProhibitedContent::matches($tag->name))) {
            if ($apply) {
                $media->delete();
            }

            return new ReclassifyOutcome($media->id, $old, $old, prohibited: true);
        }

        [$type, $confidence] = $this->type($media, $provider, $minConfidence);
        $mapped = $this->mapped($media, $provider);
        $folded = $this->foldedTags($media, $provider);
        $locked = $media->locked_fields;
        $attached = ['genres' => 0, 'themes' => 0, 'tropes' => 0, 'demographics' => 0, 'warnings' => 0];

        $relations = [
            'genres' => [Genre::class, $mapped->genres],
            'themes' => [Theme::class, $mapped->themes],
            'tropes' => [Trope::class, $mapped->tropes],
            'demographics' => [Demographic::class, $mapped->demographics],
        ];

        foreach ($relations as $relation => [$model, $slugs]) {
            if (in_array($relation, $locked, true)) {
                continue;
            }

            $new = array_values(array_diff($slugs, $media->{$relation}->pluck('slug')->all()));
            $attached[$relation] = count($new);

            if ($apply && $new !== []) {
                $media->{$relation}()->syncWithoutDetaching($this->ids($model, $new));
            }
        }

        $attached['warnings'] = $this->warningChanges($media, $mapped, $provider, $apply);
        $ratingSet = $mapped->rating !== null && $media->age_rating === AgeRating::Unknown && ! in_array('age_rating', $locked, true);
        $foldTags = ! in_array('tags', $locked, true) ? $folded : [];

        if ($apply) {
            DB::transaction(function () use ($media, $type, $old, $ratingSet, $mapped, $foldTags): void {
                if ($type !== $old) {
                    $media->update(['media_type_id' => $this->typeId($type)]);
                }

                if ($ratingSet) {
                    $media->update(['age_rating' => $mapped->rating]);
                }

                if ($foldTags !== []) {
                    $media->tags()->detach($foldTags);
                }
            });
        }

        $outcome = new ReclassifyOutcome($media->id, $old, $type, $confidence, $attached, count($foldTags), $ratingSet);

        if ($apply && $outcome->changedAnything()) {
            $media->unsetRelations()->refreshSearchText();
        }

        return $outcome;
    }

    /**
     * The new type slug and its confidence; the old slug when nothing may or should change.
     *
     * @return array{string, float}
     */
    private function type(Media $media, string $provider, float $minConfidence): array
    {
        $current = $media->mediaType->slug;

        if ($provider === '' || in_array($provider, self::FORMAT_PROVIDERS, true) || in_array('media_type_id', $media->locked_fields, true)) {
            return [$current, 1.0];
        }

        $verdict = $this->classifier->classify($this->signals($media, $provider));

        if (! $verdict->isSupported() || $verdict->confidence < $minConfidence || $this->typeId($verdict->slug) === null) {
            return [$current, $verdict->confidence];
        }

        return [$verdict->slug, $verdict->confidence];
    }

    /**
     * The signals a stored media offers: everything its provider record gave, minus the provider's own format field (not kept).
     */
    public function signals(Media $media, string $provider): ClassificationSignals
    {
        return new ClassificationSignals(
            provider: $provider,
            format: $media->format,
            title: $media->title,
            alternativeTitles: array_values(array_filter($media->alternative_titles, 'is_string')),
            description: $media->description,
            subjects: [...$this->names($media->tags), ...$this->names($media->genres), ...$this->names($media->themes)],
            publishers: $this->names($media->publishers),
            creatorRoles: array_values(DB::table('media_creators')->where('media_id', $media->id)->distinct()->pluck('role')->map(fn (mixed $role): string => (string) $role)->all()),
            pageCount: $media->page_count,
            chapterCount: $media->chapter_count,
            country: $media->country_of_origin,
            language: $media->language,
        );
    }

    /**
     * What the media's tags (the provider terms nothing mapped when it was imported) map to now.
     */
    private function mapped(Media $media, string $provider): MappedTaxonomy
    {
        if ($provider === '') {
            return new MappedTaxonomy;
        }

        return $this->mapper->mapTerms($provider, $this->names($media->tags));
    }

    /**
     * Ids of the tags that the mapping turned into something better, so they are folded into it (kept when the mapping keeps the term as a tag too).
     *
     * @return list<int>
     */
    private function foldedTags(Media $media, string $provider): array
    {
        if ($provider === '') {
            return [];
        }

        return array_values($media->tags
            ->filter(fn (Tag $tag): bool => $this->mapper->knows($provider, $tag->name) && $this->mapper->mapTerms($provider, [$tag->name])->tags === [])
            ->map(fn (Tag $tag): int => $tag->id)
            ->all());
    }

    /**
     * Record the mapped warnings as present; returns how many rows changed (or would).
     */
    private function warningChanges(Media $media, MappedTaxonomy $mapped, string $provider, bool $apply): int
    {
        if ($mapped->warnings === [] || in_array('content_warnings', $media->locked_fields, true)) {
            return 0;
        }

        if ($apply) {
            return $this->warnings->record($media, $mapped->warnings, WarningStatus::Present, $provider !== '' ? $provider : 'reclassify');
        }

        $existing = DB::table('media_content_warnings')->join('content_warnings', 'content_warnings.id', '=', 'media_content_warnings.content_warning_id')
            ->where('media_id', $media->id)->pluck('media_content_warnings.status', 'content_warnings.slug');

        return count(array_filter($mapped->warnings, fn (string $slug): bool => ! in_array($existing->get($slug), ['present', 'disputed'], true)));
    }

    /**
     * The key of the provider that owns the media: its primary source, else its first real source; empty for a media no provider describes.
     */
    private function providerOf(Media $media): string
    {
        $keys = $this->sourceKeys ??= ExternalSource::query()->pluck('key', 'id')->all();

        if ($media->primary_external_source_id !== null && isset($keys[$media->primary_external_source_id])) {
            return $keys[$media->primary_external_source_id];
        }

        foreach ($media->externalIds as $externalId) {
            $key = $keys[$externalId->external_source_id] ?? '';

            if ($key !== '' && ! in_array($key, self::SIGNAL_SOURCES, true)) {
                return $key;
            }
        }

        return '';
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $models  Rows with a `name`.
     * @return list<string>
     */
    private function names(Collection $models): array
    {
        return array_values($models->map(fn (Model $model): string => (string) $model->getAttribute('name'))->all());
    }

    private function typeId(string $slug): ?int
    {
        $this->typeIds ??= MediaType::query()->pluck('id', 'slug')->all();

        return $this->typeIds[$slug] ?? null;
    }

    /**
     * Ids of the rows with these slugs, created when missing (named after their slug).
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function ids(string $model, array $slugs): array
    {
        return array_map(fn (string $slug): int => $model::firstOrCreate(['slug' => $slug], ['name' => Str::headline($slug)])->getKey(), $slugs);
    }
}
