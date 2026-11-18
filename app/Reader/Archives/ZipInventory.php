<?php

namespace App\Reader\Archives;

/**
 * What ZipGuard found in an archive it accepted: every entry, in central-directory order.
 */
final readonly class ZipInventory
{
    /**
     * @param  list<ZipEntry>  $entries
     */
    public function __construct(public array $entries) {}

    public function count(): int
    {
        return count($this->entries);
    }

    public function first(): ZipEntry
    {
        return $this->entries[0];
    }

    public function find(string $name): ?ZipEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->name === $name) {
                return $entry;
            }
        }

        return null;
    }
}
