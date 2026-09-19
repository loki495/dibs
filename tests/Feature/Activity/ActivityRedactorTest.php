<?php

declare(strict_types=1);

use App\Services\Activity\ActivityRedactor;

function redactor(): ActivityRedactor
{
    return app(ActivityRedactor::class);
}

it('redacts values under token, secret, password, authorization and key style keys', function (string $key): void {
    $result = redactor()->redact([$key => 'hunter2', 'id' => 5]);

    expect($result)->toBe([$key => ActivityRedactor::REDACTED, 'id' => 5]);
})->with([
    'capabilityToken' => 'capabilityToken',
    'GITHUB_TOKEN' => 'GITHUB_TOKEN',
    'client_secret' => 'client_secret',
    'password' => 'password',
    'password_confirmation' => 'password_confirmation',
    'Authorization' => 'Authorization',
    'apiKey' => 'apiKey',
]);

it('redacts a secret at any depth and replaces the whole value, structured or not', function (): void {
    $result = redactor()->redact([
        'outer' => ['inner' => ['api_token' => 'abc', 'title' => 'ok']],
        'credentials' => ['password' => 'x'],
        'secretNotes' => ['a', 'b'],
        'list' => [['token' => 't'], ['name' => 'n']],
    ]);

    expect($result)->toBe([
        'outer' => ['inner' => ['api_token' => ActivityRedactor::REDACTED, 'title' => 'ok']],
        'credentials' => ['password' => ActivityRedactor::REDACTED],
        'secretNotes' => ActivityRedactor::REDACTED,
        'list' => [['token' => ActivityRedactor::REDACTED], ['name' => 'n']],
    ]);
});

it('never leaves a redacted value anywhere in the output, even when it is huge', function (): void {
    $result = json_encode(redactor()->redact(['capabilityToken' => str_repeat('s3cr3t', 5000)]));

    expect($result)->not->toContain('s3cr3t');
});

it('leaves ordinary keys and every scalar type untouched', function (): void {
    $input = ['id' => 7, 'ratio' => 1.5, 'done' => false, 'note' => null, 'title' => 'Fix the sink'];

    expect(redactor()->redact($input))->toBe($input);
});

it('truncates a long string to the configured size on a character boundary and says so', function (): void {
    config(['dibs.activity.max_value_bytes' => 100]);

    $result = redactor()->redact(['body' => str_repeat('é', 200)]);

    expect(mb_check_encoding($result['body'], 'UTF-8'))->toBeTrue()
        ->and(strlen($result['body']))->toBeLessThan(200)
        ->and($result['body'])->toStartWith(str_repeat('é', 50))
        ->and($result['body'])->toContain('truncated')
        ->and($result['body'])->toContain('400 bytes');
});

it('does not touch a string exactly at the limit', function (): void {
    config(['dibs.activity.max_value_bytes' => 10]);

    expect(redactor()->redact(['a' => str_repeat('x', 10)]))->toBe(['a' => str_repeat('x', 10)]);
});

it('truncates long strings inside nested arrays', function (): void {
    config(['dibs.activity.max_value_bytes' => 10]);

    $result = redactor()->redact(['changes' => ['body' => ['from' => str_repeat('a', 50), 'to' => 'short']]]);

    expect($result['changes']['body']['from'])->toContain('truncated')
        ->and($result['changes']['body']['to'])->toBe('short');
});

it('keeps everything when the size limit is zero', function (): void {
    config(['dibs.activity.max_value_bytes' => 0]);

    $long = str_repeat('a', 10_000);

    expect(redactor()->redact(['a' => $long]))->toBe(['a' => $long]);
});

it('reads the redact-key list and size from config on every call', function (): void {
    config(['dibs.activity.redact_keys' => ['pin']]);

    expect(redactor()->redact(['pin' => '1234', 'token' => 'visible']))
        ->toBe(['pin' => ActivityRedactor::REDACTED, 'token' => 'visible']);
});

it('matches redact keys case-insensitively even when configured in mixed case', function (): void {
    config(['dibs.activity.redact_keys' => ['PiN']]);

    expect(redactor()->redact(['MYPIN' => '1234']))->toBe(['MYPIN' => ActivityRedactor::REDACTED]);
});

it('ignores an empty pattern in the redact-key list instead of redacting every key', function (): void {
    config(['dibs.activity.redact_keys' => ['', 'token']]);

    expect(redactor()->redact(['title' => 'ok', 'token' => 't']))
        ->toBe(['title' => 'ok', 'token' => ActivityRedactor::REDACTED]);
});

it('turns dates and other objects into strings so the log can always be encoded', function (): void {
    $date = new DateTimeImmutable('2026-09-19T12:00:00+00:00');

    $result = redactor()->redact(['at' => $date, 'thing' => new stdClass]);

    expect($result)->toBe(['at' => '2026-09-19T12:00:00+00:00', 'thing' => 'stdClass'])
        ->and(json_encode($result))->not->toBeFalse();
});

it('replaces invalid UTF-8 rather than failing to encode later', function (): void {
    $result = redactor()->redact(['raw' => "bad \xB1 bytes"]);

    expect(mb_check_encoding($result['raw'], 'UTF-8'))->toBeTrue()
        ->and(json_encode($result))->not->toBeFalse();
});

it('redacts a single scalar on its own with the same truncation', function (): void {
    config(['dibs.activity.max_value_bytes' => 5]);

    expect(redactor()->redact('abcdefghij'))->toContain('truncated')
        ->and(redactor()->redact(42))->toBe(42);
});
