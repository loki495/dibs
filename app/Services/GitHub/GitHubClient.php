<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

class GitHubClient
{
    /** GraphQL error types that a later retry of the same call cannot fix. */
    private const array PERMANENT_GRAPHQL_ERRORS = ['NOT_FOUND', 'FORBIDDEN', 'INSUFFICIENT_SCOPES', 'UNPROCESSABLE'];

    /** GitHub asks for at least this long when a secondary rate limit sends no retry time. */
    private const int SECONDARY_RATE_LIMIT_SECONDS = 60;

    public function __construct(#[SensitiveParameter] private readonly string $token) {}

    /** @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function query(string $query, array $variables = []): array
    {
        try {
            $response = Http::withToken($this->token)->acceptJson()->connectTimeout(10)->timeout(45)
                ->post('https://api.github.com/graphql', ['query' => $query, 'variables' => (object) $variables]);
        } catch (ConnectionException) {
            throw new GitHubSyncException('GitHub connection failed; the previous snapshot was preserved.');
        }
        $rateLimitedUntil = $this->rateLimitedUntil($response);
        if ($rateLimitedUntil instanceof CarbonImmutable) {
            throw GitHubSyncException::rateLimited('GitHub HTTP '.$response->status().': rate limit reached; retrying after '.$rateLimitedUntil->toIso8601String().'.', $rateLimitedUntil);
        }
        if (! $response->successful()) {
            $message = 'GitHub HTTP '.$response->status().'; check access or retry later.';

            throw $response->serverError() ? new GitHubSyncException($message) : GitHubSyncException::permanent($message);
        }
        $errors = $response->json('errors');
        if (is_array($errors) && $errors !== []) {
            $message = $errors[0]['message'] ?? null;
            $message = 'GitHub returned GraphQL errors'.(is_string($message) ? ': '.$message : '; verify access and query compatibility.');

            throw in_array($errors[0]['type'] ?? null, self::PERMANENT_GRAPHQL_ERRORS, true) ? GitHubSyncException::permanent($message) : new GitHubSyncException($message);
        }
        $data = $response->json('data');
        if (! is_array($data)) {
            throw new GitHubSyncException('GitHub returned an invalid response.');
        }

        return $data;
    }

    /**
     * Primary limits answer 403/429 (REST) or 200 with a RATE_LIMITED GraphQL error, with
     * X-RateLimit-Remaining: 0 and the reset epoch. Secondary limits answer 403/429, usually
     * with Retry-After. Retry-After wins when both are present.
     */
    private function rateLimitedUntil(Response $response): ?CarbonImmutable
    {
        $errors = $response->json('errors');
        $graphQlLimited = is_array($errors) && collect($errors)->contains(fn ($error): bool => is_array($error) && ($error['type'] ?? null) === 'RATE_LIMITED');
        $exhausted = $response->header('X-RateLimit-Remaining') === '0';
        $retryAfter = $response->header('Retry-After');
        $limitStatus = in_array($response->status(), [403, 429], true);
        $mentionsLimit = str_contains(strtolower((string) $response->json('message')), 'rate limit');
        if (! $graphQlLimited && (! $limitStatus || ! ($exhausted || $retryAfter !== '' || $mentionsLimit || $response->status() === 429))) {
            return null;
        }

        $now = CarbonImmutable::now();
        if (is_numeric($retryAfter)) {
            return $now->addSeconds(max(1, (int) $retryAfter));
        }
        $reset = $response->header('X-RateLimit-Reset');
        if ($exhausted && is_numeric($reset) && (int) $reset > $now->getTimestamp()) {
            return $now->setTimestamp((int) $reset);
        }

        return $now->addSeconds(self::SECONDARY_RATE_LIMIT_SECONDS);
    }

    /** @param array<string, mixed>|null $firstPage
     * @return list<array<string, mixed>>
     */
    public function connection(string $id, string $type, string $field, string $selection, ?array $firstPage = null): array
    {
        $nodes = [];
        $cursor = null;
        $seen = [];
        do {
            $page = $firstPage ?? ($this->query(
                'query($id: ID!, $cursor: String) { node(id: $id) { ... on '.$type.' { '.$field.'(first: 100, after: $cursor) { nodes { '.$selection.' } pageInfo { hasNextPage endCursor } } } } }',
                ['id' => $id, 'cursor' => $cursor],
            )['node'][$field] ?? null);
            $firstPage = null;
            if (! is_array($page) || ! is_array($page['nodes'] ?? null) || ! is_bool($page['pageInfo']['hasNextPage'] ?? null)) {
                throw new GitHubSyncException('Incomplete GitHub connection; snapshot was not applied.');
            }
            foreach ($page['nodes'] as $node) {
                if (! is_array($node)) {
                    throw new GitHubSyncException('Inaccessible GitHub connection node; snapshot was not applied.');
                }
                $nodes[] = $node;
            }
            $more = $page['pageInfo']['hasNextPage'];
            $cursor = $page['pageInfo']['endCursor'] ?? null;
            if ($more && (! is_string($cursor) || $cursor === '' || isset($seen[$cursor]))) {
                throw new GitHubSyncException('Invalid pagination cursor; snapshot was not applied.');
            }
            if (is_string($cursor)) {
                $seen[$cursor] = true;
            }
        } while ($more);

        return $nodes;
    }
}
