<?php

namespace App\Catalog\Providers;

trait SanitizesProviderData
{
    /**
     * The URL when it is https and on one of this provider's allowed hosts (config), else null.
     */
    protected function safeUrl(mixed $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';
        $parts = filter_var($url, FILTER_VALIDATE_URL) === false ? false : parse_url($url);
        $hosts = config("bookflix.providers.{$this->key()}.allowed_hosts", []);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user'])
            && in_array(strtolower($parts['host'] ?? ''), is_array($hosts) ? $hosts : [], true)
                ? $url
                : null;
    }

    /**
     * Entities are decoded first, so escaped markup is stripped like real markup and the stored text carries none.
     */
    protected function plainText(mixed $html): ?string
    {
        $text = is_string($html) ? trim(strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5))) : '';

        return $text === '' ? null : $text;
    }
}
