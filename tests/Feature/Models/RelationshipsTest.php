<?php

declare(strict_types=1);

use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;

it('relates a repository to its issues and labels', function (): void {
    $repository = GitHubRepository::factory()->create();
    $issue = Issue::factory()->create(['repository_id' => $repository->id]);
    $label = Label::factory()->create(['repository_id' => $repository->id]);

    expect($repository->issues)->toHaveCount(1)
        ->and($repository->issues->first()->is($issue))->toBeTrue()
        ->and($repository->labels)->toHaveCount(1)
        ->and($repository->labels->first()->is($label))->toBeTrue();
});

it('relates a label to its repository and issues', function (): void {
    $repository = GitHubRepository::factory()->create();
    $label = Label::factory()->create(['repository_id' => $repository->id]);
    $issue = Issue::factory()->create();
    $issue->labels()->attach($label);

    expect($label->repository->is($repository))->toBeTrue()
        ->and($label->issues)->toHaveCount(1)
        ->and($label->issues->first()->is($issue))->toBeTrue();
});

it('relates a project field option to its field and to project items via status/group', function (): void {
    $field = ProjectField::factory()->create();
    $option = ProjectFieldOption::factory()->create(['project_field_id' => $field->id]);
    $statusItem = ProjectItem::factory()->create(['status_option_id' => $option->id]);
    $groupItem = ProjectItem::factory()->create(['group_option_id' => $option->id]);

    expect($option->field->is($field))->toBeTrue()
        ->and($option->statusItems)->toHaveCount(1)
        ->and($option->statusItems->first()->is($statusItem))->toBeTrue()
        ->and($option->groupItems)->toHaveCount(1)
        ->and($option->groupItems->first()->is($groupItem))->toBeTrue();
});
