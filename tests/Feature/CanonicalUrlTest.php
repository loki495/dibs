<?php

declare(strict_types=1);
use Tests\TestCase;

/**
 * Asset, manifest and Livewire URLs are generated from the canonical host, so a proxy that reports the
 * wrong scheme (the Cloudflare tunnel reaching Traefik over plain http) cannot leave http:// URLs on a
 * page the browser loaded over https, which the browser would block as mixed content.
 */
beforeEach(function (): void {
    /** @var TestCase $this */
    $this->withoutVite();
    config(['app.url' => 'https://dibs.example.test']);
});

it('generates urls from the configured host whatever scheme the request reports', function (): void {
    /** @var TestCase $this */
    $this->get('http://dibs.example.test/')->assertRedirect('https://dibs.example.test/login');
});

it('leaves a request for another host on its own scheme', function (): void {
    /** @var TestCase $this */
    $this->get('http://127.0.0.1:8095/')->assertRedirect('http://127.0.0.1:8095/login');
});
