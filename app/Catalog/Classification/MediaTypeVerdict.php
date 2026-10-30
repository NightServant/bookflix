<?php

namespace App\Catalog\Classification;

/**
 * The classifier's answer: a media type slug and how sure it is. An empty slug means the record is not a kind of work the catalogue holds
 * (an artbook, a drama CD, a format the provider does not say): the validator skips it. A book can be more than one type at once (a comic
 * book series collected as a graphic novel, a novel told partly in pictures): the slug is its main type and `additional` the others.
 */
final readonly class MediaTypeVerdict
{
    /**
     * @param  float  $confidence  0.5 (a coin flip between families) to 0.99 (the provider said so outright).
     * @param  list<string>  $reasons  The evidence behind the answer, strongest first, for logs and review.
     * @param  list<string>  $additional  Other media type slugs the work also is.
     */
    public function __construct(
        public string $slug,
        public float $confidence,
        public array $reasons = [],
        public array $additional = [],
    ) {}

    public function isSupported(): bool
    {
        return $this->slug !== '';
    }
}
