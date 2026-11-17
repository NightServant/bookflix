<?php

namespace App\Reader\Archives;

use RuntimeException;

/**
 * An archive Bookflix refuses to open. The message is written for the reader (it ends up on an upload form or an admin row), so it names the
 * problem in plain words and never a path or an internal detail beyond the entry name.
 */
class UnsafeArchive extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self(__('This file is not a safe archive: :reason', ['reason' => $reason]));
    }
}
