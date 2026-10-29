<?php

namespace App\Enums;

/**
 * Where a story stands in a reader's library.
 */
enum ReadingStatus: string
{
    case WantToRead = 'want_to_read';
    case Reading = 'reading';
    case Completed = 'completed';
    case Paused = 'paused';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return (string) __(match ($this) {
            self::WantToRead => 'Want to read',
            self::Reading => 'Reading',
            self::Completed => 'Completed',
            self::Paused => 'Paused',
            self::Abandoned => 'Abandoned',
        });
    }

    /**
     * The Heroicon that stands for the status.
     */
    public function icon(): string
    {
        return match ($this) {
            self::WantToRead => 'bookmark',
            self::Reading => 'book-open',
            self::Completed => 'check-circle',
            self::Paused => 'pause-circle',
            self::Abandoned => 'x-circle',
        };
    }
}
