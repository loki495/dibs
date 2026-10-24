<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TaskClaim;
use DomainException;

/**
 * Which live claims cover a path another piece of work is about to touch. Two paths overlap when they
 * are equal or one is a directory ancestor of the other. A claim is live when it is unreleased,
 * unexpired and its verified process has not been confirmed dead.
 */
class FindClaimPathOverlaps
{
    public const MAX_PATHS = 20;

    public function __construct(private readonly ResolveClaimLiveness $liveness) {}

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function normalize(array $paths): array
    {
        if (count($paths) > self::MAX_PATHS) {
            throw new DomainException('A claim may list at most '.self::MAX_PATHS.' paths.');
        }

        $normalized = [];
        foreach ($paths as $path) {
            $path = trim($path);
            if (! str_starts_with($path, '/') || str_contains($path, "\0") || strlen($path) > 500 || in_array('..', explode('/', $path), true)) {
                throw new DomainException("Paths must be absolute, without '..' or NUL, up to 500 characters: \"{$path}\" is not.");
            }
            $normalized[] = $path === '/' ? '/' : rtrim((string) preg_replace('#/+#', '/', $path), '/');
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<string>  $paths
     * @param  string|null  $exceptSession  skip claims whose agent name ends with ":<this>" (the caller's conversation id)
     * @return list<array{issueId: int, agentName: string, paths: list<string>}>
     */
    public function handle(array $paths, ?int $exceptIssueId = null, ?string $exceptSession = null): array
    {
        $paths = $this->normalize($paths);
        if ($paths === []) {
            return [];
        }

        $found = [];
        $claims = TaskClaim::query()->whereNull('released_at')->where('expires_at', '>', now())->whereNotNull('paths')->with('agentSession')->get();
        foreach ($claims as $claim) {
            $agent = $claim->agentSession->agent_name;
            if ($claim->issue_id === $exceptIssueId || ($exceptSession !== null && str_ends_with($agent, ':'.$exceptSession))) {
                continue;
            }
            $hits = array_values(array_filter($claim->paths ?? [], fn (string $held): bool => $this->anyOverlap($held, $paths)));
            if ($hits !== [] && $this->liveness->handle($claim)['isCurrentlyAlive'] !== false) {
                $found[] = ['issueId' => $claim->issue_id, 'agentName' => $agent, 'paths' => $hits];
            }
        }

        return $found;
    }

    /**
     * Live claims, with or without paths, held by the conversation whose id ends the agent name.
     *
     * @return list<array{issueId: int, agentName: string, paths: list<string>}>
     */
    public function heldBy(string $session): array
    {
        $found = [];
        $claims = TaskClaim::query()->whereNull('released_at')->where('expires_at', '>', now())->with('agentSession')->get();
        foreach ($claims as $claim) {
            $agent = $claim->agentSession->agent_name;
            if (str_ends_with($agent, ':'.$session) && $this->liveness->handle($claim)['isCurrentlyAlive'] !== false) {
                $found[] = ['issueId' => $claim->issue_id, 'agentName' => $agent, 'paths' => $claim->paths ?? []];
            }
        }

        return $found;
    }

    /** @param list<string> $paths */
    private function anyOverlap(string $held, array $paths): bool
    {
        return array_any($paths, fn (string $path): bool => $held === $path || str_starts_with($path, rtrim($held, '/').'/') || str_starts_with($held, rtrim($path, '/').'/'));
    }
}
