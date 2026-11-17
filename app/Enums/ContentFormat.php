<?php

namespace App\Enums;

enum ContentFormat: string
{
    case Epub = 'epub';
    case Pdf = 'pdf';
    case Cbz = 'cbz';
    case Cbr = 'cbr';
    case Images = 'images';
    case Url = 'url';

    /**
     * The type a file of this format is served as.
     */
    public function mimeType(): string
    {
        return match ($this) {
            self::Epub => 'application/epub+zip',
            self::Pdf => 'application/pdf',
            self::Cbz => 'application/vnd.comicbook+zip',
            self::Cbr => 'application/vnd.comicbook-rar',
            self::Images => 'application/zip',
            self::Url => 'text/uri-list',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Epub => 'EPUB',
            self::Pdf => 'PDF',
            self::Cbz => 'CBZ',
            self::Cbr => 'CBR',
            self::Images => __('Images'),
            self::Url => __('Link'),
        };
    }
}
