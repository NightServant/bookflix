<?php

namespace App\Catalog\Providers;

use App\Catalog\Classification\ClassificationSignals;
use App\Catalog\Classification\MediaTypeClassifier;

trait ClassifiesMediaType
{
    /**
     * The media type slug for a record's signals (see MediaTypeClassifier); empty when the record is not a kind of work the catalogue holds.
     */
    protected function classifyMediaType(ClassificationSignals $signals): string
    {
        return app(MediaTypeClassifier::class)->classify($signals)->slug;
    }
}
