<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
<script nonce="{{ Vite::cspNonce() }}">window.localStorage.setItem('flux.appearance', 'dark')</script>
{{-- Flux's appearance script is inline, so it is given this request's nonce explicitly. --}}
@fluxAppearance(['nonce' => Vite::cspNonce()])
