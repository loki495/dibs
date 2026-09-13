<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\CaptureSetting;

class UpdateCaptureSettings
{
    /**
     * @param  list<string>  $enabledAgents  Order encodes priority; the first entry is tried first.
     */
    public function handle(bool $enabled, array $enabledAgents): CaptureSetting
    {
        $unknown = array_diff($enabledAgents, CaptureSetting::KNOWN_AGENTS);
        if ($unknown !== []) {
            throw new TodoValidationException('Unknown capture agent(s): '.implode(', ', $unknown));
        }

        if ($enabledAgents !== array_unique($enabledAgents)) {
            throw new TodoValidationException('Each capture agent may only appear once.');
        }

        if ($enabled && $enabledAgents === []) {
            throw new TodoValidationException('Enable at least one agent before turning capture on.');
        }

        $settings = CaptureSetting::current();
        $settings->update(['enabled' => $enabled, 'enabled_agents' => $enabledAgents]);

        return $settings;
    }
}
