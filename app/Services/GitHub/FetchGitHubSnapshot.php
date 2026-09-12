<?php

declare(strict_types=1);

namespace App\Services\GitHub;

class FetchGitHubSnapshot
{
    private const string LABEL = 'id name color description';

    private const string PAGE = 'pageInfo { hasNextPage endCursor }';

    private const string VALUE = '__typename ... on ProjectV2ItemFieldTextValue { text field { ... on ProjectV2FieldCommon { id } } } ... on ProjectV2ItemFieldDateValue { date field { ... on ProjectV2FieldCommon { id } } } ... on ProjectV2ItemFieldSingleSelectValue { optionId name field { ... on ProjectV2FieldCommon { id } } } ... on ProjectV2ItemFieldNumberValue { number field { ... on ProjectV2FieldCommon { id } } } ... on ProjectV2ItemFieldIterationValue { iterationId title startDate duration field { ... on ProjectV2FieldCommon { id } } }';

    /** @return array<string, mixed> */
    public function handle(GitHubClient $client, bool $comments = false): array
    {
        $owner = config('github.owner');
        $name = config('github.repository');
        $data = $client->query('query($owner:String!, $name:String!) { viewer { login } repository(owner:$owner,name:$name) { id name nameWithOwner url isPrivate visibility updatedAt owner { login } } }', ['owner' => $owner, 'name' => $name]);
        $repo = $data['repository'] ?? null;
        if (! is_array($repo) || strcasecmp($repo['nameWithOwner'], $owner.'/'.$name) !== 0) {
            throw new GitHubSyncException('Configured repository is unavailable or does not match the response.');
        }
        $labels = $client->connection($repo['id'], 'Repository', 'labels', self::LABEL);
        $issues = $client->connection($repo['id'], 'Repository', 'issues',
            'id number title body state stateReason url updatedAt parent { id } labels(first:100) { nodes { '.self::LABEL.' } '.self::PAGE.' } subIssues(first:100) { nodes { id } '.self::PAGE.' }');
        foreach ($issues as &$issue) {
            $issue['labels'] = $client->connection($issue['id'], 'Issue', 'labels', self::LABEL, $issue['labels']);
            $issue['children'] = $client->connection($issue['id'], 'Issue', 'subIssues', 'id', $issue['subIssues']);
            unset($issue['subIssues']);
            if ($comments) {
                $issue['comments'] = $client->connection($issue['id'], 'Issue', 'comments', 'id body url createdAt updatedAt author { login }');
            }
        }
        unset($issue);
        $projects = [];
        foreach (config('github.projects') as $number) {
            $data = $client->query('query($owner:String!, $number:Int!) { user(login:$owner) { projectV2(number:$number) { id number title url closed public updatedAt } } }', ['owner' => $owner, 'number' => $number]);
            $project = $data['user']['projectV2'] ?? null;
            if (! is_array($project) || $project['number'] !== $number) {
                throw new GitHubSyncException('A configured Project is unavailable; the previous snapshot was preserved.');
            }
            $project['owner'] = $owner;
            $project['fields'] = $client->connection($project['id'], 'ProjectV2', 'fields', '__typename ... on ProjectV2FieldCommon { id name dataType } ... on ProjectV2SingleSelectField { options { id name color } }');
            $project['items'] = $client->connection($project['id'], 'ProjectV2', 'items',
                'id type isArchived updatedAt content { __typename ... on Issue { id title url repository { nameWithOwner } } ... on PullRequest { id title url } ... on DraftIssue { id title body } } fieldValues(first:100) { nodes { '.self::VALUE.' } '.self::PAGE.' }');
            foreach ($project['items'] as &$item) {
                $item['values'] = $client->connection($item['id'], 'ProjectV2Item', 'fieldValues', self::VALUE, $item['fieldValues']);
                unset($item['fieldValues']);
            }
            unset($item);
            $projects[] = $project;
        }

        return ['repository' => $repo, 'labels' => $labels, 'issues' => $issues, 'projects' => $projects];
    }
}
