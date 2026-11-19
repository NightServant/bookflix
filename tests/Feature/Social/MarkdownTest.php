<?php

use App\Support\Markdown;
use Illuminate\Support\HtmlString;

dataset('xss payloads', [
    'script tag' => ['<script>alert(1)</script>hello'],
    'img onerror' => ['<img src=x onerror=alert(1)>'],
    'javascript link' => ['[x](javascript:alert(1))'],
    'mixed case javascript link' => ['[x](JaVaScRiPt:alert(1))'],
    'javascript autolink' => ['<javascript:alert(1)>'],
    'iframe' => ['<iframe src="https://evil.test"></iframe>'],
    'data url link' => ['[x](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)'],
    'data url image' => ['![x](data:image/svg+xml;base64,PHN2Zz4=)'],
    'event handler html' => ['<a href="https://x.test" onclick="alert(1)">x</a>'],
    'svg' => ['<svg onload=alert(1)></svg>'],
    'vbscript link' => ['[x](vbscript:msgbox(1))'],
]);

test('script, iframe, event handlers and unsafe links never reach the output', function (string $payload) {
    $html = (string) Markdown::safe($payload);

    expect($html)->not->toMatch('/<(script|iframe|svg|img|style|object|embed)/i')
        ->and($html)->not->toMatch('/\son\w+\s*=/i')
        ->and($html)->not->toMatch('/(href|src)\s*=\s*"?\s*(javascript|vbscript|data):/i');
})->with('xss payloads');

test('a link to http, https or mailto stays, marked nofollow ugc', function (string $url) {
    $html = (string) Markdown::safe("[go]({$url})");

    expect($html)->toContain('href="'.$url.'"')
        ->and($html)->toContain('rel="nofollow ugc noopener noreferrer"')
        ->and($html)->toContain('>go</a>');
})->with(['https://example.test/a?b=1', 'http://example.test', 'mailto:me@example.test']);

test('other links lose the link and keep the words', function (string $url) {
    $html = (string) Markdown::safe("[go there]({$url})");

    expect($html)->not->toContain('<a')->and($html)->toContain('go there');
})->with(['ftp://example.test/file', '/relative/path', 'tel:123', 'javascript:alert(1)']);

test('images render as their alt text and load nothing', function () {
    $html = (string) Markdown::safe('![a *tiny* cat](https://tracker.test/cat.png) and ![](https://tracker.test/x.png)');

    expect($html)->not->toContain('<img')->and($html)->not->toContain('tracker.test')
        ->and($html)->toContain('a tiny cat');
});

test('an image inside a link leaves the link with the alt text', function () {
    $html = (string) Markdown::safe('[![logo](https://x.test/l.png)](https://example.test)');

    expect($html)->not->toContain('<img')->and($html)->toContain('href="https://example.test"')->and($html)->toContain('>logo</a>');
});

test('headings render as paragraphs', function () {
    $html = (string) Markdown::safe("# Big title\n\nText\n\nSetext\n======");

    expect($html)->not->toMatch('/<h[1-6]/')->and($html)->toContain('<p>Big title</p>')->and($html)->toContain('<p>Setext</p>');
});

test('emphasis, strong, lists, quotes and code still render', function () {
    $html = (string) Markdown::safe("*soft* and **loud**\n\n- one\n- two\n\n1. first\n\n> quoted\n\n`code`");

    expect($html)->toContain('<em>soft</em>')->and($html)->toContain('<strong>loud</strong>')
        ->and($html)->toContain('<ul>')->and($html)->toContain('<li>one</li>')->and($html)->toContain('<ol>')
        ->and($html)->toContain('<blockquote>')->and($html)->toContain('<code>code</code>');
});

test('angle brackets in plain text are escaped, and Unicode survives', function () {
    $html = (string) Markdown::safe('1 < 2 & "quotes" 📚 مرحبا');

    expect($html)->toContain('1 &lt; 2 &amp; &quot;quotes&quot; 📚 مرحبا');
});

test('the result is an HtmlString', function () {
    expect(Markdown::safe('x'))->toBeInstanceOf(HtmlString::class);
});
