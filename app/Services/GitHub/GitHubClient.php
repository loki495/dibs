<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

class GitHubClient
{
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
        if (! $response->successful()) {
            $retry = max(60, (int) $response->header('Retry-After'), (int) $response->header('X-RateLimit-Reset') - time());
            throw new GitHubSyncException('GitHub HTTP '.$response->status().'; check access or retry later.', $retry);
        }
        $errors = $response->json('errors');
        if (is_array($errors) && $errors !== []) {
            $message = $errors[0]['message'] ?? null;
            throw new GitHubSyncException('GitHub returned GraphQL errors'.(is_string($message) ? ': '.$message : '; verify access and query compatibility.'));
        }
        $data = $response->json('data');
        if (! is_array($data)) {
            throw new GitHubSyncException('GitHub returned an invalid response.');
        }

        return $data;
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
