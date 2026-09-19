<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\GitHubProject;
use App\Models\ProjectFieldOption;
use App\Services\Activity\ActivityRecorder;

class ResolveGroupOption
{
    public function __construct(
        private readonly EnqueueGitHubPush $enqueue,
        private readonly ActivityRecorder $recorder,
    ) {}

    /**
     * Find a project's Group by name (case-insensitively) or create it locally and queue its creation.
     * Runs inside the caller's transaction.
     */
    public function handle(GitHubProject $project, string $name): ProjectFieldOption
    {
        $name = trim($name);
        $field = $project->fields()->where('semantic_key', 'group')->where('is_available', true)->first();
        if ($field === null) {
            throw new TodoValidationException('This area has no Group field. Refresh and try again.');
        }

        $existing = $field->options()->whereRaw('LOWER(name) = LOWER(?)', [$name])->first();
        if ($existing instanceof ProjectFieldOption) {
            return $existing;
        }

        $option = ProjectFieldOption::create([
            'project_field_id' => $field->id,
            'github_option_id' => null,
            'name' => $name,
            'color' => 'GRAY',
            'position' => (int) $field->options()->max('position') + 1,
        ]);
        $this->enqueue->handle('create_group_option', 'project_field_option', $option->id, ['name' => $option->name, 'color' => 'GRAY'], 'group_option:create:'.$option->id);
        $this->recorder->change('ResolveGroupOption', $option, 'Created Group', $this->recorder->diffModel($option, ['name', 'color']));

        return $option;
    }
}
