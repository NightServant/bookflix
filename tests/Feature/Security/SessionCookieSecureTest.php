<?php

/*
 * The session cookie is Secure by default in production (a cookie that can travel over plain http is a session that can be read off the wire), and
 * `SESSION_SECURE_COOKIE` still decides when it is set. The config file is read under each environment, since the running app is always `testing`.
 */

/** @param  array<string, string|null>  $env */
function sessionSecureUnder(array $env): mixed
{
    $names = ['APP_ENV', 'SESSION_SECURE_COOKIE'];
    $keep = array_map(fn (string $name) => [$_ENV[$name] ?? null, $_SERVER[$name] ?? null], array_combine($names, $names));

    foreach ($names as $name) {
        unset($_ENV[$name], $_SERVER[$name]);

        if (($env[$name] ?? null) !== null) {
            $_ENV[$name] = $_SERVER[$name] = $env[$name];
        }
    }

    try {
        return (require config_path('session.php'))['secure'];
    } finally {
        foreach ($keep as $name => [$fromEnv, $fromServer]) {
            unset($_ENV[$name], $_SERVER[$name]);

            if ($fromEnv !== null) {
                $_ENV[$name] = $fromEnv;
            }

            if ($fromServer !== null) {
                $_SERVER[$name] = $fromServer;
            }
        }
    }
}

test('the session cookie is Secure by default in production only', function (?string $appEnv, mixed $expected) {
    expect(sessionSecureUnder(['APP_ENV' => $appEnv]))->toBe($expected);
})->with([
    'production' => ['production', true],
    'local' => ['local', false],
    'testing' => ['testing', false],
    'staging' => ['staging', false],
]);

test('SESSION_SECURE_COOKIE overrides the default either way', function (string $appEnv, string $setting, bool $expected) {
    expect(sessionSecureUnder(['APP_ENV' => $appEnv, 'SESSION_SECURE_COOKIE' => $setting]))->toBe($expected);
})->with([
    'switched off in production' => ['production', 'false', false],
    'switched on in local' => ['local', 'true', true],
    'on in production' => ['production', 'true', true],
]);
