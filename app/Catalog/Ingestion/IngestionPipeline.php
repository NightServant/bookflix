<?php

namespace App\Catalog\Ingestion;

use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderRecord;
use App\Catalog\Providers\MetadataProvider;
use App\Enums\ImportOutcome;
use App\Models\Character;
use App\Models\Creator;
use App\Models\ExternalSource;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaExternalId;
use App\Models\MediaType;
use App\Models\Publisher;
use App\Models\Series;
use App\Models\Tag;
use App\Models\Theme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IngestionPipeline
{
    public function __construct(
        private RecordValidator $validator,
        private TaxonomyMapper $taxonomy,
        private Deduplicator $deduplicator,
        private Enricher $enricher,
    ) {}

    /**
     * Normalize, validate and store one provider record. Invalid records and unchanged payloads are skipped;
     * the store step is one transaction, so a failure leaves nothing behind.
     */
    public function import(ProviderRecord $record, MetadataProvider $provider): ImportOutcome
    {
        $incoming = $provider->normalize($record);

        if (! $this->validator->validate($record, $incoming)) {
            return ImportOutcome::Skipped;
        }

        $source = ExternalSource::where('key', $provider->key())->firstOrFail();
        $hash = hash('sha256', json_encode(Arr::sortRecursive($record->payload), JSON_THROW_ON_ERROR));
        $known = MediaExternalId::where('external_source_id', $source->id)->where('external_id', $record->externalId)->first();

        if ($known?->payload_hash === $hash) {
            return ImportOutcome::Skipped;
        }

        return DB::transaction(function () use ($record, $incoming, $source, $hash, $known): ImportOutcome {
            $candidates = collect();

            if ($known) {
                $media = Media::withTrashed()->findOrFail($known->media_id);

                if ($media->trashed()) {
                    return ImportOutcome::Skipped;
                }

                $outcome = ImportOutcome::Updated;
            } elseif ($media = $this->deduplicator->match($incoming)) {
                $outcome = ImportOutcome::Merged;
            } else {
                $candidates = $this->deduplicator->candidates($incoming);
                $outcome = ImportOutcome::Created;
                $media = Media::create([
                    'media_type_id' => MediaType::where('slug', $incoming->mediaTypeSlug)->value('id'),
                    'title' => trim($incoming->title),
                    'publication_year' => $incoming->publicationYear,
                ]);
            }

            MediaExternalId::updateOrCreate(
                ['external_source_id' => $source->id, 'external_id' => $record->externalId],
                ['media_id' => $media->id, 'source_url' => $record->sourceUrl, 'payload_hash' => $hash, 'last_synced_at' => now()],
            );

            $this->recordIsbns($media, $incoming, $hash);
            $this->attachRelations($media, $incoming, $source->key);
            $this->enricher->apply($media, $incoming, $source->key);
            $this->refreshSearchText($media);
            $this->deduplicator->recordCandidates($media, $candidates);

            return $outcome;
        });
    }

    /**
     * ISBN-13s live under a synthetic `isbn` source so the Deduplicator can match on them. A clash with another media's ISBN is ignored.
     */
    private function recordIsbns(Media $media, NormalizedMedia $incoming, string $hash): void
    {
        if ($incoming->isbns === []) {
            return;
        }

        $isbnSource = ExternalSource::firstOrCreate(['key' => 'isbn'], ['name' => 'ISBN', 'base_url' => 'urn:isbn', 'attribution' => '', 'enabled' => false]);

        MediaExternalId::insertOrIgnore(array_map(fn (string $isbn): array => [
            'media_id' => $media->id,
            'external_source_id' => $isbnSource->id,
            'external_id' => $isbn,
            'payload_hash' => $hash,
            'imported_at' => now(),
            'last_synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $incoming->isbns));
    }

    /**
     * Add the record's creators, publishers, series, characters and mapped taxonomy to the media. Nothing is detached:
     * terms from another provider stay.
     */
    private function attachRelations(Media $media, NormalizedMedia $incoming, string $provider): void
    {
        foreach ($incoming->creators as $position => $creator) {
            DB::table('media_creators')->insertOrIgnore([
                'media_id' => $media->id,
                'creator_id' => $this->ids(Creator::class, [$this->slug($creator->name) => $creator->name])[0],
                'role' => $creator->role->value,
                'position' => $position,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($incoming->series !== null) {
            $series = Series::firstOrCreate(['slug' => $this->slug($incoming->series['title'])], ['title' => $incoming->series['title']]);
            $position = $incoming->series['position'];

            $media->series()->syncWithoutDetaching([$series->id => ['position' => is_numeric($position) ? $position : null]]);
        }

        $media->publishers()->syncWithoutDetaching($this->ids(Publisher::class, $this->named($incoming->publishers)));
        $media->characters()->syncWithoutDetaching($this->ids(Character::class, $this->named($incoming->characters)));

        $mapped = $this->taxonomy->map($provider, $incoming);

        foreach (['genres' => Genre::class, 'themes' => Theme::class, 'tags' => Tag::class] as $relation => $model) {
            $media->{$relation}()->syncWithoutDetaching($this->ids($model, array_combine($mapped[$relation], array_map(Str::headline(...), $mapped[$relation]))));
        }
    }

    /**
     * Ids of the rows with these slugs, created when missing.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $names  Slug to display name.
     * @return list<int>
     */
    private function ids(string $model, array $names): array
    {
        return array_map(
            fn (string $name, string $slug): int => $model::firstOrCreate(['slug' => $slug], ['name' => $name])->getKey(),
            $names,
            array_keys($names),
        );
    }

    /**
     * @param  list<string>  $names
     * @return array<string, string>
     */
    private function named(array $names): array
    {
        return array_combine(array_map($this->slug(...), $names), $names);
    }

    private function slug(string $name): string
    {
        return Str::slug($name) ?: md5(Str::lower($name));
    }

    /**
     * Phase 4 search reads this: every name that can find the media, lowercased.
     */
    private function refreshSearchText(Media $media): void
    {
        $media->load(['creators', 'series', 'genres', 'themes', 'tags', 'characters', 'publishers']);

        $terms = array_merge(
            [$media->title],
            $media->alternative_titles,
            $media->creators->pluck('name')->all(),
            $media->series->pluck('title')->all(),
            $media->genres->pluck('name')->all(),
            $media->themes->pluck('name')->all(),
            $media->tags->pluck('name')->all(),
            $media->characters->pluck('name')->all(),
            $media->publishers->pluck('name')->all(),
        );

        $media->update(['search_text' => Str::lower(implode(' ', array_unique(array_filter($terms))))]);
    }
}
