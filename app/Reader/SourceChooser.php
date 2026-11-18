<?php

namespace App\Reader;

use App\Enums\AccessType;
use App\Enums\ContentFormat;
use App\Models\ContentSource;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which file a reader opens for a story, and which links out the story page may show. Every file goes through ContentAccessGate, so a source the
 * gate refuses is never offered, whatever was asked for. The order: the source asked for (when the gate allows it), then the reader's own upload,
 * then a verified public-domain or licensed source; within each, EPUB before PDF before CBZ.
 */
class SourceChooser
{
    /** The formats the reader can open, best first. */
    private const FORMATS = [ContentFormat::Epub, ContentFormat::Pdf, ContentFormat::Cbz];

    public function __construct(private readonly ContentAccessGate $gate) {}

    public function for(User $user, Media $media, ?int $requested = null): ?ContentSource
    {
        return $this->best($this->readable($user, $media), $requested);
    }

    /**
     * The same choice from sources already loaded for the story (the story page loads them once for both its read action and Where to read).
     *
     * @param  Collection<int, ContentSource>  $sources
     */
    public function fromLoaded(User $user, Media $media, Collection $sources): ?ContentSource
    {
        return $this->best($sources
            ->filter(fn (ContentSource $source): bool => in_array($source->format, self::FORMATS, true))
            ->each(fn (ContentSource $source) => $source->setRelation('media', $media))
            ->filter(fn (ContentSource $source): bool => $this->gate->allows($user, $source))
            ->values());
    }

    /**
     * @param  Collection<int, ContentSource>  $sources
     */
    private function best(Collection $sources, ?int $requested = null): ?ContentSource
    {
        return $sources
            ->sortBy(fn (ContentSource $source): array => [
                $source->id === $requested ? 0 : 1,
                $source->access_type === AccessType::UserOwned ? 0 : 1,
                array_search($source->format, self::FORMATS, true),
                $source->id,
            ])
            ->first();
    }

    /**
     * Every source of the story this reader may open in the reader.
     *
     * @return Collection<int, ContentSource>
     */
    public function readable(User $user, Media $media): Collection
    {
        return ContentSource::query()
            ->where('media_id', $media->id)
            ->whereIn('format', array_map(fn (ContentFormat $format): string => $format->value, self::FORMATS))
            ->get()
            ->each(fn (ContentSource $source) => $source->setRelation('media', $media))
            ->filter(fn (ContentSource $source): bool => $this->gate->allows($user, $source))
            ->values();
    }

    /**
     * The verified links to read the story elsewhere. Bookflix never streams these, so the gate has nothing to say: an admin verified each one, and only a web address is ever linked.
     *
     * @return Collection<int, ContentSource>
     */
    public function links(Media $media): Collection
    {
        return $this->linksFrom(ContentSource::query()->where('media_id', $media->id)->where('access_type', AccessType::ExternalLink->value)->get());
    }

    /**
     * The same links picked from sources already loaded for the story.
     *
     * @param  Collection<int, ContentSource>  $sources
     * @return Collection<int, ContentSource>
     */
    public function linksFrom(Collection $sources): Collection
    {
        return $sources
            ->filter(fn (ContentSource $source): bool => $source->access_type === AccessType::ExternalLink
                && $source->url !== null
                && $source->isVerified()
                && in_array(parse_url((string) $source->url, PHP_URL_SCHEME), ['http', 'https'], true))
            ->sortBy('id')
            ->values();
    }
}
