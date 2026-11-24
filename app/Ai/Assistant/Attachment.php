<?php

namespace App\Ai\Assistant;

/**
 * Something the reader attached to one message (P11-R26), held only for that reply: text (a .txt or .md read on the server, or a PDF's text read in
 * the browser), or an image (`mime` and its bytes as base64). Never stored; only the name is kept.
 */
final readonly class Attachment
{
    public function __construct(
        public string $name,
        public ?string $text = null,
        public ?string $mime = null,
        public ?string $base64 = null,
    ) {}

    public function isImage(): bool
    {
        return $this->base64 !== null;
    }
}
