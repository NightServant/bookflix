<?php

namespace App\Actions\Reader;

use App\Catalog\Data\ContentFileOffer;
use App\Catalog\Data\ProviderRecord;
use App\Enums\AccessType;
use App\Models\ContentSource;
use App\Models\ExternalSource;
use App\Models\MediaExternalId;

/**
 * Keeps one content source per file a provider offers. The source is always created unverified: only an admin vouches for a license.
 * A re-import may move the source to another media, and (until it is verified) to another URL, but never touches the license or the verification, so an admin's decision
 * survives every sync.
 */
class RecordContentFile
{
    public function handle(ExternalSource $source, ProviderRecord $record, ContentFileOffer $offer): ?ContentSource
    {
        $mediaId = MediaExternalId::where('external_source_id', $source->id)
            ->where('external_id', $record->externalId)
            ->value('media_id');

        // A record the pipeline skipped or refused has no media, so there is nothing to attach a file to.
        if ($mediaId === null) {
            return null;
        }

        $content = ContentSource::where('external_key', $offer->externalKey)->first();

        if ($content === null) {
            return ContentSource::create([
                'external_key' => $offer->externalKey,
                'media_id' => $mediaId,
                'access_type' => AccessType::PublicDomain,
                'format' => $offer->format,
                'url' => $offer->url,
                'license' => $offer->license,
                'rights_holder' => $offer->rightsHolder,
            ]);
        }

        // Once an admin has verified the source, the file they vouched for is the one at its address: a re-import may only follow the media.
        $content->update($content->isVerified() ? ['media_id' => $mediaId] : ['media_id' => $mediaId, 'url' => $offer->url]);

        return $content;
    }
}
