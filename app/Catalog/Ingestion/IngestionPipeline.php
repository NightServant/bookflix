<?php

namespace App\Catalog\Ingestion;

use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderRecord;
use App\Catalog\MediaRatings;
use App\Catalog\Providers\MetadataProvider;
use App\Catalog\Taxonomy\WarningRecorder;
use App\Enums\ImportOutcome;
use App\Enums\WarningStatus;
use App\Jobs\ClassifyMedia;
use App\Models\Character;
use App\Models\Creator;
use App\Models\Demographic;
use App\Models\ExternalSource;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaExternalId;
use App\Models\MediaType;
use App\Models\Publisher;
use App\Models\Series;
use App\Models\Tag;
use App\Models\Theme;
use App\Models\Trope;
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
        private WarningRecorder $warnings,
    ) {}

    /**
     * Normalize, validate and store one provider record. Invalid records and unchanged payloads are skipped;
     * the store step is one transaction, so a failure leaves nothing behind. The media it touches is locked and
     * re-checked inside it: a match that was merged away meanwhile hands over to the winner.
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
            $existing = $known ? Media::withTrashed()->find($known->media_id) : $this->deduplicator->match($incoming);
            $media = $existing ? $this->deduplicator->lockLive($existing) : null;

            if ($known && ! $media) {
                return ImportOutcome::Skipped;
            }

            if ($media) {
                $outcome = $known ? ImportOutcome::Updated : ImportOutcome::Merged;
            } else {
                $candidates = $this->deduplicator->candidates($incoming);
                $outcome = ImportOutcome::Created;
                $media = Media::create([
                    'media_type_id' => MediaType::where('slug', $incoming->mediaTypeSlug)->value('id'),
                    'title' => trim($incoming->title),
                    'publication_year' => $incoming->publicationYear,
                    'primary_external_source_id' => $source->id,
                ]);
            }

            MediaExternalId::updateOrCreate(
                ['external_source_id' => $source->id, 'external_id' => $record->externalId],
                ['media_id' => $media->id, 'dewey_code' => $incoming->externalDeweyCode, 'source_url' => $incoming->sourceUrl, 'payload_hash' => $hash, 'last_synced_at' => now()],
            );

            $mapped = $this->taxonomy->map($source->key, $incoming);

            $this->recordIsbns($media, $incoming, $hash);
            $this->attachRelations($media, $incoming, $mapped, $source->key);
            $this->enricher->apply($media, $incoming, $source, $mapped->rating);
            $this->recordMetrics($media, $incoming, $source);
            $media->refreshSearchText();
            $this->deduplicator->recordCandidates($media, $candidates);

            ClassifyMedia::dispatch($media->id)->afterCommit();

            return $outcome;
        });
    }

    /**
     * Upsert the record's popularity signals for its source, then refresh the media's rating columns from every source's metrics
     * (see MediaRatings::blend, which leaves a locked column alone).
     */
    private function recordMetrics(Media $media, NormalizedMedia $incoming, ExternalSource $source): void
    {
        $metrics = array_filter($incoming->metrics, fn (int|float $value): bool => is_finite((float) $value));

        if ($metrics === []) {
            return;
        }

        DB::table('media_metrics')->upsert(
            array_map(fn (string $metric, int|float $value): array => [
                'media_id' => $media->id,
                'external_source_id' => $source->id,
                'metric' => $metric,
                'value' => $value,
                'observed_at' => now(),
            ], array_keys($metrics), $metrics),
            ['media_id', 'external_source_id', 'metric'],
            ['value', 'observed_at'],
        );

        MediaRatings::blend($media);
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
     * terms from another provider stay. A relation an admin locked (`locked_fields`) is left alone.
     */
    private function attachRelations(Media $media, NormalizedMedia $incoming, MappedTaxonomy $mapped, string $provider): void
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

        $named = fn (array $slugs): array => array_combine($slugs, array_map(Str::headline(...), $slugs));

        foreach ([
            'genres' => [Genre::class, $named($mapped->genres)],
            'themes' => [Theme::class, $named($mapped->themes)],
            'tropes' => [Trope::class, $named($mapped->tropes)],
            'demographics' => [Demographic::class, $named($mapped->demographics)],
            'tags' => [Tag::class, $mapped->tags],
        ] as $relation => [$model, $names]) {
            if (! in_array($relation, $media->locked_fields, true)) {
                $media->{$relation}()->syncWithoutDetaching($this->ids($model, $names));
            }
        }

        $this->warnings->record($media, $mapped->warnings, WarningStatus::Present, $provider);
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
}
