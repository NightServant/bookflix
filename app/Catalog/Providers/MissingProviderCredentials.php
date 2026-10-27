<?php

namespace App\Catalog\Providers;

/**
 * A provider that needs a key, token or login has none configured. Retrying cannot help, so the page job closes the run as failed at once.
 */
class MissingProviderCredentials extends ProviderUnavailable
{
    public static function for(string $provider, string $variables): self
    {
        return new self("The {$provider} provider has no credentials; set {$variables} in .env.");
    }
}
