<?php

namespace App\Discovery\Recommendations\Sources;

/**
 * The same as `CollaborativeSource`, from the neighbors of the active `als` version (cosine of ALS item factors). With no active `als`
 * version (the data gate was not met, or it was refused) the neighbor query finds nothing and the source is empty.
 */
class AlsSource extends CollaborativeSource
{
    public function key(): string
    {
        return 'als';
    }

    protected function method(): string
    {
        return 'als';
    }
}
