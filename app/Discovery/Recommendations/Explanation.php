<?php

namespace App\Discovery\Recommendations;

use App\Enums\ReasonType;
use App\Models\Media;

/**
 * Why a title was recommended, as the reader will read it: the reason type with the title or genre its sentence names. A sentence that
 * needs a title or genre it does not have has no label (null), and the tile shows no reason rather than a broken one.
 *
 * The title and genre must already be ones this reader may see: the ranker passes only reason media the mature gate lets through and
 * only genres that are not gated for them (`fromCandidate`), so a label never discloses a hidden title.
 */
final readonly class Explanation
{
    public function __construct(
        public ReasonType $type,
        public ?string $reasonTitle = null,
        public ?string $genre = null,
    ) {}

    /**
     * @param  Media|null  $reasonMedia  the title the label names, already checked for this reader; null when it is gated, gone or unknown
     * @param  bool  $genreVisible  whether the candidate's genre payload may be shown to this reader
     */
    public static function fromCandidate(Candidate $candidate, ?Media $reasonMedia, bool $genreVisible = true): self
    {
        $genre = $candidate->payload['genre'] ?? null;

        return new self($candidate->reason, $reasonMedia?->title, $genreVisible && is_string($genre) ? $genre : null);
    }

    public function label(): ?string
    {
        return match ($this->type) {
            ReasonType::BecauseYouRead => self::with($this->reasonTitle, 'Because you read :title'),
            ReasonType::SimilarThemes => self::with($this->reasonTitle, 'Similar themes to :title'),
            ReasonType::PopularWithSimilarReaders => self::with($this->reasonTitle, 'Popular with readers who enjoyed :title'),
            ReasonType::MatchesInterest => self::with($this->genre, 'Matches your interest in :genre', 'genre'),
            ReasonType::Trending => (string) __('Trending now'),
            ReasonType::Editorial => (string) __('Picked by our editors'),
            ReasonType::NewRelease => (string) __('New release'),
            ReasonType::Exploration => (string) __('Something different for you'),
        };
    }

    private static function with(?string $value, string $template, string $placeholder = 'title'): ?string
    {
        return $value === null || trim($value) === '' ? null : (string) __($template, [$placeholder => $value]);
    }
}
