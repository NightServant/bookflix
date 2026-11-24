<?php

namespace App\Ai\Assistant;

use App\Models\Media;
use Illuminate\Support\Collection;

/**
 * What a tool hands back to the model (`content`, always a `<data>` block) and the catalog stories it contained.
 */
final readonly class ToolResult
{
    /**
     * @param  Collection<int, Media>  $media
     */
    public function __construct(
        public string $content,
        public Collection $media,
        public bool $ok = true,
        public bool $isSearch = false,
        public bool $usedLibrary = false,
    ) {}

    public static function error(string $message): self
    {
        return new self('<data>'.json_encode(['error' => $message], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE).'</data>', new Collection, ok: false);
    }
}
