<?php

declare(strict_types=1);

it('serves a web app manifest without requiring authentication', function (): void {
    $response = $this->get('/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json');

    $manifest = $response->json();

    expect($manifest['name'])->toBe(config('app.name'))
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['icons'])->each->toHaveKeys(['src', 'sizes', 'type']);
});

it('reflects the configured app name in the manifest', function (): void {
    config(['app.name' => 'Dibs Demo']);

    $manifest = $this->get('/manifest.webmanifest')->json();

    expect($manifest['name'])->toBe('Dibs Demo')
        ->and($manifest['short_name'])->toBe('Dibs Demo');
});
