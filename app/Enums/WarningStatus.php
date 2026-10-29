<?php

namespace App\Enums;

/**
 * What is known about one content warning on one work. Unknown never implies absent: only Absent means a source checked and found none.
 */
enum WarningStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Unknown = 'unknown';
    case Disputed = 'disputed';

    /**
     * The statuses a reader is shown a warning for, and the ones a "hide this warning" filter removes a work for.
     * Disputed counts: a warning one source reports must not be hidden by another source's silence.
     *
     * @return list<self>
     */
    public static function shown(): array
    {
        return [self::Present, self::Disputed];
    }

    /**
     * @return list<string>
     */
    public static function shownValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::shown());
    }

    /**
     * The status after a source reports the warning as `$incoming` for a work that already has `$this`.
     * Unknown gives way to anything, a repeat changes nothing, and present against absent is disputed.
     */
    public function reconcile(self $incoming): self
    {
        return match (true) {
            $this === $incoming, $incoming === self::Unknown => $this,
            $this === self::Unknown => $incoming,
            default => self::Disputed,
        };
    }
}
