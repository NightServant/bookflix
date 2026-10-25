<?php

namespace App\Classification;

use App\Classification\Dewey\DeweyMapper;
use App\Classification\Dewey\DeweyResolver;
use App\Classification\Dewey\DeweyValidator;
use App\Enums\ClassificationMethod;
use App\Enums\ClassificationType;
use App\Enums\ReviewStatus;
use App\Models\DeweyClass;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClassificationService
{
    public function __construct(
        private DeweyResolver $resolver,
        private DeweyMapper $mapper,
        private DeweyValidator $validator,
        private DeweyCounts $counts,
    ) {}

    /**
     * Give the media a class, or update its row when it already has that class. Manual picks are accepted at once; everything else waits for review.
     * A row that is manual, accepted or rejected is an admin's decision: only another manual assignment may change it, so an automatic one leaves it as it is.
     * A second live primary is refused by the database, which also settles two concurrent attempts; a secondary beyond the limit is refused under the media's lock.
     * When a concurrent run wrote the same pair first, that row is returned as it is.
     * Passing the reviewer records who decided and when, in the same transaction.
     *
     * @throws PrimaryClassificationExists
     * @throws SecondaryClassificationLimit
     */
    public function assign(Media $media, DeweyClass $class, ClassificationType $type, ClassificationCandidate $candidate, ?User $reviewer = null): MediaDeweyClass
    {
        $row = $this->write($media, $class, $type, $candidate, $reviewer);

        $this->counts->flush();

        return $row;
    }

    /**
     * `assign` without the count flush, so `apply` can flush once for all of its rows.
     *
     * @throws PrimaryClassificationExists
     * @throws SecondaryClassificationLimit
     */
    private function write(Media $media, DeweyClass $class, ClassificationType $type, ClassificationCandidate $candidate, ?User $reviewer): MediaDeweyClass
    {
        try {
            $row = $this->refusingSecondPrimary($media->id, fn (): MediaDeweyClass => DB::transaction(function () use ($media, $class, $type, $candidate, $reviewer): MediaDeweyClass {
                $rows = $this->lockRows($media->id);
                $row = $rows->firstWhere('dewey_class_id', $class->id) ?? new MediaDeweyClass(['media_id' => $media->id, 'dewey_class_id' => $class->id]);
                $manual = $candidate->method === ClassificationMethod::Manual;

                if ($row->exists && ! $manual && $this->isDecided($row)) {
                    return $row;
                }

                if ($type === ClassificationType::Secondary) {
                    $this->ensureSecondaryRoom($rows->reject(fn (MediaDeweyClass $other): bool => $other->is($row)), $media->id);
                }

                $row->fill([
                    'classification_type' => $type,
                    'confidence' => $candidate->confidence,
                    'source' => $candidate->source,
                    'classification_method' => $candidate->method,
                    'model_version_id' => $candidate->modelVersionId,
                    'review_status' => $manual ? ReviewStatus::Accepted : ReviewStatus::Pending,
                ]);

                if ($reviewer !== null) {
                    $row->fill(['reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
                }

                $row->save();

                return $row;
            }));
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'media_dewey_classes_media_id_dewey_class_id_unique')) {
                throw $e;
            }

            $row = MediaDeweyClass::where(['media_id' => $media->id, 'dewey_class_id' => $class->id])->firstOrFail();
        }

        return $row;
    }

    /**
     * Keep a pending (or rejected) row as it is, now confirmed by the reviewer. A rejected primary that comes back is refused when the media has a new live primary,
     * and a rejected secondary when the media already has the most secondaries.
     *
     * @throws PrimaryClassificationExists
     * @throws SecondaryClassificationLimit
     */
    public function accept(MediaDeweyClass $row, User $reviewer): void
    {
        $this->review($row, ReviewStatus::Accepted, $reviewer);
    }

    /**
     * Turn a row down: it stops counting and never shows publicly, and automatic runs do not bring it back.
     */
    public function reject(MediaDeweyClass $row, User $reviewer): void
    {
        $this->review($row, ReviewStatus::Rejected, $reviewer);
    }

    /**
     * Swap a row for another class in one transaction. Every row of the media is locked first, the old row is rejected before the new one is written
     * (the partial unique index would refuse two live primaries), and any failure rolls the whole swap back. The new row takes the old row's type and is a
     * manual, fully confident, accepted decision; a row the media already had for that class is reused. The index is the backstop for two reviewers swapping at once:
     * the loser's insert is refused and surfaces as PrimaryClassificationExists. A swap that would leave the media with more secondaries than the limit is refused.
     *
     * @throws PrimaryClassificationExists
     * @throws SecondaryClassificationLimit
     */
    public function replace(MediaDeweyClass $row, DeweyClass $class, User $reviewer): MediaDeweyClass
    {
        $replacement = $this->refusingSecondPrimary($row->media_id, fn (): MediaDeweyClass => DB::transaction(function () use ($row, $class, $reviewer): MediaDeweyClass {
            $rows = $this->lockRows($row->media_id);
            $old = $rows->firstOrFail('id', $row->id);
            $target = $rows->firstWhere('dewey_class_id', $class->id) ?? new MediaDeweyClass(['media_id' => $row->media_id]);

            if ($target->isNot($old) && $old->classification_type === ClassificationType::Secondary && $target->classification_type === ClassificationType::Primary && $target->review_status !== ReviewStatus::Rejected) {
                throw new PrimaryClassificationExists("Media {$row->media_id} already has that class as its primary.");
            }

            if ($old->classification_type === ClassificationType::Secondary) {
                $this->ensureSecondaryRoom($rows->reject(fn (MediaDeweyClass $other): bool => $other->is($old) || $other->is($target)), $row->media_id);
            }

            if ($target->isNot($old)) {
                $old->update(['review_status' => ReviewStatus::Rejected, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
            }

            $target->fill([
                'dewey_class_id' => $class->id,
                'classification_type' => $old->classification_type,
                'confidence' => 1.0,
                'source' => 'admin',
                'classification_method' => ClassificationMethod::Manual,
                'model_version_id' => null,
                'review_status' => ReviewStatus::Accepted,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            return $target;
        }));

        $this->counts->flush();

        return $replacement;
    }

    /**
     * Take a class off the media. The row stays, rejected, so the decision is on record and an automatic run does not add the class again.
     */
    public function remove(MediaDeweyClass $row, User $reviewer): void
    {
        $this->reject($row, $reviewer);
    }

    /**
     * What the rules and the providers' own codes suggest for a media: every external code on its external ids (and the given one) that resolves to a seeded class first,
     * then the mapper's guesses, trimmed to one primary (index 0) and the allowed secondaries. Nothing is stored.
     *
     * @return Collection<int, ClassificationCandidate>
     */
    public function classify(Media $media, ?string $externalCode = null): Collection
    {
        $external = $media->externalIds()->whereNotNull('dewey_code')->orderBy('id')->pluck('dewey_code')
            ->prepend($externalCode)
            ->filter()
            ->map(fn (string $code): ?DeweyClass => $this->resolver->resolve($code))
            ->filter()
            ->unique('code')
            ->map(fn (DeweyClass $class): ClassificationCandidate => new ClassificationCandidate($class->code, 0.95, ClassificationMethod::External, 'external_code'))
            ->values()
            ->all();

        return collect($this->validator->filter([...$external, ...$this->mapper->map($media)]));
    }

    /**
     * Store what `classify` suggests, leaving every decision already on the media alone: a class the media has a row for (rejected ones included)
     * is skipped, no primary is added when the media has a live primary or any manual row, and the secondaries stop at the limit.
     * The cached counts are flushed once at the end, and only when a row was written.
     */
    public function apply(Media $media, ?string $externalCode = null): void
    {
        $candidates = $this->classify($media, $externalCode);
        $classes = DeweyClass::whereIn('code', $candidates->pluck('code'))->get()->keyBy('code');
        $rows = $media->deweyClasses()->get();
        $live = $rows->where('review_status', '!=', ReviewStatus::Rejected);

        $hasPrimary = $live->contains('classification_type', ClassificationType::Primary) || $rows->contains('classification_method', ClassificationMethod::Manual);
        $secondaryRoom = config('bookflix.classification.max_secondary') - $live->where('classification_type', ClassificationType::Secondary)->count();

        $written = false;

        try {
            foreach ($candidates->values() as $index => $candidate) {
                $class = $classes->get($candidate->code);
                $primary = $index === 0;

                if ($class === null || $rows->contains('dewey_class_id', $class->id) || ($primary ? $hasPrimary : $secondaryRoom <= 0)) {
                    continue;
                }

                try {
                    $this->write($media, $class, $primary ? ClassificationType::Primary : ClassificationType::Secondary, $candidate, null);
                    $written = true;
                } catch (PrimaryClassificationExists|SecondaryClassificationLimit) {
                    continue;
                }

                if (! $primary) {
                    $secondaryRoom--;
                }
            }
        } finally {
            if ($written) {
                $this->counts->flush();
            }
        }
    }

    /**
     * @throws PrimaryClassificationExists
     * @throws SecondaryClassificationLimit
     */
    private function review(MediaDeweyClass $row, ReviewStatus $status, User $reviewer): void
    {
        $this->refusingSecondPrimary($row->media_id, fn () => DB::transaction(function () use ($row, $status, $reviewer): void {
            $rows = $this->lockRows($row->media_id);
            $current = $rows->firstOrFail('id', $row->id);

            if ($status !== ReviewStatus::Rejected && $current->review_status === ReviewStatus::Rejected && $current->classification_type === ClassificationType::Secondary) {
                $this->ensureSecondaryRoom($rows->reject(fn (MediaDeweyClass $other): bool => $other->is($current)), $row->media_id);
            }

            $current->update(['review_status' => $status, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
        }));

        $this->counts->flush();
    }

    /**
     * Lock the media row, then all of its class rows, and return them. A row lock cannot stop another writer inserting a class, so the media
     * lock is what makes "is there room?" safe to ask; every writer takes it first, so none can deadlock another.
     *
     * @return Collection<int, MediaDeweyClass>
     */
    private function lockRows(int $mediaId): Collection
    {
        Media::withTrashed()->whereKey($mediaId)->lockForUpdate()->value('id');

        return MediaDeweyClass::where('media_id', $mediaId)->lockForUpdate()->get();
    }

    /**
     * @param  Collection<int, MediaDeweyClass>  $others  The media's rows other than the one about to become a live secondary.
     *
     * @throws SecondaryClassificationLimit
     */
    private function ensureSecondaryRoom(Collection $others, int $mediaId): void
    {
        $live = $others->where('classification_type', ClassificationType::Secondary)->where('review_status', '!=', ReviewStatus::Rejected)->count();

        if ($live >= config('bookflix.classification.max_secondary')) {
            throw new SecondaryClassificationLimit("Media {$mediaId} already has the most secondary classes.");
        }
    }

    /**
     * Run a write, turning the database's refusal of a second live primary into PrimaryClassificationExists.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     *
     * @throws PrimaryClassificationExists
     * @throws UniqueConstraintViolationException when the refusal is about something other than the primary index
     */
    private function refusingSecondPrimary(int $mediaId, Closure $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'media_dewey_primary_unique')) {
                throw $e;
            }

            throw new PrimaryClassificationExists("Media {$mediaId} already has a primary class.", previous: $e);
        }
    }

    private function isDecided(MediaDeweyClass $row): bool
    {
        return $row->classification_method === ClassificationMethod::Manual || $row->review_status !== ReviewStatus::Pending;
    }
}
