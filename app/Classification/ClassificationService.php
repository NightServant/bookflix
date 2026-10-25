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
     * A second live primary is refused by the database, which also settles two concurrent attempts.
     *
     * @throws PrimaryClassificationExists
     */
    public function assign(Media $media, DeweyClass $class, ClassificationType $type, ClassificationCandidate $candidate): MediaDeweyClass
    {
        $row = $this->refusingSecondPrimary($media->id, fn (): MediaDeweyClass => DB::transaction(function () use ($media, $class, $type, $candidate): MediaDeweyClass {
            $row = MediaDeweyClass::lockForUpdate()->firstOrNew(['media_id' => $media->id, 'dewey_class_id' => $class->id]);
            $manual = $candidate->method === ClassificationMethod::Manual;

            if ($row->exists && ! $manual && $this->isDecided($row)) {
                return $row;
            }

            $row->fill([
                'classification_type' => $type,
                'confidence' => $candidate->confidence,
                'source' => $candidate->source,
                'classification_method' => $candidate->method,
                'model_version_id' => $candidate->modelVersionId,
                'review_status' => $manual ? ReviewStatus::Accepted : ReviewStatus::Pending,
            ])->save();

            return $row;
        }));

        $this->counts->flush();

        return $row;
    }

    /**
     * Keep a pending (or rejected) row as it is, now confirmed by the reviewer. A rejected primary that comes back is refused when the media has a new live primary.
     *
     * @throws PrimaryClassificationExists
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
     * the loser's insert is refused and surfaces as PrimaryClassificationExists.
     *
     * @throws PrimaryClassificationExists
     */
    public function replace(MediaDeweyClass $row, DeweyClass $class, User $reviewer): MediaDeweyClass
    {
        $replacement = $this->refusingSecondPrimary($row->media_id, fn (): MediaDeweyClass => DB::transaction(function () use ($row, $class, $reviewer): MediaDeweyClass {
            $rows = MediaDeweyClass::where('media_id', $row->media_id)->lockForUpdate()->get();
            $old = $rows->firstOrFail('id', $row->id);
            $target = $rows->firstWhere('dewey_class_id', $class->id) ?? new MediaDeweyClass(['media_id' => $row->media_id]);

            if ($target->isNot($old) && $old->classification_type === ClassificationType::Secondary && $target->classification_type === ClassificationType::Primary && $target->review_status !== ReviewStatus::Rejected) {
                throw new PrimaryClassificationExists("Media {$row->media_id} already has that class as its primary.");
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
     * Delete a row. A hard delete leaves nothing to stamp, so the reviewer is not recorded.
     */
    public function remove(MediaDeweyClass $row, User $reviewer): void
    {
        MediaDeweyClass::whereKey($row->id)->delete();

        $this->counts->flush();
    }

    /**
     * What the rules and the provider's own code suggest for a media: the external code first (when it resolves to a seeded class),
     * then the mapper's guesses, trimmed to one primary (index 0) and the allowed secondaries. Nothing is stored.
     *
     * @return Collection<int, ClassificationCandidate>
     */
    public function classify(Media $media, ?string $externalCode = null): Collection
    {
        $candidates = $this->mapper->map($media);
        $external = $externalCode === null ? null : $this->resolver->resolve($externalCode);

        if ($external !== null) {
            array_unshift($candidates, new ClassificationCandidate($external->code, 0.95, ClassificationMethod::External, 'external_code'));
        }

        return collect($this->validator->filter($candidates));
    }

    /**
     * Store what `classify` suggests, leaving every decision already on the media alone: a class the media has a row for (rejected ones included)
     * is skipped, no primary is added when the media has a live primary or any manual row, and the secondaries stop at the limit.
     */
    public function apply(Media $media, ?string $externalCode = null): void
    {
        $candidates = $this->classify($media, $externalCode);
        $classes = DeweyClass::whereIn('code', $candidates->pluck('code'))->get()->keyBy('code');
        $rows = $media->deweyClasses()->get();
        $live = $rows->where('review_status', '!=', ReviewStatus::Rejected);

        $hasPrimary = $live->contains('classification_type', ClassificationType::Primary) || $rows->contains('classification_method', ClassificationMethod::Manual);
        $secondaryRoom = config('bookflix.classification.max_secondary') - $live->where('classification_type', ClassificationType::Secondary)->count();

        foreach ($candidates->values() as $index => $candidate) {
            $class = $classes->get($candidate->code);
            $primary = $index === 0;

            if ($class === null || $rows->contains('dewey_class_id', $class->id) || ($primary ? $hasPrimary : $secondaryRoom <= 0)) {
                continue;
            }

            try {
                $this->assign($media, $class, $primary ? ClassificationType::Primary : ClassificationType::Secondary, $candidate);
            } catch (PrimaryClassificationExists) {
                continue;
            }

            if (! $primary) {
                $secondaryRoom--;
            }
        }
    }

    /**
     * @throws PrimaryClassificationExists
     */
    private function review(MediaDeweyClass $row, ReviewStatus $status, User $reviewer): void
    {
        $this->refusingSecondPrimary($row->media_id, fn () => DB::transaction(function () use ($row, $status, $reviewer): void {
            MediaDeweyClass::where('media_id', $row->media_id)->lockForUpdate()->get()->firstOrFail('id', $row->id)
                ->update(['review_status' => $status, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
        }));

        $this->counts->flush();
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
