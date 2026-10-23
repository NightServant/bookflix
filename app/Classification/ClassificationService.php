<?php

namespace App\Classification;

use App\Enums\ClassificationMethod;
use App\Enums\ClassificationType;
use App\Enums\ReviewStatus;
use App\Models\DeweyClass;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ClassificationService
{
    /**
     * Give the media a class, or update its row when it already has that class. Manual picks are accepted at once; everything else waits for review.
     * A second live primary is refused by the database, which also settles two concurrent attempts.
     *
     * @throws PrimaryClassificationExists
     */
    public function assign(Media $media, DeweyClass $class, ClassificationType $type, ClassificationCandidate $candidate): MediaDeweyClass
    {
        try {
            return DB::transaction(fn () => MediaDeweyClass::updateOrCreate(
                ['media_id' => $media->id, 'dewey_class_id' => $class->id],
                [
                    'classification_type' => $type,
                    'confidence' => $candidate->confidence,
                    'source' => $candidate->source,
                    'classification_method' => $candidate->method,
                    'model_version_id' => $candidate->modelVersionId,
                    'review_status' => $candidate->method === ClassificationMethod::Manual ? ReviewStatus::Accepted : ReviewStatus::Pending,
                ],
            ));
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'media_dewey_primary_unique')) {
                throw $e;
            }

            throw new PrimaryClassificationExists("Media {$media->id} already has a primary class.", previous: $e);
        }
    }
}
