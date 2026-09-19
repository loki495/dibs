<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\ChangeLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Who is doing what, and which log entries belong together. Bound as a scoped singleton, so a
 * web request, queue job or Octane request never sees another one's state; a long-lived process
 * that serves many operations (the stdio MCP server) calls a begin* method per operation instead.
 */
final class ActivityContext
{
    private ?string $requestId = null;

    private ?string $source = null;

    private ?string $actorType = null;

    private ?string $actorLabel = null;

    public function beginWeb(): void
    {
        $this->begin(ChangeLog::SOURCE_UI, null, null);
    }

    public function beginMcp(?string $agentLabel): void
    {
        $this->begin(ChangeLog::SOURCE_MCP, ChangeLog::ACTOR_AGENT, $agentLabel);
    }

    public function beginConsole(string $command): void
    {
        $this->begin(ChangeLog::SOURCE_CLI, ChangeLog::ACTOR_SYSTEM, $command);
    }

    public function beginSystem(string $label): void
    {
        $this->begin(ChangeLog::SOURCE_SYSTEM, ChangeLog::ACTOR_SYSTEM, $label);
    }

    public function hasBegun(): bool
    {
        return $this->source !== null;
    }

    public function requestId(): string
    {
        return $this->requestId ??= (string) Str::uuid();
    }

    public function source(): string
    {
        return $this->source ?? ChangeLog::SOURCE_SYSTEM;
    }

    public function actorType(): string
    {
        return $this->actorType ?? ($this->signedInUserName() !== null ? ChangeLog::ACTOR_USER : ChangeLog::ACTOR_SYSTEM);
    }

    public function actorLabel(): ?string
    {
        return $this->actorType !== null ? $this->actorLabel : $this->signedInUserName();
    }

    private function begin(string $source, ?string $actorType, ?string $actorLabel): void
    {
        $this->requestId = (string) Str::uuid();
        $this->source = $source;
        $this->actorType = $actorType;
        $this->actorLabel = $actorLabel;
    }

    /** Only a web context looks at the session; an agent or command must not inherit whoever is signed in. */
    private function signedInUserName(): ?string
    {
        if ($this->source !== ChangeLog::SOURCE_UI) {
            return null;
        }

        $user = Auth::user();

        return $user === null ? null : (string) $user->getAttribute('name');
    }
}
