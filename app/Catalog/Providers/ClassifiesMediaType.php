<?php

namespace App\Catalog\Providers;

use App\Catalog\Classification\ClassificationSignals;
use App\Catalog\Classification\MediaTypeClassifier;
use App\Catalog\Classification\MediaTypeVerdict;

trait ClassifiesMediaType
{
    /**
     * The verdict on a record's signals (see MediaTypeClassifier): its main media type, empty when the record is not a kind of work the catalogue
     * holds, and the other types it also is.
     */
    protected function classifyMediaType(ClassificationSignals $signals): MediaTypeVerdict
    {
        return app(MediaTypeClassifier::class)->classify($signals);
    }
}
