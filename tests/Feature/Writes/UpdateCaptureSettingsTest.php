<?php

declare(strict_types=1);

use App\Actions\UpdateCaptureSettings;
use App\Exceptions\TodoValidationException;
use App\Models\CaptureSetting;

it('defaults to disabled with the recommended agent order', function (): void {
    $settings = CaptureSetting::current();

    expect($settings->enabled)->toBeFalse()
        ->and($settings->enabled_agents)->toBe(['claude', 'codex', 'opencode', 'agy']);
});

it('enables capture with a chosen agent order', function (): void {
    $settings = app(UpdateCaptureSettings::class)->handle(enabled: true, enabledAgents: ['codex', 'opencode']);

    expect($settings->enabled)->toBeTrue()
        ->and($settings->enabled_agents)->toBe(['codex', 'opencode'])
        ->and(CaptureSetting::current()->id)->toBe($settings->id);
});

it('rejects an unknown agent name', function (): void {
    expect(fn () => app(UpdateCaptureSettings::class)->handle(enabled: true, enabledAgents: ['claude', 'chatgpt']))
        ->toThrow(TodoValidationException::class, 'chatgpt');
});

it('rejects a duplicate agent in the order', function (): void {
    expect(fn () => app(UpdateCaptureSettings::class)->handle(enabled: true, enabledAgents: ['claude', 'claude']))
        ->toThrow(TodoValidationException::class);
});

it('rejects enabling capture with no agents selected', function (): void {
    expect(fn () => app(UpdateCaptureSettings::class)->handle(enabled: true, enabledAgents: []))
        ->toThrow(TodoValidationException::class);
});

it('allows disabling capture with no agents selected', function (): void {
    $settings = app(UpdateCaptureSettings::class)->handle(enabled: false, enabledAgents: []);

    expect($settings->enabled)->toBeFalse()
        ->and($settings->enabled_agents)->toBe([]);
});
