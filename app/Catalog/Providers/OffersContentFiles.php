<?php

namespace App\Catalog\Providers;

use App\Catalog\Data\ContentFileOffer;
use App\Catalog\Data\ProviderRecord;

/**
 * A provider whose records come with a readable file (the public-domain catalogues). Pure mapping, no I/O.
 */
interface OffersContentFiles
{
    /**
     * The file offered for the record, or null when the record has no file URL.
     */
    public function contentFile(ProviderRecord $record): ?ContentFileOffer;
}
