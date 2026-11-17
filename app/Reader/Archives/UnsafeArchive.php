<?php

namespace App\Reader\Archives;

use RuntimeException;

/**
 * An archive Bookflix refuses to open. The message is written for the reader (it ends up on an upload form or an admin row), so it names the
 * problem in plain words and never a path or an internal detail beyond the entry name.
 */
class UnsafeArchive extends RuntimeException
{
    /**
     * `$hostile` tells an archive that is dangerous (bad names, links, bombs) from one that is merely not what was expected (no pages, not an
     * EPUB), so callers branch on the kind and never on the wording of the message.
     */
    public function __construct(string $message = '', public readonly bool $hostile = false)
    {
        parent::__construct($message);
    }

    /**
     * A dangerous archive.
     */
    public static function because(string $reason): self
    {
        return new self(__('This file is not a safe archive: :reason', ['reason' => $reason]), hostile: true);
    }

    /**
     * An archive that is not dangerous but is not a comic (or EPUB) either.
     */
    public static function notAComic(string $message): self
    {
        return new self($message, hostile: false);
    }
}
